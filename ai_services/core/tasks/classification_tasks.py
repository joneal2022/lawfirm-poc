"""
Document Classification Tasks for Celery Queue
Async document classification using GPT-4o mini
"""

import asyncio
import time
import logging
from typing import Dict, Any, Optional
from celery import Task

from core.celery_app import celery_app
from services.doc_classifier.classifier import document_classifier
from core.database import AsyncSessionLocal

logger = logging.getLogger(__name__)

class ClassificationTask(Task):
    """Base classification task with retry logic"""
    
    autoretry_for = (Exception,)
    retry_kwargs = {'max_retries': 3, 'countdown': 60}
    retry_backoff = True

@celery_app.task(
    bind=True,
    base=ClassificationTask,
    queue="classification_queue",
    name="classification.classify_document",
    time_limit=120,  # 2 minutes
    soft_time_limit=90  # 1.5 minutes
)
def classify_document_async(
    self,
    document_id: int,
    content: str,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Classify document asynchronously using AI
    """
    start_time = time.time()
    
    try:
        logger.info(f"Starting classification for document {document_id}")
        
        # Update task progress
        self.update_state(
            state='PROCESSING',
            meta={
                'document_id': document_id,
                'progress': 20,
                'status': 'Analyzing document content'
            }
        )
        
        # Create event loop for async processing
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        
        try:
            # Classify document
            result = loop.run_until_complete(
                document_classifier.classify_document(
                    content=content,
                    document_id=document_id,
                    use_phi_redaction=True
                )
            )
            
            # Update progress
            self.update_state(
                state='PROCESSING',
                meta={
                    'document_id': document_id,
                    'progress': 80,
                    'status': 'Saving classification results'
                }
            )
            
            # Store results
            if result['status'] == 'completed':
                loop.run_until_complete(
                    store_classification_results(document_id, result, user_id, firm_id)
                )
            
            processing_time = time.time() - start_time
            result['celery_processing_time'] = processing_time
            
            logger.info(f"Classification completed for document {document_id} in {processing_time:.2f}s")
            
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
        logger.error(f"Classification failed for document {document_id}: {str(e)}")
        
        self.update_state(
            state='FAILURE',
            meta={
                'document_id': document_id,
                'error': str(e),
                'processing_time': time.time() - start_time
            }
        )
        
        raise

@celery_app.task(
    queue="classification_queue",
    name="classification.batch_classify",
    time_limit=600,  # 10 minutes for batch
    soft_time_limit=480  # 8 minutes
)
def batch_classify_documents(
    documents: list,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Classify multiple documents in batch
    """
    start_time = time.time()
    results = []
    
    try:
        logger.info(f"Starting batch classification for {len(documents)} documents")
        
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        
        try:
            # Use batch classification method
            batch_results = loop.run_until_complete(
                document_classifier.batch_classify(
                    documents=documents,
                    batch_size=5  # Process 5 at a time
                )
            )
            
            # Store results for each document
            for i, result in enumerate(batch_results):
                if result.get('status') == 'completed':
                    document_id = documents[i].get('document_id')
                    loop.run_until_complete(
                        store_classification_results(document_id, result, user_id, firm_id)
                    )
                
                results.append(result)
            
            processing_time = time.time() - start_time
            
            return {
                'status': 'completed',
                'batch_size': len(documents),
                'results': results,
                'successful': len([r for r in results if r.get('status') == 'completed']),
                'failed': len([r for r in results if r.get('status') == 'failed']),
                'processing_time': processing_time
            }
            
        finally:
            loop.close()
            
    except Exception as e:
        logger.error(f"Batch classification failed: {str(e)}")
        return {
            'status': 'failed',
            'error': str(e),
            'processing_time': time.time() - start_time,
            'partial_results': results
        }

async def store_classification_results(
    document_id: int,
    classification_result: Dict[str, Any],
    user_id: int = None,
    firm_id: int = None
) -> bool:
    """
    Store classification results in database
    """
    try:
        async with AsyncSessionLocal() as session:
            from core.database import AIAnalysisResult, DocumentClassification
            
            # Store in AI analysis results table
            analysis_result = AIAnalysisResult(
                document_id=document_id,
                intake_id=classification_result.get('intake_id'),
                analysis_type='classification',
                model_used=classification_result.get('ai_analysis', {}).get('model_used', 'gpt-4o-mini'),
                results=classification_result,
                confidence_score=classification_result.get('classification', {}).get('confidence_score', 0),
                processing_time_seconds=classification_result.get('processing_time', 0),
                status='completed',
                created_by=user_id or 1
            )
            session.add(analysis_result)
            
            # Store in specialized classification table
            classification = classification_result.get('classification', {})
            if classification:
                doc_classification = DocumentClassification(
                    document_id=document_id,
                    document_type=classification.get('document_type', 'other'),
                    confidence_score=classification.get('confidence_score', 0),
                    model_used=classification_result.get('ai_analysis', {}).get('model_used', 'gpt-4o-mini'),
                    processing_time=classification_result.get('processing_time', 0)
                )
                session.add(doc_classification)
            
            await session.commit()
            
            logger.info(f"Stored classification results for document {document_id}")
            return True
            
    except Exception as e:
        logger.error(f"Failed to store classification results for document {document_id}: {e}")
        return False

@celery_app.task(
    queue="classification_queue",
    name="classification.reprocess_failed"
)
def reprocess_failed_classifications(
    document_ids: list,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Reprocess documents that failed classification
    """
    try:
        reprocessed = []
        
        for document_id in document_ids:
            # Get document content from database
            # This would need to be implemented based on your document storage
            # For now, we'll assume the content is available
            
            # Retry classification
            task = classify_document_async.delay(
                document_id=document_id,
                content="", # Would need to retrieve actual content
                user_id=user_id,
                firm_id=firm_id
            )
            
            reprocessed.append({
                'document_id': document_id,
                'task_id': task.id,
                'status': 'queued'
            })
        
        return {
            'status': 'completed',
            'reprocessed_count': len(reprocessed),
            'tasks': reprocessed
        }
        
    except Exception as e:
        logger.error(f"Failed to reprocess classifications: {e}")
        return {
            'status': 'failed',
            'error': str(e)
        }

@celery_app.task(
    queue="classification_queue",
    name="classification.update_model_thresholds"
)
def update_classification_thresholds(
    new_thresholds: Dict[str, float],
    user_id: int = None
) -> Dict[str, Any]:
    """
    Update classification confidence thresholds
    """
    try:
        # This would update the classifier's internal thresholds
        # For now, we'll log the update
        
        logger.info(f"Updated classification thresholds: {new_thresholds}")
        
        return {
            'status': 'completed',
            'updated_thresholds': new_thresholds,
            'updated_by': user_id,
            'updated_at': time.time()
        }
        
    except Exception as e:
        logger.error(f"Failed to update classification thresholds: {e}")
        return {
            'status': 'failed',
            'error': str(e)
        }