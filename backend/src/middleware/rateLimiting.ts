import { FastifyRequest, FastifyReply } from 'fastify';
import { logger } from '@/utils/logger';

/**
 * Advanced Rate Limiting and DDoS Protection Middleware
 * 
 * Implements comprehensive rate limiting including:
 * - Per-IP rate limiting
 * - Per-user rate limiting
 * - Endpoint-specific limits
 * - Sliding window algorithm
 * - IP-based blocking for suspicious activity
 * - Progressive penalties
 * 
 * Requirements: NFR-3.3
 */

export interface RateLimitConfig {
  windowMs: number;           // Time window in milliseconds
  maxRequests: number;        // Max requests per window
  skipSuccessfulRequests?: boolean;
  skipFailedRequests?: boolean;
  keyGenerator?: (req: FastifyRequest) => string;
  onLimitReached?: (req: FastifyRequest, reply: FastifyReply) => void;
  progressivePenalty?: boolean;
}

export interface SecurityConfig {
  maxFailedAttempts: number;  // Max failed attempts before blocking
  blockDurationMs: number;    // How long to block suspicious IPs
  suspiciousThreshold: number; // Requests per minute that trigger suspicion
  whitelistedIPs: string[];   // IPs to never block
  blacklistedIPs: string[];   // IPs to always block
}

// Default rate limit configurations for different endpoint types
export const rateLimitConfigs = {
  // Authentication endpoints - very strict
  auth: {
    windowMs: 15 * 60 * 1000,   // 15 minutes
    maxRequests: 5,              // 5 attempts per 15 minutes
    progressivePenalty: true,
  },

  // Password reset - strict
  passwordReset: {
    windowMs: 60 * 60 * 1000,   // 1 hour
    maxRequests: 3,              // 3 attempts per hour
    progressivePenalty: true,
  },

  // File upload - moderate
  upload: {
    windowMs: 60 * 1000,        // 1 minute
    maxRequests: 10,             // 10 uploads per minute
    progressivePenalty: false,
  },

  // API endpoints - moderate
  api: {
    windowMs: 60 * 1000,        // 1 minute
    maxRequests: 100,            // 100 requests per minute
    progressivePenalty: false,
  },

  // Dashboard - lenient
  dashboard: {
    windowMs: 60 * 1000,        // 1 minute
    maxRequests: 200,            // 200 requests per minute
    progressivePenalty: false,
  },

  // Search endpoints - moderate
  search: {
    windowMs: 60 * 1000,        // 1 minute
    maxRequests: 50,             // 50 searches per minute
    progressivePenalty: false,
  },

  // Webhook endpoints - strict
  webhook: {
    windowMs: 60 * 1000,        // 1 minute
    maxRequests: 30,             // 30 webhook calls per minute
    progressivePenalty: true,
  },
};

// Security configuration
export const securityConfig: SecurityConfig = {
  maxFailedAttempts: 10,
  blockDurationMs: 30 * 60 * 1000, // 30 minutes
  suspiciousThreshold: 300,         // 300 requests per minute
  whitelistedIPs: [
    '127.0.0.1',
    '::1',
    // Add your server IPs here
  ],
  blacklistedIPs: [
    // Add known malicious IPs here
  ],
};

export class RateLimitService {
  private redis: any;
  private securityEvents: Map<string, any[]> = new Map();

  constructor(redisClient: any) {
    this.redis = redisClient;
  }

  /**
   * Create rate limiting middleware
   */
  createRateLimit(config: RateLimitConfig) {
    return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
      const key = config.keyGenerator ? config.keyGenerator(request) : this.getDefaultKey(request);
      const now = Date.now();
      const windowStart = now - config.windowMs;

      try {
        // Check if IP is blacklisted
        if (await this.isBlacklisted(request.ip)) {
          return this.sendBlockedResponse(reply, 'IP_BLACKLISTED');
        }

        // Check if IP is temporarily blocked
        if (await this.isTemporarilyBlocked(request.ip)) {
          return this.sendBlockedResponse(reply, 'IP_TEMPORARILY_BLOCKED');
        }

        // Get current request count in window
        const requestCount = await this.getRequestCount(key, windowStart, now);
        
        // Check if limit exceeded
        if (requestCount >= config.maxRequests) {
          await this.handleLimitExceeded(request, reply, config);
          return;
        }

        // Record this request
        await this.recordRequest(key, now);

        // Set rate limit headers
        this.setRateLimitHeaders(reply, config, requestCount);

        // Check for suspicious activity
        await this.checkSuspiciousActivity(request);

      } catch (error) {
        logger.error('Rate limiting error', {
          error: String(error),
          ip: request.ip,
          url: request.url,
        });
        // Don't block on rate limiting errors, just log
      }
    };
  }

  /**
   * Get request count in sliding window
   */
  private async getRequestCount(key: string, windowStart: number, now: number): Promise<number> {
    const pipeline = this.redis.pipeline();
    
    // Remove old entries
    pipeline.zremrangebyscore(key, 0, windowStart);
    
    // Count current entries
    pipeline.zcard(key);
    
    // Set expiration
    pipeline.expire(key, Math.ceil((now - windowStart) / 1000));
    
    const results = await pipeline.exec();
    return results[1][1] || 0;
  }

  /**
   * Record a request in the sliding window
   */
  private async recordRequest(key: string, timestamp: number): Promise<void> {
    const requestId = `${timestamp}-${Math.random()}`;
    await this.redis.zadd(key, timestamp, requestId);
  }

  /**
   * Handle rate limit exceeded
   */
  private async handleLimitExceeded(
    request: FastifyRequest,
    reply: FastifyReply,
    config: RateLimitConfig
  ): Promise<void> {
    const ip = request.ip;
    
    // Record failed attempt
    await this.recordFailedAttempt(ip);
    
    // Check if progressive penalty should be applied
    if (config.progressivePenalty) {
      const failedAttempts = await this.getFailedAttempts(ip);
      if (failedAttempts >= securityConfig.maxFailedAttempts) {
        await this.blockIP(ip, securityConfig.blockDurationMs);
        logger.warn('IP blocked due to excessive rate limit violations', {
          ip,
          failedAttempts,
          url: request.url,
        });
      }
    }

    // Log rate limit exceeded
    logger.warn('Rate limit exceeded', {
      ip,
      url: request.url,
      method: request.method,
      userAgent: request.headers['user-agent'],
    });

    // Call custom handler if provided
    if (config.onLimitReached) {
      config.onLimitReached(request, reply);
      return;
    }

    // Send rate limit response
    const retryAfter = Math.ceil(config.windowMs / 1000);
    reply.header('Retry-After', retryAfter.toString());
    
    return reply.status(429).send({
      success: false,
      error: {
        code: 'RATE_LIMIT_EXCEEDED',
        message: 'Too many requests. Please try again later.',
        retryAfter,
        timestamp: new Date().toISOString(),
      },
    });
  }

  /**
   * Check for suspicious activity patterns
   */
  private async checkSuspiciousActivity(request: FastifyRequest): Promise<void> {
    const ip = request.ip;
    const now = Date.now();
    const windowStart = now - 60 * 1000; // 1 minute window

    // Get request count in the last minute
    const recentRequests = await this.getRequestCount(`suspicious:${ip}`, windowStart, now);
    
    if (recentRequests > securityConfig.suspiciousThreshold) {
      // Record security event
      await this.recordSecurityEvent(ip, 'SUSPICIOUS_ACTIVITY', {
        requestCount: recentRequests,
        url: request.url,
        userAgent: request.headers['user-agent'],
      });

      // Temporarily block IP
      await this.blockIP(ip, securityConfig.blockDurationMs);
      
      logger.warn('Suspicious activity detected - IP blocked', {
        ip,
        requestCount: recentRequests,
        threshold: securityConfig.suspiciousThreshold,
      });
    }

    // Record request for suspicious activity tracking
    await this.recordRequest(`suspicious:${ip}`, now);
  }

  /**
   * Record failed attempt
   */
  private async recordFailedAttempt(ip: string): Promise<void> {
    const key = `failed:${ip}`;
    const count = await this.redis.incr(key);
    
    if (count === 1) {
      // Set expiration for first failed attempt
      await this.redis.expire(key, 3600); // 1 hour
    }
  }

  /**
   * Get failed attempts count
   */
  private async getFailedAttempts(ip: string): Promise<number> {
    const count = await this.redis.get(`failed:${ip}`);
    return parseInt(count) || 0;
  }

  /**
   * Block IP temporarily
   */
  private async blockIP(ip: string, durationMs: number): Promise<void> {
    const key = `blocked:${ip}`;
    const expirationSeconds = Math.ceil(durationMs / 1000);
    await this.redis.setex(key, expirationSeconds, Date.now().toString());
  }

  /**
   * Check if IP is temporarily blocked
   */
  private async isTemporarilyBlocked(ip: string): Promise<boolean> {
    const blocked = await this.redis.get(`blocked:${ip}`);
    return blocked !== null;
  }

  /**
   * Check if IP is blacklisted
   */
  private async isBlacklisted(ip: string): Promise<boolean> {
    // Check static blacklist
    if (securityConfig.blacklistedIPs.includes(ip)) {
      return true;
    }

    // Check dynamic blacklist in Redis
    const blacklisted = await this.redis.get(`blacklist:${ip}`);
    return blacklisted !== null;
  }

  /**
   * Check if IP is whitelisted
   */
  private isWhitelisted(ip: string): boolean {
    return securityConfig.whitelistedIPs.includes(ip);
  }

  /**
   * Record security event
   */
  private async recordSecurityEvent(ip: string, eventType: string, details: any): Promise<void> {
    const event = {
      ip,
      eventType,
      details,
      timestamp: new Date().toISOString(),
    };

    // Store in Redis for immediate access
    const key = `security_events:${ip}`;
    await this.redis.lpush(key, JSON.stringify(event));
    await this.redis.ltrim(key, 0, 99); // Keep last 100 events
    await this.redis.expire(key, 86400); // 24 hours

    // Also store in memory for quick access
    if (!this.securityEvents.has(ip)) {
      this.securityEvents.set(ip, []);
    }
    const events = this.securityEvents.get(ip)!;
    events.unshift(event);
    if (events.length > 50) {
      events.splice(50);
    }
  }

  /**
   * Get default rate limit key
   */
  private getDefaultKey(request: FastifyRequest): string {
    const user = (request as any).user;
    if (user && user.userId) {
      return `rate_limit:user:${user.userId}`;
    }
    return `rate_limit:ip:${request.ip}`;
  }

  /**
   * Set rate limit headers
   */
  private setRateLimitHeaders(
    reply: FastifyReply,
    config: RateLimitConfig,
    currentCount: number
  ): void {
    reply.header('X-RateLimit-Limit', config.maxRequests.toString());
    reply.header('X-RateLimit-Remaining', Math.max(0, config.maxRequests - currentCount).toString());
    reply.header('X-RateLimit-Reset', new Date(Date.now() + config.windowMs).toISOString());
  }

  /**
   * Send blocked response
   */
  private sendBlockedResponse(reply: FastifyReply, reason: string): void {
    return reply.status(403).send({
      success: false,
      error: {
        code: reason,
        message: 'Access denied due to security policy',
        timestamp: new Date().toISOString(),
      },
    });
  }

  /**
   * Get security events for IP
   */
  async getSecurityEvents(ip: string): Promise<any[]> {
    try {
      const key = `security_events:${ip}`;
      const events = await this.redis.lrange(key, 0, -1);
      return events.map((event: string) => JSON.parse(event));
    } catch (error) {
      logger.error('Failed to get security events', { error: String(error), ip });
      return [];
    }
  }

  /**
   * Manually blacklist IP
   */
  async blacklistIP(ip: string, reason: string, duration?: number): Promise<void> {
    const key = `blacklist:${ip}`;
    const data = {
      reason,
      timestamp: new Date().toISOString(),
    };

    if (duration) {
      await this.redis.setex(key, Math.ceil(duration / 1000), JSON.stringify(data));
    } else {
      await this.redis.set(key, JSON.stringify(data));
    }

    logger.info('IP blacklisted', { ip, reason, duration });
  }

  /**
   * Remove IP from blacklist
   */
  async removeFromBlacklist(ip: string): Promise<void> {
    await this.redis.del(`blacklist:${ip}`);
    logger.info('IP removed from blacklist', { ip });
  }

  /**
   * Get rate limit statistics
   */
  async getStatistics(): Promise<any> {
    try {
      const stats = {
        blockedIPs: 0,
        blacklistedIPs: 0,
        recentSecurityEvents: 0,
        topBlockedIPs: [],
      };

      // Count blocked IPs
      const blockedKeys = await this.redis.keys('blocked:*');
      stats.blockedIPs = blockedKeys.length;

      // Count blacklisted IPs
      const blacklistedKeys = await this.redis.keys('blacklist:*');
      stats.blacklistedIPs = blacklistedKeys.length;

      // Count recent security events
      const eventKeys = await this.redis.keys('security_events:*');
      stats.recentSecurityEvents = eventKeys.length;

      return stats;
    } catch (error) {
      logger.error('Failed to get rate limit statistics', { error: String(error) });
      return {};
    }
  }
}

/**
 * Create rate limiting middleware factory
 */
export const createRateLimitMiddleware = (redisClient: any) => {
  const rateLimitService = new RateLimitService(redisClient);

  return {
    // Pre-configured middleware for different endpoint types
    auth: rateLimitService.createRateLimit(rateLimitConfigs.auth),
    passwordReset: rateLimitService.createRateLimit(rateLimitConfigs.passwordReset),
    upload: rateLimitService.createRateLimit(rateLimitConfigs.upload),
    api: rateLimitService.createRateLimit(rateLimitConfigs.api),
    dashboard: rateLimitService.createRateLimit(rateLimitConfigs.dashboard),
    search: rateLimitService.createRateLimit(rateLimitConfigs.search),
    webhook: rateLimitService.createRateLimit(rateLimitConfigs.webhook),

    // Custom rate limit creator
    custom: (config: RateLimitConfig) => rateLimitService.createRateLimit(config),

    // Service instance for management operations
    service: rateLimitService,
  };
};