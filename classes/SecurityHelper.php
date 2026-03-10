<?php
/**
 * Security Helper Class
 * 
 * Centralized security functions for Input Validation, Sanitization, 
 * and other security-related utilities.
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

class SecurityHelper
{
    /**
     * Validate LINE User ID
     * Format: U + 32 hex characters
     * 
     * @param string|null $id LINE User ID
     * @return bool True if valid
     */
    public static function validateLineUserId($id)
    {
        if (empty($id))
            return false;
        return preg_match('/^U[a-f0-9]{32}$/', $id) === 1;
    }

    /**
     * Validate Thai Phone Number
     * 
     * @param string|null $phone Phone number
     * @return bool True if valid
     */
    public static function validateThaiPhone($phone)
    {
        if (empty($phone))
            return false;
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        // Mobile: 08x, 09x, 06x (10 digits)
        // Landline: 02x-07x (9 digits)
        return (strlen($cleaned) === 10 || strlen($cleaned) === 9) && $cleaned[0] === '0';
    }

    /**
     * Validate ID (Positive Integer)
     * 
     * @param mixed $id ID to check
     * @return bool True if valid positive integer
     */
    public static function validateId($id)
    {
        return is_numeric($id) && (int) $id > 0 && (int) $id < PHP_INT_MAX;
    }

    /**
     * Sanitize String for Output/Logging
     * 
     * @param string|null $input Input string
     * @return string Sanitized string
     */
    public static function sanitizeString($input)
    {
        if (empty($input))
            return '';
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize Data for Logging (Redact sensitive fields)
     * 
     * @param array $data Data to sanitize
     * @return array Sanitized data
     */
    public static function sanitizeForLog($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveFields = [
            'api_key',
            'apikey',
            'key',
            'password',
            'pass',
            'secret',
            'token',
            'access_token',
            'refresh_token',
            'credit_card',
            'cc_number',
            'cvv',
            'authorization',
            'auth'
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, $sensitiveFields)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeForLog($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
