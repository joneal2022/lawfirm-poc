"""
AI Services Configuration for MAAP Legal Intake System
HIPAA-compliant settings with cost optimization
"""

from pydantic_settings import BaseSettings
from typing import Optional
import os


class Settings(BaseSettings):
    # Application settings
    app_name: str = "Legal Intake AI Services"
    app_version: str = "1.0.0-MAAP"
    debug: bool = False
    
    # API settings
    api_host: str = "0.0.0.0"
    api_port: int = 8000
    api_prefix: str = "/api/v1"
    
    # Database settings
    database_url: str = "mysql+asyncmy://root:@localhost:3306/legal_intake_system"
    database_echo: bool = False
    
    # Redis settings for job queue
    redis_url: str = "redis://localhost:6379/0"
    celery_broker_url: str = "redis://localhost:6379/0"
    celery_result_backend: str = "redis://localhost:6379/0"
    
    # AI Service API Keys (should be set via environment variables)
    openai_api_key: Optional[str] = None
    anthropic_api_key: Optional[str] = None
    
    # AI Model Configuration
    gpt_4o_mini_model: str = "gpt-4o-mini"
    claude_3_5_sonnet_model: str = "claude-3-5-sonnet-20241022"
    
    # Cost optimization settings
    max_tokens_per_request: int = 1200
    cost_threshold_complex: float = 0.8  # Complexity score threshold for Claude
    token_budget_daily: int = 1000000  # Daily token budget
    
    # OCR settings
    tesseract_path: Optional[str] = None  # Will auto-detect on macOS
    ocr_timeout: int = 300  # 5 minutes
    ocr_batch_size: int = 10
    
    # Security settings
    secret_key: str = "your-secret-key-change-in-production"
    encryption_key: str = "your-encryption-key-32-characters"
    access_token_expire_minutes: int = 30
    
    # HIPAA compliance settings
    enable_phi_redaction: bool = True
    audit_all_requests: bool = True
    log_retention_days: int = 2555  # 7 years for HIPAA
    
    # Rate limiting
    rate_limit_per_minute: int = 60
    rate_limit_per_hour: int = 1000
    
    # File processing settings
    max_file_size: int = 52428800  # 50MB
    allowed_file_types: list = ["pdf", "jpg", "jpeg", "png", "tiff", "doc", "docx"]
    upload_path: str = "/tmp/ai_services_uploads"
    
    # Service ports (for microservices)
    ocr_service_port: int = 8001
    doc_classifier_port: int = 8002
    medical_nlp_port: int = 8003
    legal_nlp_port: int = 8004
    case_merit_port: int = 8005
    llm_router_port: int = 8006
    
    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"


# Global settings instance
settings = Settings()


# Model routing configuration
MODEL_ROUTING_CONFIG = {
    "gpt_4o_mini": {
        "model_name": settings.gpt_4o_mini_model,
        "cost_per_1k_input": 0.00015,
        "cost_per_1k_output": 0.0006,
        "max_tokens": 128000,
        "use_cases": ["document_classification", "medical_summarization", "simple_extraction"]
    },
    "claude_3_5_sonnet": {
        "model_name": settings.claude_3_5_sonnet_model,
        "cost_per_1k_input": 0.003,
        "cost_per_1k_output": 0.015,
        "max_tokens": 200000,
        "use_cases": ["legal_reasoning", "case_merit_analysis", "complex_analysis"]
    }
}

# PHI detection patterns for HIPAA compliance
PHI_PATTERNS = {
    "ssn": r"\b\d{3}-?\d{2}-?\d{4}\b",
    "phone": r"\b\d{3}[-.]?\d{3}[-.]?\d{4}\b",
    "email": r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b",
    "medical_record_number": r"\b(?:MRN|MR#|Medical Record)\s*:?\s*\d+\b",
    "date_of_birth": r"\b(?:DOB|Date of Birth)\s*:?\s*\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b"
}

# Document classification types
DOCUMENT_TYPES = {
    "medical_record": "Medical Record",
    "police_report": "Police Report", 
    "insurance_document": "Insurance Document",
    "employment_record": "Employment Record",
    "correspondence": "Correspondence",
    "bill_invoice": "Bill/Invoice",
    "legal_document": "Legal Document",
    "other": "Other"
}

# Case merit scoring weights
CASE_MERIT_WEIGHTS = {
    "liability_strength": 0.3,
    "damages_potential": 0.25,
    "collectibility": 0.2,
    "case_complexity": 0.1,
    "resource_requirements": 0.1,
    "success_probability": 0.05
}