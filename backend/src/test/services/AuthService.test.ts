import { describe, it, expect, beforeEach, vi } from 'vitest'
import * as fc from 'fast-check'
import { AuthService } from '@/services/AuthService'
import { prisma, createTestUser } from '@test/setup'
import { arbitraries, properties, propertyTestConfig } from '@test/utils/propertyTesting'
import bcrypt from 'bcryptjs'
import jwt from 'jsonwebtoken'

describe('AuthService', () => {
  let service: AuthService
  const JWT_SECRET = 'test-secret'
  const JWT_REFRESH_SECRET = 'test-refresh-secret'

  beforeEach(() => {
    service = new AuthService(prisma, JWT_SECRET, JWT_REFRESH_SECRET)
  })

  describe('hashPassword', () => {
    it('should hash password correctly', async () => {
      const password = 'testpassword123'
      const hash = await service.hashPassword(password)
      
      expect(hash).not.toBe(password)
      expect(hash).toMatch(/^\$2[aby]\$\d+\$/)
      
      // Verify hash can be validated
      const isValid = await bcrypt.compare(password, hash)
      expect(isValid).toBe(true)
    })

    // Property-based test: Password hashing consistency
    it('should produce different hashes for same password', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.string({ minLength: 8, maxLength: 100 }),
          async (password) => {
            const hash1 = await service.hashPassword(password)
            const hash2 = await service.hashPassword(password)
            
            // Hashes should be different (due to salt)
            const differentHashes = hash1 !== hash2
            
            // But both should validate the original password
            const valid1 = await bcrypt.compare(password, hash1)
            const valid2 = await bcrypt.compare(password, hash2)
            
            return differentHashes && valid1 && valid2
          }
        ),
        { ...propertyTestConfig, numRuns: 20 }
      )
    })
  })

  describe('validatePassword', () => {
    it('should validate correct password', async () => {
      const password = 'testpassword123'
      const hash = await service.hashPassword(password)
      
      const isValid = await service.validatePassword(password, hash)
      expect(isValid).toBe(true)
    })

    it('should reject incorrect password', async () => {
      const password = 'testpassword123'
      const wrongPassword = 'wrongpassword'
      const hash = await service.hashPassword(password)
      
      const isValid = await service.validatePassword(wrongPassword, hash)
      expect(isValid).toBe(false)
    })

    // Property-based test: Password validation properties
    it('should validate passwords correctly for any input', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.string({ minLength: 8, maxLength: 50 }),
          fc.string({ minLength: 8, maxLength: 50 }),
          async (correctPassword, wrongPassword) => {
            // Skip if passwords are the same
            if (correctPassword === wrongPassword) return true
            
            const hash = await service.hashPassword(correctPassword)
            
            const correctValidation = await service.validatePassword(correctPassword, hash)
            const wrongValidation = await service.validatePassword(wrongPassword, hash)
            
            return correctValidation === true && wrongValidation === false
          }
        ),
        { ...propertyTestConfig, numRuns: 20 }
      )
    })
  })

  describe('generateTokens', () => {
    it('should generate valid JWT tokens', async () => {
      const user = createTestUser()
      const tokens = await service.generateTokens(user)
      
      expect(tokens).toHaveProperty('accessToken')
      expect(tokens).toHaveProperty('refreshToken')
      expect(typeof tokens.accessToken).toBe('string')
      expect(typeof tokens.refreshToken).toBe('string')
      
      // Verify tokens can be decoded
      const accessPayload = jwt.verify(tokens.accessToken, JWT_SECRET) as any
      const refreshPayload = jwt.verify(tokens.refreshToken, JWT_REFRESH_SECRET) as any
      
      expect(accessPayload.userId).toBe(user.id)
      expect(accessPayload.role).toBe(user.role)
      expect(refreshPayload.userId).toBe(user.id)
    })

    it('should generate tokens with correct expiration', async () => {
      const user = createTestUser()
      const tokens = await service.generateTokens(user)
      
      const accessPayload = jwt.verify(tokens.accessToken, JWT_SECRET) as any
      const refreshPayload = jwt.verify(tokens.refreshToken, JWT_REFRESH_SECRET) as any
      
      const now = Math.floor(Date.now() / 1000)
      
      // Access token should expire in 15 minutes (900 seconds)
      expect(accessPayload.exp - accessPayload.iat).toBe(900)
      
      // Refresh token should expire in 7 days (604800 seconds)
      expect(refreshPayload.exp - refreshPayload.iat).toBe(604800)
    })

    // Property-based test: Token generation consistency
    it('should generate unique tokens for each call', async () => {
      await fc.assert(
        fc.asyncProperty(
          arbitraries.jwtPayload(),
          async (userPayload) => {
            const user = createTestUser({
              id: userPayload.userId,
              role: userPayload.role,
              lineAccountId: userPayload.lineAccountId,
              permissions: userPayload.permissions,
            })
            
            const tokens1 = await service.generateTokens(user)
            const tokens2 = await service.generateTokens(user)
            
            // Tokens should be different (due to different iat)
            return (
              tokens1.accessToken !== tokens2.accessToken &&
              tokens1.refreshToken !== tokens2.refreshToken
            )
          }
        ),
        { ...propertyTestConfig, numRuns: 20 }
      )
    })
  })

  describe('validateToken', () => {
    it('should validate valid access token', async () => {
      const user = createTestUser()
      const tokens = await service.generateTokens(user)
      
      const payload = await service.validateToken(tokens.accessToken, 'access')
      
      expect(payload).toBeDefined()
      expect(payload?.userId).toBe(user.id)
      expect(payload?.role).toBe(user.role)
    })

    it('should reject invalid token', async () => {
      const invalidToken = 'invalid.token.here'
      
      const payload = await service.validateToken(invalidToken, 'access')
      
      expect(payload).toBeNull()
    })

    it('should reject expired token', async () => {
      const user = createTestUser()
      
      // Generate token with very short expiration
      const expiredToken = jwt.sign(
        { userId: user.id, role: user.role },
        JWT_SECRET,
        { expiresIn: '1ms' }
      )
      
      // Wait for expiration
      await new Promise(resolve => setTimeout(resolve, 10))
      
      const payload = await service.validateToken(expiredToken, 'access')
      
      expect(payload).toBeNull()
    })

    // Property-based test: Token validation round-trip
    it('should validate any token it generates', async () => {
      await fc.assert(
        fc.asyncProperty(
          arbitraries.jwtPayload(),
          async (userPayload) => {
            const user = createTestUser({
              id: userPayload.userId,
              role: userPayload.role,
              lineAccountId: userPayload.lineAccountId,
              permissions: userPayload.permissions,
            })
            
            const tokens = await service.generateTokens(user)
            
            const accessPayload = await service.validateToken(tokens.accessToken, 'access')
            const refreshPayload = await service.validateToken(tokens.refreshToken, 'refresh')
            
            return (
              accessPayload !== null &&
              refreshPayload !== null &&
              accessPayload.userId === user.id &&
              refreshPayload.userId === user.id
            )
          }
        ),
        { ...propertyTestConfig, numRuns: 20 }
      )
    })
  })

  describe('authenticateUser', () => {
    it('should authenticate user with correct credentials', async () => {
      const password = 'testpassword123'
      const hashedPassword = await service.hashPassword(password)
      
      const user = createTestUser({ 
        username: 'testuser',
        passwordHash: hashedPassword 
      })
      
      await prisma.user.create({ data: user })
      
      const result = await service.authenticateUser('testuser', password)
      
      expect(result.success).toBe(true)
      expect(result.user?.id).toBe(user.id)
      expect(result.tokens).toBeDefined()
    })

    it('should reject user with incorrect password', async () => {
      const password = 'testpassword123'
      const wrongPassword = 'wrongpassword'
      const hashedPassword = await service.hashPassword(password)
      
      const user = createTestUser({ 
        username: 'testuser',
        passwordHash: hashedPassword 
      })
      
      await prisma.user.create({ data: user })
      
      const result = await service.authenticateUser('testuser', wrongPassword)
      
      expect(result.success).toBe(false)
      expect(result.error).toContain('Invalid credentials')
    })

    it('should reject non-existent user', async () => {
      const result = await service.authenticateUser('nonexistent', 'password')
      
      expect(result.success).toBe(false)
      expect(result.error).toContain('Invalid credentials')
    })
  })

  describe('refreshTokens', () => {
    it('should refresh tokens with valid refresh token', async () => {
      const user = createTestUser()
      await prisma.user.create({ data: user })
      
      const originalTokens = await service.generateTokens(user)
      
      // Wait a moment to ensure different iat
      await new Promise(resolve => setTimeout(resolve, 1000))
      
      const result = await service.refreshTokens(originalTokens.refreshToken)
      
      expect(result.success).toBe(true)
      expect(result.tokens).toBeDefined()
      expect(result.tokens?.accessToken).not.toBe(originalTokens.accessToken)
      expect(result.tokens?.refreshToken).not.toBe(originalTokens.refreshToken)
    })

    it('should reject invalid refresh token', async () => {
      const result = await service.refreshTokens('invalid.refresh.token')
      
      expect(result.success).toBe(false)
      expect(result.error).toContain('Invalid refresh token')
    })
  })

  describe('revokeToken', () => {
    it('should revoke token successfully', async () => {
      const user = createTestUser()
      const tokens = await service.generateTokens(user)
      
      await service.revokeToken(tokens.accessToken)
      
      // Token should now be invalid
      const payload = await service.validateToken(tokens.accessToken, 'access')
      expect(payload).toBeNull()
    })

    // Property-based test: Token revocation idempotency
    it('should be idempotent for token revocation', async () => {
      await fc.assert(
        fc.asyncProperty(
          arbitraries.jwtPayload(),
          async (userPayload) => {
            const user = createTestUser({
              id: userPayload.userId,
              role: userPayload.role,
            })
            
            const tokens = await service.generateTokens(user)
            
            // Revoke token multiple times
            await service.revokeToken(tokens.accessToken)
            await service.revokeToken(tokens.accessToken)
            await service.revokeToken(tokens.accessToken)
            
            // Should still be revoked
            const payload = await service.validateToken(tokens.accessToken, 'access')
            return payload === null
          }
        ),
        { ...propertyTestConfig, numRuns: 10 }
      )
    })
  })

  describe('checkPermissions', () => {
    it('should allow access with correct permissions', () => {
      const user = createTestUser({ 
        role: 'admin',
        permissions: ['view_dashboard', 'manage_orders'] 
      })
      
      const hasPermission = service.checkPermissions(user, ['view_dashboard'])
      expect(hasPermission).toBe(true)
    })

    it('should deny access without required permissions', () => {
      const user = createTestUser({ 
        role: 'staff',
        permissions: ['view_dashboard'] 
      })
      
      const hasPermission = service.checkPermissions(user, ['admin_access'])
      expect(hasPermission).toBe(false)
    })

    it('should allow super_admin all permissions', () => {
      const user = createTestUser({ 
        role: 'super_admin',
        permissions: [] 
      })
      
      const hasPermission = service.checkPermissions(user, ['admin_access', 'manage_orders'])
      expect(hasPermission).toBe(true)
    })

    // Property-based test: Permission hierarchy
    it('should respect role hierarchy', async () => {
      await fc.assert(
        fc.property(
          fc.constantFrom('super_admin', 'admin', 'staff'),
          fc.array(fc.constantFrom('view_dashboard', 'manage_orders', 'admin_access')),
          (role, requiredPermissions) => {
            const user = createTestUser({ role, permissions: [] })
            const hasPermission = service.checkPermissions(user, requiredPermissions)
            
            // Super admin should always have access
            if (role === 'super_admin') {
              return hasPermission === true
            }
            
            // Others should not have admin_access without explicit permission
            if (requiredPermissions.includes('admin_access') && role !== 'super_admin') {
              return hasPermission === false
            }
            
            return true
          }
        ),
        propertyTestConfig
      )
    })
  })
})