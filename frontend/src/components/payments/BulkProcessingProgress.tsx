'use client';

import React from 'react';
import { BulkProcessingProgress as BulkProgress, FileUploadProgress } from '@/types/payments';
import { Button } from '@/components/ui/Button';

interface BulkProcessingProgressProps {
  progress: BulkProgress;
  onCancel?: () => void;
  onRetry?: (fileId: string) => void;
  className?: string;
}

export function BulkProcessingProgress({
  progress,
  onCancel,
  onRetry,
  className = '',
}: BulkProcessingProgressProps) {
  const getStatusIcon = (status: FileUploadProgress['status']) => {
    switch (status) {
      case 'pending':
        return (
          <div className="w-5 h-5 rounded-full border-2 border-gray-300 flex items-center justify-center">
            <div className="w-2 h-2 rounded-full bg-gray-300"></div>
          </div>
        );
      case 'uploading':
      case 'processing':
        return (
          <div className="w-5 h-5">
            <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600"></div>
          </div>
        );
      case 'success':
        return (
          <div className="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center">
            <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
          </div>
        );
      case 'error':
        return (
          <div className="w-5 h-5 rounded-full bg-red-500 flex items-center justify-center">
            <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
        );
      default:
        return null;
    }
  };

  const getStatusText = (status: FileUploadProgress['status']) => {
    switch (status) {
      case 'pending':
        return 'รอดำเนินการ';
      case 'uploading':
        return 'กำลังอัปโหลด';
      case 'processing':
        return 'กำลังประมวลผล';
      case 'success':
        return 'สำเร็จ';
      case 'error':
        return 'ผิดพลาด';
      default:
        return '';
    }
  };

  const getStatusColor = (status: FileUploadProgress['status']) => {
    switch (status) {
      case 'pending':
        return 'text-gray-600';
      case 'uploading':
      case 'processing':
        return 'text-blue-600';
      case 'success':
        return 'text-green-600';
      case 'error':
        return 'text-red-600';
      default:
        return 'text-gray-600';
    }
  };

  const overallProgress = progress.totalFiles > 0 
    ? (progress.processedFiles / progress.totalFiles) * 100 
    : 0;

  return (
    <div className={`bg-white rounded-lg shadow-md ${className}`}>
      {/* Header */}
      <div className="p-4 border-b border-gray-200">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-lg font-medium text-gray-900">
              การอัปโหลดแบบกลุ่ม
            </h3>
            <p className="text-sm text-gray-600">
              {progress.processedFiles} จาก {progress.totalFiles} ไฟล์
            </p>
          </div>
          {progress.isProcessing && onCancel && (
            <Button
              variant="secondary"
              size="sm"
              onClick={onCancel}
              className="text-red-600 hover:text-red-700"
            >
              ยกเลิก
            </Button>
          )}
        </div>
      </div>

      {/* Overall Progress */}
      <div className="p-4 border-b border-gray-200">
        <div className="flex items-center justify-between mb-2">
          <span className="text-sm font-medium text-gray-700">ความคืบหน้าโดยรวม</span>
          <span className="text-sm text-gray-600">{Math.round(overallProgress)}%</span>
        </div>
        <div className="w-full bg-gray-200 rounded-full h-2">
          <div
            className="bg-blue-600 h-2 rounded-full transition-all duration-300"
            style={{ width: `${overallProgress}%` }}
          ></div>
        </div>
      </div>

      {/* Statistics */}
      <div className="p-4 border-b border-gray-200">
        <div className="grid grid-cols-3 gap-4 text-center">
          <div>
            <div className="text-2xl font-bold text-green-600">
              {progress.successfulUploads}
            </div>
            <div className="text-sm text-gray-600">สำเร็จ</div>
          </div>
          <div>
            <div className="text-2xl font-bold text-red-600">
              {progress.failedUploads}
            </div>
            <div className="text-sm text-gray-600">ผิดพลาด</div>
          </div>
          <div>
            <div className="text-2xl font-bold text-gray-600">
              {progress.totalFiles - progress.processedFiles}
            </div>
            <div className="text-sm text-gray-600">คงเหลือ</div>
          </div>
        </div>
      </div>

      {/* Current File */}
      {progress.currentFile && progress.isProcessing && (
        <div className="p-4 border-b border-gray-200 bg-blue-50">
          <div className="flex items-center space-x-3">
            <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600"></div>
            <div className="flex-1">
              <p className="text-sm font-medium text-blue-900">
                กำลังประมวลผล: {progress.currentFile}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* File List */}
      <div className="max-h-96 overflow-y-auto">
        {progress.results.map((file, index) => (
          <div
            key={`${file.filename}-${index}`}
            className="p-4 border-b border-gray-100 last:border-b-0"
          >
            <div className="flex items-center space-x-3">
              {/* Status Icon */}
              {getStatusIcon(file.status)}

              {/* File Info */}
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 truncate">
                  {file.filename}
                </p>
                <div className="flex items-center space-x-2 mt-1">
                  <span className={`text-xs ${getStatusColor(file.status)}`}>
                    {getStatusText(file.status)}
                  </span>
                  {file.status === 'uploading' && (
                    <div className="flex-1 max-w-32">
                      <div className="w-full bg-gray-200 rounded-full h-1">
                        <div
                          className="bg-blue-600 h-1 rounded-full transition-all duration-300"
                          style={{ width: `${file.progress}%` }}
                        ></div>
                      </div>
                    </div>
                  )}
                </div>
                {file.error && (
                  <p className="text-xs text-red-600 mt-1">{file.error}</p>
                )}
              </div>

              {/* Actions */}
              <div className="flex items-center space-x-2">
                {file.status === 'success' && file.slipId && (
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                      // Navigate to slip detail or show success message
                      console.log('View slip:', file.slipId);
                    }}
                  >
                    ดู
                  </Button>
                )}
                {file.status === 'error' && onRetry && (
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => onRetry(file.filename)}
                  >
                    ลองใหม่
                  </Button>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Footer */}
      {!progress.isProcessing && progress.processedFiles === progress.totalFiles && (
        <div className="p-4 bg-gray-50 rounded-b-lg">
          <div className="flex items-center justify-between">
            <div className="text-sm text-gray-600">
              การอัปโหลดเสร็จสิ้น - {progress.successfulUploads} สำเร็จ, {progress.failedUploads} ผิดพลาด
            </div>
            <Button
              size="sm"
              onClick={() => {
                // Close or reset the progress
                window.location.reload();
              }}
            >
              เสร็จสิ้น
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}