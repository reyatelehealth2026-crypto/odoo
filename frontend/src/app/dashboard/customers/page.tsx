'use client';

import React, { useState, useEffect } from 'react';
import { DashboardLayout, NavigationItem } from '@/components/layout/DashboardLayout';
import { CustomerSearch } from '@/components/customers/CustomerSearch';
import { CustomerProfile } from '@/components/customers/CustomerProfile';
import { LineAccountLink } from '@/components/customers/LineAccountLink';
import { customersAPI } from '@/lib/api/customers';
import { Customer, CustomerListItem, CustomerFilters, CustomerStatistics } from '@/types/customers';
import { User, UserRole } from '@/types';

// Mock user data - in a real app, this would come from authentication context
const mockUser: User = {
  id: '1',
  username: 'admin',
  email: 'admin@example.com',
  role: 'ADMIN' as UserRole,
  lineAccountId: '1',
  isActive: true,
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
};

// Mock navigation
const mockNavigation: NavigationItem[] = [
  {
    id: 'dashboard',
    label: 'Dashboard',
    href: '/dashboard',
    icon: (
      <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z" />
      </svg>
    ),
  },
  {
    id: 'orders',
    label: 'Orders',
    href: '/dashboard/orders',
    icon: (
      <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
    ),
  },
  {
    id: 'customers',
    label: 'Customers',
    href: '/dashboard/customers',
    icon: (
      <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
      </svg>
    ),
  },
];

export default function CustomersPage() {
  const [customers, setCustomers] = useState<CustomerListItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pagination, setPagination] = useState({
    page: 1,
    limit: 20,
    total: 0,
    totalPages: 0,
  });
  const [statistics, setStatistics] = useState<CustomerStatistics | null>(null);
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
  const [linkingCustomer, setLinkingCustomer] = useState<Customer | null>(null);
  const [currentFilters, setCurrentFilters] = useState<CustomerFilters>({});

  useEffect(() => {
    loadCustomers();
    loadStatistics();
  }, [pagination.page, currentFilters]);

  const loadCustomers = async () => {
    setLoading(true);
    setError(null);

    try {
      const result = await customersAPI.searchCustomers({
        ...currentFilters,
        page: pagination.page,
        limit: pagination.limit,
      });

      setCustomers(result.data);
      setPagination(result.meta);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'เกิดข้อผิดพลาดในการโหลดข้อมูล');
    } finally {
      setLoading(false);
    }
  };

  const loadStatistics = async () => {
    try {
      const stats = await customersAPI.getCustomerStatistics();
      setStatistics(stats);
    } catch (err) {
      console.error('Failed to load statistics:', err);
    }
  };

  const handleSearch = (filters: CustomerFilters) => {
    setCurrentFilters(filters);
    setPagination((prev) => ({ ...prev, page: 1 }));
  };

  const handleCustomerClick = async (customer: CustomerListItem) => {
    try {
      const fullCustomer = await customersAPI.getCustomerById(customer.id);
      setSelectedCustomer(fullCustomer);
    } catch (err) {
      console.error('Failed to load customer details:', err);
    }
  };

  const handleLineConnectionClick = async (customer: CustomerListItem) => {
    try {
      const fullCustomer = await customersAPI.getCustomerById(customer.id);
      setLinkingCustomer(fullCustomer);
    } catch (err) {
      console.error('Failed to load customer details:', err);
    }
  };

  const handleLineConnectionUpdate = (updatedCustomer: Customer) => {
    // Update customer in list
    setCustomers((prev) =>
      prev.map((c) =>
        c.id === updatedCustomer.id
          ? { ...c, lineUserId: updatedCustomer.lineUserId }
          : c
      )
    );
    setLinkingCustomer(null);
    loadStatistics(); // Refresh statistics
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

  return (
    <DashboardLayout
      user={mockUser}
      navigation={mockNavigation}
      currentPath="/dashboard/customers"
    >
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">จัดการลูกค้า</h1>
            <p className="text-gray-600">
              ค้นหาและจัดการข้อมูลลูกค้า รวมถึงการเชื่อมต่อ LINE
            </p>
          </div>
        </div>

        {/* Statistics */}
        {statistics && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-600">ลูกค้าทั้งหมด</div>
              <div className="text-2xl font-bold text-gray-900 mt-1">
                {statistics.totalCustomers.toLocaleString()}
              </div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-600">ลูกค้าใหม่ (30 วัน)</div>
              <div className="text-2xl font-bold text-green-600 mt-1">
                {statistics.newCustomers.toLocaleString()}
              </div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-600">ลูกค้าที่ใช้งาน</div>
              <div className="text-2xl font-bold text-blue-600 mt-1">
                {statistics.activeCustomers.toLocaleString()}
              </div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-600">เชื่อมต่อ LINE</div>
              <div className="text-2xl font-bold text-purple-600 mt-1">
                {statistics.lineConnected.toLocaleString()}
              </div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-600">มูลค่าเฉลี่ย/ออเดอร์</div>
              <div className="text-2xl font-bold text-orange-600 mt-1">
                {formatCurrency(statistics.averageOrderValue)}
              </div>
            </div>
          </div>
        )}

        {/* Search */}
        <CustomerSearch onSearch={handleSearch} loading={loading} />

        {/* Error Message */}
        {error && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4">
            <div className="flex items-start gap-3">
              <svg className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
              </svg>
              <p className="text-sm text-red-800">{error}</p>
            </div>
          </div>
        )}

        {/* Customer List */}
        <div className="bg-white rounded-lg shadow overflow-hidden">
          {loading ? (
            <div className="flex justify-center items-center py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
            </div>
          ) : customers.length === 0 ? (
            <div className="text-center py-12">
              <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <p className="mt-4 text-gray-600">ไม่พบข้อมูลลูกค้า</p>
            </div>
          ) : (
            <>
              {/* Table */}
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        ลูกค้า
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        ติดต่อ
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        ระดับ
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        ออเดอร์
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        มูลค่ารวม
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        LINE
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        การใช้งานล่าสุด
                      </th>
                      <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                        จัดการ
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {customers.map((customer) => (
                      <tr
                        key={customer.id}
                        className="hover:bg-gray-50 transition-colors cursor-pointer"
                        onClick={() => handleCustomerClick(customer)}
                      >
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center">
                            <div className="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                              <span className="text-blue-600 font-medium">
                                {(customer.realName || customer.displayName || '?').charAt(0).toUpperCase()}
                              </span>
                            </div>
                            <div className="ml-4">
                              <div className="text-sm font-medium text-gray-900">
                                {customer.realName || customer.displayName || 'ไม่ระบุชื่อ'}
                              </div>
                              {customer.displayName && customer.realName && customer.displayName !== customer.realName && (
                                <div className="text-sm text-gray-500">{customer.displayName}</div>
                              )}
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-sm text-gray-900">{customer.phone || '-'}</div>
                          <div className="text-sm text-gray-500">{customer.email || '-'}</div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${getTierBadgeColor(customer.tier)}`}>
                            {customer.tier || 'ไม่มี'}
                          </span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {customer.totalOrders}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                          {formatCurrency(customer.totalSpent)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          {customer.lineUserId ? (
                            <span className="inline-flex items-center gap-1 text-green-600 text-sm">
                              <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                              </svg>
                              เชื่อมต่อ
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 text-gray-400 text-sm">
                              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                              </svg>
                              ไม่เชื่อมต่อ
                            </span>
                          )}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {formatDate(customer.lastInteractionAt)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              handleLineConnectionClick(customer);
                            }}
                            className="text-blue-600 hover:text-blue-900 transition-colors"
                          >
                            จัดการ LINE
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {pagination.totalPages > 1 && (
                <div className="bg-white px-6 py-4 border-t flex items-center justify-between">
                  <div className="text-sm text-gray-700">
                    แสดง {customers.length} จาก {pagination.total.toLocaleString()} รายการ
                  </div>
                  <div className="flex gap-2">
                    <button
                      onClick={() => setPagination((prev) => ({ ...prev, page: prev.page - 1 }))}
                      disabled={pagination.page === 1}
                      className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                      ก่อนหน้า
                    </button>
                    <span className="px-4 py-2 text-gray-700">
                      หน้า {pagination.page} / {pagination.totalPages}
                    </span>
                    <button
                      onClick={() => setPagination((prev) => ({ ...prev, page: prev.page + 1 }))}
                      disabled={pagination.page === pagination.totalPages}
                      className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                      ถัดไป
                    </button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {/* Customer Profile Modal */}
      {selectedCustomer && (
        <CustomerProfile
          customer={selectedCustomer}
          onClose={() => setSelectedCustomer(null)}
          onLineConnectionUpdate={(customerId, lineUserId) => {
            setCustomers((prev) =>
              prev.map((c) => (c.id === customerId ? { ...c, lineUserId } : c))
            );
            setSelectedCustomer(null);
            loadStatistics();
          }}
        />
      )}

      {/* LINE Account Link Modal */}
      {linkingCustomer && (
        <LineAccountLink
          customer={linkingCustomer}
          onUpdate={handleLineConnectionUpdate}
          onCancel={() => setLinkingCustomer(null)}
        />
      )}
    </DashboardLayout>
  );
}
