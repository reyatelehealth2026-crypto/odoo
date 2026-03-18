import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { z } from 'zod';
import { SecurityMonitoringService } from '@/services/SecurityMonitoringService';
import { AuditService } from '@/services/AuditService';
import { authenticate } from '@/middleware/auth';
import { requirePermission } from '@/middleware/rbac';
import { validateQuery, validateRequest } from '@/middleware/validation';
import { createRateLimitMiddleware } from '@/middleware/rateLimiting';
import { logger } from '@/utils/logger';

/**
 * Security Monitoring API Routes
 * 
 * Provides security monitoring, threat detection, and incident response
 * Requirements: NFR-3.3
 */

// Query schemas
const securityMetricsSchema = z.object({
  days: z.number().int().min(1).max(90).default(7),
});

const acknowledgeAlertSchema = z.object({
  alertId: z.string().uuid(),
  notes: z.string().max(500).optional(),
});

const blockIPSchema = z.object({
  ip: z.string().ip(),
  durationMinutes: z.number().int().min(1).max(1440).default(30), // Max 24 hours
  reason: z.string().min(1).max(200),
});

const unblockIPSchema = z.object({
  ip: z.string().ip(),
  reason: z.string().min(1).max(200),
});

export default async function securityRoutes(fastify: FastifyInstance) {
  const auditService = new AuditService(fastify.prisma);
  const securityService = new SecurityMonitoringService(fastify.prisma, auditService, fastify.redis);
  const rateLimiter = createRateLimitMiddleware(fastify.redis);

  // Apply authentication to all routes
  fastify.addHook('preHandler', authenticate);

  /**
   * GET /security/metrics - Get security metrics and statistics
   */
  fastify.get('/metrics', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['view_security_metrics', 'admin_access']),
      validateQuery(securityMetricsSchema),
    ],
    schema: {
      description: 'Get security metrics and threat statistics',
      tags: ['Security'],
      querystring: {
        type: 'object',
        properties: {
          days: { type: 'integer', minimum: 1, maximum: 90, default: 7 },
        },
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                totalThreats: { type: 'integer' },
                activeThreats: { type: 'integer' },
                blockedIPs: { type: 'integer' },
                failedLogins: { type: 'integer' },
                suspiciousRequests: { type: 'integer' },
                timeRange: {
                  type: 'object',
                  properties: {
                    from: { type: 'string', format: 'date-time' },
                    to: { type: 'string', format: 'date-time' },
                  },
                },
                threatsByType: { type: 'array' },
                threatsBySeverity: { type: 'array' },
                topAttackerIPs: { type: 'array' },
              },
            },
          },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { days = 7 } = request.query as any;
      const user = (request as any).user;

      const metrics = await securityService.getSecurityMetrics(days);

      // Log metrics access
      await auditService.logAction({
        userId: user.userId,
        action: 'security_metrics_viewed',
        resourceType: 'security',
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          days,
          totalThreats: metrics.totalThreats,
          activeThreats: metrics.activeThreats,
        },
      });

      return reply.send({
        success: true,
        data: metrics,
      });
    } catch (error) {
      logger.error('Failed to get security metrics', {
        error: String(error),
        query: request.query,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'SECURITY_METRICS_FAILED',
          message: 'Failed to retrieve security metrics',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /security/alerts - Get active security alerts
   */
  fastify.get('/alerts', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['view_security_alerts', 'admin_access']),
    ],
    schema: {
      description: 'Get active security alerts',
      tags: ['Security'],
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: { type: 'array' },
            meta: {
              type: 'object',
              properties: {
                count: { type: 'integer' },
                criticalCount: { type: 'integer' },
                highCount: { type: 'integer' },
              },
            },
          },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const user = (request as any).user;
      const alerts = await securityService.getActiveAlerts();

      // Calculate alert counts by severity
      const criticalCount = alerts.filter(a => a.severity === 'critical').length;
      const highCount = alerts.filter(a => a.severity === 'high').length;

      // Log alerts access
      await auditService.logAction({
        userId: user.userId,
        action: 'security_alerts_viewed',
        resourceType: 'security',
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          alertCount: alerts.length,
          criticalCount,
          highCount,
        },
      });

      return reply.send({
        success: true,
        data: alerts,
        meta: {
          count: alerts.length,
          criticalCount,
          highCount,
        },
      });
    } catch (error) {
      logger.error('Failed to get security alerts', {
        error: String(error),
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'SECURITY_ALERTS_FAILED',
          message: 'Failed to retrieve security alerts',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * PUT /security/alerts/acknowledge - Acknowledge security alert
   */
  fastify.put('/alerts/acknowledge', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['acknowledge_security_alerts', 'admin_access']),
      validateRequest(acknowledgeAlertSchema),
    ],
    schema: {
      description: 'Acknowledge a security alert',
      tags: ['Security'],
      body: {
        type: 'object',
        required: ['alertId'],
        properties: {
          alertId: { type: 'string', format: 'uuid' },
          notes: { type: 'string', maxLength: 500 },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { alertId, notes } = request.body as any;
      const user = (request as any).user;

      const success = await securityService.acknowledgeAlert(alertId, user.userId);

      if (!success) {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'ALERT_NOT_FOUND',
            message: 'Security alert not found',
            timestamp: new Date().toISOString(),
          },
        });
      }

      // Log alert acknowledgment
      await auditService.logAction({
        userId: user.userId,
        action: 'security_alert_acknowledged',
        resourceType: 'security',
        resourceId: alertId,
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          alertId,
          notes,
        },
      });

      return reply.send({
        success: true,
        data: {
          alertId,
          acknowledgedBy: user.userId,
          acknowledgedAt: new Date().toISOString(),
          message: 'Security alert acknowledged successfully',
        },
      });
    } catch (error) {
      logger.error('Failed to acknowledge security alert', {
        error: String(error),
        body: request.body,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'ALERT_ACKNOWLEDGE_FAILED',
          message: 'Failed to acknowledge security alert',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * POST /security/block-ip - Manually block an IP address
   */
  fastify.post('/block-ip', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['block_ip_addresses', 'admin_access']),
      validateRequest(blockIPSchema),
    ],
    schema: {
      description: 'Manually block an IP address',
      tags: ['Security'],
      body: {
        type: 'object',
        required: ['ip', 'reason'],
        properties: {
          ip: { type: 'string', format: 'ipv4' },
          durationMinutes: { type: 'integer', minimum: 1, maximum: 1440, default: 30 },
          reason: { type: 'string', minLength: 1, maxLength: 200 },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { ip, durationMinutes = 30, reason } = request.body as any;
      const user = (request as any).user;

      // Check if IP is whitelisted (prevent blocking critical IPs)
      const whitelistedIPs = ['127.0.0.1', '::1'];
      if (whitelistedIPs.includes(ip)) {
        return reply.status(400).send({
          success: false,
          error: {
            code: 'IP_WHITELISTED',
            message: 'Cannot block whitelisted IP address',
            timestamp: new Date().toISOString(),
          },
        });
      }

      const durationMs = durationMinutes * 60 * 1000;
      const key = `blocked:${ip}`;
      const expirationSeconds = Math.ceil(durationMs / 1000);
      const blockData = {
        reason,
        blockedBy: user.userId,
        blockedAt: new Date().toISOString(),
        expiresAt: new Date(Date.now() + durationMs).toISOString(),
        manual: true,
      };

      await fastify.redis.setex(key, expirationSeconds, JSON.stringify(blockData));

      // Log IP blocking
      await auditService.logAction({
        userId: user.userId,
        action: 'ip_blocked_manually',
        resourceType: 'security',
        resourceId: ip,
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          blockedIP: ip,
          durationMinutes,
          reason,
          expiresAt: blockData.expiresAt,
        },
      });

      // Create security event
      await auditService.logSecurityEvent({
        eventType: 'ip_blocked_manually',
        severity: 'medium',
        userId: user.userId,
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        details: {
          blockedIP: ip,
          reason,
          durationMinutes,
          blockedBy: user.userId,
        },
      });

      logger.warn('IP blocked manually', {
        ip,
        reason,
        durationMinutes,
        blockedBy: user.userId,
        expiresAt: blockData.expiresAt,
      });

      return reply.send({
        success: true,
        data: {
          ip,
          blocked: true,
          reason,
          durationMinutes,
          expiresAt: blockData.expiresAt,
          blockedBy: user.userId,
          message: `IP ${ip} blocked for ${durationMinutes} minutes`,
        },
      });
    } catch (error) {
      logger.error('Failed to block IP address', {
        error: String(error),
        body: request.body,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'IP_BLOCK_FAILED',
          message: 'Failed to block IP address',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * DELETE /security/unblock-ip - Unblock an IP address
   */
  fastify.delete('/unblock-ip', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['unblock_ip_addresses', 'admin_access']),
      validateRequest(unblockIPSchema),
    ],
    schema: {
      description: 'Unblock an IP address',
      tags: ['Security'],
      body: {
        type: 'object',
        required: ['ip', 'reason'],
        properties: {
          ip: { type: 'string', format: 'ipv4' },
          reason: { type: 'string', minLength: 1, maxLength: 200 },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { ip, reason } = request.body as any;
      const user = (request as any).user;

      // Check if IP is actually blocked
      const blockData = await fastify.redis.get(`blocked:${ip}`);
      if (!blockData) {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'IP_NOT_BLOCKED',
            message: 'IP address is not currently blocked',
            timestamp: new Date().toISOString(),
          },
        });
      }

      // Remove block
      await fastify.redis.del(`blocked:${ip}`);

      // Log IP unblocking
      await auditService.logAction({
        userId: user.userId,
        action: 'ip_unblocked_manually',
        resourceType: 'security',
        resourceId: ip,
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          unblockedIP: ip,
          reason,
          previousBlockData: JSON.parse(blockData),
        },
      });

      // Create security event
      await auditService.logSecurityEvent({
        eventType: 'ip_unblocked_manually',
        severity: 'low',
        userId: user.userId,
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        details: {
          unblockedIP: ip,
          reason,
          unblockedBy: user.userId,
        },
      });

      logger.info('IP unblocked manually', {
        ip,
        reason,
        unblockedBy: user.userId,
      });

      return reply.send({
        success: true,
        data: {
          ip,
          unblocked: true,
          reason,
          unblockedBy: user.userId,
          unblockedAt: new Date().toISOString(),
          message: `IP ${ip} unblocked successfully`,
        },
      });
    } catch (error) {
      logger.error('Failed to unblock IP address', {
        error: String(error),
        body: request.body,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'IP_UNBLOCK_FAILED',
          message: 'Failed to unblock IP address',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /security/blocked-ips - Get list of blocked IP addresses
   */
  fastify.get('/blocked-ips', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['view_blocked_ips', 'admin_access']),
    ],
    schema: {
      description: 'Get list of currently blocked IP addresses',
      tags: ['Security'],
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: { type: 'array' },
            meta: {
              type: 'object',
              properties: {
                count: { type: 'integer' },
                manualBlocks: { type: 'integer' },
                automaticBlocks: { type: 'integer' },
              },
            },
          },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const user = (request as any).user;
      
      // Get all blocked IP keys
      const blockedKeys = await fastify.redis.keys('blocked:*');
      const blockedIPs = [];

      for (const key of blockedKeys) {
        const ip = key.replace('blocked:', '');
        const blockData = await fastify.redis.get(key);
        const ttl = await fastify.redis.ttl(key);
        
        if (blockData) {
          const data = JSON.parse(blockData);
          blockedIPs.push({
            ip,
            ...data,
            remainingSeconds: ttl > 0 ? ttl : 0,
          });
        }
      }

      // Sort by blocked date (most recent first)
      blockedIPs.sort((a, b) => new Date(b.blockedAt).getTime() - new Date(a.blockedAt).getTime());

      const manualBlocks = blockedIPs.filter(block => block.manual).length;
      const automaticBlocks = blockedIPs.length - manualBlocks;

      // Log blocked IPs access
      await auditService.logAction({
        userId: user.userId,
        action: 'blocked_ips_viewed',
        resourceType: 'security',
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          totalBlocked: blockedIPs.length,
          manualBlocks,
          automaticBlocks,
        },
      });

      return reply.send({
        success: true,
        data: blockedIPs,
        meta: {
          count: blockedIPs.length,
          manualBlocks,
          automaticBlocks,
        },
      });
    } catch (error) {
      logger.error('Failed to get blocked IPs', {
        error: String(error),
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'BLOCKED_IPS_FAILED',
          message: 'Failed to retrieve blocked IP addresses',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /security/rate-limit-stats - Get rate limiting statistics
   */
  fastify.get('/rate-limit-stats', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['view_rate_limit_stats', 'admin_access']),
    ],
    schema: {
      description: 'Get rate limiting statistics',
      tags: ['Security'],
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const user = (request as any).user;
      
      // Get rate limit statistics from the rate limiting service
      const stats = await rateLimiter.service.getStatistics();

      // Log stats access
      await auditService.logAction({
        userId: user.userId,
        action: 'rate_limit_stats_viewed',
        resourceType: 'security',
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: stats,
      });

      return reply.send({
        success: true,
        data: stats,
      });
    } catch (error) {
      logger.error('Failed to get rate limit statistics', {
        error: String(error),
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'RATE_LIMIT_STATS_FAILED',
          message: 'Failed to retrieve rate limiting statistics',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });
}