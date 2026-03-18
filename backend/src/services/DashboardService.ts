import { BaseService } from './BaseService';
import { PrismaClient } from '@prisma/client';

interface DashboardMetrics {
  orders: OrderMetrics;
  payments: PaymentMetrics;
  webhooks: WebhookMetrics;
  customers: CustomerMetrics;
  updatedAt: Date;
}

interface OrderMetrics {
  todayCount: number;
  todayTotal: number;
  pendingCount: number;
  completedCount: number;
  averageOrderValue: number;
}

interface PaymentMetrics {
  pendingSlips: number;
  processedToday: number;
  matchingRate: number;
  totalAmount: number;
  averageProcessingTime: number;
}

interface WebhookMetrics {
  todayCount: number;
  successRate: number;
  failedCount: number;
  averageResponseTime: number;
}

interface CustomerMetrics {
  totalActive: number;
  newToday: number;
  lineConnected: number;
  averageOrdersPerCustomer: number;
}

export class DashboardService extends BaseService {
  constructor(prisma: PrismaClient) {
    super(prisma);
  }

  async getOverviewMetrics(
    lineAccountId: string,
    dateFrom?: Date,
    dateTo?: Date
  ): Promise<DashboardMetrics> {
    try {
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      const tomorrow = new Date(today);
      tomorrow.setDate(tomorrow.getDate() + 1);

      const actualDateFrom = dateFrom || today;
      const actualDateTo = dateTo || tomorrow;

      // Try to get cached metrics first
      const cachedMetrics = await this.getCachedMetrics(lineAccountId, actualDateFrom);
      if (cachedMetrics) {
        return cachedMetrics;
      }

      // Get order metrics
      const orderMetrics = await this.getOrderMetrics(lineAccountId, actualDateFrom, actualDateTo);
      
      // Get payment metrics
      const paymentMetrics = await this.getPaymentMetrics(lineAccountId, actualDateFrom, actualDateTo);
      
      // Get webhook metrics
      const webhookMetrics = await this.getWebhookMetrics(lineAccountId, actualDateFrom, actualDateTo);
      
      // Get customer metrics
      const customerMetrics = await this.getCustomerMetrics(lineAccountId, actualDateFrom, actualDateTo);

      const metrics: DashboardMetrics = {
        orders: orderMetrics,
        payments: paymentMetrics,
        webhooks: webhookMetrics,
        customers: customerMetrics,
        updatedAt: new Date(),
      };

      // Cache the metrics
      await this.cacheMetrics(lineAccountId, actualDateFrom, metrics);

      return metrics;
    } catch (error) {
      this.handleError(error, 'DashboardService.getOverviewMetrics');
    }
  }

  private async getCachedMetrics(
    lineAccountId: string,
    dateKey: Date
  ): Promise<DashboardMetrics | null> {
    try {
      const cached = await this.prisma.dashboardMetricsCache.findFirst({
        where: {
          lineAccountId,
          dateKey,
          expiresAt: {
            gt: new Date(),
          },
        },
      });

      if (cached) {
        return cached.data as unknown as DashboardMetrics;
      }

      return null;
    } catch (error) {
      console.error('Error retrieving cached metrics:', error);
      return null;
    }
  }

  private async cacheMetrics(
    lineAccountId: string,
    dateKey: Date,
    metrics: DashboardMetrics
  ): Promise<void> {
    try {
      const expiresAt = new Date();
      expiresAt.setMinutes(expiresAt.getMinutes() + 30); // Cache for 30 minutes

      await this.prisma.dashboardMetricsCache.upsert({
        where: {
          lineAccountId_metricType_dateKey: {
            lineAccountId,
            metricType: 'ORDERS', // Using ORDERS as a general cache key
            dateKey,
          },
        },
        update: {
          data: metrics as any,
          expiresAt,
          updatedAt: new Date(),
        },
        create: {
          lineAccountId,
          metricType: 'ORDERS',
          dateKey,
          data: metrics as any,
          expiresAt,
        },
      });
    } catch (error) {
      console.error('Error caching metrics:', error);
      // Don't throw error - caching failure shouldn't break the request
    }
  }

  private async getOrderMetrics(
      lineAccountId: string,
      dateFrom: Date,
      dateTo: Date
    ): Promise<OrderMetrics> {
      try {
        // Get today's orders
        const todayOrders = await this.prisma.odooOrder.findMany({
          where: {
            lineAccountId,
            createdAt: {
              gte: dateFrom,
              lt: dateTo,
            },
          },
        });

        // Get pending orders
        const pendingOrders = await this.prisma.odooOrder.findMany({
          where: {
            lineAccountId,
            status: {
              in: ['draft', 'pending', 'confirmed'],
            },
          },
        });

        // Get completed orders
        const completedOrders = await this.prisma.odooOrder.findMany({
          where: {
            lineAccountId,
            status: {
              in: ['done', 'delivered', 'completed'],
            },
          },
        });

        // Calculate metrics
        const todayCount = todayOrders.length;
        const todayTotal = todayOrders.reduce(
          (sum, order) => sum + Number(order.totalAmount),
          0
        );
        const pendingCount = pendingOrders.length;
        const completedCount = completedOrders.length;
        const averageOrderValue = todayCount > 0 ? todayTotal / todayCount : 0;

        return {
          todayCount,
          todayTotal,
          pendingCount,
          completedCount,
          averageOrderValue,
        };
      } catch (error) {
        console.error('Error calculating order metrics:', error);
        // Return fallback data on error
        return {
          todayCount: 0,
          todayTotal: 0,
          pendingCount: 0,
          completedCount: 0,
          averageOrderValue: 0,
        };
      }
    }


  private async getPaymentMetrics(
      lineAccountId: string,
      dateFrom: Date,
      dateTo: Date
    ): Promise<PaymentMetrics> {
      try {
        // Get today's processed payments
        const todayProcessed = await this.prisma.odooSlipUpload.findMany({
          where: {
            lineAccountId,
            status: 'MATCHED',
            processedAt: {
              gte: dateFrom,
              lt: dateTo,
            },
          },
        });

        // Get pending payment slips
        const pendingSlips = await this.prisma.odooSlipUpload.findMany({
          where: {
            lineAccountId,
            status: 'PENDING',
          },
        });

        // Get all processed payments for matching rate calculation
        const allProcessed = await this.prisma.odooSlipUpload.findMany({
          where: {
            lineAccountId,
            status: {
              in: ['MATCHED', 'REJECTED'],
            },
          },
        });

        // Calculate metrics
        const processedToday = todayProcessed.length;
        const pendingCount = pendingSlips.length;
        const totalAmount = todayProcessed.reduce(
          (sum, slip) => sum + (Number(slip.amount) || 0),
          0
        );

        const matchedCount = allProcessed.filter(slip => slip.status === 'MATCHED').length;
        const matchingRate = allProcessed.length > 0 ? (matchedCount / allProcessed.length) * 100 : 0;

        // Calculate average processing time (simplified - using hours between created and processed)
        const processedWithTimes = todayProcessed.filter(slip => slip.processedAt);
        const averageProcessingTime = processedWithTimes.length > 0 
          ? processedWithTimes.reduce((sum, slip) => {
              const processingTime = slip.processedAt 
                ? (slip.processedAt.getTime() - slip.createdAt.getTime()) / (1000 * 60) // minutes
                : 0;
              return sum + processingTime;
            }, 0) / processedWithTimes.length
          : 0;

        return {
          pendingSlips: pendingCount,
          processedToday,
          matchingRate: Math.round(matchingRate * 10) / 10, // Round to 1 decimal
          totalAmount,
          averageProcessingTime: Math.round(averageProcessingTime),
        };
      } catch (error) {
        console.error('Error calculating payment metrics:', error);
        // Return fallback data on error
        return {
          pendingSlips: 0,
          processedToday: 0,
          matchingRate: 0,
          totalAmount: 0,
          averageProcessingTime: 0,
        };
      }
    }


  private async getWebhookMetrics(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<WebhookMetrics> {
    try {
      // Get today's webhooks
      const todayWebhooks = await this.prisma.odooWebhookLog.findMany({
        where: {
          lineAccountId,
          createdAt: {
            gte: dateFrom,
            lt: dateTo,
          },
        },
      });

      // Get failed webhooks today
      const failedWebhooks = todayWebhooks.filter(
        webhook => webhook.status === 'FAILED'
      );

      // Get processed webhooks for success rate calculation
      const processedWebhooks = todayWebhooks.filter(
        webhook => webhook.status === 'PROCESSED'
      );

      // Calculate metrics
      const todayCount = todayWebhooks.length;
      const failedCount = failedWebhooks.length;
      const successRate = todayCount > 0 
        ? (processedWebhooks.length / todayCount) * 100 
        : 0;

      // Calculate average response time (simplified - using processing time)
      const processedWithTimes = processedWebhooks.filter(webhook => webhook.processedAt);
      const averageResponseTime = processedWithTimes.length > 0
        ? processedWithTimes.reduce((sum, webhook) => {
            const responseTime = webhook.processedAt
              ? (webhook.processedAt.getTime() - webhook.createdAt.getTime())
              : 0;
            return sum + responseTime;
          }, 0) / processedWithTimes.length
        : 0;

      return {
        todayCount,
        successRate: Math.round(successRate * 10) / 10, // Round to 1 decimal
        failedCount,
        averageResponseTime: Math.round(averageResponseTime), // in milliseconds
      };
    } catch (error) {
      console.error('Error calculating webhook metrics:', error);
      // Return fallback data on error
      return {
        todayCount: 0,
        successRate: 0,
        failedCount: 0,
        averageResponseTime: 0,
      };
    }
  }

  private async getCustomerMetrics(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<CustomerMetrics> {
    try {
      // Get total active followers
      const activeFollowers = await this.prisma.accountFollower.findMany({
        where: {
          lineAccount: {
            id: parseInt(lineAccountId),
          },
          isFollowing: true,
        },
      });

      // Get new followers today
      const newFollowersToday = await this.prisma.accountFollower.findMany({
        where: {
          lineAccount: {
            id: parseInt(lineAccountId),
          },
          followedAt: {
            gte: dateFrom,
            lt: dateTo,
          },
          isFollowing: true,
        },
      });

      // Get LINE connected customers (followers with user_id)
      const lineConnectedFollowers = activeFollowers.filter(
        follower => follower.userId !== null
      );

      // Calculate average orders per customer (simplified)
      const totalOrders = await this.prisma.odooOrder.count({
        where: {
          lineAccountId,
        },
      });

      const averageOrdersPerCustomer = activeFollowers.length > 0 
        ? totalOrders / activeFollowers.length 
        : 0;

      return {
        totalActive: activeFollowers.length,
        newToday: newFollowersToday.length,
        lineConnected: lineConnectedFollowers.length,
        averageOrdersPerCustomer: Math.round(averageOrdersPerCustomer * 10) / 10, // Round to 1 decimal
      };
    } catch (error) {
      console.error('Error calculating customer metrics:', error);
      // Return fallback data on error
      return {
        totalActive: 0,
        newToday: 0,
        lineConnected: 0,
        averageOrdersPerCustomer: 0,
      };
    }
  }

  async getDetailedMetrics(
    lineAccountId: string,
    metricType: 'orders' | 'payments' | 'webhooks' | 'customers',
    dateFrom?: Date,
    dateTo?: Date
  ): Promise<any> {
    try {
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      const actualDateFrom = dateFrom || new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000); // 7 days ago
      const actualDateTo = dateTo || today;

      switch (metricType) {
        case 'orders':
          return await this.getOrderTrends(lineAccountId, actualDateFrom, actualDateTo);
        case 'payments':
          return await this.getPaymentTrends(lineAccountId, actualDateFrom, actualDateTo);
        case 'webhooks':
          return await this.getWebhookTrends(lineAccountId, actualDateFrom, actualDateTo);
        case 'customers':
          return await this.getCustomerTrends(lineAccountId, actualDateFrom, actualDateTo);
        default:
          throw new Error(`Unknown metric type: ${metricType}`);
      }
    } catch (error) {
      this.handleError(error, 'DashboardService.getDetailedMetrics');
    }
  }

  async getChartData(
    lineAccountId: string,
    chartType: 'orderTrends' | 'paymentTrends' | 'webhookStats',
    dateFrom?: Date,
    dateTo?: Date
  ): Promise<any> {
    try {
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      const actualDateFrom = dateFrom || new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000); // 30 days ago
      const actualDateTo = dateTo || today;

      switch (chartType) {
        case 'orderTrends':
          return await this.getOrderChartData(lineAccountId, actualDateFrom, actualDateTo);
        case 'paymentTrends':
          return await this.getPaymentChartData(lineAccountId, actualDateFrom, actualDateTo);
        case 'webhookStats':
          return await this.getWebhookChartData(lineAccountId, actualDateFrom, actualDateTo);
        default:
          throw new Error(`Unknown chart type: ${chartType}`);
      }
    } catch (error) {
      this.handleError(error, 'DashboardService.getChartData');
    }
  }

  private async getOrderTrends(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<any> {
    try {
      const orders = await this.prisma.odooOrder.findMany({
        where: {
          lineAccountId,
          createdAt: {
            gte: dateFrom,
            lte: dateTo,
          },
        },
        orderBy: {
          createdAt: 'desc',
        },
        take: 100, // Limit for performance
      });

      return {
        totalOrders: orders.length,
        totalValue: orders.reduce((sum, order) => sum + Number(order.totalAmount), 0),
        statusBreakdown: this.groupByStatus(orders),
        dailyTrends: this.groupByDate(orders, 'createdAt'),
      };
    } catch (error) {
      console.error('Error getting order trends:', error);
      return { totalOrders: 0, totalValue: 0, statusBreakdown: {}, dailyTrends: [] };
    }
  }

  private async getPaymentTrends(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<any> {
    try {
      const payments = await this.prisma.odooSlipUpload.findMany({
        where: {
          lineAccountId,
          createdAt: {
            gte: dateFrom,
            lte: dateTo,
          },
        },
        orderBy: {
          createdAt: 'desc',
        },
        take: 100,
      });

      return {
        totalPayments: payments.length,
        totalAmount: payments.reduce((sum, payment) => sum + (Number(payment.amount) || 0), 0),
        statusBreakdown: this.groupByStatus(payments),
        dailyTrends: this.groupByDate(payments, 'createdAt'),
      };
    } catch (error) {
      console.error('Error getting payment trends:', error);
      return { totalPayments: 0, totalAmount: 0, statusBreakdown: {}, dailyTrends: [] };
    }
  }

  private async getWebhookTrends(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<any> {
    try {
      const webhooks = await this.prisma.odooWebhookLog.findMany({
        where: {
          lineAccountId,
          createdAt: {
            gte: dateFrom,
            lte: dateTo,
          },
        },
        orderBy: {
          createdAt: 'desc',
        },
        take: 100,
      });

      return {
        totalWebhooks: webhooks.length,
        statusBreakdown: this.groupByStatus(webhooks),
        typeBreakdown: this.groupByField(webhooks, 'webhookType'),
        dailyTrends: this.groupByDate(webhooks, 'createdAt'),
      };
    } catch (error) {
      console.error('Error getting webhook trends:', error);
      return { totalWebhooks: 0, statusBreakdown: {}, typeBreakdown: {}, dailyTrends: [] };
    }
  }

  private async getCustomerTrends(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<any> {
    try {
      const followers = await this.prisma.accountFollower.findMany({
        where: {
          lineAccount: {
            id: parseInt(lineAccountId),
          },
          followedAt: {
            gte: dateFrom,
            lte: dateTo,
          },
        },
        orderBy: {
          followedAt: 'desc',
        },
        take: 100,
      });

      return {
        totalNewFollowers: followers.length,
        activeFollowers: followers.filter(f => f.isFollowing).length,
        dailyTrends: this.groupByDate(followers, 'followedAt'),
      };
    } catch (error) {
      console.error('Error getting customer trends:', error);
      return { totalNewFollowers: 0, activeFollowers: 0, dailyTrends: [] };
    }
  }

  private async getOrderChartData(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<any> {
    try {
      const orders = await this.prisma.odooOrder.findMany({
        where: {
          lineAccountId,
          createdAt: {
            gte: dateFrom,
            lte: dateTo,
          },
        },
        select: {
          createdAt: true,
          totalAmount: true,
          status: true,
        },
      });

      return this.generateChartData(orders, 'createdAt', 'totalAmount');
    } catch (error) {
      console.error('Error getting order chart data:', error);
      return [];
    }
  }

  private async getPaymentChartData(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<any> {
    try {
      const payments = await this.prisma.odooSlipUpload.findMany({
        where: {
          lineAccountId,
          createdAt: {
            gte: dateFrom,
            lte: dateTo,
          },
        },
        select: {
          createdAt: true,
          amount: true,
          status: true,
        },
      });

      return this.generateChartData(payments, 'createdAt', 'amount');
    } catch (error) {
      console.error('Error getting payment chart data:', error);
      return [];
    }
  }

  private async getWebhookChartData(
    lineAccountId: string,
    dateFrom: Date,
    dateTo: Date
  ): Promise<any> {
    try {
      const webhooks = await this.prisma.odooWebhookLog.findMany({
        where: {
          lineAccountId,
          createdAt: {
            gte: dateFrom,
            lte: dateTo,
          },
        },
        select: {
          createdAt: true,
          status: true,
          webhookType: true,
        },
      });

      return this.generateWebhookChartData(webhooks);
    } catch (error) {
      console.error('Error getting webhook chart data:', error);
      return [];
    }
  }

  private groupByStatus(items: any[]): Record<string, number> {
    return items.reduce((acc, item) => {
      const status = item.status || 'unknown';
      acc[status] = (acc[status] || 0) + 1;
      return acc;
    }, {});
  }

  private groupByField(items: any[], field: string): Record<string, number> {
    return items.reduce((acc, item) => {
      const value = item[field] || 'unknown';
      acc[value] = (acc[value] || 0) + 1;
      return acc;
    }, {});
  }

  private groupByDate(items: any[], dateField: string): any[] {
    const grouped: Record<string, any> = items.reduce((acc, item) => {
      const dateValue = item[dateField];
      if (!dateValue) return acc;
      
      const date = new Date(dateValue).toISOString().split('T')[0];
      if (!acc[date]) {
        acc[date] = { date, count: 0, value: 0 };
      }
      acc[date].count += 1;
      acc[date].value += Number(item.totalAmount || item.amount || 0);
      return acc;
    }, {} as Record<string, any>);

    return Object.values(grouped).sort((a: any, b: any) => a.date.localeCompare(b.date));
  }

  private generateChartData(items: any[], dateField: string, valueField: string): any[] {
    const grouped: Record<string, any> = items.reduce((acc, item) => {
      const dateValue = item[dateField];
      if (!dateValue) return acc;
      
      const date = new Date(dateValue).toISOString().split('T')[0];
      if (!acc[date]) {
        acc[date] = { date, value: 0, count: 0 };
      }
      acc[date].value += Number(item[valueField] || 0);
      acc[date].count += 1;
      return acc;
    }, {} as Record<string, any>);

    return Object.values(grouped).sort((a: any, b: any) => a.date.localeCompare(b.date));
  }

  private generateWebhookChartData(webhooks: any[]): any[] {
    const grouped: Record<string, any> = webhooks.reduce((acc, webhook) => {
      const dateValue = webhook.createdAt;
      if (!dateValue) return acc;
      
      const date = new Date(dateValue).toISOString().split('T')[0];
      if (!acc[date]) {
        acc[date] = { date, success: 0, failed: 0, total: 0 };
      }
      acc[date].total += 1;
      if (webhook.status === 'PROCESSED') {
        acc[date].success += 1;
      } else if (webhook.status === 'FAILED') {
        acc[date].failed += 1;
      }
      return acc;
    }, {} as Record<string, any>);

    return Object.values(grouped).sort((a: any, b: any) => a.date.localeCompare(b.date));
  }
}