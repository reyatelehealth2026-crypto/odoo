<?php
/**
 * Seed Notification Templates
 * 
 * Insert default notification templates into database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>\n<html>\n<head>\n";
echo "<meta charset='UTF-8'>\n";
echo "<title>Seed Notification Templates</title>\n";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;}";
echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#569cd6;}</style>\n";
echo "</head>\n<body>\n";

echo "<h2 class='info'>📝 Seeding Notification Templates</h2>\n";
echo "<pre>\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Roadmap timeline template
    $roadmapTemplate = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '🕐 Timeline: {{order_ref}}',
                    'weight' => 'bold',
                    'size' => 'lg',
                    'color' => '#ffffff'
                ]
            ],
            'backgroundColor' => '#1E88E5',
            'paddingAll' => '15px'
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => '{{timeline_items}}',
            'paddingAll' => '15px'
        ]
    ];
    
    $templates = [
        [
            'template_code' => 'roadmap_timeline',
            'event_type' => 'roadmap.milestone',
            'recipient_type' => 'customer',
            'language' => 'th',
            'template_type' => 'roadmap',
            'template_content' => json_encode($roadmapTemplate),
            'alt_text_template' => 'อัปเดตสถานะออเดอร์ {{order_ref}}',
            'description' => 'Roadmap timeline for order status updates',
            'is_active' => 1,
            'version' => 1
        ]
    ];
    
    $stmt = $db->prepare("
        INSERT INTO odoo_notification_templates
        (template_code, event_type, recipient_type, language, template_type, 
         template_content, alt_text_template, description, is_active, version)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            template_content = VALUES(template_content),
            alt_text_template = VALUES(alt_text_template),
            description = VALUES(description),
            is_active = VALUES(is_active)
    ");
    
    $insertedCount = 0;
    foreach ($templates as $template) {
        $stmt->execute([
            $template['template_code'],
            $template['event_type'],
            $template['recipient_type'],
            $template['language'],
            $template['template_type'],
            $template['template_content'],
            $template['alt_text_template'],
            $template['description'],
            $template['is_active'],
            $template['version']
        ]);
        $insertedCount++;
        echo "<span class='success'>✓ Inserted template: {$template['template_code']}</span>\n";
    }
    
    echo "\n<span class='info'>═══════════════════════════════════════════════════</span>\n";
    echo "<span class='success'>✓ Seeded {$insertedCount} templates</span>\n";
    echo "<span class='info'>═══════════════════════════════════════════════════</span>\n";
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Error: {$e->getMessage()}</span>\n";
}

echo "</pre>\n";
echo "</body>\n</html>";
