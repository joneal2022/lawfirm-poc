"""
Legal Analysis Tasks for Celery Queue
Async legal document analysis using Claude 3.5 Sonnet
"""

import asyncio
import time
import logging
from typing import Dict, Any, Optional
from celery import Task

from core.celery_app import celery_app
from services.legal_nlp.legal_analyzer import legal_analyzer
from core.database import AsyncSessionLocal

logger = logging.getLogger(__name__)

class LegalTask(Task):
    """Base legal analysis task with retry logic"""
    
    autoretry_for = (Exception,)
    retry_kwargs = {'max_retries': 2, 'countdown': 120}  # Less retries for expensive Claude calls
    retry_backoff = True

@celery_app.task(
    bind=True,
    base=LegalTask,
    queue="legal_queue",
    name="legal.analyze_document", 
    time_limit=300,  # 5 minutes for Claude processing
    soft_time_limit=240  # 4 minutes
)
def analyze_legal_document_async(
    self,
    document_id: int,
    content: str,
    case_context: Optional[Dict[str, Any]] = None,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Analyze legal document asynchronously using Claude 3.5 Sonnet
    """
    start_time = time.time()
    
    try:
        logger.info(f"Starting legal analysis for document {document_id}")
        
        self.update_state(
            state='PROCESSING',
            meta={
                'document_id': document_id,
                'progress': 20,
                'status': 'Analyzing legal content with Claude 3.5 Sonnet'
            }
        )
        
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        
        try:
            # Analyze legal document
            result = loop.run_until_complete(
                legal_analyzer.analyze_legal_document(
                    content=content,
                    document_id=document_id,
                    case_context=case_context,
                    use_phi_redaction=True
                )
            )
            
            self.update_state(
                state='PROCESSING',
                meta={
                    'document_id': document_id,
                    'progress': 80,
                    'status': 'Saving legal analysis results'
                }
            )
            
            # Store results
            if result['status'] == 'completed':
                loop.run_until_complete(
                    store_legal_results(document_id, result, user_id, firm_id)
                )
            
            processing_time = time.time() - start_time
            result['celery_processing_time'] = processing_time
            
            logger.info(f"Legal analysis completed for document {document_id} in {processing_time:.2f}s")
            
            return {
                'status': 'completed',
                'document_id': document_id,
                'result': result,
                'processing_time': processing_time,
                'task_id': self.request.id
            }
            
        finally:
            loop.close()
            
    except Exception as e:
        logger.error(f"Legal analysis failed for document {document_id}: {str(e)}")
        
        self.update_state(
            state='FAILURE',
            meta={
                'document_id': document_id,
                'error': str(e),
                'processing_time': time.time() - start_time
            }
        )
        
        raise

async def store_legal_results(
    document_id: int,
    legal_result: Dict[str, Any],
    user_id: int = None,
    firm_id: int = None
) -> bool:
    """
    Store legal analysis results in database
    """
    try:
        async with AsyncSessionLocal() as session:
            from core.database import AIAnalysisResult
            
            # Store in AI analysis results table
            analysis_result = AIAnalysisResult(
                document_id=document_id,
                intake_id=legal_result.get('intake_id'),
                analysis_type='legal_analysis',
                model_used=legal_result.get('ai_analysis', {}).get('model_used', 'claude-3.5-sonnet'),
                results=legal_result,
                confidence_score=legal_result.get('liability_assessment', {}).get('confidence', 0),
                processing_time_seconds=legal_result.get('processing_time', 0),
                status='completed',
                created_by=user_id or 1
            )
            session.add(analysis_result)
            
            await session.commit()
            
            logger.info(f"Stored legal results for document {document_id}")
            return True
            
    except Exception as e:
        logger.error(f"Failed to store legal results for document {document_id}: {e}")
        return False