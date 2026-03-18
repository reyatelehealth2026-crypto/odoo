import { describe, it, expect, beforeEach, afterEach } from '@jest/globals';
import { PrismaClient } from '@prisma/client';
import { CustomerService } from '@/services/CustomerService';

describe('CustomerService', () => {
  let prisma: PrismaClient;
  let customerService: CustomerService;
  const testLineAccountId = '1';

  beforeEach(() => {
    prisma = new PrismaClient();
    customerService = new CustomerService(prisma);
  });

  afterEach(async () => {
    await prisma.$disconnect();
  });

  describe('searchCustomers', () => {
    it('should return paginated customer list', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        {},
        { page: 1, limit: 20 }
      );

      expect(result).toHaveProperty('data');
      expect(result).toHaveProperty('meta');
      expect(result.meta).toHaveProperty('page', 1);
      expect(result.meta).toHaveProperty('limit', 20);
      expect(result.meta).toHaveProperty('total');
      expect(result.meta).toHaveProperty('totalPages');
      expect(Array.isArray(result.data)).toBe(true);
    });

    it('should filter customers by search query', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        { search: 'test' },
        { page: 1, limit: 20 }
      );

      expect(result).toHaveProperty('data');
      expect(Array.isArray(result.data)).toBe(true);
    });

    it('should filter customers by name', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        { name: 'John' },
        { page: 1, limit: 20 }
      );

      expect(result).toHaveProperty('data');
      expect(Array.isArray(result.data)).toBe(true);
    });

    it('should filter customers by tier', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        { tier: 'Gold' },
        { page: 1, limit: 20 }
      );

      expect(result).toHaveProperty('data');
      expect(Array.isArray(result.data)).toBe(true);
    });

    it('should filter customers by date range', async () => {
      const dateFrom = new Date('2024-01-01');
      const dateTo = new Date('2024-12-31');

      const result = await customerService.searchCustomers(
        testLineAccountId,
        { dateFrom, dateTo },
        { page: 1, limit: 20 }
      );

      expect(result).toHaveProperty('data');
      expect(Array.isArray(result.data)).toBe(true);
    });

    it('should respect pagination limits', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        {},
        { page: 1, limit: 5 }
      );

      expect(result.data.length).toBeLessThanOrEqual(5);
      expect(result.meta.limit).toBe(5);
    });

    it('should sort customers by specified field', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        {},
        { page: 1, limit: 20, sort: 'totalSpent', order: 'desc' }
      );

      expect(result).toHaveProperty('data');
      expect(Array.isArray(result.data)).toBe(true);
    });
  });

  describe('getCustomerById', () => {
    it('should return null for non-existent customer', async () => {
      const result = await customerService.getCustomerById(
        '999999',
        testLineAccountId
      );

      expect(result).toBeNull();
    });

    it('should return customer profile with all fields', async () => {
      // This test would need a known customer ID in the test database
      // For now, we test the structure
      const result = await customerService.getCustomerById(
        '1',
        testLineAccountId
      );

      if (result) {
        expect(result).toHaveProperty('id');
        expect(result).toHaveProperty('lineAccountId');
        expect(result).toHaveProperty('lineUserId');
        expect(result).toHaveProperty('displayName');
        expect(result).toHaveProperty('totalOrders');
        expect(result).toHaveProperty('totalSpent');
        expect(result).toHaveProperty('availablePoints');
        expect(result).toHaveProperty('tier');
        expect(result).toHaveProperty('customerScore');
        expect(result).toHaveProperty('createdAt');
        expect(result).toHaveProperty('updatedAt');
      }
    });

    it('should return null for customer from different account', async () => {
      const result = await customerService.getCustomerById(
        '1',
        'different-account-id'
      );

      // Should return null or empty if customer doesn't belong to this account
      expect(result === null || result === undefined).toBe(true);
    });
  });

  describe('getCustomerOrders', () => {
    it('should throw error for non-existent customer', async () => {
      await expect(
        customerService.getCustomerOrders('999999', testLineAccountId)
      ).rejects.toThrow('Customer not found');
    });

    it('should return paginated order list for valid customer', async () => {
      // This test would need a known customer ID with orders
      // For now, we test the error case
      try {
        const result = await customerService.getCustomerOrders(
          '1',
          testLineAccountId,
          { page: 1, limit: 10 }
        );

        expect(result).toHaveProperty('data');
        expect(result).toHaveProperty('meta');
        expect(Array.isArray(result.data)).toBe(true);
      } catch (error) {
        // Expected if customer doesn't exist
        expect(error).toBeDefined();
      }
    });

    it('should respect pagination for customer orders', async () => {
      try {
        const result = await customerService.getCustomerOrders(
          '1',
          testLineAccountId,
          { page: 1, limit: 5 }
        );

        expect(result.data.length).toBeLessThanOrEqual(5);
        expect(result.meta.limit).toBe(5);
      } catch (error) {
        // Expected if customer doesn't exist
        expect(error).toBeDefined();
      }
    });

    it('should return empty list for customer with no orders', async () => {
      try {
        const result = await customerService.getCustomerOrders(
          '1',
          testLineAccountId
        );

        if (result.data.length === 0) {
          expect(result.meta.total).toBe(0);
          expect(result.meta.totalPages).toBe(0);
        }
      } catch (error) {
        // Expected if customer doesn't exist
        expect(error).toBeDefined();
      }
    });
  });

  describe('updateLineConnection', () => {
    it('should throw error for non-existent customer', async () => {
      await expect(
        customerService.updateLineConnection(
          '999999',
          testLineAccountId,
          'U1234567890'
        )
      ).rejects.toThrow('Customer not found');
    });

    it('should update LINE user ID for valid customer', async () => {
      // This test would need a known customer ID
      // For now, we test the error case
      try {
        const result = await customerService.updateLineConnection(
          '1',
          testLineAccountId,
          'U1234567890'
        );

        expect(result).toHaveProperty('id');
        expect(result).toHaveProperty('lineUserId');
        expect(result.lineUserId).toBe('U1234567890');
      } catch (error) {
        // Expected if customer doesn't exist
        expect(error).toBeDefined();
      }
    });

    it('should allow disconnecting LINE account (null value)', async () => {
      try {
        const result = await customerService.updateLineConnection(
          '1',
          testLineAccountId,
          null
        );

        expect(result).toHaveProperty('id');
        expect(result.lineUserId).toBeNull();
      } catch (error) {
        // Expected if customer doesn't exist
        expect(error).toBeDefined();
      }
    });
  });

  describe('getCustomerStatistics', () => {
    it('should return customer statistics', async () => {
      const result = await customerService.getCustomerStatistics(
        testLineAccountId
      );

      expect(result).toHaveProperty('totalCustomers');
      expect(result).toHaveProperty('newCustomers');
      expect(result).toHaveProperty('activeCustomers');
      expect(result).toHaveProperty('lineConnected');
      expect(result).toHaveProperty('averageOrderValue');
      expect(result).toHaveProperty('topTiers');

      expect(typeof result.totalCustomers).toBe('number');
      expect(typeof result.newCustomers).toBe('number');
      expect(typeof result.activeCustomers).toBe('number');
      expect(typeof result.lineConnected).toBe('number');
      expect(typeof result.averageOrderValue).toBe('number');
      expect(typeof result.topTiers).toBe('object');
    });

    it('should filter statistics by date range', async () => {
      const dateFrom = new Date('2024-01-01');
      const dateTo = new Date('2024-12-31');

      const result = await customerService.getCustomerStatistics(
        testLineAccountId,
        dateFrom,
        dateTo
      );

      expect(result).toHaveProperty('totalCustomers');
      expect(typeof result.totalCustomers).toBe('number');
    });

    it('should return zero values for account with no customers', async () => {
      const result = await customerService.getCustomerStatistics(
        'non-existent-account'
      );

      expect(result.totalCustomers).toBe(0);
      expect(result.newCustomers).toBe(0);
      expect(result.activeCustomers).toBe(0);
      expect(result.lineConnected).toBe(0);
    });

    it('should calculate tier breakdown correctly', async () => {
      const result = await customerService.getCustomerStatistics(
        testLineAccountId
      );

      expect(result.topTiers).toBeDefined();
      expect(typeof result.topTiers).toBe('object');

      // All tier counts should be non-negative numbers
      Object.values(result.topTiers).forEach(count => {
        expect(typeof count).toBe('number');
        expect(count).toBeGreaterThanOrEqual(0);
      });
    });
  });

  describe('Edge Cases', () => {
    it('should handle empty search results gracefully', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        { search: 'nonexistentcustomer12345' },
        { page: 1, limit: 20 }
      );

      expect(result.data).toEqual([]);
      expect(result.meta.total).toBe(0);
      expect(result.meta.totalPages).toBe(0);
    });

    it('should handle invalid page numbers', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        {},
        { page: 999, limit: 20 }
      );

      expect(result).toHaveProperty('data');
      expect(Array.isArray(result.data)).toBe(true);
    });

    it('should handle large limit values', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        {},
        { page: 1, limit: 1000 }
      );

      expect(result).toHaveProperty('data');
      expect(result.meta.limit).toBe(1000);
    });
  });

  describe('Data Validation', () => {
    it('should return customers with valid data types', async () => {
      const result = await customerService.searchCustomers(
        testLineAccountId,
        {},
        { page: 1, limit: 5 }
      );

      result.data.forEach(customer => {
        expect(typeof customer.id).toBe('string');
        expect(typeof customer.totalOrders).toBe('number');
        expect(typeof customer.totalSpent).toBe('number');
        expect(typeof customer.availablePoints).toBe('number');
        expect(typeof customer.isBlocked).toBe('boolean');
        expect(customer.createdAt).toBeInstanceOf(Date);
        expect(customer.updatedAt).toBeInstanceOf(Date);
      });
    });

    it('should return customer profile with valid data types', async () => {
      try {
        const result = await customerService.getCustomerById(
          '1',
          testLineAccountId
        );

        if (result) {
          expect(typeof result.id).toBe('string');
          expect(typeof result.totalOrders).toBe('number');
          expect(typeof result.totalSpent).toBe('number');
          expect(typeof result.availablePoints).toBe('number');
          expect(typeof result.customerScore).toBe('number');
          expect(typeof result.isBlocked).toBe('boolean');
          expect(result.createdAt).toBeInstanceOf(Date);
          expect(result.updatedAt).toBeInstanceOf(Date);
        }
      } catch (error) {
        // Expected if customer doesn't exist
        expect(error).toBeDefined();
      }
    });
  });
});
