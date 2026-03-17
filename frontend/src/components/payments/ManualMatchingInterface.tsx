'use client';

import React, { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PaymentSlip, PotentialMatch } from '@/types/payments';
import { Order } from '@/types';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { DataTable, Column } from '@/components/ui/DataTable';
import { apiClient } from '@/lib/api/client';

interface ManualMatchingInterfaceProps {
  slip: PaymentSlip;
  isOpen: boolean;
  onClose: () => void;
  onMatch: (slipId: string, orderId: string) => void;
  potentialMatches?: PotentialMatch[];
}

interface OrderSearchFilters {
  search?: string;
  status?: string;
  dateFrom?: string;
  dateTo?: string;
  amountMin?: number;
  amountMax?: number;
}

export function ManualMatchingInterface({
  slip,
  isOpen,
  onClose,
  onMatch,
  potentialMatches = [],
}: ManualMatchingInterfaceProps) {
  const [searchFilters, setSearchFilters] = useState<OrderSearchFilters>({
    status: 'pending',
  });
  const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
  const [showConfirmModal, setShowConfirmModal] = useState(false);

  // Search for orders
  const { data: ordersResponse, isLoading } = useQuery({
    queryKey: ['orders-search', searchFilters],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (searchFilters.search) params.append('search', searchFilters.search);
      if (searchFilters.status) params.append('status', searchFilters.status);
      if (searchFilters.dateFrom) params.append('dateFrom', searchFilters.dateFrom);
      if (searchFilters.dateTo) params.append('dateTo', searchFilters.dateTo);
      if (searchFilters.amountMin) params.append('amountMin', searchFilters.amountMin.toString());
      if (searchFilters.amountMax) params.append('amountMax', searchFilters.amountMax.toString());
      
      return apiClient.get<Order[]>(`/orders?${params.toString()}`);
    },
    enabled: isOpen,
  });

  // Set amount range based on slip amount when modal opens
  useEffect(() => {
    if (isOpen && slip.amount) {
      const tolerance = slip.amount * 0.1; // 10% tolerance for search
      setSearchFilters(prev => ({
        ...prev,
        amountMin: Math.max(0, slip.amount! - tolerance),
        amountMax: slip.amount! + tolerance,
      }));
    }
  }, [isOpen, slip.amount]);

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: 'THB',
    }).format(amount);
  };

  const formatDate = (date: Date | null) => {
    if (!date) return '-';
    return new Intl.DateTimeFormat('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(new Date(date));
  };

  const calculateMatchConfidence = (order: Order): number => {
    if (!slip.amount) return 0;
    
    const amountDiff = Math.abs(order.totalAmount - slip.amount);
    const percentageDiff = amountDiff / slip.amount;
    
    // Higher confidence for smaller percentage differences
    return Math.max(0, 1 - percentageDiff) * 100;
  };

  const handleOrderSelect = (order: Order) => {
    setSelectedOrder(order);
    setShowConfirmModal(true);
  };

  const handleConfirmMatch = () => {
    if (selectedOrder) {
      onMatch(slip.id, selectedOrder.id);
      setShowConfirmModal(false);
      setSelectedOrder(null);
      onClose();
    }
  };

  const handleFilterChange = (key: keyof OrderSearchFilters, value: any) => {
    setSearchFilters(prev => ({ ...prev, [key]: value }));
  };

  const orders = ordersResponse?.data || [];

  const columns: Column<Order>[] = [
    {
      key: 'odooOrderId',
      title: 'รหัสออเดอร์',
      render: (value: string) => (
        <span className="font-mono text-sm">{value}</span>
      ),
    },
    {
      key: 'customerName',
      title: 'ลูกค้า',
      render: (value: string | null, record: Order) => (
        <div>
          <div className="font-medium">{value || record.customerRef || '-'}</div>
          {record.customerRef && value && (
            <div className="text-xs text-gray-500">{record.customerRef}</div>
          )}
        </div>
      ),
    },
    {
      key: 'totalAmount',
      title: 'จำนวนเงิน',
      render: (value: number, record: Order) => (
        <div className="text-right">
          <div className="font-medium">{formatCurrency(value)}</div>
          {slip.amount && (
            <div className="text-xs text-gray-500">
              ความแม่นยำ: {calculateMatchConfidence(record).toFixed(1)}%
            </div>
          )}
        </div>
      ),
      align: 'right',
    },
    {
      key: 'orderDate',
      title: 'วันที่สั่งซื้อ',
      render: (value: Date | null) => formatDate(value),
    },
    {
      key: 'status',
      title: 'สถานะ',
      render: (value: string) => (
        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
          {value === 'pending' ? 'รอดำเนินการ' : value}
        </span>
      ),
    },
    {
      key: 'actions',
      title: 'การดำเนินการ',
      render: (_, record: Order) => (
        <Button
          size="sm"
          onClick={() => handleOrderSelect(record)}
        >
          เลือก
        </Button>
      ),
      width: '100px',
    },
  ];

  return (
    <>
      <Modal
        isOpen={isOpen}
        onClose={onClose}
        title="จับคู่ใบเสร็จกับออเดอร์"
        size="xl"
      >
        <div className="space-y-6">
          {/* Slip Info */}
          <div className="bg-gray-50 rounded-lg p-4">
            <h3 className="text-sm font-medium text-gray-900 mb-2">
              ข้อมูลใบเสร็จ #{slip.id.slice(-8)}
            </h3>
            <div className="grid grid-cols-2 gap-4 text-sm">
              <div>
                <span className="text-gray-600">จำนวนเงิน:</span>
                <span className="ml-2 font-medium">
                  {slip.amount ? formatCurrency(slip.amount) : 'ไม่ระบุ'}
                </span>
              </div>
              <div>
                <span className="text-gray-600">อัปโหลดโดย:</span>
                <span className="ml-2">{slip.uploadedBy}</span>
              </div>
            </div>
          </div>

          {/* Potential Matches */}
          {potentialMatches.length > 0 && (
            <div>
              <h3 className="text-sm font-medium text-gray-900 mb-3">
                ออเดอร์ที่แนะนำ (จากระบบอัตโนมัติ)
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                {potentialMatches.slice(0, 4).map((match) => (
                  <div
                    key={match.orderId}
                    className="border border-blue-200 rounded-lg p-3 bg-blue-50"
                  >
                    <div className="flex items-center justify-between">
                      <div>
                        <div className="font-medium text-blue-900">
                          {formatCurrency(match.amount)}
                        </div>
                        <div className="text-xs text-blue-700">
                          ความแม่นยำ: {Math.round(match.confidence * 100)}%
                        </div>
                      </div>
                      <Button
                        size="sm"
                        onClick={() => onMatch(slip.id, match.orderId)}
                      >
                        จับคู่
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Search Filters */}
          <div>
            <h3 className="text-sm font-medium text-gray-900 mb-3">
              ค้นหาออเดอร์
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  ค้นหา (ลูกค้า/รหัสออเดอร์)
                </label>
                <Input
                  type="text"
                  placeholder="ค้นหา..."
                  value={searchFilters.search || ''}
                  onChange={(e) => handleFilterChange('search', e.target.value)}
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  จำนวนเงินต่ำสุด
                </label>
                <Input
                  type="number"
                  placeholder="0"
                  value={searchFilters.amountMin || ''}
                  onChange={(e) => handleFilterChange('amountMin', parseFloat(e.target.value) || undefined)}
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  จำนวนเงินสูงสุด
                </label>
                <Input
                  type="number"
                  placeholder="0"
                  value={searchFilters.amountMax || ''}
                  onChange={(e) => handleFilterChange('amountMax', parseFloat(e.target.value) || undefined)}
                />
              </div>
            </div>
          </div>

          {/* Orders Table */}
          <div>
            <h3 className="text-sm font-medium text-gray-900 mb-3">
              ออเดอร์ที่พบ ({orders.length} รายการ)
            </h3>
            <div className="max-h-96 overflow-y-auto">
              <DataTable
                data={orders}
                columns={columns}
                loading={isLoading}
                emptyText="ไม่พบออเดอร์ที่ตรงกับเงื่อนไข"
              />
            </div>
          </div>

          {/* Actions */}
          <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
            <Button variant="secondary" onClick={onClose}>
              ยกเลิก
            </Button>
          </div>
        </div>
      </Modal>

      {/* Confirmation Modal */}
      <Modal
        isOpen={showConfirmModal}
        onClose={() => {
          setShowConfirmModal(false);
          setSelectedOrder(null);
        }}
        title="ยืนยันการจับคู่"
      >
        {selectedOrder && (
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              คุณต้องการจับคู่ใบเสร็จนี้กับออเดอร์ดังกล่าวหรือไม่?
            </p>
            
            <div className="bg-gray-50 rounded-lg p-4 space-y-3">
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span className="text-gray-600">ใบเสร็จ:</span>
                  <div className="font-medium">
                    #{slip.id.slice(-8)} - {slip.amount ? formatCurrency(slip.amount) : 'ไม่ระบุ'}
                  </div>
                </div>
                <div>
                  <span className="text-gray-600">ออเดอร์:</span>
                  <div className="font-medium">
                    {selectedOrder.odooOrderId} - {formatCurrency(selectedOrder.totalAmount)}
                  </div>
                </div>
              </div>
              
              <div className="text-sm">
                <span className="text-gray-600">ลูกค้า:</span>
                <span className="ml-2 font-medium">
                  {selectedOrder.customerName || selectedOrder.customerRef || '-'}
                </span>
              </div>
              
              {slip.amount && (
                <div className="text-sm">
                  <span className="text-gray-600">ความแม่นยำ:</span>
                  <span className="ml-2 font-medium text-blue-600">
                    {calculateMatchConfidence(selectedOrder).toFixed(1)}%
                  </span>
                </div>
              )}
            </div>

            <div className="flex items-center justify-end space-x-3">
              <Button
                variant="secondary"
                onClick={() => {
                  setShowConfirmModal(false);
                  setSelectedOrder(null);
                }}
              >
                ยกเลิก
              </Button>
              <Button onClick={handleConfirmMatch}>
                ยืนยันการจับคู่
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}