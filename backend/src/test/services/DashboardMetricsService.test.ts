import { describe, it, expect, beforeEach, vi } from 'vitest'
import * as fc from 'fast-check'
import { DashboardMetricsService } from '@/services/DashboardMetricsService'
import { prisma, createTestOrder, createTestPaymentSlip } from '@test/setup'
import { arbitraries, properties, propertyTestConfig } from '@test/utils/propertyTesting'

describe('DashboardMetricsService', () => {
  let service: DashboardMetricsService
  const mockLineAccountId = '123e4567-e89b-12d3-a456-426614174000'

  beforeEach(() => {
    service = new DashboardMetricsService(prisma)
  })

  describe('calculateOrderMetrics', () => {
    it('should calculate correct order metrics for empty dataset', async () => {
      const result = await service.calculateOrderMetrics(mockLineAccountId, new Date(), new Date())
      
      expect(result).toEqual({
        todayCount: 0,
        todayTotal: 0,
        pendingCount: 0,
        completedCount: 0,
        averageOrderValue: 0,
        topProducts: [],
      })
    })

    it('should calculate correct order metrics with sample data', async () => {
      // Create test orders
      const orders = [
        createTestOrder({ status: 'completed', totalAmount: 1000.00 }),
        createTestOrder({ status: 'completed', totalAmount: 2000.00 }),
        createTestOrder({ status: 'pending', totalAmount: 1500.00 }),
      ]

      for (const order of orders) {
        await prisma.order.create({ data: order })
      }

      const result = await service.calculateOrderMetrics(mockLineAccountId, new Date(), new Date())
      
      expect(result.todayCount).toBe(3)
      expect(result.todayTotal).toBe(4500.00)
      expect(result.pendingCount).toBe(1)
      expect(result.completedCount).toBe(2)
      expect(result.averageOrderValue).toBe(1500.00)
    })

    // Property-based test: Order metrics should always be non-negative
    it('should always return non-negative metrics', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.array(fc.record({
            status: arbitraries.orderStatus(),
            totalAmount: arbitraries.amount(),
          }), { maxLength: 100 }),
          async (orderData) => {
            // Clean database
            await prisma.order.deleteMany()
            
            // Create test orders
            for (const data of orderData) {
              await prisma.order.create({
                data: createTestOrder(data)
              })
            }

            const result = await service.calculateOrderMetrics(mockLineAccountId, new Date(), new Date())
            
            return (
              result.todayCount >= 0 &&
              result.todayTotal >= 0 &&
              result.pendingCount >= 0 &&
              result.completedCount >= 0 &&
              result.averageOrderValue >= 0
            )
          }
        ),
        propertyTestConfig
      )
    })

    // Property-based test: Total count should equal sum of status counts
    it('should maintain count consistency', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.array(fc.record({
            status: arbitraries.orderStatus(),
            totalAmount: arbitraries.amount(),
          }), { maxLength: 50 }),
          async (orderData) => {
            // Clean database
            await prisma.order.deleteMany()
            
            // Create test orders
            for (const data of orderData) {
              await prisma.order.create({
                data: createTestOrder(data)
              })
            }

            const result = await service.calculateOrderMetrics(mockLineAccountId, new Date(), new Date())
            
            // Total count should be at least the sum of pending and completed
            return result.todayCount >= (result.pendingCount + result.completedCount)
          }
        ),
        propertyTestConfig
      )
    })
  })

  describe('calculatePaymentMetrics', () => {
    it('should calculate correct payment metrics for empty dataset', async () => {
      const result = await service.calculatePaymentMetrics(mockLineAccountId, new Date(), new Date())
      
      expect(result).toEqual({
        pendingSlips: 0,
        processedToday: 0,
        matchingRate: 0,
        totalAmount: 0,
        averageProcessingTime: 0,
      })
    })

    it('should calculate correct payment metrics with sample data', async () => {
      // Create test payment slips
      const slips = [
        createTestPaymentSlip({ status: 'pending', amount: 1000.00 }),
        createTestPaymentSlip({ status: 'matched', amount: 2000.00 }),
        createTestPaymentSlip({ status: 'matched', amount: 1500.00 }),
      ]

      for (const slip of slips) {
        await prisma.paymentSlip.create({ data: slip })
      }

      const result = await service.calculatePaymentMetrics(mockLineAccountId, new Date(), new Date())
      
      expect(result.pendingSlips).toBe(1)
      expect(result.processedToday).toBe(2)
      expect(result.totalAmount).toBe(4500.00)
      expect(result.matchingRate).toBeCloseTo(0.67, 2) // 2/3 matched
    })

    // Property-based test: Matching rate should be between 0 and 1
    it('should always return valid matching rate', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.array(fc.record({
            status: arbitraries.slipStatus(),
            amount: arbitraries.amount(),
          }), { maxLength: 100 }),
          async (slipData) => {
            // Clean database
            await prisma.paymentSlip.deleteMany()
            
            // Create test slips
            for (const data of slipData) {
              await prisma.paymentSlip.create({
                data: createTestPaymentSlip(data)
              })
            }

            const result = await service.calculatePaymentMetrics(mockLineAccountId, new Date(), new Date())
            
            return result.matchingRate >= 0 && result.matchingRate <= 1
          }
        ),
        propertyTestConfig
      )
    })
  })

  describe('calculateWebhookMetrics', () => {
    it('should calculate webhook success rate correctly', async () => {
      // Create test webhook logs
      const webhooks = [
        { status: 'success', responseTime: 100 },
        { status: 'success', responseTime: 200 },
        { status: 'failed', responseTime: 5000 },
        { status: 'success', responseTime: 150 },
      ]

      for (const webhook of webhooks) {
        await prisma.webhookLog.create({
          data: {
            id: crypto.randomUUID(),
            type: 'order.created',
            status: webhook.status,
            responseTime: webhook.responseTime,
            payload: {},
            createdAt: new Date(),
          }
        })
      }

      const result = await service.calculateWebhookMetrics(mockLineAccountId, new Date(), new Date())
      
      expect(result.totalEvents).toBe(4)
      expect(result.successRate).toBe(0.75) // 3/4 success
      expect(result.failedEvents).toBe(1)
      expect(result.averageResponseTime).toBe(1362.5) // (100+200+5000+150)/4
    })

    // Property-based test: Success rate calculation
    it('should calculate success rate correctly for any input', async () => {
      await fc.assert(
        fc.asyncProperty(
          fc.array(fc.record({
            status: fc.constantFrom('success', 'failed'),
            responseTime: fc.nat({ max: 10000 }),
          }), { minLength: 1, maxLength: 100 }),
          async (webhookData) => {
            // Clean database
            await prisma.webhookLog.deleteMany()
            
            // Create test webhooks
            for (const data of webhookData) {
              await prisma.webhookLog.create({
                data: {
                  id: crypto.randomUUID(),
                  type: 'order.created',
                  status: data.status,
                  responseTime: data.responseTime,
                  payload: {},
                  createdAt: new Date(),
                }
              })
            }

            const result = await service.calculateWebhookMetrics(mockLineAccountId, new Date(), new Date())
            
            const expectedSuccessCount = webhookData.filter(w => w.status === 'success').length
            const expectedFailedCount = webhookData.filter(w => w.status === 'failed').length
            const expectedSuccessRate = expectedSuccessCount / webhookData.length
            
            return (
              result.totalEvents === webhookData.length &&
              result.failedEvents === expectedFailedCount &&
              Math.abs(result.successRate - expectedSuccessRate) < 0.001
            )
          }
        ),
        propertyTestConfig
      )
    })
  })

  describe('aggregateMetrics', () => {
    it('should combine all metrics correctly', async () => {
      const dateFrom = new Date('2024-01-01')
      const dateTo = new Date('2024-01-02')
      
      const result = await service.aggregateMetrics(mockLineAccountId, dateFrom, dateTo)
      
      expect(result).toHaveProperty('orders')
      expect(result).toHaveProperty('payments')
      expect(result).toHaveProperty('webhooks')
      expect(result).toHaveProperty('customers')
      expect(result).toHaveProperty('updatedAt')
      expect(result.updatedAt).toBeInstanceOf(Date)
    })

    // Property-based test: Date range validation
    it('should handle any valid date range', async () => {
      await fc.assert(
        fc.asyncProperty(
          arbitraries.dateRange(),
          async ({ start, end }) => {
            const result = await service.aggregateMetrics(mockLineAccountId, start, end)
            
            return (
              typeof result.orders === 'object' &&
              typeof result.payments === 'object' &&
              typeof result.webhooks === 'object' &&
              typeof result.customers === 'object' &&
              result.updatedAt instanceof Date
            )
          }
        ),
        propertyTestConfig
      )
    })
  })

  describe('cacheMetrics', () => {
    it('should cache metrics with correct TTL', async () => {
      const metrics = await service.aggregateMetrics(mockLineAccountId, new Date(), new Date())
      
      await service.cacheMetrics(mockLineAccountId, 'daily', metrics, 3600)
      
      const cached = await service.getCachedMetrics(mockLineAccountId, 'daily')
      expect(cached).toEqual(metrics)
    })

    it('should return null for expired cache', async () => {
      const metrics = await service.aggregateMetrics(mockLineAccountId, new Date(), new Date())
      
      // Cache with very short TTL
      await service.cacheMetrics(mockLineAccountId, 'test', metrics, 0.001)
      
      // Wait for expiration
      await new Promise(resolve => setTimeout(resolve, 10))
      
      const cached = await service.getCachedMetrics(mockLineAccountId, 'test')
      expect(cached).toBeNull()
    })
  })
})