<?php
/**
 * Configuration Validator
 * 
 * Validates required configuration on startup.
 * Fails fast with clear error messages for missing config.
 * 
 * @version 1.0.0
 */

class ConfigValidator
{
    private array $errors = [];

    /**
     * Validate all required configuration values.
     *
     * @param bool $throwOnError If true, throws Exception on first error
     * @return bool True if all valid
     * @throws Exception If throwOnError and validation fails
     */
    public function validate(bool $throwOnError = true): bool
    {
        $this->errors = [];

        // Database
        $this->requireConstant('DB_HOST', 'Database host');
        $this->requireConstant('DB_NAME', 'Database name');
        $this->requireConstant('DB_USER', 'Database user');
        $this->requireConstant('DB_PASS', 'Database password');

        // Application
        $this->requireConstant('APP_URL', 'Application URL');

        if ($throwOnError && !empty($this->errors)) {
            throw new Exception('Configuration validation failed: ' . implode('; ', $this->errors));
        }

        return empty($this->errors);
    }

    /**
     * Validate Odoo-specific configuration.
     *
     * @return bool
     */
    public function validateOdoo(): bool
    {
        $this->errors = [];

        $this->requireConstant('ODOO_BASE_URL', 'Odoo API base URL');
        $this->requireConstant('ODOO_DB', 'Odoo database name');
        $this->requireConstant('ODOO_API_USER', 'Odoo API user');
        $this->requireConstant('ODOO_API_KEY', 'Odoo API key');
        $this->requireConstant('ODOO_WEBHOOK_SECRET', 'Odoo webhook secret');

        return empty($this->errors);
    }

    /**
     * Validate Pusher configuration.
     *
     * @return bool
     */
    public function validatePusher(): bool
    {
        $this->errors = [];

        $this->requireConstant('PUSHER_APP_ID', 'Pusher app ID');
        $this->requireConstant('PUSHER_KEY', 'Pusher key');
        $this->requireConstant('PUSHER_SECRET', 'Pusher secret');
        $this->requireConstant('PUSHER_CLUSTER', 'Pusher cluster');

        return empty($this->errors);
    }

    /**
     * Get validation errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check that a constant is defined and not empty.
     */
    private function requireConstant(string $name, string $label): void
    {
        if (!defined($name) || trim((string) constant($name)) === '') {
            $this->errors[] = "Missing required config: {$label} ({$name})";
        }
    }
}
