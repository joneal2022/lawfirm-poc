"""
Database configuration and models for AI services
HIPAA-compliant with audit logging
"""

from sqlalchemy.ext.asyncio import AsyncSession, create_async_engine, async_sessionmaker
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column
from sqlalchemy import String, Text, DateTime, Integer, Float, Boolean, JSON, func
from datetime import datetime
from typing import Optional, Dict, Any
import json

from config.settings import settings


# Database engine and session
engine = create_async_engine(
    settings.database_url,
    echo=settings.database_echo,
    pool_pre_ping=True,
    pool_recycle=3600
)

AsyncSessionLocal = async_sessionmaker(
    engine,
    class_=AsyncSession,
    expire_on_commit=False
)


class Base(DeclarativeBase):
    pass


# Dependency to get database session
async def get_db():
    async with AsyncSessionLocal() as session:
        try:
            yield session
        finally:
            await session.close()


class AIAnalysisResult(Base):
    """Store AI analysis results with HIPAA compliance"""
    __tablename__ = "ai_analysis_results"
    
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    document_id: Mapped[int] = mapped_column(Integer, nullable=False)
    intake_id: Mapped[int] = mapped_column(Integer, nullable=False)
    analysis_type: Mapped[str] = mapped_column(String(50), nullable=False)  # ocr, classification, medical_nlp, etc.
    
    # AI model information
    model_used: Mapped[str] = mapped_column(String(100), nullable=False)
    model_version: Mapped[Optional[str]] = mapped_column(String(50))
    
    # Analysis results (JSON format)
    results: Mapped[Dict[str, Any]] = mapped_column(JSON, nullable=False)
    confidence_score: Mapped[Optional[float]] = mapped_column(Float)
    
    # Token usage and cost tracking
    input_tokens: Mapped[Optional[int]] = mapped_column(Integer)
    output_tokens: Mapped[Optional[int]] = mapped_column(Integer)
    total_cost: Mapped[Optional[float]] = mapped_column(Float)
    
    # Processing metadata
    processing_time_seconds: Mapped[Optional[float]] = mapped_column(Float)
    status: Mapped[str] = mapped_column(String(20), default="completed")  # pending, completed, failed
    error_message: Mapped[Optional[str]] = mapped_column(Text)
    
    # Audit fields
    created_by: Mapped[int] = mapped_column(Integer, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=func.now(), onupdate=func.now())


class TokenUsageLog(Base):
    """Track token usage for cost monitoring and billing"""
    __tablename__ = "token_usage_log"
    
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    firm_id: Mapped[int] = mapped_column(Integer, nullable=False)
    user_id: Mapped[int] = mapped_column(Integer, nullable=False)
    
    # Service and model information
    service_name: Mapped[str] = mapped_column(String(50), nullable=False)
    model_name: Mapped[str] = mapped_column(String(100), nullable=False)
    
    # Token usage
    input_tokens: Mapped[int] = mapped_column(Integer, nullable=False)
    output_tokens: Mapped[int] = mapped_column(Integer, nullable=False)
    total_tokens: Mapped[int] = mapped_column(Integer, nullable=False)
    
    # Cost calculation
    input_cost: Mapped[float] = mapped_column(Float, nullable=False)
    output_cost: Mapped[float] = mapped_column(Float, nullable=False)
    total_cost: Mapped[float] = mapped_column(Float, nullable=False)
    
    # Request metadata
    request_id: Mapped[str] = mapped_column(String(100), nullable=False)
    endpoint: Mapped[str] = mapped_column(String(200), nullable=False)
    
    # Timestamp
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())


class CaseScore(Base):
    """Store case merit analysis scores"""
    __tablename__ = "case_scores"
    
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    intake_id: Mapped[int] = mapped_column(Integer, nullable=False, unique=True)
    
    # Scoring components
    liability_strength: Mapped[Optional[float]] = mapped_column(Float)  # 0-100
    damages_potential: Mapped[Optional[float]] = mapped_column(Float)  # Dollar amount
    collectibility_score: Mapped[Optional[float]] = mapped_column(Float)  # 0-100
    case_complexity: Mapped[Optional[float]] = mapped_column(Float)  # 1-10
    resource_requirements: Mapped[Optional[float]] = mapped_column(Float)  # 1-10
    success_probability: Mapped[Optional[float]] = mapped_column(Float)  # 0-100
    
    # Overall score and recommendation
    overall_score: Mapped[Optional[float]] = mapped_column(Float)  # 0-100
    recommendation: Mapped[Optional[str]] = mapped_column(String(20))  # accept, decline, review
    estimated_settlement_range_low: Mapped[Optional[float]] = mapped_column(Float)
    estimated_settlement_range_high: Mapped[Optional[float]] = mapped_column(Float)
    
    # Analysis metadata
    analysis_notes: Mapped[Optional[str]] = mapped_column(Text)
    legal_theories: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)
    risk_factors: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)
    
    # AI model information
    model_used: Mapped[str] = mapped_column(String(100), nullable=False)
    model_confidence: Mapped[Optional[float]] = mapped_column(Float)
    
    # Audit fields
    created_by: Mapped[int] = mapped_column(Integer, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=func.now(), onupdate=func.now())
    
    # Attorney override
    attorney_override: Mapped[Optional[bool]] = mapped_column(Boolean, default=False)
    attorney_notes: Mapped[Optional[str]] = mapped_column(Text)
    final_decision: Mapped[Optional[str]] = mapped_column(String(20))  # accepted, declined


class DocumentClassification(Base):
    """Store document classification results"""
    __tablename__ = "document_classifications"
    
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    document_id: Mapped[int] = mapped_column(Integer, nullable=False)
    
    # Classification results
    document_type: Mapped[str] = mapped_column(String(50), nullable=False)
    confidence_score: Mapped[float] = mapped_column(Float, nullable=False)
    
    # Extracted metadata
    document_date: Mapped[Optional[datetime]] = mapped_column(DateTime)
    provider_name: Mapped[Optional[str]] = mapped_column(String(200))
    patient_name: Mapped[Optional[str]] = mapped_column(String(200))  # Encrypted
    reference_numbers: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)
    
    # Model information
    model_used: Mapped[str] = mapped_column(String(100), nullable=False)
    processing_time: Mapped[Optional[float]] = mapped_column(Float)
    
    # Manual override capability
    manual_override: Mapped[bool] = mapped_column(Boolean, default=False)
    reviewed_by: Mapped[Optional[int]] = mapped_column(Integer)
    
    # Timestamps
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=func.now(), onupdate=func.now())


class MedicalExtraction(Base):
    """Store medical information extraction results"""
    __tablename__ = "medical_extractions"
    
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    document_id: Mapped[int] = mapped_column(Integer, nullable=False)
    
    # Medical information (stored as JSON for flexibility)
    diagnoses: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)  # ICD codes and descriptions
    procedures: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)  # CPT codes and descriptions
    medications: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)
    treatment_dates: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)
    providers: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)
    
    # Summary and analysis
    medical_summary: Mapped[Optional[str]] = mapped_column(Text)
    severity_assessment: Mapped[Optional[str]] = mapped_column(String(20))  # mild, moderate, severe
    prognosis_notes: Mapped[Optional[str]] = mapped_column(Text)
    
    # Cost information
    medical_costs: Mapped[Optional[Dict[str, Any]]] = mapped_column(JSON)
    future_care_estimate: Mapped[Optional[float]] = mapped_column(Float)
    
    # Model and processing info
    model_used: Mapped[str] = mapped_column(String(100), nullable=False)
    extraction_confidence: Mapped[Optional[float]] = mapped_column(Float)
    
    # Timestamps
    created_at: Mapped[datetime] = mapped_column(DateTime, default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=func.now(), onupdate=func.now())


# Database initialization
async def create_tables():
    """Create all AI service tables"""
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)


# Utility functions for database operations
async def log_token_usage(
    session: AsyncSession,
    firm_id: int,
    user_id: int,
    service_name: str,
    model_name: str,
    input_tokens: int,
    output_tokens: int,
    input_cost: float,
    output_cost: float,
    request_id: str,
    endpoint: str
):
    """Log token usage for cost tracking"""
    usage_log = TokenUsageLog(
        firm_id=firm_id,
        user_id=user_id,
        service_name=service_name,
        model_name=model_name,
        input_tokens=input_tokens,
        output_tokens=output_tokens,
        total_tokens=input_tokens + output_tokens,
        input_cost=input_cost,
        output_cost=output_cost,
        total_cost=input_cost + output_cost,
        request_id=request_id,
        endpoint=endpoint
    )
    
    session.add(usage_log)
    await session.commit()
    return usage_log