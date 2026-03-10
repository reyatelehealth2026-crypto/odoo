#!/usr/bin/env php
<?php
/**
 * Odoo Daily Summary Auto-Send Cron Job
 * 
 * Purpose: Automatically send daily order summaries to customers at scheduled time
 * Schedule: Run every minute via cron
 * 
 * Example crontab entry:
 * * * * * * /usr/bin/php /path/to/cron/odoo_daily_summary_auto.php >> /path/to/logs/daily_summary_auto.log 2>&1
 * 
 * @version 1.0.0
 * @created 2026-02-26
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$startTime = microtime(true);
$logPrefix = '[ODOO-DAILY-SUMMARY-AUTO]';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if settings table exists
    if (!tableExists($db, 'odoo_daily_summary_settings')) {
        echo "{$logPrefix} Settings table not found. Skipping.\n";
        exit(0);
    }
    
    // Get settings
    $settings = getSettings($db);
    
    // Check if auto-send is enabled
    if (!$settings['auto_send_enabled']) {
        echo "{$logPrefix} Auto-send is disabled. Skipping.\n";
        exit(0);
    }
    
    // Get scheduled time
    $scheduledTime = $settings['send_time'] ?? '09:00';
    $timezone = $settings['send_timezone'] ?? 'Asia/Bangkok';
    
    date_default_timezone_set($timezone);
    
    $currentTime = date('H:i');
    $currentDate = date('Y-m-d');
    $currentHour = (int) date('H');
    $currentMinute = (int) date('i');
    
    list($schedHour, $schedMinute) = explode(':', $scheduledTime);
    $schedHour = (int) $schedHour;
    $schedMinute = (int) $schedMinute;
    
    // Check if we're within the scheduled time window (same hour and minute)
    if ($currentHour !== $schedHour || $currentMinute !== $schedMinute) {
        echo "{$logPrefix} Not scheduled time yet. Current: {$currentTime}, Scheduled: {$scheduledTime}\n";
        exit(0);
    }
    
    // Check if already sent today
    $lastSentDate = $settings['last_sent_date'] ?? null;
    if ($lastSentDate === $currentDate) {
        echo "{$logPrefix} Already sent today ({$currentDate}). Skipping.\n";
        exit(0);
    }
    
    echo "{$logPrefix} Starting auto-send at {$currentTime} on {$currentDate}\n";
    
    // Load required files
    require_once __DIR__ . '/../api/odoo-webhooks-dashboard.php';
    
    // Get preview data
    $previewData = getDailySummaryPreview($db);
    $records = $previewData['records'];
    
    // Filter eligible users (not sent today and has orders)
    $eligibleUsers = [];
    foreach ($records as $record) {
        if (!$record['sent_today'] && !empty($record['orders'])) {
            $eligibleUsers[] = $record['line_user_id'];
        }
    }
    
    $totalRecipients = count($eligibleUsers);
    
    if ($totalRecipients === 0) {
        echo "{$logPrefix} No eligible recipients found.\n";
        
        // Log execution
        logExecution($db, $currentDate, $scheduledTime, 0, 0, 0, 0, 'success', null);
        
        // Update last sent date
        updateLastSentDate($db, $currentDate);
        
        exit(0);
    }
    
    echo "{$logPrefix} Found {$totalRecipients} eligible recipients\n";
    
    // Send to all eligible users
    $result = sendDailySummary($db, $eligibleUsers);
    
    $sentCount = $result['success_count'] ?? 0;
    $failedCount = $result['failed_count'] ?? 0;
    $skippedCount = $totalRecipients - $sentCount - $failedCount;
    
    $executionMs = (int) round((microtime(true) - $startTime) * 1000);
    
    $status = 'success';
    if ($failedCount > 0 && $sentCount === 0) {
        $status = 'failed';
    } elseif ($failedCount > 0) {
        $status = 'partial';
    }
    
    echo "{$logPrefix} Completed: {$sentCount} sent, {$failedCount} failed, {$skippedCount} skipped in {$executionMs}ms\n";
    
    // Log execution
    logExecution($db, $currentDate, $scheduledTime, $totalRecipients, $sentCount, $failedCount, $skippedCount, $status, null, $executionMs);
    
    // Update last sent date
    updateLastSentDate($db, $currentDate);
    
    exit(0);
    
} catch (Exception $e) {
    $executionMs = (int) round((microtime(true) - $startTime) * 1000);
    $errorMsg = $e->getMessage();
    
    echo "{$logPrefix} ERROR: {$errorMsg}\n";
    echo $e->getTraceAsString() . "\n";
    
    // Try to log the error
    try {
        if (isset($db)) {
            logExecution($db, date('Y-m-d'), $scheduledTime ?? '09:00', 0, 0, 0, 0, 'failed', $errorMsg, $executionMs);
        }
    } catch (Exception $logError) {
        echo "{$logPrefix} Failed to log error: " . $logError->getMessage() . "\n";
    }
    
    exit(1);
}

/**
 * Get settings from database
 */
function getSettings($db)
{
    $stmt = $db->query("
        SELECT setting_key, setting_value 
        FROM odoo_daily_summary_settings
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $settings = [];
    foreach ($rows as $row) {
        $key = $row['setting_key'];
        $value = $row['setting_value'];
        
        if ($key === 'auto_send_enabled') {
            $settings[$key] = (bool) (int) $value;
        } elseif ($key === 'lookback_days') {
            $settings[$key] = (int) $value;
        } else {
            $settings[$key] = $value;
        }
    }
    
    return $settings;
}

/**
 * Log execution to database
 */
function logExecution($db, $executionDate, $scheduledTime, $totalRecipients, $sentCount, $failedCount, $skippedCount, $status, $errorMessage = null, $durationMs = 0)
{
    if (!tableExists($db, 'odoo_daily_summary_auto_log')) {
        return;
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO odoo_daily_summary_auto_log
            (execution_date, execution_time, scheduled_time, total_recipients, 
             sent_count, failed_count, skipped_count, execution_duration_ms, 
             status, error_message)
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $executionDate,
            $scheduledTime,
            $totalRecipients,
            $sentCount,
            $failedCount,
            $skippedCount,
            $durationMs,
            $status,
            $errorMessage
        ]);
    } catch (Exception $e) {
        error_log("Failed to log execution: " . $e->getMessage());
    }
}

/**
 * Update last sent date
 */
function updateLastSentDate($db, $date)
{
    try {
        $stmt = $db->prepare("
            UPDATE odoo_daily_summary_settings 
            SET setting_value = ?, updated_at = NOW() 
            WHERE setting_key = 'last_sent_date'
        ");
        $stmt->execute([$date]);
    } catch (Exception $e) {
        error_log("Failed to update last_sent_date: " . $e->getMessage());
    }
}

/**
 * Check table existence
 */
function tableExists($db, $table)
{
    static $cache = [];
    
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    if ($table === '') {
        return false;
    }
    
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        $quoted = $db->quote($table);
        $stmt = $db->query("SHOW TABLES LIKE {$quoted}");
        $cache[$table] = $stmt ? ($stmt->rowCount() > 0) : false;
    }
    
    return $cache[$table];
}
