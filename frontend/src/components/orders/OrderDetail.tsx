'use client';

import React, { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Order, OrderTimelineEntry } from '@/types';
import { orderAPI } from '@/lib/api/orders';
import { OrderStatusBadge } from './OrderStatusBadge';
import { OrderTimeline } from './OrderTimeline';

interface OrderDetailProps {
  order: Order;
  onOrderUpdate?: (updatedOrder: Order) => void;
  showStatusUpdate?: boolean;
}

export function OrderDetail({ 
  order, 
  onOrderUpdate,
  showStatusUpdate = true 
}: OrderDetailProps) {
  const [isUpdatingStatus, setIsUpdatingStatus] = useState(false);
  const [newStatus, setNewStatus] = useState(order.status);
  const [statusNotes, setStatusNotes] = useState('');
  const [notifyCustomer, setNotifyCustomer] = useState(false);

  const formatCurrency = (amount: number, currency: string = 'THB') => {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: currency,
    }).format(amount);
  };

  const formatDate = (date: Date | string | null) => {
    if (!date) return '-';
    return new Intl.DateTimeFormat('th-TH', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: 'Asia/Bangkok',
    }).format(new Date(date));
  };

  const handleStatusUpdate = async () => {
    if (newStatus === order.status) return;

    try {
      setIsUpdatingStatus(true);
      const updatedOrder = await orderAPI.updateOrderStatus(order.id, {
        status: newStatus,
        ...(statusNotes && { notes: statusNotes }),
        notifyCustomer,
      });

      if (onOrderUpdate) {
        onOrderUpdate(updatedOrder);
      }

      setStatusNotes('');
      setNotifyCustomer(false);
    } catch (error) {
      console.error('Failed to update order status:', error);
      alert('Failed to update order status. Please try again.');
    } finally {
      setIsUpdatingStatus(false);
    }
  };

  const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'pending', label: 'Pending' },
    { value: 'confirmed', label: 'Confirmed' },
    { value: 'processing', label: 'Processing' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'on_hold', label: 'On Hold' },
  ];

  return (
    <div className="space-y-6">
      {/* Order Header */}
      <div className="bg-gray-50 p-4 rounded-lg">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-gray-900">
            Order {order.odooOrderId}
          </h3>
          <OrderStatusBadge status={order.status} />
        </div>
        
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div>
            <span className="font-medium text-gray-700">Customer:</span>
            <span className="ml-2 text-gray-900">
              {order.customerName || order.customerRef || 'Unknown'}
            </span>
          </div>
          <div>
            <span className="font-medium text-gray-700">Total Amount:</span>
            <span className="ml-2 text-gray-900 font-semibold">
              {formatCurrency(order.totalAmount, order.currency)}
            </span>
          </div>
          <div>
            <span className="font-medium text-gray-700">Order Date:</span>
            <span className="ml-2 text-gray-900">
              {formatDate(order.orderDate)}
            </span>
          </div>
          <div>
            <span className="font-medium text-gray-700">Delivery Date:</span>
            <span className="ml-2 text-gray-900">
              {formatDate(order.deliveryDate)}
            </span>
          </div>
        </div>
      </div>
      {/* Order Details */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Order Information */}
        <div className="space-y-4">
          <h4 className="text-md font-semibold text-gray-900">Order Information</h4>
          
          <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-3">
            <div className="flex justify-between">
              <span className="text-sm font-medium text-gray-700">Order ID:</span>
              <span className="text-sm text-gray-900 font-mono">{order.odooOrderId}</span>
            </div>
            
            <div className="flex justify-between">
              <span className="text-sm font-medium text-gray-700">Customer Reference:</span>
              <span className="text-sm text-gray-900">{order.customerRef || '-'}</span>
            </div>
            
            <div className="flex justify-between">
              <span className="text-sm font-medium text-gray-700">Currency:</span>
              <span className="text-sm text-gray-900">{order.currency}</span>
            </div>
            
            <div className="flex justify-between">
              <span className="text-sm font-medium text-gray-700">Webhook Processed:</span>
              <span className={`text-sm ${order.webhookProcessed ? 'text-green-600' : 'text-red-600'}`}>
                {order.webhookProcessed ? 'Yes' : 'No'}
              </span>
            </div>
            
            <div className="flex justify-between">
              <span className="text-sm font-medium text-gray-700">Created:</span>
              <span className="text-sm text-gray-900">{formatDate(order.createdAt)}</span>
            </div>
            
            <div className="flex justify-between">
              <span className="text-sm font-medium text-gray-700">Last Updated:</span>
              <span className="text-sm text-gray-900">{formatDate(order.updatedAt)}</span>
            </div>
          </div>

          {/* Notes */}
          {order.notes && (
            <div>
              <h5 className="text-sm font-medium text-gray-700 mb-2">Notes:</h5>
              <div className="bg-gray-50 border border-gray-200 rounded-lg p-3">
                <p className="text-sm text-gray-900 whitespace-pre-wrap">{order.notes}</p>
              </div>
            </div>
          )}
        </div>

        {/* Status Update */}
        {showStatusUpdate && (
          <div className="space-y-4">
            <h4 className="text-md font-semibold text-gray-900">Update Status</h4>
            
            <div className="bg-white border border-gray-200 rounded-lg p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  New Status
                </label>
                <select
                  value={newStatus}
                  onChange={(e) => setNewStatus(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  {statusOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Notes (Optional)
                </label>
                <textarea
                  value={statusNotes}
                  onChange={(e) => setStatusNotes(e.target.value)}
                  placeholder="Add notes about this status change..."
                  rows={3}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              <div className="flex items-center">
                <input
                  type="checkbox"
                  id="notifyCustomer"
                  checked={notifyCustomer}
                  onChange={(e) => setNotifyCustomer(e.target.checked)}
                  className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                />
                <label htmlFor="notifyCustomer" className="ml-2 block text-sm text-gray-700">
                  Notify customer about status change
                </label>
              </div>

              <Button
                onClick={handleStatusUpdate}
                disabled={isUpdatingStatus || newStatus === order.status}
                className="w-full"
              >
                {isUpdatingStatus ? 'Updating...' : 'Update Status'}
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* Order Timeline */}
      <div>
        <h4 className="text-md font-semibold text-gray-900 mb-4">Order Timeline</h4>
        <OrderTimeline timeline={order.timeline} />
      </div>
    </div>
  );
}