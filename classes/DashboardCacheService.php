<?php
/**
 * Dashboard Cache Service
 * 
 * Manages dashboard metrics caching and API response caching for performance optimization.
 * Implements multi-layer caching strategy with TTL-based expiration and analytics.
 * 
 * Requirements: BR-1.4 (Cache hit rate >85%), NFR-1.4 (Multi-layer caching)
 * 
 * @version 1.0.0
 * @created 2026-01-23
 * @spec odoo-dashboard-modernization
 */

class DashboardCacheService
{
    private $db;
    private $defaultTtlMinutes;
    private $apiCacheTtlMinutes;
    private $maxCacheSizeMb;
    
    public function __construct($database = null)
    {
        $this->db = $database ?: Database::getInstance()->getConnection();
        $this->loadCacheSettings();
    }
    
    /**
     * Load cache configuration from settings table
     */
    private function loadCacheSettings()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT `key`, `value` 
                FROM settings 
                WHERE `key` IN ('dashboard_cache_ttl_minutes', 'api_cache_ttl_minutes', 'cache_max_size_mb')
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $this->defaultTtlMinutes = (int)($settings['dashboard_cache_ttl_minutes'] ?? 30);
            $this->apiCacheTtlMinutes = (int)($settings['api_cache_ttl_minutes'] ?? 15);
            $this->maxCacheSizeMb = (int)($settings['cache_max_size_mb'] ?? 500);
        } catch (Exception $e) {
            // Fallback to defaults if settings table is not available
            $this->defaultTtlMinutes = 30;
            $this->apiCacheTtlMinutes = 15;
            $this->maxCacheSizeMb = 500;
        }
    }
    
    /**
     * Get dashboard metrics from cache or generate if not cached
     * 
     * @param int $lineAccountId LINE account ID
     * @param string $metricType Type of metric (orders, payments, webhooks, customers, overview)
     * @param string $dateKey Date key (YYYY-MM-DD format)
     * @param string $timeRange Time range (today, week, month, quarter, year, custom)
     * @param callable $generator Function to generate metrics if not cached
     * @return array Cached or generated metrics data
     */
    public function getDashboardMetrics($lineAccountId, $metricType, $dateKey, $timeRange = 'today', $generator = null)
    {
        $cacheKey = $this->generateDashboardCacheKey($lineAccountId, $metricType, $dateKey, $timeRange);
        
        // Try to get from cache first
        $cached = $this->getDashboardCache($cacheKey);
        if ($cached !== null) {
            $this->incrementHitCount('dashboard_metrics_cache', $cacheKey);
            return $cached;
        }
        
        // Generate new data if generator provided
        if ($generator && is_callable($generator)) {
            $data = $generator();
            $this->setDashboardCache($lineAccountId, $metricType, $dateKey, $timeRange, $data);
            return $data;
        }
        
        return null;
    }
    
    /**
     * Set dashboard metrics cache
     * 
     * @param int $lineAccountId LINE account ID
     * @param string $metricType Type of metric
     * @param string $dateKey Date key
     * @param string $timeRange Time range
     * @param array $data Metrics data to cache
     * @param int $ttlMinutes TTL in minutes (optional)
     * @return bool Success status
     */
    public function setDashboardCache($lineAccountId, $metricType, $dateKey, $timeRange, $data, $ttlMinutes = null)
    {
        try {
            $ttlMinutes = $ttlMinutes ?: $this->defaultTtlMinutes;
            $cacheKey = $this->generateDashboardCacheKey($lineAccountId, $metricType, $dateKey, $timeRange);
            $id = $this->generateUUID();
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlMinutes} minutes"));
            
            $stmt = $this->db->prepare("
                INSERT INTO dashboard_metrics_cache 
                (id, line_account_id, metric_type, date_key, time_range, data, cache_key, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                data = VALUES(data),
                expires_at = VALUES(expires_at),
                updated_at = NOW()
            ");
            
            return $stmt->execute([
                $id,
                $lineAccountId,
                $metricType,
                $dateKey,
                $timeRange,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                $cacheKey,
                $expiresAt
            ]);
        } catch (Exception $e) {
            error_log("Dashboard cache set error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get API response from cache
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $params Request parameters
     * @param int $lineAccountId LINE account ID (optional)
     * @return array|null Cached response data or null if not found
     */
    public function getApiCache($endpoint, $method = 'GET', $params = [], $lineAccountId = null)
    {
        $cacheKey = $this->generateApiCacheKey($endpoint, $method, $params, $lineAccountId);
        
        try {
            $stmt = $this->db->prepare("
                SELECT data, headers, status_code, content_type
                FROM api_cache 
                WHERE cache_key = ? AND expires_at > NOW()
            ");
            $stmt->execute([$cacheKey]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $this->incrementHitCount('api_cache', $cacheKey);
                return [
                    'data' => json_decode($result['data'], true),
                    'headers' => json_decode($result['headers'], true),
                    'status_code' => (int)$result['status_code'],
                    'content_type' => $result['content_type']
                ];
            }
            
            return null;
        } catch (Exception $e) {
            error_log("API cache get error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Set API response cache
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $params Request parameters
     * @param array $responseData Response data to cache
     * @param array $headers Response headers (optional)
     * @param int $statusCode HTTP status code (optional)
     * @param string $contentType Content type (optional)
     * @param int $lineAccountId LINE account ID (optional)
     * @param int $ttlMinutes TTL in minutes (optional)
     * @return bool Success status
     */
    public function setApiCache($endpoint, $method, $params, $responseData, $headers = [], $statusCode = 200, $contentType = 'application/json', $lineAccountId = null, $ttlMinutes = null)
    {
        try {
            $ttlMinutes = $ttlMinutes ?: $this->apiCacheTtlMinutes;
            $cacheKey = $this->generateApiCacheKey($endpoint, $method, $params, $lineAccountId);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlMinutes} minutes"));
            $dataJson = json_encode($responseData, JSON_UNESCAPED_UNICODE);
            $sizeBytes = strlen($dataJson);
            
            $stmt = $this->db->prepare("
                INSERT INTO api_cache 
                (cache_key, endpoint, method, data, headers, status_code, content_type, 
                 line_account_id, expires_at, size_bytes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                data = VALUES(data),
                headers = VALUES(headers),
                status_code = VALUES(status_code),
                expires_at = VALUES(expires_at),
                size_bytes = VALUES(size_bytes),
                created_at = NOW()
            ");
            
            return $stmt->execute([
                $cacheKey,
                $endpoint,
                $method,
                $dataJson,
                json_encode($headers, JSON_UNESCAPED_UNICODE),
                $statusCode,
                $contentType,
                $lineAccountId,
                $expiresAt,
                $sizeBytes
            ]);
        } catch (Exception $e) {
            error_log("API cache set error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidate dashboard cache by criteria
     * 
     * @param int $lineAccountId LINE account ID (optional)
     * @param string $metricType Metric type to invalidate (optional)
     * @param array $tags Cache tags for group invalidation (optional)
     * @return int Number of entries invalidated
     */
    public function invalidateDashboardCache($lineAccountId = null, $metricType = null, $tags = [])
    {
        try {
            $conditions = [];
            $params = [];
            
            if ($lineAccountId) {
                $conditions[] = "line_account_id = ?";
                $params[] = $lineAccountId;
            }
            
            if ($metricType) {
                $conditions[] = "metric_type = ?";
                $params[] = $metricType;
            }
            
            $whereClause = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
            
            $stmt = $this->db->prepare("DELETE FROM dashboard_metrics_cache {$whereClause}");
            $stmt->execute($params);
            $entriesCleared = $stmt->rowCount();
            
            // Log invalidation
            $this->logCacheInvalidation('dashboard_metrics', 'manual', null, $tags, $lineAccountId, null, $entriesCleared);
            
            return $entriesCleared;
        } catch (Exception $e) {
            error_log("Dashboard cache invalidation error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Invalidate API cache by criteria
     * 
     * @param string $endpoint Endpoint pattern to invalidate (optional)
     * @param int $lineAccountId LINE account ID (optional)
     * @param array $tags Cache tags for group invalidation (optional)
     * @return int Number of entries invalidated
     */
    public function invalidateApiCache($endpoint = null, $lineAccountId = null, $tags = [])
    {
        try {
            $conditions = [];
            $params = [];
            
            if ($endpoint) {
                $conditions[] = "endpoint LIKE ?";
                $params[] = "%{$endpoint}%";
            }
            
            if ($lineAccountId) {
                $conditions[] = "line_account_id = ?";
                $params[] = $lineAccountId;
            }
            
            $whereClause = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
            
            $stmt = $this->db->prepare("DELETE FROM api_cache {$whereClause}");
            $stmt->execute($params);
            $entriesCleared = $stmt->rowCount();
            
            // Log invalidation
            $this->logCacheInvalidation('api_response', 'manual', null, $tags, $lineAccountId, null, $entriesCleared);
            
            return $entriesCleared;
        } catch (Exception $e) {
            error_log("API cache invalidation error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get cache statistics
     * 
     * @param int $lineAccountId LINE account ID (optional)
     * @param string $cacheType Cache type (optional)
     * @param int $days Number of days to include (default: 7)
     * @return array Cache statistics
     */
    public function getCacheStatistics($lineAccountId = null, $cacheType = null, $days = 7)
    {
        try {
            $conditions = ["date_key >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"];
            $params = [$days];
            
            if ($lineAccountId) {
                $conditions[] = "line_account_id = ?";
                $params[] = $lineAccountId;
            }
            
            if ($cacheType) {
                $conditions[] = "cache_type = ?";
                $params[] = $cacheType;
            }
            
            $whereClause = "WHERE " . implode(" AND ", $conditions);
            
            $stmt = $this->db->prepare("
                SELECT 
                    cache_type,
                    SUM(total_requests) as total_requests,
                    SUM(cache_hits) as cache_hits,
                    SUM(cache_misses) as cache_misses,
                    AVG(hit_rate) as avg_hit_rate,
                    AVG(avg_response_time_ms) as avg_response_time,
                    SUM(total_size_mb) as total_size_mb,
                    SUM(evictions) as total_evictions
                FROM cache_statistics 
                {$whereClause}
                GROUP BY cache_type
                ORDER BY cache_type
            ");
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Cache statistics error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate dashboard cache key
     */
    private function generateDashboardCacheKey($lineAccountId, $metricType, $dateKey, $timeRange)
    {
        return "dashboard:{$lineAccountId}:{$metricType}:{$dateKey}:{$timeRange}";
    }
    
    /**
     * Generate API cache key
     */
    private function generateApiCacheKey($endpoint, $method, $params, $lineAccountId)
    {
        $paramString = $params ? md5(json_encode($params)) : '';
        $accountString = $lineAccountId ? ":{$lineAccountId}" : '';
        return "api:{$method}:{$endpoint}{$accountString}:{$paramString}";
    }
    
    /**
     * Get dashboard cache by key
     */
    private function getDashboardCache($cacheKey)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT data 
                FROM dashboard_metrics_cache 
                WHERE cache_key = ? AND expires_at > NOW()
            ");
            $stmt->execute([$cacheKey]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? json_decode($result['data'], true) : null;
        } catch (Exception $e) {
            error_log("Dashboard cache get error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Increment cache hit count
     */
    private function incrementHitCount($table, $cacheKey)
    {
        try {
            if ($table === 'dashboard_metrics_cache') {
                $stmt = $this->db->prepare("
                    UPDATE dashboard_metrics_cache 
                    SET hit_count = hit_count + 1 
                    WHERE cache_key = ?
                ");
            } else {
                $stmt = $this->db->prepare("
                    UPDATE api_cache 
                    SET hit_count = hit_count + 1, last_hit_at = NOW() 
                    WHERE cache_key = ?
                ");
            }
            $stmt->execute([$cacheKey]);
        } catch (Exception $e) {
            error_log("Hit count increment error: " . $e->getMessage());
        }
    }
    
    /**
     * Log cache invalidation event
     */
    private function logCacheInvalidation($cacheType, $invalidationType, $cacheKeys = null, $tags = [], $lineAccountId = null, $triggerEvent = null, $entriesCleared = 0)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cache_invalidation_log 
                (cache_type, invalidation_type, cache_keys, tags, line_account_id, trigger_event, entries_cleared)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $cacheType,
                $invalidationType,
                $cacheKeys ? json_encode($cacheKeys) : null,
                $tags ? json_encode($tags) : null,
                $lineAccountId,
                $triggerEvent,
                $entriesCleared
            ]);
        } catch (Exception $e) {
            error_log("Cache invalidation log error: " . $e->getMessage());
        }
    }
    
    /**
     * Generate UUID for cache entries
     */
    private function generateUUID()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Clean up expired cache entries manually
     * 
     * @param string $cacheType Type of cache to clean (dashboard_metrics, api_response, or both)
     * @return array Cleanup results
     */
    public function cleanupExpiredCache($cacheType = 'both')
    {
        $results = [];
        
        try {
            if ($cacheType === 'dashboard_metrics' || $cacheType === 'both') {
                $stmt = $this->db->prepare("DELETE FROM dashboard_metrics_cache WHERE expires_at < NOW()");
                $stmt->execute();
                $results['dashboard_metrics'] = $stmt->rowCount();
            }
            
            if ($cacheType === 'api_response' || $cacheType === 'both') {
                $stmt = $this->db->prepare("DELETE FROM api_cache WHERE expires_at < NOW()");
                $stmt->execute();
                $results['api_response'] = $stmt->rowCount();
            }
            
            // Log cleanup
            foreach ($results as $type => $count) {
                if ($count > 0) {
                    $this->logCacheInvalidation($type, 'ttl_expired', null, [], null, 'manual_cleanup', $count);
                }
            }
            
            return $results;
        } catch (Exception $e) {
            error_log("Cache cleanup error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}