"""
Case Merit Analysis Tasks for Celery Queue
Async case evaluation using Claude 3.5 Sonnet
"""

import asyncio
import time
import logging
from typing import Dict, Any, Optional
from celery import Task

from core.celery_app import celery_app
from services.case_merit.merit_analyzer import case_merit_analyzer
from core.database import AsyncSessionLocal

logger = logging.getLogger(__name__)

class MeritTask(Task):
    """Base case merit analysis task with retry logic"""
    
    autoretry_for = (Exception,)
    retry_kwargs = {'max_retries': 2, 'countdown': 180}  # Less retries for expensive Claude calls
    retry_backoff = True

@celery_app.task(
    bind=True,
    base=MeritTask,
    queue="merit_queue",
    name="merit.analyze_case",
    time_limit=600,  # 10 minutes for comprehensive analysis
    soft_time_limit=480  # 8 minutes
)
def analyze_case_merit_async(
    self,
    intake_id: int,
    case_data: Dict[str, Any],
    use_ai_analysis: bool = True,
    user_id: int = None,
    firm_id: int = None
) -> Dict[str, Any]:
    """
    Analyze case merit asynchronously using Claude 3.5 Sonnet
    """
    start_time = time.time()
    
    try:
        logger.info(f"Starting case merit analysis for intake {intake_id}")
        
        self.update_state(
            state='PROCESSING',
            meta={
                'intake_id': intake_id,
                'progress': 20,
                'status': 'Analyzing case merit with Claude 3.5 Sonnet'
            }
        )
        
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        
        try:
            # Analyze case merit
            result = loop.run_until_complete(
                case_merit_analyzer.analyze_case_merit(
                    case_data=case_data,
                    intake_id=intake_id,
                    use_ai_analysis=use_ai_analysis
                )
            )
            
            self.update_state(
                state='PROCESSING',
                meta={
                    'intake_id': intake_id,
                    'progress': 80,
                    'status': 'Saving case merit analysis results'
                }
            )
            
            # Store results
            if result['status'] == 'completed':
                loop.run_until_complete(
                    store_merit_results(intake_id, result, user_id, firm_id)
                )
            
            processing_time = time.time() - start_time
            result['celery_processing_time'] = processing_time
            
            logger.info(f"Case merit analysis completed for intake {intake_id} in {processing_time:.2f}s")
            
            return {
                'status': 'completed',
                'intake_id': intake_id,
                'result': result,
                'processing_time': processing_time,
                'task_id': self.request.id
            }
            
        finally:
            loop.close()
            
    except Exception as e:
        logger.error(f"Case merit analysis failed for intake {intake_id}: {str(e)}")
        
        self.update_state(
            state='FAILURE',
            meta={
                'intake_id': intake_id,
                'error': str(e),
                'processing_time': time.time() - start_time
            }
        )
        
        raise

async def store_merit_results(
    intake_id: int,
    merit_result: Dict[str, Any],
    user_id: int = None,
    firm_id: int = None
) -> bool:
    """
    Store case merit analysis results in database
    """
    try:
        async with AsyncSessionLocal() as session:
            from core.database import CaseScore
            
            # Store in case scores table
            component_scores = merit_result.get('component_scores', {})
            value_estimate = merit_result.get('value_estimate', {})
            recommendation = merit_result.get('recommendation', {})
            
            case_score = CaseScore(
                intake_id=intake_id,
                liability_strength=component_scores.get('liability_strength', 0),
                damages_potential=component_scores.get('damages_potential', 0),
                collectibility_score=component_scores.get('collectibility', 0),
                case_complexity=component_scores.get('case_complexity', 0),
                resource_requirements=component_scores.get('resource_requirements', 0),
                success_probability=component_scores.get('success_probability', 0),
                overall_score=merit_result.get('overall_score', 0),
                recommendation=recommendation.get('recommendation', 'review'),
                estimated_settlement_range_low=value_estimate.get('low_estimate', 0),
                estimated_settlement_range_high=value_estimate.get('high_estimate', 0),
                analysis_notes=recommendation.get('reasoning', ''),
                model_used='claude-3.5-sonnet',
                model_confidence=recommendation.get('confidence', 0),
                created_by=user_id or 1
            )
            
            session.add(case_score)
            await session.commit()
            
            logger.info(f"Stored case merit results for intake {intake_id}")
            return True
            
    except Exception as e:
        logger.error(f"Failed to store case merit results for intake {intake_id}: {e}")
        return False