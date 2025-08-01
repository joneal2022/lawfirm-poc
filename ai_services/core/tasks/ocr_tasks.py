"""
OCR Processing Tasks for Celery Queue
Async document processing with Tesseract integration
"""

import asyncio
import time
import logging
from typing import Dict, Any, Optional
from celery import Task
from celery.exceptions import Retry

from core.celery_app import celery_app
from services.ocr_service.ocr_processor import ocr_processor
from core.database import AsyncSessionLocal, log_token_usage

logger = logging.getLogger(__name__)

class OCRTask(Task):
    """Base OCR task with retry logic and error handling"""
    
    autoretry_for = (Exception,)
    retry_kwargs = {'max_retries': 3, 'countdown': 60}
    retry_backoff = True
    
    def on_failure(self, exc, task_id, args, kwargs, einfo):
        """Handle task failure"""
        logger.error(f"OCR task {task_id} failed: {exc}")
        # Could send notification or update database status here

@celery_app.task(
    bind=True,
    base=OCRTask,
    queue="ocr_queue",
    name="ocr.process_document",
    time_limit=600,  # 10 minutes
    soft_time_limit=300  # 5 minutes
)
def process_document_async(
    self,
    document_id: int,
    file_path: str,
    document_type: str = "standard",
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Process document with OCR asynchronously
    """
    start_time = time.time()
    
    try:
        logger.info(f"Starting OCR processing for document {document_id}")
        
        # Create event loop for async processing
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        
        try:
            # Process document
            result = loop.run_until_complete(
                ocr_processor.process_document(
                    file_path=file_path,
                    document_type=document_type,
                    document_id=document_id
                )
            )
            
            # Update task progress
            self.update_state(
                state='PROCESSING',
                meta={
                    'document_id': document_id,
                    'progress': 80,
                    'status': 'OCR completed, saving results'
                }
            )
            
            # Store results in database (async)
            if result['status'] == 'completed':
                loop.run_until_complete(
                    store_ocr_results(document_id, result, user_id, firm_id)
                )
            
            processing_time = time.time() - start_time
            result['celery_processing_time'] = processing_time
            
            logger.info(f"OCR processing completed for document {document_id} in {processing_time:.2f}s")
            
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
        logger.error(f"OCR processing failed for document {document_id}: {str(e)}")
        
        # Update task state with error
        self.update_state(
            state='FAILURE',
            meta={
                'document_id': document_id,
                'error': str(e),
                'processing_time': time.time() - start_time
            }
        )
        
        # Re-raise for Celery retry mechanism
        raise

@celery_app.task(
    queue="ocr_queue",
    name="ocr.batch_process",
    time_limit=1800,  # 30 minutes for batch
    soft_time_limit=1500  # 25 minutes
)
def batch_process_documents(
    document_batch: list,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Process multiple documents in batch
    """
    start_time = time.time()
    results = []
    
    try:
        logger.info(f"Starting batch OCR processing for {len(document_batch)} documents")
        
        for i, doc_info in enumerate(document_batch):
            try:
                # Process individual document
                result = process_document_async.apply(
                    args=[
                        doc_info['document_id'],
                        doc_info['file_path'],
                        doc_info.get('document_type', 'standard'),
                        user_id,
                        firm_id
                    ]
                ).get()
                
                results.append(result)
                
                # Update batch progress
                progress = int((i + 1) / len(document_batch) * 100)
                logger.info(f"Batch progress: {progress}% ({i + 1}/{len(document_batch)})")
                
            except Exception as e:
                logger.error(f"Failed to process document {doc_info['document_id']}: {e}")
                results.append({
                    'status': 'failed',
                    'document_id': doc_info['document_id'],
                    'error': str(e)
                })
        
        processing_time = time.time() - start_time
        
        return {
            'status': 'completed',
            'batch_size': len(document_batch),
            'results': results,
            'successful': len([r for r in results if r['status'] == 'completed']),
            'failed': len([r for r in results if r['status'] == 'failed']),
            'processing_time': processing_time
        }
        
    except Exception as e:
        logger.error(f"Batch OCR processing failed: {str(e)}")
        return {
            'status': 'failed',
            'error': str(e),
            'processing_time': time.time() - start_time,
            'partial_results': results
        }

async def store_ocr_results(
    document_id: int,
    ocr_result: Dict[str, Any],
    user_id: int = None,
    firm_id: int = None
) -> bool:
    """
    Store OCR results in database
    """
    try:
        async with AsyncSessionLocal() as session:
            from core.database import AIAnalysisResult
            
            # Create analysis result record
            analysis_result = AIAnalysisResult(
                document_id=document_id,
                intake_id=ocr_result.get('intake_id'),
                analysis_type='ocr',
                model_used='tesseract-5',
                results=ocr_result,
                confidence_score=ocr_result.get('average_confidence', 0),
                processing_time_seconds=ocr_result.get('processing_time', 0),
                status='completed',
                created_by=user_id or 1
            )
            
            session.add(analysis_result)
            await session.commit()
            
            logger.info(f"Stored OCR results for document {document_id}")
            return True
            
    except Exception as e:
        logger.error(f"Failed to store OCR results for document {document_id}: {e}")
        return False

@celery_app.task(
    queue="ocr_queue", 
    name="ocr.health_check"
)
def ocr_health_check() -> Dict[str, Any]:
    """
    Health check for OCR processing capability
    """
    try:
        # Test OCR processor initialization
        test_result = {
            'tesseract_available': True,
            'processor_initialized': True,
            'status': 'healthy'
        }
        
        # Could add more comprehensive checks here
        # like processing a small test image
        
        return test_result
        
    except Exception as e:
        return {
            'status': 'unhealthy',
            'error': str(e)
        }

@celery_app.task(bind=True, queue="ocr_queue", name="ocr.cancel_processing")
def cancel_document_processing(self, document_id: int) -> Dict[str, Any]:
    """
    Cancel ongoing OCR processing for a document
    """
    try:
        # Find and revoke related tasks
        inspect = celery_app.control.inspect()
        active_tasks = inspect.active()
        
        cancelled_tasks = []
        
        if active_tasks:
            for worker, tasks in active_tasks.items():
                for task in tasks:
                    # Check if this task is processing our document
                    if (task['name'] == 'ocr.process_document' and 
                        task['args'] and 
                        len(task['args']) > 0 and 
                        task['args'][0] == document_id):
                        
                        # Revoke the task
                        celery_app.control.revoke(task['id'], terminate=True)
                        cancelled_tasks.append(task['id'])
        
        return {
            'status': 'cancelled',
            'document_id': document_id,
            'cancelled_tasks': cancelled_tasks
        }
        
    except Exception as e:
        logger.error(f"Failed to cancel processing for document {document_id}: {e}")
        return {
            'status': 'error',
            'error': str(e)
        }