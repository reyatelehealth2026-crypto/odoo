import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { CacheService } from '@/services/CacheService';
import { DatabasePoolService } from '@/services/DatabasePoolService';
import { logger } from '@/utils/logger';

interface PerformanceMetric {
  name: string;
  value: number;
  timestamp: number;
  url?: string;
  userAgent?: string;
}

interface PerformanceData {
  metrics: PerformanceMetric[];
  session: string;
  timestamp: number;
}

export async function performanceRoutes(fastify: FastifyInstance) {
  const cacheService = new CacheService(fastify);
  const dbPool = new DatabasePoolService();

  // Record frontend performance metrics
  fastify.post<{ Body: PerformanceData }>('/analytics/performance', async (request, reply) => {
    try {
      const { metrics, session, timestamp } = request.body;
      
      // Validate metrics
      if (!Array.isArray(metrics) || metrics.length === 0) {
        return reply.status(400).send({
          success: false,
          error: { code: 'INVALID_METRICS', message: 'Invalid metrics data' },
        });
      }

      // Store metrics in cache for real-time monitoring
      const cacheKey = `performance:session:${session}`;
      await cacheService.set(cacheKey, { metrics, timestamp }, { ttl: 3600 }); // 1 hour

      // Aggregate metrics for monitoring
      await aggregatePerformanceMetrics(metrics, session);

      // Log slow performance
      const slowMetrics = metrics.filter(m => 
        (m.name.includes('web_vital') && m.value > getPerformanceThreshold(m.name)) ||
        (m.name === 'api_call' && m.value > 1000) ||
        (m.name === 'component_render' && m.value > 100)
      );

      if (slowMetrics.length > 0) {
        logger.warn('Slow frontend performance detected', {
          session,
          slowMetrics: slowMetrics.length,
          metrics: slowMetrics,
        });
      }

      reply.send({ success: true });
    } catch (error) {
      logger.error('Failed to record performance metrics', { error: String(error) });
      reply.status(500).send({
        success: false,
        error: { code: 'INTERNAL_ERROR', message: 'Failed to record metrics' },
      });
    }
  });

  // Get performance statistics
  fastify.get('/analytics/performance/stats', async (request, reply) => {
    try {
      const stats = await getPerformanceStats(cacheService, dbPool);
      reply.send({ success: true, data: stats });
    } catch (error) {
      logger.error('Failed to get performance stats', { error: String(error) });
      reply.status(500).send({
        success: false,
        error: { code: 'INTERNAL_ERROR', message: 'Failed to get stats' },
      });
    }
  });

  // Get cache statistics
  fastify.get('/analytics/cache/stats', async (request, reply) => {
    try {
      const cacheStats = cacheService.getStats();
      reply.send({ success: true, data: cacheStats });
    } catch (error) {
      logger.error('Failed to get cache stats', { error: String(error) });
      reply.status(500).send({
        success: false,
        error: { code: 'INTERNAL_ERROR', message: 'Failed to get cache stats' },
      });
    }
  });

  // Get database performance statistics
  fastify.get('/analytics/database/stats', async (request, reply) => {
    try {
      const dbStats = dbPool.getPoolStats();
      const slowQueries = dbPool.getSlowQueries(1000); // Queries > 1 second
      
      reply.send({ 
        success: true, 
        data: {
          pool: dbStats,
          slowQueries: slowQueries.slice(0, 10), // Top 10 slow queries
        },
      });
    } catch (error) {
      logger.error('Failed to get database stats', { error: String(error) });
      reply.status(500).send({
        success: false,
        error: { code: 'INTERNAL_ERROR', message: 'Failed to get database stats' },
      });
    }
  });

  // Health check endpoint with performance metrics
  fastify.get('/health/performance', async (request, reply) => {
    try {
      const [cacheHealth, dbHealth] = await Promise.all([
        checkCacheHealth(cacheService),
        dbPool.healthCheck(),
      ]);

      const overallHealth = cacheHealth.status === 'healthy' && dbHealth.status === 'healthy' 
        ? 'healthy' : 'unhealthy';

      reply.send({
        success: true,
        data: {
          status: overallHealth,
          cache: cacheHealth,
          database: dbHealth,
          timestamp: new Date().toISOString(),
        },
      });
    } catch (error) {
      logger.error('Performance health check failed', { error: String(error) });
      reply.status(500).send({
        success: false,
        error: { code: 'HEALTH_CHECK_FAILED', message: 'Health check failed' },
      });
    }
  });
}

/**
 * Aggregate performance metrics for monitoring
 */
async function aggregatePerformanceMetrics(metrics: PerformanceMetric[], session: string): Promise<void> {
  // Group metrics by type
  const metricGroups = metrics.reduce((groups, metric) => {
    if (!groups[metric.name]) {
      groups[metric.name] = [];
    }
    groups[metric.name].push(metric.value);
    return groups;
  }, {} as Record<string, number[]>);

  // Calculate aggregates
  const aggregates = Object.entries(metricGroups).map(([name, values]) => ({
    name,
    count: values.length,
    avg: values.reduce((sum, val) => sum + val, 0) / values.length,
    min: Math.min(...values),
    max: Math.max(...values),
    p95: calculatePercentile(values, 95),
  }));

  // Store aggregates (in a real implementation, this would go to a time-series database)
  logger.info('Performance metrics aggregated', {
    session,
    aggregates,
  });
}

/**
 * Get performance thresholds for different metrics
 */
function getPerformanceThreshold(metricName: string): number {
  const thresholds: Record<string, number> = {
    'web_vital_cls': 0.25,
    'web_vital_fid': 300,
    'web_vital_fcp': 3000,
    'web_vital_lcp': 4000,
    'web_vital_ttfb': 800,
  };

  return thresholds[metricName] || Infinity;
}

/**
 * Calculate percentile from array of values
 */
function calculatePercentile(values: number[], percentile: number): number {
  const sorted = values.sort((a, b) => a - b);
  const index = Math.ceil((percentile / 100) * sorted.length) - 1;
  return sorted[index] || 0;
}

/**
 * Get comprehensive performance statistics
 */
async function getPerformanceStats(cacheService: CacheService, dbPool: DatabasePoolService) {
  const cacheStats = cacheService.getStats();
  const dbStats = dbPool.getPoolStats();
  const slowQueries = dbPool.getSlowQueries(1000);

  return {
    cache: {
      hitRate: cacheStats.hitRate,
      totalRequests: cacheStats.hits + cacheStats.misses,
      performance: cacheStats.hitRate >= 85 ? 'good' : 'poor',
    },
    database: {
      activeConnections: dbStats.activeConnections,
      totalConnections: dbStats.totalConnections,
      utilization: (dbStats.activeConnections / dbStats.totalConnections) * 100,
      averageQueryTime: dbStats.averageQueryTime,
      slowQueries: slowQueries.length,
      performance: dbStats.averageQueryTime < 300 ? 'good' : 'poor',
    },
    overall: {
      status: cacheStats.hitRate >= 85 && dbStats.averageQueryTime < 300 ? 'good' : 'poor',
      timestamp: new Date().toISOString(),
    },
  };
}

/**
 * Check cache health
 */
async function checkCacheHealth(cacheService: CacheService): Promise<{ status: 'healthy' | 'unhealthy'; details: any }> {
  try {
    const testKey = 'health_check_' + Date.now();
    const testValue = { test: true, timestamp: Date.now() };
    
    // Test cache write
    await cacheService.set(testKey, testValue, { ttl: 10 });
    
    // Test cache read
    const retrieved = await cacheService.get(testKey);
    
    // Test cache delete
    await cacheService.delete(testKey);
    
    const stats = cacheService.getStats();
    
    return {
      status: 'healthy',
      details: {
        hitRate: stats.hitRate,
        totalRequests: stats.hits + stats.misses,
        performance: stats.hitRate >= 85 ? 'good' : 'poor',
      },
    };
  } catch (error) {
    return {
      status: 'unhealthy',
      details: {
        error: String(error),
      },
    };
  }
}