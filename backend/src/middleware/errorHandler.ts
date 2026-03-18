/**
 * Global Error Handling Middleware
 * Implements BR-2.2, NFR-2.2 requirements for comprehensive error handling
 */

import { FastifyRequest, FastifyReply, FastifyError } from 'fastify';
import { ErrorHandlingService } from '../services/ErrorHandlingService.js';
import { GracefulDegradationService } from '../services/GracefulDegradationService.js';
import { AppError, ErrorCode, ValidationError } from '../types/errors.js';
import { v4 as uuidv4 } from 'uuid';

export interface ErrorHandlerConfig {
  enableStackTrace: boolean;
  enableDetailedErrors: boolean;
  logAllErrors: boolean;
  enableGracefulDegradation: boolean;
}

export class ErrorHandlerMiddleware {
  private errorHandlingService: ErrorHandlingService;
  private gracefulDegradationService: GracefulDegradationService;
  private config: ErrorHandlerConfig;

  constructor(
    errorHandlingService: ErrorHandlingService,
    gracefulDegradationService: GracefulDegradationService,
    config?: Partial<ErrorHandlerConfig>
  ) {
    this.errorHandlingService = errorHandlingService;
    this.gracefulDegradationService = gracefulDegradationService;
    this.config = {
      enableStackTrace: process.env.NODE_ENV === 'development',
      enableDetailedErrors: process.env.NODE_ENV === 'development',
      logAllErrors: true,
      enableGracefulDegradation: true,
      ...config
    };
  }

  /**
   * Global error handler for Fastify
   */
  async handleError(
    error: FastifyError,
    request: FastifyRequest,
    reply: FastifyReply
  ): Promise<void> {
    // Ensure request has an ID for tracing
    if (!request.id) {
      request.id = uuidv4();
    }

    // Handle different types of errors
    if (this.isValidationError(error)) {
      await this.handleValidationError(error, request, reply);
    } else if (this.isAuthenticationError(error)) {
      await this.handleAuthenticationError(error, request, reply);
    } else if (this.isRateLimitError(error)) {
      await this.handleRateLimitError(error, request, reply);
    } else if (this.isAppError(error)) {
      await this.handleAppError(error as AppError, request, reply);
    } else {
      await this.handleUnknownError(error, request, reply);
    }
  }

  /**
   * Handle validation errors
   */
  private async handleValidationError(
    error: FastifyError,
    request: FastifyRequest,
    reply: FastifyReply
  ): Promise<void> {
    const validationError = new ValidationError(
      'Request validation failed',
      { validationErrors: error.validation }
    );

    await this.errorHandlingService.handleError(validationError, request, reply);
  }

  /**
   * Handle authentication errors
   */
  private async handleAuthenticationError(
    error: FastifyError,
    request: FastifyRequest,
    reply: FastifyReply
  ): Promise<void> {
    const authError = new AppError(
      ErrorCode.INVALID_TOKEN,
      'Authentication failed',
      401
    );

    await this.errorHandlingService.handleError(authError, request, reply);
  }

  /**
   * Handle rate limit errors
   */
  private async handleRateLimitError(
    error: FastifyError,
    request: FastifyRequest,
    reply: FastifyReply
  ): Promise<void> {
    const rateLimitError = new AppError(
      ErrorCode.RATE_LIMIT_EXCEEDED,
      'Rate limit exceeded',
      429,
      { retryAfter: error.statusCode === 429 ? '60' : undefined }
    );

    await this.errorHandlingService.handleError(rateLimitError, request, reply);
  }

  /**
   * Handle application errors
   */
  private async handleAppError(
    error: AppError,
    request: FastifyRequest,
    reply: FastifyReply
  ): Promise<void> {
    // Check if we should apply graceful degradation
    if (this.config.enableGracefulDegradation && this.shouldApplyDegradation(error)) {
      try {
        const degradationResponse = await this.gracefulDegradationService.applyDegradation(
          error,
          {
            endpoint: `${request.method} ${request.url}`,
            service: this.extractServiceFromError(error),
            params: { ...request.query, ...request.body },
            requestId: request.id
          }
        );

        reply.status(200).send(degradationResponse);
        return;
      } catch (degradationError) {
        // If degradation fails, fall back to normal error handling
        console.error('Graceful degradation failed:', degradationError);
      }
    }

    await this.errorHandlingService.handleError(error, request, reply);
  }

  /**
   * Handle unknown errors
   */
  private async handleUnknownError(
    error: FastifyError,
    request: FastifyRequest,
    reply: FastifyReply
  ): Promise<void> {
    // Convert unknown error to AppError
    const appError = new AppError(
      ErrorCode.DATABASE_ERROR,
      this.config.enableDetailedErrors ? error.message : 'Internal server error',
      error.statusCode || 500,
      this.config.enableDetailedErrors ? { 
        originalError: error.name,
        stack: this.config.enableStackTrace ? error.stack : undefined
      } : undefined,
      false // Mark as non-operational
    );

    await this.errorHandlingService.handleError(appError, request, reply);
  }

  /**
   * Check if error is a validation error
   */
  private isValidationError(error: FastifyError): boolean {
    return error.validation !== undefined || error.statusCode === 400;
  }

  /**
   * Check if error is an authentication error
   */
  private isAuthenticationError(error: FastifyError): boolean {
    return error.statusCode === 401 || 
           error.message.toLowerCase().includes('unauthorized') ||
           error.message.toLowerCase().includes('authentication');
  }

  /**
   * Check if error is a rate limit error
   */
  private isRateLimitError(error: FastifyError): boolean {
    return error.statusCode === 429 || 
           error.message.toLowerCase().includes('rate limit');
  }

  /**
   * Check if error is an AppError
   */
  private isAppError(error: any): boolean {
    return error instanceof AppError;
  }

  /**
   * Check if graceful degradation should be applied
   */
  private shouldApplyDegradation(error: AppError): boolean {
    const degradableErrors = [
      ErrorCode.EXTERNAL_SERVICE_ERROR,
      ErrorCode.DATABASE_ERROR,
      ErrorCode.CACHE_ERROR,
      ErrorCode.CIRCUIT_BREAKER_OPEN,
      ErrorCode.SERVICE_UNAVAILABLE
    ];

    return degradableErrors.includes(error.code);
  }

  /**
   * Extract service name from error for degradation context
   */
  private extractServiceFromError(error: AppError): string {
    const message = error.message.toLowerCase();
    
    if (message.includes('odoo') || message.includes('erp')) {
      return 'odoo';
    } else if (message.includes('line') || message.includes('messaging')) {
      return 'line';
    } else if (message.includes('payment') || message.includes('banking')) {
      return 'payment';
    } else if (message.includes('cache') || message.includes('redis')) {
      return 'cache';
    } else if (message.includes('database') || message.includes('mysql')) {
      return 'database';
    }
    
    return 'unknown';
  }

  /**
   * Pre-handler for request validation and setup
   */
  async preHandler(request: FastifyRequest, reply: FastifyReply): Promise<void> {
    // Ensure request has an ID for tracing
    if (!request.id) {
      request.id = uuidv4();
    }

    // Add request start time for performance tracking
    (request as any).startTime = Date.now();

    // Set security headers
    reply.headers({
      'X-Content-Type-Options': 'nosniff',
      'X-Frame-Options': 'DENY',
      'X-XSS-Protection': '1; mode=block',
      'Referrer-Policy': 'strict-origin-when-cross-origin'
    });
  }

  /**
   * Post-handler for response processing
   */
  async postHandler(request: FastifyRequest, reply: FastifyReply): Promise<void> {
    const processingTime = Date.now() - ((request as any).startTime || Date.now());
    
    // Add processing time to response headers
    reply.header('X-Processing-Time', processingTime.toString());
    reply.header('X-Request-ID', request.id);
  }

  /**
   * Handle uncaught exceptions
   */
  handleUncaughtException(error: Error): void {
    console.error('Uncaught Exception:', error);
    
    // Log the error
    this.errorHandlingService.handleError(
      new AppError(
        ErrorCode.DATABASE_ERROR,
        'Uncaught exception',
        500,
        { originalError: error.message, stack: error.stack },
        false
      ),
      {} as FastifyRequest,
      {} as FastifyReply
    ).catch(console.error);

    // Graceful shutdown
    process.exit(1);
  }

  /**
   * Handle unhandled promise rejections
   */
  handleUnhandledRejection(reason: any, promise: Promise<any>): void {
    console.error('Unhandled Rejection at:', promise, 'reason:', reason);
    
    // Log the error
    this.errorHandlingService.handleError(
      new AppError(
        ErrorCode.DATABASE_ERROR,
        'Unhandled promise rejection',
        500,
        { reason: reason?.toString(), stack: reason?.stack },
        false
      ),
      {} as FastifyRequest,
      {} as FastifyReply
    ).catch(console.error);
  }

  /**
   * Setup global error handlers
   */
  setupGlobalHandlers(): void {
    process.on('uncaughtException', this.handleUncaughtException.bind(this));
    process.on('unhandledRejection', this.handleUnhandledRejection.bind(this));
  }
}