"""
PHI Redaction System for HIPAA Compliance
Automatically removes Protected Health Information before sending to AI APIs
"""

import re
import hashlib
from typing import Dict, List, Tuple, Optional
from dataclasses import dataclass
from datetime import datetime

from config.settings import PHI_PATTERNS


@dataclass
class RedactionResult:
    redacted_text: str
    redactions_made: List[Dict[str, str]]
    redaction_map: Dict[str, str]  # For potential restoration
    phi_detected: bool


class PHIRedactor:
    """
    HIPAA-compliant PHI detection and redaction system
    """
    
    def __init__(self):
        self.patterns = PHI_PATTERNS.copy()
        
        # Extended patterns for comprehensive PHI detection
        self.extended_patterns = {
            # Names (common patterns)
            "person_name": r"\b[A-Z][a-z]+\s+[A-Z][a-z]+\b",
            
            # Addresses
            "address": r"\b\d+\s+[A-Za-z\s]+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Drive|Dr|Lane|Ln|Court|Ct|Place|Pl)\b",
            "zip_code": r"\b\d{5}(?:-\d{4})?\b",
            
            # Medical identifiers
            "medical_record_extended": r"\b(?:Patient\s+ID|Chart\s+#|Account\s+#)\s*:?\s*\d+\b",
            "insurance_id": r"\b(?:Policy|Member|Group)\s*#?\s*:?\s*[A-Za-z0-9]+\b",
            
            # Financial identifiers
            "credit_card": r"\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b",
            "bank_account": r"\b(?:Account|Acct)\s*#?\s*:?\s*\d{8,}\b",
            
            # Dates (broader pattern)
            "dates": r"\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b",
            
            # URLs and emails
            "website": r"https?://[^\s]+",
            
            # License numbers
            "license": r"\b(?:DL|License|Lic)\s*#?\s*:?\s*[A-Za-z0-9]+\b"
        }
        
        # Combine all patterns
        self.all_patterns = {**self.patterns, **self.extended_patterns}
        
        # Common name indicators that shouldn't be redacted
        self.name_exceptions = {
            "doctor", "dr", "physician", "nurse", "attorney", "lawyer", "judge",
            "hospital", "clinic", "medical", "center", "health", "care",
            "insurance", "company", "corporation", "inc", "llc", "firm"
        }
    
    def generate_replacement_token(self, phi_type: str, original_text: str) -> str:
        """Generate a consistent replacement token for PHI"""
        # Create a hash of the original text for consistency
        text_hash = hashlib.md5(original_text.encode()).hexdigest()[:8]
        return f"[{phi_type.upper()}_{text_hash}]"
    
    def is_likely_name(self, text: str) -> bool:
        """Determine if text is likely a person's name"""
        text_lower = text.lower()
        
        # Skip if contains exception words
        for exception in self.name_exceptions:
            if exception in text_lower:
                return False
        
        # Check if it's a common name pattern
        words = text.split()
        if len(words) == 2:
            # Two words, both capitalized - likely name
            return all(word[0].isupper() and word[1:].islower() for word in words if word)
        
        return False
    
    def detect_phi(self, text: str) -> List[Tuple[str, str, int, int]]:
        """
        Detect PHI in text and return matches with positions
        Returns: List of (phi_type, matched_text, start_pos, end_pos)
        """
        matches = []
        
        for phi_type, pattern in self.all_patterns.items():
            for match in re.finditer(pattern, text, re.IGNORECASE):
                matched_text = match.group()
                
                # Special handling for names
                if phi_type == "person_name":
                    if not self.is_likely_name(matched_text):
                        continue
                
                matches.append((
                    phi_type,
                    matched_text,
                    match.start(),
                    match.end()
                ))
        
        # Sort by position (reverse order for safe replacement)
        matches.sort(key=lambda x: x[2], reverse=True)
        return matches
    
    def redact_text(self, text: str, preserve_structure: bool = True) -> RedactionResult:
        """
        Redact PHI from text while preserving document structure
        """
        if not text:
            return RedactionResult(
                redacted_text="",
                redactions_made=[],
                redaction_map={},
                phi_detected=False
            )
        
        redacted_text = text
        redactions_made = []
        redaction_map = {}
        
        # Detect all PHI
        phi_matches = self.detect_phi(text)
        
        # Process matches (in reverse order to maintain positions)
        for phi_type, matched_text, start_pos, end_pos in phi_matches:
            replacement_token = self.generate_replacement_token(phi_type, matched_text)
            
            # Store redaction information
            redaction_info = {
                "type": phi_type,
                "original": matched_text,
                "replacement": replacement_token,
                "position": f"{start_pos}-{end_pos}"
            }
            redactions_made.append(redaction_info)
            redaction_map[replacement_token] = matched_text
            
            # Perform replacement
            if preserve_structure:
                # Keep similar length for document structure
                if len(replacement_token) < len(matched_text):
                    replacement = replacement_token + " " * (len(matched_text) - len(replacement_token))
                else:
                    replacement = replacement_token
            else:
                replacement = replacement_token
            
            redacted_text = redacted_text[:start_pos] + replacement + redacted_text[end_pos:]
        
        return RedactionResult(
            redacted_text=redacted_text,
            redactions_made=redactions_made,
            redaction_map=redaction_map,
            phi_detected=len(redactions_made) > 0
        )
    
    def create_audit_log(self, redaction_result: RedactionResult, document_id: Optional[int] = None) -> Dict:
        """Create audit log entry for PHI redaction"""
        return {
            "timestamp": datetime.utcnow().isoformat(),
            "document_id": document_id,
            "phi_detected": redaction_result.phi_detected,
            "redaction_count": len(redaction_result.redactions_made),
            "phi_types": list(set(r["type"] for r in redaction_result.redactions_made)),
            "redaction_summary": [
                {
                    "type": r["type"],
                    "position": r["position"]
                } for r in redaction_result.redactions_made
            ]
        }
    
    def safe_for_ai_processing(self, text: str, document_id: Optional[int] = None) -> Tuple[str, Dict]:
        """
        Prepare text for safe AI processing by removing PHI
        Returns: (redacted_text, audit_log)
        """
        redaction_result = self.redact_text(text)
        audit_log = self.create_audit_log(redaction_result, document_id)
        
        return redaction_result.redacted_text, audit_log
    
    def check_compliance(self, text: str) -> Dict[str, any]:
        """
        Check if text appears to be HIPAA compliant (no PHI detected)
        """
        phi_matches = self.detect_phi(text)
        
        return {
            "compliant": len(phi_matches) == 0,
            "phi_detected": len(phi_matches) > 0,
            "phi_count": len(phi_matches),
            "phi_types": list(set(match[0] for match in phi_matches)),
            "risk_level": "high" if len(phi_matches) > 5 else "medium" if len(phi_matches) > 0 else "low"
        }


# Medical-specific PHI patterns
MEDICAL_PHI_PATTERNS = {
    "patient_id": r"\b(?:Patient|Pt)\s*(?:ID|#)\s*:?\s*\d+\b",
    "dob_medical": r"\b(?:DOB|Date\s+of\s+Birth|Born)\s*:?\s*\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b",
    "diagnosis_codes": r"\b[A-Z]\d{2}(?:\.\d{1,2})?\b",  # ICD codes
    "procedure_codes": r"\b\d{5}\b",  # CPT codes
    "medication_doses": r"\b\d+\s*(?:mg|mcg|ml|cc|units?)\b",
    "vital_signs": r"\b(?:BP|Blood\s+Pressure)\s*:?\s*\d{2,3}/\d{2,3}\b"
}


class MedicalPHIRedactor(PHIRedactor):
    """Specialized PHI redactor for medical documents"""
    
    def __init__(self):
        super().__init__()
        self.all_patterns.update(MEDICAL_PHI_PATTERNS)


# Global instances
phi_redactor = PHIRedactor()
medical_phi_redactor = MedicalPHIRedactor()