"""
Document Classifier Service using GPT-4o mini
Cost-optimized document type classification for legal intake
"""

import json
import time
from typing import Dict, List, Any, Optional
import logging

from services.llm_router.router import llm_router
from utils.phi_redaction import phi_redactor
from config.settings import DOCUMENT_TYPES

logger = logging.getLogger(__name__)


class DocumentClassifier:
    """
    AI-powered document classification optimized for legal intake
    """
    
    def __init__(self):
        self.document_types = DOCUMENT_TYPES
        self.classification_prompts = {
            "medical_record": {
                "keywords": ["patient", "diagnosis", "treatment", "medication", "doctor", "hospital", "clinic", "medical", "health"],
                "patterns": ["MRN", "DOB", "BP", "mg", "ml", "diagnosis", "prescription"]
            },
            "police_report": {
                "keywords": ["incident", "officer", "police", "report number", "citation", "arrest", "violation", "accident"],
                "patterns": ["incident #", "case #", "officer", "badge", "violation"]
            },
            "insurance_document": {
                "keywords": ["policy", "claim", "coverage", "premium", "deductible", "insurance", "carrier", "adjuster"],
                "patterns": ["policy #", "claim #", "effective date", "coverage"]
            },
            "employment_record": {
                "keywords": ["employee", "employer", "wage", "salary", "W-2", "1099", "payroll", "benefits", "HR"],
                "patterns": ["employee id", "ssn", "wage", "salary", "benefits"]
            },
            "correspondence": {
                "keywords": ["dear", "sincerely", "regards", "letter", "email", "memo", "communication"],
                "patterns": ["dear", "sincerely", "best regards", "cc:", "subject:"]
            },
            "bill_invoice": {
                "keywords": ["invoice", "bill", "amount due", "payment", "total", "charges", "balance", "statement"],
                "patterns": ["$", "total:", "amount due:", "invoice #", "due date"]
            },
            "legal_document": {
                "keywords": ["attorney", "lawyer", "legal", "court", "case", "lawsuit", "contract", "agreement", "complaint"],
                "patterns": ["attorney", "esq.", "court", "case no.", "plaintiff", "defendant"]
            }
        }
    
    def extract_classification_features(self, content: str) -> Dict[str, Any]:
        """Extract features for document classification"""
        content_lower = content.lower()
        
        features = {
            "length": len(content),
            "word_count": len(content.split()),
            "has_letterhead": any(word in content_lower for word in ["letterhead", "header", "logo"]),
            "has_signature": any(word in content_lower for word in ["signature", "signed", "sincerely"]),
            "has_medical_terms": 0,
            "has_legal_terms": 0,
            "has_financial_terms": 0,
            "document_structure": self._analyze_structure(content)
        }
        
        # Count medical terms
        medical_terms = ["patient", "diagnosis", "treatment", "medication", "doctor", "medical", "hospital"]
        features["has_medical_terms"] = sum(1 for term in medical_terms if term in content_lower)
        
        # Count legal terms
        legal_terms = ["attorney", "court", "legal", "case", "lawsuit", "contract", "agreement"]
        features["has_legal_terms"] = sum(1 for term in legal_terms if term in content_lower)
        
        # Count financial terms
        financial_terms = ["payment", "bill", "invoice", "amount", "total", "cost", "fee"]
        features["has_financial_terms"] = sum(1 for term in financial_terms if term in content_lower)
        
        return features
    
    def _analyze_structure(self, content: str) -> Dict[str, bool]:
        """Analyze document structure patterns"""
        return {
            "has_form_fields": ":" in content and content.count(":") > 5,
            "has_tables": content.count("|") > 10 or content.count("\t") > 10,
            "has_dates": len([line for line in content.split("\n") if any(char.isdigit() for char in line)]) > 0,
            "has_addresses": "street" in content.lower() or "avenue" in content.lower() or "road" in content.lower(),
            "has_phone_numbers": any(char.isdigit() for char in content) and ("(" in content or "-" in content)
        }
    
    async def classify_document(
        self,
        content: str,
        document_id: Optional[int] = None,
        use_phi_redaction: bool = True
    ) -> Dict[str, Any]:
        """
        Main document classification method
        """
        start_time = time.time()
        
        try:
            # PHI redaction if enabled
            original_content = content
            phi_audit = None
            
            if use_phi_redaction:
                content, phi_audit = phi_redactor.safe_for_ai_processing(content, document_id)
            
            # Extract features for analysis
            features = self.extract_classification_features(content)
            
            # Prepare classification prompt
            system_prompt = self._build_classification_prompt()
            
            # Execute AI classification
            ai_result = await llm_router.execute_routed_request(
                task_type="document_classification",
                content=content,
                system_prompt=system_prompt,
                max_tokens=300,
                temperature=0.1
            )
            
            # Parse AI response
            classification_result = self._parse_ai_response(ai_result["content"])
            
            # Add rule-based backup classification
            rule_based_result = self._rule_based_classification(original_content, features)
            
            # Combine AI and rule-based results
            final_result = self._combine_classification_results(
                classification_result, 
                rule_based_result, 
                features
            )
            
            # Build complete response
            response = {
                "document_id": document_id,
                "classification": final_result,
                "features": features,
                "ai_analysis": {
                    "model_used": ai_result.get("model", "unknown"),
                    "processing_time": ai_result.get("processing_time", 0),
                    "token_usage": ai_result.get("usage", {}),
                    "routing_decision": ai_result.get("routing_decision", {})
                },
                "rule_based_backup": rule_based_result,
                "phi_audit": phi_audit,
                "processing_time": time.time() - start_time,
                "status": "completed"
            }
            
            return response
            
        except Exception as e:
            logger.error(f"Document classification failed: {e}")
            return {
                "document_id": document_id,
                "status": "failed",
                "error": str(e),
                "processing_time": time.time() - start_time
            }
    
    def _build_classification_prompt(self) -> str:
        """Build the system prompt for document classification"""
        doc_types = list(self.document_types.keys())
        
        return f"""You are a legal document classifier specializing in personal injury law firm intake documents.

Classify the document into one of these categories:
{', '.join(doc_types)}

For each document, analyze:
1. Content type and purpose
2. Key identifiers and patterns
3. Professional formatting and structure
4. Specific terminology used

Return a JSON response with:
{{
    "document_type": "category_name",
    "confidence_score": 0.95,
    "reasoning": "explanation of classification decision",
    "key_indicators": ["list", "of", "key", "terms", "found"],
    "alternative_classifications": [
        {{"type": "alternative", "confidence": 0.15}}
    ]
}}

Be especially careful to distinguish between:
- Medical records vs. medical bills
- Legal documents vs. correspondence from attorneys
- Insurance documents vs. insurance correspondence
- Employment records vs. employment-related correspondence

Base your classification on document structure, terminology, and purpose rather than just keywords."""
    
    def _parse_ai_response(self, ai_response: str) -> Dict[str, Any]:
        """Parse and validate AI classification response"""
        try:
            # Try to parse JSON response
            response_data = json.loads(ai_response.strip())
            
            # Validate required fields
            required_fields = ["document_type", "confidence_score"]
            for field in required_fields:
                if field not in response_data:
                    raise ValueError(f"Missing required field: {field}")
            
            # Validate document type
            if response_data["document_type"] not in self.document_types:
                response_data["document_type"] = "other"
            
            # Ensure confidence score is valid
            confidence = float(response_data["confidence_score"])
            if not 0 <= confidence <= 1:
                response_data["confidence_score"] = max(0, min(1, confidence))
            
            return response_data
            
        except (json.JSONDecodeError, ValueError, KeyError) as e:
            logger.warning(f"Failed to parse AI response: {e}")
            # Return default classification
            return {
                "document_type": "other",
                "confidence_score": 0.1,
                "reasoning": f"AI response parsing failed: {str(e)}",
                "key_indicators": [],
                "alternative_classifications": []
            }
    
    def _rule_based_classification(self, content: str, features: Dict[str, Any]) -> Dict[str, Any]:
        """Rule-based classification as backup/validation"""
        content_lower = content.lower()
        scores = {}
        
        # Score each document type based on keywords and patterns
        for doc_type, config in self.classification_prompts.items():
            score = 0
            matched_keywords = []
            matched_patterns = []
            
            # Keyword matching
            for keyword in config["keywords"]:
                if keyword in content_lower:
                    score += 1
                    matched_keywords.append(keyword)
            
            # Pattern matching
            for pattern in config["patterns"]:
                if pattern.lower() in content_lower:
                    score += 2  # Patterns are more specific
                    matched_patterns.append(pattern)
            
            scores[doc_type] = {
                "score": score,
                "matched_keywords": matched_keywords,
                "matched_patterns": matched_patterns
            }
        
        # Find best match
        if scores:
            best_match = max(scores.items(), key=lambda x: x[1]["score"])
            best_type, best_data = best_match
            
            if best_data["score"] > 0:
                confidence = min(0.9, best_data["score"] / 10.0)  # Cap at 0.9
                return {
                    "document_type": best_type,
                    "confidence_score": confidence,
                    "reasoning": f"Rule-based: {best_data['score']} matching indicators",
                    "matched_keywords": best_data["matched_keywords"],
                    "matched_patterns": best_data["matched_patterns"]
                }
        
        # Default classification
        return {
            "document_type": "other",
            "confidence_score": 0.1,
            "reasoning": "No clear classification indicators found",
            "matched_keywords": [],
            "matched_patterns": []
        }
    
    def _combine_classification_results(
        self,
        ai_result: Dict[str, Any],
        rule_result: Dict[str, Any],
        features: Dict[str, Any]
    ) -> Dict[str, Any]:
        """Combine AI and rule-based classification results"""
        
        ai_confidence = ai_result.get("confidence_score", 0)
        rule_confidence = rule_result.get("confidence_score", 0)
        
        # If both agree and have reasonable confidence, use AI result
        if (ai_result["document_type"] == rule_result["document_type"] and 
            ai_confidence > 0.6 and rule_confidence > 0.3):
            
            combined_confidence = min(0.95, (ai_confidence + rule_confidence) / 2 + 0.1)
            return {
                "document_type": ai_result["document_type"],
                "confidence_score": combined_confidence,
                "reasoning": f"AI and rule-based agreement: {ai_result.get('reasoning', '')}",
                "classification_method": "consensus",
                "ai_confidence": ai_confidence,
                "rule_confidence": rule_confidence
            }
        
        # If AI has high confidence, use AI result
        elif ai_confidence > 0.7:
            return {
                "document_type": ai_result["document_type"],
                "confidence_score": ai_confidence,
                "reasoning": ai_result.get("reasoning", "High AI confidence"),
                "classification_method": "ai_primary",
                "rule_based_suggestion": rule_result["document_type"]
            }
        
        # If rule-based has higher confidence, use it
        elif rule_confidence > ai_confidence:
            return {
                "document_type": rule_result["document_type"],
                "confidence_score": rule_confidence,
                "reasoning": rule_result.get("reasoning", "Rule-based classification"),
                "classification_method": "rule_based",
                "ai_suggestion": ai_result["document_type"]
            }
        
        # Default to AI result with low confidence flag
        else:
            return {
                "document_type": ai_result["document_type"],
                "confidence_score": max(ai_confidence, 0.2),
                "reasoning": "Low confidence classification - manual review recommended",
                "classification_method": "ai_low_confidence",
                "requires_manual_review": True
            }
    
    async def batch_classify(
        self,
        documents: List[Dict[str, Any]],
        batch_size: int = 5
    ) -> List[Dict[str, Any]]:
        """Classify multiple documents in batches"""
        results = []
        
        for i in range(0, len(documents), batch_size):
            batch = documents[i:i + batch_size]
            batch_results = []
            
            # Process batch concurrently
            import asyncio
            tasks = [
                self.classify_document(
                    doc["content"], 
                    doc.get("document_id")
                ) for doc in batch
            ]
            
            batch_results = await asyncio.gather(*tasks, return_exceptions=True)
            
            for result in batch_results:
                if isinstance(result, Exception):
                    results.append({
                        "status": "failed",
                        "error": str(result)
                    })
                else:
                    results.append(result)
        
        return results


# Global classifier instance
document_classifier = DocumentClassifier()