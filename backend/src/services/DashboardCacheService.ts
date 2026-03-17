import { BaseService } from './BaseService';
import { CacheService } from './CacheService';
import { CacheInvalidationService } from './CacheInvalidationService';
import { logger } from '@/utils/logger';

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
    totalToday: number;
    successRate: number;
    failedCount: number;
    averageResponseTime: number;
  };
  customers: {
    totalActive: number;
    newToday: number;
    topCustomers: Array<{
      id: string;
      name: string;
      totalOrders: number;
      totalAmount: number;
    }>;
  };
  updatedAt: Date;
}

export interface CacheWarmingJob {
  name: string;
  schedule: string; // Cron expression
  cacheKeys: string[];
  priority: 'high' | 'medium' | 'low';
  maxExecutionTime: number;
  enabled: boolean;
}

export class DashboardCacheService extends BaseService {
  private cacheService: CacheService;
  private invalidationService: CacheInvalidationService;
  private warmingJobs: Map<string, CacheWarmingJob> = new Map();

  // Cache TTL configurations (in seconds)
  private readonly CACHE_TTL = {
    DASHBOARD_METRICS: 30 * 60, // 30 minutes
    REAL_TIME_METRICS: 30, // 30 seconds
    HISTORICAL_DATA: 24 * 60 * 60, // 24 hours
    USER_SPECIFIC: 15 * 60, // 15 minutes
    AGGREGATED_REPORTS: 60 * 60, // 1 hour
  };

  constructor(prisma: any, cacheService: CacheService) {
    super(prisma);
    this.cacheService = cacheService;
    this.invalidationService = new CacheInvalidationService(cacheService);
    this.setupCacheWarmingJobs();
  }

  /**
   * Get dashboard metrics with multi-layer caching
   */
  async getDashboardMetrics(
    lineAccountId: string,
    dateRange?: { from: Date; to: Date }
  ): Promise<DashboardMetrics> {
    const cacheKey = this.buildMetricsCacheKey(lineAccountId, dateRange);
    
    return this.cacheService.getWithFallback(
      cacheKey,
      async () => {
        logger.info('Cache miss - calculating dashboard metrics', { 
          lineAccountId, 
          dateRange 
        });
        
        return this.calculateDashboardMetrics(lineAccountId, dateRange);
      },
      {
        ttl: this.CACHE_TTL.DASHBOARD_METRICS,
        prefix: 'dashboard',
      }
    );
  }

  /**
   * Get real-time metrics (shorter TTL for frequently changing data)
   */
  async getRealTimeMetrics(lineAccountId: string): Promise<Partial<DashboardMetrics>> {
    const cacheKey = `realtime:${lineAccountId}`;
    
    return this.cacheService.getWithFallback(
      cacheKey,
      async () => {
        return this.calculateRealTimeMetrics(lineAccountId);
      },
      {
        ttl: this.CACHE_TTL.REAL_TIME_METRICS,
        prefix: 'dashboard',
      }
    );
  }

  /**
   * Warm critical dashboard data
   */
  async warmDashboardCache(lineAccountIds: string[]): Promise<void> {
    const warmingPromises = lineAccountIds.map(async (accountId) => {
      try {
        // Warm main dashboard metrics
        await this.getDashboardMetrics(accountId);
        
        // Warm real-time metrics
        await this.getRealTimeMetrics(accountId);
        
        // Warm historical data for common date ranges
        const commonRanges = this.getCommonDateRanges();
        for (const range of commonRanges) {
          await this.getDashboardMetrics(accountId, range);
        }
        
        logger.debug('Cache warming completed for account', { accountId });
      } catch (error) {
        logger.error('Cache warming failed for account', { 
          accountId, 
          error: String(error) 
        });
      }
    });

    await Promise.allSettled(warmingPromises);
    logger.info(`Cache warming completed for ${lineAccountIds.length} accounts`);
  }

  /**
   * Invalidate dashboard cache when data changes
   */
  async invalidateDashboardCache(
    lineAccountId: string,
    eventType: 'order_updated' | 'payment_processed' | 'webhook_received'
  ): Promise<void> {
    const patterns = [
      `dashboard:*:${lineAccountId}:*`,
      `dashboard:realtime:${lineAccountId}`,
      `dashboard:metrics:${lineAccountId}:*`,
    ];

    for (const pattern of patterns) {
      await this.cacheService.invalidatePattern(pattern);
    }

    logger.info('Dashboard cache invalidated', { lineAccountId, eventType });
  }

  /**
   * Get cache statistics for monitoring
   */
  async getCacheStats(): Promise<{
    hitRate: number;
    totalRequests: number;
    cacheSize: number;
    topKeys: Array<{ key: string; hits: number }>;
  }> {
    const stats = this.cacheService.getStats();
    
    return {
      hitRate: stats.hitRate,
      totalRequests: stats.hits + stats.misses,
      cacheSize: stats.sets,
      topKeys: [], // Would need Redis SCAN to implement
    };
  }

  /**
   * Setup automatic cache warming jobs
   */
  private setupCacheWarmingJobs(): void {
    // High priority: Critical dashboard metrics
    this.warmingJobs.set('critical-metrics', {
      name: 'Critical Dashboard Metrics',
      schedule: '*/5 * * * *', // Every 5 minutes
      cacheKeys: ['dashboard:metrics:*', 'dashboard:realtime:*'],
      priority: 'high',
      maxExecutionTime: 30000, // 30 seconds
      enabled: true,
    });

    // Medium priority: Historical reports
    this.warmingJobs.set('historical-reports', {
      name: 'Historical Reports',
      schedule: '0 */6 * * *', // Every 6 hours
      cacheKeys: ['dashboard:historical:*', 'reports:*'],
      priority: 'medium',
      maxExecutionTime: 120000, // 2 minutes
      enabled: true,
    });

    // Low priority: Aggregated analytics
    this.warmingJobs.set('analytics', {
      name: 'Analytics Data',
      schedule: '0 2 * * *', // Daily at 2 AM
      cacheKeys: ['analytics:*', 'aggregated:*'],
      priority: 'low',
      maxExecutionTime: 300000, // 5 minutes
      enabled: true,
    });
  }

  /**
   * Execute cache warming job
   */
  async executeCacheWarmingJob(jobName: string): Promise<void> {
    const job = this.warmingJobs.get(jobName);
    if (!job || !job.enabled) {
      return;
    }

    const startTime = Date.now();
    logger.info('Starting cache warming job', { jobName, priority: job.priority });

    try {
      // Get all active line accounts
      const accounts = await this.getActiveLineAccounts();
      
      // Execute warming with timeout
      await Promise.race([
        this.warmDashboardCache(accounts.map(a => a.id)),
        new Promise((_, reject) => 
          setTimeout(() => reject(new Error('Cache warming timeout')), job.maxExecutionTime)
        ),
      ]);

      const duration = Date.now() - startTime;
      logger.info('Cache warming job completed', { 
        jobName, 
        duration, 
        accountCount: accounts.length 
      });
    } catch (error) {
      logger.error('Cache warming job failed', { 
        jobName, 
        error: String(error) 
      });
    }
  }

  /**
   * Calculate dashboard metrics from database
   */
  private async calculateDashboardMetrics(
    lineAccountId: string,
    dateRange?: { from: Date; to: Date }
  ): Promise<DashboardMetrics> {
    const today = new Date();
    const startOfDay = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const endOfDay = new Date(startOfDay.getTime() + 24 * 60 * 60 * 1000);

    // Use date range if provided, otherwise use today
    const from = dateRange?.from || startOfDay;
    const to = dateRange?.to || endOfDay;

    // Calculate order metrics
    const orderMetrics = await this.calculateOrderMetrics(lineAccountId, from, to);
    
    // Calculate payment metrics
    const paymentMetrics = await this.calculatePaymentMetrics(lineAccountId, from, to);
    
    // Calculate webhook metrics
    const webhookMetrics = await this.calculateWebhookMetrics(lineAccountId, from, to);
    
    // Calculate customer metrics
    const customerMetrics = await this.calculateCustomerMetrics(lineAccountId, from, to);

    return {
      orders: orderMetrics,
      payments: paymentMetrics,
      webhooks: webhookMetrics,
      customers: customerMetrics,
      updatedAt: new Date(),
    };
  }

  private async calculateRealTimeMetrics(lineAccountId: string): Promise<Partial<DashboardMetrics>> {
    // Only calculate frequently changing metrics for real-time updates
    const now = new Date();
    const fiveMinutesAgo = new Date(now.getTime() - 5 * 60 * 1000);

    // Get recent orders count
    const recentOrdersCount = await this.prisma.odooOrders.count({
      where: {
        lineAccountId,
        createdAt: {
          gte: fiveMinutesAgo,
        },
      },
    });

    // Get pending payments count
    const pendingPaymentsCount = await this.prisma.odooSlipUploads.count({
      where: {
        lineAccountId,
        status: 'PENDING',
      },
    });

    return {
      orders: {
        todayCount: recentOrdersCount,
        todayTotal: 0,
        pendingCount: 0,
        completedCount: 0,
        averageOrderValue: 0,
      },
      payments: {
        pendingSlips: pendingPaymentsCount,
        processedToday: 0,
        matchingRate: 0,
        totalAmount: 0,
        averageProcessingTime: 0,
      },
      updatedAt: new Date(),
    };
  }

  private async calculateOrderMetrics(lineAccountId: string, from: Date, to: Date) {
    const orders = await this.prisma.odooOrders.findMany({
      where: {
        lineAccountId,
        orderDate: {
          gte: from,
          lte: to,
        },
      },
    });

    const totalAmount = orders.reduce((sum, order) => sum + Number(order.totalAmount), 0);
    const completedOrders = orders.filter(order => order.status === 'done');
    const pendingOrders = orders.filter(order => ['draft', 'sent'].includes(order.status));

    return {
      todayCount: orders.length,
      todayTotal: totalAmount,
      pendingCount: pendingOrders.length,
      completedCount: completedOrders.length,
      averageOrderValue: orders.length > 0 ? totalAmount / orders.length : 0,
    };
  }

  private async calculatePaymentMetrics(lineAccountId: string, from: Date, to: Date) {
    const payments = await this.prisma.odooSlipUploads.findMany({
      where: {
        lineAccountId,
        createdAt: {
          gte: from,
          lte: to,
        },
      },
    });

    const processedPayments = payments.filter(p => p.status === 'MATCHED');
    const pendingPayments = payments.filter(p => p.status === 'PENDING');
    const totalAmount = processedPayments.reduce((sum, p) => sum + Number(p.amount || 0), 0);

    return {
      pendingSlips: pendingPayments.length,
      processedToday: processedPayments.length,
      matchingRate: payments.length > 0 ? (processedPayments.length / payments.length) * 100 : 0,
      totalAmount,
      averageProcessingTime: 0, // Would need processing time tracking
    };
  }

  private async calculateWebhookMetrics(lineAccountId: string, from: Date, to: Date) {
    const webhooks = await this.prisma.odooWebhooksLog.findMany({
      where: {
        lineAccountId,
        createdAt: {
          gte: from,
          lte: to,
        },
      },
    });

    const successfulWebhooks = webhooks.filter(w => w.status === 'PROCESSED');
    const failedWebhooks = webhooks.filter(w => w.status === 'FAILED');

    return {
      totalToday: webhooks.length,
      successRate: webhooks.length > 0 ? (successfulWebhooks.length / webhooks.length) * 100 : 0,
      failedCount: failedWebhooks.length,
      averageResponseTime: 0, // Would need response time tracking
    };
  }

  private async calculateCustomerMetrics(lineAccountId: string, from: Date, to: Date) {
    // This would need customer data from the existing PHP system
    // For now, return placeholder data
    return {
      totalActive: 0,
      newToday: 0,
      topCustomers: [],
    };
  }

  private buildMetricsCacheKey(lineAccountId: string, dateRange?: { from: Date; to: Date }): string {
    if (dateRange) {
      const fromStr = dateRange.from.toISOString().split('T')[0];
      const toStr = dateRange.to.toISOString().split('T')[0];
      return `metrics:${lineAccountId}:${fromStr}:${toStr}`;
    }
    
    const today = new Date().toISOString().split('T')[0];
    return `metrics:${lineAccountId}:${today}`;
  }

  private getCommonDateRanges(): Array<{ from: Date; to: Date }> {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    
    return [
      // Yesterday
      {
        from: new Date(today.getTime() - 24 * 60 * 60 * 1000),
        to: today,
      },
      // Last 7 days
      {
        from: new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000),
        to: today,
      },
      // Last 30 days
      {
        from: new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000),
        to: today,
      },
    ];
  }

  private async getActiveLineAccounts(): Promise<Array<{ id: string }>> {
    // This would query the line_accounts table
    // For now, return placeholder
    return [{ id: '1' }];
  }
}