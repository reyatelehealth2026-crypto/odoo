'use client';

import React, { useState } from 'react';
import { DashboardOverview, DashboardMetrics } from '@/components/dashboard/DashboardOverview';
import { DateRange } from '@/components/dashboard/DateRangeFilter';

// Mock data for testing
const mockMetrics: DashboardMetrics = {
  orders: {
    todayCount: 25,
    todayTotal: 15750,
    pendingCount: 8,
    completedCount: 17,
    averageOrderValue: 630,
  },
  payments: {
    pendingSlips: 3,
    processedToday: 22,
    matchingRate: 95,
    totalAmount: 14200,
    averageProcessingTime: 12,
  },
  webhooks: {
    todayCount: 156,
    successRate: 98,
    failedCount: 3,
    averageResponseTime: 245,
  },
  customers: {
    totalActive: 1247,
    newToday: 8,
    lineConnected: 1089,
    averageOrdersPerCustomer: 2.1,
  },
  updatedAt: new Date().toISOString(),
};

export default function RealtimeTestPage() {
  const [dateRange, setDateRange] = useState<DateRange>({
    from: new Date(Date.now() - 7 * 24 * 60 * 60 * 1000), // 7 days ago
    to: new Date(),
  });

  // Mock auth token - in real app, this would come from auth context
  const mockAuthToken = 'mock-jwt-token-for-testing';

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="mx-auto max-w-7xl">
        <div className="mb-6">
          <h1 className="text-3xl font-bold text-gray-900">Real-time Dashboard Test</h1>
          <p className="mt-2 text-gray-600">
            ทดสอบการทำงานของระบบอัปเดตแบบเรียลไทม์สำหรับแดชบอร์ด Odoo
          </p>
        </div>

        <div className="mb-6 rounded-lg bg-blue-50 p-4">
          <h2 className="text-lg font-semibold text-blue-900 mb-2">วิธีการทดสอบ</h2>
          <div className="text-sm text-blue-800 space-y-1">
            <p>1. เปิด WebSocket server: <code className="bg-blue-100 px-2 py-1 rounded">node websocket-dashboard-server.js</code></p>
            <p>2. ทดสอบการส่งข้อมูล: เข้า <code className="bg-blue-100 px-2 py-1 rounded">api/dashboard-realtime-demo.php?action=trigger_metrics_update</code></p>
            <p>3. ดูการอัปเดตแบบเรียลไทม์ในแดชบอร์ดด้านล่าง</p>
          </div>
        </div>

        <DashboardOverview
          metrics={mockMetrics}
          dateRange={dateRange}
          onDateRangeChange={setDateRange}
          loading={false}
          realTimeEnabled={true}
          authToken={mockAuthToken}
        />

        <div className="mt-8 rounded-lg bg-white p-6 shadow">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">การทดสอบ API</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <TestButton
              title="อัปเดตเมตริก"
              endpoint="/api/dashboard-realtime-demo.php?action=trigger_metrics_update"
              description="ทดสอบการอัปเดตข้อมูลเมตริกแดชบอร์ด"
            />
            <TestButton
              title="เปลี่ยนสถานะออเดอร์"
              endpoint="/api/dashboard-realtime-demo.php?action=trigger_order_update&order_id=TEST123&old_status=draft&new_status=sale"
              description="ทดสอบการเปลี่ยนสถานะออเดอร์"
            />
            <TestButton
              title="ประมวลผลการชำระเงิน"
              endpoint="/api/dashboard-realtime-demo.php?action=trigger_payment_update&payment_id=PAY123&amount=1500&status=matched"
              description="ทดสอบการประมวลผลการชำระเงิน"
            />
            <TestButton
              title="รับ Webhook"
              endpoint="/api/dashboard-realtime-demo.php?action=trigger_webhook_update&webhook_id=WH123&type=order.update&status=success"
              description="ทดสอบการรับ Webhook"
            />
          </div>
        </div>
      </div>
    </div>
  );
}

interface TestButtonProps {
  title: string;
  endpoint: string;
  description: string;
}

function TestButton({ title, endpoint, description }: TestButtonProps) {
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<string | null>(null);

  const handleTest = async () => {
    setLoading(true);
    setResult(null);

    try {
      const response = await fetch(endpoint);
      const data = await response.json();
      setResult(data.success ? 'สำเร็จ' : 'ล้มเหลว: ' + (data.error || 'Unknown error'));
    } catch (error) {
      setResult('ข้อผิดพลาด: ' + String(error));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="rounded-lg border border-gray-200 p-4">
      <h3 className="font-medium text-gray-900 mb-2">{title}</h3>
      <p className="text-sm text-gray-600 mb-3">{description}</p>
      <button
        onClick={handleTest}
        disabled={loading}
        className="w-full rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 disabled:cursor-not-allowed"
      >
        {loading ? 'กำลังทดสอบ...' : 'ทดสอบ'}
      </button>
      {result && (
        <div className={`mt-2 text-xs p-2 rounded ${
          result.includes('สำเร็จ') ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
        }`}>
          {result}
        </div>
      )}
    </div>
  );
}