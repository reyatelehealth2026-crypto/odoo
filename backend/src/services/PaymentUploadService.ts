import { PrismaClient, SlipStatus } from '@prisma/client';
import { BaseService } from './BaseService';
import { promises as fs } from 'fs';
import path from 'path';
import { randomUUID } from 'crypto';
import sharp from 'sharp';

export interface UploadResult {
  success: boolean;
  message: string;
  slipId?: string;
  imageUrl?: string;
  potentialMatches?: Array<{
    orderId: string;
    amount: number;
    confidence: number;
  }>;
}

export interface FileValidationResult {
  isValid: boolean;
  error?: string;
  fileInfo?: {
    size: number;
    mimeType: string;
    dimensions?: { width: number; height: number };
  };
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

export class PaymentUploadService extends BaseService {
  private readonly UPLOAD_DIR = process.env['UPLOAD_DIR'] || './uploads/payment-slips';
  private readonly MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
  private readonly ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  private readonly ALLOWED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.webp'];
  private readonly MAX_DIMENSION = 4096; // Max width/height in pixels

  constructor(prisma: PrismaClient) {
    super(prisma);
    this.ensureUploadDirectory();
  }

  /**
   * Ensure upload directory exists
   */
  private async ensureUploadDirectory(): Promise<void> {
    try {
      await fs.mkdir(this.UPLOAD_DIR, { recursive: true });
    } catch (error) {
      console.error('Failed to create upload directory:', error);
    }
  }

  /**
   * Validate uploaded file
   */
  async validateFile(file: {
    buffer: Buffer;
    mimetype: string;
    originalname: string;
    size: number;
  }): Promise<FileValidationResult> {
    try {
      // Check file size
      if (file.size > this.MAX_FILE_SIZE) {
        return {
          isValid: false,
          error: `File size exceeds maximum limit of ${this.MAX_FILE_SIZE / 1024 / 1024}MB`,
        };
      }

      // Check MIME type
      if (!this.ALLOWED_MIME_TYPES.includes(file.mimetype)) {
        return {
          isValid: false,
          error: `Invalid file type. Allowed types: ${this.ALLOWED_MIME_TYPES.join(', ')}`,
        };
      }

      // Check file extension
      const ext = path.extname(file.originalname).toLowerCase();
      if (!this.ALLOWED_EXTENSIONS.includes(ext)) {
        return {
          isValid: false,
          error: `Invalid file extension. Allowed extensions: ${this.ALLOWED_EXTENSIONS.join(', ')}`,
        };
      }

      // Validate image using Sharp
      try {
        const metadata = await sharp(file.buffer).metadata();
        
        if (!metadata.width || !metadata.height) {
          return {
            isValid: false,
            error: 'Invalid image file - unable to read dimensions',
          };
        }

        if (metadata.width > this.MAX_DIMENSION || metadata.height > this.MAX_DIMENSION) {
          return {
            isValid: false,
            error: `Image dimensions exceed maximum limit of ${this.MAX_DIMENSION}px`,
          };
        }

        return {
          isValid: true,
          fileInfo: {
            size: file.size,
            mimeType: file.mimetype,
            dimensions: {
              width: metadata.width,
              height: metadata.height,
            },
          },
        };
      } catch (sharpError) {
        return {
          isValid: false,
          error: 'Invalid or corrupted image file',
        };
      }
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.validateFile');
    }
  }

  /**
   * Process and optimize image
   */
  private async processImage(
    buffer: Buffer,
    filename: string
  ): Promise<{ processedBuffer: Buffer; filename: string }> {
    try {
      // Generate unique filename
      const ext = path.extname(filename).toLowerCase();
      const uniqueFilename = `${randomUUID()}${ext}`;

      // Process image: resize if too large, optimize quality
      const processedBuffer = await sharp(buffer)
        .resize(2048, 2048, { 
          fit: 'inside', 
          withoutEnlargement: true 
        })
        .jpeg({ 
          quality: 85, 
          progressive: true 
        })
        .toBuffer();

      return {
        processedBuffer,
        filename: uniqueFilename.replace(ext, '.jpg'), // Convert to JPEG
      };
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.processImage');
    }
  }

  /**
   * Save processed image to disk
   */
  private async saveImage(buffer: Buffer, filename: string): Promise<string> {
    try {
      const filePath = path.join(this.UPLOAD_DIR, filename);
      await fs.writeFile(filePath, buffer);
      
      // Return relative URL path
      return `/uploads/payment-slips/${filename}`;
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.saveImage');
    }
  }

  /**
   * Extract amount from image using OCR (placeholder for future implementation)
   */
  private async extractAmountFromImage(_buffer: Buffer): Promise<number | null> {
    // TODO: Implement OCR using Tesseract.js or similar
    // For now, return null to require manual amount entry
    return null;
  }

  /**
   * Upload and process payment slip
   */
  async uploadPaymentSlip(
    file: {
      buffer: Buffer;
      mimetype: string;
      originalname: string;
      size: number;
    },
    uploadedBy: string,
    lineAccountId: string,
    amount?: number
  ): Promise<UploadResult> {
    try {
      this.validateLineAccountAccess(lineAccountId);

      // Validate file
      const validation = await this.validateFile(file);
      if (!validation.isValid) {
        return {
          success: false,
          message: validation.error || 'File validation failed',
        };
      }

      // Process image
      const { processedBuffer, filename } = await this.processImage(
        file.buffer,
        file.originalname
      );

      // Save image
      const imageUrl = await this.saveImage(processedBuffer, filename);

      // Try to extract amount from image if not provided
      let extractedAmount = amount;
      if (!extractedAmount) {
        const ocrAmount = await this.extractAmountFromImage(processedBuffer);
        extractedAmount = ocrAmount || undefined;
      }

      // Create database record
      const slip = await this.prisma.odooSlipUpload.create({
        data: {
          lineAccountId,
          imageUrl,
          amount: extractedAmount || null,
          uploadedBy,
          status: SlipStatus.PENDING,
        },
      });

      // Find potential matches if amount is available
      let potentialMatches: Array<{
        orderId: string;
        amount: number;
        confidence: number;
      }> = [];

      if (extractedAmount) {
        const { PaymentMatchingService } = await import('./PaymentMatchingService');
        const matchingService = new PaymentMatchingService(this.prisma);
        potentialMatches = await matchingService.findPotentialMatches(
          extractedAmount,
          lineAccountId
        );
      }

      return {
        success: true,
        message: 'Payment slip uploaded successfully',
        slipId: slip.id,
        imageUrl,
        potentialMatches,
      };
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.uploadPaymentSlip');
    }
  }

  /**
   * Update payment slip amount manually
   */
  async updateSlipAmount(
    slipId: string,
    amount: number,
    lineAccountId: string
  ): Promise<UploadResult> {
    try {
      this.validateLineAccountAccess(lineAccountId);

      // Validate amount
      if (amount <= 0) {
        return {
          success: false,
          message: 'Amount must be greater than zero',
        };
      }

      // Check if slip exists and is pending
      const slip = await this.prisma.odooSlipUpload.findFirst({
        where: {
          id: slipId,
          lineAccountId,
          status: SlipStatus.PENDING,
        },
      });

      if (!slip) {
        return {
          success: false,
          message: 'Payment slip not found or already processed',
        };
      }

      // Update amount
      await this.prisma.odooSlipUpload.update({
        where: { id: slipId },
        data: { amount },
      });

      // Find potential matches
      const { PaymentMatchingService } = await import('./PaymentMatchingService');
      const matchingService = new PaymentMatchingService(this.prisma);
      const potentialMatches = await matchingService.findPotentialMatches(
        amount,
        lineAccountId
      );

      return {
        success: true,
        message: 'Payment slip amount updated successfully',
        slipId,
        potentialMatches,
      };
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.updateSlipAmount');
    }
  }

  /**
   * Bulk upload payment slips
   */
  async bulkUploadPaymentSlips(
    files: Array<{
      buffer: Buffer;
      mimetype: string;
      originalname: string;
      size: number;
    }>,
    uploadedBy: string,
    lineAccountId: string
  ): Promise<BulkUploadResult> {
    try {
      this.validateLineAccountAccess(lineAccountId);

      const result: BulkUploadResult = {
        totalFiles: files.length,
        successfulUploads: 0,
        failedUploads: 0,
        results: [],
      };

      // Process files in parallel (with concurrency limit)
      const concurrencyLimit = 5;
      const chunks = [];
      for (let i = 0; i < files.length; i += concurrencyLimit) {
        chunks.push(files.slice(i, i + concurrencyLimit));
      }

      for (const chunk of chunks) {
        const promises = chunk.map(async (file) => {
          try {
            const uploadResult = await this.uploadPaymentSlip(
              file,
              uploadedBy,
              lineAccountId
            );

            if (uploadResult.success) {
              result.successfulUploads++;
              result.results.push({
                filename: file.originalname,
                success: true,
                slipId: uploadResult.slipId!,
              });
            } else {
              result.failedUploads++;
              result.results.push({
                filename: file.originalname,
                success: false,
                error: uploadResult.message,
              });
            }
          } catch (error) {
            result.failedUploads++;
            result.results.push({
              filename: file.originalname,
              success: false,
              error: error instanceof Error ? error.message : 'Unknown error',
            });
          }
        });

        await Promise.all(promises);
      }

      return result;
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.bulkUploadPaymentSlips');
    }
  }

  /**
   * Get payment slip details
   */
  async getPaymentSlip(slipId: string, lineAccountId: string) {
    try {
      this.validateLineAccountAccess(lineAccountId);

      const slip = await this.prisma.odooSlipUpload.findFirst({
        where: {
          id: slipId,
          lineAccountId,
        },
      });

      if (!slip) {
        throw new Error('Payment slip not found');
      }

      // If slip has a matched order, fetch the order details separately
      let matchedOrder = null;
      if (slip.matchedOrderId) {
        matchedOrder = await this.prisma.odooOrder.findUnique({
          where: { id: slip.matchedOrderId },
          select: {
            id: true,
            odooOrderId: true,
            customerRef: true,
            customerName: true,
            totalAmount: true,
            status: true,
            orderDate: true,
          },
        });
      }

      return {
        ...slip,
        matchedOrder,
      };
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.getPaymentSlip');
    }
  }

  /**
   * List payment slips with filtering and pagination
   */
  async listPaymentSlips(
    lineAccountId: string,
    options: {
      status?: SlipStatus;
      dateFrom?: Date;
      dateTo?: Date;
      page?: number;
      limit?: number;
      search?: string;
    } = {}
  ) {
    try {
      this.validateLineAccountAccess(lineAccountId);

      const {
        status,
        dateFrom,
        dateTo,
        page = 1,
        limit = 20,
        search,
      } = options;

      const whereClause: any = { lineAccountId };

      if (status) {
        whereClause.status = status;
      }

      if (dateFrom || dateTo) {
        whereClause.createdAt = {};
        if (dateFrom) whereClause.createdAt.gte = dateFrom;
        if (dateTo) whereClause.createdAt.lte = dateTo;
      }

      if (search) {
        whereClause.OR = [
          { notes: { contains: search } },
          { uploadedBy: { contains: search } },
        ];
      }

      const [slips, total] = await Promise.all([
        this.prisma.odooSlipUpload.findMany({
          where: whereClause,
          orderBy: { createdAt: 'desc' },
          skip: (page - 1) * limit,
          take: limit,
          select: {
            id: true,
            imageUrl: true,
            amount: true,
            status: true,
            uploadedBy: true,
            matchedOrderId: true,
            processedAt: true,
            createdAt: true,
            notes: true,
          },
        }),
        this.prisma.odooSlipUpload.count({ where: whereClause }),
      ]);

      return {
        data: slips,
        meta: {
          page,
          limit,
          total,
          totalPages: Math.ceil(total / limit),
        },
      };
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.listPaymentSlips');
    }
  }

  /**
   * Delete payment slip (only if pending)
   */
  async deletePaymentSlip(slipId: string, lineAccountId: string): Promise<{ success: boolean; message: string }> {
    try {
      this.validateLineAccountAccess(lineAccountId);

      const slip = await this.prisma.odooSlipUpload.findFirst({
        where: {
          id: slipId,
          lineAccountId,
          status: SlipStatus.PENDING,
        },
      });

      if (!slip) {
        return {
          success: false,
          message: 'Payment slip not found or cannot be deleted',
        };
      }

      // Delete file from disk
      try {
        const filePath = path.join(process.cwd(), 'public', slip.imageUrl);
        await fs.unlink(filePath);
      } catch (fileError) {
        console.warn('Failed to delete image file:', fileError);
      }

      // Delete database record
      await this.prisma.odooSlipUpload.delete({
        where: { id: slipId },
      });

      return {
        success: true,
        message: 'Payment slip deleted successfully',
      };
    } catch (error) {
      this.handleError(error, 'PaymentUploadService.deletePaymentSlip');
    }
  }
}