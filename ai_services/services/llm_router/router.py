"""
LLM Router Service - Intelligent model selection for cost optimization
Routes requests between GPT-4o mini and Claude 3.5 Sonnet based on complexity
"""

import asyncio
import time
import hashlib
import json
from typing import Dict, Any, Optional, Tuple
from dataclasses import dataclass
from enum import Enum

import openai
import anthropic
import tiktoken
from fastapi import HTTPException

from config.settings import settings, MODEL_ROUTING_CONFIG


class ModelType(Enum):
    GPT_4O_MINI = "gpt_4o_mini"
    CLAUDE_3_5_SONNET = "claude_3_5_sonnet"


@dataclass
class RoutingDecision:
    selected_model: ModelType
    reasoning: str
    estimated_cost: float
    token_estimate: int


class LLMRouter:
    """
    Intelligent router for LLM requests with cost optimization
    """
    
    def __init__(self):
        self.openai_client = openai.OpenAI(api_key=settings.openai_api_key)
        self.anthropic_client = anthropic.Anthropic(api_key=settings.anthropic_api_key)
        self.gpt_tokenizer = tiktoken.encoding_for_model("gpt-4")
        
        # Initialize clients with error handling
        if not settings.openai_api_key:
            raise ValueError("OpenAI API key not provided")
        if not settings.anthropic_api_key:
            raise ValueError("Anthropic API key not provided")
    
    def analyze_request_complexity(
        self, 
        task_type: str, 
        content: str, 
        context: Optional[Dict[str, Any]] = None
    ) -> float:
        """
        Analyze the complexity of a request to determine optimal model
        Returns complexity score between 0.0 and 1.0
        """
        complexity_score = 0.0
        
        # Base complexity by task type
        task_complexity = {
            "document_classification": 0.2,
            "medical_summarization": 0.3,
            "simple_extraction": 0.2,
            "legal_reasoning": 0.8,
            "case_merit_analysis": 0.9,
            "complex_analysis": 0.8,
            "liability_assessment": 0.8
        }
        complexity_score += task_complexity.get(task_type, 0.5)
        
        # Content length factor
        content_length = len(content)
        if content_length > 50000:  # Large documents need Claude's context window
            complexity_score += 0.3
        elif content_length > 20000:
            complexity_score += 0.2
        elif content_length > 10000:
            complexity_score += 0.1
        
        # Content analysis for legal/medical complexity
        legal_indicators = [
            "liability", "negligence", "causation", "damages", "statute of limitations",
            "comparative fault", "strict liability", "breach of duty", "proximate cause"
        ]
        medical_complexity_indicators = [
            "differential diagnosis", "comorbidities", "prognosis", "treatment plan",
            "medical causation", "permanent impairment", "disability rating"
        ]
        
        content_lower = content.lower()
        legal_matches = sum(1 for indicator in legal_indicators if indicator in content_lower)
        medical_matches = sum(1 for indicator in medical_complexity_indicators if indicator in content_lower)
        
        if legal_matches >= 3:
            complexity_score += 0.2
        if medical_matches >= 3:
            complexity_score += 0.1
        
        # Context-based adjustments
        if context:
            if context.get("requires_reasoning", False):
                complexity_score += 0.2
            if context.get("multi_step_analysis", False):
                complexity_score += 0.2
            if context.get("high_stakes", False):
                complexity_score += 0.1
        
        return min(complexity_score, 1.0)
    
    def estimate_tokens(self, content: str, model_type: ModelType) -> int:
        """Estimate token count for content"""
        if model_type == ModelType.GPT_4O_MINI:
            return len(self.gpt_tokenizer.encode(content))
        else:
            # Anthropic uses different tokenization, approximate
            return int(len(content) / 3.5)
    
    def calculate_cost(
        self, 
        input_tokens: int, 
        output_tokens: int, 
        model_type: ModelType
    ) -> float:
        """Calculate estimated cost for the request"""
        config = MODEL_ROUTING_CONFIG[model_type.value]
        
        input_cost = (input_tokens / 1000) * config["cost_per_1k_input"]
        output_cost = (output_tokens / 1000) * config["cost_per_1k_output"]
        
        return input_cost + output_cost
    
    def route_request(
        self,
        task_type: str,
        content: str,
        max_output_tokens: int = 1000,
        context: Optional[Dict[str, Any]] = None
    ) -> RoutingDecision:
        """
        Determine the optimal model for a request based on complexity and cost
        """
        complexity_score = self.analyze_request_complexity(task_type, content, context)
        
        # Force Claude for specific high-complexity tasks
        if (task_type in ["case_merit_analysis", "legal_reasoning", "liability_assessment"] or 
            complexity_score > settings.cost_threshold_complex):
            
            selected_model = ModelType.CLAUDE_3_5_SONNET
            reasoning = f"High complexity task ({complexity_score:.2f}) requires advanced reasoning"
            
        # Use Claude for large documents (context window advantage)
        elif len(content) > 100000:
            selected_model = ModelType.CLAUDE_3_5_SONNET
            reasoning = "Large document requires extended context window"
            
        # Default to GPT-4o mini for cost efficiency
        else:
            selected_model = ModelType.GPT_4O_MINI
            reasoning = f"Standard complexity task ({complexity_score:.2f}) optimized for cost"
        
        # Calculate estimated cost
        input_tokens = self.estimate_tokens(content, selected_model)
        estimated_cost = self.calculate_cost(input_tokens, max_output_tokens, selected_model)
        
        return RoutingDecision(
            selected_model=selected_model,
            reasoning=reasoning,
            estimated_cost=estimated_cost,
            token_estimate=input_tokens + max_output_tokens
        )
    
    async def execute_gpt_request(
        self,
        messages: list,
        max_tokens: int = 1000,
        temperature: float = 0.1
    ) -> Dict[str, Any]:
        """Execute request using GPT-4o mini"""
        try:
            response = await asyncio.to_thread(
                self.openai_client.chat.completions.create,
                model=settings.gpt_4o_mini_model,
                messages=messages,
                max_tokens=max_tokens,
                temperature=temperature
            )
            
            return {
                "content": response.choices[0].message.content,
                "model": response.model,
                "usage": {
                    "input_tokens": response.usage.prompt_tokens,
                    "output_tokens": response.usage.completion_tokens,
                    "total_tokens": response.usage.total_tokens
                },
                "finish_reason": response.choices[0].finish_reason
            }
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"GPT request failed: {str(e)}")
    
    async def execute_claude_request(
        self,
        messages: list,
        max_tokens: int = 1000,
        temperature: float = 0.1
    ) -> Dict[str, Any]:
        """Execute request using Claude 3.5 Sonnet"""
        try:
            # Convert OpenAI format to Claude format
            system_message = ""
            claude_messages = []
            
            for msg in messages:
                if msg["role"] == "system":
                    system_message = msg["content"]
                else:
                    claude_messages.append({
                        "role": msg["role"],
                        "content": msg["content"]
                    })
            
            response = await asyncio.to_thread(
                self.anthropic_client.messages.create,
                model=settings.claude_3_5_sonnet_model,
                system=system_message,
                messages=claude_messages,
                max_tokens=max_tokens,
                temperature=temperature
            )
            
            return {
                "content": response.content[0].text,
                "model": response.model,
                "usage": {
                    "input_tokens": response.usage.input_tokens,
                    "output_tokens": response.usage.output_tokens,
                    "total_tokens": response.usage.input_tokens + response.usage.output_tokens
                },
                "finish_reason": response.stop_reason
            }
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"Claude request failed: {str(e)}")
    
    async def execute_routed_request(
        self,
        task_type: str,
        content: str,
        system_prompt: str,
        max_tokens: int = 1000,
        temperature: float = 0.1,
        context: Optional[Dict[str, Any]] = None
    ) -> Dict[str, Any]:
        """
        Execute a request using the optimal model based on routing decision
        """
        start_time = time.time()
        
        # Get routing decision
        routing_decision = self.route_request(task_type, content, max_tokens, context)
        
        # Prepare messages
        messages = [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": content}
        ]
        
        # Execute based on routing decision
        if routing_decision.selected_model == ModelType.GPT_4O_MINI:
            result = await self.execute_gpt_request(messages, max_tokens, temperature)
        else:
            result = await self.execute_claude_request(messages, max_tokens, temperature)
        
        processing_time = time.time() - start_time
        
        # Add routing metadata to result
        result.update({
            "routing_decision": {
                "selected_model": routing_decision.selected_model.value,
                "reasoning": routing_decision.reasoning,
                "estimated_cost": routing_decision.estimated_cost,
                "actual_cost": self.calculate_cost(
                    result["usage"]["input_tokens"],
                    result["usage"]["output_tokens"],
                    routing_decision.selected_model
                )
            },
            "processing_time": processing_time,
            "task_type": task_type
        })
        
        return result


# Global router instance
llm_router = LLMRouter()