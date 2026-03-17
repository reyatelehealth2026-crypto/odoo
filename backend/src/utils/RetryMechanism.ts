/**
 * Advanced Retry Mechanism with Exponential Backoff and Jitter
 * Implements BR-2.3 requirements for retry logic with exponential backoff
 */

import { LoggingService } from '../services/LoggingService.js';
import { ErrorCode } from '../types/errors.js';

export interface RetryConfig {
  maxAttempts: number;
  baseDelay: number;
  maxDelay: number;
  backoffMultiplier: number;
  jitterType: 'none' | 'full' | 'equal' | 'decorrelated';
  retryableErrors: ErrorCode[];
  timeoutMs?: number;
}

export interface RetryContext {
  attempt: number;
  totalAttempts: number;
  lastError?: Error;
  startTime: number;
  operationName: string;
  metadata?: Record<string, any>;
}

export interface RetryResult<T> {
  success: boolean;
  data?: T;
  error?: Error;
  attempts: number;
  totalTime: number;
  retryHistory: RetryAttempt[];
}

export interface RetryAttempt {
  attempt: number;
  timestamp: number;
  delay: number;
  error?: string;
  success: boolean;
}

export class RetryMechanism {
  private loggingService: LoggingService;
  private defaultConfig: RetryConfig;

  constructor(loggingService: LoggingService) {
    this.loggingService = loggingService;
    this.defaultConfig = {
      maxAttempts: 3,
      baseDelay: 1000, // 1 second
      maxDelay: 30000, // 30 seconds
      backoffMultiplier: 2,
      jitterType: 'full',
      retryableErrors: [
        ErrorCode.EXTERNAL_SERVICE_ERROR,
        ErrorCode.DATABASE_ERROR,
        ErrorCode.CACHE_ERROR,
        ErrorCode.SERVICE_UNAVAILABLE
      ],
      timeoutMs: 60000 // 1 minute total timeout
    };
  }

  /**
   * Execute operation with retry logic
   */
  async execute<T>(
    operation: (context: RetryContext) => Promise<T>,
    operationName: string,
    config?: Partial<RetryConfig>,
    metadata?: Record<string, any>
  ): Promise<RetryResult<T>> {
    const finalConfig = { ...this.defaultConfig, ...config };
    const startTime = Date.now();
    const retryHistory: RetryAttempt[] = [];
    let lastError: Error | undefined;

    for (let attempt = 1; attempt <= finalConfig.maxAttempts; attempt++) {
      const attemptStartTime = Date.now();
      
      // Check total timeout
      if (finalConfig.timeoutMs && (attemptStartTime - startTime) > finalConfig.timeoutMs) {
        const timeoutError = new Error(`Operation timeout after ${finalConfig.timeoutMs}ms`);
        retryHistory.push({
          attempt,
          timestamp: attemptStartTime,
          delay: 0,
          error: timeoutError.message,
          success: false
        });
        
        await this.logRetryFailure(operationName, attempt, timeoutError, metadata);
        
        return {
          success: false,
          error: timeoutError,
          attempts: attempt,
          totalTime: Date.now() - startTime,
          retryHistory
        };
      }

      const context: RetryContext = {
        attempt,
        totalAttempts: finalConfig.maxAttempts,
        lastError,
        startTime,
        operationName,
        metadata
      };

      try {
        const result = await operation(context);
        
        // Success
        retryHistory.push({
          attempt,
          timestamp: attemptStartTime,
          delay: 0,
          success: true
        });

        if (attempt > 1) {
          await this.logRetrySuccess(operationName, attempt, metadata);
        }

        return {
          success: true,
          data: result,
          attempts: attempt,
          totalTime: Date.now() - startTime,
          retryHistory
        };

      } catch (error) {
        lastError = error as Error;
        
        retryHistory.push({
          attempt,
          timestamp: attemptStartTime,
          delay: 0,
          error: lastError.message,
          success: false
        });

        // Check if error is retryable
        if (!this.isRetryableError(lastError, finalConfig.retryableErrors)) {
          await this.logNonRetryableError(operationName, attempt, lastError, metadata);
          
          return {
            success: false,
            error: lastError,
            attempts: attempt,
            totalTime: Date.now() - startTime,
            retryHistory
          };
        }

        // If this was the last attempt, don't wait
        if (attempt === finalConfig.maxAttempts) {
          await this.logRetryExhausted(operationName, attempt, lastError, metadata);
          
          return {
            success: false,
            error: lastError,
            attempts: attempt,
            totalTime: Date.now() - startTime,
            retryHistory
          };
        }

        // Calculate delay for next attempt
        const delay = this.calculateDelay(attempt, finalConfig);
        retryHistory[retryHistory.length - 1].delay = delay;

        await this.logRetryAttempt(operationName, attempt, lastError, delay, metadata);

        // Wait before next attempt
        await this.sleep(delay);
      }
    }

    // This should never be reached, but included for completeness
    return {
      success: false,
      error: lastError || new Error('Unknown error'),
      attempts: finalConfig.maxAttempts,
      totalTime: Date.now() - startTime,
      retryHistory
    };
  }

  /**
   * Calculate delay with exponential backoff and jitter
   */
  private calculateDelay(attempt: number, config: RetryConfig): number {
    // Base exponential backoff
    const exponentialDelay = config.baseDelay * Math.pow(config.backoffMultiplier, attempt - 1);
    
    // Cap at max delay
    const cappedDelay = Math.min(exponentialDelay, config.maxDelay);
    
    // Apply jitter
    return this.applyJitter(cappedDelay, config.jitterType);
  }

  /**
   * Apply jitter to delay to avoid thundering herd problem
   */
  private applyJitter(delay: number, jitterType: RetryConfig['jitterType']): number {
    switch (jitterType) {
      case 'none':
        return delay;
      
      case 'full':
        // Random delay between 0 and calculated delay
        return Math.random() * delay;
      
      case 'equal':
        // Half fixed delay + half random
        return (delay / 2) + (Math.random() * delay / 2);
      
      case 'decorrelated':
        // Decorrelated jitter (AWS recommended)
        return Math.random() * delay * 3;
      
      default:
        return delay;
    }
  }

  /**
   * Check if error is retryable based on configuration
   */
  private isRetryableError(error: Error, retryableErrors: ErrorCode[]): boolean {
    // Check if error message contains retryable error codes
    const errorMessage = error.message.toLowerCase();
    
    const retryablePatterns = [
      'timeout',
      'connection',
      'network',
      'temporary',
      'unavailable',
      'overloaded',
      'rate limit',
      'circuit breaker'
    ];

    // Check for specific error codes
    for (const errorCode of retryableErrors) {
      if (errorMessage.includes(errorCode.toLowerCase())) {
        return true;
      }
    }

    // Check for general retryable patterns
    for (const pattern of retryablePatterns) {
      if (errorMessage.includes(pattern)) {
        return true;
      }
    }

    // Check HTTP status codes (if available)
    if ('status' in error) {
      const status = (error as any).status;
      const retryableStatusCodes = [408, 429, 500, 502, 503, 504];
      return retryableStatusCodes.includes(status);
    }

    return false;
  }

  /**
   * Sleep for specified milliseconds
   */
  private sleep(ms: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * Log retry attempt
   */
  private async logRetryAttempt(
    operationName: string,
    attempt: number,
    error: Error,
    delay: number,
    metadata?: Record<string, any>
  ): Promise<void> {
    await this.loggingService.logEvent('warn', `Retry attempt ${attempt} for ${operationName}`, {
      operationName,
      attempt,
      error: error.message,
      delayMs: delay,
      metadata
    });
  }

  /**
   * Log retry success
   */
  private async logRetrySuccess(
    operationName: string,
    attempt: number,
    metadata?: Record<string, any>
  ): Promise<void> {
    await this.loggingService.logEvent('info', `Operation ${operationName} succeeded after ${attempt} attempts`, {
      operationName,
      totalAttempts: attempt,
      metadata
    });
  }

  /**
   * Log retry exhausted
   */
  private async logRetryExhausted(
    operationName: string,
    attempts: number,
    error: Error,
    metadata?: Record<string, any>
  ): Promise<void> {
    await this.loggingService.logEvent('error', `Operation ${operationName} failed after ${attempts} attempts`, {
      operationName,
      totalAttempts: attempts,
      finalError: error.message,
      metadata
    });
  }

  /**
   * Log non-retryable error
   */
  private async logNonRetryableError(
    operationName: string,
    attempt: number,
    error: Error,
    metadata?: Record<string, any>
  ): Promise<void> {
    await this.loggingService.logEvent('error', `Non-retryable error in ${operationName}`, {
      operationName,
      attempt,
      error: error.message,
      metadata
    });
  }

  /**
   * Log retry failure due to timeout
   */
  private async logRetryFailure(
    operationName: string,
    attempt: number,
    error: Error,
    metadata?: Record<string, any>
  ): Promise<void> {
    await this.loggingService.logEvent('error', `Operation ${operationName} failed due to timeout`, {
      operationName,
      attempt,
      error: error.message,
      metadata
    });
  }

  /**
   * Create retry configuration for specific use cases
   */
  static createConfig(type: 'database' | 'external_api' | 'cache' | 'file_upload'): RetryConfig {
    const baseConfig = {
      jitterType: 'full' as const,
      retryableErrors: [
        ErrorCode.EXTERNAL_SERVICE_ERROR,
        ErrorCode.DATABASE_ERROR,
        ErrorCode.CACHE_ERROR,
        ErrorCode.SERVICE_UNAVAILABLE
      ]
    };

    switch (type) {
      case 'database':
        return {
          ...baseConfig,
          maxAttempts: 3,
          baseDelay: 500,
          maxDelay: 5000,
          backoffMultiplier: 2,
          timeoutMs: 30000
        };

      case 'external_api':
        return {
          ...baseConfig,
          maxAttempts: 5,
          baseDelay: 1000,
          maxDelay: 30000,
          backoffMultiplier: 2,
          timeoutMs: 120000
        };

      case 'cache':
        return {
          ...baseConfig,
          maxAttempts: 2,
          baseDelay: 100,
          maxDelay: 1000,
          backoffMultiplier: 2,
          timeoutMs: 5000
        };

      case 'file_upload':
        return {
          ...baseConfig,
          maxAttempts: 3,
          baseDelay: 2000,
          maxDelay: 10000,
          backoffMultiplier: 1.5,
          timeoutMs: 300000 // 5 minutes
        };

      default:
        return baseConfig as RetryConfig;
    }
  }
}