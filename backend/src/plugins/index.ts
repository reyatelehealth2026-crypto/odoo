import { FastifyInstance } from 'fastify';
import cors from '@fastify/cors';
import helmet from '@fastify/helmet';
import rateLimit from '@fastify/rate-limit';
import jwt from '@fastify/jwt';
import redis from '@fastify/redis';
import swagger from '@fastify/swagger';
import swaggerUi from '@fastify/swagger-ui';
import { config } from '@/config/config';
import { errorHandler, notFoundHandler } from '@/middleware/errorHandler';
import { requestLogger } from '@/middleware/requestLogger';
import { responseFormatter } from '@/middleware/responseFormatter';
import { authenticate } from '@/middleware/auth';
import { requirePermission, Permission } from '@/middleware/rbac';

export const registerPlugins = async (fastify: FastifyInstance): Promise<void> => {
  // Request logging and response formatting
  await fastify.register(async (fastify) => {
    fastify.addHook('onRequest', requestLogger);
    fastify.addHook('onRequest', responseFormatter);
  });

  // Error handling
  fastify.setErrorHandler(errorHandler);
  fastify.setNotFoundHandler(notFoundHandler);

  // Security plugins
  await fastify.register(helmet, {
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'self'"],
        styleSrc: ["'self'", "'unsafe-inline'"],
        scriptSrc: ["'self'"],
        imgSrc: ["'self'", 'data:', 'https:'],
        connectSrc: ["'self'", 'wss:', 'ws:'],
        fontSrc: ["'self'", 'https://fonts.gstatic.com'],
        objectSrc: ["'none'"],
        mediaSrc: ["'self'"],
        frameSrc: ["'none'"],
      },
    },
  });

  await fastify.register(cors, {
    origin: config.CORS_ORIGIN.split(','),
    credentials: true,
    methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization', 'X-Line-Account-ID', 'X-Request-ID'],
  });

  // Rate limiting with different limits for different endpoints
  await fastify.register(rateLimit, {
    max: config.RATE_LIMIT_MAX,
    timeWindow: config.RATE_LIMIT_WINDOW_MS,
    keyGenerator: (request) => {
      // Use user ID if authenticated, otherwise IP
      const user = (request as any).user;
      return user ? `user:${user.userId}` : `ip:${request.ip}`;
    },
    errorResponseBuilder: (_request, context) => {
      return {
        success: false,
        error: {
          code: 'RATE_LIMIT_EXCEEDED',
          message: `Too many requests. Limit: ${context.max} per ${Math.floor(context.ttl / 1000)} seconds`,
          timestamp: new Date().toISOString(),
        },
      };
    },
  });

  // JWT authentication
  await fastify.register(jwt, {
    secret: config.JWT_SECRET,
    sign: {
      expiresIn: config.JWT_EXPIRES_IN,
    },
  });

  // Redis connection with error handling
  await fastify.register(redis, {
    url: config.REDIS_URL,
    password: config.REDIS_PASSWORD,
    lazyConnect: true,
  });

  // Register authentication and authorization decorators
  fastify.decorate('authenticate', authenticate);
  fastify.decorate('requirePermission', requirePermission);

  // API documentation
  if (config.NODE_ENV === 'development') {
    await fastify.register(swagger, {
      openapi: {
        info: {
          title: 'Odoo Dashboard API',
          description: 'Modern API for Odoo Dashboard modernization',
          version: '1.0.0',
        },
        servers: [
          {
            url: `http://localhost:${config.PORT}${config.API_PREFIX}`,
            description: 'Development server',
          },
        ],
        components: {
          securitySchemes: {
            bearerAuth: {
              type: 'http',
              scheme: 'bearer',
              bearerFormat: 'JWT',
            },
          },
        },
      },
    });

    await fastify.register(swaggerUi, {
      routePrefix: '/docs',
      uiConfig: {
        docExpansion: 'list',
        deepLinking: false,
      },
      staticCSP: true,
      transformStaticCSP: (header) => header,
    });
  }
};