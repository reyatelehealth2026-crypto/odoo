'use client';

import React, { useState, useEffect } from 'react';
import { Customer, CustomerOrder, PaginatedCustomerOrders } from '@/types/customers';
import { customersAPI } from '@/lib/api/customers';

interface CustomerProfileProps {
  customer: Customer;
  onClose: () => void;
  onLineConnectionUpdate?: (customerId: string, lineUserId: string | null) => void;
}

export const CustomerProfile: React.FC<CustomerProfileProps> = ({
  customer,
  onClose,
  onLineConnectionUpdate,
}) => {
  const [activeTab, setActiveTab] = useState<'profile' | 'orders' | 'medical'>('profile');
  const [orders, setOrders] = useState<CustomerOrder[]>([]);
  const [ordersLoading, setOrdersLoading] = useState(false);
  const [ordersPagination, setOrdersPagination] = useState({
    page: 1,
    limit: 10,
    total: 0,
    totalPages: 0,
  });

  useEffect(() => {
    if (activeTab === 'orders') {
      loadOrders();
    }
  }, [activeTab, ordersPagination.page]);

  const loadOrders = async () => {
    setOrdersLoading(true);
    try {
      const result = await customersAPI.getCustomerOrders(
        customer.id,
        ordersPagination.page,
        ordersPagination.limit
      );
      setOrders(result.data);
      setOrdersPagination(result.meta);
    } catch (error) {
      console.error('Failed to load orders:', error);
    } finally {
      setOrdersLoading(false);
    }
  };

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: 'THB',
    }).format(amount);
  };

  const formatDate = (date: Date | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  const getTierBadgeColor = (tier: string | null) => {
    switch (tier?.toLowerCase()) {
      case 'platinum':
        return 'bg-purple-100 text-purple-800';
      case 'gold':
        return 'bg-yellow-100 text-yellow-800';
      case 'silver':
        return 'bg-gray-100 text-gray-800';
      case 'bronze':
        return 'bg-orange-100 text-orange-800';
      default:
        return 'bg-gray-100 text-gray-600';
    }
  };

  const getStatusBadgeColor = (status: string) => {
    switch (status.toLowerCase()) {
      case 'completed':
      case 'delivered':
        return 'bg-green-100 text-green-800';
      case 'processing':
      case 'pending':
        return 'bg-yellow-100 text-yellow-800';
      case 'cancelled':
        return 'bg-red-100 text-red-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        {/* Header */}
        <div className="bg-gradient-to-r from-blue-600 to-blue-700 text-white p-6">
          <div className="flex items-start justify-between">
            <div className="flex-1">
              <h2 className="text-2xl font-bold mb-2">
                {customer.realName || customer.displayName || 'ไม่ระบุชื่อ'}
              </h2>
              <div className="flex items-center gap-3 text-blue-100">
                <span className="flex items-center gap-1">
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                  </svg>
                  {customer.email || 'ไม่มีอีเมล'}
                </span>
                <span className="flex items-center gap-1">
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                  </svg>
                  {customer.phone || 'ไม่มีเบอร์โทร'}
                </span>
              </div>
            </div>
            <button
              onClick={onClose}
              className="text-white hover:text-gray-200 transition-colors"
            >
              <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Stats */}
          <div className="grid grid-cols-3 gap-4 mt-6">
            <div className="bg-white bg-opacity-20 rounded-lg p-3">
              <div className="text-sm text-blue-100">ยอดสั่งซื้อทั้งหมด</div>
              <div className="text-2xl font-bold">{customer.totalOrders}</div>
            </div>
            <div className="bg-white bg-opacity-20 rounded-lg p-3">
              <div className="text-sm text-blue-100">มูลค่ารวม</div>
              <div className="text-2xl font-bold">{formatCurrency(customer.totalSpent)}</div>
            </div>
            <div className="bg-white bg-opacity-20 rounded-lg p-3">
              <div className="text-sm text-blue-100">คะแนนสะสม</div>
              <div className="text-2xl font-bold">{customer.availablePoints}</div>
            </div>
          </div>
        </div>

        {/* Tabs */}
        <div className="border-b">
          <div className="flex">
            <button
              onClick={() => setActiveTab('profile')}
              className={`px-6 py-3 font-medium transition-colors ${
                activeTab === 'profile'
                  ? 'border-b-2 border-blue-600 text-blue-600'
                  : 'text-gray-600 hover:text-gray-800'
              }`}
            >
              ข้อมูลส่วนตัว
            </button>
            <button
              onClick={() => setActiveTab('orders')}
              className={`px-6 py-3 font-medium transition-colors ${
                activeTab === 'orders'
                  ? 'border-b-2 border-blue-600 text-blue-600'
                  : 'text-gray-600 hover:text-gray-800'
              }`}
            >
              ประวัติการสั่งซื้อ
            </button>
            <button
              onClick={() => setActiveTab('medical')}
              className={`px-6 py-3 font-medium transition-colors ${
                activeTab === 'medical'
                  ? 'border-b-2 border-blue-600 text-blue-600'
                  : 'text-gray-600 hover:text-gray-800'
              }`}
            >
              ข้อมูลทางการแพทย์
            </button>
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-6">
          {activeTab === 'profile' && (
            <div className="space-y-6">
              {/* Basic Info */}
              <div>
                <h3 className="text-lg font-semibold mb-4">ข้อมูลพื้นฐาน</h3>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="text-sm text-gray-600">ชื่อที่แสดง</label>
                    <p className="font-medium">{customer.displayName || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">ชื่อจริง</label>
                    <p className="font-medium">{customer.realName || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">เพศ</label>
                    <p className="font-medium">{customer.gender || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">วันเกิด</label>
                    <p className="font-medium">{formatDate(customer.birthday)}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">ระดับสมาชิก</label>
                    <p>
                      <span className={`inline-block px-3 py-1 rounded-full text-sm font-medium ${getTierBadgeColor(customer.tier)}`}>
                        {customer.tier || 'ไม่มี'}
                      </span>
                    </p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">คะแนนลูกค้า</label>
                    <p className="font-medium">{customer.customerScore || 0}</p>
                  </div>
                </div>
              </div>

              {/* Address */}
              <div>
                <h3 className="text-lg font-semibold mb-4">ที่อยู่</h3>
                <div className="grid grid-cols-2 gap-4">
                  <div className="col-span-2">
                    <label className="text-sm text-gray-600">ที่อยู่</label>
                    <p className="font-medium">{customer.address || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">จังหวัด</label>
                    <p className="font-medium">{customer.province || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">เขต/อำเภอ</label>
                    <p className="font-medium">{customer.district || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">รหัสไปรษณีย์</label>
                    <p className="font-medium">{customer.postalCode || '-'}</p>
                  </div>
                </div>
              </div>

              {/* LINE Connection */}
              <div>
                <h3 className="text-lg font-semibold mb-4">การเชื่อมต่อ LINE</h3>
                <div className="flex items-center gap-3">
                  {customer.lineUserId ? (
                    <>
                      <span className="flex items-center gap-2 text-green-600">
                        <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                        </svg>
                        เชื่อมต่อแล้ว
                      </span>
                      <span className="text-sm text-gray-600">({customer.lineUserId})</span>
                    </>
                  ) : (
                    <span className="flex items-center gap-2 text-gray-500">
                      <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                      </svg>
                      ยังไม่เชื่อมต่อ
                    </span>
                  )}
                </div>
              </div>

              {/* Notes */}
              {customer.notes && (
                <div>
                  <h3 className="text-lg font-semibold mb-4">หมายเหตุ</h3>
                  <p className="text-gray-700 whitespace-pre-wrap">{customer.notes}</p>
                </div>
              )}
            </div>
          )}

          {activeTab === 'orders' && (
            <div className="space-y-4">
              {ordersLoading ? (
                <div className="flex justify-center py-8">
                  <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                </div>
              ) : orders.length === 0 ? (
                <div className="text-center py-8 text-gray-500">
                  ไม่มีประวัติการสั่งซื้อ
                </div>
              ) : (
                <>
                  <div className="space-y-3">
                    {orders.map((order) => (
                      <div key={order.id} className="border rounded-lg p-4 hover:bg-gray-50 transition-colors">
                        <div className="flex items-start justify-between">
                          <div className="flex-1">
                            <div className="flex items-center gap-3 mb-2">
                              <span className="font-medium">#{order.odooOrderId}</span>
                              <span className={`px-2 py-1 rounded-full text-xs font-medium ${getStatusBadgeColor(order.status)}`}>
                                {order.status}
                              </span>
                            </div>
                            <div className="text-sm text-gray-600 space-y-1">
                              <div>วันที่สั่งซื้อ: {formatDate(order.orderDate)}</div>
                              {order.deliveryDate && (
                                <div>วันที่จัดส่ง: {formatDate(order.deliveryDate)}</div>
                              )}
                            </div>
                          </div>
                          <div className="text-right">
                            <div className="text-lg font-bold text-blue-600">
                              {formatCurrency(order.totalAmount)}
                            </div>
                            <div className="text-xs text-gray-500">{order.currency}</div>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>

                  {/* Pagination */}
                  {ordersPagination.totalPages > 1 && (
                    <div className="flex items-center justify-between pt-4 border-t">
                      <div className="text-sm text-gray-600">
                        แสดง {orders.length} จาก {ordersPagination.total} รายการ
                      </div>
                      <div className="flex gap-2">
                        <button
                          onClick={() => setOrdersPagination(prev => ({ ...prev, page: prev.page - 1 }))}
                          disabled={ordersPagination.page === 1}
                          className="px-3 py-1 border rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                          ก่อนหน้า
                        </button>
                        <span className="px-3 py-1">
                          {ordersPagination.page} / {ordersPagination.totalPages}
                        </span>
                        <button
                          onClick={() => setOrdersPagination(prev => ({ ...prev, page: prev.page + 1 }))}
                          disabled={ordersPagination.page === ordersPagination.totalPages}
                          className="px-3 py-1 border rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                          ถัดไป
                        </button>
                      </div>
                    </div>
                  )}
                </>
              )}
            </div>
          )}

          {activeTab === 'medical' && (
            <div className="space-y-6">
              <div>
                <h3 className="text-lg font-semibold mb-4">ข้อมูลทางการแพทย์</h3>
                <div className="space-y-4">
                  <div>
                    <label className="text-sm text-gray-600">กรุ๊ปเลือด</label>
                    <p className="font-medium">{customer.bloodType || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">โรคประจำตัว</label>
                    <p className="font-medium whitespace-pre-wrap">{customer.medicalConditions || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">ประวัติแพ้ยา</label>
                    <p className="font-medium whitespace-pre-wrap">{customer.drugAllergies || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">ยาที่ใช้ประจำ</label>
                    <p className="font-medium whitespace-pre-wrap">{customer.currentMedications || '-'}</p>
                  </div>
                  <div>
                    <label className="text-sm text-gray-600">ผู้ติดต่อฉุกเฉิน</label>
                    <p className="font-medium">{customer.emergencyContact || '-'}</p>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
