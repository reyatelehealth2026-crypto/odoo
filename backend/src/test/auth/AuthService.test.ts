import { describe, it, expect, beforeAll, afterAll, beforeEach } from 'vitest';
import { PrismaClient } from '@prisma/client';
import { AuthService } from '@/services/AuthService';
import bcrypt from 'bcryptjs';

describe('AuthService Unit Tests', () => {
  let prisma: PrismaClient;
  let authService: AuthService;
  let testUserId: string;

  beforeAll(async () => {
    prisma = new PrismaClient();
    authService = new AuthService(prisma);
    
    // Create a test user
    const passwordHash = await bcrypt.hash('testpassword123', 10);
    const testUser = await prisma.user.create({
      data: {
        username: 'test_auth_user',
        email: 'test@auth.com',
        passwordHash,
        role: 'STAFF',
        lineAccountId: 'test_line_account',
        isActive: true,
      },
    });
    testUserId = testUser.id;
  });

  afterAll(async () => {
    // Clean up test data
    await prisma.userSession.deleteMany({
      where: { userId: testUserId },
    });
    await prisma.user.delete({
      where: { id: testUserId },
    });
    await prisma.$disconnect();
  });

  beforeEach(async () => {
    // Clean up sessions before each test
    await prisma.userSession.deleteMany({
      where: { userId: testUserId },
    });
  });

  describe('Authentication', () => {
    it('should successfully authenticate valid credentials', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      const result = await authService.login(credentials, '127.0.0.1', 'test-agent');

      expect(result.tokens.accessToken).toBeTruthy();
      expect(result.tokens.refreshToken).toBeTruthy();
      expect(result.user.username).toBe('test_auth_user');
      expect(result.user.role).toBe('STAFF');
      expect(result.user.permissions).toContain('view_dashboard');
    });

    it('should reject invalid credentials', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'wrongpassword',
        lineAccountId: 'test_line_account',
      };

      await expect(authService.login(credentials, '127.0.0.1', 'test-agent'))
        .rejects.toThrow('Invalid credentials');
    });

    it('should reject non-existent user', async () => {
      const credentials = {
        username: 'nonexistent_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      await expect(authService.login(credentials, '127.0.0.1', 'test-agent'))
        .rejects.toThrow('Invalid credentials');
    });
  });

  describe('Token Management', () => {
    it('should refresh tokens successfully', async () => {
      // First login
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      const loginResult = await authService.login(credentials, '127.0.0.1', 'test-agent');
      
      // Then refresh
      const refreshResult = await authService.refreshToken(
        loginResult.tokens.refreshToken,
        '127.0.0.1'
      );

      expect(refreshResult.accessToken).toBeTruthy();
      expect(refreshResult.refreshToken).toBeTruthy();
      expect(refreshResult.accessToken).not.toBe(loginResult.tokens.accessToken);
      expect(refreshResult.refreshToken).not.toBe(loginResult.tokens.refreshToken);
    });

    it('should validate tokens correctly', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      const result = await authService.login(credentials, '127.0.0.1', 'test-agent');
      const payload = await authService.validateToken(result.tokens.accessToken);

      expect(payload.userId).toBe(testUserId);
      expect(payload.role).toBe('STAFF');
      expect(payload.lineAccountId).toBe('test_line_account');
    });

    it('should reject invalid tokens', async () => {
      await expect(authService.validateToken('invalid.token.here'))
        .rejects.toThrow('Invalid token');
    });
  });

  describe('Session Management', () => {
    it('should create session on login', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      await authService.login(credentials, '127.0.0.1', 'test-agent');

      const sessions = await prisma.userSession.findMany({
        where: { userId: testUserId, isActive: true },
      });

      expect(sessions).toHaveLength(1);
      expect(sessions[0].ipAddress).toBe('127.0.0.1');
      expect(sessions[0].userAgent).toBe('test-agent');
    });

    it('should deactivate session on logout', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      const result = await authService.login(credentials, '127.0.0.1', 'test-agent');
      await authService.logout(result.tokens.accessToken, testUserId);

      const activeSessions = await prisma.userSession.findMany({
        where: { userId: testUserId, isActive: true },
      });

      expect(activeSessions).toHaveLength(0);
    });

    it('should revoke all sessions', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      // Create multiple sessions
      await authService.login(credentials, '127.0.0.1', 'agent1');
      await authService.login(credentials, '127.0.0.1', 'agent2');
      await authService.login(credentials, '127.0.0.1', 'agent3');

      let activeSessions = await prisma.userSession.findMany({
        where: { userId: testUserId, isActive: true },
      });
      expect(activeSessions).toHaveLength(3);

      // Revoke all sessions
      await authService.revokeAllSessions(testUserId);

      activeSessions = await prisma.userSession.findMany({
        where: { userId: testUserId, isActive: true },
      });
      expect(activeSessions).toHaveLength(0);
    });
  });

  describe('User Profile', () => {
    it('should return user profile', async () => {
      const profile = await authService.getUserProfile(testUserId);

      expect(profile.id).toBe(testUserId);
      expect(profile.username).toBe('test_auth_user');
      expect(profile.email).toBe('test@auth.com');
      expect(profile.role).toBe('STAFF');
      expect(profile.lineAccountId).toBe('test_line_account');
      expect(Array.isArray(profile.permissions)).toBe(true);
    });

    it('should reject invalid user ID', async () => {
      await expect(authService.getUserProfile('invalid-user-id'))
        .rejects.toThrow('User not found');
    });
  });

  describe('Permission System', () => {
    it('should assign correct permissions for STAFF role', async () => {
      const profile = await authService.getUserProfile(testUserId);
      
      expect(profile.permissions).toContain('view_dashboard');
      expect(profile.permissions).toContain('manage_orders');
      expect(profile.permissions).toContain('process_payments');
      expect(profile.permissions).not.toContain('admin_access');
      expect(profile.permissions).not.toContain('manage_users');
    });

    it('should include permissions in JWT token', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      const result = await authService.login(credentials, '127.0.0.1', 'test-agent');
      const payload = await authService.validateToken(result.tokens.accessToken);

      expect(Array.isArray(payload.permissions)).toBe(true);
      expect(payload.permissions).toContain('view_dashboard');
      expect(payload.permissions).toContain('manage_orders');
      expect(payload.permissions).toContain('process_payments');
    });
  });

  describe('Session Cleanup', () => {
    it('should clean up expired sessions', async () => {
      const credentials = {
        username: 'test_auth_user',
        password: 'testpassword123',
        lineAccountId: 'test_line_account',
      };

      // Create a session
      await authService.login(credentials, '127.0.0.1', 'test-agent');

      // Manually expire the session
      await prisma.userSession.updateMany({
        where: { userId: testUserId },
        data: { expiresAt: new Date(Date.now() - 1000) }, // 1 second ago
      });

      // Run cleanup
      await authService.cleanupExpiredSessions();

      // Verify session was removed
      const sessions = await prisma.userSession.findMany({
        where: { userId: testUserId },
      });

      expect(sessions).toHaveLength(0);
    });
  });
});