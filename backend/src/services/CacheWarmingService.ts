import { CacheService } from './CacheService';
import { DashboardCacheService } from './DashboardCacheService';
import { logger } from '@/utils/logger';
import { config } from '@/config/config';

export interface WarmingStrategy {
  name: string;
  priority: 'critical' | 'high' | 'medium' | 'low';
  schedule: string; // Cron expression
  enabled: boolean;
  maxExecutionTime: number;
  execute: () => Promise<void>;
}

export interface WarmingStats {
  totalJobs: number;
  completedJobs: number;
  failedJobs: number;
  averageExecutionTime: number;
  lastExecutionTime: Date;
  cacheHitRateImprovement: number;
}

export class CacheWarmingService {
  private strategies: Map<string, WarmingStrategy> = new Map();
  private stats: WarmingStats = {
    totalJobs: 0,
    completedJobs: 0,
    failedJobs: 0,
    averageExecutionTime: 0,
    lastExecutionTime: new Date(),
    cacheHitRateImprovement: 0,
  };

  constructor(
    private cacheService: CacheService,
    private dashboardCacheService: DashboardCacheService
  ) {
    this.setupWarmingStrategies();
  }

  /**
   * Register a cache warming strategy
   */
  registerStrategy(name: string, strategy: WarmingStrategy): void {
    this.strategies.set(name, strategy);
    logger.info('Cache warming strategy registered', { name, priority: strategy.priority });
  }

  /**
   * Execute all enabled warming strategies
   */
  async executeAllStrategies(): Promise<void> {
    const enabledStrategies = Array.from(this.strategies.values()).filter(s => s.enabled);
    
    logger.info('Starting cache warming execution', { 
      totalStrategies: enabledStrategies.length 
    });

    const startTime = Date.now();
    let completedCount = 0;
    let failedCount = 0;

    // Execute strategies by priority
    const priorityOrder = ['critical', 'high', 'medium', 'low'];
    
    for (const priority of priorityOrder) {
      const strategiesForPriority = enabledStrategies.filter(s => s.priority === priority);
      
      // Execute critical and high priority strategies sequentially
      if (priority === 'critical' || priority === 'high') {
        for (const strategy of strategiesForPriority) {
          try {
            await this.executeStrategy(strategy);
            completedCount++;
          } catch (error) {
            failedCount++;
            logger.error('Cache warming strategy failed', { 
              strategy: strategy.name, 
              error: String(error) 
            });
          }
        }
      } else {
        // Execute medium and low priority strategies in parallel
        const results = await Promise.allSettled(
          strategiesForPriority.map(strategy => this.executeStrategy(strategy))
        );
        
        results.forEach((result, index) => {
          if (result.status === 'fulfilled') {
            completedCount++;
          } else {
            failedCount++;
            logger.error('Cache warming strategy failed', { 
              strategy: strategiesForPriority[index].name, 
              error: String(result.reason) 
            });
          }
        });
      }
    }

    const totalTime = Date.now() - startTime;
    
    // Update stats
    this.stats.totalJobs += enabledStrategies.length;
    this.stats.completedJobs += completedCount;
    this.stats.failedJobs += failedCount;
    this.stats.averageExecutionTime = 
      (this.stats.averageExecutionTime + totalTime) / 2;
    this.stats.lastExecutionTime = new Date();

    logger.info('Cache warming execution completed', {
      completed: completedCount,
      failed: failedCount,
      totalTime,
    });
  }

  /**
   * Execute a specific warming strategy
   */
  async executeStrategy(strategy: WarmingStrategy): Promise<void> {
    const startTime = Date.now();
    
    logger.info('Executing cache warming strategy', { 
      name: strategy.name, 
      priority: strategy.priority 
    });

    try {
      // Execute with timeout
      await Promise.race([
        strategy.execute(),
        new Promise((_, reject) => 
          setTimeout(
            () => reject(new Error(`Strategy timeout after ${strategy.maxExecutionTime}ms`)), 
            strategy.maxExecutionTime
          )
        ),
      ]);

      const executionTime = Date.now() - startTime;
      logger.info('Cache warming strategy completed', { 
        name: strategy.name, 
        executionTime 
      });
    } catch (error) {
      const executionTime = Date.now() - startTime;
      logger.error('Cache warming strategy failed', { 
        name: strategy.name, 
        executionTime, 
        error: String(error) 
      });
      throw error;
    }
  }

  /**
   * Get warming statistics
   */
  getStats(): WarmingStats {
    return { ...this.stats };
  }

  /**
   * Reset statistics
   */
  resetStats(): void {
    this.stats = {
      totalJobs: 0,
      completedJobs: 0,
      failedJobs: 0,
      averageExecutionTime: 0,
      lastExecutionTime: new Date(),
      cacheHitRateImprovement: 0,
    };
  }

  /**
   * Setup default warming strategies
   */
  private setupWarmingStrategies(): void {
    // Critical: Dashboard overview data
    this.registerStrategy('dashboard-overview', {
      name: 'Dashboard Overview',
      priority: 'critical',
      schedule: '*/2 * * * *', // Every 2 minutes
      enabled: true,
      maxExecutionTime: 30000, // 30 seconds
      execute: async () => {
        const accounts = await this.getActiveAccounts();
        await Promise.all(
          accounts.map(account => 
            this.dashboardCacheService.getDashboardMetrics(account.id)
          )
        );
      },
    });

    // High: Real-time metrics
    this.registerStrategy('realtime-metrics', {
      name: 'Real-time Metrics',
      priority: 'high',
      schedule: '*/1 * * * *', // Every minute
      enabled: true,
      maxExecutionTime: 15000, // 15 seconds
      execute: async () => {
        const accounts = await this.getActiveAccounts();
        await Promise.all(
          accounts.map(account => 
            this.dashboardCacheService.getRealTimeMetrics(account.id)
          )
        );
      },
    });

    // Medium: Historical data for common date ranges
    this.registerStrategy('historical-data', {
      name: 'Historical Data',
      priority: 'medium',
      schedule: '0 */6 * * *', // Every 6 hours
      enabled: true,
      maxExecutionTime: 120000, // 2 minutes
      execute: async () => {
        const accounts = await this.getActiveAccounts();
        const dateRanges = this.getCommonDateRanges();
        
        for (const account of accounts) {
          await Promise.all(
            dateRanges.map(range => 
              this.dashboardCacheService.getDashboardMetrics(account.id, range)
            )
          );
        }
      },
    });

    // Low: API endpoint responses
    this.registerStrategy('api-responses', {
      name: 'API Responses',
      priority: 'low',
      schedule: '0 2 * * *', // Daily at 2 AM
      enabled: true,
      maxExecutionTime: 300000, // 5 minutes
      execute: async () => {
        await this.warmCommonAPIResponses();
      },
    });

    // Low: Static content and configurations
    this.registerStrategy('static-content', {
      name: 'Static Content',
      priority: 'low',
      schedule: '0 3 * * *', // Daily at 3 AM
      enabled: true,
      maxExecutionTime: 60000, // 1 minute
      execute: async () => {
        await this.warmStaticContent();
      },
    });
  }

  /**
   * Warm common API responses
   */
  private async warmCommonAPIResponses(): Promise<void> {
    const commonEndpoints = [
      '/api/v1/dashboard/overview',
      '/api/v1/orders?limit=20',
      '/api/v1/payments/slips?status=pending',
      '/api/v1/webhooks/stats',
    ];

    const accounts = await this.getActiveAccounts();
    
    for (const account of accounts) {
      for (const endpoint of commonEndpoints) {
        const cacheKey = `api:${endpoint}:${account.id}`;
        
        // Check if already cached
        const exists = await this.cacheService.exists(cacheKey);
        if (!exists) {
          // In a real implementation, this would make the actual API call
          // For now, we'll just mark it as warmed
          await this.cacheService.set(
            cacheKey, 
            { warmed: true, timestamp: new Date() },
            { ttl: 900 } // 15 minutes
          );
        }
      }
    }
  }

  /**
   * Warm static content like configurations and lookup data
   */
  private async warmStaticContent(): Promise<void> {
    const staticKeys = [
      'config:app-settings',
      'config:feature-flags',
      'lookup:order-statuses',
      'lookup:payment-methods',
      'lookup:currencies',
    ];

    for (const key of staticKeys) {
      const exists = await this.cacheService.exists(key);
      if (!exists) {
        // In a real implementation, this would load the actual data
        await this.cacheService.set(
          key,
          { warmed: true, timestamp: new Date() },
          { ttl: 86400 } // 24 hours
        );
      }
    }
  }

  /**
   * Get active LINE accounts
   */
  private async getActiveAccounts(): Promise<Array<{ id: string; name: string }>> {
    // In a real implementation, this would query the database
    // For now, return mock data
    return [
      { id: '1', name: 'Main Account' },
      { id: '2', name: 'Test Account' },
    ];
  }

  /**
   * Get common date ranges for historical data
   */
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
        to: now,
      },
      // Last 30 days
      {
        from: new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000),
        to: now,
      },
      // This month
      {
        from: new Date(now.getFullYear(), now.getMonth(), 1),
        to: now,
      },
    ];
  }
}