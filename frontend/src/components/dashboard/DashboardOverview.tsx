'use client';

import React from 'react';
import { KPICard, TrendData } from './KPICard';
import { ChartComponent, ChartData, ChartConfig } from './ChartComponent';
import { DateRangeFilter, DateRange } from './DateRangeFilter';
import { ConnectionStatus } from './ConnectionStatus';
import { useDashboardRealtime } from '@/hooks/useDashboardRealtime';
import { cn } from '@/lib/utils';

export interface DashboardMetrics {
  orders: {
    todayCount: number;
    todayTotal: number;
    pendingCount: number;
    completedCount: number;
    averageOrderValue: number;
  };
  payments: {
    pendingSlips: number;
    processedToday: number;
    matchingRate: number;
    totalAmount: number;
    averageProcessingTime: number;
  };
  webhooks: {
    todayCount: number;
    successRate: number;
    failedCount: number;
    averageResponseTime: number;
  };
  customers: {
    totalActive: number;
    newToday: number;
    lineConnected: number;
    averageOrdersPerCustomer: number;
  };
  updatedAt: string;
}

export interface DashboardOverviewProps {
  metrics: DashboardMetrics | undefined;
  dateRange: DateRange;
  onDateRangeChange: (range: DateRange) => void;
  loading?: boolean;
  realTimeEnabled?: boolean;
  authToken?: string;
  className?: string;
}

export function DashboardOverview({
  metrics,
  dateRange,
  onDateRangeChange,
  loading = false,
  realTimeEnabled = false,
  authToken,
  className,
}: DashboardOverviewProps) {
  // Initialize real-time updates
  const realtimeStatus = useDashboardRealtime(authToken, realTimeEnabled);
  // Mock trend data - in real implementation, this would come from API
  const getTrendData = (current: number, previous: number): TrendData => {
    const change = ((current - previous) / previous) * 100;
    return {
      value: change,
      direction: change > 0 ? 'up' : change < 0 ? 'down' : 'neutral',
      percentage: Math.abs(change),
    };
  };

  // Mock chart data - in real implementation, this would come from API
  const generateMockChartData = (days: number): ChartData[] => {
    const data: ChartData[] = [];
    const today = new Date();
    
    for (let i = days - 1; i >= 0; i--) {
      const date = new Date(today);
      date.setDate(date.getDate() - i);
      const dateString = date.toISOString().split('T')[0];
      if (dateString) {
        data.push({
          date: dateString,
          value: Math.floor(Math.random() * 1000) + 500,
          count: Math.floor(Math.random() * 50) + 10,
        });
      }
    }
    
    return data;
  };

  const orderChartConfig: ChartConfig = {
    title: 'แนวโน้มคำสั่งซื้อ',
    type: 'line',
    color: '#3B82F6',
  };

  const paymentChartConfig: ChartConfig = {
    title: 'การประมวลผลการชำระเงิน',
    type: 'bar',
    color: '#10B981',
  };

  const webhookChartConfig: ChartConfig = {
    title: 'สถิติ Webhook',
    type: 'line',
    color: '#F59E0B',
  };

  return (
    <div className={cn('space-y-6', className)}>
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">แดชบอร์ด Odoo</h1>
          <p className="mt-1 text-sm text-gray-500">
            ภาพรวมข้อมูลสำคัญของระบบ
            {metrics && (
              <span className="ml-2">
                อัปเดตล่าสุด: {new Date(metrics.updatedAt).toLocaleString('th-TH')}
              </span>
            )}
          </p>
        </div>
        
        <div className="mt-4 sm:mt-0">
          <DateRangeFilter
            value={dateRange}
            onChange={onDateRangeChange}
          />
        </div>
      </div>

      {/* Real-time connection status */}
      {realTimeEnabled && (
        <ConnectionStatus
          connected={realtimeStatus.connected}
          connecting={realtimeStatus.connecting}
          error={realtimeStatus.error}
          reconnectAttempt={realtimeStatus.reconnectAttempt}
          lastUpdate={realtimeStatus.lastUpdate}
        />
      )}

      {/* Real-time indicator */}
      {realTimeEnabled && realtimeStatus.connected && (
        <div className="flex items-center justify-center rounded-lg bg-green-50 p-3">
          <div className="flex items-center text-sm text-green-700">
            <div className="mr-2 h-2 w-2 rounded-full bg-green-500 animate-pulse"></div>
            ระบบกำลังอัปเดตข้อมูลแบบเรียลไทม์ทุก 30 วินาที
            {realtimeStatus.lastUpdate && (
              <span className="ml-2 text-xs opacity-75">
                (อัปเดตล่าสุด: {realtimeStatus.lastUpdate.toLocaleTimeString('th-TH')})
              </span>
            )}
          </div>
        </div>
      )}

      {/* KPI Cards */}
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <KPICard
          title="คำสั่งซื้อวันนี้"
          value={metrics?.orders.todayCount || 0}
          format="number"
          loading={loading}
          trend={getTrendData(metrics?.orders.todayCount || 0, 20)}
          icon={
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
            </svg>
          }
        />
        
        <KPICard
          title="ยอดขายวันนี้"
          value={metrics?.orders.todayTotal || 0}
          format="currency"
          loading={loading}
          trend={getTrendData(metrics?.orders.todayTotal || 0, 12000)}
          icon={
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          }
        />
        
        <KPICard
          title="สลิปรอดำเนินการ"
          value={metrics?.payments.pendingSlips || 0}
          format="number"
          loading={loading}
          trend={getTrendData(metrics?.payments.pendingSlips || 0, 15)}
          icon={
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
          }
        />
        
        <KPICard
          title="อัตราความสำเร็จ Webhook"
          value={metrics?.webhooks.successRate || 0}
          format="percentage"
          loading={loading}
          trend={getTrendData(metrics?.webhooks.successRate || 0, 95)}
          icon={
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M9.348 14.651a3.75 3.75 0 010-5.303m5.304 0a3.75 3.75 0 010 5.303m-7.425 2.122a6.75 6.75 0 010-9.546m9.546 0a6.75 6.75 0 010 9.546M5.106 18.894c-1.38-.965-2.5-2.09-3.281-3.311m14.35 0c.781-1.221 1.901-2.346 3.281-3.311M12 12h.008v.008H12V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
            </svg>
          }
        />
      </div>

      {/* Secondary KPIs */}
      <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <KPICard
          title="คำสั่งซื้อรอดำเนินการ"
          value={metrics?.orders.pendingCount || 0}
          format="number"
          loading={loading}
        />
        
        <KPICard
          title="ลูกค้าใหม่วันนี้"
          value={metrics?.customers.newToday || 0}
          format="number"
          loading={loading}
        />
        
        <KPICard
          title="เวลาประมวลผลเฉลี่ย"
          value={`${metrics?.payments.averageProcessingTime || 0} นาที`}
          loading={loading}
        />
        
        <KPICard
          title="ลูกค้าที่เชื่อมต่อ LINE"
          value={metrics?.customers.lineConnected || 0}
          format="number"
          loading={loading}
        />
      </div>

      {/* Charts */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <ChartComponent
          data={generateMockChartData(7)}
          config={orderChartConfig}
          realTime={realTimeEnabled}
          loading={loading}
        />
        
        <ChartComponent
          data={generateMockChartData(7)}
          config={paymentChartConfig}
          realTime={realTimeEnabled}
          loading={loading}
        />
      </div>

      <div className="grid grid-cols-1">
        <ChartComponent
          data={generateMockChartData(30)}
          config={webhookChartConfig}
          realTime={realTimeEnabled}
          loading={loading}
        />
      </div>
    </div>
  );
}