import { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { authenticate } from '@/middleware/auth';
import { validateQuery } from '@/middleware/validation';
import { OrderService } from '@/services/OrderService';
import { PrismaClient } from '@prisma/client';

// Validation schemas
const orderListQuerySchema = z.object({
  page: z.string().transform(Number).optional().default('1'),
  limit: z.string().transform(Number).optional().default('20'),
  sort: z.string().optional().default('createdAt'),
  order: z.enum(['asc', 'desc']).optional().default('desc'),
  status: z.string().optional(),
  customerRef: z.string().optional(),
  customerName: z.string().optional(),
  dateFrom: z.string().optional(),
  dateTo: z.string().optional(),
  search: z.string().optional(),
  lineAccountId: z.string().optional(),
});

export default async function orderRoutes(fastify: FastifyInstance): Promise<void> {
  const prisma = new PrismaClient();
  const orderService = new OrderService(prisma);

  // GET /api/v1/orders - List orders with pagination
  fastify.get('/', {
    preHandler: [authenticate, validateQuery(orderListQuerySchema)],
    schema: {
      tags: ['Orders'],
      summary: 'List orders with pagination and filtering',
      querystring: {
        type: 'object',
        properties: {
          page: { type: 'number', minimum: 1, default: 1 },
          limit: { type: 'number', minimum: 1, maximum: 100, default: 20 },
          sort: { type: 'string', default: 'createdAt' },
          order: { type: 'string', enum: ['asc', 'desc'], default: 'desc' },
          status: { type: 'string' },
          customerRef: { type: 'string' },
          customerName: { type: 'string' },
          dateFrom: { type: 'string', format: 'date' },
          dateTo: { type: 'string', format: 'date' },
          search: { type: 'string' },
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
                      odooOrderId: { type: 'string' },
                      customerRef: { type: 'string' },
                      customerName: { type: 'string' },
                      status: { type: 'string' },
                      totalAmount: { type: 'number' },
                      currency: { type: 'string' },
                      orderDate: { type: 'string', format: 'date-time' },
                      createdAt: { type: 'string', format: 'date-time' },
                      timeline: {
                        type: 'array',
                        items: {
                          type: 'object',
                          properties: {
                            id: { type: 'string' },
                            status: { type: 'string' },
                            changedAt: { type: 'string', format: 'date-time' },
                            source: { type: 'string' }
                          }
                        }
                      }
                    }
                  }
                },
                meta: {
                  type: 'object',
                  properties: {
                    page: { type: 'number' },
                    limit: { type: 'number' },
                    total: { type: 'number' },
                    totalPages: { type: 'number' }
                  }
                }
              }
            }
          }
        }
      }
    },
  }, async (request, reply) => {
    try {
      const query = request.query as any;
      const user = request.user as any;
      const lineAccountId = query.lineAccountId || user?.lineAccountId || '1';

      // Parse filters
      const filters = {
        ...(query.status && { status: query.status.split(',') }),
        ...(query.customerRef && { customerRef: query.customerRef }),
        ...(query.customerName && { customerName: query.customerName }),
        ...(query.dateFrom && { dateFrom: new Date(query.dateFrom) }),
        ...(query.dateTo && { dateTo: new Date(query.dateTo) }),
        ...(query.search && { search: query.search }),
      };

      const pagination = {
        page: query.page,
        limit: Math.min(query.limit, 100), // Cap at 100
        sort: query.sort,
        order: query.order,
      };

      const result = await orderService.getOrders(lineAccountId, filters, pagination);

      return reply.send({
        success: true,
        data: result,
      });
    } catch (error) {
      console.error('Orders list error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'ORDERS_LIST_ERROR',
          message: 'Failed to retrieve orders',
        },
      });
    }
  });

  // GET /api/v1/orders/:id - Get specific order details
  fastify.get('/:id', {
    preHandler: [authenticate],
    schema: {
      tags: ['Orders'],
      summary: 'Get specific order details with timeline',
      params: {
        type: 'object',
        properties: {
          id: { type: 'string' }
        },
        required: ['id']
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
                odooOrderId: { type: 'string' },
                customerRef: { type: 'string' },
                customerName: { type: 'string' },
                status: { type: 'string' },
                totalAmount: { type: 'number' },
                currency: { type: 'string' },
                orderDate: { type: 'string', format: 'date-time' },
                deliveryDate: { type: 'string', format: 'date-time' },
                notes: { type: 'string' },
                createdAt: { type: 'string', format: 'date-time' },
                updatedAt: { type: 'string', format: 'date-time' },
                timeline: {
                  type: 'array',
                  items: {
                    type: 'object',
                    properties: {
                      id: { type: 'string' },
                      status: { type: 'string' },
                      previousStatus: { type: 'string' },
                      notes: { type: 'string' },
                      changedBy: { type: 'string' },
                      changedAt: { type: 'string', format: 'date-time' },
                      source: { type: 'string' }
                    }
                  }
                }
              }
            }
          }
        },
        404: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            error: {
              type: 'object',
              properties: {
                code: { type: 'string' },
                message: { type: 'string' }
              }
            }
          }
        }
      }
    },
  }, async (request, reply) => {
    try {
      const { id } = request.params as { id: string };
      const user = request.user as any;
      const lineAccountId = user?.lineAccountId || '1';

      const order = await orderService.getOrderById(id, lineAccountId);

      if (!order) {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'ORDER_NOT_FOUND',
            message: 'Order not found',
          },
        });
      }

      return reply.send({
        success: true,
        data: order,
      });
    } catch (error) {
      console.error('Order details error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'ORDER_DETAILS_ERROR',
          message: 'Failed to retrieve order details',
        },
      });
    }
  });

  // PUT /api/v1/orders/:id/status - Update order status
  fastify.put('/:id/status', {
    preHandler: [authenticate],
    schema: {
      tags: ['Orders'],
      summary: 'Update order status with audit trail',
      params: {
        type: 'object',
        properties: {
          id: { type: 'string' }
        },
        required: ['id']
      },
      body: {
        type: 'object',
        properties: {
          status: { type: 'string' },
          notes: { type: 'string' },
          notifyCustomer: { type: 'boolean', default: false }
        },
        required: ['status']
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
                status: { type: 'string' },
                updatedAt: { type: 'string', format: 'date-time' },
                timeline: {
                  type: 'array',
                  items: {
                    type: 'object',
                    properties: {
                      id: { type: 'string' },
                      status: { type: 'string' },
                      changedAt: { type: 'string', format: 'date-time' },
                      source: { type: 'string' }
                    }
                  }
                }
              }
            }
          }
        }
      }
    },
  }, async (request, reply) => {
    try {
      const { id } = request.params as { id: string };
      const { status, notes, notifyCustomer } = request.body as any;
      const user = request.user as any;
      const lineAccountId = user?.lineAccountId || '1';

      const updatedOrder = await orderService.updateOrderStatus(
        id,
        lineAccountId,
        status,
        notes,
        user?.userId
      );

      // TODO: Implement customer notification if notifyCustomer is true
      if (notifyCustomer) {
        // This would integrate with the notification system
        console.log(`TODO: Notify customer about order ${id} status change to ${status}`);
      }

      // Broadcast real-time update via WebSocket
      const webSocketService = (fastify as any).webSocketService;
      if (webSocketService) {
        webSocketService.broadcastOrderUpdate(lineAccountId, {
          orderId: id,
          status,
          updatedAt: updatedOrder.updatedAt,
        });
      }

      return reply.send({
        success: true,
        data: {
          id: updatedOrder.id,
          status: updatedOrder.status,
          updatedAt: updatedOrder.updatedAt,
          timeline: updatedOrder.timeline,
        },
      });
    } catch (error) {
      console.error('Order status update error:', error);
      
      if (error instanceof Error && error.message === 'Order not found') {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'ORDER_NOT_FOUND',
            message: 'Order not found',
          },
        });
      }

      return reply.status(500).send({
        success: false,
        error: {
          code: 'ORDER_STATUS_UPDATE_ERROR',
          message: 'Failed to update order status',
        },
      });
    }
  });

  // GET /api/v1/orders/:id/timeline - Order status timeline
  fastify.get('/:id/timeline', {
    preHandler: [authenticate],
    schema: {
      tags: ['Orders'],
      summary: 'Get order status timeline',
      params: {
        type: 'object',
        properties: {
          id: { type: 'string' }
        },
        required: ['id']
      },
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
                  orderId: { type: 'string' },
                  status: { type: 'string' },
                  previousStatus: { type: 'string' },
                  notes: { type: 'string' },
                  changedBy: { type: 'string' },
                  changedAt: { type: 'string', format: 'date-time' },
                  source: { type: 'string' }
                }
              }
            }
          }
        }
      }
    },
  }, async (request, reply) => {
    try {
      const { id } = request.params as { id: string };
      const user = request.user as any;
      const lineAccountId = user?.lineAccountId || '1';

      // First verify the order exists and belongs to the account
      const order = await orderService.getOrderById(id, lineAccountId);
      if (!order) {
        return reply.status(404).send({
          success: false,
          error: {
            code: 'ORDER_NOT_FOUND',
            message: 'Order not found',
          },
        });
      }

      const timeline = await orderService.getOrderTimeline(id);

      return reply.send({
        success: true,
        data: timeline,
      });
    } catch (error) {
      console.error('Order timeline error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'ORDER_TIMELINE_ERROR',
          message: 'Failed to retrieve order timeline',
        },
      });
    }
  });

  // POST /api/v1/orders/search - Advanced order search
  fastify.post('/search', {
    preHandler: [authenticate],
    schema: {
      tags: ['Orders'],
      summary: 'Advanced order search',
      body: {
        type: 'object',
        properties: {
          query: { type: 'string' },
          page: { type: 'number', minimum: 1, default: 1 },
          limit: { type: 'number', minimum: 1, maximum: 100, default: 20 },
          status: { type: 'string' },
          dateFrom: { type: 'string', format: 'date' },
          dateTo: { type: 'string', format: 'date' },
        },
        required: ['query']
      },
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                data: { type: 'array' },
                meta: {
                  type: 'object',
                  properties: {
                    page: { type: 'number' },
                    limit: { type: 'number' },
                    total: { type: 'number' },
                    totalPages: { type: 'number' }
                  }
                }
              }
            }
          }
        }
      }
    },
  }, async (request, reply) => {
    try {
      const { query, page = 1, limit = 20, status, dateFrom, dateTo } = request.body as any;
      const user = request.user as any;
      const lineAccountId = user?.lineAccountId || '1';

      const filters = {
        ...(status && { status: [status] }),
        ...(dateFrom && { dateFrom: new Date(dateFrom) }),
        ...(dateTo && { dateTo: new Date(dateTo) }),
      };

      const pagination = {
        page,
        limit: Math.min(limit, 100),
        sort: 'createdAt',
        order: 'desc' as const,
      };

      const result = await orderService.searchOrders(lineAccountId, query, filters, pagination);

      return reply.send({
        success: true,
        data: result,
      });
    } catch (error) {
      console.error('Order search error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'ORDER_SEARCH_ERROR',
          message: 'Failed to search orders',
        },
      });
    }
  });

  // GET /api/v1/orders/statistics - Order statistics for dashboard
  fastify.get('/statistics', {
    preHandler: [authenticate],
    schema: {
      tags: ['Orders'],
      summary: 'Get order statistics for dashboard',
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
                totalOrders: { type: 'number' },
                totalValue: { type: 'number' },
                statusBreakdown: { type: 'object' },
                averageOrderValue: { type: 'number' },
                topCustomers: {
                  type: 'array',
                  items: {
                    type: 'object',
                    properties: {
                      customerName: { type: 'string' },
                      orderCount: { type: 'number' },
                      totalValue: { type: 'number' }
                    }
                  }
                }
              }
            }
          }
        }
      }
    },
  }, async (request, reply) => {
    try {
      const query = request.query as any;
      const user = request.user as any;
      const lineAccountId = query.lineAccountId || user?.lineAccountId || '1';

      const dateFrom = query.dateFrom ? new Date(query.dateFrom) : undefined;
      const dateTo = query.dateTo ? new Date(query.dateTo) : undefined;

      const statistics = await orderService.getOrderStatistics(lineAccountId, dateFrom, dateTo);

      return reply.send({
        success: true,
        data: statistics,
      });
    } catch (error) {
      console.error('Order statistics error:', error);
      return reply.status(500).send({
        success: false,
        error: {
          code: 'ORDER_STATISTICS_ERROR',
          message: 'Failed to retrieve order statistics',
        },
      });
    }
  });
}