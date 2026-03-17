import { apiClient } from './client';
import {
  Customer,
  CustomerListItem,
  CustomerFilters,
  PaginatedCustomers,
  CustomerStatistics,
  CustomerOrder,
  PaginatedCustomerOrders,
  LineConnectionUpdate,
} from '@/types/customers';
import { APIResponse } from '@/types';

export interface CustomerSearchParams extends CustomerFilters {
  page?: number;
  limit?: number;
  sort?: string;
  order?: 'asc' | 'desc';
}

export class CustomersAPI {
  /**
   * Search customers by various criteria
   * Validates: Requirements FR-3.1
   */
  async searchCustomers(params: CustomerSearchParams = {}): Promise<PaginatedCustomers> {
    const searchParams = new URLSearchParams();

    if (params.page) searchParams.set('page', params.page.toString());
    if (params.limit) searchParams.set('limit', params.limit.toString());
    if (params.sort) searchParams.set('sort', params.sort);
    if (params.order) searchParams.set('order', params.order);
    if (params.search) searchParams.set('search', params.search);
    if (params.name) searchParams.set('name', params.name);
    if (params.reference) searchParams.set('reference', params.reference);
    if (params.partnerId) searchParams.set('partnerId', params.partnerId);
    if (params.lineConnected !== undefined) searchParams.set('lineConnected', params.lineConnected.toString());
    if (params.tier) searchParams.set('tier', params.tier);
    if (params.dateFrom) searchParams.set('dateFrom', params.dateFrom.toISOString().split('T')[0]);
    if (params.dateTo) searchParams.set('dateTo', params.dateTo.toISOString().split('T')[0]);

    const response = await apiClient.get<PaginatedCustomers>(`/customers?${searchParams.toString()}`);
    return response.data!;
  }

  /**
   * Get customer profile details
   * Validates: Requirements FR-3.2
   */
  async getCustomerById(customerId: string): Promise<Customer> {
    const response = await apiClient.get<Customer>(`/customers/${customerId}`);
    return response.data!;
  }

  /**
   * Get customer order history
   * Validates: Requirements FR-3.2
   */
  async getCustomerOrders(
    customerId: string,
    page: number = 1,
    limit: number = 20
  ): Promise<PaginatedCustomerOrders> {
    const searchParams = new URLSearchParams();
    searchParams.set('page', page.toString());
    searchParams.set('limit', limit.toString());

    const response = await apiClient.get<PaginatedCustomerOrders>(
      `/customers/${customerId}/orders?${searchParams.toString()}`
    );
    return response.data!;
  }

  /**
   * Update LINE account connection
   * Validates: Requirements FR-3.3
   */
  async updateLineConnection(
    customerId: string,
    update: LineConnectionUpdate
  ): Promise<Customer> {
    const response = await apiClient.put<Customer>(
      `/customers/${customerId}/line`,
      update
    );
    return response.data!;
  }

  /**
   * Get customer statistics
   */
  async getCustomerStatistics(dateFrom?: Date, dateTo?: Date): Promise<CustomerStatistics> {
    const searchParams = new URLSearchParams();

    if (dateFrom) searchParams.set('dateFrom', dateFrom.toISOString().split('T')[0]);
    if (dateTo) searchParams.set('dateTo', dateTo.toISOString().split('T')[0]);

    const response = await apiClient.get<CustomerStatistics>(
      `/customers/statistics?${searchParams.toString()}`
    );
    return response.data!;
  }

  /**
   * Get recent customers
   */
  async getRecentCustomers(limit: number = 10): Promise<CustomerListItem[]> {
    const result = await this.searchCustomers({
      page: 1,
      limit,
      sort: 'createdAt',
      order: 'desc',
    });
    return result.data;
  }

  /**
   * Get customers by tier
   */
  async getCustomersByTier(tier: string, page: number = 1, limit: number = 20): Promise<PaginatedCustomers> {
    return this.searchCustomers({
      tier,
      page,
      limit,
    });
  }

  /**
   * Get LINE connected customers
   */
  async getLineConnectedCustomers(page: number = 1, limit: number = 20): Promise<PaginatedCustomers> {
    return this.searchCustomers({
      lineConnected: true,
      page,
      limit,
    });
  }
}

export const customersAPI = new CustomersAPI();
