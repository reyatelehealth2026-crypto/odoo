import { config } from '@/config/config';

interface LogContext {
  [key: string]: unknown;
}

class Logger {
  private formatMessage(level: string, message: string, context?: LogContext): string {
    const timestamp = new Date().toISOString();
    const contextStr = context ? ` ${JSON.stringify(context)}` : '';
    return `[${timestamp}] ${level.toUpperCase()}: ${message}${contextStr}`;
  }

  private shouldLog(level: string): boolean {
    const levels = ['fatal', 'error', 'warn', 'info', 'debug', 'trace'];
    const currentLevelIndex = levels.indexOf(config.LOG_LEVEL);
    const messageLevelIndex = levels.indexOf(level);
    return messageLevelIndex <= currentLevelIndex;
  }

  fatal(message: string, context?: LogContext): void {
    if (this.shouldLog('fatal')) {
      console.error(this.formatMessage('fatal', message, context));
    }
  }

  error(message: string, context?: LogContext): void {
    if (this.shouldLog('error')) {
      console.error(this.formatMessage('error', message, context));
    }
  }

  warn(message: string, context?: LogContext): void {
    if (this.shouldLog('warn')) {
      console.warn(this.formatMessage('warn', message, context));
    }
  }

  info(message: string, context?: LogContext): void {
    if (this.shouldLog('info')) {
      console.info(this.formatMessage('info', message, context));
    }
  }

  debug(message: string, context?: LogContext): void {
    if (this.shouldLog('debug')) {
      console.debug(this.formatMessage('debug', message, context));
    }
  }

  trace(message: string, context?: LogContext): void {
    if (this.shouldLog('trace')) {
      console.trace(this.formatMessage('trace', message, context));
    }
  }
}

export const logger = new Logger();