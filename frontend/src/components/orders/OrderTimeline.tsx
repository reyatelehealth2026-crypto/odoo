'use client';

import React from 'react';
import { OrderTimelineEntry } from '@/types';
import { OrderStatusBadge } from './OrderStatusBadge';

interface OrderTimelineProps {
  timeline: OrderTimelineEntry[];
  className?: string;
}

export function OrderTimeline({ timeline, className = '' }: OrderTimelineProps) {
  const formatDate = (date: Date | string) => {
    return new Intl.DateTimeFormat('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: 'Asia/Bangkok',
    }).format(new Date(date));
  };

  const getSourceIcon = (source: string) => {
    switch (source) {
      case 'webhook':
        return (
          <div className="flex items-center justify-center w-8 h-8 bg-blue-100 rounded-full">
            <svg className="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>
        );
      case 'manual':
        return (
          <div className="flex items-center justify-center w-8 h-8 bg-green-100 rounded-full">
            <svg className="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
          </div>
        );
      case 'system':
      default:
        return (
          <div className="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full">
            <svg className="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
            </svg>
          </div>
        );
    }
  };

  const getSourceLabel = (source: string) => {
    switch (source) {
      case 'webhook':
        return 'Webhook';
      case 'manual':
        return 'Manual';
      case 'system':
      default:
        return 'System';
    }
  };

  if (!timeline || timeline.length === 0) {
    return (
      <div className={`bg-gray-50 border border-gray-200 rounded-lg p-6 text-center ${className}`}>
        <p className="text-gray-500">No timeline entries available</p>
      </div>
    );
  }

  return (
    <div className={`bg-white border border-gray-200 rounded-lg ${className}`}>
      <div className="p-4">
        <div className="flow-root">
          <ul className="-mb-8">
            {timeline.map((entry, index) => (
              <li key={entry.id}>
                <div className="relative pb-8">
                  {index !== timeline.length - 1 && (
                    <span
                      className="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200"
                      aria-hidden="true"
                    />
                  )}
                  <div className="relative flex space-x-3">
                    <div className="flex-shrink-0">
                      {getSourceIcon(entry.source)}
                    </div>
                    <div className="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                      <div className="flex-1">
                        <div className="flex items-center space-x-2 mb-1">
                          <OrderStatusBadge status={entry.status} />
                          {entry.previousStatus && (
                            <>
                              <span className="text-gray-400">←</span>
                              <OrderStatusBadge status={entry.previousStatus} />
                            </>
                          )}
                        </div>
                        
                        <div className="text-sm text-gray-600 mb-1">
                          <span className="font-medium">{getSourceLabel(entry.source)}</span>
                          {entry.changedBy && entry.changedBy !== 'system' && (
                            <span> by {entry.changedBy}</span>
                          )}
                        </div>

                        {entry.notes && (
                          <div className="text-sm text-gray-700 bg-gray-50 rounded p-2 mt-2">
                            {entry.notes}
                          </div>
                        )}
                      </div>
                      <div className="text-right text-sm whitespace-nowrap text-gray-500">
                        {formatDate(entry.changedAt)}
                      </div>
                    </div>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}