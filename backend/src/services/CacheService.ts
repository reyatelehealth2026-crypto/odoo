import { FastifyInstance } from 'fastify';
import { logger } from '@/utils/logger';

export interface CacheOptions {
  ttl?: number; // Time to live in seconds
  prefix?: string;
}

export interface CacheStats {
  hits: number;
  misses: number;
  sets: number;
  deletes: number;
  hitRate: number;
}

export class CacheService {
  private redis: any;
  private stats: CacheStats = {
    hits: 0,
    misses: 0,
    sets: 0,
    deletes: 0,
    hitRate: 0,
  };

  constructor(fastify: FastifyInstance) {
    this.redis = fastify.redis;
  }

  async get<T>(key: string, options: CacheOptions = {}): Promise<T | null> {
    try {
      const fullKey = this.buildKey(key, options.prefix);
      const value = await this.redis.get(fullKey);
      
      if (value === null) {
        this.stats.misses++;
        this.updateHitRate();
        return null;
      }

      this.stats.hits++;
      this.updateHitRate();
      
      try {
        return JSON.parse(value);
      } catch {
        // If parsing fails, return as string
        return value as T;
      }
    } catch (error) {
      logger.error('Cache get error', { key, error: String(error) });
      this.stats.misses++;
      this.updateHitRate();
      return null;
    }
  }

  async set<T>(key: string, value: T, options: CacheOptions = {}): Promise<boolean> {
    try {
      const fullKey = this.buildKey(key, options.prefix);
      const serializedValue = typeof value === 'string' ? value : JSON.stringify(value);
      
      if (options.ttl) {
        await this.redis.setex(fullKey, options.ttl, serializedValue);
      } else {
        await this.redis.set(fullKey, serializedValue);
      }
      
      this.stats.sets++;
      return true;
    } catch (error) {
      logger.error('Cache set error', { key, error: String(error) });
      return false;
    }
  }

  async delete(key: string, options: CacheOptions = {}): Promise<boolean> {
    try {
      const fullKey = this.buildKey(key, options.prefix);
      const result = await this.redis.del(fullKey);
      
      this.stats.deletes++;
      return result > 0;
    } catch (error) {
      logger.error('Cache delete error', { key, error: String(error) });
      return false;
    }
  }

  async exists(key: string, options: CacheOptions = {}): Promise<boolean> {
    try {
      const fullKey = this.buildKey(key, options.prefix);
      const result = await this.redis.exists(fullKey);
      return result === 1;
    } catch (error) {
      logger.error('Cache exists error', { key, error: String(error) });
      return false;
    }
  }

  async expire(key: string, ttl: number, options: CacheOptions = {}): Promise<boolean> {
    try {
      const fullKey = this.buildKey(key, options.prefix);
      const result = await this.redis.expire(fullKey, ttl);
      return result === 1;
    } catch (error) {
      logger.error('Cache expire error', { key, ttl, error: String(error) });
      return false;
    }
  }

  async getOrSet<T>(
    key: string,
    factory: () => Promise<T>,
    options: CacheOptions = {}
  ): Promise<T> {
    const cached = await this.get<T>(key, options);
    
    if (cached !== null) {
      return cached;
    }

    const value = await factory();
    await this.set(key, value, options);
    
    return value;
  }

  async invalidatePattern(pattern: string, options: CacheOptions = {}): Promise<number> {
    try {
      const fullPattern = this.buildKey(pattern, options.prefix);
      const keys = await this.redis.keys(fullPattern);
      
      if (keys.length === 0) {
        return 0;
      }

      const result = await this.redis.del(...keys);
      this.stats.deletes += result;
      
      logger.info(`Invalidated ${result} cache keys matching pattern`, { pattern: fullPattern });
      return result;
    } catch (error) {
      logger.error('Cache invalidate pattern error', { pattern, error: String(error) });
      return 0;
    }
  }

  async flush(): Promise<boolean> {
    try {
      await this.redis.flushdb();
      logger.info('Cache flushed');
      return true;
    } catch (error) {
      logger.error('Cache flush error', { error: String(error) });
      return false;
    }
  }

  getStats(): CacheStats {
    return { ...this.stats };
  }

  resetStats(): void {
    this.stats = {
      hits: 0,
      misses: 0,
      sets: 0,
      deletes: 0,
      hitRate: 0,
    };
  }

  private buildKey(key: string, prefix?: string): string {
    const parts = ['odoo-dashboard'];
    
    if (prefix) {
      parts.push(prefix);
    }
    
    parts.push(key);
    
    return parts.join(':');
  }

  private updateHitRate(): void {
    const total = this.stats.hits + this.stats.misses;
    this.stats.hitRate = total > 0 ? (this.stats.hits / total) * 100 : 0;
  }

  // Multi-layer caching methods
  async getWithFallback<T>(
    key: string,
    fallbackFactory: () => Promise<T>,
    options: CacheOptions & { fallbackTtl?: number } = {}
  ): Promise<T> {
    // Try L1 cache (Redis)
    const cached = await this.get<T>(key, options);
    if (cached !== null) {
      return cached;
    }

    // Fallback to data source
    const value = await fallbackFactory();
    
    // Cache the result
    const cacheOptions: CacheOptions = {
      ...options,
    };
    
    if (options.fallbackTtl !== undefined) {
      cacheOptions.ttl = options.fallbackTtl;
    } else if (options.ttl !== undefined) {
      cacheOptions.ttl = options.ttl;
    }
    
    await this.set(key, value, cacheOptions);

    return value;
  }

  // Cache warming methods
  async warmCache<T>(
    keys: string[],
    factory: (key: string) => Promise<T>,
    options: CacheOptions = {}
  ): Promise<void> {
    const promises = keys.map(async (key) => {
      try {
        const exists = await this.exists(key, options);
        if (!exists) {
          const value = await factory(key);
          await this.set(key, value, options);
        }
      } catch (error) {
        logger.error('Cache warming error', { key, error: String(error) });
      }
    });

    await Promise.allSettled(promises);
    logger.info(`Cache warming completed for ${keys.length} keys`);
  }
}