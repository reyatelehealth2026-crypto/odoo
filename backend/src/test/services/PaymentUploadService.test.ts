import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import * as fc from 'fast-check';
import { PaymentUploadService } from '@/services/PaymentUploadService';
import { prisma, createTestUser } from '@test/setup';
import { SlipStatus } from '@prisma/client';
import { promises as fs } from 'fs';
import path from 'path';

// Mock Sharp for testing
vi.mock('sharp', () => ({
  default: vi.fn().mockImplementation(() => ({
    metadata: vi.fn().mockResolvedValue({
      width: 1024,
      height: 768,
      format: 'jpeg',
    }),
    resize: vi.fn().mockReturnThis(),
    jpeg: vi.fn().mockReturnThis(),
    toBuffer: vi.fn().mockResolvedValue(Buffer.from('processed-image-data')),
  })),
}));

describe('PaymentUploadService', () => {
  let service: PaymentUploadService;
  const mockLineAccountId = '123e4567-e89b-12d3-a456-426614174000';
  const mockUserId = 'user-123';

  beforeEach(async () => {
    service = new PaymentUploadService(prisma);
    
    // Clean up test data
    await prisma.odooSlipUpload.deleteMany();
    await prisma.odooOrder.deleteMany();
  });

  afterEach(async () => {
    // Clean up uploaded files
    try {
      const uploadDir = './uploads/payment-slips';
      const files = await fs.readdir(uploadDir);
      await Promise.all(
        files.map(file => fs.unlink(path.join(uploadDir, file)).catch(() => {}))
      );
    } catch (error) {
      // Directory might not exist, ignore
    }
  });

  describe('validateFile', () => {
    it('should accept valid image files', async () => {
      const validFile = {
        buffer: Buffer.from('fake-jpeg-data'),
        mimetype: 'image/jpeg',
        originalname: 'payment-slip.jpg',
        size: 1024 * 1024, // 1MB
      };

      const result = await service.validateFile(validFile);

      expect(result.isValid).toBe(true);
      expect(result.fileInfo).toBeDefined();
      expect(result.fileInfo?.mimeType).toBe('image/jpeg');
    });

    it('should reject files that are too large', async () => {
      const largeFile = {
        buffer: Buffer.alloc(15 * 1024 * 1024), // 15MB
        mimetype: 'image/jpeg',
        originalname: 'large-file.jpg',
        size: 15 * 1024 * 1024,
      };

      const result = await service.validateFile(largeFile);

      expect(result.isValid).toBe(false);
      expect(result.error).toContain('File size exceeds maximum limit');
    });

    it('should reject invalid file types', async () => {
      const invalidFile = {
        buffer: Buffer.from('fake-pdf-data'),
        mimetype: 'application/pdf',
        originalname: 'document.pdf',
        size: 1024,
      };

      const result = await service.validateFile(invalidFile);

      expect(result.isValid).toBe(false);
      expect(result.error).toContain('Invalid file type');
    });

    it('should reject files with invalid extensions', async () => {
      const invalidExtFile = {
        buffer: Buffer.from('fake-image-data'),
        mimetype: 'image/jpeg',
        originalname: 'image.txt',
        size: 1024,
      };

      const result = await service.validateFile(invalidExtFile);

      expect(result.isValid).toBe(false);
      expect(result.error).toContain('Invalid file extension');
    });
  });

  describe('uploadPaymentSlip', () => {
    it('should successfully upload a valid payment slip', async () => {
      const validFile = {
        buffer: Buffer.from('fake-jpeg-data'),
        mimetype: 'image/jpeg',
        originalname: 'payment-slip.jpg',
        size: 1024,
      };

      const result = await service.uploadPaymentSlip(
        validFile,
        mockUserId,
        mockLineAccountId,
        1000.00
      );

      expect(result.success).toBe(true);
      expect(result.slipId).toBeDefined();
      expect(result.imageUrl).toBeDefined();
      expect(result.potentialMatches).toBeDefined();

      // Verify database record
      const slip = await prisma.odooSlipUpload.findUnique({
        where: { id: result.slipId },
      });

      expect(slip).toBeDefined();
      expect(slip?.amount).toBe(1000.00);
      expect(slip?.status).toBe(SlipStatus.PENDING);
      expect(slip?.uploadedBy).toBe(mockUserId);
      expect(slip?.lineAccountId).toBe(mockLineAccountId);
    });

    it('should find potential matches when amount is provided', async () => {
      // Create a test order
      const order = await prisma.odooOrder.create({
        data: {
          odooOrderId: 'order-123',
          lineAccountId: mockLineAccountId,
          customerRef: 'CUST001',
          customerName: 'Test Customer',
          status: 'pending',
          totalAmount: 1000.00,
          currency: 'THB',
        },
      });

      const validFile = {
        buffer: Buffer.from('fake-jpeg-data'),
        mimetype: 'image/jpeg',
        originalname: 'payment-slip.jpg',
        size: 1024,
      };

      const result = await service.uploadPaymentSlip(
        validFile,
        mockUserId,
        mockLineAccountId,
        1000.00
      );

      expect(result.success).toBe(true);
      expect(result.potentialMatches).toHaveLength(1);
      expect(result.potentialMatches?.[0].orderId).toBe(order.id);
      expect(result.potentialMatches?.[0].confidence).toBe(1.0);
    });

    it('should handle upload without amount', async () => {
      const validFile = {
        buffer: Buffer.from('fake-jpeg-data'),
        mimetype: 'image/jpeg',
        originalname: 'payment-slip.jpg',
        size: 1024,
      };

      const result = await service.uploadPaymentSlip(
        validFile,
        mockUserId,
        mockLineAccountId
      );

      expect(result.success).toBe(true);
      expect(result.potentialMatches).toEqual([]);

      // Verify database record has null amount
      const slip = await prisma.odooSlipUpload.findUnique({
        where: { id: result.slipId },
      });

      expect(slip?.amount).toBeNull();
    });
  });

  describe('updateSlipAmount', () => {
    it('should update slip amount and find potential matches', async () => {
      // Create a test slip
      const slip = await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/test-slip.jpg',
          uploadedBy: mockUserId,
          status: SlipStatus.PENDING,
        },
      });

      // Create a test order
      const order = await prisma.odooOrder.create({
        data: {
          odooOrderId: 'order-123',
          lineAccountId: mockLineAccountId,
          customerRef: 'CUST001',
          customerName: 'Test Customer',
          status: 'pending',
          totalAmount: 1000.00,
          currency: 'THB',
        },
      });

      const result = await service.updateSlipAmount(
        slip.id,
        1000.00,
        mockLineAccountId
      );

      expect(result.success).toBe(true);
      expect(result.potentialMatches).toHaveLength(1);
      expect(result.potentialMatches?.[0].orderId).toBe(order.id);

      // Verify database update
      const updatedSlip = await prisma.odooSlipUpload.findUnique({
        where: { id: slip.id },
      });

      expect(updatedSlip?.amount).toBe(1000.00);
    });

    it('should reject invalid amounts', async () => {
      const slip = await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/test-slip.jpg',
          uploadedBy: mockUserId,
          status: SlipStatus.PENDING,
        },
      });

      const result = await service.updateSlipAmount(
        slip.id,
        -100.00,
        mockLineAccountId
      );

      expect(result.success).toBe(false);
      expect(result.message).toContain('Amount must be greater than zero');
    });

    it('should reject updates to non-pending slips', async () => {
      const slip = await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/test-slip.jpg',
          uploadedBy: mockUserId,
          status: SlipStatus.MATCHED,
        },
      });

      const result = await service.updateSlipAmount(
        slip.id,
        1000.00,
        mockLineAccountId
      );

      expect(result.success).toBe(false);
      expect(result.message).toContain('not found or already processed');
    });
  });

  describe('listPaymentSlips', () => {
    beforeEach(async () => {
      // Create test slips
      await prisma.odooSlipUpload.createMany({
        data: [
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip1.jpg',
            amount: 1000.00,
            uploadedBy: mockUserId,
            status: SlipStatus.PENDING,
          },
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip2.jpg',
            amount: 2000.00,
            uploadedBy: mockUserId,
            status: SlipStatus.MATCHED,
          },
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip3.jpg',
            amount: 3000.00,
            uploadedBy: mockUserId,
            status: SlipStatus.REJECTED,
          },
        ],
      });
    });

    it('should list all slips without filters', async () => {
      const result = await service.listPaymentSlips(mockLineAccountId);

      expect(result.data).toHaveLength(3);
      expect(result.meta.total).toBe(3);
      expect(result.meta.page).toBe(1);
      expect(result.meta.limit).toBe(20);
    });

    it('should filter by status', async () => {
      const result = await service.listPaymentSlips(mockLineAccountId, {
        status: SlipStatus.PENDING,
      });

      expect(result.data).toHaveLength(1);
      expect(result.data[0].status).toBe(SlipStatus.PENDING);
    });

    it('should support pagination', async () => {
      const result = await service.listPaymentSlips(mockLineAccountId, {
        page: 1,
        limit: 2,
      });

      expect(result.data).toHaveLength(2);
      expect(result.meta.totalPages).toBe(2);
    });
  });

  describe('deletePaymentSlip', () => {
    it('should delete pending payment slip', async () => {
      const slip = await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/test-slip.jpg',
          uploadedBy: mockUserId,
          status: SlipStatus.PENDING,
        },
      });

      const result = await service.deletePaymentSlip(slip.id, mockLineAccountId);

      expect(result.success).toBe(true);

      // Verify deletion
      const deletedSlip = await prisma.odooSlipUpload.findUnique({
        where: { id: slip.id },
      });

      expect(deletedSlip).toBeNull();
    });

    it('should not delete non-pending slips', async () => {
      const slip = await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/test-slip.jpg',
          uploadedBy: mockUserId,
          status: SlipStatus.MATCHED,
        },
      });

      const result = await service.deletePaymentSlip(slip.id, mockLineAccountId);

      expect(result.success).toBe(false);
      expect(result.message).toContain('cannot be deleted');
    });
  });

  // Property-based tests
  describe('Property Tests', () => {
    it('should handle various file sizes within limits', async () => {
      await fc.assert(fc.asyncProperty(
        fc.integer({ min: 1, max: 10 * 1024 * 1024 }), // File size within limits
        fc.constantFrom('image/jpeg', 'image/png', 'image/webp'), // Valid MIME types
        fc.constantFrom('.jpg', '.jpeg', '.png', '.webp'), // Valid extensions
        async (fileSize, mimeType, extension) => {
          const file = {
            buffer: Buffer.alloc(fileSize),
            mimetype: mimeType,
            originalname: `test${extension}`,
            size: fileSize,
          };

          const result = await service.validateFile(file);
          return result.isValid === true;
        }
      ), { numRuns: 50 });
    });

    it('should reject files exceeding size limits', async () => {
      await fc.assert(fc.asyncProperty(
        fc.integer({ min: 10 * 1024 * 1024 + 1, max: 50 * 1024 * 1024 }), // Oversized files
        async (fileSize) => {
          const file = {
            buffer: Buffer.alloc(Math.min(fileSize, 1024)), // Don't actually allocate huge buffers
            mimetype: 'image/jpeg',
            originalname: 'test.jpg',
            size: fileSize,
          };

          const result = await service.validateFile(file);
          return result.isValid === false && result.error?.includes('File size exceeds');
        }
      ), { numRuns: 20 });
    });
  });
});