import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import fc from 'fast-check';
import { PrismaClient } from '@prisma/client';
import { AuditService } from '@/services/AuditService';
import { SecurityMonitoringService } from '@/services/SecurityMonitoringService';
import { mockDeep, mockReset } from 'vitest-mock-extended';

/**
 * Property-Based Test for Audit Trail Completeness
 * 
 * **Feature: odoo-dashboard-modernization, Property 13: Audit Trail Completeness**
 * 
 * Tests that for any manual status override or sensitive operation,
 * a complete audit log entry is created with all required fields.
 * 
 * Requirements: BR-5.4
 */

describe('Audit Trail Completeness Property Tests', () => {
  let mockPrisma: any;
  let auditService: AuditService;
  let securityService: SecurityMonitoringService;
  let mockRedis: any;

  beforeEach(() => {
    mockPrisma = mockDeep<PrismaClient>();
    mockRedis = {
      setex: vi.fn(),
      get: vi.fn(),
      keys: vi.fn().mockResolvedValue([]),
      zadd: vi.fn(),
      zcard: vi.fn().mockResolvedValue(0),
      zremrangebyscore: vi.fn(),
      expire: vi.fn(),
      del: vi.fn(),
      incr: vi.fn().mockResolvedValue(1),
      pipeline: vi.fn().mockReturnValue({
        zremrangebyscore: vi.fn().mockReturnThis(),
        zcard: vi.fn().mockReturnThis(),
        expire: vi.fn().mockReturnThis(),
        exec: vi.fn().mockResolvedValue([[null, 0], [null, 0]]),
      }),
    };

    auditService = new AuditService(mockPrisma);
    securityService = new SecurityMonitoringService(mockPrisma, auditService, mockRedis);
  });

  afterEach(() => {
    mockReset(mockPrisma);
    vi.clearAllMocks();
  });

  /**
   * Property 13: Audit Trail Completeness
   * For any manual status override or sensitive operation, a complete audit log entry
   * should be created with all required fields.
   */
  it('should create complete audit log entries for all sensitive operations', async () => {
    await fc.assert(fc.asyncProperty(
      // Generate test data for sensitive operations
      fc.record({
        userId: fc.uuid(),
        action: fc.constantFrom(
          'order_update', 'payment_process', 'login', 'logout', 
          'token_refresh', 'security_event', 'webhook_process',
          'payment_slip_upload', 'order_create'
        ),
        resourceType: fc.constantFrom(
          'order', 'payment', 'authentication', 'security', 
          'webhook', 'payment_slip'
        ),
        resourceId: fc.option(fc.uuid(), { nil: null }),
        oldValues: fc.option(fc.record({
          status: fc.constantFrom('pending', 'processing', 'completed'),
          amount: fc.float({ min: 0, max: 10000 }),
          notes: fc.string({ maxLength: 100 }),
        }), { nil: null }),
        newValues: fc.option(fc.record({
          status: fc.constantFrom('pending', 'processing', 'completed', 'cancelled'),
          amount: fc.float({ min: 0, max: 10000 }),
          notes: fc.string({ maxLength: 100 }),
          timestamp: fc.date().map(d => d.toISOString()),
        }), { nil: null }),
        ipAddress: fc.ipV4(),
        userAgent: fc.string({ minLength: 10, maxLength: 200 }),
        success: fc.boolean(),
        errorMessage: fc.option(fc.string({ maxLength: 500 }), { nil: null }),
        metadata: fc.option(fc.record({
          source: fc.constantFrom('dashboard', 'api', 'webhook'),
          requestId: fc.string({ minLength: 10, maxLength: 50 }),
          sessionId: fc.uuid(),
        }), { nil: null }),
      }),
      async (auditData) => {
        // **Feature: odoo-dashboard-modernization, Property 13: Audit Trail Completeness**
        
        // Mock successful database insertion
        const expectedAuditId = 'audit-' + Math.random().toString(36).substr(2, 9);
        mockPrisma.auditLog.create.mockResolvedValue({
          id: expectedAuditId,
          ...auditData,
          createdAt: new Date(),
        });

        // Execute the audit logging
        const auditId = await auditService.logAction(auditData);

        // Verify audit log was created
        expect(mockPrisma.auditLog.create).toHaveBeenCalledTimes(1);
        
        const createCall = mockPrisma.auditLog.create.mock.calls[0][0];
        const auditEntry = createCall.data;

        // **Property 13 Validation: All required fields must be present**
        
        // 1. Core identification fields
        expect(auditEntry.id).toBeDefined();
        expect(typeof auditEntry.id).toBe('string');
        expect(auditEntry.id.length).toBeGreaterThan(0);
        
        expect(auditEntry.userId).toBe(auditData.userId);
        expect(auditEntry.action).toBe(auditData.action);
        expect(auditEntry.resourceType).toBe(auditData.resourceType);

        // 2. Resource identification
        if (auditData.resourceId) {
          expect(auditEntry.resourceId).toBe(auditData.resourceId);
        }

        // 3. Change tracking fields
        if (auditData.oldValues) {
          expect(auditEntry.oldValues).toBeDefined();
          const parsedOldValues = JSON.parse(auditEntry.oldValues);
          expect(parsedOldValues).toEqual(auditData.oldValues);
        }

        if (auditData.newValues) {
          expect(auditEntry.newValues).toBeDefined();
          const parsedNewValues = JSON.parse(auditEntry.newValues);
          expect(parsedNewValues).toEqual(auditData.newValues);
        }

        // 4. Security and traceability fields
        expect(auditEntry.ipAddress).toBe(auditData.ipAddress);
        expect(auditEntry.userAgent).toBe(auditData.userAgent);
        expect(auditEntry.success).toBe(auditData.success);

        // 5. Request tracing
        expect(auditEntry.requestId).toBeDefined();
        expect(typeof auditEntry.requestId).toBe('string');
        expect(auditEntry.requestId.length).toBeGreaterThan(0);

        // 6. Error information (when applicable)
        if (!auditData.success && auditData.errorMessage) {
          expect(auditEntry.errorMessage).toBe(auditData.errorMessage);
        }

        // 7. Metadata preservation
        if (auditData.metadata) {
          expect(auditEntry.metadata).toBeDefined();
          const parsedMetadata = JSON.parse(auditEntry.metadata);
          expect(parsedMetadata).toEqual(auditData.metadata);
        }

        // 8. Verify audit ID is returned
        expect(auditId).toBe(expectedAuditId);
        expect(typeof auditId).toBe('string');
        expect(auditId.length).toBeGreaterThan(0);

        // **Additional completeness checks for sensitive operations**
        
        // Authentication operations must have specific metadata
        if (auditData.action === 'login' || auditData.action === 'logout') {
          expect(auditEntry.ipAddress).toBeDefined();
          expect(auditEntry.userAgent).toBeDefined();
        }

        // Payment operations must have amount tracking
        if (auditData.action === 'payment_process' && auditData.newValues) {
          const newValues = JSON.parse(auditEntry.newValues);
          if (newValues.amount !== undefined) {
            expect(typeof newValues.amount).toBe('number');
            expect(newValues.amount).toBeGreaterThanOrEqual(0);
          }
        }

        // Order updates must track status changes
        if (auditData.action === 'order_update') {
          if (auditData.oldValues && auditData.newValues) {
            const oldValues = JSON.parse(auditEntry.oldValues);
            const newValues = JSON.parse(auditEntry.newValues);
            
            // Status change must be tracked
            if (oldValues.status && newValues.status) {
              expect(oldValues.status).not.toBe(newValues.status);
            }
          }
        }

        // Security events must have severity and details
        if (auditData.action === 'security_event' && auditData.metadata) {
          const metadata = JSON.parse(auditEntry.metadata);
          // Security events should have additional context
          expect(metadata).toBeDefined();
        }
      }
    ), { numRuns: 100 });
  });

  /**
   * Property: Audit Log Retrieval Completeness
   * When retrieving audit trails, all logged entries should be returned with complete data.
   */
  it('should retrieve complete audit trail data for any resource', async () => {
    await fc.assert(fc.asyncProperty(
      fc.record({
        resourceType: fc.constantFrom('order', 'payment', 'authentication', 'security'),
        resourceId: fc.uuid(),
        auditEntries: fc.array(
          fc.record({
            id: fc.uuid(),
            userId: fc.uuid(),
            action: fc.string({ minLength: 3, maxLength: 50 }),
            oldValues: fc.option(fc.jsonValue(), { nil: null }),
            newValues: fc.option(fc.jsonValue(), { nil: null }),
            metadata: fc.option(fc.jsonValue(), { nil: null }),
            ipAddress: fc.ipV4(),
            userAgent: fc.string({ minLength: 10, maxLength: 100 }),
            success: fc.boolean(),
            createdAt: fc.date(),
          }),
          { minLength: 1, maxLength: 20 }
        ),
      }),
      async (testData) => {
        // Mock database response
        const mockAuditEntries = testData.auditEntries.map(entry => ({
          ...entry,
          resourceType: testData.resourceType,
          resourceId: testData.resourceId,
          oldValues: entry.oldValues ? JSON.stringify(entry.oldValues) : null,
          newValues: entry.newValues ? JSON.stringify(entry.newValues) : null,
          metadata: entry.metadata ? JSON.stringify(entry.metadata) : null,
          user: {
            username: `user_${entry.userId.substr(0, 8)}`,
            email: `user_${entry.userId.substr(0, 8)}@example.com`,
            role: 'STAFF',
          },
        }));

        mockPrisma.auditLog.findMany.mockResolvedValue(mockAuditEntries);

        // Retrieve audit trail
        const auditTrail = await auditService.getAuditTrail(
          testData.resourceType,
          testData.resourceId,
          50
        );

        // **Completeness validation**
        expect(auditTrail).toHaveLength(testData.auditEntries.length);

        auditTrail.forEach((entry, index) => {
          const originalEntry = testData.auditEntries[index];

          // All core fields must be present
          expect(entry.id).toBe(originalEntry.id);
          expect(entry.userId).toBe(originalEntry.userId);
          expect(entry.action).toBe(originalEntry.action);
          expect(entry.resourceType).toBe(testData.resourceType);
          expect(entry.resourceId).toBe(testData.resourceId);
          expect(entry.ipAddress).toBe(originalEntry.ipAddress);
          expect(entry.userAgent).toBe(originalEntry.userAgent);
          expect(entry.success).toBe(originalEntry.success);

          // JSON fields must be properly parsed
          if (originalEntry.oldValues) {
            expect(entry.oldValues).toEqual(originalEntry.oldValues);
          }
          if (originalEntry.newValues) {
            expect(entry.newValues).toEqual(originalEntry.newValues);
          }
          if (originalEntry.metadata) {
            expect(entry.metadata).toEqual(originalEntry.metadata);
          }

          // User information must be included
          expect(entry.user).toBeDefined();
          expect(entry.user.username).toBeDefined();
          expect(entry.user.email).toBeDefined();
          expect(entry.user.role).toBeDefined();
        });
      }
    ), { numRuns: 50 });
  });

  /**
   * Property: Security Event Audit Completeness
   * All security events must be logged with complete audit trail information.
   */
  it('should create complete audit entries for all security events', async () => {
    await fc.assert(fc.asyncProperty(
      fc.record({
        eventType: fc.constantFrom(
          'brute_force_detected', 'sql_injection_attempt', 'xss_attempt',
          'suspicious_activity', 'unauthorized_access', 'rate_limit_violation'
        ),
        severity: fc.constantFrom('low', 'medium', 'high', 'critical'),
        userId: fc.option(fc.uuid(), { nil: null }),
        ipAddress: fc.ipV4(),
        userAgent: fc.string({ minLength: 10, maxLength: 200 }),
        details: fc.record({
          requestCount: fc.integer({ min: 1, max: 1000 }),
          endpoint: fc.string({ minLength: 5, maxLength: 100 }),
          payload: fc.string({ maxLength: 500 }),
        }),
      }),
      async (securityEventData) => {
        // Mock successful database insertions
        const securityEventId = 'security-' + Math.random().toString(36).substr(2, 9);
        const auditLogId = 'audit-' + Math.random().toString(36).substr(2, 9);

        mockPrisma.securityEvent.create.mockResolvedValue({
          id: securityEventId,
          ...securityEventData,
          details: JSON.stringify(securityEventData.details),
          createdAt: new Date(),
        });

        mockPrisma.auditLog.create.mockResolvedValue({
          id: auditLogId,
          userId: securityEventData.userId || 'anonymous',
          action: 'security_event',
          resourceType: 'security',
          resourceId: securityEventId,
          success: true,
          createdAt: new Date(),
        });

        // Log security event
        const eventId = await auditService.logSecurityEvent(securityEventData);

        // **Verify security event completeness**
        expect(mockPrisma.securityEvent.create).toHaveBeenCalledTimes(1);
        const securityEventCall = mockPrisma.securityEvent.create.mock.calls[0][0];
        const securityEvent = securityEventCall.data;

        expect(securityEvent.id).toBeDefined();
        expect(securityEvent.eventType).toBe(securityEventData.eventType);
        expect(securityEvent.severity).toBe(securityEventData.severity);
        expect(securityEvent.userId).toBe(securityEventData.userId);
        expect(securityEvent.ipAddress).toBe(securityEventData.ipAddress);
        expect(securityEvent.userAgent).toBe(securityEventData.userAgent);
        expect(securityEvent.details).toBe(JSON.stringify(securityEventData.details));

        // **Verify corresponding audit log completeness**
        expect(mockPrisma.auditLog.create).toHaveBeenCalledTimes(1);
        const auditLogCall = mockPrisma.auditLog.create.mock.calls[0][0];
        const auditLog = auditLogCall.data;

        expect(auditLog.id).toBeDefined();
        expect(auditLog.userId).toBe(securityEventData.userId || 'anonymous');
        expect(auditLog.action).toBe('security_event');
        expect(auditLog.resourceType).toBe('security');
        expect(auditLog.resourceId).toBe(eventId);
        expect(auditLog.success).toBe(true);
        expect(auditLog.ipAddress).toBe(securityEventData.ipAddress);
        expect(auditLog.userAgent).toBe(securityEventData.userAgent);

        // Verify metadata contains security event details
        if (auditLog.metadata) {
          const metadata = JSON.parse(auditLog.metadata);
          expect(metadata).toEqual(securityEventData.details);
        }

        expect(eventId).toBe(securityEventId);
      }
    ), { numRuns: 100 });
  });

  /**
   * Property: Audit Report Completeness
   * Generated audit reports must include all relevant audit entries and statistics.
   */
  it('should generate complete audit reports with all required statistics', async () => {
    await fc.assert(fc.asyncProperty(
      fc.record({
        dateRange: fc.record({
          from: fc.date({ min: new Date('2024-01-01'), max: new Date('2024-06-01') }),
          to: fc.date({ min: new Date('2024-06-01'), max: new Date('2024-12-31') }),
        }),
        auditStats: fc.record({
          totalEntries: fc.integer({ min: 0, max: 10000 }),
          successfulActions: fc.integer({ min: 0, max: 8000 }),
          failedActions: fc.integer({ min: 0, max: 2000 }),
        }),
        topActions: fc.array(
          fc.record({
            action: fc.string({ minLength: 3, maxLength: 50 }),
            _count: fc.record({ action: fc.integer({ min: 1, max: 1000 }) }),
          }),
          { minLength: 1, maxLength: 10 }
        ),
        topUsers: fc.array(
          fc.record({
            userId: fc.uuid(),
            _count: fc.record({ userId: fc.integer({ min: 1, max: 500 }) }),
          }),
          { minLength: 1, maxLength: 10 }
        ),
      }),
      async (reportData) => {
        // Ensure date range is valid
        if (reportData.dateRange.from >= reportData.dateRange.to) {
          reportData.dateRange.to = new Date(reportData.dateRange.from.getTime() + 24 * 60 * 60 * 1000);
        }

        // Ensure stats are consistent
        reportData.auditStats.successfulActions = Math.min(
          reportData.auditStats.successfulActions,
          reportData.auditStats.totalEntries
        );
        reportData.auditStats.failedActions = Math.min(
          reportData.auditStats.failedActions,
          reportData.auditStats.totalEntries - reportData.auditStats.successfulActions
        );

        // Mock database responses
        mockPrisma.auditLog.count
          .mockResolvedValueOnce(reportData.auditStats.totalEntries)
          .mockResolvedValueOnce(reportData.auditStats.successfulActions)
          .mockResolvedValueOnce(reportData.auditStats.failedActions);

        mockPrisma.auditLog.groupBy
          .mockResolvedValueOnce(reportData.topActions)
          .mockResolvedValueOnce(reportData.topUsers);

        // Mock user lookups
        const userPromises = reportData.topUsers.map(user => 
          mockPrisma.user.findUnique.mockResolvedValue({
            username: `user_${user.userId.substr(0, 8)}`,
          })
        );

        mockPrisma.securityEvent.groupBy.mockResolvedValue([]);

        // Generate audit report
        const report = await auditService.generateAuditReport(
          reportData.dateRange.from,
          reportData.dateRange.to
        );

        // **Verify report completeness**
        expect(report.totalEntries).toBe(reportData.auditStats.totalEntries);
        expect(report.successfulActions).toBe(reportData.auditStats.successfulActions);
        expect(report.failedActions).toBe(reportData.auditStats.failedActions);

        expect(report.timeRange.from).toEqual(reportData.dateRange.from);
        expect(report.timeRange.to).toEqual(reportData.dateRange.to);
        expect(report.generatedAt).toBeInstanceOf(Date);

        // Verify top actions completeness
        expect(report.topActions).toHaveLength(reportData.topActions.length);
        report.topActions.forEach((action, index) => {
          expect(action.action).toBe(reportData.topActions[index].action);
          expect(action.count).toBe(reportData.topActions[index]._count.action);
        });

        // Verify top users completeness
        expect(report.topUsers).toHaveLength(reportData.topUsers.length);
        report.topUsers.forEach((user, index) => {
          expect(user.userId).toBe(reportData.topUsers[index].userId);
          expect(user.count).toBe(reportData.topUsers[index]._count.userId);
          expect(user.username).toBeDefined();
        });

        // Verify security events array exists
        expect(report.securityEvents).toBeDefined();
        expect(Array.isArray(report.securityEvents)).toBe(true);
      }
    ), { numRuns: 50 });
  });
});