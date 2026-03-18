import { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { authenticate } from '@/middleware/auth';
import { validateQuery } from '@/middleware/validation';
import { CustomerService } from '@/services/CustomerService';
import { PrismaClient } from '@prisma/client';

// Validation schemas
const customerListQuerySchema = z.object({
  page: z.string().transform(Number).optional().default('1'),
  limit: z.string().transform(Number).optional().default('20'),
  sort: z.string().optional().default('updatedAt'),
  order: z.enum(['asc', 'desc']).optional().default('desc'),
  search: z.string().optional(),
  name: z.string().optional(),
  reference: z.string().optional(),
  partnerId: z.string().optional(),
  lineConnected: z.string().transform(val => val === 'true').optional(),
  tier: z.string().optional(),
  dateFrom: z.string().optional(),
  dateTo: z.string().optional(),
  lineAccountId: z.string().optional(),
});

const orderListQuerySchema = z.object({
  page: z.string().transform(Number).optional().default('1'),
  limit: z.string().transform(Number).optional().default('20'),
  sort: z.string().optional().default('createdAt'),
  order: z.enum(['asc', 'desc']).optional().default('desc'),
});

const updateLineConnectionSchema = z.object({
  lineUserId: z.string().nullable(),
});

export default async function customerRoutes(fastify: FastifyInstance): Promise<void> {
  const prisma = new PrismaClient();
  const customerService = new CustomerService(prisma);

  /**
   * GET /api/v1/customers - Search customers by various criteria
   * Validates: Requirements FR-3.1
   */
  fastify.get('/', {
    preHandler: [authenticate, validateQuery(customerListQuerySchema)],
    schema: {
      tags: ['Customers'],
      summary: 'Search customers by name, reference, or Partner ID',
      description: 'Search and filter customers with pagination support',
      querystring: {
        type: 'object',
        properties: {
          page: { type: 'number', minimum: 1, default: 1 },
          limit: { type: 'number', minimum: 1, maximum: 100, default: 20 },
          sort: { type: 'string', default: 'updatedAt' },
          order: { type: 'string', enum: ['asc', 'desc'], default: 'desc' },
          search: { type: 'string', description: 'Search across name, phone, email, member ID' },
          name: { type: 'string', description: 'Filter by customer name' },
          reference: { type: 'string', description: 'Filter by member ID/reference' },
          partnerId: { type: 'string', description: 'Filter by Odoo Partner ID' },
          lineConnected: { type: 'boolean', description: 'Filter by LINE connection status' },
          tier: { type: 'string', description: 'Filter by membership tier' },
          dateFrom: { type: 'string', format: 'date' },
          dateTo: { type: 'string', format: 'date' },
          lineAccountId: { type: 'string' },
        },
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                data: {
                  type: 'array',
                  items: {
                    type: 'object',
                    properties: {
                      id: { type: 'string' },
                      lineAccountId: { type: 'string' },
                      lineUserId: { type: 'string' },
                      displayName: { type: 'string' },
                      realName: { type: 'string' },
                      phone: { type: 'string' },
                      email: { type: 'string' },
                      totalOrders: { type: 'number' },
                      totalSpent: { type: 'number' },
                      availablePoints: { type: 'number' },
                      tier: { type: 'string' },
                      membershipLevel: { type: 'string' },
                      lastOrderAt: { type: 'string', format: 'date-time' },
                      lastInteractionAt: { type: 'string', format: 'date-time' },
                      isBlocked: { type: 'boolean' },
                      createdAt: { type: 'string', format: 'date-time' },
                      updatedAt: { type: 'string', format: 'date-time' },
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
      },
    },
  }, async (request, reply) => {
    try {
      const query = request.query as any;
      const user = request.user as any;
      const lineAccountId = query.lineAccountId || user?.lineAccountId || '1';

      // Parse filters
      const filters = {
        ...(query.search && { search: query.search }),
        ...(query.name && { name: query.name }),
        ...(query.reference && { reference: query.reference }),
        ...(query.partnerId && { partnerId: query.partnerId }),
        ...(query.lineConnected !== undefined && { lineConnected: query.lineConnected }),
        ...(query.tier && { tier: query.tier }),
        ...(query.dateFrom && { dateFrom: new Date(query.dateFrom) }),
        ...(query.dateTo && { dateTo: new Date(query.dateTo) }),
      };

      const pagination = {
        page: query.page,
        limit: Math.min(query.limit, 100), // Cap at 100
        sort: query.sort,
        order: query.order,
      };

      const result = await customerService.searchCustomers(lineAccountId, filters, pagination);

      return reply.send({
        success: true,
        data: result,
      });
    } catch (error) {
      console.error('Customer search error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'CUSTOMER_SEARCH_ERROR',
          message: 'Failed to search customers',
        },
      });
    }
  });

  /**
   * GET /api/v1/customers/:id - Get customer profile details
   * Validates: Requirements FR-3.2
   */
  fastify.get('/:id', {
    preHandler: [authenticate],
    schema: {
      tags: ['Customers'],
      summary: 'Get customer profile with credit information',
      description: 'Retrieve detailed customer profile including credit info, points, and medical data',
      params: {
        type: 'object',
        properties: {
          id: { type: 'string' },
        },
        required: ['id'],
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                id: { type: 'string' },
                lineAccountId: { type: 'string' },
                lineUserId: { type: 'string' },
                displayName: { type: 'string' },
                realName: { type: 'string' },
                phone: { type: 'string' },
                email: { type: 'string' },
                address: { type: 'string' },
                province: { type: 'string' },
                district: { type: 'string' },
                postalCode: { type: 'string' },
                birthday: { type: 'string', format: 'date' },
                gender: { type: 'string' },
                notes: { type: 'string' },
                tags: { type: 'string' },
                totalOrders: { type: 'number' },
                totalSpent: { type: 'number' },
                availablePoints: { type: 'number' },
                tier: { type: 'string' },
                membershipLevel: { type: 'string' },
                customerScore: { type: 'number' },
                medicalConditions: { type: 'string' },
                drugAllergies: { type: 'string' },
                currentMedications: { type: 'string' },
                emergencyContact: { type: 'string' },
                bloodType: { type: 'string' },
                lastOrderAt: { type: 'string', format: 'date-time' },
                lastInteractionAt: { type: 'string', format: 'date-time' },
                isBlocked: { type: 'boolean' },
                createdAt: { type: 'string', format: 'date-time' },
                updatedAt: { type: 'string', format: 'date-time' },
              },
            },
          },
        },
        404: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            error: {
              type: 'object',
              properties: {
                code: { type: 'string' },
                message: { type: 'string' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const { id } = request.params as { id: string };
      const user = request.user as any;
      const lineAccountId = user?.lineAccountId || '1';

      const customer = await customerService.getCustomerById(id, lineAccountId);

      if (!customer) {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'CUSTOMER_NOT_FOUND',
            message: 'Customer not found',
          },
        });
      }

      return reply.send({
        success: true,
        data: customer,
      });
    } catch (error) {
      console.error('Customer profile error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'CUSTOMER_PROFILE_ERROR',
          message: 'Failed to retrieve customer profile',
        },
      });
    }
  });

  /**
   * GET /api/v1/customers/:id/orders - Customer order history
   * Validates: Requirements FR-3.2
   */
  fastify.get('/:id/orders', {
    preHandler: [authenticate, validateQuery(orderListQuerySchema)],
    schema: {
      tags: ['Customers'],
      summary: 'Get customer order history with payment status',
      description: 'Retrieve paginated order history for a specific customer',
      params: {
        type: 'object',
        properties: {
          id: { type: 'string' },
        },
        required: ['id'],
      },
      querystring: {
        type: 'object',
        properties: {
          page: { type: 'number', minimum: 1, default: 1 },
          limit: { type: 'number', minimum: 1, maximum: 100, default: 20 },
          sort: { type: 'string', default: 'createdAt' },
          order: { type: 'string', enum: ['asc', 'desc'], default: 'desc' },
        },
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                data: {
                  type: 'array',
                  items: {
                    type: 'object',
                    properties: {
                      id: { type: 'string' },
                      odooOrderId: { type: 'string' },
                      status: { type: 'string' },
                      totalAmount: { type: 'number' },
                      currency: { type: 'string' },
                      orderDate: { type: 'string', format: 'date-time' },
                      deliveryDate: { type: 'string', format: 'date-time' },
                      createdAt: { type: 'string', format: 'date-time' },
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
        404: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            error: {
              type: 'object',
              properties: {
                code: { type: 'string' },
                message: { type: 'string' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const { id } = request.params as { id: string };
      const query = request.query as any;
      const user = request.user as any;
      const lineAccountId = user?.lineAccountId || '1';

      const pagination = {
        page: query.page,
        limit: Math.min(query.limit, 100),
        sort: query.sort,
        order: query.order,
      };

      const result = await customerService.getCustomerOrders(id, lineAccountId, pagination);

      return reply.send({
        success: true,
        data: result,
      });
    } catch (error) {
      console.error('Customer orders error:', error);

      if (error instanceof Error && error.message === 'Customer not found') {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'CUSTOMER_NOT_FOUND',
            message: 'Customer not found',
          },
        });
      }

      return reply.status(500).send({
        success: false,
        error: {
          code: 'CUSTOMER_ORDERS_ERROR',
          message: 'Failed to retrieve customer orders',
        },
      });
    }
  });

  /**
   * PUT /api/v1/customers/:id/line - Update LINE account connection
   * Validates: Requirements FR-3.3
   */
  fastify.put('/:id/line', {
    preHandler: [authenticate],
    schema: {
      tags: ['Customers'],
      summary: 'Update LINE account connection for customer',
      description: 'Link or unlink a LINE user ID to a customer profile',
      params: {
        type: 'object',
        properties: {
          id: { type: 'string' },
        },
        required: ['id'],
      },
      body: {
        type: 'object',
        properties: {
          lineUserId: { type: ['string', 'null'], description: 'LINE user ID or null to disconnect' },
        },
        required: ['lineUserId'],
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                id: { type: 'string' },
                lineUserId: { type: 'string' },
                displayName: { type: 'string' },
                updatedAt: { type: 'string', format: 'date-time' },
              },
            },
          },
        },
        404: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            error: {
              type: 'object',
              properties: {
                code: { type: 'string' },
                message: { type: 'string' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const { id } = request.params as { id: string };
      const { lineUserId } = request.body as { lineUserId: string | null };
      const user = request.user as any;
      const lineAccountId = user?.lineAccountId || '1';

      const updatedCustomer = await customerService.updateLineConnection(
        id,
        lineAccountId,
        lineUserId
      );

      // Broadcast real-time update via WebSocket
      const webSocketService = (fastify as any).webSocketService;
      if (webSocketService) {
        webSocketService.broadcastCustomerUpdate(lineAccountId, {
          customerId: id,
          lineUserId,
          updatedAt: updatedCustomer.updatedAt,
        });
      }

      return reply.send({
        success: true,
        data: {
          id: updatedCustomer.id,
          lineUserId: updatedCustomer.lineUserId,
          displayName: updatedCustomer.displayName,
          updatedAt: updatedCustomer.updatedAt,
        },
      });
    } catch (error) {
      console.error('Update LINE connection error:', error);

      if (error instanceof Error && error.message === 'Customer not found') {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'CUSTOMER_NOT_FOUND',
            message: 'Customer not found',
          },
        });
      }

      return reply.status(500).send({
        success: false,
        error: {
          code: 'LINE_CONNECTION_UPDATE_ERROR',
          message: 'Failed to update LINE connection',
        },
      });
    }
  });

  /**
   * GET /api/v1/customers/statistics - Customer statistics
   */
  fastify.get('/statistics', {
    preHandler: [authenticate],
    schema: {
      tags: ['Customers'],
      summary: 'Get customer statistics for dashboard',
      querystring: {
        type: 'object',
        properties: {
          dateFrom: { type: 'string', format: 'date' },
          dateTo: { type: 'string', format: 'date' },
          lineAccountId: { type: 'string' },
        },
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                totalCustomers: { type: 'number' },
                newCustomers: { type: 'number' },
                activeCustomers: { type: 'number' },
                lineConnected: { type: 'number' },
                averageOrderValue: { type: 'number' },
                topTiers: { type: 'object' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const query = request.query as any;
      const user = request.user as any;
      const lineAccountId = query.lineAccountId || user?.lineAccountId || '1';

      const dateFrom = query.dateFrom ? new Date(query.dateFrom) : undefined;
      const dateTo = query.dateTo ? new Date(query.dateTo) : undefined;

      const statistics = await customerService.getCustomerStatistics(
        lineAccountId,
        dateFrom,
        dateTo
      );

      return reply.send({
        success: true,
        data: statistics,
      });
    } catch (error) {
      console.error('Customer statistics error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'CUSTOMER_STATISTICS_ERROR',
          message: 'Failed to retrieve customer statistics',
        },
      });
    }
  });
}
