'use client';

import { useEffect, useCallback, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useWebSocket } from './useWebSocket';

export interface DashboardMetrics {
  orders: {
    todayCount: number;
    todayTotal: number;
    pendingCount: number;
    completedCount: number;
    averageOrderValue: number;
  };
  payments: {
    pendingSlips: number;
    processedToday: number;
    matchingRate: number;
    totalAmount: number;
    averageProcessingTime: number;
  };
  webhooks: {
    todayCount: number;
    successRate: number;
    failedCount: number;
    averageResponseTime: number;
  };
  customers: {
    totalActive: number;
    newToday: number;
    lineConnected: number;
    averageOrdersPerCustomer: number;
  };
  updatedAt: string;
}

export interface OrderStatusUpdate {
  orderId: string;
  oldStatus: string;
  newStatus: string;
  updatedBy: string;
  updatedAt: string;
  orderData?: any;
}

export interface PaymentProcessedUpdate {
  paymentId: string;
  orderId?: string;
  amount: number;
  status: 'matched' | 'processed' | 'failed';
  processedBy: string;
  processedAt: string;
  matchingRate?: number;
}

export interface WebhookReceivedUpdate {
  webhookId: string;
  type: string;
  status: 'success' | 'failed' | 'pending';
  responseTime: number;
  receivedAt: string;
  payload?: any;
}

export interface DashboardRealtimeHookReturn {
  connected: boolean;
  connecting: boolean;
  error: string | null;
  reconnectAttempt: number;
  lastUpdate: Date | null;
  subscribe: (metrics?: string[]) => void;
  requestData: () => void;
}

export function useDashboardRealtime(
  token?: string,
  enabled: boolean = true
): DashboardRealtimeHookReturn {
  const queryClient = useQueryClient();
  const lastUpdateRef = useRef<Date | null>(null);
  
  const { socket, status, emit, on, off } = useWebSocket(
    {
      url: process.env.NEXT_PUBLIC_DASHBOARD_WEBSOCKET_URL || 'http://localhost:3001',
      path: '/dashboard-socket.io/',
      autoConnect: enabled,
    },
    token
  );

  // Handle metrics updates
  const handleMetricsUpdate = useCallback((metrics: DashboardMetrics) => {
    console.log('Dashboard metrics updated:', metrics);
    
    // Update React Query cache with optimistic update
    queryClient.setQueryData(['dashboard', 'metrics'], metrics);
    
    // Invalidate related queries to trigger refetch if needed
    queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    
    lastUpdateRef.current = new Date();
  }, [queryClient]);

  // Handle order status changes
  const handleOrderStatusChange = useCallback((update: OrderStatusUpdate) => {
    console.log('Order status changed:', update);
    
    // Update specific order in cache
    queryClient.setQueryData(['orders', update.orderId], (oldData: any) => {
      if (oldData) {
        return {
          ...oldData,
          status: update.newStatus,
          updatedAt: update.updatedAt,
          updatedBy: update.updatedBy,
        };
      }
      return oldData;
    });
    
    // Invalidate orders list to refresh
    queryClient.invalidateQueries({ queryKey: ['orders'] });
    
    // Show notification (you can integrate with your notification system)
    if (typeof window !== 'undefined' && 'Notification' in window) {
      if (Notification.permission === 'granted') {
        new Notification('Order Status Updated', {
          body: `Order ${update.orderId} changed from ${update.oldStatus} to ${update.newStatus}`,
          icon: '/icon-192x192.png',
        });
      }
    }
    
    lastUpdateRef.current = new Date();
  }, [queryClient]);

  // Handle payment processing updates
  const handlePaymentProcessed = useCallback((update: PaymentProcessedUpdate) => {
    console.log('Payment processed:', update);
    
    // Update payment in cache
    queryClient.setQueryData(['payments', update.paymentId], (oldData: any) => {
      if (oldData) {
        return {
          ...oldData,
          status: update.status,
          processedAt: update.processedAt,
          processedBy: update.processedBy,
        };
      }
      return oldData;
    });
    
    // Invalidate payments list
    queryClient.invalidateQueries({ queryKey: ['payments'] });
    
    // If payment is matched to an order, update order status
    if (update.orderId && update.status === 'matched') {
      queryClient.invalidateQueries({ queryKey: ['orders', update.orderId] });
    }
    
    // Show notification
    if (typeof window !== 'undefined' && 'Notification' in window) {
      if (Notification.permission === 'granted') {
        const statusText = update.status === 'matched' ? 'matched successfully' : 
                          update.status === 'processed' ? 'processed' : 'failed';
        new Notification('Payment Update', {
          body: `Payment ${update.paymentId} ${statusText}`,
          icon: '/icon-192x192.png',
        });
      }
    }
    
    lastUpdateRef.current = new Date();
  }, [queryClient]);

  // Handle webhook updates
  const handleWebhookReceived = useCallback((update: WebhookReceivedUpdate) => {
    console.log('Webhook received:', update);
    
    // Update webhook in cache
    queryClient.setQueryData(['webhooks', update.webhookId], update);
    
    // Invalidate webhooks list
    queryClient.invalidateQueries({ queryKey: ['webhooks'] });
    
    lastUpdateRef.current = new Date();
  }, [queryClient]);

  // Handle initial connection data
  const handleConnected = useCallback((data: any) => {
    console.log('Dashboard WebSocket connected:', data);
    
    // If initial data is provided, update cache
    if (data.initialData) {
      queryClient.setQueryData(['dashboard', 'metrics'], data.initialData);
    }
    
    lastUpdateRef.current = new Date();
  }, [queryClient]);

  // Handle subscription confirmation
  const handleSubscriptionConfirmed = useCallback((data: { metrics: string[]; timestamp: number }) => {
    console.log('Dashboard subscription confirmed:', data);
  }, []);

  // Handle dashboard data response
  const handleDashboardData = useCallback((data: { metrics: DashboardMetrics; timestamp: number }) => {
    console.log('Dashboard data received:', data);
    
    if (data.metrics) {
      queryClient.setQueryData(['dashboard', 'metrics'], data.metrics);
    }
    
    lastUpdateRef.current = new Date();
  }, [queryClient]);

  // Handle heartbeat
  const handleHeartbeat = useCallback((data: { timestamp: number }) => {
    console.log('Dashboard heartbeat received:', new Date(data.timestamp));
  }, []);

  // Set up event listeners
  useEffect(() => {
    if (!socket || !status.connected) return;

    // Register event handlers
    on('connected', handleConnected);
    on('metrics_updated', handleMetricsUpdate);
    on('order_status_changed', handleOrderStatusChange);
    on('payment_processed', handlePaymentProcessed);
    on('webhook_received', handleWebhookReceived);
    on('subscription_confirmed', handleSubscriptionConfirmed);
    on('dashboard_data', handleDashboardData);
    on('heartbeat', handleHeartbeat);

    // Cleanup function
    return () => {
      off('connected', handleConnected);
      off('metrics_updated', handleMetricsUpdate);
      off('order_status_changed', handleOrderStatusChange);
      off('payment_processed', handlePaymentProcessed);
      off('webhook_received', handleWebhookReceived);
      off('subscription_confirmed', handleSubscriptionConfirmed);
      off('dashboard_data', handleDashboardData);
      off('heartbeat', handleHeartbeat);
    };
  }, [
    socket,
    status.connected,
    on,
    off,
    handleConnected,
    handleMetricsUpdate,
    handleOrderStatusChange,
    handlePaymentProcessed,
    handleWebhookReceived,
    handleSubscriptionConfirmed,
    handleDashboardData,
    handleHeartbeat,
  ]);

  // Auto-subscribe to all metrics when connected
  useEffect(() => {
    if (socket && status.connected) {
      emit('subscribe_dashboard', { metrics: ['all'] });
    }
  }, [socket, status.connected, emit]);

  // Request notification permission on first connection
  useEffect(() => {
    if (status.connected && typeof window !== 'undefined' && 'Notification' in window) {
      if (Notification.permission === 'default') {
        Notification.requestPermission().then((permission) => {
          console.log('Notification permission:', permission);
        });
      }
    }
  }, [status.connected]);

  // Subscribe to specific metrics
  const subscribe = useCallback((metrics: string[] = ['all']) => {
    if (socket && status.connected) {
      emit('subscribe_dashboard', { metrics });
    }
  }, [socket, status.connected, emit]);

  // Request current dashboard data
  const requestData = useCallback(() => {
    if (socket && status.connected) {
      emit('request_dashboard_data', {});
    }
  }, [socket, status.connected, emit]);

  return {
    connected: status.connected,
    connecting: status.connecting,
    error: status.error,
    reconnectAttempt: status.reconnectAttempt,
    lastUpdate: lastUpdateRef.current,
    subscribe,
    requestData,
  };
}