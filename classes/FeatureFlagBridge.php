<?php

/**
 * Feature Flag Bridge for Legacy PHP System
 * Purpose: Integrate feature flags with existing PHP codebase
 * Requirements: TC-3.1
 */

class FeatureFlagBridge
{
    private $redis;
    private $logger;
    private $cachePrefix = 'feature_flags:';
    private $userConfigPrefix = 'user_config:';
    private $defaultTTL = 300; // 5 minutes

    public function __construct($redis = null, $logger = null)
    {
        $this->redis = $redis ?: $this->getRedisConnection();
        $this->logger = $logger ?: new ErrorLogger();
    }

    /**
     * Get feature flags for a specific user
     */
    public function getFeatureFlags($userId, $userRole, $lineAccountId)
    {
        try {
            // Check cached configuration
            $cachedConfig = $this->getCachedUserConfig($userId);
            if ($cachedConfig !== null) {
                return $cachedConfig;
            }

            // Get base feature flags
            $baseFlags = $this->getBaseFeatureFlags();
            
            // Apply user-specific overrides
            $userFlags = $this->getUserSpecificFlags($userId, $userRole, $lineAccountId);
            
            // Merge configurations
            $finalConfig = array_merge($baseFlags, $userFlags);

            // Cache the result
            $this->cacheUserConfig($userId, $finalConfig, $this->defaultTTL);

            $this->logger->info('Feature flags retrieved', [
                'userId' => $userId,
                'userRole' => $userRole,
                'lineAccountId' => $lineAccountId,
                'config' => $finalConfig
            ]);

            return $finalConfig;
        } catch (Exception $e) {
            $this->logger->error('Failed to get feature flags', [
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);
            
            // Return safe defaults on error
            return $this->getDefaultFeatureFlags();
        }
    }

    /**
     * Check if a specific feature is enabled for a user
     */
    public function isFeatureEnabled($featureName, $userId, $userRole, $lineAccountId)
    {
        try {
            $config = $this->getFeatureFlags($userId, $userRole, $lineAccountId);
            return isset($config[$featureName]) ? (bool)$config[$featureName] : false;
        } catch (Exception $e) {
            $this->logger->error('Failed to check feature flag', [
                'featureName' => $featureName,
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);
            return false; // Fail safe - default to disabled
        }
    }

    /**
     * Route request based on feature flags
     */
    public function shouldUseNewSystem($route, $userId, $userRole, $lineAccountId)
    {
        $routeFeatureMap = [
            'dashboard' => 'useNewDashboard',
            'orders' => 'useNewOrderManagement',
            'payments' => 'useNewPaymentProcessing',
            'webhooks' => 'useNewWebhookManagement',
            'customers' => 'useNewCustomerManagement'
        ];

        $featureFlag = null;
        foreach ($routeFeatureMap as $routePattern => $flag) {
            if (strpos($route, $routePattern) !== false) {
                $featureFlag = $flag;
                break;
            }
        }

        if (!$featureFlag) {
            return false; // Default to legacy system for unknown routes
        }

        $enabled = $this->isFeatureEnabled($featureFlag, $userId, $userRole, $lineAccountId);

        // Check gradual rollout if feature is not explicitly enabled
        if (!$enabled && $userId) {
            $rolloutDecision = $this->checkGradualRollout($featureFlag, $userId);
            if ($rolloutDecision['useNewSystem']) {
                $enabled = true;
            }
        }

        // Log routing decision
        $this->logRoutingDecision($route, $userId, $enabled, $featureFlag);

        return $enabled;
    }

    /**
     * Get rollout percentage for gradual deployment
     */
    public function getRolloutPercentage($flagName)
    {
        try {
            $flag = $this->getFeatureFlag($flagName);
            return isset($flag['rolloutPercentage']) ? (int)$flag['rolloutPercentage'] : 0;
        } catch (Exception $e) {
            $this->logger->error('Failed to get rollout percentage', [
                'flagName' => $flagName,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Update rollout percentage (admin function)
     */
    public function updateRolloutPercentage($flagName, $percentage, $updatedBy)
    {
        try {
            if ($percentage < 0 || $percentage > 100) {
                throw new InvalidArgumentException('Rollout percentage must be between 0 and 100');
            }

            $flag = $this->getFeatureFlag($flagName);
            $flag['rolloutPercentage'] = $percentage;
            $flag['updatedAt'] = date('Y-m-d H:i:s');
            $flag['updatedBy'] = $updatedBy;

            $this->redis->hset(
                $this->cachePrefix . $flagName,
                'config',
                json_encode($flag)
            );

            // Invalidate user caches
            $this->invalidateUserCaches();

            $this->logger->info('Rollout percentage updated', [
                'flagName' => $flagName,
                'percentage' => $percentage,
                'updatedBy' => $updatedBy
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update rollout percentage', [
                'flagName' => $flagName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get routing metrics for monitoring
     */
    public function getRoutingMetrics($date = null)
    {
        try {
            $targetDate = $date ?: date('Y-m-d');
            $metricsKey = "routing_metrics:{$targetDate}";
            $metrics = $this->redis->hgetall($metricsKey);

            $processed = [
                'date' => $targetDate,
                'totalRequests' => 0,
                'newSystemRequests' => 0,
                'legacySystemRequests' => 0,
                'routeBreakdown' => []
            ];

            foreach ($metrics as $key => $value) {
                $count = (int)$value;
                $processed['totalRequests'] += $count;

                list($route, $system) = explode(':', $key, 2);
                
                if ($system === 'new') {
                    $processed['newSystemRequests'] += $count;
                } else {
                    $processed['legacySystemRequests'] += $count;
                }

                if (!isset($processed['routeBreakdown'][$route])) {
                    $processed['routeBreakdown'][$route] = [
                        'new' => 0,
                        'legacy' => 0,
                        'total' => 0
                    ];
                }
                
                $processed['routeBreakdown'][$route][$system] = $count;
                $processed['routeBreakdown'][$route]['total'] += $count;
            }

            // Calculate percentages
            if ($processed['totalRequests'] > 0) {
                $processed['newSystemPercentage'] = round(
                    ($processed['newSystemRequests'] / $processed['totalRequests']) * 100
                );
                $processed['legacySystemPercentage'] = round(
                    ($processed['legacySystemRequests'] / $processed['totalRequests']) * 100
                );
            }

            return $processed;
        } catch (Exception $e) {
            $this->logger->error('Failed to get routing metrics', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    // Private helper methods

    private function getBaseFeatureFlags()
    {
        $flags = $this->redis->hgetall($this->cachePrefix . 'base');
        
        if (empty($flags)) {
            return $this->getDefaultFeatureFlags();
        }

        return json_decode($flags['config'] ?? '{}', true);
    }

    private function getUserSpecificFlags($userId, $userRole, $lineAccountId)
    {
        // Check role-based flags
        $roleFlags = $this->redis->hgetall($this->cachePrefix . "role:{$userRole}");
        
        // Check account-specific flags
        $accountFlags = $this->redis->hgetall($this->cachePrefix . "account:{$lineAccountId}");
        
        // Check user-specific flags
        $userFlags = $this->redis->hgetall($this->cachePrefix . "user:{$userId}");

        $merged = array_merge(
            json_decode($roleFlags['config'] ?? '{}', true),
            json_decode($accountFlags['config'] ?? '{}', true),
            json_decode($userFlags['config'] ?? '{}', true)
        );

        return $merged;
    }

    private function getFeatureFlag($flagName)
    {
        $flag = $this->redis->hget($this->cachePrefix . $flagName, 'config');
        
        if (!$flag) {
            throw new Exception("Feature flag '{$flagName}' not found");
        }

        return json_decode($flag, true);
    }

    private function getCachedUserConfig($userId)
    {
        $cached = $this->redis->get($this->userConfigPrefix . $userId);
        return $cached ? json_decode($cached, true) : null;
    }

    private function cacheUserConfig($userId, $config, $ttl)
    {
        $this->redis->setex(
            $this->userConfigPrefix . $userId,
            $ttl,
            json_encode($config)
        );
    }

    private function invalidateUserCaches()
    {
        $keys = $this->redis->keys($this->userConfigPrefix . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    private function checkGradualRollout($featureFlag, $userId)
    {
        try {
            $rolloutPercentage = $this->getRolloutPercentage($featureFlag);
            
            if ($rolloutPercentage === 0) {
                return [
                    'useNewSystem' => false,
                    'reason' => 'Gradual rollout at 0%'
                ];
            }

            if ($rolloutPercentage === 100) {
                return [
                    'useNewSystem' => true,
                    'reason' => 'Gradual rollout at 100%'
                ];
            }

            // Use consistent hashing for stable user assignment
            $userHash = $this->hashUserId($userId . $featureFlag);
            $userPercentile = $userHash % 100;

            $useNewSystem = $userPercentile < $rolloutPercentage;

            return [
                'useNewSystem' => $useNewSystem,
                'reason' => "Gradual rollout {$rolloutPercentage}% - user " . 
                           ($useNewSystem ? 'included' : 'excluded')
            ];
        } catch (Exception $e) {
            $this->logger->error('Gradual rollout check failed', [
                'featureFlag' => $featureFlag,
                'userId' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'useNewSystem' => false,
                'reason' => 'Gradual rollout check failed'
            ];
        }
    }

    private function hashUserId($input)
    {
        $hash = 0;
        $len = strlen($input);
        
        for ($i = 0; $i < $len; $i++) {
            $char = ord($input[$i]);
            $hash = (($hash << 5) - $hash) + $char;
            $hash = $hash & 0xFFFFFFFF; // Convert to 32-bit integer
        }
        
        return abs($hash);
    }

    private function logRoutingDecision($route, $userId, $useNewSystem, $featureFlag)
    {
        $this->logger->info('Traffic routing decision', [
            'route' => $route,
            'userId' => $userId,
            'decision' => $useNewSystem ? 'new-system' : 'legacy-system',
            'featureFlag' => $featureFlag,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Update metrics
        $this->updateRoutingMetrics($route, $useNewSystem);
    }

    private function updateRoutingMetrics($route, $useNewSystem)
    {
        try {
            $metricsKey = 'routing_metrics:' . date('Y-m-d');
            $system = $useNewSystem ? 'new' : 'legacy';
            $metricField = "{$route}:{$system}";

            $this->redis->hincrby($metricsKey, $metricField, 1);
            $this->redis->expire($metricsKey, 86400 * 7); // Keep for 7 days
        } catch (Exception $e) {
            $this->logger->error('Failed to update routing metrics', [
                'route' => $route,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function getDefaultFeatureFlags()
    {
        return [
            'useNewDashboard' => false,
            'useNewOrderManagement' => false,
            'useNewPaymentProcessing' => false,
            'useNewWebhookManagement' => false,
            'useNewCustomerManagement' => false,
            'enableRealTimeUpdates' => false,
            'enablePerformanceOptimizations' => false,
            'enableAdvancedAuditLogging' => false
        ];
    }

    private function getRedisConnection()
    {
        // Use existing Redis connection if available
        if (class_exists('RedisConnection')) {
            return RedisConnection::getInstance();
        }

        // Fallback to new Redis connection
        $redis = new Redis();
        $redis->connect(
            $_ENV['REDIS_HOST'] ?? 'localhost',
            $_ENV['REDIS_PORT'] ?? 6379
        );
        
        if (!empty($_ENV['REDIS_PASSWORD'])) {
            $redis->auth($_ENV['REDIS_PASSWORD']);
        }

        return $redis;
    }
}

/**
 * Simple error logger for feature flag operations
 */
class ErrorLogger
{
    public function info($message, $context = [])
    {
        error_log("INFO: {$message} " . json_encode($context));
    }

    public function error($message, $context = [])
    {
        error_log("ERROR: {$message} " . json_encode($context));
    }
}