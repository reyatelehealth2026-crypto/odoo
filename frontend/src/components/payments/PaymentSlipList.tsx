'use client';

import React, { useState } from 'react';
import { PaymentSlip, SlipStatus } from '@/types/payments';
import { PaymentSlipPreview } from './PaymentSlipPreview';
import { DataTable, Column } from '@/components/ui/DataTable';
import { Modal } from '@/components/ui/Modal';

interface PaymentSlipListProps {
  slips: PaymentSlip[];
  loading?: boolean;
  pagination?: {
    current: number;
    pageSize: number;
    total: number;
    onChange: (page: number, pageSize: number) => void;
  };
  onAmountUpdate?: (slipId: string, amount: number) => void;
  onMatch?: (slipId: string, orderId: string) => void;
  onReject?: (slipId: string, reason?: string) => void;
  onDelete?: (slipId: string) => void;
  className?: string;
}

export function PaymentSlipList({
  slips,
  loading = false,
  pagination,
  onAmountUpdate,
  onMatch,
  onReject,
  onDelete,
  className = '',
}: PaymentSlipListProps) {
  const [selectedSlip, setSelectedSlip] = useState<PaymentSlip | null>(null);
  const [showDetailModal, setShowDetailModal] = useState(false);

  const handleRowClick = (slip: PaymentSlip) => {
    setSelectedSlip(slip);
    setShowDetailModal(true);
  };

  const handleCloseModal = () => {
    setSelectedSlip(null);
    setShowDetailModal(false);
  };

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: 'THB',
    }).format(amount);
  };

  const formatDate = (date: Date) => {
    return new Intl.DateTimeFormat('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(date));
  };

  const getStatusBadge = (status: SlipStatus) => {
    const statusConfig = {
      PENDING: { color: 'bg-yellow-100 text-yellow-800', text: 'รอดำเนินการ' },
      MATCHED: { color: 'bg-green-100 text-green-800', text: 'จับคู่แล้ว' },
      REJECTED: { color: 'bg-red-100 text-red-800', text: 'ปฏิเสธ' },
      PROCESSING: { color: 'bg-blue-100 text-blue-800', text: 'กำลังประมวลผล' },
    };

    const config = statusConfig[status] || statusConfig.PENDING;

    return (
      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
        {config.text}
      </span>
    );
  };

  const columns: Column<PaymentSlip>[] = [
    {
      key: 'id',
      title: 'รหัส',
      render: (value: string) => (
        <span className="font-mono text-sm">#{value.slice(-8)}</span>
      ),
      width: '120px',
    },
    {
      key: 'imageUrl',
      title: 'รูปภาพ',
      render: (value: string, record: PaymentSlip) => (
        <div className="flex items-center">
          <img
            src={value}
            alt="Payment slip"
            className="w-12 h-12 object-cover rounded border cursor-pointer hover:opacity-75 transition-opacity"
            onClick={(e) => {
              e.stopPropagation();
              handleRowClick(record);
            }}
          />
        </div>
      ),
      width: '80px',
    },
    {
      key: 'amount',
      title: 'จำนวนเงิน',
      render: (value: number | null) => (
        <span className={`font-medium ${value ? 'text-gray-900' : 'text-gray-400'}`}>
          {value ? formatCurrency(value) : 'ไม่ระบุ'}
        </span>
      ),
      align: 'right',
      sortable: true,
    },
    {
      key: 'status',
      title: 'สถานะ',
      render: (value: SlipStatus) => getStatusBadge(value),
      sortable: true,
    },
    {
      key: 'matchedOrder',
      title: 'ออเดอร์ที่จับคู่',
      render: (value: PaymentSlip['matchedOrder']) => (
        value ? (
          <div className="text-sm">
            <div className="font-medium text-gray-900">
              {value.odooOrderId}
            </div>
            <div className="text-gray-500">
              {value.customerName || value.customerRef}
            </div>
          </div>
        ) : (
          <span className="text-gray-400">-</span>
        )
      ),
    },
    {
      key: 'uploadedBy',
      title: 'อัปโหลดโดย',
      render: (value: string) => (
        <span className="text-sm text-gray-600">{value}</span>
      ),
    },
    {
      key: 'createdAt',
      title: 'วันที่อัปโหลด',
      render: (value: Date) => (
        <span className="text-sm text-gray-600">
          {formatDate(value)}
        </span>
      ),
      sortable: true,
    },
    {
      key: 'actions',
      title: 'การดำเนินการ',
      render: (_, record: PaymentSlip) => (
        <div className="flex items-center space-x-2">
          <button
            onClick={(e) => {
              e.stopPropagation();
              handleRowClick(record);
            }}
            className="text-blue-600 hover:text-blue-800 text-sm font-medium"
          >
            ดูรายละเอียด
          </button>
          {record.status === 'PENDING' && onDelete && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                if (confirm('คุณแน่ใจหรือไม่ที่จะลบใบเสร็จนี้?')) {
                  onDelete(record.id);
                }
              }}
              className="text-red-600 hover:text-red-800 text-sm font-medium"
            >
              ลบ
            </button>
          )}
        </div>
      ),
      width: '150px',
    },
  ];

  return (
    <>
      <div className={className}>
        <DataTable
          data={slips}
          columns={columns}
          loading={loading}
          pagination={pagination}
          onRowClick={handleRowClick}
          emptyText="ไม่พบใบเสร็จ"
          className="cursor-pointer"
        />
      </div>

      {/* Detail Modal */}
      <Modal
        isOpen={showDetailModal}
        onClose={handleCloseModal}
        title={`รายละเอียดใบเสร็จ #${selectedSlip?.id.slice(-8)}`}
        size="lg"
      >
        {selectedSlip && (
          <PaymentSlipPreview
            slip={selectedSlip}
            onAmountUpdate={onAmountUpdate}
            onMatch={onMatch}
            onReject={onReject}
            onDelete={onDelete}
            className="border-0 shadow-none"
          />
        )}
      </Modal>
    </>
  );
}