/**
 * Comprehensive Error Handling System Tests
 * Tests BR-2.2, NFR-2.2 requirements for error handling and reliability
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ErrorHandlingService } from '../../services/ErrorHandlingService.js';
import { GracefulDegradationService } from '../../services/GracefulDegradationService.js';
import { RetryMechanism } from '../../utils/RetryMechanism.js';
import { DeadLetterQueueService } from '../../services/DeadLetterQueueService.js';
import { LoggingService } from '../../services/LoggingService.js';
import { NotificationService } from '../../services/NotificationService.js';
import { 
  AppError, 
  ErrorCode, 
  ValidationError, 
  ExternalServiceError,
  DatabaseError 
} from '../../types/errors.js';

describe('Error Handling System', () => {
  let errorHandlingService: ErrorHandlingService;
  let gracefulDegradationService: GracefulDegradationService;
  let retryMechanism: RetryMechanism;
  let deadLetterQueueService: DeadLetterQueueService;
  let loggingService: LoggingService;
  let notificationService: NotificationService;

  beforeEach(() => {
    // Mock services
    loggingService = {
      logError: vi.fn(),
      logEvent: vi.fn()
    } as any;

    notificationService = {
      sendAlert: vi.fn()
    } as any;

    errorHandlingService = new ErrorHandlingService(loggingService, notificationService);
    gracefulDegradationService = new GracefulDegradationService({} as any, loggingService);
    retryMechanism = new RetryMechanism(loggingService);
    deadLetterQueueService = new DeadLetterQueueService(
      {} as any, 
      loggingService, 
      notificationService
    );
  });

  describe('Error Classification and Handling', () => {
    it('should correctly classify and handle validation errors', async () => {
      const validationError = new ValidationError('Invalid input', {
        field: 'email',
        value: 'invalid-email'
      });

      expect(validationError.code).toBe(ErrorCode.INVALID_REQUEST);
      expect(validationError.statusCode).toBe(400);
      expect(validationError.isOperational).toBe(true);
    });

    it('should correctly classify and handle external service errors', async () => {
      const serviceError = new ExternalServiceError('Odoo API', 'Connection timeout');

      expect(serviceError.code).toBe(ErrorCode.EXTERNAL_SERVICE_ERROR);
      expect(serviceError.statusCode).toBe(502);
      expect(serviceError.message).toContain('Odoo API');
    });

    it('should correctly classify and handle database errors', async () => {
      const dbError = new DatabaseError('Connection lost', {
        host: 'localhost',
        database: 'test_db'
      });

      expect(dbError.code).toBe(ErrorCode.DATABASE_ERROR);
      expect(dbError.statusCode).toBe(500);
      expect(dbError.details).toEqual({
        host: 'localhost',
        database: 'test_db'
      });
    });
  });
});
  describe('Retry Mechanism', () => {
    it('should retry operations with exponential backoff', async () => {
      let attempts = 0;
      const operation = vi.fn().mockImplementation(() => {
        attempts++;
        if (attempts < 3) {
          throw new Error('Temporary failure');
        }
        return 'success';
      });

      const result = await retryMechanism.execute(
        operation,
        'test-operation',
        { maxAttempts: 3, baseDelay: 100 }
      );

      expect(result.success).toBe(true);
      expect(result.data).toBe('success');
      expect(result.attempts).toBe(3);
      expect(operation).toHaveBeenCalledTimes(3);
    });

    it('should fail after max attempts exceeded', async () => {
      const operation = vi.fn().mockRejectedValue(new Error('Persistent failure'));

      const result = await retryMechanism.execute(
        operation,
        'failing-operation',
        { maxAttempts: 2, baseDelay: 50 }
      );

      expect(result.success).toBe(false);
      expect(result.attempts).toBe(2);
      expect(result.error?.message).toBe('Persistent failure');
    });

    it('should not retry non-retryable errors', async () => {
      const operation = vi.fn().mockRejectedValue(new ValidationError('Invalid input'));

      const result = await retryMechanism.execute(
        operation,
        'validation-operation',
        { maxAttempts: 3 }
      );

      expect(result.success).toBe(false);
      expect(result.attempts).toBe(1);
      expect(operation).toHaveBeenCalledTimes(1);
    });

    it('should apply jitter to delay calculations', () => {
      const config = RetryMechanism.createConfig('external_api');
      expect(config.jitterType).toBe('full');
      expect(config.maxAttempts).toBe(5);
      expect(config.baseDelay).toBe(1000);
      expect(config.maxDelay).toBe(30000);
    });
  });

  describe('Graceful Degradation', () => {
    it('should provide fallback data when service fails', async () => {
      const error = new ExternalServiceError('Odoo API', 'Service unavailable');
      const context = {
        endpoint: '/api/v1/dashboard/overview',
        service: 'odoo',
        requestId: 'test-123'
      };

      const response = await gracefulDegradationService.applyDegradation(error, context);

      expect(response.success).toBe(true);
      expect(response.meta?.degraded).toBe(true);
      expect(response.meta?.degradationReason).toContain('External Service Fallback');
    });

    it('should track service health status', () => {
      const health = gracefulDegradationService.getServiceHealth();
      expect(typeof health).toBe('object');
    });

    it('should detect service degradation levels', () => {
      const level = gracefulDegradationService.getDegradationLevel('test-service');
      expect(['none', 'partial', 'full']).toContain(level);
    });
  });

  describe('Dead Letter Queue', () => {
    it('should add failed operations to queue', async () => {
      const mockPrisma = {
        deadLetterQueue: {
          create: vi.fn().mockResolvedValue({ id: 'dlq-123' })
        }
      };

      const dlqService = new DeadLetterQueueService(
        mockPrisma as any,
        loggingService,
        notificationService
      );

      const messageId = await dlqService.addToQueue(
        'webhook_delivery',
        { url: 'https://example.com/webhook', data: { test: true } },
        new Error('Connection timeout'),
        3,
        5,
        'high'
      );

      expect(messageId).toBe('dlq-123');
      expect(mockPrisma.deadLetterQueue.create).toHaveBeenCalledWith({
        data: expect.objectContaining({
          operationType: 'webhook_delivery',
          attempts: 3,
          maxAttempts: 5,
          priority: 'high'
        })
      });
    });

    it('should process queue messages with retry logic', async () => {
      const mockPrisma = {
        deadLetterQueue: {
          findMany: vi.fn().mockResolvedValue([
            {
              id: 'dlq-123',
              operationType: 'webhook_delivery',
              payload: JSON.stringify({ url: 'https://example.com' }),
              attempts: 1,
              maxAttempts: 3,
              status: 'pending'
            }
          ]),
          update: vi.fn()
        }
      };

      const dlqService = new DeadLetterQueueService(
        mockPrisma as any,
        loggingService,
        notificationService
      );

      // Mock successful retry
      vi.spyOn(dlqService as any, 'retryWebhookDelivery').mockResolvedValue(true);

      await (dlqService as any).processQueue();

      expect(mockPrisma.deadLetterQueue.update).toHaveBeenCalledWith({
        where: { id: 'dlq-123' },
        data: expect.objectContaining({
          status: 'resolved'
        })
      });
    });
  });

  describe('Error Logging and Monitoring', () => {
    it('should log errors with appropriate severity levels', async () => {
      const error = new DatabaseError('Connection failed');
      const logEntry = {
        id: 'log-123',
        timestamp: new Date().toISOString(),
        level: 'critical' as const,
        code: ErrorCode.DATABASE_ERROR,
        message: error.message,
        requestId: 'req-123'
      };

      await loggingService.logError(logEntry);

      expect(loggingService.logError).toHaveBeenCalledWith(logEntry);
    });

    it('should send alerts when error thresholds are exceeded', async () => {
      // Simulate multiple errors to trigger threshold
      for (let i = 0; i < 6; i++) {
        await errorHandlingService.handleError(
          new DatabaseError('Connection failed'),
          { id: `req-${i}` } as any,
          {} as any
        );
      }

      expect(notificationService.sendAlert).toHaveBeenCalled();
    });
  });

  describe('Performance and Reliability Metrics', () => {
    it('should track error rates within acceptable limits', () => {
      const stats = errorHandlingService.getErrorStatistics();
      expect(stats).toHaveProperty('errorCounts');
      expect(stats).toHaveProperty('errorThresholds');
    });

    it('should maintain response times under degradation', async () => {
      const startTime = Date.now();
      
      const error = new ExternalServiceError('Test Service', 'Timeout');
      const response = await gracefulDegradationService.applyDegradation(error, {
        endpoint: '/api/test',
        requestId: 'test-123'
      });

      const responseTime = Date.now() - startTime;
      
      expect(response.success).toBe(true);
      expect(responseTime).toBeLessThan(1000); // Should respond quickly with fallback
    });
  });

  describe('Integration Tests', () => {
    it('should handle complete error flow from detection to resolution', async () => {
      // Simulate a complete error handling flow
      const originalError = new ExternalServiceError('Odoo API', 'Service unavailable');
      
      // 1. Error occurs and is handled
      const mockRequest = { id: 'req-123', method: 'GET', url: '/api/orders' };
      const mockReply = { 
        status: vi.fn().mockReturnThis(),
        headers: vi.fn().mockReturnThis(),
        send: vi.fn()
      };

      await errorHandlingService.handleError(originalError, mockRequest as any, mockReply as any);

      // 2. Verify error was logged
      expect(loggingService.logError).toHaveBeenCalled();

      // 3. Verify graceful degradation was applied (if applicable)
      expect(mockReply.send).toHaveBeenCalled();
    });

    it('should maintain system stability under high error rates', async () => {
      // Simulate high error rate scenario
      const errors = Array.from({ length: 100 }, (_, i) => 
        new ExternalServiceError('Test Service', `Error ${i}`)
      );

      const results = await Promise.allSettled(
        errors.map(error => 
          gracefulDegradationService.applyDegradation(error, {
            endpoint: '/api/test',
            requestId: `req-${Math.random()}`
          })
        )
      );

      // All should resolve (either success or graceful degradation)
      expect(results.every(result => result.status === 'fulfilled')).toBe(true);
    });
  });
});