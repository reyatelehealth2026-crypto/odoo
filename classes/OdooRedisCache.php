<?php
/**
 * Universal Cache Manager - Redis, Native Redis, or File Cache
 * Auto-detects available caching method
 */

// Try to load Predis from different locations
$predisLoaded = false;

// Option 1: Composer autoload
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $predisLoaded = class_exists('Predis\Client');
}

// Option 2: Manual Predis install
if (!$predisLoaded && file_exists(__DIR__ . '/../vendor/predis/predis/autoload.php')) {
    require_once __DIR__ . '/../vendor/predis/predis/autoload.php';
    $predisLoaded = class_exists('Predis\Client');
}

// Option 3: Include File Cache fallback
require_once __DIR__ . '/FileCache.php';

use Predis\Client as PredisClient;

class OdooRedisCache {
    private static $instance = null;
    private $cache = null;
    private $type = 'none'; // 'redis', 'predis', 'file', 'none'
    private $enabled = false;
    private $hitCount = 0;
    private $missCount = 0;
    
    // Cache TTL Constants
    const TTL_OVERVIEW = 60;
    const TTL_STATS = 30;
    const TTL_WEBHOOKS = 30;
    const TTL_SLIPS = 60;
    const TTL_CUSTOMERS = 300;
    const TTL_ORDERS = 120;
    const TTL_BDO = 180;
    const TTL_NOTIFICATIONS = 30;
    const TTL_STATIC = 3600;
    
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
        // Try 1: Native PHP Redis Extension (fastest)
        if (extension_loaded('redis')) {
            try {
                $redis = new Redis();
                $redis->connect('redis-13718.fcrce172.us-east-1-1.ec2.cloud.redislabs.com', 13718, 3);
                $redis->auth(['default', '8aOsi5ZlcevxIxkXOFn4b4qshhMTHKC5']);
                
                if ($redis->ping()) {
                    $this->cache = $redis;
                    $this->type = 'redis';
                    $this->enabled = true;
                    return;
                }
            } catch (Exception $e) {
                error_log("[Cache] Native Redis failed: " . $e->getMessage());
            }
        }
        
        // Try 2: Predis (if available)
        if (class_exists('Predis\Client')) {
            try {
                $client = new PredisClient([
                    'host' => 'redis-13718.fcrce172.us-east-1-1.ec2.cloud.redislabs.com',
                    'port' => 13718,
                    'database' => 0,
                    'username' => 'default',
                    'password' => '8aOsi5ZlcevxIxkXOFn4b4qshhMTHKC5',
                    'timeout' => 3.0,
                ]);
                
                $client->ping();
                $this->cache = $client;
                $this->type = 'predis';
                $this->enabled = true;
                return;
            } catch (Exception $e) {
                error_log("[Cache] Predis failed: " . $e->getMessage());
            }
        }
        
        // Try 3: File Cache (fallback)
        $fileCache = FileCache::getInstance();
        if ($fileCache->isEnabled()) {
            $this->cache = $fileCache;
            $this->type = 'file';
            $this->enabled = true;
            error_log("[Cache] Using file-based cache as fallback");
            return;
        }
        
        // No caching available
        $this->type = 'none';
        $this->enabled = false;
        error_log("[Cache] No caching available - using database directly");
    }
    
    public function get($key) {
        if (!$this->enabled) return null;
        
        try {
            switch ($this->type) {
                case 'redis':
                    $value = $this->cache->get($key);
                    break;
                case 'predis':
                    $value = $this->cache->get($key);
                    break;
                case 'file':
                    return $this->cache->get($key);
                default:
                    return null;
            }
            
            if ($value !== false && $value !== null) {
                $this->hitCount++;
                return json_decode($value, true);
            }
            $this->missCount++;
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    public function set($key, $value, $ttl = 300) {
        if (!$this->enabled) return false;
        
        try {
            $encoded = json_encode($value);
            
            switch ($this->type) {
                case 'redis':
                    return $this->cache->setex($key, $ttl, $encoded);
                case 'predis':
                    return $this->cache->setex($key, $ttl, $encoded);
                case 'file':
                    return $this->cache->set($key, $value, $ttl);
                default:
                    return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function delete($key) {
        if (!$this->enabled) return false;
        
        try {
            switch ($this->type) {
                case 'redis':
                    return $this->cache->del($key) > 0;
                case 'predis':
                    return $this->cache->del($key) > 0;
                case 'file':
                    return $this->cache->delete($key);
                default:
                    return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function deletePattern($pattern) {
        if (!$this->enabled) return 0;
        
        try {
            switch ($this->type) {
                case 'redis':
                case 'predis':
                    $keys = $this->cache->keys($pattern);
                    if (!empty($keys)) {
                        return $this->cache->del($keys);
                    }
                    return 0;
                case 'file':
                    // File cache doesn't support pattern delete
                    return 0;
                default:
                    return 0;
            }
        } catch (Exception $e) {
            return 0;
        }
    }
    
    public function remember($key, $ttl, callable $callback) {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
    
    public function getStats() {
        return [
            'enabled' => $this->enabled,
            'type' => $this->type,
            'hits' => $this->hitCount,
            'misses' => $this->missCount,
            'hit_rate' => $this->hitCount + $this->missCount > 0 
                ? round($this->hitCount / ($this->hitCount + $this->missCount) * 100, 2) 
                : 0
        ];
    }
    
    public function isEnabled() {
        return $this->enabled;
    }
    
    public function getType() {
        return $this->type;
    }
    
    public static function key($type, $accountId, $suffix = '') {
        $key = "odoo:{$type}:{$accountId}";
        if ($suffix) {
            $key .= ":{$suffix}";
        }
        return $key;
    }
}

// Helper functions
function cache_get($key) {
    return OdooRedisCache::getInstance()->get($key);
}

function cache_set($key, $value, $ttl = 300) {
    return OdooRedisCache::getInstance()->set($key, $value, $ttl);
}

function cache_delete($key) {
    return OdooRedisCache::getInstance()->delete($key);
}

function cache_delete_pattern($pattern) {
    return OdooRedisCache::getInstance()->deletePattern($pattern);
}

function cache_key($type, $accountId, $suffix = '') {
    return OdooRedisCache::key($type, $accountId, $suffix);
}

function cache_remember($key, $ttl, $callback) {
    return OdooRedisCache::getInstance()->remember($key, $ttl, $callback);
}
