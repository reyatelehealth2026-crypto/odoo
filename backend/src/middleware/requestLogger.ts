import { FastifyRequest, FastifyReply } from 'fastify';
import { logger } from '@/utils/logger';

export const requestLogger = async (
  request: FastifyRequest,
  reply: FastifyReply
): Promise<void> => {
  const start = Date.now();
  const requestId = request.id;
  const method = request.method;
  const url = request.url;
  const userAgent = request.headers['user-agent'];
  const ip = request.ip;

  // Log incoming request
  logger.info('Incoming request', {
    requestId,
    method,
    url,
    userAgent,
    ip,
  });

  // Hook into response to log completion
  reply.raw.on('finish', () => {
    const duration = Date.now() - start;
    const statusCode = reply.statusCode;

    logger.info('Request completed', {
      requestId,
      method,
      url,
      statusCode,
      duration: `${duration}ms`,
      ip,
    });

    // Log slow requests as warnings
    if (duration > 1000) {
      logger.warn('Slow request detected', {
        requestId,
        method,
        url,
        duration: `${duration}ms`,
      });
    }
  });
};