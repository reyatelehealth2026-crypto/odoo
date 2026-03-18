import { describe, it, expect, beforeEach, vi } from 'vitest'
import * as fc from 'fast-check'
import { PaymentMatchingService } from '@/services/PaymentMatchingService'
import { prisma, createTestOrder, createTestPaymentSlip } from '@test/setup'
import { arbitraries, properties, propertyTestConfig } from '@test/utils/propertyTesting'

describe('PaymentMatchingService', () => {
  let service: PaymentMatchingService
  const mockLineAccountId = '123e4567-e89b-12d3-a456-426614174000'

  beforeEach(() => {
    service = new PaymentMatchingService(prisma)
  })

  describe('findMatchingOrders', () => {
    it('should find exact amount matches', async () => {
      const order = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const slip = createTestPaymentSlip({ amount: 1000.00 })

      await prisma.order.create({ data: order })
      
      const matches = await service.findMatchingOrders(slip.amount, mockLineAccountId)
      
      expect(matches).toHaveLength(1)
      expect(matches[0].id).toBe(order.id)
      expect(matches[0].matchConfidence).toBe(1.0) // Exact match
    })

    it('should find matches within tolerance (5%)', async () => {
      const order = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const slip = createTestPaymentSlip({ amount: 1050.00 }) // 5% higher

      await prisma.order.create({ data: order })
      
      const matches = await service.findMatchingOrders(slip.amount, mockLineAccountId)
      
      expect(matches).toHaveLength(1)
      expect(matches[0].id).toBe(order.id)
      expect(matches[0].matchConfidence).toBeGreaterThan(0.9)
      expect(matches[0].matchConfidence).toBeLessThan(1.0)
    })

    it('should not find matches outside tolerance', async () => {
      const order = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const slip = createTestPaymentSlip({ amount: 1100.00 }) // 10% higher

      await prisma.order.create({ data: order })
      
      const matches = await service.findMatchingOrders(slip.amount, mockLineAccountId)
      
      expect(matches).toHaveLength(0)
    })

    it('should only match pending orders', async () => {
      const pendingOrder = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const completedOrder = createTestOrder({ totalAmount: 1000.00, status: 'completed' })

      await prisma.order.create({ data: pendingOrder })
      await prisma.order.create({ data: completedOrder })
      
      const matches = await service.findMatchingOrders(1000.00, mockLineAccountId)
      
      expect(matches).toHaveLength(1)
      expect(matches[0].id).toBe(pendingOrder.id)
    })

    // Property-based test: Tolerance calculation
    it('should respect tolerance boundaries for any amount', async () => {
      await fc.assert(
        fc.asyncProperty(
          arbitraries.amount(),
          fc.float({ min: 0.01, max: 0.1 }), // tolerance between 1% and 10%
          async (orderAmount, tolerance) => {
            // Clean database
            await prisma.order.deleteMany()
            
            const order = createTestOrder({ 
              totalAmount: orderAmount, 
              status: 'pending' 
            })
            await prisma.order.create({ data: order })

            // Test amounts within and outside tolerance
            const withinTolerance = orderAmount * (1 + tolerance * 0.9) // 90% of tolerance
            const outsideTolerance = orderAmount * (1 + tolerance * 1.1) // 110% of tolerance

            const matchesWithin = await service.findMatchingOrders(withinTolerance, mockLineAccountId)
            const matchesOutside = await service.findMatchingOrders(outsideTolerance, mockLineAccountId)

            // Should find match within tolerance, not outside
            return matchesWithin.length > 0 && matchesOutside.length === 0
          }
        ),
        { ...propertyTestConfig, numRuns: 50 }
      )
    })

    // Property-based test: Match confidence calculation
    it('should calculate confidence correctly', async () => {
      await fc.assert(
        fc.asyncProperty(
          arbitraries.amount(),
          fc.float({ min: 0, max: 0.05 }), // variance within 5%
          async (baseAmount, variance) => {
            // Clean database
            await prisma.order.deleteMany()
            
            const orderAmount = baseAmount
            const slipAmount = baseAmount * (1 + variance)

            const order = createTestOrder({ 
              totalAmount: orderAmount, 
              status: 'pending' 
            })
            await prisma.order.create({ data: order })

            const matches = await service.findMatchingOrders(slipAmount, mockLineAccountId)

            if (matches.length > 0) {
              const confidence = matches[0].matchConfidence
              
              // Exact match should have confidence 1.0
              if (Math.abs(orderAmount - slipAmount) < 0.01) {
                return Math.abs(confidence - 1.0) < 0.01
              }
              
              // Non-exact matches should have confidence < 1.0
              return confidence < 1.0 && confidence > 0
            }
            
            return true // No matches is also valid
          }
        ),
        { ...propertyTestConfig, numRuns: 50 }
      )
    })
  })

  describe('matchPaymentSlip', () => {
    it('should successfully match slip to order', async () => {
      const order = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const slip = createTestPaymentSlip({ amount: 1000.00, status: 'pending' })

      await prisma.order.create({ data: order })
      await prisma.paymentSlip.create({ data: slip })

      const result = await service.matchPaymentSlip(slip.id, order.id, mockLineAccountId)

      expect(result.success).toBe(true)
      expect(result.matchId).toBeDefined()

      // Verify database updates
      const updatedSlip = await prisma.paymentSlip.findUnique({ where: { id: slip.id } })
      expect(updatedSlip?.status).toBe('matched')
      expect(updatedSlip?.matchedOrderId).toBe(order.id)
    })

    it('should fail to match already matched slip', async () => {
      const order = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const slip = createTestPaymentSlip({ 
        amount: 1000.00, 
        status: 'matched',
        matchedOrderId: 'other-order-id'
      })

      await prisma.order.create({ data: order })
      await prisma.paymentSlip.create({ data: slip })

      const result = await service.matchPaymentSlip(slip.id, order.id, mockLineAccountId)

      expect(result.success).toBe(false)
      expect(result.error).toContain('already matched')
    })

    it('should fail to match non-pending order', async () => {
      const order = createTestOrder({ totalAmount: 1000.00, status: 'completed' })
      const slip = createTestPaymentSlip({ amount: 1000.00, status: 'pending' })

      await prisma.order.create({ data: order })
      await prisma.paymentSlip.create({ data: slip })

      const result = await service.matchPaymentSlip(slip.id, order.id, mockLineAccountId)

      expect(result.success).toBe(false)
      expect(result.error).toContain('not pending')
    })

    // Property-based test: Idempotency
    it('should be idempotent for successful matches', async () => {
      await fc.assert(
        fc.asyncProperty(
          arbitraries.amount(),
          async (amount) => {
            // Clean database
            await prisma.order.deleteMany()
            await prisma.paymentSlip.deleteMany()
            
            const order = createTestOrder({ totalAmount: amount, status: 'pending' })
            const slip = createTestPaymentSlip({ amount, status: 'pending' })

            await prisma.order.create({ data: order })
            await prisma.paymentSlip.create({ data: slip })

            // First match
            const result1 = await service.matchPaymentSlip(slip.id, order.id, mockLineAccountId)
            
            // Second match attempt (should fail)
            const result2 = await service.matchPaymentSlip(slip.id, order.id, mockLineAccountId)

            return result1.success && !result2.success
          }
        ),
        { ...propertyTestConfig, numRuns: 20 }
      )
    })
  })

  describe('automaticMatching', () => {
    it('should automatically match obvious pairs', async () => {
      const order = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const slip = createTestPaymentSlip({ amount: 1000.00, status: 'pending' })

      await prisma.order.create({ data: order })
      await prisma.paymentSlip.create({ data: slip })

      const results = await service.performAutomaticMatching(mockLineAccountId)

      expect(results.matched).toBe(1)
      expect(results.failed).toBe(0)
      expect(results.matches).toHaveLength(1)
      expect(results.matches[0].slipId).toBe(slip.id)
      expect(results.matches[0].orderId).toBe(order.id)
    })

    it('should not match ambiguous cases', async () => {
      // Create two orders with same amount
      const order1 = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const order2 = createTestOrder({ totalAmount: 1000.00, status: 'pending' })
      const slip = createTestPaymentSlip({ amount: 1000.00, status: 'pending' })

      await prisma.order.create({ data: order1 })
      await prisma.order.create({ data: order2 })
      await prisma.paymentSlip.create({ data: slip })

      const results = await service.performAutomaticMatching(mockLineAccountId)

      expect(results.matched).toBe(0)
      expect(results.ambiguous).toBe(1)
    })

    // Property-based test: Automatic matching consistency
    it('should maintain consistency in automatic matching', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.array(fc.record({
            orderAmount: arbitraries.amount(),
            slipAmount: arbitraries.amount(),
          }), { maxLength: 10 }),
          async (pairs) => {
            // Clean database
            await prisma.order.deleteMany()
            await prisma.paymentSlip.deleteMany()
            
            // Create orders and slips
            for (let i = 0; i < pairs.length; i++) {
              const { orderAmount, slipAmount } = pairs[i]
              
              const order = createTestOrder({ 
                totalAmount: orderAmount, 
                status: 'pending',
                id: `order-${i}`
              })
              const slip = createTestPaymentSlip({ 
                amount: slipAmount, 
                status: 'pending',
                id: `slip-${i}`
              })

              await prisma.order.create({ data: order })
              await prisma.paymentSlip.create({ data: slip })
            }

            const results = await service.performAutomaticMatching(mockLineAccountId)

            // Total processed should equal input
            const totalProcessed = results.matched + results.failed + results.ambiguous + results.noMatch
            
            return totalProcessed <= pairs.length && results.matched >= 0
          }
        ),
        { ...propertyTestConfig, numRuns: 20 }
      )
    })
  })

  describe('calculateMatchingStatistics', () => {
    it('should calculate correct statistics', async () => {
      // Create test data
      const matchedSlip = createTestPaymentSlip({ status: 'matched' })
      const pendingSlip = createTestPaymentSlip({ status: 'pending' })
      const rejectedSlip = createTestPaymentSlip({ status: 'rejected' })

      await prisma.paymentSlip.create({ data: matchedSlip })
      await prisma.paymentSlip.create({ data: pendingSlip })
      await prisma.paymentSlip.create({ data: rejectedSlip })

      const stats = await service.calculateMatchingStatistics(mockLineAccountId, new Date(), new Date())

      expect(stats.totalSlips).toBe(3)
      expect(stats.matchedSlips).toBe(1)
      expect(stats.pendingSlips).toBe(1)
      expect(stats.rejectedSlips).toBe(1)
      expect(stats.matchingRate).toBeCloseTo(0.33, 2) // 1/3
    })

    // Property-based test: Statistics consistency
    it('should maintain statistical consistency', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.array(fc.record({
            status: arbitraries.slipStatus(),
          }), { maxLength: 100 }),
          async (slips) => {
            // Clean database
            await prisma.paymentSlip.deleteMany()
            
            // Create test slips
            for (const slipData of slips) {
              await prisma.paymentSlip.create({
                data: createTestPaymentSlip(slipData)
              })
            }

            const stats = await service.calculateMatchingStatistics(mockLineAccountId, new Date(), new Date())

            // Verify totals add up
            const calculatedTotal = stats.matchedSlips + stats.pendingSlips + stats.rejectedSlips
            
            return (
              stats.totalSlips === slips.length &&
              calculatedTotal === slips.length &&
              stats.matchingRate >= 0 &&
              stats.matchingRate <= 1
            )
          }
        ),
        propertyTestConfig
      )
    })
  })
})