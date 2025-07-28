"""
Case Merit Analysis Service using Claude 3.5 Sonnet
Advanced case evaluation and recommendation system for personal injury law
"""

import json
import time
import statistics
from typing import Dict, List, Any, Optional, Tuple
from datetime import datetime
import logging

from services.llm_router.router import llm_router
from services.legal_nlp.legal_analyzer import legal_analyzer
from services.medical_nlp.medical_processor import medical_processor
from utils.phi_redaction import phi_redactor
from config.settings import CASE_MERIT_WEIGHTS

logger = logging.getLogger(__name__)


class CaseMeritAnalyzer:
    """
    Comprehensive case merit analysis using Claude 3.5 Sonnet
    Combines legal, medical, and financial analysis for intake decisions
    """
    
    def __init__(self):
        self.merit_weights = CASE_MERIT_WEIGHTS
        
        # Settlement value multipliers by case type
        self.settlement_multipliers = {
            "motor_vehicle": {"min": 1.5, "max": 5.0, "average": 3.0},
            "premises_liability": {"min": 1.8, "max": 4.5, "average": 2.8},
            "medical_malpractice": {"min": 2.0, "max": 8.0, "average": 4.5},
            "product_liability": {"min": 3.0, "max": 10.0, "average": 6.0},
            "workplace_injury": {"min": 1.2, "max": 3.5, "average": 2.2}
        }
        
        # Risk factors that affect case merit
        self.risk_factors = {
            "high_risk": [
                "pre_existing_condition", "disputed_liability", "no_witnesses",
                "poor_documentation", "uninsured_defendant", "comparative_fault",
                "statute_of_limitations_close", "weak_causation"
            ],
            "medium_risk": [
                "insurance_coverage_limits", "economic_damages_only", "minor_injuries",
                "some_comparative_fault", "limited_medical_treatment", "jurisdiction_issues"
            ],
            "low_risk": [
                "clear_liability", "well_documented", "serious_injuries",
                "good_insurance_coverage", "strong_medical_records", "no_fault_issues"
            ]
        }
        
        # Case complexity indicators
        self.complexity_indicators = {
            "simple": ["clear_fault", "single_defendant", "straightforward_injuries", "good_documentation"],
            "moderate": ["multiple_parties", "some_fault_disputes", "moderate_injuries", "standard_medical_care"],
            "complex": ["multiple_defendants", "disputed_facts", "complex_injuries", "expert_testimony_needed"],
            "very_complex": ["class_action_potential", "novel_legal_theory", "catastrophic_injuries", "multi_state_issues"]
        }
    
    def calculate_base_scores(self, case_data: Dict[str, Any]) -> Dict[str, float]:
        """Calculate base scores for each merit component"""
        
        scores = {
            "liability_strength": 50.0,  # Default middle score
            "damages_potential": 0.0,
            "collectibility": 50.0,
            "case_complexity": 50.0,
            "resource_requirements": 50.0,
            "success_probability": 50.0
        }
        
        try:
            # Liability strength from legal analysis
            legal_analysis = case_data.get("legal_analysis", {})
            if legal_analysis:
                liability_assessment = legal_analysis.get("liability_assessment", {})
                strength = liability_assessment.get("overall_strength", "moderate")
                
                if strength == "strong":
                    scores["liability_strength"] = 85.0
                elif strength == "moderate":
                    scores["liability_strength"] = 65.0
                elif strength == "weak":
                    scores["liability_strength"] = 35.0
                else:
                    scores["liability_strength"] = 50.0
            
            # Damages potential from medical analysis
            medical_analysis = case_data.get("medical_analysis", {})
            if medical_analysis:
                severity = medical_analysis.get("severity_analysis", {}).get("overall_severity", "unknown")
                medical_costs = medical_analysis.get("cost_analysis", {}).get("total_identifiable_costs", 0)
                
                # Base damages score on severity and costs
                if severity == "severe" or medical_costs > 100000:
                    scores["damages_potential"] = 90.0
                elif severity == "moderate" or medical_costs > 25000:
                    scores["damages_potential"] = 70.0
                elif severity == "mild" or medical_costs > 5000:
                    scores["damages_potential"] = 45.0
                else:
                    scores["damages_potential"] = 25.0
            
            # Case complexity
            complexity_score = self._assess_complexity(case_data)
            scores["case_complexity"] = complexity_score
            
            # Resource requirements (inverse of complexity for merit)
            scores["resource_requirements"] = max(20.0, 100.0 - complexity_score)
            
            # Success probability (combination of liability and other factors)
            success_factors = [
                scores["liability_strength"],
                scores["damages_potential"],
                scores["collectibility"]
            ]
            scores["success_probability"] = statistics.mean(success_factors)
            
        except Exception as e:
            logger.error(f"Error calculating base scores: {e}")
        
        return scores
    
    def _assess_complexity(self, case_data: Dict[str, Any]) -> float:
        """Assess case complexity based on multiple factors"""
        complexity_score = 50.0  # Base score
        
        try:
            # Check for complexity indicators
            case_facts = str(case_data).lower()
            
            complexity_adjustments = {
                "simple": -20,
                "moderate": 0,
                "complex": 20,
                "very_complex": 40
            }
            
            for complexity_level, indicators in self.complexity_indicators.items():
                matches = sum(1 for indicator in indicators if indicator.replace("_", " ") in case_facts)
                if matches >= 2:  # Require multiple indicators
                    complexity_score += complexity_adjustments[complexity_level]
                    break
            
            # Additional complexity factors
            legal_theories = case_data.get("legal_analysis", {}).get("legal_theories", [])
            if len(legal_theories) > 2:
                complexity_score += 10
            
            medical_specialties = case_data.get("medical_analysis", {}).get("specialty_relevance", [])
            if len(medical_specialties) > 3:
                complexity_score += 15
            
        except Exception as e:
            logger.error(f"Error assessing complexity: {e}")
        
        return max(10.0, min(90.0, complexity_score))  # Clamp between 10-90
    
    def assess_risk_factors(self, case_data: Dict[str, Any]) -> Dict[str, Any]:
        """Assess risk factors that could affect case outcome"""
        
        identified_risks = {
            "high_risk": [],
            "medium_risk": [],
            "low_risk": []
        }
        
        risk_score = 50.0  # Neutral starting point
        
        try:
            case_content = str(case_data).lower()
            
            # Check for risk indicators
            for risk_level, risk_factors in self.risk_factors.items():
                for risk_factor in risk_factors:
                    risk_phrase = risk_factor.replace("_", " ")
                    if risk_phrase in case_content:
                        identified_risks[risk_level].append(risk_factor)
            
            # Calculate risk score
            high_risk_count = len(identified_risks["high_risk"])
            medium_risk_count = len(identified_risks["medium_risk"])
            low_risk_count = len(identified_risks["low_risk"])
            
            # Adjust risk score
            risk_score -= (high_risk_count * 15)  # High risk factors significantly decrease score
            risk_score -= (medium_risk_count * 8)  # Medium risk factors moderately decrease score  
            risk_score += (low_risk_count * 10)   # Low risk factors improve score
            
            risk_score = max(0.0, min(100.0, risk_score))  # Clamp between 0-100
            
        except Exception as e:
            logger.error(f"Error assessing risk factors: {e}")
        
        return {
            "risk_score": risk_score,
            "identified_risks": identified_risks,
            "total_risk_factors": sum(len(risks) for risks in identified_risks.values()),
            "risk_level": "high" if risk_score < 30 else "medium" if risk_score < 70 else "low"
        }
    
    def estimate_case_value(self, case_data: Dict[str, Any]) -> Dict[str, Any]:
        """Estimate potential case settlement value"""
        
        try:
            # Get medical costs
            medical_analysis = case_data.get("medical_analysis", {})
            medical_costs = medical_analysis.get("cost_analysis", {}).get("total_identifiable_costs", 0)
            
            # Determine case type
            legal_theories = case_data.get("legal_analysis", {}).get("legal_theories", [])
            case_type = "motor_vehicle"  # Default
            
            if legal_theories:
                primary_theory = legal_theories[0].get("theory", "")
                if "medical" in primary_theory:
                    case_type = "medical_malpractice"
                elif "premises" in primary_theory:
                    case_type = "premises_liability"
                elif "product" in primary_theory:
                    case_type = "product_liability"
            
            # Get multiplier range
            multipliers = self.settlement_multipliers.get(case_type, self.settlement_multipliers["motor_vehicle"])
            
            # Calculate value estimates
            if medical_costs > 0:
                low_estimate = medical_costs * multipliers["min"]
                high_estimate = medical_costs * multipliers["max"]
                likely_estimate = medical_costs * multipliers["average"]
            else:
                # Fallback estimates based on severity
                severity = medical_analysis.get("severity_analysis", {}).get("overall_severity", "mild")
                if severity == "severe":
                    low_estimate, high_estimate, likely_estimate = 50000, 500000, 150000
                elif severity == "moderate":
                    low_estimate, high_estimate, likely_estimate = 15000, 150000, 50000
                else:
                    low_estimate, high_estimate, likely_estimate = 2500, 50000, 15000
            
            # Adjust for liability strength
            legal_analysis = case_data.get("legal_analysis", {})
            liability_strength = legal_analysis.get("liability_assessment", {}).get("overall_strength", "moderate")
            
            liability_adjustments = {
                "strong": 1.0,
                "moderate": 0.8,
                "weak": 0.5,
                "unknown": 0.6
            }
            
            adjustment = liability_adjustments.get(liability_strength, 0.8)
            
            return {
                "low_estimate": round(low_estimate * adjustment),
                "high_estimate": round(high_estimate * adjustment),
                "likely_estimate": round(likely_estimate * adjustment),
                "case_type": case_type,
                "multiplier_used": multipliers,
                "medical_costs_base": medical_costs,
                "liability_adjustment": adjustment,
                "confidence_level": "high" if medical_costs > 0 else "medium"
            }
            
        except Exception as e:
            logger.error(f"Error estimating case value: {e}")
            return {
                "low_estimate": 0,
                "high_estimate": 0,
                "likely_estimate": 0,
                "error": str(e),
                "confidence_level": "low"
            }
    
    def calculate_weighted_score(self, component_scores: Dict[str, float]) -> float:
        """Calculate weighted overall merit score"""
        
        try:
            total_score = 0.0
            total_weight = 0.0
            
            for component, score in component_scores.items():
                weight = self.merit_weights.get(component, 0.0)
                total_score += score * weight
                total_weight += weight
            
            if total_weight > 0:
                return total_score / total_weight
            else:
                return 50.0  # Neutral score if no weights
                
        except Exception as e:
            logger.error(f"Error calculating weighted score: {e}")
            return 50.0
    
    def generate_recommendation(
        self,
        overall_score: float,
        component_scores: Dict[str, float],
        risk_assessment: Dict[str, Any],
        value_estimate: Dict[str, Any]
    ) -> Dict[str, Any]:
        """Generate final case recommendation"""
        
        try:
            # Base recommendation on overall score
            if overall_score >= 80:
                recommendation = "accept"
                confidence = "high"
            elif overall_score >= 65:
                recommendation = "accept" if risk_assessment["risk_level"] != "high" else "review"
                confidence = "medium-high"
            elif overall_score >= 50:
                recommendation = "review"
                confidence = "medium"
            elif overall_score >= 35:
                recommendation = "decline" if risk_assessment["risk_level"] == "high" else "review"
                confidence = "medium-low"
            else:
                recommendation = "decline"
                confidence = "high"
            
            # Adjust based on specific factors
            adjustments = []
            
            # High-value cases get extra consideration
            if value_estimate.get("likely_estimate", 0) > 200000:
                if recommendation == "decline":
                    recommendation = "review"
                    adjustments.append("High value case warrants additional review")
            
            # Very risky cases should be declined regardless of score
            if risk_assessment["risk_level"] == "high" and len(risk_assessment["identified_risks"]["high_risk"]) >= 3:
                recommendation = "decline"
                adjustments.append("Multiple high-risk factors identified")
            
            # Strong liability with good damages should be accepted
            if (component_scores.get("liability_strength", 0) >= 80 and 
                component_scores.get("damages_potential", 0) >= 70):
                if recommendation in ["review", "decline"]:
                    recommendation = "accept"
                    adjustments.append("Strong liability and damages overcome other concerns")
            
            # Generate reasoning
            reasoning_parts = [
                f"Overall merit score: {overall_score:.1f}/100",
                f"Liability strength: {component_scores.get('liability_strength', 0):.1f}/100",
                f"Damages potential: {component_scores.get('damages_potential', 0):.1f}/100",
                f"Risk level: {risk_assessment['risk_level']}",
                f"Estimated value: ${value_estimate.get('likely_estimate', 0):,}"
            ]
            
            if adjustments:
                reasoning_parts.extend(adjustments)
            
            return {
                "recommendation": recommendation,
                "confidence": confidence,
                "reasoning": "; ".join(reasoning_parts),
                "key_factors": {
                    "strongest_aspect": max(component_scores.items(), key=lambda x: x[1])[0],
                    "weakest_aspect": min(component_scores.items(), key=lambda x: x[1])[0],
                    "primary_risk": risk_assessment["identified_risks"]["high_risk"][0] if risk_assessment["identified_risks"]["high_risk"] else None
                },
                "next_steps": self._generate_next_steps(recommendation, component_scores, risk_assessment),
                "timeline_urgency": self._assess_timeline_urgency(component_scores, risk_assessment)
            }
            
        except Exception as e:
            logger.error(f"Error generating recommendation: {e}")
            return {
                "recommendation": "review",
                "confidence": "low",
                "reasoning": f"Analysis error: {str(e)}",
                "error": True
            }
    
    def _generate_next_steps(
        self,
        recommendation: str,
        component_scores: Dict[str, float],
        risk_assessment: Dict[str, Any]
    ) -> List[str]:
        """Generate recommended next steps based on analysis"""
        
        next_steps = []
        
        if recommendation == "accept":
            next_steps.extend([
                "Obtain signed retainer agreement",
                "Request complete medical records",
                "Initiate formal investigation"
            ])
            
            if component_scores.get("liability_strength", 0) < 70:
                next_steps.append("Conduct additional liability investigation")
            
        elif recommendation == "review":
            next_steps.extend([
                "Conduct attorney review meeting",
                "Obtain additional documentation",
                "Assess resource allocation"
            ])
            
            if "disputed_liability" in risk_assessment["identified_risks"]["high_risk"]:
                next_steps.append("Investigate liability issues further")
                
            if component_scores.get("damages_potential", 0) < 50:
                next_steps.append("Obtain complete medical records and bills")
                
        else:  # decline
            next_steps.extend([
                "Prepare declination letter",
                "Consider referral to appropriate firm",
                "Document reasons for declination"
            ])
        
        return next_steps
    
    def _assess_timeline_urgency(
        self,
        component_scores: Dict[str, float],
        risk_assessment: Dict[str, Any]
    ) -> str:
        """Assess timeline urgency for case handling"""
        
        # Check for statute of limitations concerns
        if "statute_of_limitations_close" in risk_assessment["identified_risks"]["high_risk"]:
            return "urgent"
        
        # High-value cases need prompt attention
        if component_scores.get("damages_potential", 0) >= 85:
            return "high"
        
        # Standard timeline for most cases
        if component_scores.get("liability_strength", 0) >= 70:
            return "normal"
        
        return "low"
    
    async def analyze_case_merit(
        self,
        case_data: Dict[str, Any],
        intake_id: Optional[int] = None,
        use_ai_analysis: bool = True
    ) -> Dict[str, Any]:
        """
        Main case merit analysis method using Claude 3.5 Sonnet
        """
        start_time = time.time()
        
        try:
            # Calculate base component scores
            component_scores = self.calculate_base_scores(case_data)
            
            # Assess risk factors
            risk_assessment = self.assess_risk_factors(case_data)
            
            # Estimate case value
            value_estimate = self.estimate_case_value(case_data)
            
            # Enhanced AI analysis if requested
            ai_analysis = None
            if use_ai_analysis:
                ai_analysis = await self._conduct_ai_merit_analysis(case_data)
                
                # Integrate AI insights into component scores
                if ai_analysis and not ai_analysis.get("error"):
                    component_scores = self._integrate_ai_scores(component_scores, ai_analysis)
            
            # Calculate overall weighted score
            overall_score = self.calculate_weighted_score(component_scores)
            
            # Adjust overall score based on risk assessment
            risk_adjustment = (risk_assessment["risk_score"] - 50) * 0.2  # Scale risk impact
            adjusted_score = max(0, min(100, overall_score + risk_adjustment))
            
            # Generate final recommendation
            recommendation = self.generate_recommendation(
                adjusted_score,
                component_scores,
                risk_assessment,
                value_estimate
            )
            
            # Build comprehensive response
            response = {
                "intake_id": intake_id,
                "overall_score": round(adjusted_score, 1),
                "raw_score": round(overall_score, 1),
                "component_scores": {k: round(v, 1) for k, v in component_scores.items()},
                "risk_assessment": risk_assessment,
                "value_estimate": value_estimate,
                "recommendation": recommendation,
                "ai_analysis": ai_analysis,
                "analysis_metadata": {
                    "weights_used": self.merit_weights,
                    "processing_time": time.time() - start_time,
                    "analysis_date": datetime.utcnow().isoformat(),
                    "ai_enhanced": use_ai_analysis
                },
                "status": "completed"
            }
            
            return response
            
        except Exception as e:
            logger.error(f"Case merit analysis failed: {e}")
            return {
                "intake_id": intake_id,
                "status": "failed",
                "error": str(e),
                "processing_time": time.time() - start_time
            }
    
    async def _conduct_ai_merit_analysis(self, case_data: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        """Conduct enhanced AI analysis using Claude 3.5 Sonnet"""
        
        try:
            # Prepare comprehensive case summary for AI
            case_summary = self._prepare_case_summary(case_data)
            
            system_prompt = """You are an expert personal injury attorney with 20+ years of experience evaluating case merit. 

Analyze this case comprehensively and provide detailed scoring and recommendations.

Evaluate these components (score 0-100):
1. LIABILITY_STRENGTH: How clear is defendant liability?
2. DAMAGES_POTENTIAL: What is the damages potential?
3. COLLECTIBILITY: Can damages be collected?
4. CASE_COMPLEXITY: How complex is the case? (lower scores = less complex = better)
5. RESOURCE_REQUIREMENTS: How much will this case cost to pursue? (lower scores = fewer resources = better)
6. SUCCESS_PROBABILITY: Overall chance of favorable outcome?

Return JSON format:

{
    "component_analysis": {
        "liability_strength": {"score": 0-100, "reasoning": "detailed analysis"},
        "damages_potential": {"score": 0-100, "reasoning": "detailed analysis"},
        "collectibility": {"score": 0-100, "reasoning": "detailed analysis"},
        "case_complexity": {"score": 0-100, "reasoning": "detailed analysis"},
        "resource_requirements": {"score": 0-100, "reasoning": "detailed analysis"},
        "success_probability": {"score": 0-100, "reasoning": "detailed analysis"}
    },
    "key_strengths": ["list of case strengths"],
    "key_weaknesses": ["list of case weaknesses"],
    "critical_issues": ["issues that could make or break the case"],
    "investigation_priorities": ["what needs to be investigated first"],
    "expert_witnesses_needed": ["types of experts needed"],
    "settlement_factors": ["factors affecting settlement negotiations"],
    "trial_considerations": ["factors if case goes to trial"],
    "overall_assessment": "detailed narrative assessment",
    "confidence_level": "high/medium/low"
}

Be thorough, realistic, and consider both strengths and weaknesses. Base your analysis on established legal principles and practical experience."""
            
            # Execute AI analysis
            ai_result = await llm_router.execute_routed_request(
                task_type="case_merit_analysis",
                content=case_summary,
                system_prompt=system_prompt,
                max_tokens=2000,
                temperature=0.1,
                context={
                    "requires_reasoning": True,
                    "high_stakes": True,
                    "multi_step_analysis": True
                }
            )
            
            # Parse AI response
            ai_analysis = self._parse_ai_merit_response(ai_result["content"])
            
            # Add processing metadata
            ai_analysis["processing_metadata"] = {
                "model_used": ai_result.get("model", "unknown"),
                "token_usage": ai_result.get("usage", {}),
                "processing_time": ai_result.get("processing_time", 0),
                "routing_decision": ai_result.get("routing_decision", {})
            }
            
            return ai_analysis
            
        except Exception as e:
            logger.error(f"AI merit analysis failed: {e}")
            return {"error": str(e)}
    
    def _prepare_case_summary(self, case_data: Dict[str, Any]) -> str:
        """Prepare comprehensive case summary for AI analysis"""
        
        summary_parts = []
        
        # Add legal analysis summary
        legal_analysis = case_data.get("legal_analysis", {})
        if legal_analysis:
            summary_parts.append("LEGAL ANALYSIS:")
            summary_parts.append(f"Legal theories: {legal_analysis.get('legal_theories', [])}")
            summary_parts.append(f"Liability assessment: {legal_analysis.get('liability_assessment', {})}")
            summary_parts.append(f"Damages analysis: {legal_analysis.get('damages_analysis', {})}")
        
        # Add medical analysis summary
        medical_analysis = case_data.get("medical_analysis", {})
        if medical_analysis:
            summary_parts.append("\nMEDICAL ANALYSIS:")
            summary_parts.append(f"Severity: {medical_analysis.get('severity_analysis', {})}")
            summary_parts.append(f"Medical costs: {medical_analysis.get('cost_analysis', {})}")
            summary_parts.append(f"Medical information: {medical_analysis.get('medical_information', {})}")
        
        # Add case facts if available
        case_facts = case_data.get("case_facts", "")
        if case_facts:
            summary_parts.append(f"\nCASE FACTS:\n{case_facts}")
        
        # Add document summaries
        documents = case_data.get("documents", [])
        if documents:
            summary_parts.append(f"\nDOCUMENTS AVAILABLE: {len(documents)} documents analyzed")
        
        return "\n".join(summary_parts)
    
    def _parse_ai_merit_response(self, ai_response: str) -> Dict[str, Any]:
        """Parse Claude's merit analysis response"""
        
        try:
            response_data = json.loads(ai_response.strip())
            
            # Validate structure
            if "component_analysis" not in response_data:
                raise ValueError("Missing component_analysis in AI response")
            
            return response_data
            
        except (json.JSONDecodeError, ValueError) as e:
            logger.warning(f"Failed to parse AI merit response: {e}")
            return {
                "error": f"AI response parsing failed: {str(e)}",
                "raw_response": ai_response[:1000]  # First 1000 chars for debugging
            }
    
    def _integrate_ai_scores(
        self,
        base_scores: Dict[str, float],
        ai_analysis: Dict[str, Any]
    ) -> Dict[str, float]:
        """Integrate AI analysis scores with base rule-based scores"""
        
        try:
            ai_components = ai_analysis.get("component_analysis", {})
            integrated_scores = base_scores.copy()
            
            # Weight: 60% AI, 40% rule-based for final scores
            ai_weight = 0.6
            rule_weight = 0.4
            
            for component, base_score in base_scores.items():
                ai_component_data = ai_components.get(component, {})
                ai_score = ai_component_data.get("score", base_score)
                
                # Validate AI score is reasonable
                if isinstance(ai_score, (int, float)) and 0 <= ai_score <= 100:
                    integrated_scores[component] = (ai_score * ai_weight) + (base_score * rule_weight)
                else:
                    # Keep base score if AI score is invalid
                    integrated_scores[component] = base_score
            
            return integrated_scores
            
        except Exception as e:
            logger.error(f"Error integrating AI scores: {e}")
            return base_scores  # Fallback to base scores


# Global case merit analyzer instance
case_merit_analyzer = CaseMeritAnalyzer()