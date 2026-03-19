<?php
/**
 * Simple File-based Cache (Fallback when Redis not available)
 * ใช้เมื่อไม่มี Redis หรือ Predis
 */

class FileCache {
    private static $instance = null;
    private $cacheDir;
    private $enabled = false;
    
    private function __construct() {
        $this->cacheDir = sys_get_temp_dir() . '/odoo_cache/';
        $this->ensureDirectory();
        $this->enabled = is_writable($this->cacheDir);
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function ensureDirectory() {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    private function getFilePath($key) {
        return $this->cacheDir . md5($key) . '.cache';
    }
    
    public function get($key) {
        if (!$this->enabled) return null;
        
        $file = $this->getFilePath($key);
        if (!file_exists($file)) return null;
        
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    public function set($key, $value, $ttl = 300) {
        if (!$this->enabled) return false;
        
        $file = $this->getFilePath($key);
        $data = [
            'expires' => time() + $ttl,
            'value' => $value
        ];
        
        return file_put_contents($file, serialize($data)) !== false;
    }
    
    public function delete($key) {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }
    
    public function isEnabled() {
        return $this->enabled;
    }
}
