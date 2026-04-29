'use client';

import { keepPreviousData, useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { paymentsAPI } from '@/lib/api/payments';
import { PaymentSlipFilters, PaymentSlip } from '@/types/payments';

export function usePaymentSlips(filters: PaymentSlipFilters = {}) {
  const queryClient = useQueryClient();

  // Fetch payment slips
  const slipsQuery = useQuery({
    queryKey: ['payment-slips', filters],
    queryFn: () => paymentsAPI.getPaymentSlips(filters),
    placeholderData: keepPreviousData,
  });

  // Fetch statistics
  const statsQuery = useQuery({
    queryKey: ['payment-statistics'],
    queryFn: () => paymentsAPI.getPaymentStatistics(),
    refetchInterval: 30000, // Refresh every 30 seconds
  });

  // Upload single file
  const uploadMutation = useMutation({
    mutationFn: ({ file, amount }: { file: File; amount?: number }) =>
      paymentsAPI.uploadPaymentSlip(file, amount),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-slips'] });
      queryClient.invalidateQueries({ queryKey: ['payment-statistics'] });
    },
  });

  // Bulk upload
  const bulkUploadMutation = useMutation({
    mutationFn: (files: File[]) => paymentsAPI.bulkUploadPaymentSlips(files),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-slips'] });
      queryClient.invalidateQueries({ queryKey: ['payment-statistics'] });
    },
  });

  // Update amount
  const updateAmountMutation = useMutation({
    mutationFn: ({ slipId, amount }: { slipId: string; amount: number }) =>
      paymentsAPI.updateSlipAmount(slipId, amount),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-slips'] });
    },
  });

  // Match slip to order
  const matchSlipMutation = useMutation({
    mutationFn: ({ slipId, orderId }: { slipId: string; orderId: string }) =>
      paymentsAPI.matchPaymentSlip(slipId, orderId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-slips'] });
      queryClient.invalidateQueries({ queryKey: ['payment-statistics'] });
    },
  });

  // Reject slip
  const rejectSlipMutation = useMutation({
    mutationFn: ({ slipId, reason }: { slipId: string; reason?: string }) =>
      paymentsAPI.rejectPaymentSlip(slipId, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-slips'] });
      queryClient.invalidateQueries({ queryKey: ['payment-statistics'] });
    },
  });

  // Delete slip
  const deleteSlipMutation = useMutation({
    mutationFn: (slipId: string) => paymentsAPI.deletePaymentSlip(slipId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-slips'] });
      queryClient.invalidateQueries({ queryKey: ['payment-statistics'] });
    },
  });

  // Auto matching
  const autoMatchMutation = useMutation({
    mutationFn: () => paymentsAPI.performAutoMatching(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-slips'] });
      queryClient.invalidateQueries({ queryKey: ['payment-statistics'] });
    },
  });

  return {
    // Data
    slips: slipsQuery.data?.data || [],
    slipsMeta: slipsQuery.data?.meta,
    statistics: statsQuery.data?.data,
    
    // Loading states
    isLoadingSlips: slipsQuery.isLoading,
    isLoadingStats: statsQuery.isLoading,
    
    // Error states
    slipsError: slipsQuery.error,
    statsError: statsQuery.error,
    
    // Mutations
    uploadSlip: uploadMutation.mutate,
    bulkUpload: bulkUploadMutation.mutate,
    updateAmount: updateAmountMutation.mutate,
    matchSlip: matchSlipMutation.mutate,
    rejectSlip: rejectSlipMutation.mutate,
    deleteSlip: deleteSlipMutation.mutate,
    performAutoMatch: autoMatchMutation.mutate,
    
    // Mutation states
    isUploading: uploadMutation.isPending,
    isBulkUploading: bulkUploadMutation.isPending,
    isUpdatingAmount: updateAmountMutation.isPending,
    isMatching: matchSlipMutation.isPending,
    isRejecting: rejectSlipMutation.isPending,
    isDeleting: deleteSlipMutation.isPending,
    isAutoMatching: autoMatchMutation.isPending,
    
    // Refetch functions
    refetchSlips: slipsQuery.refetch,
    refetchStats: statsQuery.refetch,
  };
}