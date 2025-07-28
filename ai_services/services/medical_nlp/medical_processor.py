"""
Medical NLP Service using GPT-4o mini
Cost-optimized medical record analysis and information extraction
"""

import json
import re
import time
from typing import Dict, List, Any, Optional, Tuple
from datetime import datetime
import logging

from services.llm_router.router import llm_router
from utils.phi_redaction import medical_phi_redactor
from config.settings import settings

logger = logging.getLogger(__name__)


class MedicalProcessor:
    """
    AI-powered medical record analysis and information extraction
    Optimized for personal injury law firms
    """
    
    def __init__(self):
        self.medical_specialties = {
            "orthopedic": ["bone", "joint", "fracture", "spine", "orthopedic", "musculoskeletal"],
            "neurological": ["brain", "nerve", "neurological", "concussion", "tbi", "spinal cord"],
            "cardiology": ["heart", "cardiac", "cardiovascular", "chest pain", "heart rate"],
            "emergency": ["emergency", "er", "trauma", "acute", "emergency room"],
            "physical_therapy": ["pt", "physical therapy", "rehabilitation", "therapy", "exercise"],
            "radiology": ["x-ray", "mri", "ct scan", "ultrasound", "imaging", "radiological"],
            "pain_management": ["pain", "chronic", "pain management", "injection", "nerve block"]
        }
        
        self.severity_indicators = {
            "severe": ["severe", "acute", "critical", "emergent", "urgent", "significant"],
            "moderate": ["moderate", "notable", "concerning", "substantial"],
            "mild": ["mild", "minor", "slight", "minimal", "small"]
        }
        
        # Common medical abbreviations and their meanings
        self.medical_abbreviations = {
            "BP": "Blood Pressure",
            "HR": "Heart Rate",
            "RR": "Respiratory Rate",
            "temp": "Temperature",
            "O2 sat": "Oxygen Saturation",
            "PT": "Physical Therapy",
            "OT": "Occupational Therapy",
            "ROM": "Range of Motion",
            "DTR": "Deep Tendon Reflexes",
            "LOC": "Loss of Consciousness",
            "c/o": "complains of",
            "s/p": "status post",
            "h/o": "history of"
        }
    
    def extract_medical_entities(self, content: str) -> Dict[str, List[str]]:
        """Extract medical entities using pattern matching"""
        content_lower = content.lower()
        
        entities = {
            "medications": [],
            "procedures": [],
            "conditions": [],
            "body_parts": [],
            "dates": [],
            "providers": [],
            "measurements": []
        }
        
        # Extract medications (patterns like "mg", "mcg", etc.)
        med_patterns = [
            r'\b\w+\s+\d+\s*(?:mg|mcg|ml|cc|units?)\b',
            r'\b(?:aspirin|ibuprofen|acetaminophen|morphine|oxycodone|tramadol)\b',
            r'\b\w+(?:cillin|mycin|prazole|statin)\b'
        ]
        
        for pattern in med_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            entities["medications"].extend(matches)
        
        # Extract dates
        date_patterns = [
            r'\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b',
            r'\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4}\b'
        ]
        
        for pattern in date_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            entities["dates"].extend(matches)
        
        # Extract measurements and vital signs
        measurement_patterns = [
            r'\b\d+/\d+\s*(?:mmHg|mm Hg)?\b',  # Blood pressure
            r'\b\d+\s*(?:bpm|beats per minute)\b',  # Heart rate
            r'\b\d+\.\d+\s*(?:°F|°C|degrees)\b',  # Temperature
            r'\b\d+%\s*(?:O2|oxygen)\b'  # Oxygen saturation
        ]
        
        for pattern in measurement_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            entities["measurements"].extend(matches)
        
        return entities
    
    def analyze_injury_severity(self, content: str) -> Dict[str, Any]:
        """Analyze injury severity based on medical terminology"""
        content_lower = content.lower()
        severity_scores = {"severe": 0, "moderate": 0, "mild": 0}
        
        for severity, indicators in self.severity_indicators.items():
            for indicator in indicators:
                severity_scores[severity] += content_lower.count(indicator)
        
        # Determine overall severity
        max_severity = max(severity_scores, key=severity_scores.get)
        total_indicators = sum(severity_scores.values())
        
        if total_indicators == 0:
            return {
                "overall_severity": "unknown",
                "confidence": 0.0,
                "indicators_found": 0,
                "severity_breakdown": severity_scores
            }
        
        confidence = severity_scores[max_severity] / total_indicators
        
        return {
            "overall_severity": max_severity,
            "confidence": confidence,
            "indicators_found": total_indicators,
            "severity_breakdown": severity_scores
        }
    
    def identify_medical_specialty(self, content: str) -> List[Dict[str, Any]]:
        """Identify relevant medical specialties based on content"""
        content_lower = content.lower()
        specialty_scores = {}
        
        for specialty, keywords in self.medical_specialties.items():
            score = 0
            matched_terms = []
            
            for keyword in keywords:
                count = content_lower.count(keyword)
                if count > 0:
                    score += count
                    matched_terms.append(keyword)
            
            if score > 0:
                specialty_scores[specialty] = {
                    "score": score,
                    "matched_terms": matched_terms,
                    "relevance": min(1.0, score / 10.0)  # Normalize to 0-1
                }
        
        # Sort by relevance
        sorted_specialties = sorted(
            specialty_scores.items(),
            key=lambda x: x[1]["score"],
            reverse=True
        )
        
        return [
            {
                "specialty": specialty,
                "relevance_score": data["relevance"],
                "matched_terms": data["matched_terms"]
            }
            for specialty, data in sorted_specialties
        ]
    
    async def extract_medical_information(
        self,
        content: str,
        document_id: Optional[int] = None,
        use_phi_redaction: bool = True
    ) -> Dict[str, Any]:
        """
        Main medical information extraction method
        """
        start_time = time.time()
        
        try:
            # PHI redaction for HIPAA compliance
            original_content = content
            phi_audit = None
            
            if use_phi_redaction:
                content, phi_audit = medical_phi_redactor.safe_for_ai_processing(
                    content, document_id
                )
            
            # Extract basic entities first
            extracted_entities = self.extract_medical_entities(original_content)
            severity_analysis = self.analyze_injury_severity(original_content)
            specialty_analysis = self.identify_medical_specialty(original_content)
            
            # Prepare AI extraction prompt
            system_prompt = self._build_medical_extraction_prompt()
            
            # Execute AI extraction
            ai_result = await llm_router.execute_routed_request(
                task_type="medical_summarization",
                content=content,
                system_prompt=system_prompt,
                max_tokens=1000,
                temperature=0.1
            )
            
            # Parse AI response
            ai_extraction = self._parse_medical_ai_response(ai_result["content"])
            
            # Combine AI results with rule-based extraction
            combined_results = self._combine_medical_extractions(
                ai_extraction,
                extracted_entities,
                severity_analysis,
                specialty_analysis
            )
            
            # Calculate medical costs if possible
            cost_analysis = self._analyze_medical_costs(original_content, combined_results)
            
            # Build comprehensive response
            response = {
                "document_id": document_id,
                "medical_information": combined_results,
                "severity_analysis": severity_analysis,
                "specialty_relevance": specialty_analysis,
                "cost_analysis": cost_analysis,
                "ai_analysis": {
                    "model_used": ai_result.get("model", "unknown"),
                    "processing_time": ai_result.get("processing_time", 0),
                    "token_usage": ai_result.get("usage", {}),
                    "routing_decision": ai_result.get("routing_decision", {})
                },
                "extracted_entities": extracted_entities,
                "phi_audit": phi_audit,
                "processing_time": time.time() - start_time,
                "status": "completed"
            }
            
            return response
            
        except Exception as e:
            logger.error(f"Medical information extraction failed: {e}")
            return {
                "document_id": document_id,
                "status": "failed",
                "error": str(e),
                "processing_time": time.time() - start_time
            }
    
    def _build_medical_extraction_prompt(self) -> str:
        """Build system prompt for medical information extraction"""
        return """You are a medical information extraction specialist for personal injury law firms. 

Extract the following information from medical records and return as JSON:

{
    "patient_info": {
        "age_mentioned": "if age is mentioned",
        "gender": "if gender is mentioned"
    },
    "diagnoses": [
        {
            "condition": "diagnosis name",
            "icd_code": "if mentioned",
            "severity": "mild/moderate/severe",
            "date_diagnosed": "if mentioned"
        }
    ],
    "procedures": [
        {
            "procedure": "procedure name",
            "cpt_code": "if mentioned", 
            "date": "if mentioned",
            "provider": "if mentioned"
        }
    ],
    "medications": [
        {
            "medication": "drug name",
            "dosage": "dose if mentioned",
            "frequency": "how often",
            "start_date": "if mentioned"
        }
    ],
    "symptoms": [
        {
            "symptom": "symptom description",
            "severity": "mild/moderate/severe",
            "duration": "if mentioned",
            "location": "body part if specified"
        }
    ],
    "vital_signs": {
        "blood_pressure": "if mentioned",
        "heart_rate": "if mentioned",
        "temperature": "if mentioned",
        "oxygen_saturation": "if mentioned"
    },
    "treatment_plan": {
        "current_treatment": "ongoing treatments",
        "recommended_treatment": "future recommendations",
        "restrictions": "activity restrictions",
        "follow_up": "follow-up plans"
    },
    "prognosis": {
        "short_term": "immediate outlook",
        "long_term": "long-term prognosis",
        "return_to_work": "work capacity assessment",
        "permanent_impairment": "if mentioned"
    },
    "medical_summary": "Brief summary of medical findings and treatment"
}

Focus on information relevant to personal injury claims. Be conservative in your assessments and only include information explicitly stated in the document."""
    
    def _parse_medical_ai_response(self, ai_response: str) -> Dict[str, Any]:
        """Parse and validate AI medical extraction response"""
        try:
            response_data = json.loads(ai_response.strip())
            
            # Validate and standardize structure
            standardized = {
                "patient_info": response_data.get("patient_info", {}),
                "diagnoses": response_data.get("diagnoses", []),
                "procedures": response_data.get("procedures", []),
                "medications": response_data.get("medications", []),
                "symptoms": response_data.get("symptoms", []),
                "vital_signs": response_data.get("vital_signs", {}),
                "treatment_plan": response_data.get("treatment_plan", {}),
                "prognosis": response_data.get("prognosis", {}),
                "medical_summary": response_data.get("medical_summary", "")
            }
            
            return standardized
            
        except (json.JSONDecodeError, ValueError) as e:
            logger.warning(f"Failed to parse medical AI response: {e}")
            return {
                "patient_info": {},
                "diagnoses": [],
                "procedures": [],
                "medications": [],
                "symptoms": [],
                "vital_signs": {},
                "treatment_plan": {},
                "prognosis": {},
                "medical_summary": "AI response parsing failed",
                "parsing_error": str(e)
            }
    
    def _combine_medical_extractions(
        self,
        ai_extraction: Dict[str, Any],
        rule_entities: Dict[str, List[str]],
        severity_analysis: Dict[str, Any],
        specialty_analysis: List[Dict[str, Any]]
    ) -> Dict[str, Any]:
        """Combine AI and rule-based medical extractions"""
        
        combined = ai_extraction.copy()
        
        # Enhance with rule-based entities
        if rule_entities["medications"] and not combined["medications"]:
            combined["medications"] = [
                {"medication": med, "source": "pattern_extraction"}
                for med in rule_entities["medications"][:10]  # Limit to top 10
            ]
        
        # Add extracted dates to relevant sections
        if rule_entities["dates"]:
            combined["extracted_dates"] = rule_entities["dates"]
        
        # Add measurements
        if rule_entities["measurements"]:
            if not combined["vital_signs"]:
                combined["vital_signs"] = {}
            combined["vital_signs"]["extracted_measurements"] = rule_entities["measurements"]
        
        # Add severity and specialty analysis
        combined["severity_assessment"] = severity_analysis
        combined["specialty_relevance"] = specialty_analysis
        
        return combined
    
    def _analyze_medical_costs(
        self,
        content: str,
        medical_info: Dict[str, Any]
    ) -> Dict[str, Any]:
        """Analyze and estimate medical costs from the document"""
        
        # Extract dollar amounts
        cost_patterns = [
            r'\$\s*\d{1,3}(?:,\d{3})*(?:\.\d{2})?',
            r'\b\d{1,3}(?:,\d{3})*(?:\.\d{2})?\s*dollars?\b'
        ]
        
        found_amounts = []
        for pattern in cost_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            found_amounts.extend(matches)
        
        # Convert to numerical values
        numerical_amounts = []
        for amount in found_amounts:
            try:
                # Clean and convert
                clean_amount = re.sub(r'[^\d.]', '', amount)
                if clean_amount:
                    numerical_amounts.append(float(clean_amount))
            except ValueError:
                continue
        
        cost_analysis = {
            "amounts_found": found_amounts,
            "total_identifiable_costs": sum(numerical_amounts),
            "cost_categories": {
                "emergency_room": 0,
                "hospital_stay": 0,
                "procedures": 0,
                "medications": 0,
                "therapy": 0,
                "imaging": 0
            },
            "cost_indicators": []
        }
        
        # Categorize costs based on context
        content_lower = content.lower()
        
        if "emergency" in content_lower or "er" in content_lower:
            cost_analysis["cost_categories"]["emergency_room"] = 1
            cost_analysis["cost_indicators"].append("Emergency room visit indicated")
        
        if any(term in content_lower for term in ["surgery", "operation", "procedure"]):
            cost_analysis["cost_categories"]["procedures"] = 1
            cost_analysis["cost_indicators"].append("Surgical procedures indicated")
        
        if any(term in content_lower for term in ["mri", "ct scan", "x-ray", "ultrasound"]):
            cost_analysis["cost_categories"]["imaging"] = 1
            cost_analysis["cost_indicators"].append("Medical imaging performed")
        
        if any(term in content_lower for term in ["physical therapy", "pt", "rehabilitation"]):
            cost_analysis["cost_categories"]["therapy"] = 1
            cost_analysis["cost_indicators"].append("Therapy services indicated")
        
        return cost_analysis
    
    def create_medical_timeline(self, medical_info: Dict[str, Any]) -> List[Dict[str, Any]]:
        """Create a timeline of medical events"""
        timeline_events = []
        
        # Add diagnoses with dates
        for diagnosis in medical_info.get("diagnoses", []):
            if diagnosis.get("date_diagnosed"):
                timeline_events.append({
                    "date": diagnosis["date_diagnosed"],
                    "event_type": "diagnosis",
                    "description": diagnosis["condition"],
                    "severity": diagnosis.get("severity", "unknown")
                })
        
        # Add procedures with dates
        for procedure in medical_info.get("procedures", []):
            if procedure.get("date"):
                timeline_events.append({
                    "date": procedure["date"],
                    "event_type": "procedure",
                    "description": procedure["procedure"],
                    "provider": procedure.get("provider", "unknown")
                })
        
        # Sort by date (this would need proper date parsing)
        return timeline_events
    
    async def generate_medical_summary(
        self,
        medical_extractions: List[Dict[str, Any]],
        case_context: Optional[str] = None
    ) -> Dict[str, Any]:
        """Generate a comprehensive medical summary from multiple extractions"""
        
        # Combine all medical information
        combined_info = {
            "all_diagnoses": [],
            "all_procedures": [],
            "all_medications": [],
            "all_symptoms": [],
            "treatment_history": [],
            "cost_summary": {"total_estimated": 0, "categories": {}}
        }
        
        for extraction in medical_extractions:
            if extraction.get("status") == "completed":
                med_info = extraction.get("medical_information", {})
                combined_info["all_diagnoses"].extend(med_info.get("diagnoses", []))
                combined_info["all_procedures"].extend(med_info.get("procedures", []))
                combined_info["all_medications"].extend(med_info.get("medications", []))
                combined_info["all_symptoms"].extend(med_info.get("symptoms", []))
        
        # Generate summary using AI
        summary_prompt = f"""Create a comprehensive medical summary for a personal injury case based on the following medical information:

{json.dumps(combined_info, indent=2)}

Case context: {case_context or 'Personal injury case'}

Provide a structured summary including:
1. Primary injuries and conditions
2. Treatment received
3. Current medical status
4. Prognosis and future care needs
5. Impact on daily activities and work capacity
6. Estimated total medical costs
7. Relevance to personal injury claim

Focus on information most relevant to legal proceedings."""
        
        summary_result = await llm_router.execute_routed_request(
            task_type="medical_summarization",
            content=json.dumps(combined_info),
            system_prompt=summary_prompt,
            max_tokens=1200,
            temperature=0.1
        )
        
        return {
            "comprehensive_summary": summary_result.get("content", ""),
            "combined_medical_data": combined_info,
            "processing_metadata": {
                "documents_analyzed": len(medical_extractions),
                "successful_extractions": len([e for e in medical_extractions if e.get("status") == "completed"]),
                "model_used": summary_result.get("model", "unknown"),
                "token_usage": summary_result.get("usage", {})
            }
        }


# Global medical processor instance
medical_processor = MedicalProcessor()