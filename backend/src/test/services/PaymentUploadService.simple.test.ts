import { describe, it, expect, beforeEach } from 'vitest';
import { PaymentUploadService } from '@/services/PaymentUploadService';
import { prisma } from '@/utils/prisma';

describe('PaymentUploadService - Simple Tests', () => {
  let service: PaymentUploadService;
  const mockLineAccountId = '123e4567-e89b-12d3-a456-426614174000';

  beforeEach(() => {
    service = new PaymentUploadService(prisma);
  });

  describe('validateFile', () => {
    it('should accept valid JPEG files', async () => {
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

  describe('Service instantiation', () => {
    it('should create service instance successfully', () => {
      expect(service).toBeDefined();
      expect(service).toBeInstanceOf(PaymentUploadService);
    });
  });
});