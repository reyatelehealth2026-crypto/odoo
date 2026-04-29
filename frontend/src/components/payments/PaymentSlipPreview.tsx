'use client';

import React, { useState, useRef, useEffect } from 'react';
import { PaymentSlip, PotentialMatch } from '@/types/payments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { ManualMatchingInterface } from './ManualMatchingInterface';

interface PaymentSlipPreviewProps {
  slip: PaymentSlip;
  potentialMatches?: PotentialMatch[] | undefined;
  onAmountUpdate?: ((slipId: string, amount: number) => void) | undefined;
  onMatch?: ((slipId: string, orderId: string) => void) | undefined;
  onReject?: ((slipId: string, reason?: string) => void) | undefined;
  onDelete?: ((slipId: string) => void) | undefined;
  className?: string;
}

export function PaymentSlipPreview({
  slip,
  potentialMatches = [],
  onAmountUpdate,
  onMatch,
  onReject,
  onDelete,
  className = '',
}: PaymentSlipPreviewProps) {
  const [isZoomed, setIsZoomed] = useState(false);
  const [zoomLevel, setZoomLevel] = useState(1);
  const [panPosition, setPanPosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 });
  const [editingAmount, setEditingAmount] = useState(false);
  const [tempAmount, setTempAmount] = useState(slip.amount?.toString() || '');
  const [showRejectModal, setShowRejectModal] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [showManualMatch, setShowManualMatch] = useState(false);

  const imageRef = useRef<HTMLImageElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setTempAmount(slip.amount?.toString() || '');
  }, [slip.amount]);

  const handleImageClick = () => {
    setIsZoomed(true);
  };

  const handleZoomIn = () => {
    setZoomLevel(prev => Math.min(prev * 1.5, 5));
  };

  const handleZoomOut = () => {
    setZoomLevel(prev => Math.max(prev / 1.5, 0.5));
  };

  const handleMouseDown = (e: React.MouseEvent) => {
    if (!isZoomed) return;
    setIsDragging(true);
    setDragStart({
      x: e.clientX - panPosition.x,
      y: e.clientY - panPosition.y,
    });
  };

  const handleMouseMove = (e: React.MouseEvent) => {
    if (!isDragging || !isZoomed) return;
    setPanPosition({
      x: e.clientX - dragStart.x,
      y: e.clientY - dragStart.y,
    });
  };

  const handleMouseUp = () => {
    setIsDragging(false);
  };

  const handleAmountSave = () => {
    const amount = parseFloat(tempAmount);
    if (!isNaN(amount) && amount > 0 && onAmountUpdate) {
      onAmountUpdate(slip.id, amount);
    }
    setEditingAmount(false);
  };

  const handleAmountCancel = () => {
    setTempAmount(slip.amount?.toString() || '');
    setEditingAmount(false);
  };

  const handleReject = () => {
    if (onReject) {
      onReject(slip.id, rejectReason || undefined);
    }
    setShowRejectModal(false);
    setRejectReason('');
  };

  const getStatusBadge = () => {
    const statusConfig = {
      PENDING: { color: 'bg-yellow-100 text-yellow-800', text: 'รอดำเนินการ' },
      MATCHED: { color: 'bg-green-100 text-green-800', text: 'จับคู่แล้ว' },
      REJECTED: { color: 'bg-red-100 text-red-800', text: 'ปฏิเสธ' },
      PROCESSING: { color: 'bg-blue-100 text-blue-800', text: 'กำลังประมวลผล' },
    };

    const config = statusConfig[slip.status] || statusConfig.PENDING;

    return (
      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
        {config.text}
      </span>
    );
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

  return (
    <>
      <div className={`bg-white rounded-lg shadow-md overflow-hidden ${className}`}>
        {/* Header */}
        <div className="p-4 border-b border-gray-200">
          <div className="flex items-center justify-between">
            <div className="flex items-center space-x-3">
              <h3 className="text-lg font-medium text-gray-900">
                ใบเสร็จ #{slip.id.slice(-8)}
              </h3>
              {getStatusBadge()}
            </div>
            <div className="flex items-center space-x-2">
              {slip.status === 'PENDING' && onDelete && (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => onDelete(slip.id)}
                  className="text-red-600 hover:text-red-700"
                >
                  ลบ
                </Button>
              )}
            </div>
          </div>
        </div>

        {/* Image Preview */}
        <div className="relative">
          <div
            ref={containerRef}
            className="relative h-64 bg-gray-100 cursor-pointer overflow-hidden"
            onClick={handleImageClick}
          >
            <img
              ref={imageRef}
              src={slip.imageUrl}
              alt="Payment slip"
              className="w-full h-full object-contain transition-transform duration-200 hover:scale-105"
            />
            <div className="absolute inset-0 bg-black bg-opacity-0 hover:bg-opacity-10 transition-all duration-200 flex items-center justify-center">
              <div className="bg-white bg-opacity-90 rounded-full p-2 opacity-0 hover:opacity-100 transition-opacity duration-200">
                <svg className="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                </svg>
              </div>
            </div>
          </div>
        </div>

        {/* Details */}
        <div className="p-4 space-y-4">
          {/* Amount Section */}
          <div className="flex items-center justify-between">
            <label className="text-sm font-medium text-gray-700">จำนวนเงิน:</label>
            <div className="flex items-center space-x-2">
              {editingAmount ? (
                <div className="flex items-center space-x-2">
                  <Input
                    type="number"
                    value={tempAmount}
                    onChange={(e) => setTempAmount(e.target.value)}
                    className="w-32 text-right"
                    placeholder="0.00"
                    step="0.01"
                    min="0"
                  />
                  <Button size="sm" onClick={handleAmountSave}>
                    บันทึก
                  </Button>
                  <Button size="sm" variant="secondary" onClick={handleAmountCancel}>
                    ยกเลิก
                  </Button>
                </div>
              ) : (
                <div className="flex items-center space-x-2">
                  <span className="text-lg font-semibold text-gray-900">
                    {slip.amount ? formatCurrency(slip.amount) : 'ไม่ระบุ'}
                  </span>
                  {slip.status === 'PENDING' && onAmountUpdate && (
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => setEditingAmount(true)}
                    >
                      แก้ไข
                    </Button>
                  )}
                </div>
              )}
            </div>
          </div>

          {/* Upload Info */}
          <div className="text-sm text-gray-600 space-y-1">
            <p>อัปโหลดโดย: {slip.uploadedBy}</p>
            <p>วันที่อัปโหลด: {formatDate(slip.createdAt)}</p>
            {slip.processedAt && (
              <p>วันที่ประมวลผล: {formatDate(slip.processedAt)}</p>
            )}
          </div>

          {/* Matched Order Info */}
          {slip.matchedOrder && (
            <div className="bg-green-50 border border-green-200 rounded-lg p-3">
              <h4 className="text-sm font-medium text-green-800 mb-2">จับคู่กับออเดอร์:</h4>
              <div className="text-sm text-green-700 space-y-1">
                <p>รหัสออเดอร์: {slip.matchedOrder.odooOrderId}</p>
                <p>ลูกค้า: {slip.matchedOrder.customerName || slip.matchedOrder.customerRef}</p>
                <p>จำนวนเงิน: {formatCurrency(slip.matchedOrder.totalAmount)}</p>
              </div>
            </div>
          )}

          {/* Potential Matches */}
          {potentialMatches.length > 0 && slip.status === 'PENDING' && (
            <div className="space-y-2">
              <h4 className="text-sm font-medium text-gray-700">ออเดอร์ที่เป็นไปได้:</h4>
              <div className="space-y-2 max-h-32 overflow-y-auto">
                {potentialMatches.map((match) => (
                  <div
                    key={match.orderId}
                    className="flex items-center justify-between p-2 bg-gray-50 rounded border"
                  >
                    <div className="flex-1">
                      <p className="text-sm font-medium">
                        {formatCurrency(match.amount)}
                      </p>
                      <p className="text-xs text-gray-500">
                        ความแม่นยำ: {Math.round(match.confidence * 100)}%
                      </p>
                    </div>
                    {onMatch && (
                      <Button
                        size="sm"
                        onClick={() => onMatch(slip.id, match.orderId)}
                        className="ml-2"
                      >
                        จับคู่
                      </Button>
                    )}
                  </div>
                ))}
              </div>
              <div className="mt-3 pt-2 border-t border-gray-200">
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => setShowManualMatch(true)}
                  className="w-full"
                >
                  ค้นหาออเดอร์เพิ่มเติม
                </Button>
              </div>
            </div>
          )}

          {/* Actions */}
          {slip.status === 'PENDING' && (
            <div className="flex items-center justify-end space-x-2 pt-2 border-t border-gray-200">
              {onReject && (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => setShowRejectModal(true)}
                  className="text-red-600 hover:text-red-700"
                >
                  ปฏิเสธ
                </Button>
              )}
            </div>
          )}

          {/* Notes */}
          {slip.notes && (
            <div className="text-sm text-gray-600">
              <p className="font-medium">หมายเหตุ:</p>
              <p className="mt-1">{slip.notes}</p>
            </div>
          )}
        </div>
      </div>

      {/* Zoomed Image Modal */}
      {isZoomed && (
        <Modal
          isOpen={isZoomed}
          onClose={() => {
            setIsZoomed(false);
            setZoomLevel(1);
            setPanPosition({ x: 0, y: 0 });
          }}
          title="ดูใบเสร็จ"
          size="full"
        >
          <div className="relative h-full bg-gray-900">
            {/* Zoom Controls */}
            <div className="absolute top-4 right-4 z-10 flex items-center space-x-2">
              <Button size="sm" onClick={handleZoomOut} disabled={zoomLevel <= 0.5}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 12H4" />
                </svg>
              </Button>
              <span className="text-white text-sm px-2">
                {Math.round(zoomLevel * 100)}%
              </span>
              <Button size="sm" onClick={handleZoomIn} disabled={zoomLevel >= 5}>
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                </svg>
              </Button>
            </div>

            {/* Image Container */}
            <div
              className="w-full h-full flex items-center justify-center overflow-hidden cursor-move"
              onMouseDown={handleMouseDown}
              onMouseMove={handleMouseMove}
              onMouseUp={handleMouseUp}
              onMouseLeave={handleMouseUp}
            >
              <img
                src={slip.imageUrl}
                alt="Payment slip"
                className="max-w-none transition-transform duration-100"
                style={{
                  transform: `scale(${zoomLevel}) translate(${panPosition.x / zoomLevel}px, ${panPosition.y / zoomLevel}px)`,
                }}
                draggable={false}
              />
            </div>
          </div>
        </Modal>
      )}

      {/* Reject Modal */}
      <Modal
        isOpen={showRejectModal}
        onClose={() => {
          setShowRejectModal(false);
          setRejectReason('');
        }}
        title="ปฏิเสธใบเสร็จ"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            คุณแน่ใจหรือไม่ที่จะปฏิเสธใบเสร็จนี้?
          </p>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              เหตุผล (ไม่บังคับ):
            </label>
            <textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              rows={3}
              placeholder="ระบุเหตุผลในการปฏิเสธ..."
            />
          </div>
          <div className="flex items-center justify-end space-x-2">
            <Button
              variant="secondary"
              onClick={() => {
                setShowRejectModal(false);
                setRejectReason('');
              }}
            >
              ยกเลิก
            </Button>
            <Button
              onClick={handleReject}
              className="bg-red-600 hover:bg-red-700 text-white"
            >
              ปฏิเสธ
            </Button>
          </div>
        </div>
      </Modal>

      {/* Manual Matching Interface */}
      <ManualMatchingInterface
        slip={slip}
        isOpen={showManualMatch}
        onClose={() => setShowManualMatch(false)}
        onMatch={onMatch || (() => {})}
        potentialMatches={potentialMatches}
      />
    </>
  );
}