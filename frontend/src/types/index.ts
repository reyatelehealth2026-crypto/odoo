// Core application types
export interface User {
  id: string;
  username: string;
  email: string;
  role: UserRole;
  lineAccountId: string;
  permissions?: Permission[];
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
}

export type UserRole = 'SUPER_ADMIN' | 'ADMIN' | 'PHARMACIST' | 'STAFF';

export enum Permission {
  VIEW_DASHBOARD = 'view_dashboard',
  MANAGE_ORDERS = 'manage_orders',
  PROCESS_PAYMENTS = 'process_payments',
  MANAGE_WEBHOOKS = 'manage_webhooks',
  ADMIN_ACCESS = 'admin_access',
}

// API Response types
export interface APIResponse<T> {
  success: boolean;
  data?: T;
  error?: APIError;
  meta?: ResponseMeta;
}

export interface APIError {
  code: string;
  message: string;
  details?: Record<string, any>;
  timestamp: string;
}

export interface ResponseMeta {
  page: number;
  limit: number;
  total: number;
  totalPages: number;
}

// Dashboard types
export interface DashboardMetrics {
  orders: OrderMetrics;
  payments: PaymentMetrics;
  webhooks: WebhookMetrics;
  customers: CustomerMetrics;
  updatedAt: Date;
}

export interface OrderMetrics {
  todayCount: number;
  todayTotal: number;
  pendingCount: number;
  completedCount: number;
  averageOrderValue: number;
  topProducts: ProductSummary[];
}

export interface PaymentMetrics {
  pendingSlips: number;
  processedToday: number;
  matchingRate: number;
  totalAmount: number;
  averageProcessingTime: number;
}

export interface WebhookMetrics {
  totalEvents: number;
  successRate: number;
  failedEvents: number;
  averageProcessingTime: number;
}

export interface CustomerMetrics {
  totalCustomers: number;
  newCustomersToday: number;
  activeCustomers: number;
  lineConnectedRate: number;
}

export interface ProductSummary {
  id: string;
  name: string;
  salesCount: number;
  revenue: number;
}

// Date range types
export interface DateRange {
  from: Date;
  to: Date;
}

// Order types
export interface Order {
  id: string;
  odooOrderId: string;
  lineAccountId: string;
  customerRef: string | null;
  customerName: string | null;
  status: string;
  totalAmount: number;
  currency: string;
  orderDate: Date | null;
  deliveryDate: Date | null;
  notes: string | null;
  webhookProcessed: boolean;
  createdAt: Date;
  updatedAt: Date;
  timeline: OrderTimelineEntry[];
}

export interface OrderTimelineEntry {
  id: string;
  orderId: string;
  status: string;
  previousStatus: string | null;
  notes: string | null;
  changedBy: string | null;
  changedAt: Date;
  source: 'system' | 'manual' | 'webhook';
}

export interface OrderFilters {
  status?: string[];
  customerRef?: string;
  customerName?: string;
  dateFrom?: Date;
  dateTo?: Date;
  search?: string;
}

export interface PaginatedOrders {
  data: Order[];
  meta: {
    page: number;
    limit: number;
    total: number;
    totalPages: number;
  };
}

export interface OrderStatistics {
  totalOrders: number;
  totalValue: number;
  statusBreakdown: Record<string, number>;
  averageOrderValue: number;
  topCustomers: Array<{
    customerName: string;
    orderCount: number;
    totalValue: number;
  }>;
}

// Notification types
export interface Notification {
  id: string;
  type: 'success' | 'error' | 'warning' | 'info';
  title: string;
  message: string;
  timestamp: Date;
  read: boolean;
}

// Re-export customer types
export * from './customers';
