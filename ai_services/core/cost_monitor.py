"""
Token Usage Tracking and Cost Monitoring System
Real-time cost optimization and budget controls for MAAP AI services
"""

import asyncio
import time
from datetime import datetime, timedelta
from typing import Dict, Any, List, Optional
import json
import logging

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, and_
from core.database import AsyncSessionLocal, TokenUsageLog
from config.settings import settings, MODEL_ROUTING_CONFIG

logger = logging.getLogger(__name__)

class CostMonitor:
    """
    Real-time cost monitoring and budget management system
    """
    
    def __init__(self):
        self.daily_budget = settings.token_budget_daily * 0.002  # Approximate daily cost in USD
        self.cost_target_per_doc = 0.006  # Target cost per document
        self.warning_threshold = 0.8  # 80% of budget
        self.critical_threshold = 0.95  # 95% of budget
        
    async def log_token_usage(
        self,
        session: AsyncSession,
        firm_id: int,
        user_id: int,
        service_name: str,
        model_name: str,
        input_tokens: int,
        output_tokens: int,
        request_id: str,
        endpoint: str,
        additional_metadata: Optional[Dict[str, Any]] = None
    ) -> TokenUsageLog:
        """
        Log token usage with cost calculation
        """
        try:
            # Get model pricing
            model_config = None
            for model_key, config in MODEL_ROUTING_CONFIG.items():
                if config["model_name"] == model_name:
                    model_config = config
                    break
            
            if not model_config:
                # Default pricing for unknown models
                model_config = {
                    "cost_per_1k_input": 0.001,
                    "cost_per_1k_output": 0.002
                }
            
            # Calculate costs
            input_cost = (input_tokens / 1000) * model_config["cost_per_1k_input"]
            output_cost = (output_tokens / 1000) * model_config["cost_per_1k_output"]
            total_cost = input_cost + output_cost
            
            # Create usage log entry
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
                total_cost=total_cost,
                request_id=request_id,
                endpoint=endpoint
            )
            
            session.add(usage_log)
            await session.commit()
            
            # Check budget limits
            await self._check_budget_limits(session, firm_id, total_cost)
            
            logger.info(f"Logged token usage: {total_tokens} tokens, ${total_cost:.4f} for {service_name}")
            
            return usage_log
            
        except Exception as e:
            logger.error(f"Failed to log token usage: {e}")
            raise
    
    async def _check_budget_limits(self, session: AsyncSession, firm_id: int, current_cost: float):
        """
        Check if current usage exceeds budget limits
        """
        try:
            # Get today's usage
            today = datetime.utcnow().date()
            today_start = datetime.combine(today, datetime.min.time())
            
            stmt = select(func.sum(TokenUsageLog.total_cost)).where(
                and_(
                    TokenUsageLog.firm_id == firm_id,
                    TokenUsageLog.created_at >= today_start
                )
            )
            
            result = await session.execute(stmt)
            daily_spend = result.scalar() or 0.0
            
            # Calculate percentage of daily budget used
            budget_used_pct = daily_spend / self.daily_budget
            
            if budget_used_pct >= self.critical_threshold:
                logger.critical(f"CRITICAL: Firm {firm_id} has used {budget_used_pct:.1%} of daily budget (${daily_spend:.2f}/${self.daily_budget:.2f})")
                # Could implement automatic service throttling here
                
            elif budget_used_pct >= self.warning_threshold:
                logger.warning(f"WARNING: Firm {firm_id} has used {budget_used_pct:.1%} of daily budget (${daily_spend:.2f}/${self.daily_budget:.2f})")
                
        except Exception as e:
            logger.error(f"Failed to check budget limits: {e}")
    
    async def get_usage_stats(
        self,
        firm_id: int,
        start_date: Optional[datetime] = None,
        end_date: Optional[datetime] = None
    ) -> Dict[str, Any]:
        """
        Get comprehensive usage statistics for a firm
        """
        try:
            async with AsyncSessionLocal() as session:
                # Set default date range if not provided
                if not end_date:
                    end_date = datetime.utcnow()
                if not start_date:
                    start_date = end_date - timedelta(days=30)
                
                # Base query
                base_query = select(TokenUsageLog).where(
                    and_(
                        TokenUsageLog.firm_id == firm_id,
                        TokenUsageLog.created_at >= start_date,
                        TokenUsageLog.created_at <= end_date
                    )
                )
                
                # Total usage
                total_stmt = select(
                    func.sum(TokenUsageLog.total_tokens),
                    func.sum(TokenUsageLog.total_cost),
                    func.count(TokenUsageLog.id)
                ).where(
                    and_(
                        TokenUsageLog.firm_id == firm_id,
                        TokenUsageLog.created_at >= start_date,
                        TokenUsageLog.created_at <= end_date
                    )
                )
                
                total_result = await session.execute(total_stmt)
                total_tokens, total_cost, total_requests = total_result.first()
                
                # Usage by service
                service_stmt = select(
                    TokenUsageLog.service_name,
                    func.sum(TokenUsageLog.total_tokens),
                    func.sum(TokenUsageLog.total_cost),
                    func.count(TokenUsageLog.id)
                ).where(
                    and_(
                        TokenUsageLog.firm_id == firm_id,
                        TokenUsageLog.created_at >= start_date,
                        TokenUsageLog.created_at <= end_date
                    )
                ).group_by(TokenUsageLog.service_name)
                
                service_results = await session.execute(service_stmt)
                service_usage = {}
                
                for service_name, tokens, cost, requests in service_results:
                    service_usage[service_name] = {
                        'tokens': tokens or 0,
                        'cost': cost or 0.0,
                        'requests': requests or 0
                    }
                
                # Usage by model
                model_stmt = select(
                    TokenUsageLog.model_name,
                    func.sum(TokenUsageLog.total_tokens),
                    func.sum(TokenUsageLog.total_cost),
                    func.count(TokenUsageLog.id)
                ).where(
                    and_(
                        TokenUsageLog.firm_id == firm_id,
                        TokenUsageLog.created_at >= start_date,
                        TokenUsageLog.created_at <= end_date
                    )
                ).group_by(TokenUsageLog.model_name)
                
                model_results = await session.execute(model_stmt)
                model_usage = {}
                
                for model_name, tokens, cost, requests in model_results:
                    model_usage[model_name] = {
                        'tokens': tokens or 0,
                        'cost': cost or 0.0,
                        'requests': requests or 0
                    }
                
                # Daily usage for trend analysis
                daily_stmt = select(
                    func.date(TokenUsageLog.created_at),
                    func.sum(TokenUsageLog.total_tokens),
                    func.sum(TokenUsageLog.total_cost),
                    func.count(TokenUsageLog.id)
                ).where(
                    and_(
                        TokenUsageLog.firm_id == firm_id,
                        TokenUsageLog.created_at >= start_date,
                        TokenUsageLog.created_at <= end_date
                    )
                ).group_by(func.date(TokenUsageLog.created_at)).order_by(func.date(TokenUsageLog.created_at))
                
                daily_results = await session.execute(daily_stmt)
                daily_usage = []
                
                for date, tokens, cost, requests in daily_results:
                    daily_usage.append({
                        'date': date.isoformat(),
                        'tokens': tokens or 0,
                        'cost': cost or 0.0,
                        'requests': requests or 0
                    })
                
                # Calculate averages and metrics
                days_in_period = (end_date - start_date).days or 1
                avg_daily_cost = (total_cost or 0) / days_in_period
                avg_cost_per_request = (total_cost or 0) / (total_requests or 1)
                
                return {
                    'summary': {
                        'total_tokens': total_tokens or 0,
                        'total_cost': total_cost or 0.0,
                        'total_requests': total_requests or 0,
                        'avg_daily_cost': avg_daily_cost,
                        'avg_cost_per_request': avg_cost_per_request,
                        'period_days': days_in_period
                    },
                    'service_breakdown': service_usage,
                    'model_breakdown': model_usage,
                    'daily_usage': daily_usage,
                    'budget_analysis': {
                        'daily_budget': self.daily_budget,
                        'current_usage_pct': min(100, (avg_daily_cost / self.daily_budget) * 100),
                        'cost_target_per_doc': self.cost_target_per_doc,
                        'actual_cost_per_doc': avg_cost_per_request,
                        'efficiency_ratio': self.cost_target_per_doc / max(avg_cost_per_request, 0.001)
                    }
                }
                
        except Exception as e:
            logger.error(f"Failed to get usage stats: {e}")
            return {}
    
    async def get_real_time_costs(self, firm_id: int) -> Dict[str, Any]:
        """
        Get real-time cost information for today
        """
        try:
            async with AsyncSessionLocal() as session:
                # Get today's usage
                today = datetime.utcnow().date()
                today_start = datetime.combine(today, datetime.min.time())
                
                stmt = select(
                    func.sum(TokenUsageLog.total_cost),
                    func.sum(TokenUsageLog.total_tokens),
                    func.count(TokenUsageLog.id)
                ).where(
                    and_(
                        TokenUsageLog.firm_id == firm_id,
                        TokenUsageLog.created_at >= today_start
                    )
                )
                
                result = await session.execute(stmt)
                daily_cost, daily_tokens, daily_requests = result.first()
                
                # Calculate metrics
                daily_cost = daily_cost or 0.0
                daily_tokens = daily_tokens or 0
                daily_requests = daily_requests or 0
                
                budget_used_pct = (daily_cost / self.daily_budget) * 100
                remaining_budget = max(0, self.daily_budget - daily_cost)
                
                # Get current hour usage
                current_hour = datetime.utcnow().replace(minute=0, second=0, microsecond=0)
                
                hour_stmt = select(
                    func.sum(TokenUsageLog.total_cost),
                    func.count(TokenUsageLog.id)
                ).where(
                    and_(
                        TokenUsageLog.firm_id == firm_id,
                        TokenUsageLog.created_at >= current_hour
                    )
                )
                
                hour_result = await session.execute(hour_stmt)
                hourly_cost, hourly_requests = hour_result.first()
                
                return {
                    'today': {
                        'cost': daily_cost,
                        'tokens': daily_tokens,
                        'requests': daily_requests,
                        'avg_cost_per_request': daily_cost / max(daily_requests, 1)
                    },
                    'current_hour': {
                        'cost': hourly_cost or 0.0,
                        'requests': hourly_requests or 0
                    },
                    'budget': {
                        'daily_limit': self.daily_budget,
                        'used_amount': daily_cost,
                        'remaining': remaining_budget,
                        'used_percentage': budget_used_pct,
                        'status': self._get_budget_status(budget_used_pct)
                    },
                    'efficiency': {
                        'target_cost_per_doc': self.cost_target_per_doc,
                        'actual_cost_per_doc': daily_cost / max(daily_requests, 1),
                        'on_target': (daily_cost / max(daily_requests, 1)) <= self.cost_target_per_doc
                    }
                }
                
        except Exception as e:
            logger.error(f"Failed to get real-time costs: {e}")
            return {}
    
    def _get_budget_status(self, used_percentage: float) -> str:
        """Get budget status based on usage percentage"""
        if used_percentage >= self.critical_threshold * 100:
            return 'critical'
        elif used_percentage >= self.warning_threshold * 100:
            return 'warning'
        else:
            return 'healthy'
    
    async def generate_cost_report(
        self,
        firm_id: int,
        report_type: str = 'monthly'
    ) -> Dict[str, Any]:
        """
        Generate detailed cost report
        """
        try:
            # Set date range based on report type
            end_date = datetime.utcnow()
            
            if report_type == 'daily':
                start_date = end_date - timedelta(days=1)
            elif report_type == 'weekly':
                start_date = end_date - timedelta(days=7)
            elif report_type == 'monthly':
                start_date = end_date - timedelta(days=30)
            else:
                start_date = end_date - timedelta(days=30)  # Default to monthly
            
            # Get usage stats
            usage_stats = await self.get_usage_stats(firm_id, start_date, end_date)
            
            # Add report metadata
            report = {
                'report_type': report_type,
                'firm_id': firm_id,
                'period': {
                    'start_date': start_date.isoformat(),
                    'end_date': end_date.isoformat(),
                    'days': (end_date - start_date).days
                },
                'generated_at': datetime.utcnow().isoformat(),
                **usage_stats
            }
            
            # Add recommendations
            summary = usage_stats.get('summary', {})
            avg_cost_per_request = summary.get('avg_cost_per_request', 0)
            
            recommendations = []
            
            if avg_cost_per_request > self.cost_target_per_doc:
                recommendations.append(
                    f"Cost per document (${avg_cost_per_request:.4f}) exceeds target (${self.cost_target_per_doc:.4f}). "
                    "Consider optimizing model routing or reducing token usage."
                )
            
            model_breakdown = usage_stats.get('model_breakdown', {})
            claude_usage = model_breakdown.get('claude-3-5-sonnet-20241022', {})
            gpt_usage = model_breakdown.get('gpt-4o-mini', {})
            
            if claude_usage.get('cost', 0) > gpt_usage.get('cost', 0) * 2:
                recommendations.append(
                    "Claude 3.5 Sonnet usage is significantly high. Review routing logic to ensure "
                    "complex reasoning tasks are properly identified."
                )
            
            report['recommendations'] = recommendations
            
            return report
            
        except Exception as e:
            logger.error(f"Failed to generate cost report: {e}")
            return {}

# Global cost monitor instance
cost_monitor = CostMonitor()