'use client';

import React from 'react';
import { PaymentStatistics as PaymentStats } from '@/types/payments';
import { Card } from '@/components/ui/Card';

interface PaymentStatisticsProps {
  statistics: PaymentStats;
  className?: string;
}

export function PaymentStatistics({ statistics, className = '' }: PaymentStatisticsProps) {
  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('th-TH', {
      style: 'currency',
      currency: 'THB',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(amount);
  };

  const formatTime = (minutes: number) => {
    if (minutes < 60) {
      return `${minutes} นาที`;
    }
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    return `${hours} ชม. ${remainingMinutes} นาที`;
  };

  const getMatchingRateColor = (rate: number) => {
    if (rate >= 80) return 'text-green-600';
    if (rate >= 60) return 'text-yellow-600';
    return 'text-red-600';
  };

  const getMatchingRateBgColor = (rate: number) => {
    if (rate >= 80) return 'bg-green-100';
    if (rate >= 60) return 'bg-yellow-100';
    return 'bg-red-100';
  };

  const stats = [
    {
      title: 'ใบเสร็จทั้งหมด',
      value: statistics.totalSlips.toLocaleString(),
      icon: (
        <svg className="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
      ),
      bgColor: 'bg-blue-100',
      textColor: 'text-blue-600',
    },
    {
      title: 'จับคู่แล้ว',
      value: statistics.matchedSlips.toLocaleString(),
      icon: (
        <svg className="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
        </svg>
      ),
      bgColor: 'bg-green-100',
      textColor: 'text-green-600',
    },
    {
      title: 'รอดำเนินการ',
      value: statistics.pendingSlips.toLocaleString(),
      icon: (
        <svg className="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      ),
      bgColor: 'bg-yellow-100',
      textColor: 'text-yellow-600',
    },
    {
      title: 'ปฏิเสธ',
      value: statistics.rejectedSlips.toLocaleString(),
      icon: (
        <svg className="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
        </svg>
      ),
      bgColor: 'bg-red-100',
      textColor: 'text-red-600',
    },
  ];

  return (
    <div className={`space-y-6 ${className}`}>
      {/* Main Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {stats.map((stat, index) => (
          <Card key={index} className="p-4">
            <div className="flex items-center">
              <div className="flex-1">
                <p className="text-sm font-medium text-gray-600">{stat.title}</p>
                <p className="text-2xl font-bold text-gray-900">{stat.value}</p>
              </div>
              <div className={`w-12 h-12 ${stat.bgColor} rounded-full flex items-center justify-center`}>
                {stat.icon}
              </div>
            </div>
          </Card>
        ))}
      </div>

      {/* Performance Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Matching Rate */}
        <Card className="p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-medium text-gray-900">อัตราการจับคู่</h3>
            <div className={`px-3 py-1 rounded-full text-sm font-medium ${getMatchingRateBgColor(statistics.matchingRate)} ${getMatchingRateColor(statistics.matchingRate)}`}>
              {statistics.matchingRate.toFixed(1)}%
            </div>
          </div>
          
          <div className="space-y-3">
            {/* Progress Bar */}
            <div className="w-full bg-gray-200 rounded-full h-3">
              <div
                className={`h-3 rounded-full transition-all duration-500 ${
                  statistics.matchingRate >= 80 ? 'bg-green-500' :
                  statistics.matchingRate >= 60 ? 'bg-yellow-500' : 'bg-red-500'
                }`}
                style={{ width: `${Math.min(statistics.matchingRate, 100)}%` }}
              ></div>
            </div>
            
            {/* Breakdown */}
            <div className="grid grid-cols-3 gap-4 text-center text-sm">
              <div>
                <div className="font-medium text-green-600">
                  {statistics.matchedSlips}
                </div>
                <div className="text-gray-500">จับคู่แล้ว</div>
              </div>
              <div>
                <div className="font-medium text-yellow-600">
                  {statistics.pendingSlips}
                </div>
                <div className="text-gray-500">รอดำเนินการ</div>
              </div>
              <div>
                <div className="font-medium text-red-600">
                  {statistics.rejectedSlips}
                </div>
                <div className="text-gray-500">ปฏิเสธ</div>
              </div>
            </div>
          </div>
        </Card>

        {/* Processing Time */}
        <Card className="p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="text-lg font-medium text-gray-900">เวลาประมวลผลเฉลี่ย</h3>
            <div className="flex items-center text-blue-600">
              <svg className="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span className="text-lg font-semibold">
                {formatTime(statistics.averageProcessingTime)}
              </span>
            </div>
          </div>

          <div className="space-y-3">
            <div className="text-sm text-gray-600">
              เวลาเฉลี่ยในการประมวลผลใบเสร็จตั้งแต่อัปโหลดจนถึงจับคู่เสร็จสิ้น
            </div>
            
            {/* Time Categories */}
            <div className="grid grid-cols-3 gap-2 text-xs">
              <div className="text-center p-2 bg-green-50 rounded">
                <div className="font-medium text-green-700">เร็ว</div>
                <div className="text-green-600">&lt; 30 นาที</div>
              </div>
              <div className="text-center p-2 bg-yellow-50 rounded">
                <div className="font-medium text-yellow-700">ปานกลาง</div>
                <div className="text-yellow-600">30-120 นาที</div>
              </div>
              <div className="text-center p-2 bg-red-50 rounded">
                <div className="font-medium text-red-700">ช้า</div>
                <div className="text-red-600">&gt; 120 นาที</div>
              </div>
            </div>
          </div>
        </Card>
      </div>

      {/* Summary Insights */}
      <Card className="p-6">
        <h3 className="text-lg font-medium text-gray-900 mb-4">สรุปข้อมูลเชิงลึก</h3>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="text-center">
            <div className="text-2xl font-bold text-blue-600 mb-2">
              {statistics.totalSlips > 0 ? 
                ((statistics.matchedSlips / statistics.totalSlips) * 100).toFixed(1) : 0}%
            </div>
            <div className="text-sm text-gray-600">
              ประสิทธิภาพการจับคู่โดยรวม
            </div>
          </div>
          
          <div className="text-center">
            <div className="text-2xl font-bold text-green-600 mb-2">
              {statistics.pendingSlips === 0 ? '✓' : statistics.pendingSlips}
            </div>
            <div className="text-sm text-gray-600">
              {statistics.pendingSlips === 0 ? 'ไม่มีงานค้างคา' : 'งานค้างการประมวลผล'}
            </div>
          </div>
          
          <div className="text-center">
            <div className="text-2xl font-bold text-purple-600 mb-2">
              {statistics.averageProcessingTime < 60 ? 'เร็ว' : 
               statistics.averageProcessingTime < 120 ? 'ปกติ' : 'ช้า'}
            </div>
            <div className="text-sm text-gray-600">
              ความเร็วในการประมวลผล
            </div>
          </div>
        </div>
      </Card>
    </div>
  );
}