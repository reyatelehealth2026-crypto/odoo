import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { z } from 'zod';
import { AuditService } from '@/services/AuditService';
import { authenticate } from '@/middleware/auth';
import { requirePermission } from '@/middleware/rbac';
import { validateQuery, validateRequest } from '@/middleware/validation';
import { validationSchemas } from '@/middleware/security';
import { createRateLimitMiddleware } from '@/middleware/rateLimiting';
import { logger } from '@/utils/logger';

/**
 * Audit Logging API Routes
 * 
 * Provides comprehensive audit log access and management
 * Requirements: BR-5.4
 */

// Query schemas
const auditQuerySchema = validationSchemas.auditLogQuery;

const securityEventSchema = z.object({
  eventType: z.string().min(1).max(100),
  severity: z.enum(['low', 'medium', 'high', 'critical']),
  details: z.record(z.any()),
  userId: z.string().uuid().optional(),
});

const auditReportSchema = z.object({
  dateFrom: z.string().datetime(),
  dateTo: z.string().datetime(),
  format: z.enum(['json', 'csv']).default('json'),
});

export default async function auditRoutes(fastify: FastifyInstance) {
  const auditService = new AuditService(fastify.prisma);
  const rateLimiter = createRateLimitMiddleware(fastify.redis);

  // Apply authentication to all routes
  fastify.addHook('preHandler', authenticate);

  /**
   * GET /audit/logs - Query audit logs
   */
  fastify.get('/logs', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['view_audit_logs', 'admin_access']),
      validateQuery(auditQuerySchema),
    ],
    schema: {
      description: 'Query audit logs with filters',
      tags: ['Audit'],
      querystring: {
        type: 'object',
        properties: {
          userId: { type: 'string', format: 'uuid' },
          action: { type: 'string' },
          resourceType: { type: 'string' },
          resourceId: { type: 'string', format: 'uuid' },
          dateFrom: { type: 'string', format: 'date-time' },
          dateTo: { type: 'string', format: 'date-time' },
          success: { type: 'boolean' },
          page: { type: 'integer', minimum: 1, default: 1 },
          limit: { type: 'integer', minimum: 1, maximum: 100, default: 50 },
        },
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: { type: 'array' },
            meta: {
              type: 'object',
              properties: {
                total: { type: 'integer' },
                page: { type: 'integer' },
                limit: { type: 'integer' },
                totalPages: { type: 'integer' },
              },
            },
          },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const query = request.query as any;
      const user = (request as any).user;

      // Convert date strings to Date objects
      if (query.dateFrom) query.dateFrom = new Date(query.dateFrom);
      if (query.dateTo) query.dateTo = new Date(query.dateTo);

      // Non-admin users can only see their own audit logs
      if (!user.permissions.includes('admin_access') && !user.permissions.includes('view_all_audit_logs')) {
        query.userId = user.userId;
      }

      const result = await auditService.queryAuditLogs(query);
      const totalPages = Math.ceil(result.total / result.limit);

      // Log audit log access
      await auditService.logAction({
        userId: user.userId,
        action: 'audit_logs_viewed',
        resourceType: 'audit',
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          query: query,
          resultCount: result.data.length,
        },
      });

      return reply.send({
        success: true,
        data: result.data,
        meta: {
          total: result.total,
          page: result.page,
          limit: result.limit,
          totalPages,
        },
      });
    } catch (error) {
      logger.error('Failed to query audit logs', {
        error: String(error),
        query: request.query,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'AUDIT_QUERY_FAILED',
          message: 'Failed to retrieve audit logs',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /audit/trail/:resourceType/:resourceId - Get audit trail for specific resource
   */
  fastify.get('/trail/:resourceType/:resourceId', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['view_audit_logs', 'admin_access']),
    ],
    schema: {
      description: 'Get audit trail for a specific resource',
      tags: ['Audit'],
      params: {
        type: 'object',
        required: ['resourceType', 'resourceId'],
        properties: {
          resourceType: { type: 'string' },
          resourceId: { type: 'string' },
        },
      },
      querystring: {
        type: 'object',
        properties: {
          limit: { type: 'integer', minimum: 1, maximum: 100, default: 50 },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { resourceType, resourceId } = request.params as any;
      const { limit = 50 } = request.query as any;
      const user = (request as any).user;

      const auditTrail = await auditService.getAuditTrail(resourceType, resourceId, limit);

      // Log audit trail access
      await auditService.logAction({
        userId: user.userId,
        action: 'audit_trail_viewed',
        resourceType: 'audit',
        resourceId: `${resourceType}:${resourceId}`,
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          targetResourceType: resourceType,
          targetResourceId: resourceId,
          resultCount: auditTrail.length,
        },
      });

      return reply.send({
        success: true,
        data: auditTrail,
        meta: {
          resourceType,
          resourceId,
          count: auditTrail.length,
        },
      });
    } catch (error) {
      logger.error('Failed to get audit trail', {
        error: String(error),
        params: request.params,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'AUDIT_TRAIL_FAILED',
          message: 'Failed to retrieve audit trail',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * POST /audit/security-event - Log security event
   */
  fastify.post('/security-event', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['log_security_events', 'admin_access']),
      validateRequest(securityEventSchema),
    ],
    schema: {
      description: 'Log a security event',
      tags: ['Audit'],
      body: {
        type: 'object',
        required: ['eventType', 'severity', 'details'],
        properties: {
          eventType: { type: 'string' },
          severity: { type: 'string', enum: ['low', 'medium', 'high', 'critical'] },
          details: { type: 'object' },
          userId: { type: 'string', format: 'uuid' },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const body = request.body as any;
      const user = (request as any).user;

      const eventId = await auditService.logSecurityEvent({
        eventType: body.eventType,
        severity: body.severity,
        userId: body.userId || user.userId,
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        details: body.details,
      });

      return reply.send({
        success: true,
        data: {
          eventId,
          message: 'Security event logged successfully',
        },
      });
    } catch (error) {
      logger.error('Failed to log security event', {
        error: String(error),
        body: request.body,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'SECURITY_EVENT_LOG_FAILED',
          message: 'Failed to log security event',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /audit/report - Generate audit report
   */
  fastify.get('/report', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['generate_audit_reports', 'admin_access']),
      validateQuery(auditReportSchema),
    ],
    schema: {
      description: 'Generate comprehensive audit report',
      tags: ['Audit'],
      querystring: {
        type: 'object',
        required: ['dateFrom', 'dateTo'],
        properties: {
          dateFrom: { type: 'string', format: 'date-time' },
          dateTo: { type: 'string', format: 'date-time' },
          format: { type: 'string', enum: ['json', 'csv'], default: 'json' },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { dateFrom, dateTo, format = 'json' } = request.query as any;
      const user = (request as any).user;

      const fromDate = new Date(dateFrom);
      const toDate = new Date(dateTo);

      // Validate date range
      if (fromDate >= toDate) {
        return reply.status(400).send({
          success: false,
          error: {
            code: 'INVALID_DATE_RANGE',
            message: 'dateFrom must be before dateTo',
            timestamp: new Date().toISOString(),
          },
        });
      }

      // Limit report range to prevent performance issues
      const maxRangeDays = 90;
      const rangeDays = Math.ceil((toDate.getTime() - fromDate.getTime()) / (1000 * 60 * 60 * 24));
      if (rangeDays > maxRangeDays) {
        return reply.status(400).send({
          success: false,
          error: {
            code: 'DATE_RANGE_TOO_LARGE',
            message: `Date range cannot exceed ${maxRangeDays} days`,
            timestamp: new Date().toISOString(),
          },
        });
      }

      if (format === 'csv') {
        // Generate CSV export
        const csvContent = await auditService.exportAuditLogs({
          dateFrom: fromDate,
          dateTo: toDate,
          limit: 10000,
        });

        // Log report generation
        await auditService.logAction({
          userId: user.userId,
          action: 'audit_report_generated',
          resourceType: 'audit',
          ipAddress: request.ip,
          userAgent: request.headers['user-agent'],
          success: true,
          metadata: {
            format: 'csv',
            dateRange: { from: fromDate, to: toDate },
            rangeDays,
          },
        });

        reply.header('Content-Type', 'text/csv');
        reply.header('Content-Disposition', `attachment; filename="audit-report-${fromDate.toISOString().split('T')[0]}-to-${toDate.toISOString().split('T')[0]}.csv"`);
        return reply.send(csvContent);
      } else {
        // Generate JSON report
        const report = await auditService.generateAuditReport(fromDate, toDate);

        // Log report generation
        await auditService.logAction({
          userId: user.userId,
          action: 'audit_report_generated',
          resourceType: 'audit',
          ipAddress: request.ip,
          userAgent: request.headers['user-agent'],
          success: true,
          metadata: {
            format: 'json',
            dateRange: { from: fromDate, to: toDate },
            rangeDays,
            totalEntries: report.totalEntries,
          },
        });

        return reply.send({
          success: true,
          data: report,
        });
      }
    } catch (error) {
      logger.error('Failed to generate audit report', {
        error: String(error),
        query: request.query,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'AUDIT_REPORT_FAILED',
          message: 'Failed to generate audit report',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /audit/stats - Get audit statistics
   */
  fastify.get('/stats', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['view_audit_stats', 'admin_access']),
    ],
    schema: {
      description: 'Get audit statistics',
      tags: ['Audit'],
      querystring: {
        type: 'object',
        properties: {
          days: { type: 'integer', minimum: 1, maximum: 90, default: 7 },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { days = 7 } = request.query as any;
      const user = (request as any).user;

      const toDate = new Date();
      const fromDate = new Date();
      fromDate.setDate(fromDate.getDate() - days);

      const report = await auditService.generateAuditReport(fromDate, toDate);

      // Simplified stats for dashboard
      const stats = {
        totalEntries: report.totalEntries,
        successRate: report.totalEntries > 0 ? 
          ((report.successfulActions / report.totalEntries) * 100).toFixed(2) : '0.00',
        failedActions: report.failedActions,
        topActions: report.topActions.slice(0, 5),
        securityEvents: report.securityEvents,
        timeRange: report.timeRange,
      };

      // Log stats access
      await auditService.logAction({
        userId: user.userId,
        action: 'audit_stats_viewed',
        resourceType: 'audit',
        ipAddress: request.ip,
        userAgent: request.headers['user-agent'],
        success: true,
        metadata: {
          days,
          totalEntries: stats.totalEntries,
        },
      });

      return reply.send({
        success: true,
        data: stats,
      });
    } catch (error) {
      logger.error('Failed to get audit statistics', {
        error: String(error),
        query: request.query,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'AUDIT_STATS_FAILED',
          message: 'Failed to retrieve audit statistics',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * DELETE /audit/cleanup - Clean up old audit logs
   */
  fastify.delete('/cleanup', {
    preHandler: [
      rateLimiter.api,
      requirePermission(['manage_audit_retention', 'admin_access']),
    ],
    schema: {
      description: 'Clean up old audit logs based on retention policy',
      tags: ['Audit'],
      querystring: {
        type: 'object',
        properties: {
          retentionDays: { type: 'integer', minimum: 30, maximum: 2555, default: 365 },
          dryRun: { type: 'boolean', default: false },
        },
      },
    },
  }, async (request: FastifyRequest, reply: FastifyReply) => {
    try {
      const { retentionDays = 365, dryRun = false } = request.query as any;
      const user = (request as any).user;

      if (dryRun) {
        // Calculate what would be deleted without actually deleting
        const cutoffDate = new Date();
        cutoffDate.setDate(cutoffDate.getDate() - retentionDays);

        const criticalActions = ['login', 'logout', 'payment_process', 'security_event'];
        const countToDelete = await fastify.prisma.auditLog.count({
          where: {
            createdAt: {
              lt: cutoffDate,
            },
            action: {
              notIn: criticalActions,
            },
          },
        });

        return reply.send({
          success: true,
          data: {
            dryRun: true,
            wouldDelete: countToDelete,
            retentionDays,
            cutoffDate: cutoffDate.toISOString(),
          },
        });
      } else {
        const deletedCount = await auditService.cleanupOldLogs(retentionDays);

        return reply.send({
          success: true,
          data: {
            deletedCount,
            retentionDays,
            message: `Successfully cleaned up ${deletedCount} old audit log entries`,
          },
        });
      }
    } catch (error) {
      logger.error('Failed to cleanup audit logs', {
        error: String(error),
        query: request.query,
        userId: (request as any).user?.userId,
      });

      return reply.status(500).send({
        success: false,
        error: {
          code: 'AUDIT_CLEANUP_FAILED',
          message: 'Failed to cleanup audit logs',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });
}