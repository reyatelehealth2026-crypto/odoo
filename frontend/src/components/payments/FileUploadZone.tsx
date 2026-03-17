'use client';

import React, { useCallback, useState } from 'react';
import { useDropzone } from 'react-dropzone';
import { DragDropFile, FileUploadProgress } from '@/types/payments';

interface FileUploadZoneProps {
  onFilesSelected: (files: DragDropFile[]) => void;
  maxFiles?: number;
  maxSize?: number; // in bytes
  accept?: Record<string, string[]>;
  disabled?: boolean;
  className?: string;
}

export function FileUploadZone({
  onFilesSelected,
  maxFiles = 10,
  maxSize = 10 * 1024 * 1024, // 10MB
  accept = {
    'image/jpeg': ['.jpg', '.jpeg'],
    'image/png': ['.png'],
    'image/webp': ['.webp'],
  },
  disabled = false,
  className = '',
}: FileUploadZoneProps) {
  const [dragActive, setDragActive] = useState(false);

  const onDrop = useCallback(
    (acceptedFiles: File[], rejectedFiles: any[]) => {
      // Create DragDropFile objects with unique IDs and previews
      const processedFiles: DragDropFile[] = acceptedFiles.map((file) => {
        const dragDropFile = file as DragDropFile;
        dragDropFile.id = `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        
        // Create preview URL for images
        if (file.type.startsWith('image/')) {
          dragDropFile.preview = URL.createObjectURL(file);
        }
        
        return dragDropFile;
      });

      onFilesSelected(processedFiles);

      // Handle rejected files
      if (rejectedFiles.length > 0) {
        const errors = rejectedFiles.map((rejected) => {
          const errors = rejected.errors.map((error: any) => error.message).join(', ');
          return `${rejected.file.name}: ${errors}`;
        });
        
        // You might want to show these errors in a toast or alert
        console.warn('Rejected files:', errors);
      }
    },
    [onFilesSelected]
  );

  const {
    getRootProps,
    getInputProps,
    isDragActive,
    isDragAccept,
    isDragReject,
  } = useDropzone({
    onDrop,
    accept,
    maxFiles,
    maxSize,
    disabled,
    onDragEnter: () => setDragActive(true),
    onDragLeave: () => setDragActive(false),
  });

  const getBorderColor = () => {
    if (isDragReject) return 'border-red-400 bg-red-50';
    if (isDragAccept) return 'border-green-400 bg-green-50';
    if (isDragActive) return 'border-blue-400 bg-blue-50';
    return 'border-gray-300 hover:border-gray-400';
  };

  const getTextColor = () => {
    if (isDragReject) return 'text-red-600';
    if (isDragAccept) return 'text-green-600';
    if (isDragActive) return 'text-blue-600';
    return 'text-gray-600';
  };

  return (
    <div
      {...getRootProps()}
      className={`
        relative border-2 border-dashed rounded-lg p-8 text-center cursor-pointer
        transition-all duration-200 ease-in-out
        ${getBorderColor()}
        ${disabled ? 'opacity-50 cursor-not-allowed' : ''}
        ${className}
      `}
    >
      <input {...getInputProps()} />
      
      <div className="space-y-4">
        {/* Upload Icon */}
        <div className="mx-auto w-16 h-16 flex items-center justify-center">
          {isDragActive ? (
            <svg
              className={`w-12 h-12 ${getTextColor()}`}
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"
              />
            </svg>
          ) : (
            <svg
              className={`w-12 h-12 ${getTextColor()}`}
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
              />
            </svg>
          )}
        </div>

        {/* Upload Text */}
        <div>
          <p className={`text-lg font-medium ${getTextColor()}`}>
            {isDragActive
              ? isDragAccept
                ? 'วางไฟล์ที่นี่...'
                : 'ไฟล์ไม่ถูกต้อง'
              : 'ลากและวางไฟล์ใบเสร็จที่นี่'}
          </p>
          <p className="text-sm text-gray-500 mt-1">
            หรือคลิกเพื่อเลือกไฟล์
          </p>
        </div>

        {/* File Requirements */}
        <div className="text-xs text-gray-400 space-y-1">
          <p>รองรับไฟล์: JPG, PNG, WebP</p>
          <p>ขนาดไฟล์สูงสุด: {Math.round(maxSize / 1024 / 1024)}MB</p>
          <p>จำนวนไฟล์สูงสุด: {maxFiles} ไฟล์</p>
        </div>
      </div>

      {/* Loading Overlay */}
      {disabled && (
        <div className="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center rounded-lg">
          <div className="flex items-center space-x-2">
            <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
            <span className="text-sm text-gray-600">กำลังประมวลผล...</span>
          </div>
        </div>
      )}
    </div>
  );
}