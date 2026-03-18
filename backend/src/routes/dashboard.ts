import { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { authenticate } from '@/middleware/auth';
import { validateQuery } from '@/middleware/validation';
import { DashboardService } from '@/services/DashboardService';
import { PrismaClient } from '@prisma/client';

const dashboardQuerySchema = z.object({
  dateFrom: z.string().optional(),
  dateTo: z.string().optional(),
  lineAccountId: z.string().optional(),
});

const metricsQuerySchema = z.object({
  dateFrom: z.string().optional(),
  dateTo: z.string().optional(),
  lineAccountId: z.string().optional(),
  metricType: z.enum(['orders', 'payments', 'webhooks', 'customers']).optional(),
});

const chartsQuerySchema = z.object({
  dateFrom: z.string().optional(),
  dateTo: z.string().optional(),
  lineAccountId: z.string().optional(),
  chartType: z.enum(['orderTrends', 'paymentTrends', 'webhookStats']).optional(),
});

export default async function dashboardRoutes(fastify: FastifyInstance): Promise<void> {
  const prisma = new PrismaClient();
  const dashboardService = new DashboardService(prisma);

  // Dashboard overview endpoint
  fastify.get('/overview', {
    preHandler: [authenticate, validateQuery(dashboardQuerySchema)],
    schema: {
      tags: ['Dashboard'],
      summary: 'Get dashboard overview metrics',
      querystring: {
        type: 'object',
        properties: {
          dateFrom: { type: 'string', format: 'date' },
          dateTo: { type: 'string', format: 'date' },
          lineAccountId: { type: 'string' },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const { dateFrom, dateTo, lineAccountId } = request.query as any;
      
      // Use lineAccountId from query or from authenticated user
      const user = request.user as any;
      const accountId = lineAccountId || user?.lineAccountId || '1';
      
      const parsedDateFrom = dateFrom ? new Date(dateFrom) : undefined;
      const parsedDateTo = dateTo ? new Date(dateTo) : undefined;

      const metrics = await dashboardService.getOverviewMetrics(
        accountId,
        parsedDateFrom,
        parsedDateTo
      );

      return reply.send({
        success: true,
        data: metrics,
      });
    } catch (error) {
      console.error('Dashboard overview error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'DASHBOARD_ERROR',
          message: 'Failed to retrieve dashboard metrics',
        },
      });
    }
  });

  // Detailed metrics endpoint
  fastify.get('/metrics', {
    preHandler: [authenticate, validateQuery(metricsQuerySchema)],
    schema: {
      tags: ['Dashboard'],
      summary: 'Get detailed dashboard metrics',
      querystring: {
        type: 'object',
        properties: {
          dateFrom: { type: 'string', format: 'date' },
          dateTo: { type: 'string', format: 'date' },
          lineAccountId: { type: 'string' },
          metricType: { 
            type: 'string', 
            enum: ['orders', 'payments', 'webhooks', 'customers'] 
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const { dateFrom, dateTo, lineAccountId, metricType } = request.query as any;
      
      const user = request.user as any;
      const accountId = lineAccountId || user?.lineAccountId || '1';
      const parsedDateFrom = dateFrom ? new Date(dateFrom) : undefined;
      const parsedDateTo = dateTo ? new Date(dateTo) : undefined;

      if (metricType) {
        // Get specific metric type
        const metrics = await dashboardService.getDetailedMetrics(
          accountId,
          metricType,
          parsedDateFrom,
          parsedDateTo
        );

        return reply.send({
          success: true,
          data: { [metricType]: metrics },
        });
      } else {
        // Get all detailed metrics
        const [orderMetrics, paymentMetrics, webhookMetrics, customerMetrics] = await Promise.all([
          dashboardService.getDetailedMetrics(accountId, 'orders', parsedDateFrom, parsedDateTo),
          dashboardService.getDetailedMetrics(accountId, 'payments', parsedDateFrom, parsedDateTo),
          dashboardService.getDetailedMetrics(accountId, 'webhooks', parsedDateFrom, parsedDateTo),
          dashboardService.getDetailedMetrics(accountId, 'customers', parsedDateFrom, parsedDateTo),
        ]);

        return reply.send({
          success: true,
          data: {
            orderMetrics,
            paymentMetrics,
            webhookMetrics,
            customerMetrics,
          },
        });
      }
    } catch (error) {
      console.error('Dashboard metrics error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'METRICS_ERROR',
          message: 'Failed to retrieve detailed metrics',
        },
      });
    }
  });

  // Chart data endpoint
  fastify.get('/charts', {
    preHandler: [authenticate, validateQuery(chartsQuerySchema)],
    schema: {
      tags: ['Dashboard'],
      summary: 'Get chart data for visualizations',
      querystring: {
        type: 'object',
        properties: {
          dateFrom: { type: 'string', format: 'date' },
          dateTo: { type: 'string', format: 'date' },
          lineAccountId: { type: 'string' },
          chartType: { 
            type: 'string', 
            enum: ['orderTrends', 'paymentTrends', 'webhookStats'] 
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const { dateFrom, dateTo, lineAccountId, chartType } = request.query as any;
      
      const user = request.user as any;
      const accountId = lineAccountId || user?.lineAccountId || '1';
      const parsedDateFrom = dateFrom ? new Date(dateFrom) : undefined;
      const parsedDateTo = dateTo ? new Date(dateTo) : undefined;

      if (chartType) {
        // Get specific chart type
        const chartData = await dashboardService.getChartData(
          accountId,
          chartType,
          parsedDateFrom,
          parsedDateTo
        );

        return reply.send({
          success: true,
          data: { [chartType]: chartData },
        });
      } else {
        // Get all chart data
        const [orderTrends, paymentTrends, webhookStats] = await Promise.all([
          dashboardService.getChartData(accountId, 'orderTrends', parsedDateFrom, parsedDateTo),
          dashboardService.getChartData(accountId, 'paymentTrends', parsedDateFrom, parsedDateTo),
          dashboardService.getChartData(accountId, 'webhookStats', parsedDateFrom, parsedDateTo),
        ]);

        return reply.send({
          success: true,
          data: {
            orderTrends,
            paymentTrends,
            webhookStats,
          },
        });
      }
    } catch (error) {
      console.error('Dashboard charts error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'CHARTS_ERROR',
          message: 'Failed to retrieve chart data',
        },
      });
    }
  });
}