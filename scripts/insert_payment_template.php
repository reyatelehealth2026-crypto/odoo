<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();

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
