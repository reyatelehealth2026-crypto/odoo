// Common API response types
export interface APIResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: APIError;
  meta?: ResponseMeta;
}

export interface APIError {
  code: string;
  message: string;
  details?: Record<string, unknown>;
  timestamp: string;
}

export interface ResponseMeta {
  page: number;
  limit: number;
  total: number;
  totalPages: number;
}

// Pagination types
export interface PaginationParams {
  page?: number;
  limit?: number;
  sort?: string;
  order?: 'asc' | 'desc';
}

// Filter types
export interface FilterParams {
  dateFrom?: string;
  dateTo?: string;
  status?: string[];
  search?: string;
  customerId?: string;
}

// JWT payload type
export interface JWTPayload {
  userId: string;
  role: string;
  lineAccountId: string;
  permissions: string[];
  iat: number;
  exp: number;
}

// Request context type
export interface RequestContext {
  user: JWTPayload;
  requestId: string;
  ipAddress: string;
  userAgent: string;
}