import { FastifyRequest, FastifyReply } from 'fastify';
import { JWTPayload } from '@/types';
import { prisma } from '@/utils/prisma';
import { logger } from '@/utils/logger';
import { AuthService } from '@/services/AuthService';

export const authenticate = async (
  request: FastifyRequest,
  reply: FastifyReply
): Promise<void> => {
  try {
    const authHeader = request.headers.authorization;
    if (!authHeader || !authHeader.startsWith('Bearer ')) {
      return reply.status(401).send({
        success: false,
        error: {
          code: 'MISSING_TOKEN',
          message: 'Authorization token required',
          timestamp: new Date().toISOString(),
        },
      });
    }

    const token = authHeader.replace('Bearer ', '');
    const authService = new AuthService(prisma);
    
    // Validate token and get payload
    const payload = await authService.validateToken(token);
    
    // Check if token is blacklisted in Redis
    const blacklisted = await request.server.redis.get(`blacklist:${token}`);
    if (blacklisted) {
      return reply.status(401).send({
        success: false,
        error: {
          code: 'TOKEN_REVOKED',
          message: 'Token has been revoked',
          timestamp: new Date().toISOString(),
        },
      });
    }

    // Verify user session exists and is active
    const tokenHash = require('crypto').createHash('sha256').update(token).digest('hex');
    const session = await prisma.userSession.findFirst({
      where: {
        userId: payload.userId,
        tokenHash,
        isActive: true,
        expiresAt: {
          gt: new Date(),
        },
      },
    });

    if (!session) {
      return reply.status(401).send({
        success: false,
        error: {
          code: 'INVALID_SESSION',
          message: 'Session not found or expired',
          timestamp: new Date().toISOString(),
        },
      });
    }

    // Update last activity
    await prisma.userSession.update({
      where: { id: session.id },
      data: { lastActivity: new Date() },
    });

    // Attach user to request
    (request as any).user = payload;
  } catch (error) {
    logger.error('Authentication failed', { error: String(error) });
    
    return reply.status(401).send({
      success: false,
      error: {
        code: 'INVALID_TOKEN',
        message: 'Invalid or expired token',
        timestamp: new Date().toISOString(),
      },
    });
  }
};

// Legacy authorize function - use RBAC middleware instead
export const authorize = (permissions: string[]) => {
  return async (request: FastifyRequest, reply: FastifyReply): Promise<void> => {
    const user = (request as any).user as JWTPayload;
    const userPermissions = user.permissions || [];
    
    const hasPermission = permissions.some(permission => 
      userPermissions.includes(permission) || userPermissions.includes('*')
    );

    if (!hasPermission) {
      return reply.status(403).send({
        success: false,
        error: {
          code: 'INSUFFICIENT_PERMISSIONS',
          message: 'Access denied',
          timestamp: new Date().toISOString(),
        },
      });
    }
  };
};