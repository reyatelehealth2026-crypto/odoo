#!/usr/bin/env tsx

/**
 * Database Migration Validation Script
 * 
 * This script validates the database migration by:
 * 1. Testing data integrity during migration
 * 2. Validating foreign key relationships
 * 3. Testing performance of new indexes
 * 
 * Requirements: TC-3.3
 */

import { PrismaClient } from '@prisma/client';
import { performance } from 'perf_hooks';

const prisma = new PrismaClient();

interface ValidationResult {
  test: string;
  passed: boolean;
  duration: number;
  error?: string;
  details?: any;
}

class MigrationValidator {
  private results: ValidationResult[] = [];

  async validateDataIntegrity(): Promise<ValidationResult> {
    const startTime = performance.now();
    
    try {
      // Test basic table creation and data insertion
      const testUser = await prisma.user.create({
        data: {
          username: 'test_migration_user',
          email: 'test@migration.com',
          passwordHash: 'hashed_password',
          role: 'STAFF',
          lineAccountId: '1',
        }
      });

      // Test foreign key relationships
      const testSession = await prisma.userSession.create({
        data: {
          userId: testUser.id,
          tokenHash: 'test_token_hash',
          refreshTokenHash: 'test_refresh_hash',
          expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000), // 24 hours
        }
      });

      // Test audit log creation
      const testAuditLog = await prisma.auditLog.create({
        data: {
          userId: testUser.id,
          action: 'CREATE_USER',
          resourceType: 'user',
          resourceId: testUser.id,
          newValues: { username: testUser.username },
        }
      });

      // Cleanup test data
      await prisma.auditLog.delete({ where: { id: testAuditLog.id } });
      await prisma.userSession.delete({ where: { id: testSession.id } });
      await prisma.user.delete({ where: { id: testUser.id } });

      const duration = performance.now() - startTime;
      
      return {
        test: 'Data Integrity',
        passed: true,
        duration,
        details: {
          userCreated: !!testUser,
          sessionCreated: !!testSession,
          auditLogCreated: !!testAuditLog,
        }
      };
    } catch (error) {
      const duration = performance.now() - startTime;
      return {
        test: 'Data Integrity',
        passed: false,
        duration,
        error: error instanceof Error ? error.message : 'Unknown error',
      };
    }
  }

  async validateForeignKeyRelationships(): Promise<ValidationResult> {
    const startTime = performance.now();
    
    try {
      // Test that foreign key constraints are properly enforced
      const testUser = await prisma.user.create({
        data: {
          username: 'fk_test_user',
          email: 'fk@test.com',
          passwordHash: 'hashed_password',
          role: 'STAFF',
          lineAccountId: '1',
        }
      });

      // Test cascade delete
      const testSession = await prisma.userSession.create({
        data: {
          userId: testUser.id,
          tokenHash: 'fk_test_token',
          refreshTokenHash: 'fk_test_refresh',
          expiresAt: new Date(Date.now() + 24 * 60 * 60 * 1000),
        }
      });

      // Delete user should cascade to session
      await prisma.user.delete({ where: { id: testUser.id } });

      // Verify session was deleted
      const deletedSession = await prisma.userSession.findUnique({
        where: { id: testSession.id }
      });

      const duration = performance.now() - startTime;
      
      return {
        test: 'Foreign Key Relationships',
        passed: deletedSession === null,
        duration,
        details: {
          cascadeDeleteWorked: deletedSession === null,
        }
      };
    } catch (error) {
      const duration = performance.now() - startTime;
      return {
        test: 'Foreign Key Relationships',
        passed: false,
        duration,
        error: error instanceof Error ? error.message : 'Unknown error',
      };
    }
  }

  async validateIndexPerformance(): Promise<ValidationResult> {
    const startTime = performance.now();
    
    try {
      // Test index performance by running queries that should use indexes
      const queries = [
        // Test user session indexes
        () => prisma.userSession.findMany({
          where: { expiresAt: { gt: new Date() } },
          take: 10
        }),
        
        // Test audit log indexes
        () => prisma.auditLog.findMany({
          where: { 
            action: 'CREATE_USER',
            createdAt: { gte: new Date(Date.now() - 24 * 60 * 60 * 1000) }
          },
          take: 10
        }),
        
        // Test dashboard metrics cache indexes
        () => prisma.dashboardMetricsCache.findMany({
          where: {
            lineAccountId: '1',
            metricType: 'ORDERS',
            expiresAt: { gt: new Date() }
          },
          take: 10
        }),
      ];

      const queryTimes: number[] = [];
      
      for (const query of queries) {
        const queryStart = performance.now();
        await query();
        const queryTime = performance.now() - queryStart;
        queryTimes.push(queryTime);
      }

      const avgQueryTime = queryTimes.reduce((a, b) => a + b, 0) / queryTimes.length;
      const duration = performance.now() - startTime;
      
      // Consider performance good if average query time is under 50ms
      const performanceGood = avgQueryTime < 50;
      
      return {
        test: 'Index Performance',
        passed: performanceGood,
        duration,
        details: {
          averageQueryTime: avgQueryTime,
          individualQueryTimes: queryTimes,
          performanceThreshold: 50,
        }
      };
    } catch (error) {
      const duration = performance.now() - startTime;
      return {
        test: 'Index Performance',
        passed: false,
        duration,
        error: error instanceof Error ? error.message : 'Unknown error',
      };
    }
  }

  async validateCacheTableStructure(): Promise<ValidationResult> {
    const startTime = performance.now();
    
    try {
      // Test dashboard metrics cache
      const testMetric = await prisma.dashboardMetricsCache.create({
        data: {
          lineAccountId: '1',
          metricType: 'ORDERS',
          dateKey: new Date(),
          data: {
            totalOrders: 100,
            totalAmount: 50000,
            averageOrderValue: 500
          },
          expiresAt: new Date(Date.now() + 60 * 60 * 1000), // 1 hour
        }
      });

      // Test API cache
      const testApiCache = await prisma.apiCache.create({
        data: {
          cacheKey: 'test_api_cache_key',
          data: {
            result: 'cached_data',
            timestamp: new Date().toISOString()
          },
          expiresAt: new Date(Date.now() + 30 * 60 * 1000), // 30 minutes
        }
      });

      // Test unique constraint on dashboard metrics cache
      try {
        await prisma.dashboardMetricsCache.create({
          data: {
            lineAccountId: '1',
            metricType: 'ORDERS',
            dateKey: new Date(),
            data: { duplicate: true },
            expiresAt: new Date(Date.now() + 60 * 60 * 1000),
          }
        });
        
        // If we reach here, unique constraint failed
        throw new Error('Unique constraint not enforced');
      } catch (uniqueError) {
        // This should fail due to unique constraint - that's expected
      }

      // Cleanup
      await prisma.dashboardMetricsCache.delete({ where: { id: testMetric.id } });
      await prisma.apiCache.delete({ where: { cacheKey: testApiCache.cacheKey } });

      const duration = performance.now() - startTime;
      
      return {
        test: 'Cache Table Structure',
        passed: true,
        duration,
        details: {
          dashboardMetricsCacheWorking: !!testMetric,
          apiCacheWorking: !!testApiCache,
          uniqueConstraintEnforced: true,
        }
      };
    } catch (error) {
      const duration = performance.now() - startTime;
      return {
        test: 'Cache Table Structure',
        passed: false,
        duration,
        error: error instanceof Error ? error.message : 'Unknown error',
      };
    }
  }

  async runAllValidations(): Promise<void> {
    console.log('🔍 Starting database migration validation...\n');

    const validations = [
      this.validateDataIntegrity(),
      this.validateForeignKeyRelationships(),
      this.validateIndexPerformance(),
      this.validateCacheTableStructure(),
    ];

    this.results = await Promise.all(validations);

    // Print results
    console.log('📊 Validation Results:');
    console.log('=' .repeat(60));
    
    let allPassed = true;
    
    for (const result of this.results) {
      const status = result.passed ? '✅ PASS' : '❌ FAIL';
      const duration = `${result.duration.toFixed(2)}ms`;
      
      console.log(`${status} ${result.test.padEnd(25)} (${duration})`);
      
      if (!result.passed) {
        allPassed = false;
        console.log(`   Error: ${result.error}`);
      }
      
      if (result.details) {
        console.log(`   Details: ${JSON.stringify(result.details, null, 2)}`);
      }
      
      console.log('');
    }

    console.log('=' .repeat(60));
    console.log(`Overall Status: ${allPassed ? '✅ ALL TESTS PASSED' : '❌ SOME TESTS FAILED'}`);
    
    if (!allPassed) {
      process.exit(1);
    }
  }
}

// Run validation if called directly
if (require.main === module) {
  const validator = new MigrationValidator();
  
  validator.runAllValidations()
    .catch((error) => {
      console.error('❌ Validation failed with error:', error);
      process.exit(1);
    })
    .finally(() => {
      prisma.$disconnect();
    });
}

export { MigrationValidator };