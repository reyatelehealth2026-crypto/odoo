<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();

echo "=== ai_settings ===\n";
$stmt = $db->query("SELECT id, line_account_id, is_enabled, ai_mode FROM ai_settings");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

echo "\n=== ai_chat_settings ===\n";
$stmt = $db->query("SELECT id, line_account_id, is_enabled, CASE WHEN gemini_api_key != '' THEN 'SET' ELSE 'EMPTY' END as api_key FROM ai_chat_settings");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
