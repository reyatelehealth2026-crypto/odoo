import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { customersAPI, CustomerSearchParams } from '@/lib/api/customers';
import {
  Customer,
  CustomerListItem,
  PaginatedCustomers,
  CustomerStatistics,
  PaginatedCustomerOrders,
  LineConnectionUpdate,
} from '@/types/customers';

/**
 * Hook for searching customers with filters and pagination
 */
export const useCustomers = (params: CustomerSearchParams = {}) => {
  return useQuery<PaginatedCustomers>({
    queryKey: ['customers', params],
    queryFn: () => customersAPI.searchCustomers(params),
    staleTime: 30 * 1000, // 30 seconds
    refetchInterval: 60 * 1000, // 1 minute
  });
};

/**
 * Hook for getting a specific customer by ID
 */
export const useCustomer = (customerId: string | null) => {
  return useQuery<Customer>({
    queryKey: ['customers', customerId],
    queryFn: () => customersAPI.getCustomerById(customerId!),
    enabled: !!customerId,
    staleTime: 30 * 1000,
  });
};

/**
 * Hook for getting customer order history
 */
export const useCustomerOrders = (
  customerId: string | null,
  page: number = 1,
  limit: number = 20
) => {
  return useQuery<PaginatedCustomerOrders>({
    queryKey: ['customers', customerId, 'orders', page, limit],
    queryFn: () => customersAPI.getCustomerOrders(customerId!, page, limit),
    enabled: !!customerId,
    staleTime: 30 * 1000,
  });
};

/**
 * Hook for getting customer statistics
 */
export const useCustomerStatistics = (dateFrom?: Date, dateTo?: Date) => {
  return useQuery<CustomerStatistics>({
    queryKey: ['customers', 'statistics', dateFrom, dateTo],
    queryFn: () => customersAPI.getCustomerStatistics(dateFrom, dateTo),
    staleTime: 60 * 1000, // 1 minute
    refetchInterval: 5 * 60 * 1000, // 5 minutes
  });
};

/**
 * Hook for updating LINE account connection
 */
export const useUpdateLineConnection = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      customerId,
      update,
    }: {
      customerId: string;
      update: LineConnectionUpdate;
    }) => customersAPI.updateLineConnection(customerId, update),
    onMutate: async ({ customerId, update }) => {
      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: ['customers'] });

      // Snapshot previous value
      const previousCustomers = queryClient.getQueryData(['customers']);
      const previousCustomer = queryClient.getQueryData(['customers', customerId]);

      // Optimistically update customer in list
      queryClient.setQueriesData<PaginatedCustomers>(
        { queryKey: ['customers'], exact: false },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            data: old.data.map((customer) =>
              customer.id === customerId
                ? { ...customer, lineUserId: update.lineUserId }
                : customer
            ),
          };
        }
      );

      // Optimistically update individual customer
      queryClient.setQueryData<Customer>(['customers', customerId], (old) => {
        if (!old) return old;
        return { ...old, lineUserId: update.lineUserId };
      });

      return { previousCustomers, previousCustomer };
    },
    onError: (err, variables, context) => {
      // Rollback on error
      if (context?.previousCustomers) {
        queryClient.setQueryData(['customers'], context.previousCustomers);
      }
      if (context?.previousCustomer) {
        queryClient.setQueryData(
          ['customers', variables.customerId],
          context.previousCustomer
        );
      }
    },
    onSettled: (data, error, variables) => {
      // Refetch to ensure consistency
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      queryClient.invalidateQueries({ queryKey: ['customers', variables.customerId] });
      queryClient.invalidateQueries({ queryKey: ['customers', 'statistics'] });
    },
  });
};

/**
 * Hook for getting recent customers
 */
export const useRecentCustomers = (limit: number = 10) => {
  return useQuery<CustomerListItem[]>({
    queryKey: ['customers', 'recent', limit],
    queryFn: () => customersAPI.getRecentCustomers(limit),
    staleTime: 60 * 1000,
  });
};

/**
 * Hook for getting customers by tier
 */
export const useCustomersByTier = (tier: string, page: number = 1, limit: number = 20) => {
  return useQuery<PaginatedCustomers>({
    queryKey: ['customers', 'tier', tier, page, limit],
    queryFn: () => customersAPI.getCustomersByTier(tier, page, limit),
    enabled: !!tier,
    staleTime: 60 * 1000,
  });
};

/**
 * Hook for getting LINE connected customers
 */
export const useLineConnectedCustomers = (page: number = 1, limit: number = 20) => {
  return useQuery<PaginatedCustomers>({
    queryKey: ['customers', 'line-connected', page, limit],
    queryFn: () => customersAPI.getLineConnectedCustomers(page, limit),
    staleTime: 60 * 1000,
  });
};
