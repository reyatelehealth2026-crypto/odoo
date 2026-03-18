import { CacheService } from './CacheService';
import { logger } from '@/utils/logger';

export interface CacheInvalidationEvent {
  type: 'order_updated' | 'payment_processed' | 'webhook_received' | 'customer_updated';
  resourceId: string;
  resourceType: string;
  affectedCacheKeys: string[];
  timestamp: Date;
  metadata?: Record<string, any>;
}

export class CacheInvalidationService {
  private eventHandlers: Map<string, ((event: CacheInvalidationEvent) => Promise<void>)[]> = new Map();

  constructor(private cacheService: CacheService) {
    this.setupDefaultHandlers();
  }

  async invalidate(event: CacheInvalidationEvent): Promise<void> {
    logger.info('Processing cache invalidation event', {
      type: event.type,
      resourceId: event.resourceId,
      resourceType: event.resourceType,
      affectedKeys: event.affectedCacheKeys.length,
    });

    // Invalidate specific cache keys
    const invalidationPromises = event.affectedCacheKeys.map(key => 
      this.cacheService.delete(key)
    );

    await Promise.allSettled(invalidationPromises);

    // Run event-specific handlers
    const handlers = this.eventHandlers.get(event.type) || [];
    const handlerPromises = handlers.map(handler => handler(event));
    
    await Promise.allSettled(handlerPromises);

    logger.info('Cache invalidation completed', {
      type: event.type,
      resourceId: event.resourceId,
      invalidatedKeys: event.affectedCacheKeys.length,
    });
  }

  onEvent(
    eventType: CacheInvalidationEvent['type'],
    handler: (event: CacheInvalidationEvent) => Promise<void>
  ): void {
    if (!this.eventHandlers.has(eventType)) {
      this.eventHandlers.set(eventType, []);
    }
    
    this.eventHandlers.get(eventType)!.push(handler);
  }

  private setupDefaultHandlers(): void {
    // Order update handler
    this.onEvent('order_updated', async (event) => {
      const patterns = [
        `dashboard:metrics:*`,
        `orders:*`,
        `orders:${event.resourceId}:*`,
        `customer:${event.metadata?.['customerId']}:orders:*`,
      ];

      for (const pattern of patterns) {
        await this.cacheService.invalidatePattern(pattern);
      }
    });

    // Payment processed handler
    this.onEvent('payment_processed', async (event) => {
      const patterns = [
        `dashboard:metrics:*`,
        `payments:*`,
        `orders:${event.metadata?.['orderId']}:*`,
      ];

      for (const pattern of patterns) {
        await this.cacheService.invalidatePattern(pattern);
      }
    });

    // Webhook received handler
    this.onEvent('webhook_received', async () => {
      const patterns = [
        `dashboard:metrics:*`,
        `webhooks:stats:*`,
      ];

      for (const pattern of patterns) {
        await this.cacheService.invalidatePattern(pattern);
      }
    });

    // Customer updated handler
    this.onEvent('customer_updated', async (event) => {
      const patterns = [
        `customers:*`,
        `customer:${event.resourceId}:*`,
        `dashboard:metrics:customers:*`,
      ];

      for (const pattern of patterns) {
        await this.cacheService.invalidatePattern(pattern);
      }
    });
  }

  // Helper methods to create invalidation events
  static createOrderUpdateEvent(
    orderId: string,
    customerId?: string,
    metadata?: Record<string, any>
  ): CacheInvalidationEvent {
    return {
      type: 'order_updated',
      resourceId: orderId,
      resourceType: 'order',
      affectedCacheKeys: [
        `orders:${orderId}`,
        `orders:${orderId}:details`,
        `orders:${orderId}:timeline`,
      ],
      timestamp: new Date(),
      metadata: { customerId, ...metadata },
    };
  }

  static createPaymentProcessedEvent(
    paymentId: string,
    orderId?: string,
    metadata?: Record<string, any>
  ): CacheInvalidationEvent {
    return {
      type: 'payment_processed',
      resourceId: paymentId,
      resourceType: 'payment',
      affectedCacheKeys: [
        `payments:${paymentId}`,
        `payments:pending`,
        `payments:processed`,
      ],
      timestamp: new Date(),
      metadata: { orderId, ...metadata },
    };
  }

  static createWebhookReceivedEvent(
    webhookId: string,
    metadata?: Record<string, any>
  ): CacheInvalidationEvent {
    return {
      type: 'webhook_received',
      resourceId: webhookId,
      resourceType: 'webhook',
      affectedCacheKeys: [
        `webhooks:${webhookId}`,
        `webhooks:recent`,
        `webhooks:stats`,
      ],
      timestamp: new Date(),
      ...(metadata && { metadata }),
    };
  }

  static createCustomerUpdatedEvent(
    customerId: string,
    metadata?: Record<string, any>
  ): CacheInvalidationEvent {
    return {
      type: 'customer_updated',
      resourceId: customerId,
      resourceType: 'customer',
      affectedCacheKeys: [
        `customer:${customerId}`,
        `customer:${customerId}:profile`,
        `customer:${customerId}:orders`,
      ],
      timestamp: new Date(),
      ...(metadata && { metadata }),
    };
  }
}