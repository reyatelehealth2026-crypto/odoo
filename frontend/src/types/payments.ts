// Payment processing types
export interface PaymentSlip {
  id: string;
  imageUrl: string;
  amount: number | null;
  status: SlipStatus;
  uploadedBy: string;
  matchedOrderId: string | null;
  processedAt: Date | null;
  createdAt: Date;
  notes: string | null;
  matchedOrder?: {
    id: string;
    odooOrderId: string;
    customerRef: string | null;
    customerName: string | null;
    totalAmount: number;
    status: string;
    orderDate: Date | null;
  };
}

export enum SlipStatus {
  PENDING = 'PENDING',
  MATCHED = 'MATCHED',
  REJECTED = 'REJECTED',
  PROCESSING = 'PROCESSING',
}

export interface PotentialMatch {
  orderId: string;
  amount: number;
  confidence: number;
}

export interface UploadResult {
  success: boolean;
  message: string;
  slipId?: string;
  imageUrl?: string;
  potentialMatches?: PotentialMatch[];
}

export interface BulkUploadResult {
  totalFiles: number;
  successfulUploads: number;
  failedUploads: number;
  results: Array<{
    filename: string;
    success: boolean;
    slipId?: string;
    error?: string;
  }>;
}

export interface PaymentSlipFilters {
  status?: SlipStatus;
  dateFrom?: Date;
  dateTo?: Date;
  search?: string;
  page?: number;
  limit?: number;
}

export interface PaymentStatistics {
  totalSlips: number;
  matchedSlips: number;
  pendingSlips: number;
  rejectedSlips: number;
  matchingRate: number;
  averageProcessingTime: number;
}

export interface AutoMatchingResult {
  totalProcessed: number;
  successfulMatches: number;
  failedMatches: number;
  ambiguousMatches: number;
  matches: Array<{
    slipId: string;
    orderId: string;
    confidence: number;
  }>;
}

// File upload types
export interface FileUploadProgress {
  filename: string;
  progress: number;
  status: 'pending' | 'uploading' | 'processing' | 'success' | 'error';
  error?: string;
  slipId?: string;
}

export interface DragDropFile extends File {
  id: string;
  preview?: string;
}

// Bulk processing types
export interface BulkProcessingProgress {
  totalFiles: number;
  processedFiles: number;
  successfulUploads: number;
  failedUploads: number;
  currentFile?: string;
  isProcessing: boolean;
  results: FileUploadProgress[];
}