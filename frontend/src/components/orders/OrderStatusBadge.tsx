'use client';

import React from 'react';

interface OrderStatusBadgeProps {
  status: string;
  className?: string;
}

export function OrderStatusBadge({ status, className = '' }: OrderStatusBadgeProps) {
  const getStatusConfig = (status: string) => {
    const statusLower = status.toLowerCase();
    
    switch (statusLower) {
      case 'draft':
        return {
          label: 'Draft',
          className: 'bg-gray-100 text-gray-800 border-gray-200',
        };
      case 'pending':
        return {
          label: 'Pending',
          className: 'bg-yellow-100 text-yellow-800 border-yellow-200',
        };
      case 'confirmed':
        return {
          label: 'Confirmed',
          className: 'bg-blue-100 text-blue-800 border-blue-200',
        };
      case 'processing':
        return {
          label: 'Processing',
          className: 'bg-purple-100 text-purple-800 border-purple-200',
        };
      case 'completed':
      case 'done':
      case 'delivered':
        return {
          label: 'Completed',
          className: 'bg-green-100 text-green-800 border-green-200',
        };
      case 'cancelled':
      case 'canceled':
        return {
          label: 'Cancelled',
          className: 'bg-red-100 text-red-800 border-red-200',
        };
      case 'on_hold':
      case 'hold':
        return {
          label: 'On Hold',
          className: 'bg-orange-100 text-orange-800 border-orange-200',
        };
      default:
        return {
          label: status.charAt(0).toUpperCase() + status.slice(1),
          className: 'bg-gray-100 text-gray-800 border-gray-200',
        };
    }
  };

  const config = getStatusConfig(status);

  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${config.className} ${className}`}>
      {config.label}
    </span>
  );
}