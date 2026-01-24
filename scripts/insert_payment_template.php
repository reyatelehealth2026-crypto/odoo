<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Create table if not exists
    $sql = "CREATE TABLE IF NOT EXISTS `chat_templates` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(255) NOT NULL,
      `content` text NOT NULL,
      `category` varchar(100) DEFAULT 'General',
      `quick_reply` varchar(255) DEFAULT NULL,
      `created_by` int(11) DEFAULT NULL,
      `line_account_id` int(11) DEFAULT NULL,
      `is_active` tinyint(1) DEFAULT 1,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_category` (`category`),
      KEY `idx_line_account_id` (`line_account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $db->exec($sql);
    echo "Ensured chat_templates table exists.\n";

    // Magic string for interception
    $magicContent = "{{PAYMENT_TEMPLATE_V1}}";
    $templateName = "✅ แจ้งยอดชำระ/เลขบัญชี (Payment)";
    $category = "Payment";

    // Check if exists
    $stmt = $db->prepare("SELECT id FROM chat_templates WHERE content = ?");
    $stmt->execute([$magicContent]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo "Template already exists (ID: {$existing['id']})\n";

        // Update name just in case
        $stmt = $db->prepare("UPDATE chat_templates SET name = ? WHERE id = ?");
        $stmt->execute([$templateName, $existing['id']]);
        echo "Updated template name.\n";
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO chat_templates (name, content, category, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$templateName, $magicContent, $category]);
        echo "Created new template: $templateName\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
