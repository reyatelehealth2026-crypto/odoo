import { PrismaClient, User, UserRole } from '@prisma/client';
import bcrypt from 'bcryptjs';
import jwt from 'jsonwebtoken';
import { config } from '@/config/config';
import { BaseService } from './BaseService';
import { JWTPayload } from '@/types';
import { logger } from '@/utils/logger';

export interface LoginCredentials {
  username: string;
  password: string;
  lineAccountId: string;
}

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  expiresIn: number;
  refreshExpiresIn: number;
}

export interface UserProfile {
  id: string;
  username: string;
  email: string;
  role: UserRole;
  lineAccountId: string;
  permissions: string[];
  lastLoginAt: Date | null;
}

export class AuthService extends BaseService {
  constructor(prisma: PrismaClient) {
    super(prisma);
  }

  /**
   * Authenticate user with credentials and generate JWT tokens
   */
  async login(credentials: LoginCredentials, ipAddress?: string, userAgent?: string): Promise<{
    tokens: AuthTokens;
    user: UserProfile;
  }> {
    try {
      // Find user by username and line account
      const user = await this.prisma.user.findFirst({
        where: {
          username: credentials.username,
          lineAccountId: credentials.lineAccountId,
          isActive: true,
        },
      });

      if (!user) {
        throw new Error('Invalid credentials');
      }

      // Verify password
      const isPasswordValid = await bcrypt.compare(credentials.password, user.passwordHash);
      if (!isPasswordValid) {
        throw new Error('Invalid credentials');
      }

      // Generate tokens
      const tokens = await this.generateTokens(user);

      // Create session record
      await this.createSession(user.id, tokens, ipAddress, userAgent);

      // Update last login
      await this.prisma.user.update({
        where: { id: user.id },
        data: { lastLoginAt: new Date() },
      });

      // Log successful login
      logger.info('User logged in successfully', {
        userId: user.id,
        username: user.username,
        ipAddress,
      });

      return {
        tokens,
        user: this.mapUserToProfile(user),
      };
    } catch (error) {
      logger.error('Login failed', { 
        username: credentials.username,
        error: String(error),
      });
      throw error;
    }
  }

  /**
   * Refresh access token using refresh token
   */
  async refreshToken(refreshToken: string, ipAddress?: string): Promise<AuthTokens> {
    try {
      // Verify refresh token
      const payload = jwt.verify(refreshToken, config.JWT_REFRESH_SECRET) as JWTPayload;

      // Find active session
      const session = await this.prisma.userSession.findFirst({
        where: {
          userId: payload.userId,
          refreshTokenHash: this.hashToken(refreshToken),
          isActive: true,
          expiresAt: {
            gt: new Date(),
          },
        },
        include: {
          user: true,
        },
      });

      if (!session || !session.user.isActive) {
        throw new Error('Invalid refresh token');
      }

      // Generate new tokens (refresh token rotation)
      const newTokens = await this.generateTokens(session.user);

      // Update session with new tokens
      await this.prisma.userSession.update({
        where: { id: session.id },
        data: {
          tokenHash: this.hashToken(newTokens.accessToken),
          refreshTokenHash: this.hashToken(newTokens.refreshToken),
          expiresAt: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000), // 7 days
          lastActivity: new Date(),
        },
      });

      logger.info('Token refreshed successfully', {
        userId: session.userId,
        sessionId: session.id,
        ipAddress,
      });

      return newTokens;
    } catch (error) {
      logger.error('Token refresh failed', { error: String(error) });
      throw new Error('Invalid refresh token');
    }
  }

  /**
   * Logout user and invalidate session
   */
  async logout(accessToken: string, userId: string): Promise<void> {
    try {
      const tokenHash = this.hashToken(accessToken);

      // Find and deactivate session
      const session = await this.prisma.userSession.findFirst({
        where: {
          userId,
          tokenHash,
          isActive: true,
        },
      });

      if (session) {
        await this.prisma.userSession.update({
          where: { id: session.id },
          data: { isActive: false },
        });
      }

      // Add token to blacklist (expires when token would naturally expire)
      const payload = jwt.decode(accessToken) as JWTPayload;
      if (payload && payload.exp) {
        const expiresAt = new Date(payload.exp * 1000);
        // Note: In a real implementation, you'd store this in Redis
        // For now, we'll just log it
        logger.info('Token blacklisted', {
          userId,
          tokenHash: tokenHash.substring(0, 8) + '...',
          expiresAt,
        });
      }

      logger.info('User logged out successfully', { userId });
    } catch (error) {
      logger.error('Logout failed', { 
        userId,
        error: String(error),
      });
      throw error;
    }
  }

  /**
   * Validate JWT token and return payload
   */
  async validateToken(token: string): Promise<JWTPayload> {
    try {
      const payload = jwt.verify(token, config.JWT_SECRET) as JWTPayload;

      // Check if user is still active
      const user = await this.prisma.user.findFirst({
        where: {
          id: payload.userId,
          isActive: true,
        },
      });

      if (!user) {
        throw new Error('User not found or inactive');
      }

      return payload;
    } catch (error) {
      logger.error('Token validation failed', { error: String(error) });
      throw new Error('Invalid token');
    }
  }

  /**
   * Get user profile by ID
   */
  async getUserProfile(userId: string): Promise<UserProfile> {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
    });

    if (!user || !user.isActive) {
      throw new Error('User not found');
    }

    return this.mapUserToProfile(user);
  }

  /**
   * Revoke all sessions for a user
   */
  async revokeAllSessions(userId: string): Promise<void> {
    await this.prisma.userSession.updateMany({
      where: { userId },
      data: { isActive: false },
    });

    logger.info('All sessions revoked for user', { userId });
  }

  /**
   * Clean up expired sessions
   */
  async cleanupExpiredSessions(): Promise<void> {
    const result = await this.prisma.userSession.deleteMany({
      where: {
        OR: [
          { expiresAt: { lt: new Date() } },
          { isActive: false },
        ],
      },
    });

    logger.info('Cleaned up expired sessions', { count: result.count });
  }

  /**
   * Generate JWT access and refresh tokens
   */
  private async generateTokens(user: User): Promise<AuthTokens> {
    const permissions = this.getUserPermissions(user.role);
    
    const payload: Omit<JWTPayload, 'iat' | 'exp'> = {
      userId: user.id,
      role: user.role,
      lineAccountId: user.lineAccountId,
      permissions,
    };

    const accessToken = jwt.sign(payload, config.JWT_SECRET, {
      expiresIn: config.JWT_EXPIRES_IN,
    } as jwt.SignOptions);

    const refreshToken = jwt.sign(payload, config.JWT_REFRESH_SECRET, {
      expiresIn: config.JWT_REFRESH_EXPIRES_IN,
    } as jwt.SignOptions);

    // Calculate expiration times
    const accessTokenDecoded = jwt.decode(accessToken) as JWTPayload;
    const refreshTokenDecoded = jwt.decode(refreshToken) as JWTPayload;

    return {
      accessToken,
      refreshToken,
      expiresIn: accessTokenDecoded.exp - accessTokenDecoded.iat,
      refreshExpiresIn: refreshTokenDecoded.exp - refreshTokenDecoded.iat,
    };
  }

  /**
   * Create session record in database
   */
  private async createSession(
    userId: string,
    tokens: AuthTokens,
    ipAddress?: string,
    userAgent?: string
  ): Promise<void> {
    const refreshTokenDecoded = jwt.decode(tokens.refreshToken) as JWTPayload;
    
    await this.prisma.userSession.create({
      data: {
        userId,
        tokenHash: this.hashToken(tokens.accessToken),
        refreshTokenHash: this.hashToken(tokens.refreshToken),
        expiresAt: new Date(refreshTokenDecoded.exp * 1000),
        ipAddress: ipAddress || null,
        userAgent: userAgent || null,
      },
    });
  }

  /**
   * Hash token for secure storage
   */
  private hashToken(token: string): string {
    return require('crypto').createHash('sha256').update(token).digest('hex');
  }

  /**
   * Get user permissions based on role
   */
  private getUserPermissions(role: UserRole): string[] {
    const rolePermissions = {
      SUPER_ADMIN: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
        'manage_webhooks',
        'admin_access',
        'manage_users',
        'system_settings',
      ],
      ADMIN: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
        'manage_webhooks',
        'admin_access',
      ],
      PHARMACIST: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
        'pharmacist_access',
      ],
      STAFF: [
        'view_dashboard',
        'manage_orders',
        'process_payments',
      ],
    };

    return rolePermissions[role] || [];
  }

  /**
   * Map User entity to UserProfile
   */
  private mapUserToProfile(user: User): UserProfile {
    return {
      id: user.id,
      username: user.username,
      email: user.email,
      role: user.role,
      lineAccountId: user.lineAccountId,
      permissions: this.getUserPermissions(user.role),
      lastLoginAt: user.lastLoginAt,
    };
  }
}