import { FastifyRequest, FastifyReply } from 'fastify';
import { SecurityMonitoringService } from '@/services/SecurityMonitoringService';
import { AuditService } from '@/services/AuditService';
import { logger } from '@/utils/logger';

/**
 * Security Integration Middleware
 * 
 * Integrates all security components:
 * - Threat detection
 * - Audit logging
 * - Rate limiting
 * - Input validation
 * 
 * Requirements: BR-5.3, NFR-3.3
 */

export class SecurityIntegrationMiddleware {
  private securityService: SecurityMonitoringService;
  private auditService: AuditService;

  constructor(securityService: SecurityMonitoringService, auditService: AuditService) {
    this.securityService = securityService;
    this.auditService = auditService;
  }

  /**
   * Comprehensive security monitoring middleware
   */
  createSecurityMiddleware() {
    return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
      const startTime = Date.now();
      const ip = request.ip;
      const userAgent = request.headers['user-agent'] || '';
      const user = (request as any).user;
      const userId = user?.userId;

      try {
        // 1. Monitor for brute force attacks (on authentication endpoints)
        if (request.url.includes('/auth/login') && request.method === 'POST') {
          await this.securityService.monitorBruteForce(ip, userId);
        }

        // 2. Monitor for SQL injection attempts
        if (request.body || request.query || request.params) {
          const payload = {
            body: request.body,
            query: request.query,
            params: request.params,
          };
          await this.securityService.monitorSqlInjection(ip, userAgent, payload, userId);
        }

        // 3. Monitor for XSS attempts
        if (request.body && typeof request.body === 'object') {
          await this.securityService.monitorXssAttempts(ip, userAgent, request.body, userId);
        }

        // 4. Monitor for suspicious activity patterns
        await this.securityService.monitorSuspiciousActivity(ip, userAgent, userId);

        // 5. Log request for audit trail (for sensitive endpoints)
        if (this.isSensitiveEndpoint(request.url, request.method)) {
          await this.auditService.logAction({
            userId: userId || 'anonymous',
            action: `${request.method.toLowerCase()}_${this.getResourceType(request.url)}`,
            resourceType: this.getResourceType(request.url),
            resourceId: this.extractResourceId(request.url, request.params),
            ipAddress: ip,
            userAgent,
            success: true, // Will be updated in response handler
            metadata: {
              endpoint: request.url,
              method: request.method,
              requestSize: this.getRequestSize(request),
            },
          });
        }

        // Add security headers to response
        this.addSecurityHeaders(reply);

        // Hook into response to log completion
        reply.addHook('onSend', async (request, reply, payload) => {
          const duration = Date.now() - startTime;
          const statusCode = reply.statusCode;

          // Log failed requests for security analysis
          if (statusCode >= 400) {
            await this.handleFailedRequest(request, reply, statusCode, duration);
          }

          // Log successful sensitive operations
          if (statusCode < 400 && this.isSensitiveEndpoint(request.url, request.method)) {
            await this.handleSuccessfulRequest(request, reply, duration);
          }

          return payload;
        });

      } catch (error) {
        logger.error('Security middleware error', {
          error: String(error),
          ip,
          url: request.url,
          method: request.method,
        });
        // Don't block request on security middleware errors
      }
    };
  }

  /**
   * Handle failed requests for security analysis
   */
  private async handleFailedRequest(
    request: FastifyRequest,
    reply: FastifyReply,
    statusCode: number,
    duration: number
  ): Promise<void> {
    const ip = request.ip;
    const userAgent = request.headers['user-agent'] || '';
    const user = (request as any).user;
    const userId = user?.userId;

    // Log security event for certain types of failures
    if (statusCode === 401 || statusCode === 403) {
      await this.securityService.detectThreat('unauthorized_access', 
        { ip, userAgent, userId }, 
        {
          statusCode,
          endpoint: request.url,
          method: request.method,
          duration,
        }
      );
    }

    // Track failed login attempts
    if (request.url.includes('/auth/login') && statusCode === 401) {
      const key = `failed_login:${ip}`;
      const redis = (request.server as any).redis;
      await redis.zadd(key, Date.now(), `${Date.now()}-${Math.random()}`);
      await redis.expire(key, 900); // 15 minutes
    }
  }

  /**
   * Handle successful requests for audit logging
   */
  private async handleSuccessfulRequest(
    request: FastifyRequest,
    reply: FastifyReply,
    duration: number
  ): Promise<void> {
    const user = (request as any).user;
    if (!user) return;

    // Log successful sensitive operations
    await this.auditService.logAction({
      userId: user.userId,
      action: `${request.method.toLowerCase()}_${this.getResourceType(request.url)}_success`,
      resourceType: this.getResourceType(request.url),
      resourceId: this.extractResourceId(request.url, request.params),
      ipAddress: request.ip,
      userAgent: request.headers['user-agent'],
      success: true,
      metadata: {
        endpoint: request.url,
        method: request.method,
        statusCode: reply.statusCode,
        duration,
        responseSize: this.getResponseSize(reply),
      },
    });
  }

  /**
   * Add security headers to response
   */
  private addSecurityHeaders(reply: FastifyReply): void {
    reply.header('X-Content-Type-Options', 'nosniff');
    reply.header('X-Frame-Options', 'DENY');
    reply.header('X-XSS-Protection', '1; mode=block');
    reply.header('Referrer-Policy', 'strict-origin-when-cross-origin');
    reply.header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    reply.header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
  }

  /**
   * Check if endpoint is sensitive and requires audit logging
   */
  private isSensitiveEndpoint(url: string, method: string): boolean {
    const sensitivePatterns = [
      /\/auth\/(login|logout|refresh)/,
      /\/audit\//,
      /\/security\//,
      /\/orders\/.*\/status/,
      /\/payments\//,
      /\/users\/.*\/(role|permissions)/,
    ];

    const sensitiveMethods = ['POST', 'PUT', 'DELETE'];

    return sensitivePatterns.some(pattern => pattern.test(url)) || 
           (sensitiveMethods.includes(method) && !url.includes('/health'));
  }

  /**
   * Extract resource type from URL
   */
  private getResourceType(url: string): string {
    if (url.includes('/auth/')) return 'authentication';
    if (url.includes('/audit/')) return 'audit';
    if (url.includes('/security/')) return 'security';
    if (url.includes('/orders/')) return 'order';
    if (url.includes('/payments/')) return 'payment';
    if (url.includes('/dashboard/')) return 'dashboard';
    if (url.includes('/users/')) return 'user';
    if (url.includes('/webhooks/')) return 'webhook';
    return 'unknown';
  }

  /**
   * Extract resource ID from URL parameters
   */
  private extractResourceId(url: string, params: any): string | undefined {
    if (params && typeof params === 'object') {
      // Common ID parameter names
      const idFields = ['id', 'orderId', 'paymentId', 'userId', 'webhookId'];
      for (const field of idFields) {
        if (params[field]) {
          return params[field];
        }
      }
    }

    // Extract UUID from URL path
    const uuidRegex = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;
    const match = url.match(uuidRegex);
    return match ? match[0] : undefined;
  }

  /**
   * Get request size for logging
   */
  private getRequestSize(request: FastifyRequest): number {
    const contentLength = request.headers['content-length'];
    if (contentLength) {
      return parseInt(contentLength, 10);
    }

    if (request.body) {
      return JSON.stringify(request.body).length;
    }

    return 0;
  }

  /**
   * Get response size for logging
   */
  private getResponseSize(reply: FastifyReply): number {
    const contentLength = reply.getHeader('content-length');
    if (contentLength) {
      return parseInt(String(contentLength), 10);
    }
    return 0;
  }
}

/**
 * Factory function to create security integration middleware
 */
export const createSecurityIntegration = (
  securityService: SecurityMonitoringService,
  auditService: AuditService
) => {
  const integration = new SecurityIntegrationMiddleware(securityService, auditService);
  return integration.createSecurityMiddleware();
};