/**
 * Dead Letter Queue Service
 * Implements BR-2.3 requirements for handling failed operations
 */

import { PrismaClient } from '@prisma/client';
import { LoggingService } from './LoggingService.js';
import { NotificationService } from './NotificationService.js';
import { ErrorSeverity } from '../types/errors.js';

export interface DeadLetterMessage {
  id: string;
  operationType: string;
  payload: Record<string, any>;
  originalError: string;
  attempts: number;
  maxAttempts: number;
  firstFailedAt: Date;
  lastAttemptAt: Date;
  nextRetryAt?: Date;
  status: 'pending' | 'processing' | 'failed' | 'resolved';
  priority: 'low' | 'medium' | 'high' | 'critical';
  metadata?: Record<string, any>;
}

export interface DLQConfig {
  maxRetries: number;
  retryDelayMs: number;
  batchSize: number;
  processingIntervalMs: number;
  alertThreshold: number;
}

export class DeadLetterQueueService {
  private prisma: PrismaClient;
  private loggingService: LoggingService;
  private notificationService: NotificationService;
  private config: DLQConfig;
  private processingInterval?: NodeJS.Timeout;
  private isProcessing = false;

  constructor(
    prisma: PrismaClient,
    loggingService: LoggingService,
    notificationService: NotificationService,
    config?: Partial<DLQConfig>
  ) {
    this.prisma = prisma;
    this.loggingService = loggingService;
    this.notificationService = notificationService;
    this.config = {
      maxRetries: 5,
      retryDelayMs: 300000, // 5 minutes
      batchSize: 10,
      processingIntervalMs: 60000, // 1 minute
      alertThreshold: 50,
      ...config
    };
  }

  /**
   * Start the dead letter queue processor
   */
  start(): void {
    if (this.processingInterval) {
      return; // Already started
    }

    this.processingInterval = setInterval(
      () => this.processQueue(),
      this.config.processingIntervalMs
    );

    this.loggingService.logEvent('info', 'Dead Letter Queue processor started', {
      config: this.config
    });
  }

  /**
   * Stop the dead letter queue processor
   */
  stop(): void {
    if (this.processingInterval) {
      clearInterval(this.processingInterval);
      this.processingInterval = undefined;
    }

    this.loggingService.logEvent('info', 'Dead Letter Queue processor stopped');
  }

  /**
   * Add failed operation to dead letter queue
   */
  async addToQueue(
    operationType: string,
    payload: Record<string, any>,
    error: Error,
    attempts: number,
    maxAttempts: number,
    priority: DeadLetterMessage['priority'] = 'medium',
    metadata?: Record<string, any>
  ): Promise<string> {
    const messageId = `dlq-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    const now = new Date();

    const message: Omit<DeadLetterMessage, 'id'> = {
      operationType,
      payload,
      originalError: error.message,
      attempts,
      maxAttempts,
      firstFailedAt: now,
      lastAttemptAt: now,
      nextRetryAt: new Date(now.getTime() + this.config.retryDelayMs),
      status: 'pending',
      priority,
      metadata
    };

    try {
      await this.prisma.deadLetterQueue.create({
        data: {
          id: messageId,
          operationType: message.operationType,
          payload: JSON.stringify(message.payload),
          originalError: message.originalError,
          attempts: message.attempts,
          maxAttempts: message.maxAttempts,
          firstFailedAt: message.firstFailedAt,
          lastAttemptAt: message.lastAttemptAt,
          nextRetryAt: message.nextRetryAt,
          status: message.status,
          priority: message.priority,
          metadata: message.metadata ? JSON.stringify(message.metadata) : null
        }
      });

      await this.loggingService.logEvent('warn', `Operation added to dead letter queue: ${operationType}`, {
        messageId,
        operationType,
        attempts,
        maxAttempts,
        error: error.message,
        priority
      });

      // Check if we need to send alerts
      await this.checkAlertThreshold();

      return messageId;

    } catch (dbError) {
      await this.loggingService.logEvent('error', 'Failed to add message to dead letter queue', {
        operationType,
        error: error.message,
        dbError: (dbError as Error).message
      });
      throw dbError;
    }
  }

  /**
   * Process pending messages in the queue
   */
  private async processQueue(): Promise<void> {
    if (this.isProcessing) {
      return; // Already processing
    }

    this.isProcessing = true;

    try {
      const pendingMessages = await this.getPendingMessages();
      
      if (pendingMessages.length === 0) {
        return;
      }

      await this.loggingService.logEvent('info', `Processing ${pendingMessages.length} dead letter queue messages`);

      for (const message of pendingMessages) {
        await this.processMessage(message);
      }

    } catch (error) {
      await this.loggingService.logEvent('error', 'Error processing dead letter queue', {
        error: (error as Error).message
      });
    } finally {
      this.isProcessing = false;
    }
  }

  /**
   * Get pending messages ready for retry
   */
  private async getPendingMessages(): Promise<DeadLetterMessage[]> {
    const now = new Date();

    const messages = await this.prisma.deadLetterQueue.findMany({
      where: {
        status: 'pending',
        nextRetryAt: {
          lte: now
        }
      },
      orderBy: [
        { priority: 'desc' },
        { firstFailedAt: 'asc' }
      ],
      take: this.config.batchSize
    });

    return messages.map(msg => ({
      id: msg.id,
      operationType: msg.operationType,
      payload: JSON.parse(msg.payload),
      originalError: msg.originalError,
      attempts: msg.attempts,
      maxAttempts: msg.maxAttempts,
      firstFailedAt: msg.firstFailedAt,
      lastAttemptAt: msg.lastAttemptAt,
      nextRetryAt: msg.nextRetryAt || undefined,
      status: msg.status as DeadLetterMessage['status'],
      priority: msg.priority as DeadLetterMessage['priority'],
      metadata: msg.metadata ? JSON.parse(msg.metadata) : undefined
    }));
  }

  /**
   * Process individual message
   */
  private async processMessage(message: DeadLetterMessage): Promise<void> {
    try {
      // Mark as processing
      await this.updateMessageStatus(message.id, 'processing');

      // Attempt to reprocess the operation
      const success = await this.retryOperation(message);

      if (success) {
        // Mark as resolved
        await this.updateMessageStatus(message.id, 'resolved');
        
        await this.loggingService.logEvent('info', `Dead letter queue message resolved: ${message.operationType}`, {
          messageId: message.id,
          attempts: message.attempts + 1
        });
      } else {
        // Increment attempts and check if we should give up
        const newAttempts = message.attempts + 1;
        
        if (newAttempts >= message.maxAttempts) {
          // Mark as permanently failed
          await this.updateMessageStatus(message.id, 'failed');
          
          await this.loggingService.logEvent('error', `Dead letter queue message permanently failed: ${message.operationType}`, {
            messageId: message.id,
            totalAttempts: newAttempts,
            maxAttempts: message.maxAttempts
          });

          // Send alert for permanent failure
          await this.notificationService.sendAlert({
            type: 'system_health',
            severity: ErrorSeverity.HIGH,
            message: `Dead letter queue message permanently failed: ${message.operationType}`,
            details: {
              messageId: message.id,
              operationType: message.operationType,
              totalAttempts: newAttempts,
              originalError: message.originalError
            }
          });
        } else {
          // Schedule next retry
          const nextRetryAt = new Date(Date.now() + this.config.retryDelayMs * Math.pow(2, newAttempts - 1));
          
          await this.prisma.deadLetterQueue.update({
            where: { id: message.id },
            data: {
              attempts: newAttempts,
              lastAttemptAt: new Date(),
              nextRetryAt,
              status: 'pending'
            }
          });
        }
      }

    } catch (error) {
      await this.loggingService.logEvent('error', `Error processing dead letter queue message: ${message.id}`, {
        messageId: message.id,
        error: (error as Error).message
      });

      // Reset to pending status for next attempt
      await this.updateMessageStatus(message.id, 'pending');
    }
  }

  /**
   * Retry the original operation
   */
  private async retryOperation(message: DeadLetterMessage): Promise<boolean> {
    try {
      // This is where you would implement the actual retry logic
      // For now, we'll simulate different operation types
      
      switch (message.operationType) {
        case 'webhook_delivery':
          return await this.retryWebhookDelivery(message.payload);
        
        case 'payment_processing':
          return await this.retryPaymentProcessing(message.payload);
        
        case 'notification_send':
          return await this.retryNotificationSend(message.payload);
        
        case 'data_sync':
          return await this.retryDataSync(message.payload);
        
        default:
          await this.loggingService.logEvent('warn', `Unknown operation type in dead letter queue: ${message.operationType}`);
          return false;
      }

    } catch (error) {
      await this.loggingService.logEvent('error', `Retry operation failed: ${message.operationType}`, {
        messageId: message.id,
        error: (error as Error).message
      });
      return false;
    }
  }

  /**
   * Retry webhook delivery
   */
  private async retryWebhookDelivery(payload: Record<string, any>): Promise<boolean> {
    // Implement webhook retry logic
    // This would typically involve making HTTP requests to webhook endpoints
    return Math.random() > 0.3; // Simulate 70% success rate
  }

  /**
   * Retry payment processing
   */
  private async retryPaymentProcessing(payload: Record<string, any>): Promise<boolean> {
    // Implement payment retry logic
    // This would typically involve calling payment gateway APIs
    return Math.random() > 0.2; // Simulate 80% success rate
  }

  /**
   * Retry notification send
   */
  private async retryNotificationSend(payload: Record<string, any>): Promise<boolean> {
    // Implement notification retry logic
    // This would typically involve calling notification services
    return Math.random() > 0.1; // Simulate 90% success rate
  }

  /**
   * Retry data synchronization
   */
  private async retryDataSync(payload: Record<string, any>): Promise<boolean> {
    // Implement data sync retry logic
    // This would typically involve database operations or API calls
    return Math.random() > 0.4; // Simulate 60% success rate
  }

  /**
   * Update message status
   */
  private async updateMessageStatus(messageId: string, status: DeadLetterMessage['status']): Promise<void> {
    await this.prisma.deadLetterQueue.update({
      where: { id: messageId },
      data: { 
        status,
        lastAttemptAt: new Date()
      }
    });
  }

  /**
   * Check if alert threshold is exceeded
   */
  private async checkAlertThreshold(): Promise<void> {
    const pendingCount = await this.prisma.deadLetterQueue.count({
      where: { status: 'pending' }
    });

    if (pendingCount >= this.config.alertThreshold) {
      await this.notificationService.sendAlert({
        type: 'system_health',
        severity: ErrorSeverity.HIGH,
        message: `Dead letter queue threshold exceeded: ${pendingCount} pending messages`,
        details: {
          pendingCount,
          threshold: this.config.alertThreshold
        }
      });
    }
  }

  /**
   * Get queue statistics
   */
  async getQueueStatistics(): Promise<{
    pending: number;
    processing: number;
    failed: number;
    resolved: number;
    total: number;
    oldestPending?: Date;
    averageRetryTime?: number;
  }> {
    const [statusCounts, oldestPending, avgRetryTime] = await Promise.all([
      this.prisma.deadLetterQueue.groupBy({
        by: ['status'],
        _count: { status: true }
      }),
      this.prisma.deadLetterQueue.findFirst({
        where: { status: 'pending' },
        orderBy: { firstFailedAt: 'asc' },
        select: { firstFailedAt: true }
      }),
      this.prisma.deadLetterQueue.aggregate({
        _avg: {
          attempts: true
        },
        where: {
          status: 'resolved'
        }
      })
    ]);

    const stats = {
      pending: 0,
      processing: 0,
      failed: 0,
      resolved: 0,
      total: 0,
      oldestPending: oldestPending?.firstFailedAt,
      averageRetryTime: avgRetryTime._avg.attempts || 0
    };

    for (const count of statusCounts) {
      stats[count.status as keyof typeof stats] = count._count.status;
      stats.total += count._count.status;
    }

    return stats;
  }

  /**
   * Manually retry specific message
   */
  async manualRetry(messageId: string): Promise<boolean> {
    const message = await this.prisma.deadLetterQueue.findUnique({
      where: { id: messageId }
    });

    if (!message) {
      throw new Error(`Dead letter queue message not found: ${messageId}`);
    }

    const dlqMessage: DeadLetterMessage = {
      id: message.id,
      operationType: message.operationType,
      payload: JSON.parse(message.payload),
      originalError: message.originalError,
      attempts: message.attempts,
      maxAttempts: message.maxAttempts,
      firstFailedAt: message.firstFailedAt,
      lastAttemptAt: message.lastAttemptAt,
      nextRetryAt: message.nextRetryAt || undefined,
      status: message.status as DeadLetterMessage['status'],
      priority: message.priority as DeadLetterMessage['priority'],
      metadata: message.metadata ? JSON.parse(message.metadata) : undefined
    };

    await this.processMessage(dlqMessage);
    return true;
  }

  /**
   * Clear resolved messages older than specified days
   */
  async cleanupResolvedMessages(olderThanDays: number = 7): Promise<number> {
    const cutoffDate = new Date();
    cutoffDate.setDate(cutoffDate.getDate() - olderThanDays);

    const result = await this.prisma.deadLetterQueue.deleteMany({
      where: {
        status: 'resolved',
        lastAttemptAt: {
          lt: cutoffDate
        }
      }
    });

    await this.loggingService.logEvent('info', `Cleaned up ${result.count} resolved dead letter queue messages`);
    
    return result.count;
  }
}