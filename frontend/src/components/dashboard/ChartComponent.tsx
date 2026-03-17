'use client';

import React from 'react';
import { cn } from '@/lib/utils';

export interface ChartData {
  date: string;
  value: number;
  count?: number;
  success?: number;
  failed?: number;
  total?: number;
}

export interface ChartConfig {
  title: string;
  type: 'line' | 'bar' | 'pie';
  color?: string;
  height?: number;
}

export interface ChartProps {
  data: ChartData[];
  config: ChartConfig;
  realTime?: boolean;
  loading?: boolean;
  className?: string;
}

export function ChartComponent({
  data,
  config,
  realTime = false,
  loading = false,
  className,
}: ChartProps) {
  const { title, type, color = '#3B82F6', height = 300 } = config;

  // Simple chart implementation using SVG
  const renderLineChart = () => {
    if (!data.length) return null;

    const maxValue = Math.max(...data.map(d => d.value));
    const minValue = Math.min(...data.map(d => d.value));
    const range = maxValue - minValue || 1;
    
    const width = 400;
    const chartHeight = height - 60; // Leave space for labels
    
    const points = data.map((d, i) => {
      const x = (i / (data.length - 1)) * width;
      const y = chartHeight - ((d.value - minValue) / range) * chartHeight;
      return `${x},${y}`;
    }).join(' ');

    return (
      <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} className="overflow-visible">
        {/* Grid lines */}
        {[0, 0.25, 0.5, 0.75, 1].map(ratio => (
          <line
            key={ratio}
            x1="0"
            y1={chartHeight * ratio}
            x2={width}
            y2={chartHeight * ratio}
            stroke="#E5E7EB"
            strokeWidth="1"
          />
        ))}
        
        {/* Chart line */}
        <polyline
          fill="none"
          stroke={color}
          strokeWidth="2"
          points={points}
        />
        
        {/* Data points */}
        {data.map((d, i) => {
          const x = (i / (data.length - 1)) * width;
          const y = chartHeight - ((d.value - minValue) / range) * chartHeight;
          return (
            <circle
              key={i}
              cx={x}
              cy={y}
              r="4"
              fill={color}
              className="hover:r-6 transition-all cursor-pointer"
            >
              <title>{`${d.date}: ${d.value.toLocaleString('th-TH')}`}</title>
            </circle>
          );
        })}
        
        {/* X-axis labels */}
        {data.map((d, i) => {
          if (i % Math.ceil(data.length / 5) !== 0) return null; // Show every 5th label
          const x = (i / (data.length - 1)) * width;
          return (
            <text
              key={i}
              x={x}
              y={height - 10}
              textAnchor="middle"
              className="text-xs fill-gray-500"
            >
              {new Date(d.date).toLocaleDateString('th-TH', { month: 'short', day: 'numeric' })}
            </text>
          );
        })}
      </svg>
    );
  };

  const renderBarChart = () => {
    if (!data.length) return null;

    const maxValue = Math.max(...data.map(d => d.value));
    const width = 400;
    const chartHeight = height - 60;
    const barWidth = width / data.length * 0.8;
    const barSpacing = width / data.length * 0.2;

    return (
      <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} className="overflow-visible">
        {/* Grid lines */}
        {[0, 0.25, 0.5, 0.75, 1].map(ratio => (
          <line
            key={ratio}
            x1="0"
            y1={chartHeight * ratio}
            x2={width}
            y2={chartHeight * ratio}
            stroke="#E5E7EB"
            strokeWidth="1"
          />
        ))}
        
        {/* Bars */}
        {data.map((d, i) => {
          const x = i * (barWidth + barSpacing) + barSpacing / 2;
          const barHeight = (d.value / maxValue) * chartHeight;
          const y = chartHeight - barHeight;
          
          return (
            <rect
              key={i}
              x={x}
              y={y}
              width={barWidth}
              height={barHeight}
              fill={color}
              className="hover:opacity-80 transition-opacity cursor-pointer"
            >
              <title>{`${d.date}: ${d.value.toLocaleString('th-TH')}`}</title>
            </rect>
          );
        })}
        
        {/* X-axis labels */}
        {data.map((d, i) => {
          if (i % Math.ceil(data.length / 5) !== 0) return null;
          const x = i * (barWidth + barSpacing) + barSpacing / 2 + barWidth / 2;
          return (
            <text
              key={i}
              x={x}
              y={height - 10}
              textAnchor="middle"
              className="text-xs fill-gray-500"
            >
              {new Date(d.date).toLocaleDateString('th-TH', { month: 'short', day: 'numeric' })}
            </text>
          );
        })}
      </svg>
    );
  };

  const renderPieChart = () => {
    if (!data.length) return null;

    const total = data.reduce((sum, d) => sum + d.value, 0);
    const radius = Math.min(height, 300) / 2 - 20;
    const centerX = 200;
    const centerY = height / 2;
    
    let currentAngle = 0;
    const colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6'];

    return (
      <svg width="100%" height={height} viewBox={`0 0 400 ${height}`} className="overflow-visible">
        {data.map((d, i) => {
          const percentage = d.value / total;
          const angle = percentage * 2 * Math.PI;
          
          const x1 = centerX + radius * Math.cos(currentAngle);
          const y1 = centerY + radius * Math.sin(currentAngle);
          const x2 = centerX + radius * Math.cos(currentAngle + angle);
          const y2 = centerY + radius * Math.sin(currentAngle + angle);
          
          const largeArcFlag = angle > Math.PI ? 1 : 0;
          
          const pathData = [
            `M ${centerX} ${centerY}`,
            `L ${x1} ${y1}`,
            `A ${radius} ${radius} 0 ${largeArcFlag} 1 ${x2} ${y2}`,
            'Z'
          ].join(' ');
          
          currentAngle += angle;
          
          return (
            <path
              key={i}
              d={pathData}
              fill={colors[i % colors.length]}
              className="hover:opacity-80 transition-opacity cursor-pointer"
            >
              <title>{`${d.date}: ${d.value.toLocaleString('th-TH')} (${(percentage * 100).toFixed(1)}%)`}</title>
            </path>
          );
        })}
      </svg>
    );
  };

  const renderChart = () => {
    switch (type) {
      case 'line':
        return renderLineChart();
      case 'bar':
        return renderBarChart();
      case 'pie':
        return renderPieChart();
      default:
        return renderLineChart();
    }
  };

  if (loading) {
    return (
      <div className={cn('rounded-lg border border-gray-200 bg-white p-6 shadow-sm', className)}>
        <div className="animate-pulse">
          <div className="mb-4 h-6 w-32 rounded bg-gray-200"></div>
          <div className="h-64 w-full rounded bg-gray-200"></div>
        </div>
      </div>
    );
  }

  return (
    <div className={cn('rounded-lg border border-gray-200 bg-white p-6 shadow-sm', className)}>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
        {realTime && (
          <div className="flex items-center text-sm text-green-600">
            <div className="mr-2 h-2 w-2 rounded-full bg-green-500 animate-pulse"></div>
            อัปเดตแบบเรียลไทม์
          </div>
        )}
      </div>
      
      <div className="w-full overflow-hidden">
        {data.length > 0 ? (
          renderChart()
        ) : (
          <div className="flex h-64 items-center justify-center text-gray-500">
            ไม่มีข้อมูลสำหรับแสดงผล
          </div>
        )}
      </div>
    </div>
  );
}