'use client';

import React, { useState, useEffect } from 'react';
import { DataTable, Column } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Order, OrderFilters } from '@/types';
import { orderAPI } from '@/lib/api/orders';
import { OrderDetail } from './OrderDetail';
import { OrderStatusBadge } from './OrderStatusBadge';

interface OrderListProps {
  initialFilters?: OrderFilters;
  onOrderSelect?: (order: Order) => void;
  showActions?: boolean;
  pageSize?: number;
}

export function OrderList({ 
  initialFilters = {}, 
  onOrderSelect,
  showActions = true,
  pageSize = 20 
}: OrderListProps) {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  const [showOrderDetail, setShowOrderDetail] = useState(false);
  const [filters, setFilters] = useState<OrderFilters>(initialFilters);
  const [searchQuery, setSearchQuery] = useState('');
  const [pagination, setPagination] = useState({
    current: 1,
    pageSize,
    total: 0,
  });
  const [sortConfig, setSortConfig] = useState<{
    key: string;
    direction: 'asc' | 'desc';
  }>({
    key: 'createdAt',
    direction: 'desc',
  });

  const loadOrders = async () => {
    try {
      setLoading(true);
      const result = await orderAPI.getOrders({
        ...filters,
        ...(searchQuery && { search: searchQuery }),
        page: pagination.current,
        limit: pagination.pageSize,
        sort: sortConfig.key,
        order: sortConfig.direction,
      });

      setOrders(result.data);
      setPagination(prev => ({
        ...prev,
        total: result.meta.total,
      }));
    } catch (error) {
      console.error('Failed to load orders:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadOrders();
  }, [filters, searchQuery, pagination.current, pagination.pageSize, sortConfig]);

  const handleRowClick = (order: Order) => {
    setSelectedOrder(order);
    if (onOrderSelect) {
      onOrderSelect(order);
    } else {
      setShowOrderDetail(true);
    }
  };

  const handleSearch = (query: string) => {
    setSearchQuery(query);
    setPagination(prev => ({ ...prev, current: 1 }));
  };

  const handleFilterChange = (newFilters: Partial<OrderFilters>) => {
    setFilters(prev => ({ ...prev, ...newFilters }));
    setPagination(prev => ({ ...prev, current: 1 }));
  };

  const handleSort = (key: string, direction: 'asc' | 'desc') => {
    setSortConfig({ key, direction });
  };

  const handlePaginationChange = (page: number, pageSize: number) => {
    setPagination(prev => ({ ...prev, current: page, pageSize }));
  };
  const formatCurrency = (amount: number, currency: string = 'THB') => {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: currency,
    }).format(amount);
  };

  const formatDate = (date: Date | string) => {
    return new Intl.DateTimeFormat('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(date));
  };

  const columns: Column<Order>[] = [
    {
      key: 'odooOrderId',
      title: 'Order ID',
      sortable: true,
      render: (value) => (
        <span className="font-mono text-sm text-blue-600">{value}</span>
      ),
    },
    {
      key: 'customerName',
      title: 'Customer',
      sortable: true,
      render: (value, record) => (
        <div>
          <div className="font-medium text-gray-900">
            {value || record.customerRef || 'Unknown'}
          </div>
          {value && record.customerRef && (
            <div className="text-sm text-gray-500">{record.customerRef}</div>
          )}
        </div>
      ),
    },
    {
      key: 'status',
      title: 'Status',
      sortable: true,
      render: (value) => <OrderStatusBadge status={value} />,
    },
    {
      key: 'totalAmount',
      title: 'Amount',
      sortable: true,
      align: 'right',
      render: (value, record) => (
        <span className="font-medium">
          {formatCurrency(value, record.currency)}
        </span>
      ),
    },
    {
      key: 'orderDate',
      title: 'Order Date',
      sortable: true,
      render: (value) => value ? formatDate(value) : '-',
    },
    {
      key: 'createdAt',
      title: 'Created',
      sortable: true,
      render: (value) => formatDate(value),
    },
  ];

  if (showActions) {
    columns.push({
      key: 'actions',
      title: 'Actions',
      render: (_, record) => (
        <div className="flex space-x-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={(e) => {
              e.stopPropagation();
              setSelectedOrder(record);
              setShowOrderDetail(true);
            }}
          >
            View
          </Button>
        </div>
      ),
    });
  }

  return (
    <div className="space-y-4">
      {/* Search and Filters */}
      <div className="flex flex-col sm:flex-row gap-4">
        <div className="flex-1">
          <Input
            placeholder="Search orders by ID, customer, or notes..."
            value={searchQuery}
            onChange={(e) => handleSearch(e.target.value)}
            className="w-full"
          />
        </div>
        <div className="flex gap-2">
          <select
            className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            value={filters.status?.[0] || ''}
            onChange={(e) => handleFilterChange({ 
              ...(e.target.value && { status: [e.target.value] })
            })}
          >
            <option value="">All Status</option>
            <option value="draft">Draft</option>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="processing">Processing</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
          </select>
          <Button
            variant="secondary"
            onClick={() => {
              setFilters({});
              setSearchQuery('');
              setPagination(prev => ({ ...prev, current: 1 }));
            }}
          >
            Clear
          </Button>
        </div>
      </div>

      {/* Orders Table */}
      <DataTable
        data={orders}
        columns={columns}
        loading={loading}
        onRowClick={handleRowClick}
        pagination={{
          current: pagination.current,
          pageSize: pagination.pageSize,
          total: pagination.total,
          onChange: handlePaginationChange,
        }}
        sortConfig={sortConfig}
        onSort={handleSort}
        emptyText="No orders found"
      />

      {/* Order Detail Modal */}
      {showOrderDetail && selectedOrder && (
        <Modal
          isOpen={showOrderDetail}
          onClose={() => {
            setShowOrderDetail(false);
            setSelectedOrder(null);
          }}
          title={`Order ${selectedOrder.odooOrderId}`}
          size="xl"
        >
          <OrderDetail
            order={selectedOrder}
            onOrderUpdate={(updatedOrder) => {
              setOrders(prev => 
                prev.map(order => 
                  order.id === updatedOrder.id ? updatedOrder : order
                )
              );
              setSelectedOrder(updatedOrder);
            }}
          />
        </Modal>
      )}
    </div>
  );
}