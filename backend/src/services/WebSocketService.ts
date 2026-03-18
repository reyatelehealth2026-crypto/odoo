/**
 * WebSocket Service for Dashboard Real-time Updates
 * 
 * Provides methods to broadcast real-time updates to connected dashboard clients.
 * Integrates with the DashboardWebSocketServer for event broadcasting.
 * 
 * Requirements: FR-1.4, BR-3.3
 */

import { DashboardWebSocketServer, DashboardUpdateEvent } from '../websocket/server';
import { BaseService } from './BaseService';
import { PrismaClient } from '@prisma/client';
import { logger } from '../utils/logger';

export interface DashboardMetricsUpdate {
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
    todayCount: number;
    successRate: number;
    failedCount: number;
    averageResponseTime: number;
  };
  customers: {
    totalActive: number;
    newToday: number;
    lineConnected: number;
    averageOrdersPerCustomer: number;
  };
  updatedAt: string;
}

export interface OrderStatusUpdate {
  orderId: string;
  oldStatus: string;
  newStatus: string;
  updatedBy: string;
  updatedAt: string;
  customerRef?: string;
  totalAmount?: number;
}

export interface PaymentProcessedUpdate {
  paymentId: string;
  orderId?: string;
  amount: number;
  status: 'matched' | 'processed' | 'failed';
  processedBy: string;
  processedAt: string;
  matchingRate?: number;
}

export interface WebhookReceivedUpdate {
  webhookId: string;
  type: string;
  status: 'success' | 'failed' | 'pending';
  responseTime: number;
  receivedAt: string;
  payload?: any;
}

export class WebSocketService extends BaseService {
  private webSocketServer: DashboardWebSocketServer | null = null;

  constructor(prisma: PrismaClient) {
    super(prisma);
  }

  /**
   * Set the WebSocket server instance
   */
  public setWebSocketServer(server: DashboardWebSocketServer): void {
    this.webSocketServer = server;
    logger.info('WebSocket server instance set in WebSocketService');
  }

  /**
   * Broadcast dashboard metrics update to all connected clients
   */
  public async broadcastMetricsUpdate(
    lineAccountId: string,
    metrics: DashboardMetricsUpdate
  ): Promise<void> {
    if (!this.webSocketServer) {
      logger.warn('WebSocket server not available for metrics update broadcast');
      return;
    }

    try {
      const event: DashboardUpdateEvent = {
        type: 'metrics_updated',
        data: metrics,
        lineAccountId,
        timestamp: Date.now(),
      };

      await this.webSocketServer.broadcastDashboardUpdate(event);
      
      logger.info('Dashboard metrics update broadcasted', {
        lineAccountId,
        updatedAt: metrics.updatedAt,
      });
    } catch (error) {
      logger.error('Failed to broadcast metrics update', {
        lineAccountId,
        error: String(error),
      });
    }
  }

  /**
   * Broadcast order status change to connected clients
   */
  public async broadcastOrderStatusChange(
    lineAccountId: string,
    orderUpdate: OrderStatusUpdate
  ): Promise<void> {
    if (!this.webSocketServer) {
      logger.warn('WebSocket server not available for order status broadcast');
      return;
    }

    try {
      const event: DashboardUpdateEvent = {
        type: 'order_status_changed',
        data: orderUpdate,
        lineAccountId,
        timestamp: Date.now(),
      };

      await this.webSocketServer.broadcastDashboardUpdate(event);
      
      logger.info('Order status change broadcasted', {
        lineAccountId,
        orderId: orderUpdate.orderId,
        oldStatus: orderUpdate.oldStatus,
        newStatus: orderUpdate.newStatus,
      });
    } catch (error) {
      logger.error('Failed to broadcast order status change', {
        lineAccountId,
        orderId: orderUpdate.orderId,
        error: String(error),
      });
    }
  }

  /**
   * Broadcast payment processing update to connected clients
   */
  public async broadcastPaymentProcessed(
    lineAccountId: string,
    paymentUpdate: PaymentProcessedUpdate
  ): Promise<void> {
    if (!this.webSocketServer) {
      logger.warn('WebSocket server not available for payment processed broadcast');
      return;
    }

    try {
      const event: DashboardUpdateEvent = {
        type: 'payment_processed',
        data: paymentUpdate,
        lineAccountId,
        timestamp: Date.now(),
      };

      await this.webSocketServer.broadcastDashboardUpdate(event);
      
      logger.info('Payment processed update broadcasted', {
        lineAccountId,
        paymentId: paymentUpdate.paymentId,
        status: paymentUpdate.status,
        amount: paymentUpdate.amount,
      });
    } catch (error) {
      logger.error('Failed to broadcast payment processed update', {
        lineAccountId,
        paymentId: paymentUpdate.paymentId,
        error: String(error),
      });
    }
  }

  /**
   * Broadcast webhook received update to connected clients
   */
  public async broadcastWebhookReceived(
    lineAccountId: string,
    webhookUpdate: WebhookReceivedUpdate
  ): Promise<void> {
    if (!this.webSocketServer) {
      logger.warn('WebSocket server not available for webhook received broadcast');
      return;
    }

    try {
      const event: DashboardUpdateEvent = {
        type: 'webhook_received',
        data: webhookUpdate,
        lineAccountId,
        timestamp: Date.now(),
      };

      await this.webSocketServer.broadcastDashboardUpdate(event);
      
      logger.info('Webhook received update broadcasted', {
        lineAccountId,
        webhookId: webhookUpdate.webhookId,
        type: webhookUpdate.type,
        status: webhookUpdate.status,
      });
    } catch (error) {
      logger.error('Failed to broadcast webhook received update', {
        lineAccountId,
        webhookId: webhookUpdate.webhookId,
        error: String(error),
      });
    }
  }

  /**
   * Get WebSocket connection statistics
   */
  public getConnectionStats(): {
    totalConnections: number;
    accountsConnected: number;
    connectionsByAccount: Array<{ accountId: string; connections: number }>;
  } | null {
    if (!this.webSocketServer) {
      return null;
    }

    return this.webSocketServer.getConnectionStats();
  }

  /**
   * Check if WebSocket server is available
   */
  public isWebSocketAvailable(): boolean {
    return this.webSocketServer !== null;
  }

  /**
   * Broadcast custom event to specific LINE account
   */
  public async broadcastCustomEvent(
    lineAccountId: string,
    eventType: string,
    data: any
  ): Promise<void> {
    if (!this.webSocketServer) {
      logger.warn('WebSocket server not available for custom event broadcast');
      return;
    }

    try {
      const event: DashboardUpdateEvent = {
        type: eventType as any,
        data,
        lineAccountId,
        timestamp: Date.now(),
      };

      await this.webSocketServer.broadcastDashboardUpdate(event);
      
      logger.info('Custom event broadcasted', {
        lineAccountId,
        eventType,
      });
    } catch (error) {
      logger.error('Failed to broadcast custom event', {
        lineAccountId,
        eventType,
        error: String(error),
      });
    }
  }

  /**
   * Schedule periodic dashboard metrics updates
   */
  public startPeriodicUpdates(intervalMs: number = 30000): NodeJS.Timeout {
    logger.info('Starting periodic dashboard updates', { intervalMs });

    return setInterval(async () => {
      try {
        // Get all active LINE accounts
        const activeAccounts = await this.prisma.user.findMany({
          where: { isActive: true },
          select: { lineAccountId: true },
          distinct: ['lineAccountId'],
        });

        // Broadcast updates for each account
        for (const account of activeAccounts) {
          // In a real implementation, you would fetch actual metrics here
          const mockMetrics: DashboardMetricsUpdate = {
            orders: {
              todayCount: Math.floor(Math.random() * 100),
              todayTotal: Math.floor(Math.random() * 50000),
              pendingCount: Math.floor(Math.random() * 20),
              completedCount: Math.floor(Math.random() * 80),
              averageOrderValue: Math.floor(Math.random() * 1000) + 500,
            },
            payments: {
              pendingSlips: Math.floor(Math.random() * 10),
              processedToday: Math.floor(Math.random() * 50),
              matchingRate: Math.floor(Math.random() * 20) + 80,
              totalAmount: Math.floor(Math.random() * 100000),
              averageProcessingTime: Math.floor(Math.random() * 30) + 5,
            },
            webhooks: {
              todayCount: Math.floor(Math.random() * 200),
              successRate: Math.floor(Math.random() * 10) + 90,
              failedCount: Math.floor(Math.random() * 10),
              averageResponseTime: Math.floor(Math.random() * 500) + 100,
            },
            customers: {
              totalActive: Math.floor(Math.random() * 1000) + 500,
              newToday: Math.floor(Math.random() * 20),
              lineConnected: Math.floor(Math.random() * 800) + 400,
              averageOrdersPerCustomer: Math.floor(Math.random() * 5) + 2,
            },
            updatedAt: new Date().toISOString(),
          };

          await this.broadcastMetricsUpdate(account.lineAccountId, mockMetrics);
        }

        logger.debug('Periodic dashboard updates completed', {
          accountsUpdated: activeAccounts.length,
        });
      } catch (error) {
        logger.error('Failed to send periodic dashboard updates', {
          error: String(error),
        });
      }
    }, intervalMs);
  }
}