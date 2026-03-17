/**
 * Standardized Error Types and Response Formats
 * Implements BR-2.2, NFR-2.2 requirements for comprehensive error handling
 */

export enum ErrorCode {
  // Authentication errors (401)
  INVALID_TOKEN = 'INVALID_TOKEN',
  TOKEN_EXPIRED = 'TOKEN_EXPIRED',
  INSUFFICIENT_PERMISSIONS = 'INSUFFICIENT_PERMISSIONS',
  
  // Validation errors (400)
  INVALID_REQUEST = 'INVALID_REQUEST',
  MISSING_REQUIRED_FIELD = 'MISSING_REQUIRED_FIELD',
  INVALID_DATE_RANGE = 'INVALID_DATE_RANGE',
  INVALID_FILE_FORMAT = 'INVALID_FILE_FORMAT',
  
  // Business logic errors (422)
  ORDER_NOT_FOUND = 'ORDER_NOT_FOUND',
  PAYMENT_ALREADY_MATCHED = 'PAYMENT_ALREADY_MATCHED',
  INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE',
  WEBHOOK_PROCESSING_FAILED = 'WEBHOOK_PROCESSING_FAILED',
  
  // System errors (500)
  DATABASE_ERROR = 'DATABASE_ERROR',
  EXTERNAL_SERVICE_ERROR = 'EXTERNAL_SERVICE_ERROR',
  CACHE_ERROR = 'CACHE_ERROR',
  CIRCUIT_BREAKER_OPEN = 'CIRCUIT_BREAKER_OPEN',
  
  // Rate limiting (429)
  RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED',
  
  // Service unavailable (503)
  SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE',
  MAINTENANCE_MODE = 'MAINTENANCE_MODE'
}

export interface APIError {
  code: ErrorCode;
  message: string;
  details?: Record<string, any>;
  timestamp: string;
  requestId: string;
  traceId?: string;
}

export interface APIResponse<T = any> {
  success: boolean;
  data?: T;
  error?: APIError;
  meta?: ResponseMeta;
}

export interface ResponseMeta {
  page?: number;
  limit?: number;
  total?: number;
  totalPages?: number;
  requestId: string;
  processingTime: number;
}

export class AppError extends Error {
  public readonly code: ErrorCode;
  public readonly statusCode: number;
  public readonly details?: Record<string, any>;
  public readonly isOperational: boolean;

  constructor(
    code: ErrorCode,
    message: string,
    statusCode: number = 500,
    details?: Record<string, any>,
    isOperational: boolean = true
  ) {
    super(message);
    this.name = 'AppError';
    this.code = code;
    this.statusCode = statusCode;
    this.details = details;
    this.isOperational = isOperational;

    Error.captureStackTrace(this, this.constructor);
  }
}

export class ValidationError extends AppError {
  constructor(message: string, details?: Record<string, any>) {
    super(ErrorCode.INVALID_REQUEST, message, 400, details);
  }
}

export class AuthenticationError extends AppError {
  constructor(code: ErrorCode = ErrorCode.INVALID_TOKEN, message: string = 'Authentication failed') {
    super(code, message, 401);
  }
}

export class AuthorizationError extends AppError {
  constructor(message: string = 'Insufficient permissions') {
    super(ErrorCode.INSUFFICIENT_PERMISSIONS, message, 403);
  }
}

export class NotFoundError extends AppError {
  constructor(resource: string, id?: string) {
    const message = id ? `${resource} with ID ${id} not found` : `${resource} not found`;
    super(ErrorCode.ORDER_NOT_FOUND, message, 404);
  }
}

export class BusinessLogicError extends AppError {
  constructor(code: ErrorCode, message: string, details?: Record<string, any>) {
    super(code, message, 422, details);
  }
}

export class ExternalServiceError extends AppError {
  constructor(service: string, message: string, details?: Record<string, any>) {
    super(
      ErrorCode.EXTERNAL_SERVICE_ERROR,
      `External service error: ${service} - ${message}`,
      502,
      details
    );
  }
}

export class DatabaseError extends AppError {
  constructor(message: string, details?: Record<string, any>) {
    super(ErrorCode.DATABASE_ERROR, message, 500, details);
  }
}

export class CacheError extends AppError {
  constructor(message: string, details?: Record<string, any>) {
    super(ErrorCode.CACHE_ERROR, message, 500, details);
  }
}

export class RateLimitError extends AppError {
  constructor(message: string = 'Rate limit exceeded') {
    super(ErrorCode.RATE_LIMIT_EXCEEDED, message, 429);
  }
}

export class ServiceUnavailableError extends AppError {
  constructor(message: string = 'Service temporarily unavailable') {
    super(ErrorCode.SERVICE_UNAVAILABLE, message, 503);
  }
}

// Error severity levels for logging
export enum ErrorSeverity {
  LOW = 'low',
  MEDIUM = 'medium',
  HIGH = 'high',
  CRITICAL = 'critical'
}

export interface ErrorLogEntry {
  id: string;
  timestamp: string;
  level: ErrorSeverity;
  code: ErrorCode;
  message: string;
  stack?: string;
  details?: Record<string, any>;
  requestId?: string;
  userId?: string;
  endpoint?: string;
  userAgent?: string;
  ipAddress?: string;
}