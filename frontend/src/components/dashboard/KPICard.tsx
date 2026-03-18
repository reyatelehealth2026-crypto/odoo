'use client';

import React from 'react';
import { cn } from '@/lib/utils';

export interface TrendData {
  value: number;
  direction: 'up' | 'down' | 'neutral';
  percentage: number;
}

export interface KPICardProps {
  title: string;
  value: number | string;
  trend?: TrendData;
  format?: 'currency' | 'number' | 'percentage';
  loading?: boolean;
  icon?: React.ReactNode;
  className?: string;
}

export function KPICard({
  title,
  value,
  trend,
  format = 'number',
  loading = false,
  icon,
  className,
}: KPICardProps) {
  const formatValue = (val: number | string): string => {
    if (typeof val === 'string') return val;
    
    switch (format) {
      case 'currency':
        return new Intl.NumberFormat('th-TH', {
          style: 'currency',
          currency: 'THB',
          minimumFractionDigits: 0,
          maximumFractionDigits: 0,
        }).format(val);
      case 'percentage':
        return `${val.toFixed(1)}%`;
      case 'number':
      default:
        return new Intl.NumberFormat('th-TH').format(val);
    }
  };

  const getTrendIcon = (direction: TrendData['direction']) => {
    switch (direction) {
      case 'up':
        return (
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.94" />
          </svg>
        );
      case 'down':
        return (
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 6L9 12.75l4.286-4.286a11.948 11.948 0 014.306 6.43l.776 2.898m0 0l3.182-5.511m-3.182 5.511l-5.511-3.182" />
          </svg>
        );
      case 'neutral':
      default:
        return (
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14" />
          </svg>
        );
    }
  };

  const getTrendColor = (direction: TrendData['direction']) => {
    switch (direction) {
      case 'up':
        return 'text-green-600';
      case 'down':
        return 'text-red-600';
      case 'neutral':
      default:
        return 'text-gray-500';
    }
  };

  if (loading) {
    return (
      <div className={cn('rounded-lg border border-gray-200 bg-white p-6 shadow-sm', className)}>
        <div className="animate-pulse">
          <div className="flex items-center justify-between">
            <div className="h-4 w-24 rounded bg-gray-200"></div>
            {icon && <div className="h-8 w-8 rounded bg-gray-200"></div>}
          </div>
          <div className="mt-4 h-8 w-32 rounded bg-gray-200"></div>
          <div className="mt-2 h-4 w-20 rounded bg-gray-200"></div>
        </div>
      </div>
    );
  }

  return (
    <div className={cn('rounded-lg border border-gray-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md', className)}>
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium text-gray-600">{title}</h3>
        {icon && (
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
            {icon}
          </div>
        )}
      </div>
      
      <div className="mt-4">
        <p className="text-2xl font-bold text-gray-900">
          {formatValue(value)}
        </p>
        
        {trend && (
          <div className={cn('mt-2 flex items-center text-sm', getTrendColor(trend.direction))}>
            {getTrendIcon(trend.direction)}
            <span className="ml-1">
              {trend.percentage > 0 ? '+' : ''}{trend.percentage.toFixed(1)}%
            </span>
            <span className="ml-1 text-gray-500">จากเมื่อวาน</span>
          </div>
        )}
      </div>
    </div>
  );
}