import { defineConfig } from 'vitest/config'

// Test configuration constants
export const TEST_CONFIG = {
  // Database configuration
  database: {
    testTimeout: 30000,
    connectionTimeout: 10000,
    maxConnections: 10,
  },
  
  // Property-based testing configuration
  propertyTesting: {
    numRuns: 100,
    timeout: 5000,
    verbose: false,
    seed: 42, // For reproducible tests
  },
  
  // Performance testing thresholds
  performance: {
    apiResponseTime: 300, // milliseconds
    databaseQueryTime: 100, // milliseconds
    memoryUsage: 50 * 1024 * 1024, // 50MB
  },
  
  // Mock configuration
  mocks: {
    redis: {
      defaultTTL: 3600,
      maxMemory: '100mb',
    },
    odoo: {
      timeout: 5000,
      retryAttempts: 3,
    },
    line: {
      timeout: 3000,
      retryAttempts: 2,
    },
  },
  
  // Test data limits
  testData: {
    maxOrders: 1000,
    maxPaymentSlips: 500,
    maxWebhooks: 2000,
    maxUsers: 100,
  },
}

// Test environment setup
export const setupTestEnvironment = () => {
  // Set test environment variables
  process.env.NODE_ENV = 'test'
  process.env.LOG_LEVEL = 'error'
  process.env.REDIS_URL = 'redis://localhost:6379/15' // Use test database
  
  // Configure test timeouts
  vi.setConfig({
    testTimeout: TEST_CONFIG.database.testTimeout,
    hookTimeout: TEST_CONFIG.database.connectionTimeout,
  })
}

// Test utilities for consistent test data
export const testConstants = {
  // Fixed UUIDs for predictable tests
  TEST_USER_ID: '123e4567-e89b-12d3-a456-426614174000',
  TEST_LINE_ACCOUNT_ID: '123e4567-e89b-12d3-a456-426614174001',
  TEST_ORDER_ID: '123e4567-e89b-12d3-a456-426614174002',
  TEST_PAYMENT_SLIP_ID: '123e4567-e89b-12d3-a456-426614174003',
  
  // Test dates
  TEST_DATE_START: new Date('2024-01-01T00:00:00Z'),
  TEST_DATE_END: new Date('2024-01-31T23:59:59Z'),
  
  // Test amounts
  TEST_AMOUNTS: {
    SMALL: 100.00,
    MEDIUM: 1000.00,
    LARGE: 10000.00,
    VERY_LARGE: 100000.00,
  },
  
  // Test credentials
  TEST_CREDENTIALS: {
    VALID_PASSWORD: 'TestPassword123!',
    INVALID_PASSWORD: 'wrong_password',
    WEAK_PASSWORD: '123',
  },
}

// Performance testing utilities
export const performanceHelpers = {
  measureExecutionTime: async <T>(fn: () => Promise<T>): Promise<{ result: T; duration: number }> => {
    const start = performance.now()
    const result = await fn()
    const duration = performance.now() - start
    return { result, duration }
  },
  
  measureMemoryUsage: <T>(fn: () => T): { result: T; memoryDelta: number } => {
    const before = process.memoryUsage().heapUsed
    const result = fn()
    const after = process.memoryUsage().heapUsed
    const memoryDelta = after - before
    return { result, memoryDelta }
  },
  
  assertPerformance: (duration: number, threshold: number, operation: string) => {
    if (duration > threshold) {
      throw new Error(`Performance assertion failed: ${operation} took ${duration}ms, expected < ${threshold}ms`)
    }
  },
}

// Test data cleanup utilities
export const cleanupHelpers = {
  cleanupDatabase: async (prisma: any) => {
    // Clean up in reverse dependency order
    await prisma.auditLog.deleteMany()
    await prisma.userSession.deleteMany()
    await prisma.webhookLog.deleteMany()
    await prisma.paymentSlip.deleteMany()
    await prisma.orderItem.deleteMany()
    await prisma.order.deleteMany()
    await prisma.user.deleteMany()
  },
  
  cleanupRedis: async (redis: any) => {
    await redis.flushdb()
  },
  
  resetMocks: () => {
    vi.clearAllMocks()
    vi.resetAllMocks()
  },
}

// Test assertion helpers
export const assertionHelpers = {
  assertValidUUID: (uuid: string) => {
    const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
    expect(uuid).toMatch(uuidRegex)
  },
  
  assertValidDate: (date: any) => {
    expect(date).toBeInstanceOf(Date)
    expect(date.getTime()).not.toBeNaN()
  },
  
  assertValidAmount: (amount: number) => {
    expect(amount).toBeTypeOf('number')
    expect(amount).toBeGreaterThanOrEqual(0)
    expect(Number.isFinite(amount)).toBe(true)
  },
  
  assertValidPercentage: (percentage: number) => {
    expect(percentage).toBeTypeOf('number')
    expect(percentage).toBeGreaterThanOrEqual(0)
    expect(percentage).toBeLessThanOrEqual(1)
  },
  
  assertAPIResponse: (response: any) => {
    expect(response).toHaveProperty('success')
    expect(typeof response.success).toBe('boolean')
    
    if (response.success) {
      expect(response).toHaveProperty('data')
    } else {
      expect(response).toHaveProperty('error')
      expect(response.error).toHaveProperty('code')
      expect(response.error).toHaveProperty('message')
    }
  },
}

export default TEST_CONFIG