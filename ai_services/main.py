"""
Main FastAPI Application for AI Services
MAAP Legal Intake System - AI Microservices Gateway
"""

import time
import uuid
from contextlib import asynccontextmanager
from typing import Dict, Any, Optional

from fastapi import FastAPI, HTTPException, Depends, Request, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from sqlalchemy.ext.asyncio import AsyncSession
import structlog

from config.settings import settings
from core.database import get_db, create_tables, log_token_usage
from services.llm_router.router import llm_router
from utils.phi_redaction import phi_redactor

# Initialize structured logging
logger = structlog.get_logger()

# Security
security = HTTPBearer()


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifespan handler"""
    # Startup
    logger.info("AI Services starting up", version=settings.app_version)
    await create_tables()
    logger.info("Database tables created/verified")
    
    yield
    
    # Shutdown
    logger.info("AI Services shutting down")


# Create FastAPI app
app = FastAPI(
    title=settings.app_name,
    version=settings.app_version,
    description="AI-powered legal document processing and analysis services",
    lifespan=lifespan
)

# CORS middleware for PHP frontend integration
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost", "http://127.0.0.1"],  # PHP frontend
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT", "DELETE"],
    allow_headers=["*"],
)


# Middleware for request tracking and audit logging
@app.middleware("http")
async def audit_middleware(request: Request, call_next):
    request_id = str(uuid.uuid4())
    start_time = time.time()
    
    # Add request ID to request state
    request.state.request_id = request_id
    
    # Log request
    logger.info(
        "Request started",
        request_id=request_id,
        method=request.method,
        url=str(request.url),
        client_ip=request.client.host
    )
    
    response = await call_next(request)
    
    # Log response
    process_time = time.time() - start_time
    logger.info(
        "Request completed",
        request_id=request_id,
        status_code=response.status_code,
        process_time=process_time
    )
    
    response.headers["X-Request-ID"] = request_id
    response.headers["X-Process-Time"] = str(process_time)
    
    return response


# Authentication dependency (simplified for POC)
async def get_current_user(credentials: HTTPAuthorizationCredentials = Depends(security)):
    """Verify JWT token and extract user info"""
    # TODO: Implement proper JWT verification with the PHP system
    # For POC, we'll use a simple token format
    token = credentials.credentials
    
    # Placeholder authentication - integrate with PHP session/JWT
    if not token or token == "demo-token":
        return {
            "user_id": 1,
            "firm_id": 1,
            "role": "attorney",
            "permissions": ["read", "write"]
        }
    
    raise HTTPException(status_code=401, detail="Invalid authentication token")


# Health check endpoint
@app.get("/health")
async def health_check():
    """Service health check"""
    return {
        "status": "healthy",
        "service": settings.app_name,
        "version": settings.app_version,
        "timestamp": time.time()
    }


# Test LLM routing endpoint
@app.post("/api/v1/test-routing")
async def test_llm_routing(
    task_type: str,
    content: str,
    max_tokens: int = 500,
    current_user: dict = Depends(get_current_user)
):
    """Test the LLM routing system"""
    try:
        # Get routing decision without executing
        routing_decision = llm_router.route_request(
            task_type=task_type,
            content=content,
            max_output_tokens=max_tokens
        )
        
        return {
            "routing_decision": {
                "selected_model": routing_decision.selected_model.value,
                "reasoning": routing_decision.reasoning,
                "estimated_cost": routing_decision.estimated_cost,
                "token_estimate": routing_decision.token_estimate
            },
            "content_analysis": {
                "length": len(content),
                "complexity_score": llm_router.analyze_request_complexity(task_type, content)
            }
        }
    except Exception as e:
        logger.error("LLM routing test failed", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))


# PHI redaction test endpoint
@app.post("/api/v1/test-phi-redaction")
async def test_phi_redaction(
    text: str,
    current_user: dict = Depends(get_current_user)
):
    """Test PHI redaction system"""
    try:
        redaction_result = phi_redactor.redact_text(text)
        compliance_check = phi_redactor.check_compliance(text)
        
        return {
            "original_text": text,
            "redacted_text": redaction_result.redacted_text,
            "phi_detected": redaction_result.phi_detected,
            "redactions_count": len(redaction_result.redactions_made),
            "redaction_details": redaction_result.redactions_made,
            "compliance_check": compliance_check
        }
    except Exception as e:
        logger.error("PHI redaction test failed", error=str(e))
        raise HTTPException(status_code=500, detail=str(e))


# Document analysis endpoint (main AI processing)
@app.post("/api/v1/analyze-document")
async def analyze_document(
    document_id: int,
    analysis_type: str,
    content: str,
    background_tasks: BackgroundTasks,
    current_user: dict = Depends(get_current_user),
    db: AsyncSession = Depends(get_db)
):
    """
    Main document analysis endpoint
    Supports: classification, medical_extraction, legal_analysis, case_merit
    """
    try:
        # PHI redaction for HIPAA compliance
        safe_content, phi_audit = phi_redactor.safe_for_ai_processing(
            content, document_id
        )
        
        if phi_audit["phi_detected"]:
            logger.warning(
                "PHI detected and redacted",
                document_id=document_id,
                phi_count=phi_audit["redaction_count"]
            )
        
        # Route to appropriate analysis service based on type
        if analysis_type == "classification":
            result = await analyze_document_classification(
                safe_content, current_user, db
            )
        elif analysis_type == "medical_extraction":
            result = await analyze_medical_extraction(
                safe_content, current_user, db
            )
        elif analysis_type == "legal_analysis":
            result = await analyze_legal_document(
                safe_content, current_user, db
            )
        elif analysis_type == "case_merit":
            result = await analyze_case_merit(
                safe_content, current_user, db
            )
        else:
            raise HTTPException(
                status_code=400, 
                detail=f"Unsupported analysis type: {analysis_type}"
            )
        
        # Log token usage in background
        if "usage" in result:
            background_tasks.add_task(
                log_token_usage,
                db,
                current_user["firm_id"],
                current_user["user_id"],
                analysis_type,
                result.get("model", "unknown"),
                result["usage"].get("input_tokens", 0),
                result["usage"].get("output_tokens", 0),
                result.get("routing_decision", {}).get("actual_cost", 0.0) / 2,  # Input cost
                result.get("routing_decision", {}).get("actual_cost", 0.0) / 2,  # Output cost
                getattr(request.state, "request_id", "unknown"),
                f"/api/v1/analyze-document/{analysis_type}"
            )
        
        # Add metadata
        result.update({
            "document_id": document_id,
            "analysis_type": analysis_type,
            "phi_audit": phi_audit,
            "processed_at": time.time()
        })
        
        return result
        
    except Exception as e:
        logger.error(
            "Document analysis failed",
            document_id=document_id,
            analysis_type=analysis_type,
            error=str(e)
        )
        raise HTTPException(status_code=500, detail=str(e))


# Individual analysis functions (will be implemented in separate services)
async def analyze_document_classification(
    content: str, 
    current_user: dict, 
    db: AsyncSession
) -> Dict[str, Any]:
    """Classify document type using GPT-4o mini"""
    system_prompt = """You are a legal document classifier. Analyze the document and classify it into one of these categories:
    - medical_record
    - police_report
    - insurance_document
    - employment_record
    - correspondence
    - bill_invoice
    - legal_document
    - other
    
    Return a JSON response with 'document_type' and 'confidence_score' (0-1)."""
    
    result = await llm_router.execute_routed_request(
        task_type="document_classification",
        content=content,
        system_prompt=system_prompt,
        max_tokens=200,
        temperature=0.1
    )
    
    return result


async def analyze_medical_extraction(
    content: str,
    current_user: dict,
    db: AsyncSession
) -> Dict[str, Any]:
    """Extract medical information using GPT-4o mini"""
    system_prompt = """Extract medical information from this document. Return JSON with:
    - diagnoses: List of conditions/diagnoses
    - procedures: List of procedures performed
    - medications: List of medications mentioned
    - treatment_dates: Relevant dates
    - providers: Healthcare providers mentioned
    - medical_summary: Brief summary of medical content"""
    
    result = await llm_router.execute_routed_request(
        task_type="medical_summarization",
        content=content,
        system_prompt=system_prompt,
        max_tokens=800,
        temperature=0.1
    )
    
    return result


async def analyze_legal_document(
    content: str,
    current_user: dict,
    db: AsyncSession
) -> Dict[str, Any]:
    """Analyze legal document using Claude 3.5 Sonnet"""
    system_prompt = """Analyze this legal document for personal injury case relevance. Provide:
    - Legal theories applicable
    - Liability factors
    - Relevant legal standards
    - Statute of limitations considerations
    - Key legal issues identified"""
    
    result = await llm_router.execute_routed_request(
        task_type="legal_reasoning",
        content=content,
        system_prompt=system_prompt,
        max_tokens=1000,
        temperature=0.1,
        context={"requires_reasoning": True}
    )
    
    return result


async def analyze_case_merit(
    content: str,
    current_user: dict,
    db: AsyncSession
) -> Dict[str, Any]:
    """Analyze case merit using Claude 3.5 Sonnet"""
    system_prompt = """Analyze this case for merit in personal injury litigation. Provide scores (0-100) for:
    - liability_strength: How clear is defendant liability?
    - damages_potential: Estimate potential damages value
    - collectibility: Likelihood of collecting judgment
    - case_complexity: How complex is the case?
    - success_probability: Overall chance of success
    
    Also provide overall_recommendation: accept, decline, or review
    Include reasoning for each score."""
    
    result = await llm_router.execute_routed_request(
        task_type="case_merit_analysis",
        content=content,
        system_prompt=system_prompt,
        max_tokens=1200,
        temperature=0.1,
        context={"requires_reasoning": True, "high_stakes": True}
    )
    
    return result


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "main:app",
        host=settings.api_host,
        port=settings.api_port,
        reload=settings.debug
    )