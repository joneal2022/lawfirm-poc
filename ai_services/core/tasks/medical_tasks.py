"""
Medical NLP Processing Tasks for Celery Queue
Async medical information extraction using GPT-4o mini
"""

import asyncio
import time
import logging
from typing import Dict, Any, Optional, List
from celery import Task

from core.celery_app import celery_app
from services.medical_nlp.medical_processor import medical_processor
from core.database import AsyncSessionLocal

logger = logging.getLogger(__name__)

class MedicalTask(Task):
    """Base medical processing task with retry logic"""
    
    autoretry_for = (Exception,)
    retry_kwargs = {'max_retries': 3, 'countdown': 60}
    retry_backoff = True

@celery_app.task(
    bind=True,
    base=MedicalTask,
    queue="medical_queue",
    name="medical.extract_information",
    time_limit=180,  # 3 minutes
    soft_time_limit=150  # 2.5 minutes
)
def extract_medical_information_async(
    self,
    document_id: int,
    content: str,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Extract medical information from document asynchronously
    """
    start_time = time.time()
    
    try:
        logger.info(f"Starting medical extraction for document {document_id}")
        
        # Update task progress
        self.update_state(
            state='PROCESSING',
            meta={
                'document_id': document_id,
                'progress': 20,
                'status': 'Analyzing medical content'
            }
        )
        
        # Create event loop for async processing
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        
        try:
            # Extract medical information
            result = loop.run_until_complete(
                medical_processor.extract_medical_information(
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
                    'status': 'Saving medical analysis results'
                }
            )
            
            # Store results
            if result['status'] == 'completed':
                loop.run_until_complete(
                    store_medical_results(document_id, result, user_id, firm_id)
                )
            
            processing_time = time.time() - start_time
            result['celery_processing_time'] = processing_time
            
            logger.info(f"Medical extraction completed for document {document_id} in {processing_time:.2f}s")
            
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
        logger.error(f"Medical extraction failed for document {document_id}: {str(e)}")
        
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
    queue="medical_queue",
    name="medical.generate_summary",
    time_limit=300,  # 5 minutes
    soft_time_limit=240  # 4 minutes
)
def generate_medical_summary_async(
    document_analyses: List[Dict[str, Any]],
    case_context: Optional[str] = None,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Generate comprehensive medical summary from multiple analyses
    """
    start_time = time.time()
    
    try:
        logger.info(f"Generating medical summary from {len(document_analyses)} analyses")
        
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        
        try:
            # Generate summary
            result = loop.run_until_complete(
                medical_processor.generate_medical_summary(
                    medical_extractions=document_analyses,
                    case_context=case_context
                )
            )
            
            processing_time = time.time() - start_time
            result['celery_processing_time'] = processing_time
            
            logger.info(f"Medical summary generation completed in {processing_time:.2f}s")
            
            return {
                'status': 'completed',
                'result': result,
                'processing_time': processing_time
            }
            
        finally:
            loop.close()
            
    except Exception as e:
        logger.error(f"Medical summary generation failed: {str(e)}")
        return {
            'status': 'failed',
            'error': str(e),
            'processing_time': time.time() - start_time
        }

@celery_app.task(
    queue="medical_queue",
    name="medical.create_timeline",
    time_limit=120,  # 2 minutes
    soft_time_limit=90  # 1.5 minutes
)
def create_medical_timeline_async(
    medical_information: Dict[str, Any],
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Create medical timeline from extracted information
    """
    start_time = time.time()
    
    try:
        logger.info("Creating medical timeline")
        
        # Create timeline using medical processor
        timeline = medical_processor.create_medical_timeline(medical_information)
        
        processing_time = time.time() - start_time
        
        return {
            'status': 'completed',
            'timeline': timeline,
            'processing_time': processing_time
        }
        
    except Exception as e:
        logger.error(f"Medical timeline creation failed: {str(e)}")
        return {
            'status': 'failed',
            'error': str(e),
            'processing_time': time.time() - start_time
        }

async def store_medical_results(
    document_id: int,
    medical_result: Dict[str, Any],
    user_id: int = None,
    firm_id: int = None
) -> bool:
    """
    Store medical extraction results in database
    """
    try:
        async with AsyncSessionLocal() as session:
            from core.database import AIAnalysisResult, MedicalExtraction
            
            # Store in AI analysis results table
            analysis_result = AIAnalysisResult(
                document_id=document_id,
                intake_id=medical_result.get('intake_id'),
                analysis_type='medical_extraction',
                model_used=medical_result.get('ai_analysis', {}).get('model_used', 'gpt-4o-mini'),
                results=medical_result,
                confidence_score=medical_result.get('severity_analysis', {}).get('confidence', 0),
                processing_time_seconds=medical_result.get('processing_time', 0),
                status='completed',
                created_by=user_id or 1
            )
            session.add(analysis_result)
            
            # Store in specialized medical extraction table
            medical_info = medical_result.get('medical_information', {})
            if medical_info:
                extraction = MedicalExtraction(
                    document_id=document_id,
                    diagnoses=medical_info.get('diagnoses'),
                    procedures=medical_info.get('procedures'),
                    medications=medical_info.get('medications'),
                    treatment_dates=medical_info.get('treatment_plan'),
                    providers=medical_info.get('providers'),
                    medical_summary=medical_info.get('medical_summary', ''),
                    severity_assessment=medical_result.get('severity_analysis', {}).get('overall_severity'),
                    medical_costs=medical_result.get('cost_analysis'),
                    model_used=medical_result.get('ai_analysis', {}).get('model_used', 'gpt-4o-mini'),
                    extraction_confidence=medical_result.get('severity_analysis', {}).get('confidence', 0)
                )
                session.add(extraction)
            
            await session.commit()
            
            logger.info(f"Stored medical results for document {document_id}")
            return True
            
    except Exception as e:
        logger.error(f"Failed to store medical results for document {document_id}: {e}")
        return False

@celery_app.task(
    queue="medical_queue",
    name="medical.batch_extract",
    time_limit=900,  # 15 minutes for batch
    soft_time_limit=720  # 12 minutes
)
def batch_extract_medical_async(
    documents: List[Dict[str, Any]],
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Extract medical information from multiple documents in batch
    """
    start_time = time.time()
    results = []
    
    try:
        logger.info(f"Starting batch medical extraction for {len(documents)} documents")
        
        for i, doc_info in enumerate(documents):
            try:
                # Process individual document
                result = extract_medical_information_async.apply(
                    args=[
                        doc_info['document_id'],
                        doc_info['content'],
                        user_id,
                        firm_id
                    ]
                ).get()
                
                results.append(result)
                
                # Update batch progress
                progress = int((i + 1) / len(documents) * 100)
                logger.info(f"Batch medical progress: {progress}% ({i + 1}/{len(documents)})")
                
            except Exception as e:
                logger.error(f"Failed to extract medical info from document {doc_info['document_id']}: {e}")
                results.append({
                    'status': 'failed',
                    'document_id': doc_info['document_id'],
                    'error': str(e)
                })
        
        processing_time = time.time() - start_time
        
        return {
            'status': 'completed',
            'batch_size': len(documents),
            'results': results,
            'successful': len([r for r in results if r['status'] == 'completed']),
            'failed': len([r for r in results if r['status'] == 'failed']),
            'processing_time': processing_time
        }
        
    except Exception as e:
        logger.error(f"Batch medical extraction failed: {str(e)}")
        return {
            'status': 'failed',
            'error': str(e),
            'processing_time': time.time() - start_time,
            'partial_results': results
        }

@celery_app.task(
    queue="medical_queue",
    name="medical.calculate_costs"
)
def calculate_medical_costs_async(
    medical_extractions: List[Dict[str, Any]],
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Calculate total medical costs from extractions
    """
    try:
        total_costs = 0
        cost_breakdown = {
            'emergency_room': 0,
            'hospital_stay': 0,
            'procedures': 0,
            'medications': 0,
            'therapy': 0,
            'imaging': 0
        }
        
        for extraction in medical_extractions:
            cost_analysis = extraction.get('result', {}).get('cost_analysis', {})
            if cost_analysis:
                total_costs += cost_analysis.get('total_identifiable_costs', 0)
                
                # Add to breakdown
                categories = cost_analysis.get('cost_categories', {})
                for category, amount in categories.items():
                    if category in cost_breakdown:
                        cost_breakdown[category] += amount
        
        return {
            'status': 'completed',
            'total_medical_costs': total_costs,
            'cost_breakdown': cost_breakdown,
            'documents_analyzed': len(medical_extractions)
        }
        
    except Exception as e:
        logger.error(f"Medical cost calculation failed: {e}")
        return {
            'status': 'failed',
            'error': str(e)
        }