import { apiClient } from './client';
import { APIResponse } from '@/types';
import {
  PaymentSlip,
  PaymentSlipFilters,
  UploadResult,
  BulkUploadResult,
  PaymentStatistics,
  AutoMatchingResult,
  SlipStatus,
} from '@/types/payments';

export class PaymentsAPI {
  /**
   * Get list of payment slips with filtering and pagination
   */
  async getPaymentSlips(filters: PaymentSlipFilters = {}): Promise<APIResponse<PaymentSlip[]>> {
    const params = new URLSearchParams();
    
    if (filters.status) params.append('status', filters.status);
    if (filters.dateFrom) params.append('dateFrom', filters.dateFrom.toISOString());
    if (filters.dateTo) params.append('dateTo', filters.dateTo.toISOString());
    if (filters.search) params.append('search', filters.search);
    if (filters.page) params.append('page', filters.page.toString());
    if (filters.limit) params.append('limit', filters.limit.toString());

    const queryString = params.toString();
    const endpoint = `/payments/slips${queryString ? `?${queryString}` : ''}`;
    
    return apiClient.get<PaymentSlip[]>(endpoint);
  }

  /**
   * Get a specific payment slip by ID
   */
  async getPaymentSlip(slipId: string): Promise<APIResponse<PaymentSlip>> {
    return apiClient.get<PaymentSlip>(`/payments/slips/${slipId}`);
  }

  /**
   * Upload a single payment slip image
   */
  async uploadPaymentSlip(
    file: File,
    amount?: number,
    onProgress?: (progress: number) => void
  ): Promise<UploadResult> {
    const formData = new FormData();
    formData.append('file', file);
    if (amount) {
      formData.append('amount', amount.toString());
    }

    try {
      const response = await fetch(`${process.env.NEXT_PUBLIC_API_URL || '/api/v1'}/payments/upload`, {
        method: 'POST',
        body: formData,
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
          'X-Line-Account-ID': localStorage.getItem('line_account_id') || '',
        },
      });

      const result = await response.json();
      
      if (!response.ok) {
        return {
          success: false,
          message: result.error?.message || 'Upload failed',
        };
      }

      return result.data;
    } catch (error) {
      return {
        success: false,
        message: error instanceof Error ? error.message : 'Upload failed',
      };
    }
  }

  /**
   * Upload multiple payment slip images
   */
  async bulkUploadPaymentSlips(
    files: File[],
    onProgress?: (progress: { filename: string; progress: number; status: string }[]) => void
  ): Promise<BulkUploadResult> {
    const formData = new FormData();
    files.forEach((file) => {
      formData.append('files', file);
    });

    try {
      const response = await fetch(`${process.env.NEXT_PUBLIC_API_URL || '/api/v1'}/payments/bulk`, {
        method: 'POST',
        body: formData,
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
          'X-Line-Account-ID': localStorage.getItem('line_account_id') || '',
        },
      });

      const result = await response.json();
      
      if (!response.ok) {
        throw new Error(result.error?.message || 'Bulk upload failed');
      }

      return result.data;
    } catch (error) {
      throw new Error(error instanceof Error ? error.message : 'Bulk upload failed');
    }
  }

  /**
   * Update payment slip amount
   */
  async updateSlipAmount(slipId: string, amount: number): Promise<APIResponse<{ slipId: string; potentialMatches: any[] }>> {
    return apiClient.put(`/payments/slips/${slipId}/amount`, { amount });
  }

  /**
   * Match payment slip to order
   */
  async matchPaymentSlip(slipId: string, orderId: string): Promise<APIResponse<{ matchedOrderId: string; message: string }>> {
    return apiClient.put(`/payments/slips/${slipId}/match`, { orderId });
  }

  /**
   * Reject payment slip
   */
  async rejectPaymentSlip(slipId: string, reason?: string): Promise<APIResponse<{ message: string }>> {
    return apiClient.put(`/payments/slips/${slipId}/reject`, { reason });
  }

  /**
   * Delete payment slip
   */
  async deletePaymentSlip(slipId: string): Promise<APIResponse<{ message: string }>> {
    return apiClient.delete(`/payments/slips/${slipId}`);
  }

  /**
   * Get pending payment slips
   */
  async getPendingPaymentSlips(): Promise<APIResponse<PaymentSlip[]>> {
    return apiClient.get<PaymentSlip[]>('/payments/pending');
  }

  /**
   * Perform automatic matching
   */
  async performAutoMatching(): Promise<APIResponse<AutoMatchingResult>> {
    return apiClient.post<AutoMatchingResult>('/payments/auto-match');
  }

  /**
   * Get payment processing statistics
   */
  async getPaymentStatistics(dateFrom?: Date, dateTo?: Date): Promise<APIResponse<PaymentStatistics>> {
    const params = new URLSearchParams();
    if (dateFrom) params.append('dateFrom', dateFrom.toISOString());
    if (dateTo) params.append('dateTo', dateTo.toISOString());

    const queryString = params.toString();
    const endpoint = `/payments/statistics${queryString ? `?${queryString}` : ''}`;
    
    return apiClient.get<PaymentStatistics>(endpoint);
  }
}

export const paymentsAPI = new PaymentsAPI();