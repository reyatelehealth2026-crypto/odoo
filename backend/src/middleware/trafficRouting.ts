/**
 * Traffic Routing Middleware for Legacy/Modern System Migration
 * Purpose: Route requests between legacy PHP system and new Node.js system
 * Requirements: TC-3.1
 */

import { Request, Response, NextFunction } from 'express';
import { createProxyMiddleware, Options } from 'http-proxy-middleware';
import { FeatureFlagService, FeatureFlagConfig } from '../services/FeatureFlagService';
import { Logger } from '../services/LoggingService';
import { Redis } from 'ioredis';

export interface RoutingDecision {
  useNewSystem: boolean;
  reason: string;
  featureFlag?: string;
  abTestVariant?: string;
  routingTimestamp: Date;
}

export interface RoutingConfig {
  legacyBaseUrl: string;
  newSystemBaseUrl: string;
  defaultToLegacy: boolean;
  enableLogging: boolean;
  enableMetrics: boolean;
}

export class TrafficRoutingMiddleware {
  private featureFlagService: FeatureFlagService;
  private logger: Logger;
  private redis: Redis;
  private config: RoutingConfig;
  private legacyProxy: any;
  private routingMetrics: Map<string, number> = new Map();

  constructor(
    featureFlagService: FeatureFlagService,
    logger: Logger,
    redis: Redis,
    config: RoutingConfig
  ) {
    this.featureFlagService = featureFlagService;
    this.logger = logger;
    this.redis = redis;
    this.config = config;

    // Create proxy middleware for legacy system
    this.legacyProxy = createProxyMiddleware({
      target: config.legacyBaseUrl,
      changeOrigin: true,
      pathRewrite: {
        '^/api/v1': '/api' // Rewrite new API paths to legacy paths
      },
      onError: (err, req, res) => {
        this.logger.error('Legacy proxy error', { error: err.message, path: req.url });
        res.status(502).json({
          success: false,
          error: {
            code: 'LEGACY_SYSTEM_ERROR',
            message: 'Legacy system temporarily unavailable'
          }
        });
      },
      onProxyReq: (proxyReq, req) => {
        // Add routing headers for legacy system
        proxyReq.setHeader('X-Routed-From', 'new-system');
        proxyReq.setHeader('X-Routing-Timestamp', new Date().toISOString());
      }
    } as Options);
  }

  /**
   * Main routing middleware
   */
  routeTraffic() {
    return async (req: Request, res: Response, next: NextFunction) => {
      try {
        const decision = await this.makeRoutingDecision(req);
        
        // Add routing information to request
        req.routingDecision = decision;

        // Log routing decision
        if (this.config.enableLogging) {
          this.logRoutingDecision(req, decision);
        }

        // Update metrics
        if (this.config.enableMetrics) {
          await this.updateRoutingMetrics(req.path, decision);
        }

        if (decision.useNewSystem) {
          // Continue to new system
          next();
        } else {
          // Proxy to legacy system
          this.legacyProxy(req, res, next);
        }
      } catch (error) {
        this.logger.error('Traffic routing error', { error, path: req.path });
        
        // Fallback to configured default
        if (this.config.defaultToLegacy) {
          this.legacyProxy(req, res, next);
        } else {
          next();
        }
      }
    };
  }

  /**
   * Make routing decision based on feature flags and request context
   */
  private async makeRoutingDecision(req: Request): Promise<RoutingDecision> {
    const userId = req.user?.userId;
    const userRole = req.user?.role || 'guest';
    const lineAccountId = req.user?.lineAccountId || req.headers['x-line-account-id'] as string;
    const path = req.path;

    // Get feature flags for user
    const featureFlags = userId 
      ? await this.featureFlagService.getFeatureFlags(userId, userRole, lineAccountId)
      : await this.getGuestFeatureFlags();

    // Determine which feature flag applies to this route
    const routeFeatureFlag = this.getRouteFeatureFlag(path);
    
    if (!routeFeatureFlag) {
      return {
        useNewSystem: false,
        reason: 'No feature flag defined for route',
        routingTimestamp: new Date()
      };
    }

    const useNewSystem = featureFlags[routeFeatureFlag];

    // Check for gradual rollout
    if (!useNewSystem && userId) {
      const rolloutDecision = await this.checkGradualRollout(routeFeatureFlag, userId);
      if (rolloutDecision.useNewSystem) {
        return rolloutDecision;
      }
    }

    return {
      useNewSystem,
      reason: useNewSystem ? 'Feature flag enabled' : 'Feature flag disabled',
      featureFlag: routeFeatureFlag,
      routingTimestamp: new Date()
    };
  }

  /**
   * Map request paths to feature flags
   */
  private getRouteFeatureFlag(path: string): keyof FeatureFlagConfig | null {
    const routeMap: Record<string, keyof FeatureFlagConfig> = {
      '/api/v1/dashboard': 'useNewDashboard',
      '/api/v1/orders': 'useNewOrderManagement',
      '/api/v1/payments': 'useNewPaymentProcessing',
      '/api/v1/webhooks': 'useNewWebhookManagement',
      '/api/v1/customers': 'useNewCustomerManagement'
    };

    // Find matching route pattern
    for (const [pattern, flag] of Object.entries(routeMap)) {
      if (path.startsWith(pattern)) {
        return flag;
      }
    }

    return null;
  }

  /**
   * Check gradual rollout percentage
   */
  private async checkGradualRollout(
    featureFlag: keyof FeatureFlagConfig,
    userId: string
  ): Promise<RoutingDecision> {
    try {
      const rolloutPercentage = await this.featureFlagService.getRolloutPercentage(featureFlag);
      
      if (rolloutPercentage === 0) {
        return {
          useNewSystem: false,
          reason: 'Gradual rollout at 0%',
          featureFlag,
          routingTimestamp: new Date()
        };
      }

      if (rolloutPercentage === 100) {
        return {
          useNewSystem: true,
          reason: 'Gradual rollout at 100%',
          featureFlag,
          routingTimestamp: new Date()
        };
      }

      // Use consistent hashing for stable user assignment
      const userHash = this.hashUserId(userId + featureFlag);
      const userPercentile = userHash % 100;

      const useNewSystem = userPercentile < rolloutPercentage;

      return {
        useNewSystem,
        reason: `Gradual rollout ${rolloutPercentage}% - user ${useNewSystem ? 'included' : 'excluded'}`,
        featureFlag,
        routingTimestamp: new Date()
      };
    } catch (error) {
      this.logger.error('Gradual rollout check failed', { featureFlag, userId, error });
      return {
        useNewSystem: false,
        reason: 'Gradual rollout check failed',
        featureFlag,
        routingTimestamp: new Date()
      };
    }
  }

  /**
   * Get feature flags for guest users
   */
  private async getGuestFeatureFlags(): Promise<FeatureFlagConfig> {
    // For guests, use conservative defaults
    return {
      useNewDashboard: false,
      useNewOrderManagement: false,
      useNewPaymentProcessing: false,
      useNewWebhookManagement: false,
      useNewCustomerManagement: false,
      enableRealTimeUpdates: false,
      enablePerformanceOptimizations: false,
      enableAdvancedAuditLogging: false
    };
  }

  /**
   * Hash user ID for consistent assignment
   */
  private hashUserId(input: string): number {
    let hash = 0;
    for (let i = 0; i < input.length; i++) {
      const char = input.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash; // Convert to 32-bit integer
    }
    return Math.abs(hash);
  }

  /**
   * Log routing decision for analytics
   */
  private logRoutingDecision(req: Request, decision: RoutingDecision): void {
    this.logger.info('Traffic routing decision', {
      path: req.path,
      method: req.method,
      userId: req.user?.userId,
      userRole: req.user?.role,
      lineAccountId: req.user?.lineAccountId,
      decision: decision.useNewSystem ? 'new-system' : 'legacy-system',
      reason: decision.reason,
      featureFlag: decision.featureFlag,
      abTestVariant: decision.abTestVariant,
      userAgent: req.get('User-Agent'),
      ip: req.ip,
      timestamp: decision.routingTimestamp
    });
  }

  /**
   * Update routing metrics
   */
  private async updateRoutingMetrics(path: string, decision: RoutingDecision): Promise<void> {
    try {
      const metricsKey = `routing_metrics:${new Date().toISOString().split('T')[0]}`;
      const system = decision.useNewSystem ? 'new' : 'legacy';
      const metricField = `${path}:${system}`;

      await this.redis.hincrby(metricsKey, metricField, 1);
      await this.redis.expire(metricsKey, 86400 * 7); // Keep for 7 days

      // Update in-memory metrics for quick access
      const currentCount = this.routingMetrics.get(metricField) || 0;
      this.routingMetrics.set(metricField, currentCount + 1);
    } catch (error) {
      this.logger.error('Failed to update routing metrics', { path, error });
    }
  }

  /**
   * Get routing metrics for monitoring
   */
  async getRoutingMetrics(date?: string): Promise<Record<string, any>> {
    try {
      const targetDate = date || new Date().toISOString().split('T')[0];
      const metricsKey = `routing_metrics:${targetDate}`;
      const metrics = await this.redis.hgetall(metricsKey);

      const processed: Record<string, any> = {
        date: targetDate,
        totalRequests: 0,
        newSystemRequests: 0,
        legacySystemRequests: 0,
        routeBreakdown: {}
      };

      for (const [key, value] of Object.entries(metrics)) {
        const count = parseInt(value);
        processed.totalRequests += count;

        const [route, system] = key.split(':');
        
        if (system === 'new') {
          processed.newSystemRequests += count;
        } else {
          processed.legacySystemRequests += count;
        }

        if (!processed.routeBreakdown[route]) {
          processed.routeBreakdown[route] = { new: 0, legacy: 0, total: 0 };
        }
        
        processed.routeBreakdown[route][system] = count;
        processed.routeBreakdown[route].total += count;
      }

      // Calculate percentages
      if (processed.totalRequests > 0) {
        processed.newSystemPercentage = Math.round(
          (processed.newSystemRequests / processed.totalRequests) * 100
        );
        processed.legacySystemPercentage = Math.round(
          (processed.legacySystemRequests / processed.totalRequests) * 100
        );
      }

      return processed;
    } catch (error) {
      this.logger.error('Failed to get routing metrics', { date, error });
      return {};
    }
  }

  /**
   * Health check for routing system
   */
  async healthCheck(): Promise<{
    status: 'healthy' | 'degraded' | 'unhealthy';
    checks: Record<string, any>;
  }> {
    const checks: Record<string, any> = {};

    try {
      // Check feature flag service
      checks.featureFlagService = {
        status: 'healthy',
        message: 'Feature flag service accessible'
      };
    } catch (error) {
      checks.featureFlagService = {
        status: 'unhealthy',
        message: 'Feature flag service error',
        error: error.message
      };
    }

    try {
      // Check Redis connectivity
      await this.redis.ping();
      checks.redis = {
        status: 'healthy',
        message: 'Redis connection active'
      };
    } catch (error) {
      checks.redis = {
        status: 'unhealthy',
        message: 'Redis connection failed',
        error: error.message
      };
    }

    try {
      // Check legacy system connectivity
      const response = await fetch(`${this.config.legacyBaseUrl}/api/health`, {
        method: 'GET',
        timeout: 5000
      });
      
      checks.legacySystem = {
        status: response.ok ? 'healthy' : 'degraded',
        message: `Legacy system HTTP ${response.status}`,
        responseTime: Date.now()
      };
    } catch (error) {
      checks.legacySystem = {
        status: 'unhealthy',
        message: 'Legacy system unreachable',
        error: error.message
      };
    }

    // Determine overall status
    const unhealthyCount = Object.values(checks).filter(check => check.status === 'unhealthy').length;
    const degradedCount = Object.values(checks).filter(check => check.status === 'degraded').length;

    let overallStatus: 'healthy' | 'degraded' | 'unhealthy';
    if (unhealthyCount > 0) {
      overallStatus = 'unhealthy';
    } else if (degradedCount > 0) {
      overallStatus = 'degraded';
    } else {
      overallStatus = 'healthy';
    }

    return {
      status: overallStatus,
      checks
    };
  }
}

// Extend Express Request interface
declare global {
  namespace Express {
    interface Request {
      routingDecision?: RoutingDecision;
    }
  }
}