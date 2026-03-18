import { FastifyRequest, FastifyReply } from 'fastify';
import { z, ZodSchema } from 'zod';
import { logger } from '@/utils/logger';

export const validateRequest = <T extends ZodSchema>(schema: T) => {
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    try {
      const validatedData = schema.parse(request.body);
      request.body = validatedData;
    } catch (error) {
      if (error instanceof z.ZodError) {
        logger.warn('Request validation failed', {
          errors: error.errors,
          path: request.url,
          method: request.method,
        });

        return reply.status(400).send({
          success: false,
          error: {
            code: 'INVALID_REQUEST',
            message: 'Validation failed',
            details: error.errors.map(err => ({
              field: err.path.join('.'),
              message: err.message,
              code: err.code,
            })),
            timestamp: new Date().toISOString(),
          },
        });
      }
      
      logger.error('Unexpected validation error', { error: String(error) });
      return reply.status(500).send({
        success: false,
        error: {
          code: 'INTERNAL_ERROR',
          message: 'Internal server error',
          timestamp: new Date().toISOString(),
        },
      });
    }
  };
};

export const validateQuery = <T extends ZodSchema>(schema: T) => {
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    try {
      const validatedQuery = schema.parse(request.query);
      request.query = validatedQuery;
    } catch (error) {
      if (error instanceof z.ZodError) {
        return reply.status(400).send({
          success: false,
          error: {
            code: 'INVALID_QUERY',
            message: 'Query validation failed',
            details: error.errors,
            timestamp: new Date().toISOString(),
          },
        });
      }
      throw error;
    }
  };
};