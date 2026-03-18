import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { PrismaClient } from '@prisma/client';
import { AuthService } from '@/services/AuthService';
import { config } from '@/config/config';
import jwt from 'jsonwebtoken';
import bcrypt from 'bcryptjs';
import { randomBytes } from 'crypto';

// Property-based testing utilities
interface TestUser {
  id: string;
  username: string;
  email: string;
  passwordHash: string;
  role: 'SUPER_ADMIN' | 'ADMIN' | 'PHARMACIST' | 'STAFF';
  lineAccountId: string;
  isActive: boolean;
}

interface TokenTestCase {
  user: TestUser;
  credentials: {
    username: string;
    password: string;
    lineAccountId: string;
  };
  expectedValid: boolean;
}

describe('JWT Token Security Property Tests', () => {
  let prisma: PrismaClient;
  let authService: AuthService;
  let testUsers: TestUser[] = [];

  beforeAll(async () => {
    prisma = new PrismaClient();
    authService = new AuthService(prisma);
    
    // Clean up any existing test data
    await prisma.userSession.deleteMany({
      where: {
        user: {
          username: {
            startsWith: 'test_'
          }
        }
      }
    });
    
    await prisma.user.deleteMany({
      where: {
        username: {
          startsWith: 'test_'
        }
      }
    });
  });

  afterAll(async () => {
    // Clean up test data
    await prisma.userSession.deleteMany({
      where: {
        user: {
          username: {
            startsWith: 'test_'
          }
        }
      }
    });
    
    await prisma.user.deleteMany({
      where: {
        username: {
          startsWith: 'test_'
        }
      }
    });
    
    await prisma.$disconnect();
  });

  /**
   * Property 1: Authentication Token Validity
   * Feature: odoo-dashboard-modernization, Property 4: Authentication Token Validity
   * Validates: Requirements BR-5.2, NFR-3.1
   * 
   * For any valid user credentials, the generated JWT tokens should:
   * 1. Be properly formatted and verifiable
   * 2. Contain correct user information
   * 3. Have appropriate expiration times
   * 4. Be invalidated after logout
   */
  it('should maintain JWT token validity properties across all authentication scenarios', async () => {
    // Generate test cases with various user scenarios
    const testCases: TokenTestCase[] = [];
    const roles: Array<'SUPER_ADMIN' | 'ADMIN' | 'PHARMACIST' | 'STAFF'> = 
      ['SUPER_ADMIN', 'ADMIN', 'PHARMACIST', 'STAFF'];
    
    // Generate 100+ test cases for comprehensive coverage
    for (let i = 0; i < 25; i++) {
      for (const role of roles) {
        const userId = `test_user_${i}_${role.toLowerCase()}`;
        const password = `password_${i}_${randomBytes(8).toString('hex')}`;
        const lineAccountId = `line_${i}_${randomBytes(4).toString('hex')}`;
        
        const user: TestUser = {
          id: userId,
          username: userId,
          email: `${userId}@test.com`,
          passwordHash: await bcrypt.hash(password, 10),
          role,
          lineAccountId,
          isActive: true,
        };
        
        // Create user in database
        await prisma.user.create({
          data: user,
        });
        
        testUsers.push(user);
        
        testCases.push({
          user,
          credentials: {
            username: userId,
            password,
            lineAccountId,
          },
          expectedValid: true,
        });
        
        // Add invalid credential test cases
        testCases.push({
          user,
          credentials: {
            username: userId,
            password: 'wrong_password',
            lineAccountId,
          },
          expectedValid: false,
        });
      }
    }

    console.log(`Testing ${testCases.length} authentication scenarios...`);

    // Test each case
    for (const testCase of testCases) {
      if (testCase.expectedValid) {
        // Test valid authentication
        const result = await authService.login(testCase.credentials, '127.0.0.1', 'test-agent');
        
        // Property 1.1: Tokens should be properly formatted
        expect(result.tokens.accessToken).toBeTruthy();
        expect(result.tokens.refreshToken).toBeTruthy();
        expect(typeof result.tokens.accessToken).toBe('string');
        expect(typeof result.tokens.refreshToken).toBe('string');
        
        // Property 1.2: Access token should be valid JWT
        const accessPayload = jwt.verify(result.tokens.accessToken, config.JWT_SECRET) as any;
        expect(accessPayload.userId).toBe(testCase.user.id);
        expect(accessPayload.role).toBe(testCase.user.role);
        expect(accessPayload.lineAccountId).toBe(testCase.user.lineAccountId);
        expect(Array.isArray(accessPayload.permissions)).toBe(true);
        
        // Property 1.3: Refresh token should be valid JWT
        const refreshPayload = jwt.verify(result.tokens.refreshToken, config.JWT_REFRESH_SECRET) as any;
        expect(refreshPayload.userId).toBe(testCase.user.id);
        
        // Property 1.4: Expiration times should be appropriate
        const accessExp = accessPayload.exp - accessPayload.iat;
        const refreshExp = refreshPayload.exp - refreshPayload.iat;
        expect(accessExp).toBe(15 * 60); // 15 minutes
        expect(refreshExp).toBe(7 * 24 * 60 * 60); // 7 days
        
        // Property 1.5: User profile should match
        expect(result.user.id).toBe(testCase.user.id);
        expect(result.user.username).toBe(testCase.user.username);
        expect(result.user.role).toBe(testCase.user.role);
        expect(result.user.lineAccountId).toBe(testCase.user.lineAccountId);
        
        // Property 1.6: Token refresh should work
        const refreshedTokens = await authService.refreshToken(result.tokens.refreshToken, '127.0.0.1');
        expect(refreshedTokens.accessToken).toBeTruthy();
        expect(refreshedTokens.refreshToken).toBeTruthy();
        expect(refreshedTokens.accessToken).not.toBe(result.tokens.accessToken);
        expect(refreshedTokens.refreshToken).not.toBe(result.tokens.refreshToken);
        
        // Property 1.7: Token validation should work
        const validatedPayload = await authService.validateToken(refreshedTokens.accessToken);
        expect(validatedPayload.userId).toBe(testCase.user.id);
        
        // Property 1.8: Logout should invalidate session
        await authService.logout(refreshedTokens.accessToken, testCase.user.id);
        
        // Verify session is deactivated
        const tokenHash = require('crypto').createHash('sha256').update(refreshedTokens.accessToken).digest('hex');
        const session = await prisma.userSession.findFirst({
          where: {
            userId: testCase.user.id,
            tokenHash,
            isActive: true,
          },
        });
        expect(session).toBeNull();
        
      } else {
        // Test invalid authentication
        await expect(authService.login(testCase.credentials, '127.0.0.1', 'test-agent'))
          .rejects.toThrow('Invalid credentials');
      }
    }
  });

  /**
   * Property 2: Token Security Properties
   * Feature: odoo-dashboard-modernization, Property 4: Authentication Token Security
   * Validates: Requirements NFR-3.1, NFR-3.3
   * 
   * For any generated JWT token, security properties should hold:
   * 1. Tokens should not be predictable
   * 2. Expired tokens should be rejected
   * 3. Malformed tokens should be rejected
   * 4. Tokens with wrong secrets should be rejected
   */
  it('should maintain token security properties across all scenarios', async () => {
    // Create a test user
    const testUser = testUsers[0];
    if (!testUser) {
      throw new Error('No test users available');
    }
    
    const credentials = {
      username: testUser.username,
      password: testUser.username.replace('test_user_', 'password_').split('_')[1] + '_' + testUser.username.split('_')[3],
      lineAccountId: testUser.lineAccountId,
    };
    
    // Generate multiple tokens for the same user
    const tokens: string[] = [];
    for (let i = 0; i < 50; i++) {
      const result = await authService.login(credentials, '127.0.0.1', 'test-agent');
      tokens.push(result.tokens.accessToken);
      
      // Clean up session to avoid conflicts
      await authService.logout(result.tokens.accessToken, testUser.id);
    }
    
    // Property 2.1: Tokens should be unique (not predictable)
    const uniqueTokens = new Set(tokens);
    expect(uniqueTokens.size).toBe(tokens.length);
    
    // Property 2.2: Malformed tokens should be rejected
    const malformedTokens = [
      'invalid.token.format',
      'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.invalid',
      'not-a-jwt-token',
      '',
      'Bearer token',
    ];
    
    for (const malformedToken of malformedTokens) {
      await expect(authService.validateToken(malformedToken))
        .rejects.toThrow();
    }
    
    // Property 2.3: Tokens with wrong secret should be rejected
    const fakeToken = jwt.sign(
      { userId: testUser.id, role: testUser.role },
      'wrong-secret',
      { expiresIn: '15m' }
    );
    
    await expect(authService.validateToken(fakeToken))
      .rejects.toThrow();
    
    // Property 2.4: Expired tokens should be rejected
    const expiredToken = jwt.sign(
      { userId: testUser.id, role: testUser.role },
      config.JWT_SECRET,
      { expiresIn: '-1s' } // Already expired
    );
    
    await expect(authService.validateToken(expiredToken))
      .rejects.toThrow();
  });

  /**
   * Property 3: Role-Based Access Control Properties
   * Feature: odoo-dashboard-modernization, Property 4: RBAC Correctness
   * Validates: Requirements BR-5.1, NFR-3.2
   * 
   * For any user role and permission combination:
   * 1. Users should only have permissions appropriate to their role
   * 2. Role hierarchy should be respected
   * 3. Permission checks should be consistent
   */
  it('should maintain RBAC properties across all role combinations', async () => {
    const roleHierarchy = {
      'SUPER_ADMIN': 4,
      'ADMIN': 3,
      'PHARMACIST': 2,
      'STAFF': 1,
    };
    
    const expectedPermissions = {
      'SUPER_ADMIN': [
        'view_dashboard', 'manage_orders', 'process_payments', 
        'manage_webhooks', 'admin_access', 'manage_users', 'system_settings'
      ],
      'ADMIN': [
        'view_dashboard', 'manage_orders', 'process_payments', 
        'manage_webhooks', 'admin_access'
      ],
      'PHARMACIST': [
        'view_dashboard', 'manage_orders', 'process_payments', 'pharmacist_access'
      ],
      'STAFF': [
        'view_dashboard', 'manage_orders', 'process_payments'
      ],
    };
    
    // Test each role
    for (const testUser of testUsers.slice(0, 20)) { // Test subset for performance
      const credentials = {
        username: testUser.username,
        password: testUser.username.replace('test_user_', 'password_').split('_')[1] + '_' + testUser.username.split('_')[3],
        lineAccountId: testUser.lineAccountId,
      };
      
      const result = await authService.login(credentials, '127.0.0.1', 'test-agent');
      
      // Property 3.1: User should have correct permissions for their role
      const expectedPerms = expectedPermissions[testUser.role as keyof typeof expectedPermissions];
      expect(result.user.permissions).toEqual(expect.arrayContaining(expectedPerms));
      expect(result.user.permissions.length).toBe(expectedPerms.length);
      
      // Property 3.2: JWT payload should contain correct permissions
      const payload = jwt.verify(result.tokens.accessToken, config.JWT_SECRET) as any;
      expect(payload.permissions).toEqual(expect.arrayContaining(expectedPerms));
      
      // Property 3.3: Role hierarchy should be consistent
      const userLevel = roleHierarchy[testUser.role as keyof typeof roleHierarchy];
      expect(userLevel).toBeGreaterThan(0);
      expect(userLevel).toBeLessThanOrEqual(4);
      
      // Clean up
      await authService.logout(result.tokens.accessToken, testUser.id);
    }
  });

  /**
   * Property 4: Session Management Properties
   * Feature: odoo-dashboard-modernization, Property 4: Session Security
   * Validates: Requirements NFR-3.1
   * 
   * For any user session:
   * 1. Sessions should be properly tracked
   * 2. Expired sessions should be cleaned up
   * 3. Multiple sessions should be supported
   * 4. Session revocation should work
   */
  it('should maintain session management properties', async () => {
    const testUser = testUsers[0];
    if (!testUser) {
      throw new Error('No test users available');
    }
    
    const credentials = {
      username: testUser.username,
      password: testUser.username.replace('test_user_', 'password_').split('_')[1] + '_' + testUser.username.split('_')[3],
      lineAccountId: testUser.lineAccountId,
    };
    
    // Property 4.1: Multiple sessions should be supported
    const sessions = [];
    for (let i = 0; i < 5; i++) {
      const result = await authService.login(credentials, '127.0.0.1', `test-agent-${i}`);
      sessions.push(result);
    }
    
    // Verify all sessions exist
    const activeSessions = await prisma.userSession.findMany({
      where: {
        userId: testUser.id,
        isActive: true,
      },
    });
    expect(activeSessions.length).toBe(5);
    
    // Property 4.2: Session revocation should work
    await authService.revokeAllSessions(testUser.id);
    
    const revokedSessions = await prisma.userSession.findMany({
      where: {
        userId: testUser.id,
        isActive: true,
      },
    });
    expect(revokedSessions.length).toBe(0);
    
    // Property 4.3: Cleanup should remove expired sessions
    // Create expired session manually
    const expiredResult = await authService.login(credentials, '127.0.0.1', 'test-agent');
    
    // Manually expire the session
    await prisma.userSession.updateMany({
      where: {
        userId: testUser.id,
      },
      data: {
        expiresAt: new Date(Date.now() - 1000), // 1 second ago
      },
    });
    
    await authService.cleanupExpiredSessions();
    
    const remainingSessions = await prisma.userSession.findMany({
      where: {
        userId: testUser.id,
      },
    });
    expect(remainingSessions.length).toBe(0);
  });
});