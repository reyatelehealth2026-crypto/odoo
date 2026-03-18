import { logger } from '@/utils/logger';

export interface RetryOptions {
  maxRetries: number;
  baseDelay: number;
  maxDelay: number;
  backoffMultiplier: number;
  jitter: boolean;
}

export class RetryHandler {
  constructor(
    private name: string,
    private options: RetryOptions = {
      maxRetries: 3,
      baseDelay: 1000,
      maxDelay: 30000,
      backoffMultiplier: 2,
      jitter: true,
    }
  ) {}

  async executeWithRetry<T>(
    operation: () => Promise<T>,
    shouldRetry?: (error: Error) => boolean
  ): Promise<T> {
    let lastError: Error;
    let attempt = 0;

    while (attempt <= this.options.maxRetries) {
      try {
        if (attempt > 0) {
          logger.info(`Retry attempt ${attempt} for ${this.name}`);
        }
        
        return await operation();
      } catch (error) {
        lastError = error as Error;
        attempt++;

        // Check if we should retry this error
        if (shouldRetry && !shouldRetry(lastError)) {
          logger.info(`Not retrying ${this.name} due to error type`, {
            error: lastError.message,
            attempt,
          });
          throw lastError;
        }

        // Don't retry if we've reached max attempts
        if (attempt > this.options.maxRetries) {
          break;
        }

        // Calculate delay with exponential backoff and jitter
        const delay = this.calculateDelay(attempt);
        
        logger.warn(`${this.name} failed, retrying in ${delay}ms`, {
          error: lastError.message,
          attempt,
          maxRetries: this.options.maxRetries,
        });

        await this.sleep(delay);
      }
    }

    logger.error(`${this.name} failed after ${this.options.maxRetries + 1} attempts`, {
      error: lastError!.message,
    });
    
    throw new Error(
      `Operation failed after ${this.options.maxRetries + 1} attempts: ${lastError!.message}`
    );
  }

  private calculateDelay(attempt: number): number {
    // Exponential backoff: baseDelay * (backoffMultiplier ^ (attempt - 1))
    let delay = this.options.baseDelay * Math.pow(this.options.backoffMultiplier, attempt - 1);
    
    // Cap at maxDelay
    delay = Math.min(delay, this.options.maxDelay);
    
    // Add jitter to prevent thundering herd
    if (this.options.jitter) {
      delay = delay * (0.5 + Math.random() * 0.5);
    }
    
    return Math.floor(delay);
  }

  private sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  // Helper method to determine if an error should be retried
  static shouldRetryError(error: Error): boolean {
    // Don't retry client errors (4xx)
    if ((error as any).status >= 400 && (error as any).status < 500) {
      return false;
    }

    // Don't retry authentication errors
    if (error.message.includes('authentication') || error.message.includes('unauthorized')) {
      return false;
    }

    // Don't retry validation errors
    if (error.message.includes('validation') || error.message.includes('invalid')) {
      return false;
    }

    // Retry network errors, timeouts, and server errors
    return true;
  }
}