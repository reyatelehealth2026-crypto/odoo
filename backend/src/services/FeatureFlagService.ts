/**
 * Feature Flag Service for Gradual Migration
 * Purpose: Control traffic routing between legacy and new systems
 * Requirements: TC-3.1
 */

import { Redis } from 'ioredis';
import { Logger } from './LoggingService';

export interface FeatureFlag {
  name: string;
  enabled: boolean;
  rolloutPercentage: number;
  userGroups: string[];
  startDate?: Date;
  endDate?: Date;
  description: string;
  createdBy: string;
  createdAt: Date;
  updatedAt: Date;
}

export interface FeatureFlagConfig {
  useNewDashboard: boolean;
  useNewOrderManagement: boolean;
  useNewPaymentProcessing: boolean;
  useNewWebhookManagement: boolean;
  useNewCustomerManagement: boolean;
  enableRealTimeUpdates: boolean;
  enablePerformanceOptimizations: boolean;
  enableAdvancedAuditLogging: boolean;
}

export interface ABTestConfig {
  testName: string;
  variants: {
    name: string;
    percentage: number;
    config: Partial<FeatureFlagConfig>;
  }[];
  startDate: Date;
  endDate: Date;
  targetUserGroups?: string[];
}

export class FeatureFlagService {
  private redis: Redis;
  private logger: Logger;
  private cachePrefix = 'feature_flags:';
  private abTestPrefix = 'ab_tests:';
  private userAssignmentPrefix = 'user_assignments:';

  constructor(redis: Redis, logger: Logger) {
    this.redis = redis;
    this.logger = logger;
  }

  /**
   * Get feature flag configuration for a specific user
   */
  async getFeatureFlags(
    userId: string,
    userRole: string,
    lineAccountId: string
  ): Promise<FeatureFlagConfig> {
    try {
      // Check for cached user assignment
      const cachedConfig = await this.getCachedUserConfig(userId);
      if (cachedConfig) {
        return cachedConfig;
      }

      // Get base feature flags
      const baseFlags = await this.getBaseFeatureFlags();
      
      // Apply user-specific overrides
      const userFlags = await this.getUserSpecificFlags(userId, userRole, lineAccountId);
      
      // Apply A/B test assignments
      const abTestFlags = await this.getABTestAssignment(userId, userRole);
      
      // Merge configurations (A/B tests take precedence)
      const finalConfig: FeatureFlagConfig = {
        ...baseFlags,
        ...userFlags,
        ...abTestFlags
      };

      // Cache the result for 5 minutes
      await this.cacheUserConfig(userId, finalConfig, 300);

      this.logger.info('Feature flags retrieved', {
        userId,
        userRole,
        lineAccountId,
        config: finalConfig
      });

      return finalConfig;
    } catch (error) {
      this.logger.error('Failed to get feature flags', { userId, error });
      // Return safe defaults on error
      return this.getDefaultFeatureFlags();
    }
  }

  /**
   * Update feature flag configuration
   */
  async updateFeatureFlag(
    flagName: string,
    config: Partial<FeatureFlag>,
    updatedBy: string
  ): Promise<void> {
    try {
      const existingFlag = await this.getFeatureFlag(flagName);
      
      const updatedFlag: FeatureFlag = {
        ...existingFlag,
        ...config,
        updatedAt: new Date()
      };

      await this.redis.hset(
        `${this.cachePrefix}${flagName}`,
        'config',
        JSON.stringify(updatedFlag)
      );

      // Invalidate user caches
      await this.invalidateUserCaches();

      this.logger.info('Feature flag updated', {
        flagName,
        updatedBy,
        config: updatedFlag
      });
    } catch (error) {
      this.logger.error('Failed to update feature flag', { flagName, error });
      throw error;
    }
  }

  /**
   * Create A/B test configuration
   */
  async createABTest(
    testConfig: ABTestConfig,
    createdBy: string
  ): Promise<void> {
    try {
      // Validate test configuration
      this.validateABTestConfig(testConfig);

      const testData = {
        ...testConfig,
        createdBy,
        createdAt: new Date(),
        status: 'active'
      };

      await this.redis.hset(
        `${this.abTestPrefix}${testConfig.testName}`,
        'config',
        JSON.stringify(testData)
      );

      // Set expiration based on end date
      const ttl = Math.floor((testConfig.endDate.getTime() - Date.now()) / 1000);
      if (ttl > 0) {
        await this.redis.expire(`${this.abTestPrefix}${testConfig.testName}`, ttl);
      }

      this.logger.info('A/B test created', {
        testName: testConfig.testName,
        createdBy,
        variants: testConfig.variants.length
      });
    } catch (error) {
      this.logger.error('Failed to create A/B test', { testConfig, error });
      throw error;
    }
  }

  /**
   * Get user's A/B test assignment
   */
  async getABTestAssignment(
    userId: string,
    userRole: string
  ): Promise<Partial<FeatureFlagConfig>> {
    try {
      // Check for existing assignment
      const existingAssignment = await this.redis.get(
        `${this.userAssignmentPrefix}${userId}`
      );

      if (existingAssignment) {
        return JSON.parse(existingAssignment);
      }

      // Get active A/B tests
      const activeTests = await this.getActiveABTests();
      let finalConfig: Partial<FeatureFlagConfig> = {};

      for (const test of activeTests) {
        // Check if user is eligible for this test
        if (this.isUserEligibleForTest(test, userRole)) {
          const assignment = this.assignUserToVariant(userId, test);
          finalConfig = { ...finalConfig, ...assignment.config };

          // Log assignment for analytics
          this.logger.info('User assigned to A/B test variant', {
            userId,
            testName: test.testName,
            variant: assignment.name,
            userRole
          });
        }
      }

      // Cache assignment for consistency
      if (Object.keys(finalConfig).length > 0) {
        await this.redis.setex(
          `${this.userAssignmentPrefix}${userId}`,
          3600, // 1 hour
          JSON.stringify(finalConfig)
        );
      }

      return finalConfig;
    } catch (error) {
      this.logger.error('Failed to get A/B test assignment', { userId, error });
      return {};
    }
  }

  /**
   * Check if a specific feature is enabled for a user
   */
  async isFeatureEnabled(
    featureName: keyof FeatureFlagConfig,
    userId: string,
    userRole: string,
    lineAccountId: string
  ): Promise<boolean> {
    try {
      const config = await this.getFeatureFlags(userId, userRole, lineAccountId);
      return config[featureName] || false;
    } catch (error) {
      this.logger.error('Failed to check feature flag', { featureName, userId, error });
      return false; // Fail safe - default to disabled
    }
  }

  /**
   * Get rollout percentage for gradual deployment
   */
  async getRolloutPercentage(flagName: string): Promise<number> {
    try {
      const flag = await this.getFeatureFlag(flagName);
      return flag.rolloutPercentage;
    } catch (error) {
      this.logger.error('Failed to get rollout percentage', { flagName, error });
      return 0;
    }
  }

  /**
   * Update rollout percentage for gradual deployment
   */
  async updateRolloutPercentage(
    flagName: string,
    percentage: number,
    updatedBy: string
  ): Promise<void> {
    try {
      if (percentage < 0 || percentage > 100) {
        throw new Error('Rollout percentage must be between 0 and 100');
      }

      await this.updateFeatureFlag(
        flagName,
        { rolloutPercentage: percentage },
        updatedBy
      );

      this.logger.info('Rollout percentage updated', {
        flagName,
        percentage,
        updatedBy
      });
    } catch (error) {
      this.logger.error('Failed to update rollout percentage', { flagName, error });
      throw error;
    }
  }

  /**
   * Get analytics data for A/B tests
   */
  async getABTestAnalytics(testName: string): Promise<any> {
    try {
      const analyticsKey = `ab_analytics:${testName}`;
      const analytics = await this.redis.hgetall(analyticsKey);
      
      return {
        testName,
        totalUsers: parseInt(analytics.totalUsers || '0'),
        variantDistribution: JSON.parse(analytics.variantDistribution || '{}'),
        conversionRates: JSON.parse(analytics.conversionRates || '{}'),
        lastUpdated: analytics.lastUpdated
      };
    } catch (error) {
      this.logger.error('Failed to get A/B test analytics', { testName, error });
      return null;
    }
  }

  // Private helper methods

  private async getBaseFeatureFlags(): Promise<FeatureFlagConfig> {
    const flags = await this.redis.hgetall(`${this.cachePrefix}base`);
    
    if (Object.keys(flags).length === 0) {
      return this.getDefaultFeatureFlags();
    }

    return JSON.parse(flags.config || '{}');
  }

  private async getUserSpecificFlags(
    userId: string,
    userRole: string,
    lineAccountId: string
  ): Promise<Partial<FeatureFlagConfig>> {
    // Check role-based flags
    const roleFlags = await this.redis.hgetall(`${this.cachePrefix}role:${userRole}`);
    
    // Check account-specific flags
    const accountFlags = await this.redis.hgetall(`${this.cachePrefix}account:${lineAccountId}`);
    
    // Check user-specific flags
    const userFlags = await this.redis.hgetall(`${this.cachePrefix}user:${userId}`);

    const merged = {
      ...(roleFlags.config ? JSON.parse(roleFlags.config) : {}),
      ...(accountFlags.config ? JSON.parse(accountFlags.config) : {}),
      ...(userFlags.config ? JSON.parse(userFlags.config) : {})
    };

    return merged;
  }

  private async getFeatureFlag(flagName: string): Promise<FeatureFlag> {
    const flag = await this.redis.hget(`${this.cachePrefix}${flagName}`, 'config');
    
    if (!flag) {
      throw new Error(`Feature flag '${flagName}' not found`);
    }

    return JSON.parse(flag);
  }

  private async getCachedUserConfig(userId: string): Promise<FeatureFlagConfig | null> {
    const cached = await this.redis.get(`user_config:${userId}`);
    return cached ? JSON.parse(cached) : null;
  }

  private async cacheUserConfig(
    userId: string,
    config: FeatureFlagConfig,
    ttl: number
  ): Promise<void> {
    await this.redis.setex(`user_config:${userId}`, ttl, JSON.stringify(config));
  }

  private async invalidateUserCaches(): Promise<void> {
    const keys = await this.redis.keys('user_config:*');
    if (keys.length > 0) {
      await this.redis.del(...keys);
    }
  }

  private async getActiveABTests(): Promise<ABTestConfig[]> {
    const testKeys = await this.redis.keys(`${this.abTestPrefix}*`);
    const tests: ABTestConfig[] = [];

    for (const key of testKeys) {
      const testData = await this.redis.hget(key, 'config');
      if (testData) {
        const test = JSON.parse(testData);
        const now = new Date();
        
        if (new Date(test.startDate) <= now && new Date(test.endDate) >= now) {
          tests.push(test);
        }
      }
    }

    return tests;
  }

  private isUserEligibleForTest(test: ABTestConfig, userRole: string): boolean {
    if (!test.targetUserGroups || test.targetUserGroups.length === 0) {
      return true;
    }
    
    return test.targetUserGroups.includes(userRole);
  }

  private assignUserToVariant(userId: string, test: ABTestConfig): any {
    // Use consistent hashing for stable assignment
    const hash = this.hashUserId(userId + test.testName);
    const percentage = hash % 100;
    
    let cumulativePercentage = 0;
    for (const variant of test.variants) {
      cumulativePercentage += variant.percentage;
      if (percentage < cumulativePercentage) {
        return variant;
      }
    }
    
    // Fallback to first variant
    return test.variants[0];
  }

  private hashUserId(input: string): number {
    let hash = 0;
    for (let i = 0; i < input.length; i++) {
      const char = input.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash; // Convert to 32-bit integer
    }
    return Math.abs(hash);
  }

  private validateABTestConfig(config: ABTestConfig): void {
    if (!config.testName || config.testName.trim() === '') {
      throw new Error('Test name is required');
    }

    if (!config.variants || config.variants.length === 0) {
      throw new Error('At least one variant is required');
    }

    const totalPercentage = config.variants.reduce((sum, variant) => sum + variant.percentage, 0);
    if (Math.abs(totalPercentage - 100) > 0.01) {
      throw new Error('Variant percentages must sum to 100');
    }

    if (config.startDate >= config.endDate) {
      throw new Error('Start date must be before end date');
    }
  }

  private getDefaultFeatureFlags(): FeatureFlagConfig {
    return {
      useNewDashboard: false,
      useNewOrderManagement: false,
      useNewPaymentProcessing: false,
      useNewWebhookManagement: false,
      useNewCustomerManagement: false,
      enableRealTimeUpdates: false,
      enablePerformanceOptimizations: false,
      enableAdvancedAuditLogging: false
    };
  }
}