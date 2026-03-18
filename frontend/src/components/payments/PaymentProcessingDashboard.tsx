'use client';

import React, { useState, useCallback } from 'react';
import { 
  PaymentSlipFilters, 
  SlipStatus, 
  DragDropFile,
  BulkProcessingProgress as BulkProgress,
} from '@/types/payments';
import { FileUploadZone } from './FileUploadZone';
import { PaymentSlipList } from './PaymentSlipList';
import { BulkProcessingProgress } from './BulkProcessingProgress';
import { PaymentStatistics } from './PaymentStatistics';
import { usePaymentSlips } from '@/hooks/usePaymentSlips';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Card } from '@/components/ui/Card';

export function PaymentProcessingDashboard() {
  const [filters, setFilters] = useState<PaymentSlipFilters>({
    page: 1,
    limit: 20,
  });
  const [bulkProgress, setBulkProgress] = useState<BulkProgress | null>(null);
  const [uploadMode, setUploadMode] = useState<'single' | 'bulk'>('single');
  const [selectedFiles, setSelectedFiles] = useState<DragDropFile[]>([]);

  // Use the payment slips hook
  const {
    slips,
    slipsMeta,
    statistics,
    isLoadingSlips,
    uploadSlip,
    bulkUpload,
    updateAmount,
    matchSlip,
    rejectSlip,
    deleteSlip,
    performAutoMatch,
    isUploading,
    isBulkUploading,
    isAutoMatching,
  } = usePaymentSlips(filters);

  // Handle file selection
  const handleFilesSelected = useCallback((files: DragDropFile[]) => {
    setSelectedFiles(files);
    
    if (uploadMode === 'single' && files.length > 0) {
      // Auto-upload single file
      uploadSlip({ file: files[0] });
    }
  }, [uploadMode, uploadSlip]);

  // Handle bulk upload
  const handleBulkUpload = useCallback(async () => {
    if (selectedFiles.length === 0) return;

    const initialProgress: BulkProgress = {
      totalFiles: selectedFiles.length,
      processedFiles: 0,
      successfulUploads: 0,
      failedUploads: 0,
      isProcessing: true,
      results: selectedFiles.map(file => ({
        filename: file.name,
        progress: 0,
        status: 'pending',
      })),
    };

    setBulkProgress(initialProgress);

    try {
      // Simulate bulk upload process
      bulkUpload(selectedFiles);
      
      // For now, simulate success
      setTimeout(() => {
        setBulkProgress(prev => prev ? {
          ...prev,
          processedFiles: prev.totalFiles,
          successfulUploads: prev.totalFiles,
          failedUploads: 0,
          isProcessing: false,
          results: prev.results.map(r => ({
            ...r,
            progress: 100,
            status: 'success',
          })),
        } : null);
        setSelectedFiles([]);
      }, 3000);
    } catch (error) {
      setBulkProgress(prev => prev ? {
        ...prev,
        isProcessing: false,
        failedUploads: prev.totalFiles,
        results: prev.results.map(r => ({
          ...r,
          status: 'error',
          error: error instanceof Error ? error.message : 'Upload failed',
        })),
      } : null);
    }
  }, [selectedFiles, bulkUpload]);

  // Handle filter changes
  const handleFilterChange = (newFilters: Partial<PaymentSlipFilters>) => {
    setFilters(prev => ({ ...prev, ...newFilters, page: 1 }));
  };

  // Handle pagination
  const handlePageChange = (page: number, pageSize: number) => {
    setFilters(prev => ({ ...prev, page, limit: pageSize }));
  };

  return (
    <div className="space-y-6">
      {/* Statistics */}
      {statistics && (
        <PaymentStatistics statistics={statistics} />
      )}

      {/* Upload Section */}
      <Card className="p-6">
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-medium text-gray-900">อัปโหลดใบเสร็จ</h2>
            <div className="flex items-center space-x-2">
              <Button
                variant={uploadMode === 'single' ? 'primary' : 'secondary'}
                size="sm"
                onClick={() => setUploadMode('single')}
              >
                อัปโหลดเดี่ยว
              </Button>
              <Button
                variant={uploadMode === 'bulk' ? 'primary' : 'secondary'}
                size="sm"
                onClick={() => setUploadMode('bulk')}
              >
                อัปโหลดกลุ่ม
              </Button>
            </div>
          </div>

          <FileUploadZone
            onFilesSelected={handleFilesSelected}
            maxFiles={uploadMode === 'single' ? 1 : 10}
            disabled={isUploading || isBulkUploading}
          />

          {/* Selected Files for Bulk Upload */}
          {uploadMode === 'bulk' && selectedFiles.length > 0 && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <p className="text-sm text-gray-600">
                  เลือกไฟล์แล้ว {selectedFiles.length} ไฟล์
                </p>
                <div className="flex items-center space-x-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => setSelectedFiles([])}
                  >
                    ล้างทั้งหมด
                  </Button>
                  <Button
                    size="sm"
                    onClick={handleBulkUpload}
                    disabled={isBulkUploading}
                  >
                    {isBulkUploading ? 'กำลังอัปโหลด...' : 'อัปโหลดทั้งหมด'}
                  </Button>
                </div>
              </div>

              <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                {selectedFiles.map((file) => (
                  <div key={file.id} className="relative">
                    <div className="aspect-square bg-gray-100 rounded-lg overflow-hidden">
                      {file.preview && (
                        <img
                          src={file.preview}
                          alt={file.name}
                          className="w-full h-full object-cover"
                        />
                      )}
                    </div>
                    <p className="text-xs text-gray-600 mt-1 truncate" title={file.name}>
                      {file.name}
                    </p>
                    <button
                      onClick={() => {
                        setSelectedFiles(prev => prev.filter(f => f.id !== file.id));
                        if (file.preview) {
                          URL.revokeObjectURL(file.preview);
                        }
                      }}
                      className="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xs hover:bg-red-600"
                    >
                      ×
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </Card>

      {/* Bulk Processing Progress */}
      {bulkProgress && (
        <BulkProcessingProgress
          progress={bulkProgress}
          onCancel={() => setBulkProgress(null)}
        />
      )}

      {/* Filters and Actions */}
      <Card className="p-4">
        <div className="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
          <div className="flex items-center space-x-4">
            <div className="flex items-center space-x-2">
              <label className="text-sm font-medium text-gray-700">สถานะ:</label>
              <select
                value={filters.status || ''}
                onChange={(e) => handleFilterChange({ 
                  status: e.target.value as SlipStatus || undefined 
                })}
                className="px-3 py-1 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">ทั้งหมด</option>
                <option value="PENDING">รอดำเนินการ</option>
                <option value="MATCHED">จับคู่แล้ว</option>
                <option value="REJECTED">ปฏิเสธ</option>
                <option value="PROCESSING">กำลังประมวลผล</option>
              </select>
            </div>

            <div className="flex items-center space-x-2">
              <label className="text-sm font-medium text-gray-700">ค้นหา:</label>
              <Input
                type="text"
                placeholder="ค้นหา..."
                value={filters.search || ''}
                onChange={(e) => handleFilterChange({ search: e.target.value || undefined })}
                className="w-48"
              />
            </div>
          </div>

          <div className="flex items-center space-x-2">
            <Button
              size="sm"
              onClick={() => performAutoMatch()}
              disabled={isAutoMatching}
            >
              {isAutoMatching ? 'กำลังจับคู่...' : 'จับคู่อัตโนมัติ'}
            </Button>
          </div>
        </div>
      </Card>

      {/* Payment Slips List */}
      <Card>
        <PaymentSlipList
          slips={slips}
          loading={isLoadingSlips}
          pagination={slipsMeta ? {
            current: slipsMeta.page,
            pageSize: slipsMeta.limit,
            total: slipsMeta.total,
            onChange: handlePageChange,
          } : undefined}
          onAmountUpdate={(slipId, amount) => updateAmount({ slipId, amount })}
          onMatch={(slipId, orderId) => matchSlip({ slipId, orderId })}
          onReject={(slipId, reason) => rejectSlip({ slipId, reason })}
          onDelete={(slipId) => deleteSlip(slipId)}
        />
      </Card>
    </div>
  );
}