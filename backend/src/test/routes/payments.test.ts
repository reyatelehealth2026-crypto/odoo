import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { FastifyInstance } from 'fastify';
import { build } from '@test/helper';
import { prisma, createTestUser } from '@test/setup';
import { SlipStatus } from '@prisma/client';

describe('Payment Routes', () => {
  let app: FastifyInstance;
  let authToken: string;
  const mockLineAccountId = '123e4567-e89b-12d3-a456-426614174000';

  beforeEach(async () => {
    app = await build();
    
    // Clean up test data
    await prisma.odooSlipUpload.deleteMany();
    await prisma.odooOrder.deleteMany();
    await prisma.userSession.deleteMany();
    await prisma.user.deleteMany();

    // Create test user and get auth token
    const user = createTestUser({
      lineAccountId: mockLineAccountId,
      role: 'ADMIN',
      permissions: ['process_payments'],
    });

    await prisma.user.create({ data: user });

    // Login to get token
    const loginResponse = await app.inject({
      method: 'POST',
      url: '/api/v1/auth/login',
      payload: {
        username: user.username,
        password: 'password123',
      },
    });

    const loginData = JSON.parse(loginResponse.body);
    authToken = loginData.data.accessToken;
  });

  afterEach(async () => {
    await app.close();
  });

  describe('GET /api/v1/payments/slips', () => {
    beforeEach(async () => {
      // Create test payment slips
      await prisma.odooSlipUpload.createMany({
        data: [
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip1.jpg',
            amount: 1000.00,
            uploadedBy: 'user-123',
            status: SlipStatus.PENDING,
          },
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip2.jpg',
            amount: 2000.00,
            uploadedBy: 'user-123',
            status: SlipStatus.MATCHED,
          },
        ],
      });
    });

    it('should list payment slips', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/api/v1/payments/slips',
        headers: {
          authorization: `Bearer ${authToken}`,
        },
      });

      expect(response.statusCode).toBe(200);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data).toHaveLength(2);
      expect(data.meta.total).toBe(2);
    });

    it('should filter by status', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/api/v1/payments/slips?status=PENDING',
        headers: {
          authorization: `Bearer ${authToken}`,
        },
      });

      expect(response.statusCode).toBe(200);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data).toHaveLength(1);
      expect(data.data[0].status).toBe('PENDING');
    });

    it('should require authentication', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/api/v1/payments/slips',
      });

      expect(response.statusCode).toBe(401);
    });
  });

  describe('POST /api/v1/payments/upload', () => {
    it('should upload payment slip successfully', async () => {
      const form = new FormData();
      const imageBuffer = Buffer.from('fake-image-data');
      const blob = new Blob([imageBuffer], { type: 'image/jpeg' });
      
      form.append('file', blob, 'payment-slip.jpg');
      form.append('amount', '1000.00');

      const response = await app.inject({
        method: 'POST',
        url: '/api/v1/payments/upload',
        headers: {
          authorization: `Bearer ${authToken}`,
        },
        payload: form,
      });

      expect(response.statusCode).toBe(201);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data.slipId).toBeDefined();
      expect(data.data.imageUrl).toBeDefined();
    });

    it('should require file upload', async () => {
      const response = await app.inject({
        method: 'POST',
        url: '/api/v1/payments/upload',
        headers: {
          authorization: `Bearer ${authToken}`,
        },
        payload: {},
      });

      expect(response.statusCode).toBe(400);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(false);
      expect(data.error.code).toBe('MISSING_FILE');
    });
  });

  describe('PUT /api/v1/payments/slips/:id/amount', () => {
    let slipId: string;

    beforeEach(async () => {
      const slip = await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/test-slip.jpg',
          uploadedBy: 'user-123',
          status: SlipStatus.PENDING,
        },
      });
      slipId = slip.id;
    });

    it('should update slip amount', async () => {
      const response = await app.inject({
        method: 'PUT',
        url: `/api/v1/payments/slips/${slipId}/amount`,
        headers: {
          authorization: `Bearer ${authToken}`,
          'content-type': 'application/json',
        },
        payload: {
          amount: 1500.00,
        },
      });

      expect(response.statusCode).toBe(200);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data.slipId).toBe(slipId);

      // Verify database update
      const updatedSlip = await prisma.odooSlipUpload.findUnique({
        where: { id: slipId },
      });
      expect(updatedSlip?.amount).toBe(1500.00);
    });

    it('should reject invalid amounts', async () => {
      const response = await app.inject({
        method: 'PUT',
        url: `/api/v1/payments/slips/${slipId}/amount`,
        headers: {
          authorization: `Bearer ${authToken}`,
          'content-type': 'application/json',
        },
        payload: {
          amount: -100.00,
        },
      });

      expect(response.statusCode).toBe(400);
    });
  });

  describe('PUT /api/v1/payments/slips/:id/match', () => {
    let slipId: string;
    let orderId: string;

    beforeEach(async () => {
      const slip = await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/test-slip.jpg',
          amount: 1000.00,
          uploadedBy: 'user-123',
          status: SlipStatus.PENDING,
        },
      });
      slipId = slip.id;

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
      orderId = order.id;
    });

    it('should match slip to order', async () => {
      const response = await app.inject({
        method: 'PUT',
        url: `/api/v1/payments/slips/${slipId}/match`,
        headers: {
          authorization: `Bearer ${authToken}`,
          'content-type': 'application/json',
        },
        payload: {
          orderId,
        },
      });

      expect(response.statusCode).toBe(200);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data.matchedOrderId).toBe(orderId);

      // Verify database updates
      const updatedSlip = await prisma.odooSlipUpload.findUnique({
        where: { id: slipId },
      });
      expect(updatedSlip?.status).toBe(SlipStatus.MATCHED);
      expect(updatedSlip?.matchedOrderId).toBe(orderId);

      const updatedOrder = await prisma.odooOrder.findUnique({
        where: { id: orderId },
      });
      expect(updatedOrder?.status).toBe('processing');
    });

    it('should reject invalid order ID', async () => {
      const response = await app.inject({
        method: 'PUT',
        url: `/api/v1/payments/slips/${slipId}/match`,
        headers: {
          authorization: `Bearer ${authToken}`,
          'content-type': 'application/json',
        },
        payload: {
          orderId: 'invalid-uuid',
        },
      });

      expect(response.statusCode).toBe(400);
    });
  });

  describe('GET /api/v1/payments/pending', () => {
    beforeEach(async () => {
      await prisma.odooSlipUpload.createMany({
        data: [
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip1.jpg',
            amount: 1000.00,
            uploadedBy: 'user-123',
            status: SlipStatus.PENDING,
          },
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip2.jpg',
            amount: 2000.00,
            uploadedBy: 'user-123',
            status: SlipStatus.MATCHED,
          },
        ],
      });
    });

    it('should return only pending slips', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/api/v1/payments/pending',
        headers: {
          authorization: `Bearer ${authToken}`,
        },
      });

      expect(response.statusCode).toBe(200);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data).toHaveLength(1);
      expect(data.data[0].status).toBe('PENDING');
    });
  });

  describe('POST /api/v1/payments/auto-match', () => {
    beforeEach(async () => {
      // Create matching order and slip
      await prisma.odooOrder.create({
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

      await prisma.odooSlipUpload.create({
        data: {
          lineAccountId: mockLineAccountId,
          imageUrl: '/uploads/slip1.jpg',
          amount: 1000.00,
          uploadedBy: 'user-123',
          status: SlipStatus.PENDING,
        },
      });
    });

    it('should perform automatic matching', async () => {
      const response = await app.inject({
        method: 'POST',
        url: '/api/v1/payments/auto-match',
        headers: {
          authorization: `Bearer ${authToken}`,
        },
      });

      expect(response.statusCode).toBe(200);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data.totalProcessed).toBe(1);
      expect(data.data.successfulMatches).toBe(1);
      expect(data.data.matches).toHaveLength(1);
    });
  });

  describe('GET /api/v1/payments/statistics', () => {
    beforeEach(async () => {
      await prisma.odooSlipUpload.createMany({
        data: [
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip1.jpg',
            amount: 1000.00,
            uploadedBy: 'user-123',
            status: SlipStatus.PENDING,
          },
          {
            lineAccountId: mockLineAccountId,
            imageUrl: '/uploads/slip2.jpg',
            amount: 2000.00,
            uploadedBy: 'user-123',
            status: SlipStatus.MATCHED,
            processedAt: new Date(),
          },
        ],
      });
    });

    it('should return payment statistics', async () => {
      const response = await app.inject({
        method: 'GET',
        url: '/api/v1/payments/statistics',
        headers: {
          authorization: `Bearer ${authToken}`,
        },
      });

      expect(response.statusCode).toBe(200);
      
      const data = JSON.parse(response.body);
      expect(data.success).toBe(true);
      expect(data.data.totalSlips).toBe(2);
      expect(data.data.matchedSlips).toBe(1);
      expect(data.data.pendingSlips).toBe(1);
      expect(data.data.matchingRate).toBe(50);
    });
  });
});