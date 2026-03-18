import { QueryClient, DefaultOptions } from '@tanstack/react-query';
import performanceMonitor from '@/lib/performance/PerformanceMonitor';

// Default query options optimized for performance
const defaultOptions: DefaultOptions = {
  queries: {
    // Stale time - how long data is considered fresh
    staleTime: 5 * 60 * 1000, // 5 minutes
    
    // Cache time - how long data stays in cache after becoming unused
    gcTime: 10 * 60 * 1000, // 10 minutes (formerly cacheTime)
    
    // Retry configuration
    retry: (failureCount, error: any) => {
      // Don't retry on 4xx errors (client errors)
      if (error?.status >= 400 && error?.status < 500) {
        return false;
      }
      // Retry up to 3 times for other errors
      return failureCount < 3;
    },
    
    // Retry delay with exponential backoff
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
    
    // Refetch configuration
    refetchOnWindowFocus: false, // Disable refetch on window focus for better performance
    refetchOnReconnect: true,
    refetchOnMount: true,
    
    // Network mode
    networkMode: 'online',
  },
  mutations: {
    // Retry mutations once
    retry: 1,
    
    // Network mode for mutations
    networkMode: 'online',
  },
};

// Create query client with performance monitoring
export const queryClient = new QueryClient({
  defaultOptions,
  queryCache: {
    onError: (error, query) => {
      console.error('Query error:', error, query);
      
      // Record performance metric for failed queries
      performanceMonitor.recordMetric({
        name: 'query_error',
        value: 1,
        url: query.queryKey.join(':'),
      });
    },
    onSuccess: (data, query) => {
      // Record successful query metrics
      performanceMonitor.recordMetric({
        name: 'query_success',
        value: 1,
        url: query.queryKey.join(':'),
      });
    },
  },
  mutationCache: {
    onError: (error, variables, context, mutation) => {
      console.error('Mutation error:', error, mutation);
      
      // Record performance metric for failed mutations
      performanceMonitor.recordMetric({
        name: 'mutation_error',
        value: 1,
        url: mutation.options.mutationKey?.join(':') || 'unknown',
      });
    },
    onSuccess: (data, variables, context, mutation) => {
      // Record successful mutation metrics
      performanceMonitor.recordMetric({
        name: 'mutation_success',
        value: 1,
        url: mutation.options.mutationKey?.join(':') || 'unknown',
      });
    },
  },
});

// Query key factory for consistent cache keys
export const queryKeys = {
  // Dashboard queries
  dashboard: {
    all: ['dashboard'] as const,
    overview: (accountId: string, dateRange?: { from: Date; to: Date }) => 
      ['dashboard', 'overview', accountId, dateRange] as const,
    metrics: (accountId: string, type: string) => 
      ['dashboard', 'metrics', accountId, type] as const,
    realtime: (accountId: string) => 
      ['dashboard', 'realtime', accountId] as const,
  },
  
  // Order queries
  orders: {
    all: ['orders'] as const,
    lists: () => ['orders', 'list'] as const,
    list: (filters: any) => ['orders', 'list', filters] as const,
    details: () => ['orders', 'detail'] as const,
    detail: (id: string) => ['orders', 'detail', id] as const,
    timeline: (id: string) => ['orders', 'timeline', id] as const,
  },
  
  // Payment queries
  payments: {
    all: ['payments'] as const,
    slips: (filters: any) => ['payments', 'slips', filters] as const,
    pending: () => ['payments', 'pending'] as const,
    processed: (dateRange: any) => ['payments', 'processed', dateRange] as const,
  },
  
  // Webhook queries
  webhooks: {
    all: ['webhooks'] as const,
    logs: (filters: any) => ['webhooks', 'logs', filters] as const,
    stats: (dateRange: any) => ['webhooks', 'stats', dateRange] as const,
    detail: (id: string) => ['webhooks', 'detail', id] as const,
  },
  
  // Customer queries
  customers: {
    all: ['customers'] as const,
    list: (filters: any) => ['customers', 'list', filters] as const,
    detail: (id: string) => ['customers', 'detail', id] as const,
    orders: (id: string) => ['customers', 'orders', id] as const,
  },
};

// Cache invalidation helpers
export const invalidateQueries = {
  dashboard: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all }),
  orders: () => queryClient.invalidateQueries({ queryKey: queryKeys.orders.all }),
  payments: () => queryClient.invalidateQueries({ queryKey: queryKeys.payments.all }),
  webhooks: () => queryClient.invalidateQueries({ queryKey: queryKeys.webhooks.all }),
  customers: () => queryClient.invalidateQueries({ queryKey: queryKeys.customers.all }),
};

// Prefetch helpers for cache warming
export const prefetchQueries = {
  dashboardOverview: async (accountId: string) => {
    await queryClient.prefetchQuery({
      queryKey: queryKeys.dashboard.overview(accountId),
      queryFn: () => fetch(`/api/v1/dashboard/overview?accountId=${accountId}`).then(r => r.json()),
      staleTime: 2 * 60 * 1000, // 2 minutes
    });
  },
  
  recentOrders: async (accountId: string) => {
    await queryClient.prefetchQuery({
      queryKey: queryKeys.orders.list({ accountId, limit: 10 }),
      queryFn: () => fetch(`/api/v1/orders?accountId=${accountId}&limit=10`).then(r => r.json()),
      staleTime: 1 * 60 * 1000, // 1 minute
    });
  },
  
  pendingPayments: async (accountId: string) => {
    await queryClient.prefetchQuery({
      queryKey: queryKeys.payments.pending(),
      queryFn: () => fetch(`/api/v1/payments/slips?status=pending&accountId=${accountId}`).then(r => r.json()),
      staleTime: 30 * 1000, // 30 seconds
    });
  },
};

// Optimistic update helpers
export const optimisticUpdates = {
  updateOrderStatus: (orderId: string, newStatus: string) => {
    queryClient.setQueryData(
      queryKeys.orders.detail(orderId),
      (oldData: any) => oldData ? { ...oldData, status: newStatus } : oldData
    );
    
    // Also update in lists
    queryClient.setQueriesData(
      { queryKey: queryKeys.orders.lists() },
      (oldData: any) => {
        if (!oldData?.data) return oldData;
        
        return {
          ...oldData,
          data: oldData.data.map((order: any) =>
            order.id === orderId ? { ...order, status: newStatus } : order
          ),
        };
      }
    );
  },
  
  updatePaymentStatus: (paymentId: string, newStatus: string) => {
    queryClient.setQueriesData(
      { queryKey: queryKeys.payments.all },
      (oldData: any) => {
        if (!oldData?.data) return oldData;
        
        return {
          ...oldData,
          data: oldData.data.map((payment: any) =>
            payment.id === paymentId ? { ...payment, status: newStatus } : payment
          ),
        };
      }
    );
  },
};

// Background sync for critical data
export const backgroundSync = {
  startDashboardSync: (accountId: string) => {
    const interval = setInterval(async () => {
      try {
        // Silently refetch critical dashboard data
        await queryClient.refetchQueries({
          queryKey: queryKeys.dashboard.realtime(accountId),
          type: 'active',
        });
      } catch (error) {
        console.warn('Background sync failed:', error);
      }
    }, 30000); // Every 30 seconds
    
    return () => clearInterval(interval);
  },
  
  startOrderSync: () => {
    const interval = setInterval(async () => {
      try {
        // Refetch pending orders
        await queryClient.refetchQueries({
          queryKey: queryKeys.orders.lists(),
          type: 'active',
        });
      } catch (error) {
        console.warn('Order sync failed:', error);
      }
    }, 60000); // Every minute
    
    return () => clearInterval(interval);
  },
};