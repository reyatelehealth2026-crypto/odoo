import { FastifyRequest, FastifyReply } from 'fastify';
import { z } from 'zod';
import { logger } from '@/utils/logger';

/**
 * Security Middleware for Input Validation and XSS Protection
 * 
 * Implements comprehensive security measures including:
 * - Input validation and sanitization
 * - XSS protection
 * - Content Security Policy
 * - SQL injection prevention
 * 
 * Requirements: BR-5.3, NFR-3.3
 */

// Common validation schemas
export const commonSchemas = {
  uuid: z.string().uuid('Invalid UUID format'),
  email: z.string().email('Invalid email format'),
  password: z.string().min(8, 'Password must be at least 8 characters')
    .regex(/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/, 
      'Password must contain uppercase, lowercase, number and special character'),
  username: z.string().min(3, 'Username must be at least 3 characters')
    .max(50, 'Username must be less than 50 characters')
    .regex(/^[a-zA-Z0-9_-]+$/, 'Username can only contain letters, numbers, underscore and dash'),
  phoneNumber: z.string().regex(/^\+?[1-9]\d{1,14}$/, 'Invalid phone number format'),
  url: z.string().url('Invalid URL format'),
  ipAddress: z.string().ip('Invalid IP address format'),
  dateString: z.string().datetime('Invalid date format'),
  positiveNumber: z.number().positive('Must be a positive number'),
  nonEmptyString: z.string().min(1, 'Field cannot be empty').trim(),
  safeString: z.string().max(1000, 'String too long').trim()
    .refine(val => !/<script|javascript:|data:|vbscript:/i.test(val), 
      'Potentially dangerous content detected'),
};

// Request validation schemas
export const validationSchemas = {
  // Authentication schemas
  login: z.object({
    username: commonSchemas.username,
    password: z.string().min(1, 'Password required'),
    lineAccountId: commonSchemas.uuid,
    rememberMe: z.boolean().optional(),
  }),

  refreshToken: z.object({
    refreshToken: z.string().min(1, 'Refresh token required'),
  }),

  // Dashboard schemas
  dashboardMetrics: z.object({
    dateFrom: commonSchemas.dateString.optional(),
    dateTo: commonSchemas.dateString.optional(),
    metricTypes: z.array(z.enum(['orders', 'payments', 'webhooks', 'customers'])).optional(),
    lineAccountId: commonSchemas.uuid.optional(),
  }),

  // Order schemas
  orderUpdate: z.object({
    status: z.enum(['pending', 'processing', 'completed', 'cancelled', 'refunded']),
    notes: commonSchemas.safeString.max(500).optional(),
    notifyCustomer: z.boolean().default(false),
    internalNotes: commonSchemas.safeString.max(1000).optional(),
  }),

  orderSearch: z.object({
    query: commonSchemas.safeString.max(100).optional(),
    status: z.array(z.string()).optional(),
    customerId: commonSchemas.uuid.optional(),
    dateFrom: commonSchemas.dateString.optional(),
    dateTo: commonSchemas.dateString.optional(),
    page: z.number().int().min(1).default(1),
    limit: z.number().int().min(1).max(100).default(20),
    sortBy: z.enum(['created_at', 'updated_at', 'total_amount', 'status']).default('created_at'),
    sortOrder: z.enum(['asc', 'desc']).default('desc'),
  }),

  // Payment schemas
  paymentSlipUpload: z.object({
    orderId: commonSchemas.uuid.optional(),
    amount: commonSchemas.positiveNumber,
    currency: z.string().length(3, 'Currency must be 3 characters').default('THB'),
    notes: commonSchemas.safeString.max(500).optional(),
    bankAccount: commonSchemas.safeString.max(100).optional(),
  }),

  paymentMatch: z.object({
    slipId: commonSchemas.uuid,
    orderId: commonSchemas.uuid,
    matchType: z.enum(['automatic', 'manual']).default('manual'),
    confidence: z.number().min(0).max(1).optional(),
    notes: commonSchemas.safeString.max(500).optional(),
  }),

  // Webhook schemas
  webhookRetry: z.object({
    webhookId: commonSchemas.uuid,
    reason: commonSchemas.safeString.max(200).optional(),
  }),

  // Customer schemas
  customerSearch: z.object({
    query: commonSchemas.safeString.max(100).optional(),
    customerRef: commonSchemas.safeString.max(50).optional(),
    partnerId: z.string().max(50).optional(),
    email: commonSchemas.email.optional(),
    phone: commonSchemas.phoneNumber.optional(),
    page: z.number().int().min(1).default(1),
    limit: z.number().int().min(1).max(100).default(20),
  }),

  // Audit log schemas
  auditLogQuery: z.object({
    userId: commonSchemas.uuid.optional(),
    action: commonSchemas.safeString.max(100).optional(),
    resourceType: commonSchemas.safeString.max(50).optional(),
    resourceId: commonSchemas.uuid.optional(),
    dateFrom: commonSchemas.dateString.optional(),
    dateTo: commonSchemas.dateString.optional(),
    success: z.boolean().optional(),
    page: z.number().int().min(1).default(1),
    limit: z.number().int().min(1).max(100).default(50),
  }),
};

/**
 * Content Security Policy middleware
 */
export const contentSecurityPolicy = async (
  request: FastifyRequest,
  reply: FastifyReply
): Promise<void> => {
  const cspDirectives = [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
    "img-src 'self' data: https: blob:",
    "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net",
    "connect-src 'self' wss: https://api.line.me https://*.odoo.com",
    "media-src 'self' blob:",
    "object-src 'none'",
    "frame-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
    "upgrade-insecure-requests",
  ].join('; ');

  reply.header('Content-Security-Policy', cspDirectives);
  reply.header('X-Content-Type-Options', 'nosniff');
  reply.header('X-Frame-Options', 'DENY');
  reply.header('X-XSS-Protection', '1; mode=block');
  reply.header('Referrer-Policy', 'strict-origin-when-cross-origin');
  reply.header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
};

/**
 * Input sanitization utility
 */
export class InputSanitizer {
  /**
   * Sanitize HTML content to prevent XSS
   */
  static sanitizeHtml(input: string): string {
    if (typeof input !== 'string') return '';
    
    return input
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#x27;')
      .replace(/\//g, '&#x2F;')
      .replace(/&/g, '&amp;');
  }

  /**
   * Sanitize SQL input (additional layer beyond Prisma protection)
   */
  static sanitizeSql(input: string): string {
    if (typeof input !== 'string') return '';
    
    // Remove common SQL injection patterns
    const sqlPatterns = [
      /(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b)/gi,
      /(--|\/\*|\*\/|;|'|"|`)/g,
      /(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/gi,
      /\b(UNION|SELECT)\b.*\b(FROM|WHERE)\b/gi,
    ];

    let sanitized = input;
    sqlPatterns.forEach(pattern => {
      sanitized = sanitized.replace(pattern, '');
    });

    return sanitized.trim();
  }

  /**
   * Sanitize file names
   */
  static sanitizeFileName(fileName: string): string {
    if (typeof fileName !== 'string') return '';
    
    return fileName
      .replace(/[^a-zA-Z0-9._-]/g, '_')
      .replace(/\.{2,}/g, '.')
      .replace(/^\.+|\.+$/g, '')
      .substring(0, 255);
  }

  /**
   * Validate and sanitize JSON input
   */
  static sanitizeJson(input: any): any {
    if (typeof input === 'string') {
      try {
        input = JSON.parse(input);
      } catch {
        throw new Error('Invalid JSON format');
      }
    }

    // Recursively sanitize object properties
    if (typeof input === 'object' && input !== null) {
      if (Array.isArray(input)) {
        return input.map(item => this.sanitizeJson(item));
      } else {
        const sanitized: any = {};
        for (const [key, value] of Object.entries(input)) {
          const sanitizedKey = this.sanitizeHtml(key);
          sanitized[sanitizedKey] = this.sanitizeJson(value);
        }
        return sanitized;
      }
    }

    if (typeof input === 'string') {
      return this.sanitizeHtml(input);
    }

    return input;
  }
}

/**
 * Request sanitization middleware
 */
export const sanitizeRequest = async (
  request: FastifyRequest,
  reply: FastifyReply
): Promise<void> => {
  try {
    // Sanitize request body
    if (request.body && typeof request.body === 'object') {
      request.body = InputSanitizer.sanitizeJson(request.body);
    }

    // Sanitize query parameters
    if (request.query && typeof request.query === 'object') {
      const sanitizedQuery: any = {};
      for (const [key, value] of Object.entries(request.query)) {
        const sanitizedKey = InputSanitizer.sanitizeHtml(key);
        if (typeof value === 'string') {
          sanitizedQuery[sanitizedKey] = InputSanitizer.sanitizeHtml(value);
        } else {
          sanitizedQuery[sanitizedKey] = value;
        }
      }
      request.query = sanitizedQuery;
    }

    // Sanitize URL parameters
    if (request.params && typeof request.params === 'object') {
      const sanitizedParams: any = {};
      for (const [key, value] of Object.entries(request.params)) {
        const sanitizedKey = InputSanitizer.sanitizeHtml(key);
        if (typeof value === 'string') {
          sanitizedParams[sanitizedKey] = InputSanitizer.sanitizeHtml(value);
        } else {
          sanitizedParams[sanitizedKey] = value;
        }
      }
      request.params = sanitizedParams;
    }

  } catch (error) {
    logger.error('Request sanitization failed', {
      error: String(error),
      url: request.url,
      method: request.method,
    });

    return reply.status(400).send({
      success: false,
      error: {
        code: 'INVALID_INPUT',
        message: 'Request contains invalid or potentially dangerous content',
        timestamp: new Date().toISOString(),
      },
    });
  }
};

/**
 * File upload validation
 */
export const validateFileUpload = (
  allowedTypes: string[] = ['image/jpeg', 'image/png', 'image/gif'],
  maxSize: number = 5 * 1024 * 1024 // 5MB
) => {
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    const contentType = request.headers['content-type'];
    const contentLength = parseInt(request.headers['content-length'] || '0');

    // Check file size
    if (contentLength > maxSize) {
      return reply.status(413).send({
        success: false,
        error: {
          code: 'FILE_TOO_LARGE',
          message: `File size exceeds maximum allowed size of ${maxSize} bytes`,
          timestamp: new Date().toISOString(),
        },
      });
    }

    // Check content type
    if (contentType && !allowedTypes.some(type => contentType.includes(type))) {
      return reply.status(415).send({
        success: false,
        error: {
          code: 'UNSUPPORTED_MEDIA_TYPE',
          message: `File type not allowed. Allowed types: ${allowedTypes.join(', ')}`,
          timestamp: new Date().toISOString(),
        },
      });
    }
  };
};

/**
 * SQL injection detection middleware
 */
export const detectSqlInjection = async (
  request: FastifyRequest,
  reply: FastifyReply
): Promise<void> => {
  const suspiciousPatterns = [
    /(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b.*\b(FROM|WHERE|INTO|VALUES|SET)\b)/gi,
    /(--|\/\*|\*\/|;)\s*(SELECT|INSERT|UPDATE|DELETE|DROP)/gi,
    /(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/gi,
    /\b(UNION|SELECT)\b.*\b(FROM|WHERE)\b/gi,
    /'.*(\bOR\b|\bAND\b).*'/gi,
  ];

  const checkForSqlInjection = (obj: any, path: string = ''): boolean => {
    if (typeof obj === 'string') {
      return suspiciousPatterns.some(pattern => pattern.test(obj));
    }

    if (typeof obj === 'object' && obj !== null) {
      for (const [key, value] of Object.entries(obj)) {
        if (checkForSqlInjection(value, `${path}.${key}`)) {
          return true;
        }
      }
    }

    return false;
  };

  // Check request body, query, and params
  const requestData = {
    body: request.body,
    query: request.query,
    params: request.params,
  };

  if (checkForSqlInjection(requestData)) {
    logger.warn('Potential SQL injection attempt detected', {
      url: request.url,
      method: request.method,
      ip: request.ip,
      userAgent: request.headers['user-agent'],
      body: request.body,
      query: request.query,
      params: request.params,
    });

    return reply.status(400).send({
      success: false,
      error: {
        code: 'SUSPICIOUS_INPUT',
        message: 'Request contains potentially malicious content',
        timestamp: new Date().toISOString(),
      },
    });
  }
};

/**
 * Request size limiter
 */
export const limitRequestSize = (maxSize: number = 1024 * 1024) => { // 1MB default
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    const contentLength = parseInt(request.headers['content-length'] || '0');
    
    if (contentLength > maxSize) {
      return reply.status(413).send({
        success: false,
        error: {
          code: 'REQUEST_TOO_LARGE',
          message: `Request size exceeds maximum allowed size of ${maxSize} bytes`,
          timestamp: new Date().toISOString(),
        },
      });
    }
  };
};