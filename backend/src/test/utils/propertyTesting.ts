import * as fc from 'fast-check'

// Custom arbitraries for domain-specific data
export const arbitraries = {
  // User data arbitraries
  userId: () => fc.uuid(),
  username: () => fc.string({ minLength: 3, maxLength: 50 }).filter(s => /^[a-zA-Z0-9_]+$/.test(s)),
  email: () => fc.emailAddress(),
  userRole: () => fc.constantFrom('super_admin', 'admin', 'pharmacist', 'staff'),
  
  // Order data arbitraries
  orderId: () => fc.uuid(),
  odooOrderId: () => fc.string({ minLength: 5, maxLength: 20 }).map(s => `ORD_${s}`),
  customerRef: () => fc.string({ minLength: 5, maxLength: 20 }).map(s => `CUST_${s}`),
  orderStatus: () => fc.constantFrom('pending', 'processing', 'completed', 'cancelled'),
  currency: () => fc.constantFrom('THB', 'USD', 'EUR'),
  amount: () => fc.float({ min: 0.01, max: 999999.99, noNaN: true }),
  
  // Payment data arbitraries
  paymentSlipId: () => fc.uuid(),
  imageUrl: () => fc.webUrl().filter(url => url.endsWith('.jpg') || url.endsWith('.png')),
  slipStatus: () => fc.constantFrom('pending', 'matched', 'rejected'),
  
  // Date arbitraries
  pastDate: () => fc.date({ max: new Date() }),
  futureDate: () => fc.date({ min: new Date() }),
  dateRange: () => fc.tuple(fc.date(), fc.date()).map(([d1, d2]) => {
    const start = d1 < d2 ? d1 : d2
    const end = d1 < d2 ? d2 : d1
    return { start, end }
  }),
  
  // Dashboard metrics arbitraries
  dashboardMetrics: () => fc.record({
    orders: fc.record({
      todayCount: fc.nat({ max: 10000 }),
      todayTotal: fc.float({ min: 0, max: 1000000, noNaN: true }),
      pendingCount: fc.nat({ max: 1000 }),
      completedCount: fc.nat({ max: 10000 }),
      averageOrderValue: fc.float({ min: 0, max: 10000, noNaN: true }),
    }),
    payments: fc.record({
      pendingSlips: fc.nat({ max: 1000 }),
      processedToday: fc.nat({ max: 1000 }),
      matchingRate: fc.float({ min: 0, max: 1, noNaN: true }),
      totalAmount: fc.float({ min: 0, max: 1000000, noNaN: true }),
    }),
    webhooks: fc.record({
      totalEvents: fc.nat({ max: 100000 }),
      successRate: fc.float({ min: 0, max: 1, noNaN: true }),
      failedEvents: fc.nat({ max: 1000 }),
      averageResponseTime: fc.float({ min: 0, max: 5000, noNaN: true }),
    }),
  }),
  
  // Authentication arbitraries
  jwtPayload: () => fc.record({
    userId: fc.uuid(),
    role: fc.constantFrom('super_admin', 'admin', 'pharmacist', 'staff'),
    lineAccountId: fc.uuid(),
    permissions: fc.array(fc.constantFrom(
      'view_dashboard', 'manage_orders', 'process_payments', 
      'manage_webhooks', 'admin_access'
    ), { minLength: 1, maxLength: 5 }),
    iat: fc.nat(),
    exp: fc.nat(),
  }),
  
  // API request arbitraries
  paginationParams: () => fc.record({
    page: fc.nat({ min: 1, max: 1000 }),
    limit: fc.nat({ min: 1, max: 100 }),
    sort: fc.constantFrom('createdAt', 'updatedAt', 'amount', 'status'),
    order: fc.constantFrom('asc', 'desc'),
  }),
  
  filterParams: () => fc.record({
    dateFrom: fc.option(fc.date()),
    dateTo: fc.option(fc.date()),
    status: fc.option(fc.array(fc.constantFrom('pending', 'processing', 'completed', 'cancelled'))),
    search: fc.option(fc.string({ minLength: 1, maxLength: 100 })),
    customerId: fc.option(fc.uuid()),
  }),
}

// Property testing helpers
export const propertyTestConfig = {
  numRuns: 100,
  timeout: 5000,
  verbose: false,
}

// Common property test patterns
export const properties = {
  // Idempotency: f(f(x)) = f(x)
  idempotent: <T>(fn: (x: T) => T, arbitrary: fc.Arbitrary<T>) =>
    fc.property(arbitrary, (x) => {
      const result1 = fn(x)
      const result2 = fn(result1)
      return JSON.stringify(result1) === JSON.stringify(result2)
    }),
  
  // Commutativity: f(x, y) = f(y, x)
  commutative: <T, R>(fn: (x: T, y: T) => R, arbitrary: fc.Arbitrary<T>) =>
    fc.property(arbitrary, arbitrary, (x, y) => {
      const result1 = fn(x, y)
      const result2 = fn(y, x)
      return JSON.stringify(result1) === JSON.stringify(result2)
    }),
  
  // Associativity: f(f(x, y), z) = f(x, f(y, z))
  associative: <T>(fn: (x: T, y: T) => T, arbitrary: fc.Arbitrary<T>) =>
    fc.property(arbitrary, arbitrary, arbitrary, (x, y, z) => {
      const result1 = fn(fn(x, y), z)
      const result2 = fn(x, fn(y, z))
      return JSON.stringify(result1) === JSON.stringify(result2)
    }),
  
  // Invariant: property that should always hold
  invariant: <T>(predicate: (x: T) => boolean, arbitrary: fc.Arbitrary<T>) =>
    fc.property(arbitrary, predicate),
  
  // Round-trip: encode(decode(x)) = x
  roundTrip: <T, U>(
    encode: (x: T) => U,
    decode: (x: U) => T,
    arbitrary: fc.Arbitrary<T>
  ) =>
    fc.property(arbitrary, (x) => {
      const encoded = encode(x)
      const decoded = decode(encoded)
      return JSON.stringify(x) === JSON.stringify(decoded)
    }),
}

// Test data generators for specific domains
export const generators = {
  // Generate valid dashboard filter combinations
  validDashboardFilters: () => fc.record({
    dateRange: arbitraries.dateRange(),
    orderStatuses: fc.array(arbitraries.orderStatus(), { minLength: 0, maxLength: 4 }),
    paymentStatuses: fc.array(arbitraries.slipStatus(), { minLength: 0, maxLength: 3 }),
    minAmount: fc.option(fc.float({ min: 0, max: 1000, noNaN: true })),
    maxAmount: fc.option(fc.float({ min: 1000, max: 1000000, noNaN: true })),
  }),
  
  // Generate valid API responses
  apiResponse: <T>(dataArbitrary: fc.Arbitrary<T>) => fc.record({
    success: fc.boolean(),
    data: fc.option(dataArbitrary),
    error: fc.option(fc.record({
      code: fc.string({ minLength: 5, maxLength: 50 }),
      message: fc.string({ minLength: 10, maxLength: 200 }),
      details: fc.option(fc.object()),
    })),
    meta: fc.option(fc.record({
      page: fc.nat({ min: 1 }),
      limit: fc.nat({ min: 1, max: 100 }),
      total: fc.nat(),
      totalPages: fc.nat({ min: 1 }),
    })),
  }),
  
  // Generate valid webhook payloads
  webhookPayload: () => fc.record({
    id: fc.uuid(),
    type: fc.constantFrom('order.created', 'order.updated', 'payment.processed', 'invoice.generated'),
    timestamp: fc.date(),
    data: fc.object(),
    signature: fc.string({ minLength: 64, maxLength: 64 }),
  }),
}

// Performance testing utilities
export const performanceProperties = {
  // Response time should be under threshold
  responseTimeUnder: (threshold: number) => 
    (fn: () => Promise<any>) => async () => {
      const start = Date.now()
      await fn()
      const duration = Date.now() - start
      return duration < threshold
    },
  
  // Memory usage should not exceed threshold
  memoryUsageUnder: (threshold: number) =>
    (fn: () => any) => () => {
      const before = process.memoryUsage().heapUsed
      fn()
      const after = process.memoryUsage().heapUsed
      const increase = after - before
      return increase < threshold
    },
}

export default {
  arbitraries,
  properties,
  generators,
  performanceProperties,
  propertyTestConfig,
}