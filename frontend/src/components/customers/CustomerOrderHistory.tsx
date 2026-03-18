'use client';

import React, { useState } from 'react';
import { DataTable, Column } from '@/components/ui/DataTable';
import { CustomerOrder } from '@/types/customers';
import { useCustomerOrders } from '@/hooks/useCustomers';

interface CustomerOrderHistoryProps {
  customerId: string;
  onOrderClick?: (order: CustomerOrder) => void;
}

export function CustomerOrderHistory({ customerId, onOrderClick }: CustomerOrderHistoryProps) {
  const [pagination, setPagination] = useState({
    current: 1,
    pageSize: 10,
  });
  const [sortConfig, setSortConfig] = useState<{
    key: string;
    direction: 'asc' | 'desc';
  }>({
    key: 'createdAt',
    direction: 'desc',
  });

  const { data, isLoading, error } = useCustomerOrders(customerId, {
    page: pagination.current,
    limit: pagination.pageSize,
    sort: sortConfig.key,
    order: sortConfig.direction,
  });

  const formatCurrency = (amount: number, currency: string = 'THB') => {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: currency,
    }).format(amount);
  };

  const formatDate = (date: Date | string | null) => {
    if (!date) return '-';
    return new Intl.DateTimeFormat('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(date));
  };

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { label: string; color: string }> = {
      draft: { label: 'ร่าง / Draft', color: 'bg-gray-100 text-gray-800' },
      pending: { label: 'รอดำเนินการ / Pending', color: 'bg-yellow-100 text-yellow-800' },
      confirmed: { label: 'ยืนยันแล้ว / Confirmed', color: 'bg-blue-100 text-blue-800' },
      processing: { label: 'กำลังดำเนินการ / Processing', color: 'bg-purple-100 text-purple-800' },
      completed: { label: 'เสร็จสิ้น / Completed', color: 'bg-green-100 text-green-800' },
      cancelled: { label: 'ยกเลิก / Cancelled', color: 'bg-red-100 text-red-800' },
    };

    const config = statusConfig[status.toLowerCase()] || {
      label: status,
      color: 'bg-gray-100 text-gray-800',
    };

    return (
      <span className={`px-2 py-1 rounded-full text-xs font-medium ${config.color}`}>
        {config.label}
      </span>
    );
  };

  const handlePaginationChange = (page: number, pageSize: number) => {
    setPagination({ current: page, pageSize });
  };

  const handleSort = (key: string, direction: 'asc' | 'desc') => {
    setSortConfig({ key, direction });
  };

  const columns: Column<CustomerOrder>[] = [
    {
      key: 'odooOrderId',
      title: 'Order ID',
      sortable: true,
      render: (value) => (
        <span className="font-mono text-sm text-blue-600">{value}</span>
      ),
    },
    {
      key: 'status',
      title: 'สถานะ / Status',
      sortable: true,
      render: (value) => getStatusBadge(value),
    },
    {
      key: 'totalAmount',
      title: 'ยอดรวม / Amount',
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
      title: 'วันที่สั่งซื้อ / Order Date',
      sortable: true,
      render: (value) => formatDate(value),
    },
    {
      key: 'deliveryDate',
      title: 'วันที่จัดส่ง / Delivery Date',
      sortable: true,
      render: (value) => formatDate(value),
    },
    {
      key: 'createdAt',
      title: 'สร้างเมื่อ / Created',
      sortable: true,
      render: (value) => formatDate(value),
    },
  ];

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-lg p-4">
        <p className="text-red-800">
          ⚠️ ไม่สามารถโหลดประวัติคำสั่งซื้อได้ / Failed to load order history
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-900">
          📦 ประวัติคำสั่งซื้อ / Order History
        </h3>
        {data && (
          <span className="text-sm text-gray-600">
            ทั้งหมด {data.meta.total} รายการ / Total {data.meta.total} orders
          </span>
        )}
      </div>

      <DataTable
        data={data?.data || []}
        columns={columns}
        loading={isLoading}
        onRowClick={onOrderClick}
        pagination={
          data
            ? {
                current: pagination.current,
                pageSize: pagination.pageSize,
                total: data.meta.total,
                onChange: handlePaginationChange,
              }
            : undefined
        }
        sortConfig={sortConfig}
        onSort={handleSort}
        emptyText="ไม่พบประวัติคำสั่งซื้อ / No orders found"
      />
    </div>
  );
}
