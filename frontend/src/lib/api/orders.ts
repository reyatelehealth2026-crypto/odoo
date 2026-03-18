import { apiClient } from './client';
import { 
  Order, 
  PaginatedOrders, 
  OrderFilters, 
  OrderTimelineEntry, 
  OrderStatistics,
  APIResponse 
} from '@/types';

export interface OrderListParams extends OrderFilters {
  page?: number;
  limit?: number;
  sort?: string;
  order?: 'asc' | 'desc';
  lineAccountId?: string;
}

export interface OrderStatusUpdate {
  status: string;
  notes?: string;
  notifyCustomer?: boolean;
}

export interface OrderSearchParams {
  query: string;
  page?: number;
  limit?: number;
  status?: string;
  dateFrom?: string;
  dateTo?: string;
}

export class OrderAPI {
  /**
   * Get paginated list of orders with filtering
   */
  async getOrders(params: OrderListParams = {}): Promise<PaginatedOrders> {
    const searchParams = new URLSearchParams();
    
    if (params.page) searchParams.set('page', params.page.toString());
    if (params.limit) searchParams.set('limit', params.limit.toString());
    if (params.sort) searchParams.set('sort', params.sort);
    if (params.order) searchParams.set('order', params.order);
    if (params.status?.length) searchParams.set('status', params.status.join(','));
    if (params.customerRef) searchParams.set('customerRef', params.customerRef);
    if (params.customerName) searchParams.set('customerName', params.customerName);
    if (params.dateFrom) searchParams.set('dateFrom', params.dateFrom.toISOString().split('T')[0]);
    if (params.dateTo) searchParams.set('dateTo', params.dateTo.toISOString().split('T')[0]);
    if (params.search) searchParams.set('search', params.search);
    if (params.lineAccountId) searchParams.set('lineAccountId', params.lineAccountId);

    const response = await apiClient.get<PaginatedOrders>(`/orders?${searchParams.toString()}`);
    return response.data!;
  }

  /**
   * Get specific order details with timeline
   */
  async getOrderById(orderId: string): Promise<Order> {
    const response = await apiClient.get<Order>(`/orders/${orderId}`);
    return response.data!;
  }

  /**
   * Update order status with audit trail
   */
  async updateOrderStatus(orderId: string, update: OrderStatusUpdate): Promise<Order> {
    const response = await apiClient.put<Order>(`/orders/${orderId}/status`, update);
    return response.data!;
  }

  /**
   * Get order status timeline
   */
  async getOrderTimeline(orderId: string): Promise<OrderTimelineEntry[]> {
    const response = await apiClient.get<OrderTimelineEntry[]>(`/orders/${orderId}/timeline`);
    return response.data!;
  }

  /**
   * Search orders with advanced filters
   */
  async searchOrders(params: OrderSearchParams): Promise<PaginatedOrders> {
    const response = await apiClient.post<PaginatedOrders>('/orders/search', params);
    return response.data!;
  }

  /**
   * Get order statistics for dashboard
   */
  async getOrderStatistics(dateFrom?: Date, dateTo?: Date, lineAccountId?: string): Promise<OrderStatistics> {
    const searchParams = new URLSearchParams();
    
    if (dateFrom) searchParams.set('dateFrom', dateFrom.toISOString().split('T')[0]);
    if (dateTo) searchParams.set('dateTo', dateTo.toISOString().split('T')[0]);
    if (lineAccountId) searchParams.set('lineAccountId', lineAccountId);

    const response = await apiClient.get<OrderStatistics>(`/orders/statistics?${searchParams.toString()}`);
    return response.data!;
  }

  /**
   * Get orders by status
   */
  async getOrdersByStatus(status: string, page: number = 1, limit: number = 20): Promise<PaginatedOrders> {
    return this.getOrders({
      status: [status],
      page,
      limit
    });
  }

  /**
   * Get recent orders
   */
  async getRecentOrders(limit: number = 10): Promise<Order[]> {
    const result = await this.getOrders({
      page: 1,
      limit,
      sort: 'createdAt',
      order: 'desc'
    });
    return result.data;
  }
}

export const orderAPI = new OrderAPI();