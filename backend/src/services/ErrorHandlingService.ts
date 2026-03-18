/**
 * Comprehensive Error Handling Service
 * Implements BR-2.2, NFR-2.2 requirements for error handling and monitoring
 */

import { FastifyRequest, FastifyReply } from 'fastify';
import { 
  AppError, 
  APIResponse, 
  ErrorCode, 
  ErrorSeverity, 
  ErrorLogEntry 
} from '../types/errors.js';
import { LoggingService } from './LoggingService.js';
import { NotificationService } from './NotificationService.js';
import { v4 as uuidv4 } from 'uuid';

export class ErrorHandlingService {
  private loggingService: LoggingService;
  private notificationService: NotificationService;
  private errorThresholds: Map<ErrorCode, number> = new Map();
  private errorCounts: Map<ErrorCode, number> = new Map();

  constructor(
    loggingService: LoggingService,
    notificationService: NotificationService
  ) {
    this.loggingService = loggingService;
    this.notificationService = notificationService;
    this.initializeErrorThresholds();
  }

  /**
   * Initialize error thresholds for alerting
   */
  private initializeErrorThresholds(): void {
    this.errorThresholds.set(ErrorCode.DATABASE_ERROR, 5);
    this.errorThresholds.set(ErrorCode.EXTERNAL_SERVICE_ERROR, 10);
    this.errorThresholds.set(ErrorCode.CACHE_ERROR, 15);
    this.errorThresholds.set(ErrorCode.CIRCUIT_BREAKER_OPEN, 3);
    this.errorThresholds.set(ErrorCode.RATE_LIMIT_EXCEEDED, 50);
  }

  /**
   * Handle application errors and return standardized response
   */
  async handleError(
    error: Error,
    request: FastifyRequest,
    reply: FastifyReply
  ): Promise<void> {
    const requestId = request.id || uuidv4();
    const startTime = Date.now();

    try {
      let appError: AppError;

      // Convert unknown errors to AppError
      if (error instanceof AppError) {
        appError = error;
      } else {
        appError = new AppError(
          ErrorCode.DATABASE_ERROR,
          error.message || 'Internal server error',
          500,
          { originalError: error.name },
          false
        );
      }

      // Log the error
      await this.logError(appError, request, requestId);

      // Check error thresholds and send alerts if needed
      await this.checkErrorThresholds(appError.code);

      // Create standardized error response
      const errorResponse: APIResponse = {
        success: false,
        error: {
          code: appError.code,
          message: this.sanitizeErrorMessage(appError.message, appError.isOperational),
          details: appError.isOperational ? appError.details : undefined,
          timestamp: new Date().toISOString(),
          requestId,
          traceId: this.generateTraceId(requestId)
        },
        meta: {
          requestId,
          processingTime: Date.now() - startTime
        }
      };

      // Set appropriate status code
      reply.status(appError.statusCode);

      // Add security headers
      reply.headers({
        'X-Content-Type-Options': 'nosniff',
        'X-Frame-Options': 'DENY',
        'X-XSS-Protection': '1; mode=block'
      });

      reply.send(errorResponse);

    } catch (handlingError) {
      // Fallback error handling
      console.error('Error in error handler:', handlingError);
      
      reply.status(500).send({
        success: false,
        error: {
          code: ErrorCode.DATABASE_ERROR,
          message: 'Internal server error',
          timestamp: new Date().toISOString(),
          requestId
        }
      });
    }
  }

  /**
   * Log error with appropriate severity and details
   */
  private async logError(
    error: AppError,
    request: FastifyRequest,
    requestId: string
  ): Promise<void> {
    const severity = this.determineErrorSeverity(error);
    
    const logEntry: ErrorLogEntry = {
      id: uuidv4(),
      timestamp: new Date().toISOString(),
      level: severity,
      code: error.code,
      message: error.message,
      stack: error.stack,
      details: error.details,
      requestId,
      userId: (request as any).user?.id,
      endpoint: `${request.method} ${request.url}`,
      userAgent: request.headers['user-agent'],
      ipAddress: request.ip
    };

    await this.loggingService.logError(logEntry);

    // Log to console for development
    if (process.env.NODE_ENV === 'development') {
      console.error(`[${severity.toUpperCase()}] ${error.code}: ${error.message}`, {
        requestId,
        endpoint: logEntry.endpoint,
        stack: error.stack
      });
    }
  }

  /**
   * Determine error severity based on error code and context
   */
  private determineErrorSeverity(error: AppError): ErrorSeverity {
    switch (error.code) {
      case ErrorCode.DATABASE_ERROR:
      case ErrorCode.CIRCUIT_BREAKER_OPEN:
        return ErrorSeverity.CRITICAL;
      
      case ErrorCode.EXTERNAL_SERVICE_ERROR:
      case ErrorCode.CACHE_ERROR:
        return ErrorSeverity.HIGH;
      
      case ErrorCode.WEBHOOK_PROCESSING_FAILED:
      case ErrorCode.SERVICE_UNAVAILABLE:
        return ErrorSeverity.MEDIUM;
      
      default:
        return ErrorSeverity.LOW;
    }
  }

  /**
   * Sanitize error messages for client consumption
   */
  private sanitizeErrorMessage(message: string, isOperational: boolean): string {
    if (!isOperational) {
      return 'An internal error occurred. Please try again later.';
    }

    // Remove sensitive information from error messages
    return message
      .replace(/password/gi, '[REDACTED]')
      .replace(/token/gi, '[REDACTED]')
      .replace(/key/gi, '[REDACTED]')
      .replace(/secret/gi, '[REDACTED]');
  }

  /**
   * Generate trace ID for error tracking
   */
  private generateTraceId(requestId: string): string {
    return `trace-${requestId}-${Date.now()}`;
  }

  /**
   * Check error thresholds and send alerts
   */
  private async checkErrorThresholds(errorCode: ErrorCode): Promise<void> {
    const threshold = this.errorThresholds.get(errorCode);
    if (!threshold) return;

    const currentCount = (this.errorCounts.get(errorCode) || 0) + 1;
    this.errorCounts.set(errorCode, currentCount);

    if (currentCount >= threshold) {
      await this.sendErrorAlert(errorCode, currentCount, threshold);
      // Reset counter after alert
      this.errorCounts.set(errorCode, 0);
    }

    // Reset counters every hour
    setTimeout(() => {
      this.errorCounts.clear();
    }, 60 * 60 * 1000);
  }

  /**
   * Send error alerts to administrators
   */
  private async sendErrorAlert(
    errorCode: ErrorCode,
    count: number,
    threshold: number
  ): Promise<void> {
    const alertMessage = `🚨 Error Alert: ${errorCode} has occurred ${count} times (threshold: ${threshold})`;
    
    try {
      await this.notificationService.sendAlert({
        type: 'error_threshold',
        severity: ErrorSeverity.HIGH,
        message: alertMessage,
        details: {
          errorCode,
          count,
          threshold,
          timestamp: new Date().toISOString()
        }
      });
    } catch (notificationError) {
      console.error('Failed to send error alert:', notificationError);
    }
  }

  /**
   * Create graceful degradation response
   */
  createGracefulDegradationResponse<T>(
    fallbackData: T,
    degradationReason: string,
    requestId: string
  ): APIResponse<T> {
    return {
      success: true,
      data: fallbackData,
      meta: {
        requestId,
        processingTime: 0,
        degraded: true,
        degradationReason
      }
    };
  }

  /**
   * Handle validation errors from request schemas
   */
  handleValidationError(validationError: any, requestId: string): APIResponse {
    const details = validationError.details?.map((detail: any) => ({
      field: detail.path?.join('.'),
      message: detail.message,
      value: detail.context?.value
    }));

    return {
      success: false,
      error: {
        code: ErrorCode.INVALID_REQUEST,
        message: 'Request validation failed',
        details: { validationErrors: details },
        timestamp: new Date().toISOString(),
        requestId
      }
    };
  }

  /**
   * Get error statistics for monitoring
   */
  getErrorStatistics(): Record<string, any> {
    return {
      errorCounts: Object.fromEntries(this.errorCounts),
      errorThresholds: Object.fromEntries(this.errorThresholds),
      timestamp: new Date().toISOString()
    };
  }
}