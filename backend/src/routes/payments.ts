import { FastifyInstance } from 'fastify';
import { SlipStatus } from '@prisma/client';
import { PaymentUploadService } from '@/services/PaymentUploadService';
import { PaymentMatchingService } from '@/services/PaymentMatchingService';
import { prisma } from '@/utils/prisma';
import { APIResponse, JWTPayload } from '@/types';
import { Permission } from '@/middleware/rbac';
import { z } from 'zod';

// Validation schemas
const uploadSchema = z.object({
  amount: z.number().positive().optional(),
});

const updateAmountSchema = z.object({
  amount: z.number().positive(),
});

const matchSlipSchema = z.object({
  orderId: z.string().uuid(),
});

const rejectSlipSchema = z.object({
  reason: z.string().optional(),
});

const listSlipsSchema = z.object({
  status: z.nativeEnum(SlipStatus).optional(),
  dateFrom: z.string().datetime().optional(),
  dateTo: z.string().datetime().optional(),
  page: z.number().int().positive().default(1),
  limit: z.number().int().positive().max(100).default(20),
  search: z.string().optional(),
});

// Multipart file upload configuration
const multipartOptions = {
  limits: {
    fileSize: 10 * 1024 * 1024, // 10MB
    files: 10, // Max 10 files for bulk upload
  },
};

export default async function paymentRoutes(fastify: FastifyInstance) {
  const uploadService = new PaymentUploadService(prisma);
  const matchingService = new PaymentMatchingService(prisma);

  // Register multipart support
  await fastify.register(require('@fastify/multipart'), multipartOptions);

  /**
   * GET /api/v1/payments/slips - List payment slips with filtering
   */
  fastify.get<{
    Querystring: z.infer<typeof listSlipsSchema>;
  }>('/slips', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
    schema: {
      querystring: listSlipsSchema,
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'array',
              items: {
                type: 'object',
                properties: {
                  id: { type: 'string' },
                  imageUrl: { type: 'string' },
                  amount: { type: 'number', nullable: true },
                  status: { type: 'string' },
                  uploadedBy: { type: 'string' },
                  matchedOrderId: { type: 'string', nullable: true },
                  processedAt: { type: 'string', nullable: true },
                  createdAt: { type: 'string' },
                  notes: { type: 'string', nullable: true },
                },
              },
            },
            meta: {
              type: 'object',
              properties: {
                page: { type: 'number' },
                limit: { type: 'number' },
                total: { type: 'number' },
                totalPages: { type: 'number' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;
      const query = request.query;

      const options = {
        ...query,
        dateFrom: query.dateFrom ? new Date(query.dateFrom) : undefined,
        dateTo: query.dateTo ? new Date(query.dateTo) : undefined,
        status: query.status,
      };

      const result = await uploadService.listPaymentSlips(user.lineAccountId, options);

      const response: APIResponse = {
        success: true,
        data: result.data,
        meta: result.meta,
      };

      return reply.code(200).send(response);
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to fetch payment slips',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * POST /api/v1/payments/upload - Upload payment slip image
   */
  fastify.post<{
    Body: z.infer<typeof uploadSchema>;
  }>('/upload', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;

      // Handle multipart file upload
      const data = await (request as any).file();
      if (!data) {
        return reply.code(400).send({
          success: false,
          error: {
            code: 'MISSING_FILE',
            message: 'No file uploaded',
            timestamp: new Date().toISOString(),
          },
        });
      }

      // Get file buffer
      const buffer = await data.toBuffer();
      
      // Parse additional fields
      const fields = data.fields;
      const amount = fields.amount ? parseFloat(fields.amount.value as string) : undefined;

      const file = {
        buffer,
        mimetype: data.mimetype,
        originalname: data.filename,
        size: buffer.length,
      };

      const result = await uploadService.uploadPaymentSlip(
        file,
        user.userId,
        user.lineAccountId,
        amount
      );

      if (result.success) {
        const response: APIResponse = {
          success: true,
          data: {
            slipId: result.slipId,
            imageUrl: result.imageUrl,
            potentialMatches: result.potentialMatches,
          },
        };
        return reply.code(201).send(response);
      } else {
        return reply.code(400).send({
          success: false,
          error: {
            code: 'UPLOAD_FAILED',
            message: result.message,
            timestamp: new Date().toISOString(),
          },
        });
      }
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to upload payment slip',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * POST /api/v1/payments/bulk - Bulk upload payment slips
   */
  fastify.post('/bulk', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;

      // Handle multiple file uploads
      const files = (request as any).files();
      const fileArray = [];

      for await (const file of files) {
        const buffer = await file.toBuffer();
        fileArray.push({
          buffer,
          mimetype: file.mimetype,
          originalname: file.filename,
          size: buffer.length,
        });
      }

      if (fileArray.length === 0) {
        return reply.code(400).send({
          success: false,
          error: {
            code: 'NO_FILES',
            message: 'No files uploaded',
            timestamp: new Date().toISOString(),
          },
        });
      }

      const result = await uploadService.bulkUploadPaymentSlips(
        fileArray,
        user.userId,
        user.lineAccountId
      );

      const response: APIResponse = {
        success: true,
        data: result,
      };

      return reply.code(201).send(response);
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to process bulk upload',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /api/v1/payments/slips/:id - Get payment slip details
   */
  fastify.get<{
    Params: { id: string };
  }>('/slips/:id', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;
      const { id } = request.params;

      const slip = await uploadService.getPaymentSlip(id, user.lineAccountId);

      const response: APIResponse = {
        success: true,
        data: slip,
      };

      return reply.code(200).send(response);
    } catch (error) {
      request.log.error(error);
      
      if (error instanceof Error && error.message === 'Payment slip not found') {
        return reply.code(404).send({
          success: false,
          error: {
            code: 'SLIP_NOT_FOUND',
            message: 'Payment slip not found',
            timestamp: new Date().toISOString(),
          },
        });
      }

      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to fetch payment slip',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * PUT /api/v1/payments/slips/:id/amount - Update payment slip amount
   */
  fastify.put<{
    Params: { id: string };
    Body: z.infer<typeof updateAmountSchema>;
  }>('/slips/:id/amount', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
    schema: {
      body: updateAmountSchema,
    },
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;
      const { id } = request.params;
      const { amount } = request.body;

      const result = await uploadService.updateSlipAmount(id, amount, user.lineAccountId);

      if (result.success) {
        const response: APIResponse = {
          success: true,
          data: {
            slipId: result.slipId,
            potentialMatches: result.potentialMatches,
          },
        };
        return reply.code(200).send(response);
      } else {
        return reply.code(400).send({
          success: false,
          error: {
            code: 'UPDATE_FAILED',
            message: result.message,
            timestamp: new Date().toISOString(),
          },
        });
      }
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to update payment slip amount',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * PUT /api/v1/payments/slips/:id/match - Match payment slip to order
   */
  fastify.put<{
    Params: { id: string };
    Body: z.infer<typeof matchSlipSchema>;
  }>('/slips/:id/match', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
    schema: {
      body: matchSlipSchema,
    },
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;
      const { id } = request.params;
      const { orderId } = request.body;

      const result = await matchingService.matchPaymentSlip(id, orderId, user.lineAccountId);

      if (result.success) {
        const response: APIResponse = {
          success: true,
          data: {
            matchedOrderId: result.matchedOrderId,
            message: result.message,
          },
        };
        return reply.code(200).send(response);
      } else {
        return reply.code(400).send({
          success: false,
          error: {
            code: 'MATCH_FAILED',
            message: result.message,
            timestamp: new Date().toISOString(),
          },
        });
      }
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to match payment slip',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * PUT /api/v1/payments/slips/:id/reject - Reject payment slip
   */
  fastify.put<{
    Params: { id: string };
    Body: z.infer<typeof rejectSlipSchema>;
  }>('/slips/:id/reject', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
    schema: {
      body: rejectSlipSchema,
    },
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;
      const { id } = request.params;
      const { reason } = request.body;

      const result = await matchingService.rejectPaymentSlip(id, user.lineAccountId, reason);

      if (result.success) {
        const response: APIResponse = {
          success: true,
          data: { message: result.message },
        };
        return reply.code(200).send(response);
      } else {
        return reply.code(400).send({
          success: false,
          error: {
            code: 'REJECT_FAILED',
            message: result.message,
            timestamp: new Date().toISOString(),
          },
        });
      }
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to reject payment slip',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * DELETE /api/v1/payments/slips/:id - Delete payment slip
   */
  fastify.delete<{
    Params: { id: string };
  }>('/slips/:id', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;
      const { id } = request.params;

      const result = await uploadService.deletePaymentSlip(id, user.lineAccountId);

      if (result.success) {
        const response: APIResponse = {
          success: true,
          data: { message: result.message },
        };
        return reply.code(200).send(response);
      } else {
        return reply.code(400).send({
          success: false,
          error: {
            code: 'DELETE_FAILED',
            message: result.message,
            timestamp: new Date().toISOString(),
          },
        });
      }
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to delete payment slip',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /api/v1/payments/pending - Get pending payment slips
   */
  fastify.get('/pending', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;

      const result = await uploadService.listPaymentSlips(user.lineAccountId, {
        status: SlipStatus.PENDING,
        limit: 100,
      });

      const response: APIResponse = {
        success: true,
        data: result.data,
        meta: result.meta,
      };

      return reply.code(200).send(response);
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to fetch pending payment slips',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * POST /api/v1/payments/auto-match - Perform automatic matching
   */
  fastify.post('/auto-match', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;

      const result = await matchingService.performAutomaticMatching(user.lineAccountId);

      const response: APIResponse = {
        success: true,
        data: result,
      };

      return reply.code(200).send(response);
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to perform automatic matching',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  /**
   * GET /api/v1/payments/statistics - Get payment processing statistics
   */
  fastify.get<{
    Querystring: {
      dateFrom?: string;
      dateTo?: string;
    };
  }>('/statistics', {
    preHandler: [fastify.authenticate, fastify.requirePermission(Permission.PROCESS_PAYMENTS)],
  }, async (request, reply) => {
    try {
      const user = request.user as JWTPayload;
      const { dateFrom, dateTo } = request.query;

      const result = await matchingService.getMatchingStatistics(
        user.lineAccountId,
        dateFrom ? new Date(dateFrom) : undefined,
        dateTo ? new Date(dateTo) : undefined
      );

      const response: APIResponse = {
        success: true,
        data: result,
      };

      return reply.code(200).send(response);
    } catch (error) {
      request.log.error(error);
      return reply.code(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Failed to fetch payment statistics',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });
}