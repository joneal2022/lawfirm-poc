"""
Celery Application Configuration for Async AI Processing
MAAP Legal Intake System - Background Job Processing
"""

from celery import Celery
from celery.signals import worker_ready, worker_shutting_down
import redis
import logging
from typing import Dict, Any

from config.settings import settings

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Create Celery application
celery_app = Celery(
    "legal_intake_ai",
    broker=settings.celery_broker_url,
    backend=settings.celery_result_backend,
    include=[
        "core.tasks.ocr_tasks",
        "core.tasks.classification_tasks", 
        "core.tasks.medical_tasks",
        "core.tasks.legal_tasks",
        "core.tasks.merit_tasks"
    ]
)

# Celery configuration
celery_app.conf.update(
    # Task routing
    task_routes={
        "ocr.*": {"queue": "ocr_queue"},
        "classification.*": {"queue": "classification_queue"},
        "medical.*": {"queue": "medical_queue"},
        "legal.*": {"queue": "legal_queue"},
        "merit.*": {"queue": "merit_queue"},
    },
    
    # Task execution settings
    task_serializer="json",
    accept_content=["json"],
    result_serializer="json",
    timezone="UTC",
    enable_utc=True,
    
    # Worker settings
    worker_prefetch_multiplier=1,  # Process one task at a time for AI workloads
    task_acks_late=True,
    worker_disable_rate_limits=False,
    
    # Task time limits
    task_soft_time_limit=300,  # 5 minutes soft limit
    task_time_limit=600,       # 10 minutes hard limit
    
    # Result backend settings
    result_expires=3600,  # Results expire after 1 hour
    result_persistent=True,
    
    # Retry settings
    task_default_retry_delay=60,  # 1 minute delay
    task_max_retries=3,
    
    # Priority queues
    worker_hijack_root_logger=False,
    worker_log_format="[%(asctime)s: %(levelname)s/%(processName)s] %(message)s",
    worker_task_log_format="[%(asctime)s: %(levelname)s/%(processName)s][%(task_name)s(%(task_id)s)] %(message)s",
    
    # Monitoring
    worker_send_task_events=True,
    task_send_sent_event=True,
    
    # Security
    worker_disable_rate_limits=False,
    task_always_eager=False,  # Set to True for testing
)

# Queue definitions with priorities
QUEUE_DEFINITIONS = {
    "ocr_queue": {
        "exchange": "ocr",
        "routing_key": "ocr",
        "priority": 3,
        "description": "OCR document processing tasks"
    },
    "classification_queue": {
        "exchange": "classification", 
        "routing_key": "classification",
        "priority": 4,
        "description": "Document classification tasks"
    },
    "medical_queue": {
        "exchange": "medical",
        "routing_key": "medical", 
        "priority": 2,
        "description": "Medical NLP processing tasks"
    },
    "legal_queue": {
        "exchange": "legal",
        "routing_key": "legal",
        "priority": 1,
        "description": "Legal analysis tasks (highest priority)"
    },
    "merit_queue": {
        "exchange": "merit",
        "routing_key": "merit",
        "priority": 1,
        "description": "Case merit analysis tasks (highest priority)"
    }
}

# Redis connection for queue monitoring
redis_client = redis.Redis.from_url(settings.redis_url)

@worker_ready.connect
def worker_ready_handler(sender=None, **kwargs):
    """Handle worker startup"""
    logger.info(f"Celery worker {sender} is ready")
    
    # Test Redis connection
    try:
        redis_client.ping()
        logger.info("Redis connection successful")
    except Exception as e:
        logger.error(f"Redis connection failed: {e}")

@worker_shutting_down.connect  
def worker_shutting_down_handler(sender=None, **kwargs):
    """Handle worker shutdown"""
    logger.info(f"Celery worker {sender} is shutting down")

def get_queue_stats() -> Dict[str, Any]:
    """Get queue statistics for monitoring"""
    stats = {}
    
    try:
        for queue_name, config in QUEUE_DEFINITIONS.items():
            # Get queue length
            queue_length = redis_client.llen(f"celery:{queue_name}")
            
            # Get active tasks (approximate)
            active_key = f"celery:active:{queue_name}"
            active_count = redis_client.zcard(active_key) if redis_client.exists(active_key) else 0
            
            stats[queue_name] = {
                "pending": queue_length,
                "active": active_count,
                "priority": config["priority"],
                "description": config["description"]
            }
            
    except Exception as e:
        logger.error(f"Error getting queue stats: {e}")
        
    return stats

def purge_queue(queue_name: str) -> bool:
    """Purge all tasks from a specific queue"""
    try:
        celery_app.control.purge()
        logger.info(f"Purged queue: {queue_name}")
        return True
    except Exception as e:
        logger.error(f"Error purging queue {queue_name}: {e}")
        return False

def get_worker_stats() -> Dict[str, Any]:
    """Get worker statistics"""
    try:
        inspect = celery_app.control.inspect()
        
        return {
            "active": inspect.active(),
            "scheduled": inspect.scheduled(), 
            "reserved": inspect.reserved(),
            "stats": inspect.stats(),
            "registered": inspect.registered()
        }
    except Exception as e:
        logger.error(f"Error getting worker stats: {e}")
        return {}

# Health check function
def health_check() -> Dict[str, Any]:
    """Check health of Celery system"""
    health_status = {
        "celery": "unknown",
        "redis": "unknown", 
        "queues": {},
        "workers": 0
    }
    
    try:
        # Test Redis connection
        redis_client.ping()
        health_status["redis"] = "healthy"
    except Exception as e:
        health_status["redis"] = f"unhealthy: {str(e)}"
    
    try:
        # Check worker status
        inspect = celery_app.control.inspect()
        stats = inspect.stats()
        
        if stats:
            health_status["workers"] = len(stats)
            health_status["celery"] = "healthy"
        else:
            health_status["celery"] = "no_workers"
            
        # Get queue stats
        health_status["queues"] = get_queue_stats()
        
    except Exception as e:
        health_status["celery"] = f"unhealthy: {str(e)}"
    
    return health_status

if __name__ == "__main__":
    # For debugging - print configuration
    print("Celery Configuration:")
    print(f"Broker: {settings.celery_broker_url}")
    print(f"Backend: {settings.celery_result_backend}")
    print(f"Queues: {list(QUEUE_DEFINITIONS.keys())}")
    print(f"Health Check: {health_check()}")