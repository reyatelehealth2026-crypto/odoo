import { beforeAll, afterAll, beforeEach, afterEach } from 'vitest'
import { PrismaClient } from '@prisma/client'
import { execSync } from 'child_process'
import { randomUUID } from 'crypto'

// Test database setup
const generateDatabaseUrl = () => {
  const testId = randomUUID()
  return `mysql://root:password@localhost:3306/test_odoo_dashboard_${testId.replace(/-/g, '_')}`
}

let prisma: PrismaClient
let testDatabaseUrl: string

beforeAll(async () => {
  // Generate unique test database URL
  testDatabaseUrl = generateDatabaseUrl()
  process.env.DATABASE_URL = testDatabaseUrl
  
  // Create test database
  const dbName = testDatabaseUrl.split('/').pop()
  execSync(`mysql -u root -ppassword -e "CREATE DATABASE IF NOT EXISTS ${dbName}"`)
  
  // Initialize Prisma client
  prisma = new PrismaClient({
    datasources: {
      db: {
        url: testDatabaseUrl,
      },
    },
  })
  
  // Run migrations
  execSync('npx prisma migrate deploy', { 
    env: { ...process.env, DATABASE_URL: testDatabaseUrl },
    stdio: 'inherit'
  })
  
  // Connect to database
  await prisma.$connect()
})

afterAll(async () => {
  // Disconnect from database
  await prisma.$disconnect()
  
  // Drop test database
  const dbName = testDatabaseUrl.split('/').pop()
  execSync(`mysql -u root -ppassword -e "DROP DATABASE IF EXISTS ${dbName}"`)
})

beforeEach(async () => {
  // Clean up database before each test
  const tablenames = await prisma.$queryRaw<Array<{ TABLE_NAME: string }>>`
    SELECT TABLE_NAME from information_schema.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME != '_prisma_migrations'
  `
  
  const tables = tablenames
    .map(({ TABLE_NAME }) => TABLE_NAME)
    .filter(name => name !== '_prisma_migrations')
  
  try {
    // Disable foreign key checks
    await prisma.$executeRawUnsafe('SET FOREIGN_KEY_CHECKS = 0;')
    
    // Truncate all tables
    for (const table of tables) {
      await prisma.$executeRawUnsafe(`TRUNCATE TABLE \`${table}\`;`)
    }
    
    // Re-enable foreign key checks
    await prisma.$executeRawUnsafe('SET FOREIGN_KEY_CHECKS = 1;')
  } catch (error) {
    console.log('Error cleaning database:', error)
  }
})

afterEach(async () => {
  // Additional cleanup if needed
})

// Export test utilities
export { prisma }

// Mock Redis for tests
export const mockRedis = {
  get: vi.fn(),
  set: vi.fn(),
  del: vi.fn(),
  exists: vi.fn(),
  expire: vi.fn(),
  flushall: vi.fn(),
}

// Mock external services
export const mockOdooService = {
  authenticate: vi.fn(),
  getOrders: vi.fn(),
  getCustomers: vi.fn(),
  updateOrderStatus: vi.fn(),
}

export const mockLineAPI = {
  sendMessage: vi.fn(),
  broadcastMessage: vi.fn(),
  getUserProfile: vi.fn(),
}

// Test data factories
export const createTestUser = (overrides = {}) => ({
  id: randomUUID(),
  username: `testuser_${Date.now()}`,
  email: `test_${Date.now()}@example.com`,
  role: 'staff',
  lineAccountId: randomUUID(),
  permissions: ['view_dashboard'],
  createdAt: new Date(),
  updatedAt: new Date(),
  ...overrides,
})

export const createTestOrder = (overrides = {}) => ({
  id: randomUUID(),
  odooOrderId: `ORD_${Date.now()}`,
  customerRef: `CUST_${Date.now()}`,
  status: 'pending',
  totalAmount: 1000.00,
  currency: 'THB',
  createdAt: new Date(),
  updatedAt: new Date(),
  ...overrides,
})

export const createTestPaymentSlip = (overrides = {}) => ({
  id: randomUUID(),
  imageUrl: `https://example.com/slip_${Date.now()}.jpg`,
  amount: 1000.00,
  uploadedBy: randomUUID(),
  status: 'pending',
  createdAt: new Date(),
  ...overrides,
})