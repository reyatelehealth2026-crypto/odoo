/**
 * Graceful Degradation Service
 * Implements BR-2.2, NFR-2.2 requirements for graceful degradation strategies
 */

import { APIResponse } from '../types/errors.js';
import { CacheService } from './CacheService.js';
import { LoggingService } from './LoggingService.js';

interface DegradationStrategy {
  name: string;
  priority: number;
  condition: (error: Error) => boolean;
  fallback: (context: any) => Promise<any>;
}

interface ServiceHealth {
  service: string;
  healthy: boolean;
  lastCheck: Date;
  errorCount: number;
  degradationLevel: 'none' | 'partial' | 'full';
}

export class GracefulDegradationService {
  private cacheService: CacheService;
  private loggingService: LoggingService;
  private strategies: Map<string, DegradationStrategy> = new Map();
  private serviceHealth: Map<string, ServiceHealth> = new Map();
  private fallbackData: Map<string, any> = new Map();

  constructor(cacheService: CacheService, loggingService: LoggingService) {
    this.cacheService = cacheService;
    this.loggingService = loggingService;
    this.initializeStrategies();
    this.initializeFallbackData();
  }

  /**
   * Initialize degradation strategies
   */
  private initializeStrategies(): void {
    // Database degradation strategy
    this.strategies.set('database', {
      name: 'Database Fallback',
      priority: 1,
      condition: (error) => error.message.includes('database') || error.message.includes('connection'),
      fallback: async (context) => {
        // Try to get data from cache first
        const cacheKey = `fallback:${context.endpoint}:${JSON.stringify(context.params)}`;
        const cachedData = await this.cacheService.get(cacheKey);
        
        if (cachedData) {
          await this.loggingService.logEvent('warn', 'Using cached data due to database error', {
            endpoint: context.endpoint,
            cacheKey
          });
          return cachedData;
        }

        // Return static fallback data
        return this.getStaticFallback(context.endpoint);
      }
    });

    // External service degradation strategy
    this.strategies.set('external_service', {
      name: 'External Service Fallback',
      priority: 2,
      condition: (error) => error.message.includes('external') || error.message.includes('timeout'),
      fallback: async (context) => {
        // Use cached data from previous successful calls
        const cacheKey = `service:${context.service}:${JSON.stringify(context.params)}`;
        const cachedData = await this.cacheService.get(cacheKey);
        
        if (cachedData) {
          return {
            ...cachedData,
            _degraded: true,
            _degradationReason: 'External service unavailable, using cached data'
          };
        }

        // Return minimal functionality
        return this.getMinimalFunctionality(context.service);
      }
    });

    // Cache degradation strategy
    this.strategies.set('cache', {
      name: 'Cache Fallback',
      priority: 3,
      condition: (error) => error.message.includes('cache') || error.message.includes('redis'),
      fallback: async (context) => {
        // Proceed without caching
        await this.loggingService.logEvent('warn', 'Cache unavailable, proceeding without caching', {
          endpoint: context.endpoint
        });
        
        return {
          _degraded: true,
          _degradationReason: 'Cache service unavailable',
          _cacheDisabled: true
        };
      }
    });

    // Real-time updates degradation strategy
    this.strategies.set('realtime', {
      name: 'Real-time Fallback',
      priority: 4,
      condition: (error) => error.message.includes('websocket') || error.message.includes('socket'),
      fallback: async (context) => {
        // Fall back to polling-based updates
        return {
          _degraded: true,
          _degradationReason: 'Real-time updates unavailable, using polling',
          _pollingInterval: 30000 // 30 seconds
        };
      }
    });
  }

  /**
   * Initialize static fallback data
   */
  private initializeFallbackData(): void {
    // Dashboard fallback data
    this.fallbackData.set('/api/v1/dashboard/overview', {
      orders: {
        todayCount: 0,
        todayTotal: 0,
        pendingCount: 0,
        completedCount: 0,
        averageOrderValue: 0
      },
      payments: {
        pendingSlips: 0,
        processedToday: 0,
        matchingRate: 0,
        totalAmount: 0
      },
      webhooks: {
        successRate: 0,
        totalEvents: 0,
        failedEvents: 0
      },
      _degraded: true,
      _degradationReason: 'Service unavailable, showing default values'
    });

    // Orders fallback data
    this.fallbackData.set('/api/v1/orders', {
      data: [],
      total: 0,
      page: 1,
      totalPages: 0,
      _degraded: true,
      _degradationReason: 'Order service unavailable'
    });

    // Payments fallback data
    this.fallbackData.set('/api/v1/payments/slips', {
      data: [],
      total: 0,
      page: 1,
      totalPages: 0,
      _degraded: true,
      _degradationReason: 'Payment service unavailable'
    });
  }

  /**
   * Apply graceful degradation for a failed operation
   */
  async applyDegradation(
    error: Error,
    context: {
      endpoint: string;
      service?: string;
      params?: any;
      requestId: string;
    }
  ): Promise<APIResponse> {
    // Find applicable degradation strategy
    const strategy = this.findApplicableStrategy(error);
    
    if (!strategy) {
      // No specific strategy found, return generic fallback
      return this.createGenericFallback(error, context);
    }

    try {
      // Apply the degradation strategy
      const fallbackData = await strategy.fallback(context);
      
      // Log degradation event
      await this.loggingService.logEvent('warn', `Applied degradation strategy: ${strategy.name}`, {
        error: error.message,
        strategy: strategy.name,
        endpoint: context.endpoint,
        requestId: context.requestId
      });

      // Update service health
      this.updateServiceHealth(context.service || 'unknown', false);

      return {
        success: true,
        data: fallbackData,
        meta: {
          requestId: context.requestId,
          processingTime: 0,
          degraded: true,
          degradationReason: `${strategy.name}: ${error.message}`,
          degradationStrategy: strategy.name
        }
      };

    } catch (degradationError) {
      // Degradation strategy failed, return generic fallback
      await this.loggingService.logEvent('error', 'Degradation strategy failed', {
        originalError: error.message,
        degradationError: degradationError.message,
        strategy: strategy.name,
        requestId: context.requestId
      });

      return this.createGenericFallback(error, context);
    }
  }

  /**
   * Find applicable degradation strategy for the error
   */
  private findApplicableStrategy(error: Error): DegradationStrategy | null {
    const applicableStrategies = Array.from(this.strategies.values())
      .filter(strategy => strategy.condition(error))
      .sort((a, b) => a.priority - b.priority);

    return applicableStrategies[0] || null;
  }

  /**
   * Create generic fallback response
   */
  private createGenericFallback(error: Error, context: any): APIResponse {
    const fallbackData = this.getStaticFallback(context.endpoint);

    return {
      success: true,
      data: fallbackData,
      meta: {
        requestId: context.requestId,
        processingTime: 0,
        degraded: true,
        degradationReason: `Service temporarily unavailable: ${error.message}`,
        degradationStrategy: 'generic'
      }
    };
  }

  /**
   * Get static fallback data for endpoint
   */
  private getStaticFallback(endpoint: string): any {
    return this.fallbackData.get(endpoint) || {
      _degraded: true,
      _degradationReason: 'Service temporarily unavailable',
      _message: 'Please try again later'
    };
  }

  /**
   * Get minimal functionality for service
   */
  private getMinimalFunctionality(service: string): any {
    switch (service) {
      case 'odoo':
        return {
          orders: [],
          customers: [],
          _degraded: true,
          _degradationReason: 'Odoo ERP service unavailable'
        };

      case 'line':
        return {
          messaging: false,
          notifications: false,
          _degraded: true,
          _degradationReason: 'LINE API service unavailable'
        };

      case 'payment':
        return {
          processing: false,
          matching: false,
          _degraded: true,
          _degradationReason: 'Payment processing service unavailable'
        };

      default:
        return {
          _degraded: true,
          _degradationReason: `${service} service unavailable`
        };
    }
  }

  /**
   * Update service health status
   */
  private updateServiceHealth(service: string, healthy: boolean): void {
    const currentHealth = this.serviceHealth.get(service) || {
      service,
      healthy: true,
      lastCheck: new Date(),
      errorCount: 0,
      degradationLevel: 'none' as const
    };

    currentHealth.healthy = healthy;
    currentHealth.lastCheck = new Date();

    if (!healthy) {
      currentHealth.errorCount++;
    } else {
      currentHealth.errorCount = Math.max(0, currentHealth.errorCount - 1);
    }

    // Determine degradation level
    if (currentHealth.errorCount >= 10) {
      currentHealth.degradationLevel = 'full';
    } else if (currentHealth.errorCount >= 5) {
      currentHealth.degradationLevel = 'partial';
    } else {
      currentHealth.degradationLevel = 'none';
    }

    this.serviceHealth.set(service, currentHealth);
  }

  /**
   * Get service health status
   */
  getServiceHealth(): Record<string, ServiceHealth> {
    const health: Record<string, ServiceHealth> = {};
    
    for (const [service, status] of this.serviceHealth.entries()) {
      health[service] = { ...status };
    }

    return health;
  }

  /**
   * Check if service is degraded
   */
  isServiceDegraded(service: string): boolean {
    const health = this.serviceHealth.get(service);
    return health ? health.degradationLevel !== 'none' : false;
  }

  /**
   * Get degradation level for service
   */
  getDegradationLevel(service: string): 'none' | 'partial' | 'full' {
    const health = this.serviceHealth.get(service);
    return health ? health.degradationLevel : 'none';
  }

  /**
   * Reset service health (for recovery)
   */
  resetServiceHealth(service: string): void {
    const health = this.serviceHealth.get(service);
    if (health) {
      health.healthy = true;
      health.errorCount = 0;
      health.degradationLevel = 'none';
      health.lastCheck = new Date();
    }
  }

  /**
   * Get degradation statistics
   */
  getDegradationStatistics(): {
    totalServices: number;
    healthyServices: number;
    degradedServices: number;
    criticalServices: number;
    degradationStrategies: string[];
  } {
    const services = Array.from(this.serviceHealth.values());
    
    return {
      totalServices: services.length,
      healthyServices: services.filter(s => s.degradationLevel === 'none').length,
      degradedServices: services.filter(s => s.degradationLevel === 'partial').length,
      criticalServices: services.filter(s => s.degradationLevel === 'full').length,
      degradationStrategies: Array.from(this.strategies.keys())
    };
  }
}