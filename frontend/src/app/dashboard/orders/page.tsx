'use client';

import React from 'react';
import { DashboardLayout, NavigationItem } from '@/components/layout/DashboardLayout';
import { OrderList } from '@/components/orders/OrderList';
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

// Mock navigation - in a real app, this would be based on user permissions
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
];

export default function OrdersPage() {
  return (
    <DashboardLayout 
      user={mockUser} 
      navigation={mockNavigation}
      currentPath="/dashboard/orders"
    >
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Order Management</h1>
            <p className="text-gray-600">
              Manage and track all orders with real-time status updates
            </p>
          </div>
        </div>

        <OrderList />
      </div>
    </DashboardLayout>
  );
}