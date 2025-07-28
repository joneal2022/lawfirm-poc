"""
Legal NLP Service using Claude 3.5 Sonnet
Advanced legal document analysis and reasoning for personal injury cases
"""

import json
import re
import time
from typing import Dict, List, Any, Optional, Tuple
from datetime import datetime, timedelta
import logging

from services.llm_router.router import llm_router
from utils.phi_redaction import phi_redactor
from config.settings import settings

logger = logging.getLogger(__name__)


class LegalAnalyzer:
    """
    Advanced legal document analysis using Claude 3.5 Sonnet
    Specialized for personal injury law
    """
    
    def __init__(self):
        # Legal theories relevant to personal injury
        self.legal_theories = {
            "negligence": {
                "elements": ["duty", "breach", "causation", "damages"],
                "keywords": ["negligence", "duty of care", "reasonable person", "standard of care", "breach"],
                "description": "Failure to exercise reasonable care"
            },
            "strict_liability": {
                "elements": ["defective product", "unreasonably dangerous", "causation", "damages"],
                "keywords": ["strict liability", "product liability", "defective", "manufacturing defect"],
                "description": "Liability without fault for defective products"
            },
            "intentional_tort": {
                "elements": ["intent", "act", "causation", "damages"],
                "keywords": ["intentional", "assault", "battery", "false imprisonment", "intentional infliction"],
                "description": "Intentional wrongful acts"
            },
            "premises_liability": {
                "elements": ["duty to invitee/licensee", "dangerous condition", "notice", "causation", "damages"],
                "keywords": ["premises liability", "slip and fall", "dangerous condition", "invitee", "licensee"],
                "description": "Landowner liability for dangerous conditions"
            },
            "motor_vehicle": {
                "elements": ["duty", "breach", "causation", "damages"],
                "keywords": ["motor vehicle", "car accident", "traffic violation", "drunk driving", "reckless driving"],
                "description": "Vehicle-related negligence"
            },
            "medical_malpractice": {
                "elements": ["doctor-patient relationship", "standard of care", "breach", "causation", "damages"],
                "keywords": ["medical malpractice", "standard of care", "medical negligence", "physician", "treatment"],
                "description": "Professional medical negligence"
            }
        }
        
        # Statute of limitations by state (common periods)
        self.statute_of_limitations = {
            "personal_injury": {"default": 2, "range": "1-6 years"},
            "medical_malpractice": {"default": 2, "range": "1-4 years"},
            "product_liability": {"default": 2, "range": "1-4 years"},
            "motor_vehicle": {"default": 2, "range": "1-6 years"},
            "premises_liability": {"default": 2, "range": "1-4 years"}
        }
        
        # Liability factors and indicators
        self.liability_indicators = {
            "strong_liability": [
                "violated traffic law", "driving under influence", "criminal conviction",
                "admitted fault", "cited by police", "clear violation", "obvious negligence"
            ],
            "moderate_liability": [
                "traffic citation", "witness statements", "physical evidence",
                "safety violation", "policy violation", "inattentive"
            ],
            "weak_liability": [
                "disputed facts", "conflicting accounts", "no witnesses",
                "mutual fault", "unclear causation", "act of god"
            ]
        }
        
        # Damage categories for valuation
        self.damage_categories = {
            "economic": [
                "medical expenses", "lost wages", "property damage", "rehabilitation costs",
                "future medical care", "loss of earning capacity", "out of pocket expenses"
            ],
            "non_economic": [
                "pain and suffering", "emotional distress", "loss of consortium",
                "loss of enjoyment", "disfigurement", "mental anguish", "inconvenience"
            ],
            "punitive": [
                "willful misconduct", "gross negligence", "malicious conduct",
                "drunk driving", "intentional harm", "reckless disregard"
            ]
        }
    
    def extract_legal_entities(self, content: str) -> Dict[str, List[str]]:
        """Extract legal entities using pattern matching"""
        content_lower = content.lower()
        
        entities = {
            "parties": [],
            "dates": [],
            "case_numbers": [],
            "statutes": [],
            "legal_citations": [],
            "damages_mentioned": [],
            "locations": []
        }
        
        # Extract case numbers and legal citations
        case_patterns = [
            r'\b(?:case|docket|file)\s*(?:no\.?|number)\s*:?\s*[\w\d-]+\b',
            r'\b\d{4}\s+WL\s+\d+\b',  # Westlaw citations
            r'\b\d+\s+F\.\d+d?\s+\d+\b',  # Federal Reporter citations
            r'\b\d+\s+[A-Z][a-z\.]*\s+\d+\b'  # State reporter citations
        ]
        
        for pattern in case_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            entities["case_numbers"].extend(matches)
        
        # Extract statute references
        statute_patterns = [
            r'\b\d+\s+U\.?S\.?C\.?\s+§?\s*\d+\b',  # USC
            r'\b[A-Z][a-z\.]*\s+(?:Code|Stat\.?)\s+§?\s*\d+\b',  # State codes
            r'\bC\.?F\.?R\.?\s+§?\s*\d+\.\d+\b'  # CFR
        ]
        
        for pattern in statute_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            entities["statutes"].extend(matches)
        
        # Extract damage amounts
        damage_patterns = [
            r'\$\s*\d{1,3}(?:,\d{3})*(?:\.\d{2})?',
            r'\b\d{1,3}(?:,\d{3})*(?:\.\d{2})?\s*dollars?\b',
            r'\bmillion\s+dollars?\b',
            r'\bthousand\s+dollars?\b'
        ]
        
        for pattern in damage_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            entities["damages_mentioned"].extend(matches)
        
        return entities
    
    def identify_legal_theories(self, content: str) -> List[Dict[str, Any]]:
        """Identify applicable legal theories based on content analysis"""
        content_lower = content.lower()
        theory_scores = {}
        
        for theory_name, theory_data in self.legal_theories.items():
            score = 0
            matched_keywords = []
            matched_elements = []
            
            # Score based on keywords
            for keyword in theory_data["keywords"]:
                if keyword in content_lower:
                    score += 2
                    matched_keywords.append(keyword)
            
            # Score based on legal elements mentioned
            for element in theory_data["elements"]:
                if element in content_lower:
                    score += 3  # Elements are more important
                    matched_elements.append(element)
            
            if score > 0:
                confidence = min(1.0, score / 15.0)  # Normalize confidence
                theory_scores[theory_name] = {
                    "score": score,
                    "confidence": confidence,
                    "matched_keywords": matched_keywords,
                    "matched_elements": matched_elements,
                    "description": theory_data["description"],
                    "required_elements": theory_data["elements"]
                }
        
        # Sort by score and return top theories
        sorted_theories = sorted(
            theory_scores.items(),
            key=lambda x: x[1]["score"],
            reverse=True
        )
        
        return [
            {
                "theory": theory,
                "confidence": data["confidence"],
                "matched_keywords": data["matched_keywords"],
                "matched_elements": data["matched_elements"],
                "description": data["description"],
                "required_elements": data["required_elements"],
                "elements_analysis": self._analyze_elements(data["required_elements"], content)
            }
            for theory, data in sorted_theories
        ]
    
    def _analyze_elements(self, required_elements: List[str], content: str) -> Dict[str, Any]:
        """Analyze how well legal elements are supported by the document"""
        content_lower = content.lower()
        elements_analysis = {}
        
        for element in required_elements:
            # Simple keyword-based analysis (would be enhanced with more sophisticated NLP)
            element_keywords = {
                "duty": ["duty", "obligation", "responsibility", "owe", "standard"],
                "breach": ["breach", "violated", "failed", "negligent", "unreasonable"],
                "causation": ["caused", "result", "because", "due to", "proximately"],
                "damages": ["injury", "harm", "damage", "loss", "hurt", "pain"],
                "intent": ["intentional", "purpose", "deliberate", "on purpose"],
                "defective": ["defect", "defective", "malfunction", "design flaw"]
            }
            
            keywords = element_keywords.get(element, [element])
            matches = sum(1 for keyword in keywords if keyword in content_lower)
            
            elements_analysis[element] = {
                "evidence_strength": "strong" if matches >= 3 else "moderate" if matches >= 1 else "weak",
                "keyword_matches": matches,
                "requires_investigation": matches == 0
            }
        
        return elements_analysis
    
    def assess_liability_strength(self, content: str) -> Dict[str, Any]:
        """Assess the strength of liability based on document content"""
        content_lower = content.lower()
        
        liability_scores = {
            "strong": 0,
            "moderate": 0, 
            "weak": 0
        }
        
        strong_indicators = []
        moderate_indicators = []
        weak_indicators = []
        
        # Check for liability indicators
        for strength, indicators in self.liability_indicators.items():
            for indicator in indicators:
                if indicator in content_lower:
                    if strength == "strong_liability":
                        liability_scores["strong"] += 1
                        strong_indicators.append(indicator)
                    elif strength == "moderate_liability":
                        liability_scores["moderate"] += 1
                        moderate_indicators.append(indicator)
                    else:
                        liability_scores["weak"] += 1
                        weak_indicators.append(indicator)
        
        # Calculate overall liability strength
        total_indicators = sum(liability_scores.values())
        
        if total_indicators == 0:
            overall_strength = "unknown"
            confidence = 0.0
        elif liability_scores["strong"] > liability_scores["moderate"] + liability_scores["weak"]:
            overall_strength = "strong"
            confidence = min(0.9, liability_scores["strong"] / total_indicators + 0.2)
        elif liability_scores["weak"] > liability_scores["strong"] + liability_scores["moderate"]:
            overall_strength = "weak"
            confidence = min(0.8, liability_scores["weak"] / total_indicators + 0.1)
        else:
            overall_strength = "moderate"
            confidence = min(0.7, liability_scores["moderate"] / total_indicators + 0.1)
        
        return {
            "overall_strength": overall_strength,
            "confidence": confidence,
            "indicator_breakdown": liability_scores,
            "strong_indicators": strong_indicators,
            "moderate_indicators": moderate_indicators,
            "weak_indicators": weak_indicators,
            "total_indicators": total_indicators
        }
    
    def analyze_damages(self, content: str) -> Dict[str, Any]:
        """Analyze damages mentioned in the document"""
        content_lower = content.lower()
        
        damages_analysis = {
            "economic_damages": [],
            "non_economic_damages": [],
            "punitive_potential": False,
            "damage_amounts": [],
            "damage_severity": "unknown"
        }
        
        # Check for different types of damages
        for category, damage_types in self.damage_categories.items():
            found_damages = []
            for damage_type in damage_types:
                if damage_type in content_lower:
                    found_damages.append(damage_type)
            
            if category == "economic":
                damages_analysis["economic_damages"] = found_damages
            elif category == "non_economic":
                damages_analysis["non_economic_damages"] = found_damages
            elif category == "punitive" and found_damages:
                damages_analysis["punitive_potential"] = True
        
        # Extract monetary amounts
        damage_entities = self.extract_legal_entities(content)
        damages_analysis["damage_amounts"] = damage_entities["damages_mentioned"]
        
        # Assess damage severity
        severity_keywords = {
            "severe": ["severe", "catastrophic", "permanent", "disability", "death", "fatal"],
            "moderate": ["significant", "substantial", "serious", "major"],
            "mild": ["minor", "slight", "minimal", "small"]
        }
        
        severity_scores = {}
        for severity, keywords in severity_keywords.items():
            score = sum(1 for keyword in keywords if keyword in content_lower)
            if score > 0:
                severity_scores[severity] = score
        
        if severity_scores:
            damages_analysis["damage_severity"] = max(severity_scores, key=severity_scores.get)
        
        return damages_analysis
    
    def calculate_statute_of_limitations(
        self,
        incident_date: Optional[str],
        case_type: str = "personal_injury",
        state: str = "default"
    ) -> Dict[str, Any]:
        """Calculate statute of limitations deadlines"""
        
        if not incident_date:
            return {
                "status": "unknown",
                "reason": "Incident date not provided",
                "default_period": self.statute_of_limitations.get(case_type, {}).get("default", 2)
            }
        
        try:
            # Parse incident date (basic parsing - would need more robust date parsing)
            if "/" in incident_date:
                month, day, year = map(int, incident_date.split("/"))
                incident_datetime = datetime(year, month, day)
            else:
                # Handle other date formats as needed
                return {"status": "unparseable_date", "incident_date": incident_date}
            
            # Get statute period
            statute_period = self.statute_of_limitations.get(case_type, {}).get("default", 2)
            
            # Calculate deadline
            deadline = incident_datetime + timedelta(days=365 * statute_period)
            days_remaining = (deadline - datetime.now()).days
            
            return {
                "incident_date": incident_datetime.isoformat(),
                "statute_period_years": statute_period,
                "deadline": deadline.isoformat(),
                "days_remaining": days_remaining,
                "status": "expired" if days_remaining < 0 else "urgent" if days_remaining < 90 else "active",
                "urgency_level": "critical" if days_remaining < 30 else "high" if days_remaining < 90 else "normal"
            }
            
        except Exception as e:
            return {
                "status": "calculation_error",
                "error": str(e),
                "incident_date": incident_date
            }
    
    async def analyze_legal_document(
        self,
        content: str,
        document_id: Optional[int] = None,
        case_context: Optional[Dict[str, Any]] = None,
        use_phi_redaction: bool = True
    ) -> Dict[str, Any]:
        """
        Main legal document analysis method using Claude 3.5 Sonnet
        """
        start_time = time.time()
        
        try:
            # PHI redaction if enabled
            original_content = content
            phi_audit = None
            
            if use_phi_redaction:
                content, phi_audit = phi_redactor.safe_for_ai_processing(content, document_id)
            
            # Extract legal entities and patterns
            legal_entities = self.extract_legal_entities(original_content)
            legal_theories = self.identify_legal_theories(original_content)
            liability_assessment = self.assess_liability_strength(original_content)
            damages_analysis = self.analyze_damages(original_content)
            
            # Calculate statute of limitations if incident date available
            statute_analysis = None
            if case_context and case_context.get("incident_date"):
                case_type = case_context.get("case_type", "personal_injury")
                statute_analysis = self.calculate_statute_of_limitations(
                    case_context["incident_date"],
                    case_type
                )
            
            # Prepare advanced legal analysis prompt for Claude
            system_prompt = self._build_legal_analysis_prompt(case_context)
            
            # Execute AI analysis using Claude 3.5 Sonnet
            ai_result = await llm_router.execute_routed_request(
                task_type="legal_reasoning",
                content=content,
                system_prompt=system_prompt,
                max_tokens=1500,
                temperature=0.1,
                context={"requires_reasoning": True, "high_stakes": True}
            )
            
            # Parse AI response
            ai_analysis = self._parse_legal_ai_response(ai_result["content"])
            
            # Combine all analyses
            comprehensive_analysis = self._combine_legal_analyses(
                ai_analysis,
                legal_theories,
                liability_assessment,
                damages_analysis,
                legal_entities,
                statute_analysis
            )
            
            # Build complete response
            response = {
                "document_id": document_id,
                "legal_analysis": comprehensive_analysis,
                "legal_theories": legal_theories,
                "liability_assessment": liability_assessment,
                "damages_analysis": damages_analysis,
                "statute_analysis": statute_analysis,
                "legal_entities": legal_entities,
                "ai_analysis": {
                    "model_used": ai_result.get("model", "unknown"),
                    "processing_time": ai_result.get("processing_time", 0),
                    "token_usage": ai_result.get("usage", {}),
                    "routing_decision": ai_result.get("routing_decision", {})
                },
                "phi_audit": phi_audit,
                "case_context": case_context,
                "processing_time": time.time() - start_time,
                "status": "completed"
            }
            
            return response
            
        except Exception as e:
            logger.error(f"Legal document analysis failed: {e}")
            return {
                "document_id": document_id,
                "status": "failed",
                "error": str(e),
                "processing_time": time.time() - start_time
            }
    
    def _build_legal_analysis_prompt(self, case_context: Optional[Dict[str, Any]] = None) -> str:
        """Build comprehensive legal analysis prompt for Claude"""
        
        context_info = ""
        if case_context:
            context_info = f"\nCase Context: {json.dumps(case_context, indent=2)}"
        
        return f"""You are an expert legal analyst specializing in personal injury law. Analyze this legal document and provide a comprehensive analysis.

{context_info}

Provide your analysis in the following JSON format:

{{
    "document_type_legal": "contract/complaint/discovery/correspondence/report/other",
    "legal_significance": "high/medium/low",
    "key_legal_issues": [
        {{
            "issue": "description of legal issue",
            "importance": "critical/important/minor",
            "analysis": "detailed analysis of the issue"
        }}
    ],
    "applicable_law": {{
        "primary_legal_theories": ["list of applicable legal theories"],
        "relevant_statutes": ["statutes that may apply"],
        "case_law_considerations": "relevant case law principles",
        "jurisdiction_issues": "jurisdictional considerations"
    }},
    "liability_analysis": {{
        "defendant_liability": "strong/moderate/weak/unclear",
        "contributing_factors": ["factors affecting liability"],
        "defenses_available": ["potential defenses"],
        "causation_analysis": "analysis of legal and factual causation"
    }},
    "damages_assessment": {{
        "damages_categories": ["economic/non-economic/punitive"],
        "damage_factors": ["factors affecting damage calculation"],
        "settlement_considerations": "factors affecting settlement value",
        "collectibility": "assessment of defendant's ability to pay"
    }},
    "procedural_considerations": {{
        "statute_of_limitations": "SOL analysis and urgency",
        "venue_jurisdiction": "proper venue and jurisdiction",
        "discovery_needs": ["key discovery items needed"],
        "expert_witnesses": ["types of experts needed"]
    }},
    "strategic_recommendations": {{
        "immediate_actions": ["urgent actions needed"],
        "investigation_priorities": ["key investigation areas"],
        "legal_research": ["areas requiring additional research"],
        "case_strengths": ["strongest aspects of the case"],
        "case_weaknesses": ["potential weaknesses to address"]
    }},
    "overall_assessment": {{
        "case_merit": "excellent/good/fair/poor",
        "complexity_level": "simple/moderate/complex/very complex",
        "estimated_case_value": "high/medium/low/minimal",
        "litigation_recommendation": "file suit/negotiate/investigate further/decline",
        "confidence_level": "high/medium/low"
    }},
    "legal_summary": "Comprehensive summary of legal analysis and recommendations"
}}

Base your analysis on established legal principles, focus on personal injury law, and consider both the strengths and weaknesses of potential claims. Be thorough but practical in your recommendations."""
    
    def _parse_legal_ai_response(self, ai_response: str) -> Dict[str, Any]:
        """Parse and validate Claude's legal analysis response"""
        try:
            response_data = json.loads(ai_response.strip())
            
            # Validate required sections
            required_sections = [
                "document_type_legal", "legal_significance", "key_legal_issues",
                "applicable_law", "liability_analysis", "damages_assessment",
                "procedural_considerations", "strategic_recommendations", "overall_assessment"
            ]
            
            for section in required_sections:
                if section not in response_data:
                    response_data[section] = f"Analysis for {section} not provided"
            
            return response_data
            
        except (json.JSONDecodeError, ValueError) as e:
            logger.warning(f"Failed to parse legal AI response: {e}")
            return {
                "document_type_legal": "unknown",
                "legal_significance": "unknown",
                "parsing_error": str(e),
                "raw_response": ai_response[:500],  # First 500 chars for debugging
                "overall_assessment": {
                    "case_merit": "unknown",
                    "confidence_level": "low"
                }
            }
    
    def _combine_legal_analyses(
        self,
        ai_analysis: Dict[str, Any],
        legal_theories: List[Dict[str, Any]],
        liability_assessment: Dict[str, Any],
        damages_analysis: Dict[str, Any],
        legal_entities: Dict[str, List[str]],
        statute_analysis: Optional[Dict[str, Any]]
    ) -> Dict[str, Any]:
        """Combine AI analysis with rule-based legal analysis"""
        
        combined_analysis = ai_analysis.copy()
        
        # Enhance with rule-based findings
        combined_analysis["rule_based_theories"] = legal_theories
        combined_analysis["rule_based_liability"] = liability_assessment
        combined_analysis["rule_based_damages"] = damages_analysis
        combined_analysis["extracted_entities"] = legal_entities
        
        if statute_analysis:
            combined_analysis["statute_of_limitations_calc"] = statute_analysis
        
        # Create consensus assessment
        ai_case_merit = ai_analysis.get("overall_assessment", {}).get("case_merit", "unknown")
        
        # Determine if there's consensus between AI and rule-based analysis
        rule_liability = liability_assessment.get("overall_strength", "unknown")
        
        consensus_factors = {
            "ai_assessment": ai_case_merit,
            "rule_liability": rule_liability,
            "theories_identified": len(legal_theories),
            "damages_categories": len(damages_analysis.get("economic_damages", [])) + len(damages_analysis.get("non_economic_damages", [])),
            "legal_entities_found": sum(len(entities) for entities in legal_entities.values())
        }
        
        combined_analysis["consensus_assessment"] = self._create_consensus_assessment(consensus_factors)
        
        return combined_analysis
    
    def _create_consensus_assessment(self, factors: Dict[str, Any]) -> Dict[str, Any]:
        """Create consensus assessment from multiple analysis factors"""
        
        # Simple scoring system for POC
        score = 0
        max_score = 0
        
        # AI assessment contribution
        ai_merit = factors.get("ai_assessment", "unknown")
        if ai_merit in ["excellent", "good"]:
            score += 3
        elif ai_merit == "fair":
            score += 2
        elif ai_merit == "poor":
            score += 1
        max_score += 3
        
        # Rule-based liability contribution
        rule_liability = factors.get("rule_liability", "unknown")
        if rule_liability == "strong":
            score += 3
        elif rule_liability == "moderate":
            score += 2
        elif rule_liability == "weak":
            score += 1
        max_score += 3
        
        # Legal theories found
        theories_count = factors.get("theories_identified", 0)
        if theories_count >= 2:
            score += 2
        elif theories_count == 1:
            score += 1
        max_score += 2
        
        # Damages identified
        damages_count = factors.get("damages_categories", 0)
        if damages_count >= 3:
            score += 2
        elif damages_count >= 1:
            score += 1
        max_score += 2
        
        # Calculate consensus confidence
        confidence = score / max_score if max_score > 0 else 0
        
        if confidence >= 0.8:
            consensus_merit = "strong"
        elif confidence >= 0.6:
            consensus_merit = "moderate"
        elif confidence >= 0.4:
            consensus_merit = "weak"
        else:
            consensus_merit = "insufficient"
        
        return {
            "consensus_merit": consensus_merit,
            "confidence_score": confidence,
            "contributing_factors": factors,
            "score_breakdown": f"{score}/{max_score}"
        }


# Global legal analyzer instance
legal_analyzer = LegalAnalyzer()