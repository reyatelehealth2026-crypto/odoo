'use client';

import React from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { DashboardOverview, DateRange, DashboardMetrics } from '@/components/dashboard';
import { User } from '@/types';

// Mock user data - in real implementation, this would come from authentication
const mockUser: User = {
  id: '1',
  username: 'admin',
  email: 'admin@example.com',
  role: 'ADMIN',
  lineAccountId: '1',
  isActive: true,
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
};

// Mock navigation items
const navigationItems = [
  {
    id: 'dashboard',
    label: 'แดshboard',
    href: '/dashboard',
    icon: (
      <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
      </svg>
    ),
  },
  {
    id: 'orders',
    label: 'คำสั่งซื้อ',
    href: '/orders',
    icon: (
      <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
      </svg>
    ),
    badge: '8',
  },
  {
    id: 'payments',
    label: 'การชำระเงิน',
    href: '/payments',
    icon: (
      <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
      </svg>
    ),
    badge: '12',
  },
  {
    id: 'webhooks',
    label: 'Webhooks',
    href: '/webhooks',
    icon: (
      <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M9.348 14.651a3.75 3.75 0 010-5.303m5.304 0a3.75 3.75 0 010 5.303m-7.425 2.122a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-1.38-.965-2.5-2.09-3.281-3.311m14.35 0c.781-1.221 1.901-2.346 3.281-3.311M12 12h.008v.008H12V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
      </svg>
    ),
  },
  {
    id: 'customers',
    label: 'ลูกค้า',
    href: '/customers',
    icon: (
      <svg fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
      </svg>
    ),
  },
];

export default function DashboardPage() {
  const [dateRange, setDateRange] = React.useState<DateRange>({
    from: new Date(new Date().setHours(0, 0, 0, 0)),
    to: new Date(new Date().setHours(23, 59, 59, 999)),
  });
  
  const [metrics, setMetrics] = React.useState<DashboardMetrics | undefined>();
  const [loading, setLoading] = React.useState(true);
  const [realTimeEnabled, setRealTimeEnabled] = React.useState(false);

  // Simulate API call
  React.useEffect(() => {
    const fetchMetrics = async () => {
      setLoading(true);
      
      // Simulate API delay
      await new Promise(resolve => setTimeout(resolve, 1000));
      
      // Mock data
      const mockMetrics: DashboardMetrics = {
        orders: {
          todayCount: 25,
          todayTotal: 15750,
          pendingCount: 8,
          completedCount: 17,
          averageOrderValue: 630,
        },
        payments: {
          pendingSlips: 12,
          processedToday: 18,
          matchingRate: 92.5,
          totalAmount: 14200,
          averageProcessingTime: 45,
        },
        webhooks: {
          todayCount: 156,
          successRate: 98.7,
          failedCount: 2,
          averageResponseTime: 250,
        },
        customers: {
          totalActive: 1247,
          newToday: 8,
          lineConnected: 1089,
          averageOrdersPerCustomer: 3.2,
        },
        updatedAt: new Date().toISOString(),
      };
      
      setMetrics(mockMetrics);
      setLoading(false);
    };

    fetchMetrics();
  }, [dateRange]);

  // Real-time updates simulation
  React.useEffect(() => {
    if (!realTimeEnabled) return;

    const interval = setInterval(() => {
      if (metrics) {
        setMetrics({
          ...metrics,
          orders: {
            ...metrics.orders,
            todayCount: metrics.orders.todayCount + Math.floor(Math.random() * 3),
            todayTotal: metrics.orders.todayTotal + Math.floor(Math.random() * 1000),
          },
          updatedAt: new Date().toISOString(),
        });
      }
    }, 30000); // Update every 30 seconds

    return () => clearInterval(interval);
  }, [realTimeEnabled, metrics]);

  const handleDateRangeChange = (newRange: DateRange) => {
    setDateRange(newRange);
  };

  const toggleRealTime = () => {
    setRealTimeEnabled(!realTimeEnabled);
  };

  return (
    <DashboardLayout
      user={mockUser}
      navigation={navigationItems}
      currentPath="/dashboard"
    >
      <div className="space-y-6">
        {/* Real-time toggle */}
        <div className="flex justify-end">
          <button
            onClick={toggleRealTime}
            className={`flex items-center rounded-lg px-4 py-2 text-sm font-medium transition-colors ${
              realTimeEnabled
                ? 'bg-green-100 text-green-700 hover:bg-green-200'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            <div
              className={`mr-2 h-2 w-2 rounded-full ${
                realTimeEnabled ? 'bg-green-500 animate-pulse' : 'bg-gray-400'
              }`}
            />
            {realTimeEnabled ? 'ปิดการอัปเดตแบบเรียลไทม์' : 'เปิดการอัปเดตแบบเรียลไทม์'}
          </button>
        </div>

        <DashboardOverview
          metrics={metrics}
          dateRange={dateRange}
          onDateRangeChange={handleDateRangeChange}
          loading={loading}
          realTimeEnabled={realTimeEnabled}
        />
      </div>
    </DashboardLayout>
  );
}