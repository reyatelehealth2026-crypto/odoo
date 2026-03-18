import { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { validateRequest } from '@/middleware/validation';
import { authenticate } from '@/middleware/auth';
import { loginRateLimit, refreshRateLimit, logoutRateLimit, profileRateLimit } from '@/middleware/authRateLimit';
import { auditLogin, auditTokenRefresh, auditLogout, auditProfileAccess, detectSuspiciousActivity } from '@/middleware/auditAuth';
import { JWTPayload } from '@/types';
import { AuthService } from '@/services/AuthService';
import { prisma } from '@/utils/prisma';
import { logger } from '@/utils/logger';

const loginSchema = z.object({
  username: z.string().min(1),
  password: z.string().min(1),
  lineAccountId: z.string().min(1),
});

const refreshSchema = z.object({
  refreshToken: z.string().min(1),
});

export default async function authRoutes(fastify: FastifyInstance): Promise<void> {
  const authService = new AuthService(prisma);

  // Login endpoint
  fastify.post('/login', {
    preHandler: [detectSuspiciousActivity, loginRateLimit, validateRequest(loginSchema), auditLogin],
    schema: {
      tags: ['Authentication'],
      summary: 'User login',
      body: {
        type: 'object',
        required: ['username', 'password', 'lineAccountId'],
        properties: {
          username: { type: 'string' },
          password: { type: 'string' },
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
                accessToken: { type: 'string' },
                refreshToken: { type: 'string' },
                expiresIn: { type: 'number' },
                user: {
                  type: 'object',
                  properties: {
                    id: { type: 'string' },
                    username: { type: 'string' },
                    email: { type: 'string' },
                    role: { type: 'string' },
                    lineAccountId: { type: 'string' },
                    permissions: { type: 'array', items: { type: 'string' } },
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
      const { username, password, lineAccountId } = request.body as z.infer<typeof loginSchema>;
      const ipAddress = request.ip;
      const userAgent = request.headers['user-agent'];

      const result = await authService.login(
        { username, password, lineAccountId },
        ipAddress,
        userAgent
      );

      return reply.send({
        success: true,
        data: {
          accessToken: result.tokens.accessToken,
          refreshToken: result.tokens.refreshToken,
          expiresIn: result.tokens.expiresIn,
          user: result.user,
        },
      });
    } catch (error) {
      logger.error('Login endpoint error', { error: String(error) });
      
      return reply.status(401).send({
        success: false,
        error: {
          code: 'LOGIN_FAILED',
          message: 'Invalid credentials',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  // Refresh token endpoint
  fastify.post('/refresh', {
    preHandler: [refreshRateLimit, validateRequest(refreshSchema), auditTokenRefresh],
    schema: {
      tags: ['Authentication'],
      summary: 'Refresh access token',
      body: {
        type: 'object',
        required: ['refreshToken'],
        properties: {
          refreshToken: { type: 'string' },
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
                accessToken: { type: 'string' },
                refreshToken: { type: 'string' },
                expiresIn: { type: 'number' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const { refreshToken } = request.body as z.infer<typeof refreshSchema>;
      const ipAddress = request.ip;

      const tokens = await authService.refreshToken(refreshToken, ipAddress);

      return reply.send({
        success: true,
        data: {
          accessToken: tokens.accessToken,
          refreshToken: tokens.refreshToken,
          expiresIn: tokens.expiresIn,
        },
      });
    } catch (error) {
      logger.error('Token refresh endpoint error', { error: String(error) });
      
      return reply.status(401).send({
        success: false,
        error: {
          code: 'REFRESH_FAILED',
          message: 'Invalid refresh token',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  // Logout endpoint
  fastify.post('/logout', {
    preHandler: [logoutRateLimit, authenticate, auditLogout],
    schema: {
      tags: ['Authentication'],
      summary: 'User logout',
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            message: { type: 'string' },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const user = (request as any).user as JWTPayload;
      const authHeader = request.headers.authorization;
      const token = authHeader?.replace('Bearer ', '') || '';

      await authService.logout(token, user.userId);

      // Add token to Redis blacklist
      const payload = require('jsonwebtoken').decode(token) as JWTPayload;
      if (payload && payload.exp) {
        const ttl = payload.exp - Math.floor(Date.now() / 1000);
        if (ttl > 0) {
          await request.server.redis.setex(`blacklist:${token}`, ttl, 'true');
        }
      }

      return reply.send({
        success: true,
        message: 'Logged out successfully',
      });
    } catch (error) {
      logger.error('Logout endpoint error', { error: String(error) });
      
      return reply.status(500).send({
        success: false,
        error: {
          code: 'LOGOUT_FAILED',
          message: 'Logout failed',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });

  // Get current user profile
  fastify.get('/profile', {
    preHandler: [profileRateLimit, authenticate, auditProfileAccess],
    schema: {
      tags: ['Authentication'],
      summary: 'Get current user profile',
      response: {
        200: {
          type: 'object',
          properties: {
            success: { type: 'boolean' },
            data: {
              type: 'object',
              properties: {
                id: { type: 'string' },
                username: { type: 'string' },
                email: { type: 'string' },
                role: { type: 'string' },
                lineAccountId: { type: 'string' },
                permissions: { type: 'array', items: { type: 'string' } },
                lastLoginAt: { type: 'string', format: 'date-time' },
              },
            },
          },
        },
      },
    },
  }, async (request, reply) => {
    try {
      const user = (request as any).user as JWTPayload;
      const profile = await authService.getUserProfile(user.userId);

      return reply.send({
        success: true,
        data: profile,
      });
    } catch (error) {
      logger.error('Profile endpoint error', { error: String(error) });
      
      return reply.status(500).send({
        success: false,
        error: {
          code: 'PROFILE_FETCH_FAILED',
          message: 'Failed to fetch user profile',
          timestamp: new Date().toISOString(),
        },
      });
    }
  });
}