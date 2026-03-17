'use client';

import React, { useState } from 'react';
import { Customer } from '@/types/customers';
import { customersAPI } from '@/lib/api/customers';

interface LineAccountLinkProps {
  customer: Customer;
  onUpdate: (updatedCustomer: Customer) => void;
  onCancel: () => void;
}

export const LineAccountLink: React.FC<LineAccountLinkProps> = ({
  customer,
  onUpdate,
  onCancel,
}) => {
  const [lineUserId, setLineUserId] = useState(customer.lineUserId || '');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const updatedCustomer = await customersAPI.updateLineConnection(customer.id, {
        lineUserId: lineUserId || null,
      });
      onUpdate(updatedCustomer);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาดในการอัปเดต');
    } finally {
      setLoading(false);
    }
  };

  const handleDisconnect = async () => {
    if (!confirm('คุณต้องการยกเลิกการเชื่อมต่อ LINE ใช่หรือไม่?')) {
      return;
    }

    setError(null);
    setLoading(true);

    try {
      const updatedCustomer = await customersAPI.updateLineConnection(customer.id, {
        lineUserId: null,
      });
      onUpdate(updatedCustomer);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาดในการยกเลิกการเชื่อมต่อ');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
        {/* Header */}
        <div className="border-b px-6 py-4">
          <h3 className="text-lg font-semibold">จัดการการเชื่อมต่อ LINE</h3>
          <p className="text-sm text-gray-600 mt-1">
            {customer.realName || customer.displayName || 'ลูกค้า'}
          </p>
        </div>

        {/* Content */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {/* Current Status */}
          <div className="bg-gray-50 rounded-lg p-4">
            <div className="text-sm text-gray-600 mb-2">สถานะปัจจุบัน</div>
            {customer.lineUserId ? (
              <div className="flex items-center gap-2 text-green-600">
                <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                </svg>
                <span className="font-medium">เชื่อมต่อแล้ว</span>
              </div>
            ) : (
              <div className="flex items-center gap-2 text-gray-500">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
                <span className="font-medium">ยังไม่เชื่อมต่อ</span>
              </div>
            )}
          </div>

          {/* LINE User ID Input */}
          <div>
            <label htmlFor="lineUserId" className="block text-sm font-medium text-gray-700 mb-2">
              LINE User ID
            </label>
            <input
              type="text"
              id="lineUserId"
              value={lineUserId}
              onChange={(e) => setLineUserId(e.target.value)}
              placeholder="กรอก LINE User ID"
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              disabled={loading}
            />
            <p className="text-xs text-gray-500 mt-1">
              LINE User ID เป็นรหัสเฉพาะที่ใช้ระบุผู้ใช้ LINE (เช่น U1234567890abcdef)
            </p>
          </div>

          {/* Error Message */}
          {error && (
            <div className="bg-red-50 border border-red-200 rounded-lg p-3">
              <div className="flex items-start gap-2">
                <svg className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                </svg>
                <p className="text-sm text-red-800">{error}</p>
              </div>
            </div>
          )}

          {/* Help Text */}
          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <div className="flex items-start gap-2">
              <svg className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
              </svg>
              <div className="text-sm text-blue-800">
                <p className="font-medium mb-1">วิธีหา LINE User ID:</p>
                <ol className="list-decimal list-inside space-y-1 text-xs">
                  <li>ให้ลูกค้าส่งข้อความมาที่ LINE Official Account</li>
                  <li>ตรวจสอบ User ID จากระบบ Chat Inbox</li>
                  <li>คัดลอก User ID มาใส่ในช่องนี้</li>
                </ol>
              </div>
            </div>
          </div>

          {/* Actions */}
          <div className="flex gap-3 pt-4">
            {customer.lineUserId && (
              <button
                type="button"
                onClick={handleDisconnect}
                disabled={loading}
                className="flex-1 px-4 py-2 border border-red-300 text-red-700 rounded-lg hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                {loading ? 'กำลังยกเลิก...' : 'ยกเลิกการเชื่อมต่อ'}
              </button>
            )}
            <button
              type="button"
              onClick={onCancel}
              disabled={loading}
              className="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              ยกเลิก
            </button>
            <button
              type="submit"
              disabled={loading || !lineUserId}
              className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed transition-colors"
            >
              {loading ? (
                <span className="flex items-center justify-center gap-2">
                  <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                  </svg>
                  กำลังบันทึก...
                </span>
              ) : (
                'บันทึก'
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};
