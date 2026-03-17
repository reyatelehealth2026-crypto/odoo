import { Pool, PoolConnection, createPool } from 'mysql2/promise';
import { logger } from '@/utils/logger';
import { config } from '@/config/config';

export interface PoolStats {
  totalConnections: number;
  activeConnections: number;
  idleConnections: number;
  queuedRequests: number;
  totalQueries: number;
  averageQueryTime: number;
  slowQueries: number;
}

export interface QueryMetrics {
  query: string;
  executionTime: number;
  rowsAffected: number;
  timestamp: Date;
}

export class DatabasePoolService {
  private pool: Pool;
  private queryMetrics: QueryMetrics[] = [];
  private totalQueries = 0;
  private totalQueryTime = 0;
  private slowQueryThreshold = 1000; // 1 second

  constructor() {
    this.pool = createPool({
      host: config.DB_HOST || 'localhost',
      port: parseInt(config.DB_PORT || '3306'),
      user: config.DB_USER || 'root',
      password: config.DB_PASSWORD || '',
      database: config.DB_NAME || 'telepharmacy',
      charset: 'utf8mb4',
      timezone: '+07:00',
      
      // Connection pool settings
      connectionLimit: 20, // Maximum number of connections
      acquireTimeout: 60000, // 60 seconds
      timeout: 60000, // 60 seconds
      reconnect: true,
      
      // Performance optimizations
      multipleStatements: false,
      dateStrings: false,
      supportBigNumbers: true,
      bigNumberStrings: false,
      
      // Connection management
      idleTimeout: 300000, // 5 minutes
      maxIdle: 10, // Maximum idle connections
      
      // SSL configuration (if needed)
      ssl: config.DB_SSL_ENABLED === 'true' ? {
        rejectUnauthorized: false,
      } : false,
    });

    this.setupEventHandlers();
    this.startMetricsCollection();
  }

  /**
   * Execute a query with performance monitoring
   */
  async query<T = any>(sql: string, params?: any[]): Promise<T[]> {
    const startTime = Date.now();
    let connection: PoolConnection | null = null;

    try {
      connection = await this.pool.getConnection();
      const [rows, fields] = await connection.execute(sql, params);
      
      const executionTime = Date.now() - startTime;
      this.recordQueryMetrics(sql, executionTime, Array.isArray(rows) ? rows.length : 0);
      
      return rows as T[];
    } catch (error) {
      const executionTime = Date.now() - startTime;
      logger.error('Database query failed', {
        sql: this.sanitizeQuery(sql),
        executionTime,
        error: String(error),
      });
      throw error;
    } finally {
      if (connection) {
        connection.release();
      }
    }
  }

  /**
   * Execute a single query and return first result
   */
  async queryOne<T = any>(sql: string, params?: any[]): Promise<T | null> {
    const results = await this.query<T>(sql, params);
    return results.length > 0 ? results[0] : null;
  }

  /**
   * Execute multiple queries in a transaction
   */
  async transaction<T>(callback: (connection: PoolConnection) => Promise<T>): Promise<T> {
    const connection = await this.pool.getConnection();
    
    try {
      await connection.beginTransaction();
      const result = await callback(connection);
      await connection.commit();
      return result;
    } catch (error) {
      await connection.rollback();
      logger.error('Transaction failed', { error: String(error) });
      throw error;
    } finally {
      connection.release();
    }
  }

  /**
   * Get connection pool statistics
   */
  getPoolStats(): PoolStats {
    const poolConfig = this.pool.config;
    
    return {
      totalConnections: poolConfig.connectionLimit || 0,
      activeConnections: this.pool.pool._allConnections.length - this.pool.pool._freeConnections.length,
      idleConnections: this.pool.pool._freeConnections.length,
      queuedRequests: this.pool.pool._connectionQueue.length,
      totalQueries: this.totalQueries,
      averageQueryTime: this.totalQueries > 0 ? this.totalQueryTime / this.totalQueries : 0,
      slowQueries: this.queryMetrics.filter(m => m.executionTime > this.slowQueryThreshold).length,
    };
  }

  /**
   * Get recent query metrics
   */
  getQueryMetrics(limit = 100): QueryMetrics[] {
    return this.queryMetrics
      .slice(-limit)
      .sort((a, b) => b.executionTime - a.executionTime);
  }

  /**
   * Get slow queries for optimization
   */
  getSlowQueries(threshold = 1000): QueryMetrics[] {
    return this.queryMetrics
      .filter(m => m.executionTime > threshold)
      .sort((a, b) => b.executionTime - a.executionTime);
  }

  /**
   * Health check for the database connection
   */
  async healthCheck(): Promise<{ status: 'healthy' | 'unhealthy'; details: any }> {
    try {
      const startTime = Date.now();
      await this.query('SELECT 1 as health_check');
      const responseTime = Date.now() - startTime;
      
      const stats = this.getPoolStats();
      
      return {
        status: 'healthy',
        details: {
          responseTime,
          ...stats,
        },
      };
    } catch (error) {
      return {
        status: 'unhealthy',
        details: {
          error: String(error),
          ...this.getPoolStats(),
        },
      };
    }
  }

  /**
   * Close all connections in the pool
   */
  async close(): Promise<void> {
    try {
      await this.pool.end();
      logger.info('Database connection pool closed');
    } catch (error) {
      logger.error('Error closing database pool', { error: String(error) });
    }
  }

  /**
   * Setup event handlers for connection monitoring
   */
  private setupEventHandlers(): void {
    this.pool.on('connection', (connection) => {
      logger.debug('New database connection established', { 
        connectionId: connection.threadId 
      });
    });

    this.pool.on('error', (error) => {
      logger.error('Database pool error', { error: String(error) });
    });

    this.pool.on('release', (connection) => {
      logger.debug('Database connection released', { 
        connectionId: connection.threadId 
      });
    });
  }

  /**
   * Record query performance metrics
   */
  private recordQueryMetrics(query: string, executionTime: number, rowsAffected: number): void {
    this.totalQueries++;
    this.totalQueryTime += executionTime;

    // Keep only recent metrics to prevent memory issues
    if (this.queryMetrics.length > 1000) {
      this.queryMetrics = this.queryMetrics.slice(-500);
    }

    this.queryMetrics.push({
      query: this.sanitizeQuery(query),
      executionTime,
      rowsAffected,
      timestamp: new Date(),
    });

    // Log slow queries
    if (executionTime > this.slowQueryThreshold) {
      logger.warn('Slow query detected', {
        query: this.sanitizeQuery(query),
        executionTime,
        rowsAffected,
      });
    }
  }

  /**
   * Sanitize query for logging (remove sensitive data)
   */
  private sanitizeQuery(query: string): string {
    // Remove potential sensitive data from queries
    return query
      .replace(/password\s*=\s*'[^']*'/gi, "password='***'")
      .replace(/token\s*=\s*'[^']*'/gi, "token='***'")
      .replace(/secret\s*=\s*'[^']*'/gi, "secret='***'")
      .substring(0, 200); // Limit length
  }

  /**
   * Start collecting metrics periodically
   */
  private startMetricsCollection(): void {
    // Log pool statistics every 5 minutes
    setInterval(() => {
      const stats = this.getPoolStats();
      logger.info('Database pool statistics', stats);
      
      // Alert if pool utilization is high
      const utilizationRate = stats.activeConnections / stats.totalConnections;
      if (utilizationRate > 0.8) {
        logger.warn('High database pool utilization', {
          utilizationRate: Math.round(utilizationRate * 100),
          activeConnections: stats.activeConnections,
          totalConnections: stats.totalConnections,
        });
      }
    }, 5 * 60 * 1000);

    // Clean old metrics every hour
    setInterval(() => {
      const oneHourAgo = new Date(Date.now() - 60 * 60 * 1000);
      this.queryMetrics = this.queryMetrics.filter(m => m.timestamp > oneHourAgo);
    }, 60 * 60 * 1000);
  }
}

// Singleton instance
let databasePool: DatabasePoolService | null = null;

export const getDatabasePool = (): DatabasePoolService => {
  if (!databasePool) {
    databasePool = new DatabasePoolService();
  }
  return databasePool;
};

export const closeDatabasePool = async (): Promise<void> => {
  if (databasePool) {
    await databasePool.close();
    databasePool = null;
  }
};