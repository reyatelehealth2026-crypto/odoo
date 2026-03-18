/**
 * Comprehensive Logging Service
 * Implements error logging and monitoring for BR-2.2, NFR-2.2
 */

import { PrismaClient } from '@prisma/client';
import { ErrorLogEntry, ErrorSeverity } from '../types/errors.js';
import { createWriteStream, WriteStream } from 'fs';
import { join } from 'path';
import { mkdir } from 'fs/promises';

interface LogConfig {
  enableFileLogging: boolean;
  enableDatabaseLogging: boolean;
  logDirectory: string;
  maxFileSize: number;
  retentionDays: number;
}

export class LoggingService {
  private prisma: PrismaClient;
  private config: LogConfig;
  private logStreams: Map<string, WriteStream> = new Map();

  constructor(prisma: PrismaClient, config?: Partial<LogConfig>) {
    this.prisma = prisma;
    this.config = {
      enableFileLogging: true,
      enableDatabaseLogging: true,
      logDirectory: './logs',
      maxFileSize: 10 * 1024 * 1024, // 10MB
      retentionDays: 30,
      ...config
    };

    this.initializeLogging();
  }

  /**
   * Initialize logging infrastructure
   */
  private async initializeLogging(): Promise<void> {
    if (this.config.enableFileLogging) {
      try {
        await mkdir(this.config.logDirectory, { recursive: true });
      } catch (error) {
        console.error('Failed to create log directory:', error);
      }
    }

    // Schedule log cleanup
    this.scheduleLogCleanup();
  }

  /**
   * Log error entry to configured destinations
   */
  async logError(logEntry: ErrorLogEntry): Promise<void> {
    const promises: Promise<void>[] = [];

    if (this.config.enableDatabaseLogging) {
      promises.push(this.logToDatabase(logEntry));
    }

    if (this.config.enableFileLogging) {
      promises.push(this.logToFile(logEntry));
    }

    try {
      await Promise.allSettled(promises);
    } catch (error) {
      console.error('Failed to log error:', error);
    }
  }

  /**
   * Log error to database
   */
  private async logToDatabase(logEntry: ErrorLogEntry): Promise<void> {
    try {
      await this.prisma.errorLog.create({
        data: {
          id: logEntry.id,
          timestamp: new Date(logEntry.timestamp),
          level: logEntry.level,
          code: logEntry.code,
          message: logEntry.message,
          stack: logEntry.stack,
          details: logEntry.details ? JSON.stringify(logEntry.details) : null,
          requestId: logEntry.requestId,
          userId: logEntry.userId,
          endpoint: logEntry.endpoint,
          userAgent: logEntry.userAgent,
          ipAddress: logEntry.ipAddress
        }
      });
    } catch (error) {
      console.error('Failed to log to database:', error);
      // Fallback to file logging
      await this.logToFile(logEntry);
    }
  }

  /**
   * Log error to file
   */
  private async logToFile(logEntry: ErrorLogEntry): Promise<void> {
    try {
      const logFileName = `error-${new Date().toISOString().split('T')[0]}.log`;
      const logFilePath = join(this.config.logDirectory, logFileName);
      
      let stream = this.logStreams.get(logFileName);
      if (!stream) {
        stream = createWriteStream(logFilePath, { flags: 'a' });
        this.logStreams.set(logFileName, stream);
      }

      const logLine = JSON.stringify({
        ...logEntry,
        timestamp: new Date(logEntry.timestamp).toISOString()
      }) + '\n';

      stream.write(logLine);
    } catch (error) {
      console.error('Failed to log to file:', error);
    }
  }

  /**
   * Log application events (non-error)
   */
  async logEvent(
    level: 'info' | 'warn' | 'debug',
    message: string,
    details?: Record<string, any>,
    requestId?: string
  ): Promise<void> {
    const logEntry = {
      id: `event-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
      timestamp: new Date().toISOString(),
      level: level as any,
      message,
      details,
      requestId
    };

    if (this.config.enableFileLogging) {
      const logFileName = `app-${new Date().toISOString().split('T')[0]}.log`;
      const logFilePath = join(this.config.logDirectory, logFileName);
      
      let stream = this.logStreams.get(logFileName);
      if (!stream) {
        stream = createWriteStream(logFilePath, { flags: 'a' });
        this.logStreams.set(logFileName, stream);
      }

      const logLine = JSON.stringify(logEntry) + '\n';
      stream.write(logLine);
    }
  }

  /**
   * Get error logs with filtering and pagination
   */
  async getErrorLogs(filters: {
    level?: ErrorSeverity;
    code?: string;
    dateFrom?: Date;
    dateTo?: Date;
    userId?: string;
    page?: number;
    limit?: number;
  }): Promise<{
    logs: ErrorLogEntry[];
    total: number;
    page: number;
    totalPages: number;
  }> {
    const page = filters.page || 1;
    const limit = Math.min(filters.limit || 50, 100);
    const skip = (page - 1) * limit;

    const where: any = {};

    if (filters.level) {
      where.level = filters.level;
    }

    if (filters.code) {
      where.code = filters.code;
    }

    if (filters.dateFrom || filters.dateTo) {
      where.timestamp = {};
      if (filters.dateFrom) {
        where.timestamp.gte = filters.dateFrom;
      }
      if (filters.dateTo) {
        where.timestamp.lte = filters.dateTo;
      }
    }

    if (filters.userId) {
      where.userId = filters.userId;
    }

    const [logs, total] = await Promise.all([
      this.prisma.errorLog.findMany({
        where,
        orderBy: { timestamp: 'desc' },
        skip,
        take: limit
      }),
      this.prisma.errorLog.count({ where })
    ]);

    return {
      logs: logs.map(log => ({
        id: log.id,
        timestamp: log.timestamp.toISOString(),
        level: log.level as ErrorSeverity,
        code: log.code as any,
        message: log.message,
        stack: log.stack || undefined,
        details: log.details ? JSON.parse(log.details) : undefined,
        requestId: log.requestId || undefined,
        userId: log.userId || undefined,
        endpoint: log.endpoint || undefined,
        userAgent: log.userAgent || undefined,
        ipAddress: log.ipAddress || undefined
      })),
      total,
      page,
      totalPages: Math.ceil(total / limit)
    };
  }

  /**
   * Get error statistics for monitoring dashboard
   */
  async getErrorStatistics(timeRange: {
    from: Date;
    to: Date;
  }): Promise<{
    totalErrors: number;
    errorsByLevel: Record<ErrorSeverity, number>;
    errorsByCode: Record<string, number>;
    errorTrends: Array<{ date: string; count: number }>;
  }> {
    const [
      totalErrors,
      errorsByLevel,
      errorsByCode,
      errorTrends
    ] = await Promise.all([
      // Total errors in time range
      this.prisma.errorLog.count({
        where: {
          timestamp: {
            gte: timeRange.from,
            lte: timeRange.to
          }
        }
      }),

      // Errors by severity level
      this.prisma.errorLog.groupBy({
        by: ['level'],
        _count: { level: true },
        where: {
          timestamp: {
            gte: timeRange.from,
            lte: timeRange.to
          }
        }
      }),

      // Errors by error code
      this.prisma.errorLog.groupBy({
        by: ['code'],
        _count: { code: true },
        where: {
          timestamp: {
            gte: timeRange.from,
            lte: timeRange.to
          }
        },
        orderBy: {
          _count: {
            code: 'desc'
          }
        },
        take: 10
      }),

      // Error trends by day
      this.prisma.$queryRaw`
        SELECT 
          DATE(timestamp) as date,
          COUNT(*) as count
        FROM error_logs 
        WHERE timestamp >= ${timeRange.from} AND timestamp <= ${timeRange.to}
        GROUP BY DATE(timestamp)
        ORDER BY date ASC
      `
    ]);

    return {
      totalErrors,
      errorsByLevel: errorsByLevel.reduce((acc, item) => {
        acc[item.level as ErrorSeverity] = item._count.level;
        return acc;
      }, {} as Record<ErrorSeverity, number>),
      errorsByCode: errorsByCode.reduce((acc, item) => {
        acc[item.code] = item._count.code;
        return acc;
      }, {} as Record<string, number>),
      errorTrends: (errorTrends as any[]).map(item => ({
        date: item.date.toISOString().split('T')[0],
        count: Number(item.count)
      }))
    };
  }

  /**
   * Schedule periodic log cleanup
   */
  private scheduleLogCleanup(): void {
    // Run cleanup daily at 2 AM
    const now = new Date();
    const tomorrow2AM = new Date(now);
    tomorrow2AM.setDate(tomorrow2AM.getDate() + 1);
    tomorrow2AM.setHours(2, 0, 0, 0);
    
    const msUntil2AM = tomorrow2AM.getTime() - now.getTime();

    setTimeout(() => {
      this.cleanupOldLogs();
      // Schedule daily cleanup
      setInterval(() => this.cleanupOldLogs(), 24 * 60 * 60 * 1000);
    }, msUntil2AM);
  }

  /**
   * Clean up old log entries
   */
  private async cleanupOldLogs(): Promise<void> {
    try {
      const cutoffDate = new Date();
      cutoffDate.setDate(cutoffDate.getDate() - this.config.retentionDays);

      // Clean up database logs
      const deletedCount = await this.prisma.errorLog.deleteMany({
        where: {
          timestamp: {
            lt: cutoffDate
          }
        }
      });

      console.log(`Cleaned up ${deletedCount.count} old error log entries`);

      // Close old file streams
      const today = new Date().toISOString().split('T')[0];
      for (const [fileName, stream] of this.logStreams.entries()) {
        if (!fileName.includes(today)) {
          stream.end();
          this.logStreams.delete(fileName);
        }
      }
    } catch (error) {
      console.error('Failed to cleanup old logs:', error);
    }
  }

  /**
   * Close all log streams
   */
  async close(): Promise<void> {
    for (const stream of this.logStreams.values()) {
      stream.end();
    }
    this.logStreams.clear();
  }
}