<?php
/**
 * Redis Cache Implementation for Odoo Dashboard
 * Comprehensive caching layer for all APIs
 * 
 * Redis Cloud: redis-13718.fcrce172.us-east-1-1.ec2.cloud.redislabs.com:13718
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Predis\Client;

class OdooRedisCache {
    private static $instance = null;
    private $redis = null;
    private $enabled = false;
    private $hitCount = 0;
    private $missCount = 0;
    
    // Cache TTL Constants (seconds)
    const TTL_OVERVIEW = 60;           // 1 minute - เปลี่ยนบ่อย
    const TTL_STATS = 30;              // 30 seconds - real-time
    const TTL_WEBHOOKS = 30;           // 30 seconds
    const TTL_SLIPS = 60;              // 1 minute
    const TTL_CUSTOMERS = 300;         // 5 minutes
    const TTL_ORDERS = 120;            // 2 minutes
    const TTL_BDO = 180;               // 3 minutes
    const TTL_NOTIFICATIONS = 30;      // 30 seconds
    const TTL_STATIC = 3600;           // 1 hour - ข้อมูลไม่ค่อยเปลี่ยน
    
    private function __construct() {
        $this->connect();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        try {
            $this->redis = new Client([
                'host' => 'redis-13718.fcrce172.us-east-1-1.ec2.cloud.redislabs.com',
                'port' => 13718,
                'database' => 0,
                'username' => 'default',
                'password' => '8aOsi5ZlcevxIxkXOFn4b4qshhMTHKC5',
                'timeout' => 3.0,
                'read_timeout' => 3.0,
            ]);
            
            // Test connection
            $this->redis->ping();
            $this->enabled = true;
            
        } catch (Exception $e) {
            error_log("[Redis] Connection failed: " . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    /**
     * Get value from cache
     */
    public function get($key) {
        if (!$this->enabled) return null;
        
        try {
            $value = $this->redis->get($key);
            if ($value !== null) {
                $this->hitCount++;
                return json_decode($value, true);
            }
            $this->missCount++;
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Set value to cache
     */
    public function set($key, $value, $ttl = 300) {
        if (!$this->enabled) return false;
        
        try {
            return $this->redis->setex($key, $ttl, json_encode($value));
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Delete key from cache
     */
    public function delete($key) {
        if (!$this->enabled) return false;
        
        try {
            return $this->redis->del($key) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Delete multiple keys by pattern
     */
    public function deletePattern($pattern) {
        if (!$this->enabled) return 0;
        
        try {
            $keys = $this->redis->keys($pattern);
            if (!empty($keys)) {
                return $this->redis->del($keys);
            }
            return 0;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get with fallback to database
     */
    public function remember($key, $ttl, callable $callback) {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
    
    /**
     * Increment counter
     */
    public function increment($key, $amount = 1) {
        if (!$this->enabled) return false;
        
        try {
            return $this->redis->incrby($key, $amount);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Set hash (for complex objects)
     */
    public function hset($key, $field, $value) {
        if (!$this->enabled) return false;
        
        try {
            return $this->redis->hset($key, $field, json_encode($value));
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get hash
     */
    public function hget($key, $field) {
        if (!$this->enabled) return null;
        
        try {
            $value = $this->redis->hget($key, $field);
            return $value ? json_decode($value, true) : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Add to sorted set (for rankings, recent items)
     */
    public function zadd($key, $score, $member) {
        if (!$this->enabled) return false;
        
        try {
            return $this->redis->zadd($key, $score, json_encode($member));
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get sorted set range
     */
    public function zrange($key, $start, $stop, $withscores = false) {
        if (!$this->enabled) return [];
        
        try {
            $results = $this->redis->zrange($key, $start, $stop, $withscores ? 'WITHSCORES' : '');
            return array_map(function($item) {
                return json_decode($item, true);
            }, $results);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Generate cache key
     */
    public static function key($type, $accountId, $suffix = '') {
        $key = "odoo:{$type}:{$accountId}";
        if ($suffix) {
            $key .= ":{$suffix}";
        }
        return $key;
    }
    
    /**
     * Get cache stats
     */
    public function getStats() {
        return [
            'enabled' => $this->enabled,
            'hits' => $this->hitCount,
            'misses' => $this->missCount,
            'hit_rate' => $this->hitCount + $this->missCount > 0 
                ? round($this->hitCount / ($this->hitCount + $this->missCount) * 100, 2) 
                : 0
        ];
    }
    
    /**
     * Check if enabled
     */
    public function isEnabled() {
        return $this->enabled;
    }
    
    /**
     * Flush all cache (use with caution!)
     */
    public function flush() {
        if (!$this->enabled) return false;
        
        try {
            return $this->redis->flushdb();
        } catch (Exception $e) {
            return false;
        }
    }
}

// Helper functions for quick access

/**
 * Get from cache or compute
 */
function cache_remember($key, $ttl, $callback) {
    return OdooRedisCache::getInstance()->remember($key, $ttl, $callback);
}

/**
 * Get from cache
 */
function cache_get($key) {
    return OdooRedisCache::getInstance()->get($key);
}

/**
 * Set to cache
 */
function cache_set($key, $value, $ttl = 300) {
    return OdooRedisCache::getInstance()->set($key, $value, $ttl);
}

/**
 * Delete from cache
 */
function cache_delete($key) {
    return OdooRedisCache::getInstance()->delete($key);
}

/**
 * Delete by pattern
 */
function cache_delete_pattern($pattern) {
    return OdooRedisCache::getInstance()->deletePattern($pattern);
}

/**
 * Generate cache key
 */
function cache_key($type, $accountId, $suffix = '') {
    return OdooRedisCache::key($type, $accountId, $suffix);
}
