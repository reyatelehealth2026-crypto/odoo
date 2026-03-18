import { BaseService } from './BaseService';
import { AuditService } from './AuditService';
import { logger } from '@/utils/logger';
import { PrismaClient } from '@prisma/client';

/**
 * Security Monitoring Service
 * 
 * Provides comprehensive security monitoring including:
 * - Real-time threat detection
 * - Automated incident response
 * - Security metrics and alerting
 * - Integration with audit logging
 * 
 * Requirements: NFR-3.3
 */

export interface SecurityThreat {
  id: string;
  type: 'brute_force' | 'sql_injection' | 'xss_attempt' | 'suspicious_activity' | 'rate_limit_violation' | 'unauthorized_access';
  severity: 'low' | 'medium' | 'high' | 'critical';
  source: {
    ip: string;
    userAgent?: string;
    userId?: string;
    sessionId?: string;
  };
  details: Record<string, any>;
  detectedAt: Date;
  status: 'active' | 'mitigated' | 'false_positive';
  mitigationActions: string[];
}

export interface SecurityMetrics {
  totalThreats: number;
  activeThreats: number;
  blockedIPs: number;
  failedLogins: number;
  suspiciousRequests: number;
  timeRange: { from: Date; to: Date };
  threatsByType: Array<{ type: string; count: number }>;
  threatsBySeverity: Array<{ severity: string; count: number }>;
  topAttackerIPs: Array<{ ip: string; threatCount: number; lastSeen: Date }>;
}

export interface SecurityAlert {
  id: string;
  type: 'threat_detected' | 'threshold_exceeded' | 'system_anomaly';
  severity: 'low' | 'medium' | 'high' | 'critical';
  message: string;
  details: Record<string, any>;
  createdAt: Date;
  acknowledged: boolean;
  acknowledgedBy?: string;
  acknowledgedAt?: Date;
}

export class SecurityMonitoringService extends BaseService {
  private auditService: AuditService;
  private redis: any;
  private threats: Map<string, SecurityThreat> = new Map();
  private alerts: Map<string, SecurityAlert> = new Map();

  // Threat detection thresholds
  private readonly thresholds = {
    bruteForce: {
      failedAttempts: 5,
      timeWindowMs: 15 * 60 * 1000, // 15 minutes
    },
    suspiciousActivity: {
      requestsPerMinute: 300,
      timeWindowMs: 60 * 1000, // 1 minute
    },
    rateLimitViolations: {
      violationsPerHour: 10,
      timeWindowMs: 60 * 60 * 1000, // 1 hour
    },
  };

  constructor(prisma: PrismaClient, auditService: AuditService, redisClient: any) {
    super(prisma);
    this.auditService = auditService;
    this.redis = redisClient;
  }

  /**
   * Detect and analyze security threats
   */
  async detectThreat(
    type: SecurityThreat['type'],
    source: SecurityThreat['source'],
    details: Record<string, any>
  ): Promise<SecurityThreat | null> {
    try {
      const threatId = this.generateUUID();
      const severity = this.calculateThreatSeverity(type, details);
      
      const threat: SecurityThreat = {
        id: threatId,
        type,
        severity,
        source,
        details,
        detectedAt: new Date(),
        status: 'active',
        mitigationActions: [],
      };

      // Store threat
      this.threats.set(threatId, threat);
      await this.storeThreatInRedis(threat);

      // Log security event
      await this.auditService.logSecurityEvent({
        eventType: `threat_detected_${type}`,
        severity,
        userId: source.userId,
        ipAddress: source.ip,
        userAgent: source.userAgent,
        details: {
          threatId,
          threatType: type,
          ...details,
        },
      });

      // Apply automatic mitigation if needed
      await this.applyAutomaticMitigation(threat);

      // Create alert if severity is high or critical
      if (severity === 'high' || severity === 'critical') {
        await this.createSecurityAlert('threat_detected', severity, 
          `${type.replace('_', ' ').toUpperCase()} threat detected from ${source.ip}`, {
            threatId,
            threatType: type,
            source,
            details,
          });
      }

      logger.warn('Security threat detected', {
        threatId,
        type,
        severity,
        source,
        details,
      });

      return threat;
    } catch (error) {
      logger.error('Failed to detect threat', {
        error: String(error),
        type,
        source,
        details,
      });
      return null;
    }
  }

  /**
   * Monitor for brute force attacks
   */
  async monitorBruteForce(ip: string, userId?: string): Promise<void> {
    try {
      const key = `brute_force:${ip}`;
      const now = Date.now();
      const windowStart = now - this.thresholds.bruteForce.timeWindowMs;

      // Get failed attempts in time window
      const attempts = await this.redis.zrangebyscore(key, windowStart, now);
      
      if (attempts.length >= this.thresholds.bruteForce.failedAttempts) {
        await this.detectThreat('brute_force', { ip, userId }, {
          failedAttempts: attempts.length,
          timeWindow: this.thresholds.bruteForce.timeWindowMs,
          attempts: attempts.slice(-5), // Last 5 attempts
        });
      }
    } catch (error) {
      logger.error('Failed to monitor brute force', {
        error: String(error),
        ip,
        userId,
      });
    }
  }

  /**
   * Monitor for SQL injection attempts
   */
  async monitorSqlInjection(ip: string, userAgent: string, payload: any, userId?: string): Promise<void> {
    try {
      const suspiciousPatterns = [
        /(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b.*\b(FROM|WHERE|INTO|VALUES|SET)\b)/gi,
        /(--|\/\*|\*\/|;)\s*(SELECT|INSERT|UPDATE|DELETE|DROP)/gi,
        /(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/gi,
        /\b(UNION|SELECT)\b.*\b(FROM|WHERE)\b/gi,
        /'.*(\bOR\b|\bAND\b).*'/gi,
      ];

      const payloadString = JSON.stringify(payload);
      const detectedPatterns = suspiciousPatterns.filter(pattern => pattern.test(payloadString));

      if (detectedPatterns.length > 0) {
        await this.detectThreat('sql_injection', { ip, userAgent, userId }, {
          payload: payloadString.substring(0, 1000), // Limit payload size in logs
          detectedPatterns: detectedPatterns.map(p => p.source),
          payloadSize: payloadString.length,
        });
      }
    } catch (error) {
      logger.error('Failed to monitor SQL injection', {
        error: String(error),
        ip,
        userId,
      });
    }
  }

  /**
   * Monitor for XSS attempts
   */
  async monitorXssAttempts(ip: string, userAgent: string, payload: any, userId?: string): Promise<void> {
    try {
      const xssPatterns = [
        /<script[^>]*>.*?<\/script>/gi,
        /javascript:/gi,
        /on\w+\s*=\s*["'][^"']*["']/gi,
        /<iframe[^>]*>.*?<\/iframe>/gi,
        /<object[^>]*>.*?<\/object>/gi,
        /<embed[^>]*>/gi,
        /data:text\/html/gi,
      ];

      const payloadString = JSON.stringify(payload);
      const detectedPatterns = xssPatterns.filter(pattern => pattern.test(payloadString));

      if (detectedPatterns.length > 0) {
        await this.detectThreat('xss_attempt', { ip, userAgent, userId }, {
          payload: payloadString.substring(0, 1000),
          detectedPatterns: detectedPatterns.map(p => p.source),
          payloadSize: payloadString.length,
        });
      }
    } catch (error) {
      logger.error('Failed to monitor XSS attempts', {
        error: String(error),
        ip,
        userId,
      });
    }
  }

  /**
   * Monitor for suspicious activity patterns
   */
  async monitorSuspiciousActivity(ip: string, userAgent: string, userId?: string): Promise<void> {
    try {
      const key = `suspicious_activity:${ip}`;
      const now = Date.now();
      const windowStart = now - this.thresholds.suspiciousActivity.timeWindowMs;

      // Count requests in time window
      const requestCount = await this.redis.zcount(key, windowStart, now);
      
      if (requestCount >= this.thresholds.suspiciousActivity.requestsPerMinute) {
        await this.detectThreat('suspicious_activity', { ip, userAgent, userId }, {
          requestCount,
          timeWindow: this.thresholds.suspiciousActivity.timeWindowMs,
          threshold: this.thresholds.suspiciousActivity.requestsPerMinute,
        });
      }
    } catch (error) {
      logger.error('Failed to monitor suspicious activity', {
        error: String(error),
        ip,
        userId,
      });
    }
  }

  /**
   * Apply automatic mitigation actions
   */
  private async applyAutomaticMitigation(threat: SecurityThreat): Promise<void> {
    try {
      const mitigationActions: string[] = [];

      switch (threat.type) {
        case 'brute_force':
          // Block IP for 30 minutes
          await this.blockIP(threat.source.ip, 30 * 60 * 1000, 'brute_force_detected');
          mitigationActions.push('ip_blocked_30min');
          break;

        case 'sql_injection':
        case 'xss_attempt':
          // Block IP for 1 hour
          await this.blockIP(threat.source.ip, 60 * 60 * 1000, `${threat.type}_detected`);
          mitigationActions.push('ip_blocked_1hour');
          break;

        case 'suspicious_activity':
          if (threat.severity === 'high' || threat.severity === 'critical') {
            // Block IP for 15 minutes
            await this.blockIP(threat.source.ip, 15 * 60 * 1000, 'suspicious_activity');
            mitigationActions.push('ip_blocked_15min');
          }
          break;

        case 'rate_limit_violation':
          // Already handled by rate limiting middleware
          mitigationActions.push('rate_limit_applied');
          break;
      }

      // Update threat with mitigation actions
      threat.mitigationActions = mitigationActions;
      threat.status = mitigationActions.length > 0 ? 'mitigated' : 'active';
      
      this.threats.set(threat.id, threat);
      await this.storeThreatInRedis(threat);

      if (mitigationActions.length > 0) {
        logger.info('Automatic mitigation applied', {
          threatId: threat.id,
          threatType: threat.type,
          mitigationActions,
          source: threat.source,
        });
      }
    } catch (error) {
      logger.error('Failed to apply automatic mitigation', {
        error: String(error),
        threatId: threat.id,
        threatType: threat.type,
      });
    }
  }

  /**
   * Block IP address
   */
  private async blockIP(ip: string, durationMs: number, reason: string): Promise<void> {
    const key = `blocked:${ip}`;
    const expirationSeconds = Math.ceil(durationMs / 1000);
    const blockData = {
      reason,
      blockedAt: new Date().toISOString(),
      expiresAt: new Date(Date.now() + durationMs).toISOString(),
    };

    await this.redis.setex(key, expirationSeconds, JSON.stringify(blockData));
    
    logger.warn('IP blocked', {
      ip,
      reason,
      durationMs,
      expiresAt: blockData.expiresAt,
    });
  }

  /**
   * Calculate threat severity based on type and details
   */
  private calculateThreatSeverity(type: SecurityThreat['type'], details: Record<string, any>): SecurityThreat['severity'] {
    switch (type) {
      case 'sql_injection':
      case 'xss_attempt':
        return 'high';

      case 'brute_force':
        const attempts = details.failedAttempts || 0;
        if (attempts >= 20) return 'critical';
        if (attempts >= 10) return 'high';
        return 'medium';

      case 'suspicious_activity':
        const requestCount = details.requestCount || 0;
        if (requestCount >= 1000) return 'critical';
        if (requestCount >= 500) return 'high';
        return 'medium';

      case 'rate_limit_violation':
        return 'low';

      case 'unauthorized_access':
        return 'high';

      default:
        return 'medium';
    }
  }

  /**
   * Create security alert
   */
  private async createSecurityAlert(
    type: SecurityAlert['type'],
    severity: SecurityAlert['severity'],
    message: string,
    details: Record<string, any>
  ): Promise<string> {
    const alertId = this.generateUUID();
    
    const alert: SecurityAlert = {
      id: alertId,
      type,
      severity,
      message,
      details,
      createdAt: new Date(),
      acknowledged: false,
    };

    this.alerts.set(alertId, alert);
    await this.storeAlertInRedis(alert);

    logger.warn('Security alert created', {
      alertId,
      type,
      severity,
      message,
    });

    return alertId;
  }

  /**
   * Get security metrics
   */
  async getSecurityMetrics(days: number = 7): Promise<SecurityMetrics> {
    try {
      const toDate = new Date();
      const fromDate = new Date();
      fromDate.setDate(fromDate.getDate() - days);

      // Get threats from Redis and database
      const threatKeys = await this.redis.keys('threat:*');
      const threats: SecurityThreat[] = [];
      
      for (const key of threatKeys) {
        const threatData = await this.redis.get(key);
        if (threatData) {
          const threat = JSON.parse(threatData);
          if (new Date(threat.detectedAt) >= fromDate) {
            threats.push(threat);
          }
        }
      }

      // Calculate metrics
      const totalThreats = threats.length;
      const activeThreats = threats.filter(t => t.status === 'active').length;
      
      // Get blocked IPs count
      const blockedIPKeys = await this.redis.keys('blocked:*');
      const blockedIPs = blockedIPKeys.length;

      // Get failed logins from audit logs
      const failedLogins = await this.prisma.auditLog.count({
        where: {
          action: 'login',
          success: false,
          createdAt: {
            gte: fromDate,
            lte: toDate,
          },
        },
      });

      // Calculate suspicious requests
      const suspiciousRequests = threats.filter(t => t.type === 'suspicious_activity').length;

      // Group threats by type
      const threatsByType = threats.reduce((acc, threat) => {
        const existing = acc.find(item => item.type === threat.type);
        if (existing) {
          existing.count++;
        } else {
          acc.push({ type: threat.type, count: 1 });
        }
        return acc;
      }, [] as Array<{ type: string; count: number }>);

      // Group threats by severity
      const threatsBySeverity = threats.reduce((acc, threat) => {
        const existing = acc.find(item => item.severity === threat.severity);
        if (existing) {
          existing.count++;
        } else {
          acc.push({ severity: threat.severity, count: 1 });
        }
        return acc;
      }, [] as Array<{ severity: string; count: number }>);

      // Get top attacker IPs
      const ipCounts = threats.reduce((acc, threat) => {
        const ip = threat.source.ip;
        if (acc[ip]) {
          acc[ip].count++;
          if (new Date(threat.detectedAt) > acc[ip].lastSeen) {
            acc[ip].lastSeen = new Date(threat.detectedAt);
          }
        } else {
          acc[ip] = {
            count: 1,
            lastSeen: new Date(threat.detectedAt),
          };
        }
        return acc;
      }, {} as Record<string, { count: number; lastSeen: Date }>);

      const topAttackerIPs = Object.entries(ipCounts)
        .map(([ip, data]) => ({
          ip,
          threatCount: data.count,
          lastSeen: data.lastSeen,
        }))
        .sort((a, b) => b.threatCount - a.threatCount)
        .slice(0, 10);

      return {
        totalThreats,
        activeThreats,
        blockedIPs,
        failedLogins,
        suspiciousRequests,
        timeRange: { from: fromDate, to: toDate },
        threatsByType,
        threatsBySeverity,
        topAttackerIPs,
      };
    } catch (error) {
      logger.error('Failed to get security metrics', {
        error: String(error),
        days,
      });
      throw error;
    }
  }

  /**
   * Get active security alerts
   */
  async getActiveAlerts(): Promise<SecurityAlert[]> {
    try {
      const alertKeys = await this.redis.keys('alert:*');
      const alerts: SecurityAlert[] = [];
      
      for (const key of alertKeys) {
        const alertData = await this.redis.get(key);
        if (alertData) {
          const alert = JSON.parse(alertData);
          if (!alert.acknowledged) {
            alerts.push(alert);
          }
        }
      }

      return alerts.sort((a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime());
    } catch (error) {
      logger.error('Failed to get active alerts', {
        error: String(error),
      });
      return [];
    }
  }

  /**
   * Acknowledge security alert
   */
  async acknowledgeAlert(alertId: string, acknowledgedBy: string): Promise<boolean> {
    try {
      const alert = this.alerts.get(alertId);
      if (!alert) {
        const alertData = await this.redis.get(`alert:${alertId}`);
        if (!alertData) return false;
        
        const parsedAlert = JSON.parse(alertData);
        this.alerts.set(alertId, parsedAlert);
      }

      const updatedAlert = this.alerts.get(alertId)!;
      updatedAlert.acknowledged = true;
      updatedAlert.acknowledgedBy = acknowledgedBy;
      updatedAlert.acknowledgedAt = new Date();

      this.alerts.set(alertId, updatedAlert);
      await this.storeAlertInRedis(updatedAlert);

      logger.info('Security alert acknowledged', {
        alertId,
        acknowledgedBy,
      });

      return true;
    } catch (error) {
      logger.error('Failed to acknowledge alert', {
        error: String(error),
        alertId,
        acknowledgedBy,
      });
      return false;
    }
  }

  /**
   * Store threat in Redis
   */
  private async storeThreatInRedis(threat: SecurityThreat): Promise<void> {
    const key = `threat:${threat.id}`;
    const expirationSeconds = 7 * 24 * 60 * 60; // 7 days
    await this.redis.setex(key, expirationSeconds, JSON.stringify(threat));
  }

  /**
   * Store alert in Redis
   */
  private async storeAlertInRedis(alert: SecurityAlert): Promise<void> {
    const key = `alert:${alert.id}`;
    const expirationSeconds = 30 * 24 * 60 * 60; // 30 days
    await this.redis.setex(key, expirationSeconds, JSON.stringify(alert));
  }

  /**
   * Generate UUID v4
   */
  private generateUUID(): string {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      const r = Math.random() * 16 | 0;
      const v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }
}