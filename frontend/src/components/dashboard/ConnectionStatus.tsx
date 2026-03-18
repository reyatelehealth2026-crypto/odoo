'use client';

import React from 'react';
import { cn } from '@/lib/utils';

export interface ConnectionStatusProps {
  connected: boolean;
  connecting: boolean;
  error: string | null;
  reconnectAttempt: number;
  lastUpdate: Date | null;
  className?: string;
}

export function ConnectionStatus({
  connected,
  connecting,
  error,
  reconnectAttempt,
  lastUpdate,
  className,
}: ConnectionStatusProps) {
  const getStatusColor = () => {
    if (error) return 'text-red-600 bg-red-50';
    if (connecting || reconnectAttempt > 0) return 'text-yellow-600 bg-yellow-50';
    if (connected) return 'text-green-600 bg-green-50';
    return 'text-gray-600 bg-gray-50';
  };

  const getStatusText = () => {
    if (error) return 'เชื่อมต่อล้มเหลว';
    if (reconnectAttempt > 0) return `กำลังเชื่อมต่อใหม่... (ครั้งที่ ${reconnectAttempt})`;
    if (connecting) return 'กำลังเชื่อมต่อ...';
    if (connected) return 'เชื่อมต่อแล้ว';
    return 'ไม่ได้เชื่อมต่อ';
  };

  const getStatusIcon = () => {
    if (error) {
      return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        </svg>
      );
    }
    
    if (connecting || reconnectAttempt > 0) {
      return (
        <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
        </svg>
      );
    }
    
    if (connected) {
      return (
        <div className="h-2 w-2 rounded-full bg-current animate-pulse"></div>
      );
    }
    
    return (
      <div className="h-2 w-2 rounded-full bg-current"></div>
    );
  };

  const formatLastUpdate = (date: Date) => {
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffSeconds = Math.floor(diffMs / 1000);
    const diffMinutes = Math.floor(diffSeconds / 60);
    
    if (diffSeconds < 60) {
      return `${diffSeconds} วินาทีที่แล้ว`;
    } else if (diffMinutes < 60) {
      return `${diffMinutes} นาทีที่แล้ว`;
    } else {
      return date.toLocaleString('th-TH');
    }
  };

  return (
    <div className={cn('flex items-center space-x-2 rounded-lg px-3 py-2 text-sm', getStatusColor(), className)}>
      {getStatusIcon()}
      <span className="font-medium">{getStatusText()}</span>
      
      {error && (
        <div className="ml-2 text-xs text-red-500" title={error}>
          ({error.length > 30 ? error.substring(0, 30) + '...' : error})
        </div>
      )}
      
      {connected && lastUpdate && (
        <div className="ml-2 text-xs opacity-75">
          อัปเดตล่าสุด: {formatLastUpdate(lastUpdate)}
        </div>
      )}
    </div>
  );
}