import { BaseService } from './BaseService';
import { PrismaClient, Prisma } from '@prisma/client';

export interface OrderFilters {
  status?: string[];
  customerRef?: string;
  customerName?: string;
  dateFrom?: Date;
  dateTo?: Date;
  search?: string;
}

export interface PaginationOptions {
  page: number;
  limit: number;
  sort?: string;
  order?: 'asc' | 'desc';
}

export interface OrderWithTimeline {
  id: string;
  odooOrderId: string;
  lineAccountId: string;
  customerRef: string | null;
  customerName: string | null;
  status: string;
  totalAmount: number;
  currency: string;
  orderDate: Date | null;
  deliveryDate: Date | null;
  notes: string | null;
  webhookProcessed: boolean;
  createdAt: Date;
  updatedAt: Date;
  timeline: OrderTimelineEntry[];
}

export interface OrderTimelineEntry {
  id: string;
  orderId: string;
  status: string;
  previousStatus: string | null;
  notes: string | null;
  changedBy: string | null;
  changedAt: Date;
  source: 'system' | 'manual' | 'webhook';
}

export interface PaginatedOrders {
  data: OrderWithTimeline[];
  meta: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
  };
}

export class OrderService extends BaseService {
  constructor(prisma: PrismaClient) {
    super(prisma);
  }

  /**
   * Get paginated list of orders with filtering
   */
  async getOrders(
    lineAccountId: string,
    filters: OrderFilters = {},
    pagination: PaginationOptions = { page: 1, limit: 20 }
  ): Promise<PaginatedOrders> {
    try {
      const { page, limit, sort = 'createdAt', order = 'desc' } = pagination;
      const skip = (page - 1) * limit;

      // Build where clause
      const where: Prisma.OdooOrderWhereInput = {
        lineAccountId,
        ...(filters.status && filters.status.length > 0 && {
          status: { in: filters.status }
        }),
        ...(filters.customerRef && {
          customerRef: { contains: filters.customerRef }
        }),
        ...(filters.customerName && {
          customerName: { contains: filters.customerName }
        }),
        ...(filters.dateFrom || filters.dateTo) && {
          createdAt: {
            ...(filters.dateFrom && { gte: filters.dateFrom }),
            ...(filters.dateTo && { lte: filters.dateTo })
          }
        },
        ...(filters.search && {
          OR: [
            { customerRef: { contains: filters.search } },
            { customerName: { contains: filters.search } },
            { odooOrderId: { contains: filters.search } },
            { notes: { contains: filters.search } }
          ]
        })
      };

      // Get total count for pagination
      const total = await this.prisma.odooOrder.count({ where });

      // Get orders with timeline
      const orders = await this.prisma.odooOrder.findMany({
        where,
        skip,
        take: limit,
        orderBy: { [sort]: order },
      });

      // Get timeline for each order
      const ordersWithTimeline = await Promise.all(
        orders.map(async (order) => ({
          ...order,
          totalAmount: Number(order.totalAmount),
          timeline: await this.getOrderTimeline(order.id)
        }))
      );

      return {
        data: ordersWithTimeline,
        meta: {
          page,
          limit,
          total,
          totalPages: Math.ceil(total / limit)
        }
      };
    } catch (error) {
      this.handleError(error, 'OrderService.getOrders');
    }
  }

  /**
   * Get specific order details with timeline
   */
  async getOrderById(
    orderId: string,
    lineAccountId: string
  ): Promise<OrderWithTimeline | null> {
    try {
      const order = await this.prisma.odooOrder.findFirst({
        where: {
          id: orderId,
          lineAccountId
        }
      });

      if (!order) {
        return null;
      }

      const timeline = await this.getOrderTimeline(orderId);

      return {
        ...order,
        totalAmount: Number(order.totalAmount),
        timeline
      };
    } catch (error) {
      this.handleError(error, 'OrderService.getOrderById');
    }
  }

  /**
   * Update order status with audit trail
   */
  async updateOrderStatus(
    orderId: string,
    lineAccountId: string,
    newStatus: string,
    notes?: string,
    changedBy?: string
  ): Promise<OrderWithTimeline> {
    try {
      // Get current order
      const currentOrder = await this.prisma.odooOrder.findFirst({
        where: {
          id: orderId,
          lineAccountId
        }
      });

      if (!currentOrder) {
        throw new Error('Order not found');
      }

      const previousStatus = currentOrder.status;

      // Update order status in transaction
      const result = await this.prisma.$transaction(async (tx) => {
        // Update the order
        const updatedOrder = await tx.odooOrder.update({
          where: { id: orderId },
          data: {
            status: newStatus,
            updatedAt: new Date(),
            ...(notes && { notes })
          }
        });

        // Create timeline entry
        await this.createTimelineEntry(tx, {
          orderId,
          status: newStatus,
          previousStatus,
          ...(notes && { notes }),
          ...(changedBy && { changedBy }),
          source: changedBy ? 'manual' : 'system'
        });

        return updatedOrder;
      });

      // Get updated order with timeline
      const timeline = await this.getOrderTimeline(orderId);

      return {
        ...result,
        totalAmount: Number(result.totalAmount),
        timeline
      };
    } catch (error) {
      this.handleError(error, 'OrderService.updateOrderStatus');
    }
  }

  /**
   * Get order status timeline
   */
  async getOrderTimeline(orderId: string): Promise<OrderTimelineEntry[]> {
    try {
      // For now, we'll create a simple timeline from audit logs
      // In a full implementation, you'd have a dedicated order_timeline table
      const auditLogs = await this.prisma.auditLog.findMany({
        where: {
          resourceType: 'order',
          resourceId: orderId
        },
        orderBy: {
          createdAt: 'asc'
        }
      });

      return auditLogs.map(log => ({
        id: log.id,
        orderId,
        status: (log.newValues as any)?.status || 'unknown',
        previousStatus: (log.oldValues as any)?.status || null,
        notes: (log.newValues as any)?.notes || null,
        changedBy: log.userId,
        changedAt: log.createdAt,
        source: log.action.includes('webhook') ? 'webhook' as const : 
                log.action.includes('manual') ? 'manual' as const : 'system' as const
      }));
    } catch (error) {
      console.error('Error getting order timeline:', error);
      return [];
    }
  }

  /**
   * Create timeline entry (helper method)
   */
  private async createTimelineEntry(
    tx: Prisma.TransactionClient,
    entry: {
      orderId: string;
      status: string;
      previousStatus: string | null;
      notes?: string;
      changedBy?: string;
      source: 'system' | 'manual' | 'webhook';
    }
  ): Promise<void> {
    // Create audit log entry for timeline tracking
    await tx.auditLog.create({
      data: {
        userId: entry.changedBy || 'system',
        action: `${entry.source}_status_update`,
        resourceType: 'order',
        resourceId: entry.orderId,
        ...(entry.previousStatus && { oldValues: { status: entry.previousStatus } }),
        newValues: {
          status: entry.status,
          ...(entry.notes && { notes: entry.notes })
        }
      }
    });
  }

  /**
   * Get order statistics for dashboard
   */
  async getOrderStatistics(
    lineAccountId: string,
    dateFrom?: Date,
    dateTo?: Date
  ): Promise<{
    totalOrders: number;
    totalValue: number;
    statusBreakdown: Record<string, number>;
    averageOrderValue: number;
    topCustomers: Array<{ customerName: string; orderCount: number; totalValue: number }>;
  }> {
    try {
      const where: Prisma.OdooOrderWhereInput = {
        lineAccountId,
        ...(dateFrom || dateTo) && {
          createdAt: {
            ...(dateFrom && { gte: dateFrom }),
            ...(dateTo && { lte: dateTo })
          }
        }
      };

      const orders = await this.prisma.odooOrder.findMany({
        where,
        select: {
          status: true,
          totalAmount: true,
          customerName: true,
          customerRef: true
        }
      });

      const totalOrders = orders.length;
      const totalValue = orders.reduce((sum, order) => sum + Number(order.totalAmount), 0);
      const averageOrderValue = totalOrders > 0 ? totalValue / totalOrders : 0;

      // Status breakdown
      const statusBreakdown = orders.reduce((acc, order) => {
        acc[order.status] = (acc[order.status] || 0) + 1;
        return acc;
      }, {} as Record<string, number>);

      // Top customers
      const customerStats = orders.reduce((acc, order) => {
        const key = order.customerName || order.customerRef || 'Unknown';
        if (!acc[key]) {
          acc[key] = { customerName: key, orderCount: 0, totalValue: 0 };
        }
        acc[key].orderCount += 1;
        acc[key].totalValue += Number(order.totalAmount);
        return acc;
      }, {} as Record<string, { customerName: string; orderCount: number; totalValue: number }>);

      const topCustomers = Object.values(customerStats)
        .sort((a, b) => b.totalValue - a.totalValue)
        .slice(0, 10);

      return {
        totalOrders,
        totalValue,
        statusBreakdown,
        averageOrderValue,
        topCustomers
      };
    } catch (error) {
      this.handleError(error, 'OrderService.getOrderStatistics');
    }
  }

  /**
   * Search orders with advanced filters
   */
  async searchOrders(
    lineAccountId: string,
    searchQuery: string,
    filters: OrderFilters = {},
    pagination: PaginationOptions = { page: 1, limit: 20 }
  ): Promise<PaginatedOrders> {
    try {
      const enhancedFilters: OrderFilters = {
        ...filters,
        search: searchQuery
      };

      return await this.getOrders(lineAccountId, enhancedFilters, pagination);
    } catch (error) {
      this.handleError(error, 'OrderService.searchOrders');
    }
  }

  /**
   * Get orders by status
   */
  async getOrdersByStatus(
    lineAccountId: string,
    status: string,
    pagination: PaginationOptions = { page: 1, limit: 20 }
  ): Promise<PaginatedOrders> {
    try {
      const filters: OrderFilters = { status: [status] };
      return await this.getOrders(lineAccountId, filters, pagination);
    } catch (error) {
      this.handleError(error, 'OrderService.getOrdersByStatus');
    }
  }

  /**
   * Get recent orders
   */
  async getRecentOrders(
    lineAccountId: string,
    limit: number = 10
  ): Promise<OrderWithTimeline[]> {
    try {
      const result = await this.getOrders(
        lineAccountId,
        {},
        { page: 1, limit, sort: 'createdAt', order: 'desc' }
      );
      return result.data;
    } catch (error) {
      this.handleError(error, 'OrderService.getRecentOrders');
    }
  }
}