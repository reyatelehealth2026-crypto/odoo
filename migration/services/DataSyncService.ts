/**
 * Data Synchronization Service for Migration
 * Purpose: Keep data synchronized between legacy and modern systems
 * Requirements: TC-3.1
 */

import { EventEmitter } from 'events';
import { Logger } from '../../backend/src/services/LoggingService';
import { Redis } from 'ioredis';
import mysql from 'mysql2/promise';

export interface SyncConfig {
  syncInterval: number;
  batchSize: number;
  maxRetries: number;
  conflictResolution: 'legacy_wins' | 'modern_wins' | 'timestamp_wins';
  enableBidirectionalSync: boolean;
  syncTables: string[];
}

export interface SyncStatus {
  tableName: string;
  lastSyncTime: Date;
  recordsSynced: number;
  conflictsResolved: number;
  errors: number;
  status: 'active' | 'paused' | 'error';
}

export interface ConflictRecord {
  tableName: string;
  recordId: string;
  legacyData: any;
  modernData: any;
  conflictType: 'update' | 'delete' | 'create';
  resolvedBy: 'legacy' | 'modern' | 'manual';
  resolvedAt: Date;
}

export class DataSyncService extends EventEmitter {
  private logger: Logger;
  private redis: Redis;
  private legacyDb: mysql.Connection;
  private modernDb: mysql.Connection;
  private config: SyncConfig;
  private syncIntervals: Map<string, NodeJS.Timeout> = new Map();
  private syncStatus: Map<string, SyncStatus> = new Map();
  private isRunning = false;

  constructor(
    logger: Logger,
    redis: Redis,
    legacyDb: mysql.Connection,
    modernDb: mysql.Connection,
    config: SyncConfig
  ) {
    super();
    this.logger = logger;
    this.redis = redis;
    this.legacyDb = legacyDb;
    this.modernDb = modernDb;
    this.config = config;
  }

  /**
   * Start data synchronization
   */
  async start(): Promise<void> {
    if (this.isRunning) {
      this.logger.warn('Data sync service already running');
      return;
    }

    this.logger.info('Starting data synchronization service', {
      syncInterval: this.config.syncInterval,
      syncTables: this.config.syncTables,
      bidirectional: this.config.enableBidirectionalSync
    });

    this.isRunning = true;

    // Initialize sync status for each table
    for (const tableName of this.config.syncTables) {
      this.syncStatus.set(tableName, {
        tableName,
        lastSyncTime: new Date(),
        recordsSynced: 0,
        conflictsResolved: 0,
        errors: 0,
        status: 'active'
      });

      // Start sync interval for each table
      const interval = setInterval(
        () => this.syncTable(tableName),
        this.config.syncInterval
      );
      this.syncIntervals.set(tableName, interval);
    }

    // Start conflict resolution monitor
    this.startConflictMonitor();

    this.emit('started');
  }

  /**
   * Stop data synchronization
   */
  async stop(): Promise<void> {
    if (!this.isRunning) {
      return;
    }

    this.logger.info('Stopping data synchronization service');

    this.isRunning = false;

    // Clear all intervals
    for (const [tableName, interval] of this.syncIntervals) {
      clearInterval(interval);
      this.syncIntervals.delete(tableName);
    }

    this.emit('stopped');
  }

  /**
   * Sync a specific table
   */
  private async syncTable(tableName: string): Promise<void> {
    const status = this.syncStatus.get(tableName);
    if (!status || status.status === 'paused') {
      return;
    }

    try {
      this.logger.debug(`Starting sync for table: ${tableName}`);

      // Get last sync timestamp
      const lastSync = await this.getLastSyncTimestamp(tableName);
      
      // Sync from legacy to modern
      const legacyChanges = await this.getLegacyChanges(tableName, lastSync);
      await this.applyChangesToModern(tableName, legacyChanges);

      // Sync from modern to legacy (if bidirectional)
      if (this.config.enableBidirectionalSync) {
        const modernChanges = await this.getModernChanges(tableName, lastSync);
        await this.applyChangesToLegacy(tableName, modernChanges);
      }

      // Update sync status
      status.lastSyncTime = new Date();
      status.recordsSynced += legacyChanges.length;
      if (this.config.enableBidirectionalSync) {
        status.recordsSynced += (await this.getModernChanges(tableName, lastSync)).length;
      }

      // Store sync timestamp
      await this.updateLastSyncTimestamp(tableName, status.lastSyncTime);

      this.emit('tableSynced', { tableName, recordCount: legacyChanges.length });

    } catch (error) {
      this.logger.error(`Sync failed for table ${tableName}`, { error });
      
      if (status) {
        status.errors++;
        status.status = 'error';
      }

      this.emit('syncError', { tableName, error });
    }
  }

  /**
   * Get changes from legacy system since last sync
   */
  private async getLegacyChanges(tableName: string, since: Date): Promise<any[]> {
    const query = `
      SELECT * FROM ${tableName} 
      WHERE updated_at > ? OR created_at > ?
      ORDER BY updated_at ASC
      LIMIT ?
    `;

    const [rows] = await this.legacyDb.execute(query, [
      since.toISOString(),
      since.toISOString(),
      this.config.batchSize
    ]);

    return rows as any[];
  }

  /**
   * Get changes from modern system since last sync
   */
  private async getModernChanges(tableName: string, since: Date): Promise<any[]> {
    const query = `
      SELECT * FROM ${tableName} 
      WHERE updated_at > ? OR created_at > ?
      ORDER BY updated_at ASC
      LIMIT ?
    `;

    const [rows] = await this.modernDb.execute(query, [
      since.toISOString(),
      since.toISOString(),
      this.config.batchSize
    ]);

    return rows as any[];
  }

  /**
   * Apply changes to modern system
   */
  private async applyChangesToModern(tableName: string, changes: any[]): Promise<void> {
    for (const change of changes) {
      try {
        // Check if record exists in modern system
        const existing = await this.getModernRecord(tableName, change.id);

        if (existing) {
          // Check for conflicts
          if (this.hasConflict(existing, change)) {
            await this.handleConflict(tableName, change.id, existing, change, 'legacy_to_modern');
            continue;
          }

          // Update existing record
          await this.updateModernRecord(tableName, change);
        } else {
          // Insert new record
          await this.insertModernRecord(tableName, change);
        }

        this.logger.debug(`Synced record ${change.id} to modern system`, {
          tableName,
          recordId: change.id
        });

      } catch (error) {
        this.logger.error(`Failed to sync record ${change.id} to modern system`, {
          tableName,
          recordId: change.id,
          error
        });
      }
    }
  }

  /**
   * Apply changes to legacy system
   */
  private async applyChangesToLegacy(tableName: string, changes: any[]): Promise<void> {
    for (const change of changes) {
      try {
        // Check if record exists in legacy system
        const existing = await this.getLegacyRecord(tableName, change.id);

        if (existing) {
          // Check for conflicts
          if (this.hasConflict(existing, change)) {
            await this.handleConflict(tableName, change.id, existing, change, 'modern_to_legacy');
            continue;
          }

          // Update existing record
          await this.updateLegacyRecord(tableName, change);
        } else {
          // Insert new record
          await this.insertLegacyRecord(tableName, change);
        }

        this.logger.debug(`Synced record ${change.id} to legacy system`, {
          tableName,
          recordId: change.id
        });

      } catch (error) {
        this.logger.error(`Failed to sync record ${change.id} to legacy system`, {
          tableName,
          recordId: change.id,
          error
        });
      }
    }
  }

  /**
   * Check if there's a conflict between records
   */
  private hasConflict(existing: any, incoming: any): boolean {
    // Simple timestamp-based conflict detection
    const existingTime = new Date(existing.updated_at);
    const incomingTime = new Date(incoming.updated_at);

    // If both records were updated at different times, there's a potential conflict
    return Math.abs(existingTime.getTime() - incomingTime.getTime()) > 1000; // 1 second tolerance
  }

  /**
   * Handle data conflicts
   */
  private async handleConflict(
    tableName: string,
    recordId: string,
    existing: any,
    incoming: any,
    direction: 'legacy_to_modern' | 'modern_to_legacy'
  ): Promise<void> {
    const conflict: ConflictRecord = {
      tableName,
      recordId,
      legacyData: direction === 'legacy_to_modern' ? incoming : existing,
      modernData: direction === 'legacy_to_modern' ? existing : incoming,
      conflictType: 'update',
      resolvedBy: this.config.conflictResolution === 'legacy_wins' ? 'legacy' : 'modern',
      resolvedAt: new Date()
    };

    // Store conflict for review
    await this.storeConflict(conflict);

    // Resolve based on strategy
    switch (this.config.conflictResolution) {
      case 'legacy_wins':
        if (direction === 'legacy_to_modern') {
          await this.updateModernRecord(tableName, incoming);
        }
        break;
      case 'modern_wins':
        if (direction === 'modern_to_legacy') {
          await this.updateLegacyRecord(tableName, incoming);
        }
        break;
      case 'timestamp_wins':
        const existingTime = new Date(existing.updated_at);
        const incomingTime = new Date(incoming.updated_at);
        
        if (incomingTime > existingTime) {
          if (direction === 'legacy_to_modern') {
            await this.updateModernRecord(tableName, incoming);
          } else {
            await this.updateLegacyRecord(tableName, incoming);
          }
        }
        break;
    }

    // Update sync status
    const status = this.syncStatus.get(tableName);
    if (status) {
      status.conflictsResolved++;
    }

    this.emit('conflictResolved', conflict);
  }

  /**
   * Store conflict for manual review
   */
  private async storeConflict(conflict: ConflictRecord): Promise<void> {
    const conflictKey = `conflict:${conflict.tableName}:${conflict.recordId}:${Date.now()}`;
    await this.redis.setex(conflictKey, 86400 * 7, JSON.stringify(conflict)); // Keep for 7 days
  }

  /**
   * Start conflict resolution monitor
   */
  private startConflictMonitor(): void {
    setInterval(async () => {
      try {
        const conflictKeys = await this.redis.keys('conflict:*');
        if (conflictKeys.length > 0) {
          this.logger.info(`Found ${conflictKeys.length} unresolved conflicts`);
          this.emit('conflictsFound', { count: conflictKeys.length });
        }
      } catch (error) {
        this.logger.error('Failed to check for conflicts', { error });
      }
    }, 60000); // Check every minute
  }

  /**
   * Get sync status for all tables
   */
  getSyncStatus(): SyncStatus[] {
    return Array.from(this.syncStatus.values());
  }

  /**
   * Pause sync for a specific table
   */
  pauseTableSync(tableName: string): void {
    const status = this.syncStatus.get(tableName);
    if (status) {
      status.status = 'paused';
      this.logger.info(`Paused sync for table: ${tableName}`);
    }
  }

  /**
   * Resume sync for a specific table
   */
  resumeTableSync(tableName: string): void {
    const status = this.syncStatus.get(tableName);
    if (status) {
      status.status = 'active';
      this.logger.info(`Resumed sync for table: ${tableName}`);
    }
  }

  // Helper methods for database operations
  private async getLastSyncTimestamp(tableName: string): Promise<Date> {
    const timestamp = await this.redis.get(`sync_timestamp:${tableName}`);
    return timestamp ? new Date(timestamp) : new Date(Date.now() - 3600000); // 1 hour ago default
  }

  private async updateLastSyncTimestamp(tableName: string, timestamp: Date): Promise<void> {
    await this.redis.set(`sync_timestamp:${tableName}`, timestamp.toISOString());
  }

  private async getModernRecord(tableName: string, id: string): Promise<any> {
    const [rows] = await this.modernDb.execute(`SELECT * FROM ${tableName} WHERE id = ?`, [id]);
    return (rows as any[])[0] || null;
  }

  private async getLegacyRecord(tableName: string, id: string): Promise<any> {
    const [rows] = await this.legacyDb.execute(`SELECT * FROM ${tableName} WHERE id = ?`, [id]);
    return (rows as any[])[0] || null;
  }

  private async updateModernRecord(tableName: string, record: any): Promise<void> {
    const fields = Object.keys(record).filter(key => key !== 'id');
    const values = fields.map(field => record[field]);
    const setClause = fields.map(field => `${field} = ?`).join(', ');
    
    await this.modernDb.execute(
      `UPDATE ${tableName} SET ${setClause} WHERE id = ?`,
      [...values, record.id]
    );
  }

  private async updateLegacyRecord(tableName: string, record: any): Promise<void> {
    const fields = Object.keys(record).filter(key => key !== 'id');
    const values = fields.map(field => record[field]);
    const setClause = fields.map(field => `${field} = ?`).join(', ');
    
    await this.legacyDb.execute(
      `UPDATE ${tableName} SET ${setClause} WHERE id = ?`,
      [...values, record.id]
    );
  }

  private async insertModernRecord(tableName: string, record: any): Promise<void> {
    const fields = Object.keys(record);
    const values = fields.map(field => record[field]);
    const placeholders = fields.map(() => '?').join(', ');
    
    await this.modernDb.execute(
      `INSERT INTO ${tableName} (${fields.join(', ')}) VALUES (${placeholders})`,
      values
    );
  }

  private async insertLegacyRecord(tableName: string, record: any): Promise<void> {
    const fields = Object.keys(record);
    const values = fields.map(field => record[field]);
    const placeholders = fields.map(() => '?').join(', ');
    
    await this.legacyDb.execute(
      `INSERT INTO ${tableName} (${fields.join(', ')}) VALUES (${placeholders})`,
      values
    );
  }
}