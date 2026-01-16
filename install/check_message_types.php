<?php
/**
 * Check Message Types - Show messages with incorrect types
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Check Message Types</title>
    <style>
        body { font-family: system-ui; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        .type-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .type-text { background: #e3f2fd; color: #1976d2; }
        .type-flex { background: #f3e5f5; color: #7b1fa2; }
        .type-image { background: #e8f5e9; color: #388e3c; }
        .wrong { background: #ffebee; }
        .content-preview { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; color: #666; }
        .btn { padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #059669; }
        .stats { display: flex; gap: 20px; margin: 20px 0; }
        .stat-card { flex: 1; padding: 15px; background: #f8f9fa; border-radius: 8px; }
        .stat-number { font-size: 24px; font-weight: bold; color: #10b981; }
        .stat-label { font-size: 14px; color: #666; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Check Message Types</h1>
        
        <?php
        try {
            // Count messages by type
            $stmt = $db->query("
                SELECT message_type, COUNT(*) as count 
                FROM messages 
                GROUP BY message_type
            ");
            $typeCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Find potentially wrong messages
            $stmt = $db->query("
                SELECT id, user_id, content, message_type, created_at
                FROM messages 
                WHERE message_type = 'text' 
                AND content LIKE '%\"type\":%'
                AND (content LIKE '%\"type\":\"flex\"%' OR content LIKE '%\"type\":\"bubble\"%')
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $wrongMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ?>
            
            <div class="stats">
                <?php foreach ($typeCounts as $type): ?>
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($type['count']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($type['message_type']) ?> messages</div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <h2>⚠️ Messages with Wrong Type (<?= count($wrongMessages) ?>)</h2>
            <p>These messages are saved as 'text' but contain Flex Message JSON:</p>
            
            <?php if (count($wrongMessages) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User ID</th>
                        <th>Saved As</th>
                        <th>Should Be</th>
                        <th>Content Preview</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wrongMessages as $msg): 
                        $decoded = json_decode($msg['content'], true);
                        $actualType = $decoded['type'] ?? 'unknown';
                        if ($actualType === 'bubble' || $actualType === 'carousel') {
                            $actualType = 'flex';
                        }
                    ?>
                    <tr class="wrong">
                        <td><?= $msg['id'] ?></td>
                        <td><?= $msg['user_id'] ?></td>
                        <td><span class="type-badge type-text"><?= $msg['message_type'] ?></span></td>
                        <td><span class="type-badge type-flex"><?= $actualType ?></span></td>
                        <td class="content-preview"><?= htmlspecialchars(substr($msg['content'], 0, 100)) ?>...</td>
                        <td><?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 20px;">
                <a href="fix_message_types.php" class="btn" onclick="return confirm('Fix all wrong message types?')">
                    🔧 Fix All Message Types
                </a>
            </div>
            <?php else: ?>
            <p style="color: #10b981;">✅ All messages have correct types!</p>
            <?php endif; ?>
            
        <?php } catch (Exception $e) { ?>
            <p style="color: red;">❌ Error: <?= htmlspecialchars($e->getMessage()) ?></p>
        <?php } ?>
    </div>
</body>
</html>
