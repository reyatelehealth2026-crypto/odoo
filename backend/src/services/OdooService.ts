import { BaseService } from './BaseService';
import { CircuitBreaker } from '@/utils/CircuitBreaker';
import { RetryHandler } from '@/utils/RetryHandler';
import { logger } from '@/utils/logger';
import { config } from '@/config/config';

export interface OdooOrder {
  id: string;
  name: string;
  partner_id: number;
  partner_name: string;
  amount_total: number;
  state: string;
  date_order: string;
  currency_id: number;
}

export interface OdooCustomer {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  credit_limit: number;
  total_due: number;
}

export interface OdooInvoice {
  id: string;
  name: string;
  partner_id: number;
  amount_total: number;
  state: string;
  invoice_date: string;
  due_date: string;
}

export class OdooService extends BaseService {
  private circuitBreaker: CircuitBreaker;
  private retryHandler: RetryHandler;
  private baseUrl: string;
  private apiKey: string;

  constructor(prisma: any) {
    super(prisma);
    
    this.baseUrl = config.ODOO_API_URL || '';
    this.apiKey = config.ODOO_API_KEY || '';
    
    if (!this.baseUrl || !this.apiKey) {
      logger.warn('Odoo API configuration missing, service will be disabled');
    }

    this.circuitBreaker = new CircuitBreaker('OdooService', {
      failureThreshold: 5,
      recoveryTimeout: 60000, // 1 minute
      successThreshold: 3,
      timeout: 10000, // 10 seconds
    });

    this.retryHandler = new RetryHandler('OdooService', {
      maxRetries: 3,
      baseDelay: 1000,
      maxDelay: 10000,
      backoffMultiplier: 2,
      jitter: true,
    });
  }

  async getOrders(filters: {
    limit?: number;
    offset?: number;
    dateFrom?: string;
    dateTo?: string;
    state?: string;
  } = {}): Promise<OdooOrder[]> {
    if (!this.isConfigured()) {
      return this.getCachedOrders(filters);
    }

    return this.circuitBreaker.call(async () => {
      return this.retryHandler.executeWithRetry(
        async () => {
          const response = await this.makeRequest('/api/orders', {
            method: 'GET',
            params: filters,
          });
          
          // Cache the results
          await this.cacheOrders(response.data);
          
          return response.data;
        },
        RetryHandler.shouldRetryError
      );
    });
  }

  async getCustomers(filters: {
    limit?: number;
    offset?: number;
    search?: string;
  } = {}): Promise<OdooCustomer[]> {
    if (!this.isConfigured()) {
      return this.getCachedCustomers(filters);
    }

    return this.circuitBreaker.call(async () => {
      return this.retryHandler.executeWithRetry(
        async () => {
          const response = await this.makeRequest('/api/customers', {
            method: 'GET',
            params: filters,
          });
          
          // Cache the results
          await this.cacheCustomers(response.data);
          
          return response.data;
        },
        RetryHandler.shouldRetryError
      );
    });
  }

  async getInvoices(filters: {
    limit?: number;
    offset?: number;
    dateFrom?: string;
    dateTo?: string;
    state?: string;
  } = {}): Promise<OdooInvoice[]> {
    if (!this.isConfigured()) {
      return this.getCachedInvoices(filters);
    }

    return this.circuitBreaker.call(async () => {
      return this.retryHandler.executeWithRetry(
        async () => {
          const response = await this.makeRequest('/api/invoices', {
            method: 'GET',
            params: filters,
          });
          
          // Cache the results
          await this.cacheInvoices(response.data);
          
          return response.data;
        },
        RetryHandler.shouldRetryError
      );
    });
  }

  async updateOrderStatus(orderId: string, status: string): Promise<void> {
    if (!this.isConfigured()) {
      throw new Error('Odoo API not configured');
    }

    return this.circuitBreaker.call(async () => {
      return this.retryHandler.executeWithRetry(
        async () => {
          await this.makeRequest(`/api/orders/${orderId}`, {
            method: 'PUT',
            body: { state: status },
          });
          
          // Invalidate cache
          await this.invalidateOrdersCache();
        },
        RetryHandler.shouldRetryError
      );
    });
  }

  getCircuitBreakerStats() {
    return this.circuitBreaker.getStats();
  }

  async healthCheck(): Promise<{ status: 'ok' | 'error'; message: string }> {
    if (!this.isConfigured()) {
      return { status: 'error', message: 'Odoo API not configured' };
    }

    try {
      await this.circuitBreaker.call(async () => {
        await this.makeRequest('/api/health', { method: 'GET' });
      });
      
      return { status: 'ok', message: 'Odoo API is healthy' };
    } catch (error) {
      return { 
        status: 'error', 
        message: `Odoo API health check failed: ${(error as Error).message}` 
      };
    }
  }

  private async makeRequest(endpoint: string, options: {
    method: string;
    params?: Record<string, any>;
    body?: Record<string, any>;
  }): Promise<any> {
    const url = new URL(endpoint, this.baseUrl);
    
    if (options.params) {
      Object.entries(options.params).forEach(([key, value]) => {
        if (value !== undefined) {
          url.searchParams.append(key, String(value));
        }
      });
    }

    const fetchOptions: RequestInit = {
      method: options.method,
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.apiKey}`,
      },
    };

    if (options.body) {
      fetchOptions.body = JSON.stringify(options.body);
    }

    const response = await fetch(url.toString(), fetchOptions);
    
    if (!response.ok) {
      const error = new Error(`Odoo API error: ${response.status} ${response.statusText}`);
      (error as any).status = response.status;
      throw error;
    }

    return response.json();
  }

  private isConfigured(): boolean {
    return !!(this.baseUrl && this.apiKey);
  }

  // Fallback methods that use cached data from the existing PHP system
  private async getCachedOrders(filters: any): Promise<OdooOrder[]> {
    try {
      // For now, return empty array since we don't have the Prisma models set up
      // In a real implementation, this would query the odoo_orders table
      logger.info('Using cached orders fallback', { filters });
      return [];
    } catch (error) {
      logger.error('Failed to get cached orders', { error: String(error) });
      return [];
    }
  }

  private async getCachedCustomers(filters: any): Promise<OdooCustomer[]> {
    try {
      // For now, return empty array since we don't have the Prisma models set up
      // In a real implementation, this would query the odoo_customers table
      logger.info('Using cached customers fallback', { filters });
      return [];
    } catch (error) {
      logger.error('Failed to get cached customers', { error: String(error) });
      return [];
    }
  }

  private async getCachedInvoices(filters: any): Promise<OdooInvoice[]> {
    try {
      // For now, return empty array since we don't have the Prisma models set up
      // In a real implementation, this would query the odoo_invoices table
      logger.info('Using cached invoices fallback', { filters });
      return [];
    } catch (error) {
      logger.error('Failed to get cached invoices', { error: String(error) });
      return [];
    }
  }

  private async cacheOrders(orders: OdooOrder[]): Promise<void> {
    // Implementation would update the odoo_orders cache table
    // For now, just log that we would cache
    logger.debug(`Would cache ${orders.length} orders`);
  }

  private async cacheCustomers(customers: OdooCustomer[]): Promise<void> {
    // Implementation would update the odoo_customers cache table
    logger.debug(`Would cache ${customers.length} customers`);
  }

  private async cacheInvoices(invoices: OdooInvoice[]): Promise<void> {
    // Implementation would update the odoo_invoices cache table
    logger.debug(`Would cache ${invoices.length} invoices`);
  }

  private async invalidateOrdersCache(): Promise<void> {
    // Implementation would invalidate the orders cache
    logger.debug('Would invalidate orders cache');
  }
}