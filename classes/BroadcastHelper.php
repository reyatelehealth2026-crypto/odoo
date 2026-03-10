<?php
/**
 * Broadcast Helper Class
 * Centralizes logic for sending and scheduling broadcasts.
 */

class BroadcastHelper
{
    /**
     * Get unique LINE user IDs from multiple tags
     */
    public static function getUserIdsByTags(AdvancedCRM $crm, array $tagIds): array
    {
        $seen = [];
        foreach ($tagIds as $tid) {
            $users = $crm->getUsersByTag((int) $tid);
            foreach ($users as $u) {
                if (!empty($u['line_user_id'])) {
                    $seen[$u['line_user_id']] = true;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * Execute the actual broadcast sending and return the number of sent messages
     */
    public static function executeBroadcastSend($db, $line, $crm, $currentBotId, $targetType, $post, $messages): array
    {
        $sentCount    = 0;
        $targetGroupId = null;

        if ($targetType === 'database') {
            $stmt = $db->prepare("SELECT line_user_id FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$currentBotId]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($userIds)) {
                foreach (array_chunk($userIds, 500) as $chunk) {
                    $r = $line->multicastMessage($chunk, $messages);
                    if ($r['code'] === 200) $sentCount += count($chunk);
                }
            }
        } elseif ($targetType === 'all') {
            $r = $line->broadcastMessage($messages);
            if ($r['code'] === 200) {
                $stmt = $db->prepare("SELECT COUNT(*) as c FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$currentBotId]);
                $sentCount = (int) $stmt->fetch()['c'];
            }
        } elseif ($targetType === 'limit') {
            $limit = (int) ($post['limit_count'] ?? 100);
            $stmt  = $db->prepare("SELECT line_user_id FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$currentBotId, $limit]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($userIds)) {
                $r = $line->multicastMessage($userIds, $messages);
                if ($r['code'] === 200) $sentCount = count($userIds);
            }
        } elseif ($targetType === 'narrowcast') {
            $narrowcastLimit  = (int) ($post['narrowcast_limit'] ?? 0);
            $narrowcastFilter = $post['narrowcast_filter'] ?? 'none';
            $filter = null;
            switch ($narrowcastFilter) {
                case 'male':      $filter = ['demographic' => ['gender' => 'male']]; break;
                case 'female':    $filter = ['demographic' => ['gender' => 'female']]; break;
                case 'age_15_24': $filter = ['demographic' => ['age' => ['gte' => 'age_15', 'lt' => 'age_25']]]; break;
                case 'age_25_34': $filter = ['demographic' => ['age' => ['gte' => 'age_25', 'lt' => 'age_35']]]; break;
                case 'age_35_44': $filter = ['demographic' => ['age' => ['gte' => 'age_35', 'lt' => 'age_45']]]; break;
                case 'age_45_plus': $filter = ['demographic' => ['age' => ['gte' => 'age_45']]]; break;
            }
            $r = $line->narrowcastMessage($messages, $narrowcastLimit, null, $filter);
            if ($r['code'] === 202) {
                $sentCount     = $narrowcastLimit;
                $targetGroupId = $r['requestId'] ?? null;
            }
        } elseif ($targetType === 'group') {
            $targetGroupId = $post['target_group_id'] ?? null;
            $stmt = $db->prepare("SELECT u.line_user_id FROM users u JOIN user_groups ug ON u.id = ug.user_id WHERE ug.group_id = ? AND u.is_blocked = 0");
            $stmt->execute([$targetGroupId]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($userIds)) {
                $r = $line->multicastMessage($userIds, $messages);
                if ($r['code'] === 200) $sentCount = count($userIds);
            }
        } elseif ($targetType === 'segment') {
            $segmentId = (int) ($post['segment_id'] ?? 0);
            $userIds   = [];
            foreach ($crm->getSegmentMembers($segmentId) as $m) {
                if (!empty($m['line_user_id'])) $userIds[] = $m['line_user_id'];
            }
            if (!empty($userIds)) {
                foreach (array_chunk($userIds, 500) as $chunk) {
                    $r = $line->multicastMessage($chunk, $messages);
                    if ($r['code'] === 200) $sentCount += count($chunk);
                }
            }
        } elseif ($targetType === 'tag') {
            $tagIds  = array_filter(array_map('intval', (array) ($post['tag_ids'] ?? [])));
            $userIds = self::getUserIdsByTags($crm, $tagIds);
            if (!empty($userIds)) {
                foreach (array_chunk($userIds, 500) as $chunk) {
                    $r = $line->multicastMessage($chunk, $messages);
                    if ($r['code'] === 200) $sentCount += count($chunk);
                }
            }
        } elseif ($targetType === 'select') {
            $selectedUsers = $post['selected_users'] ?? [];
            if (!empty($selectedUsers)) {
                $placeholders = implode(',', array_fill(0, count($selectedUsers), '?'));
                $stmt = $db->prepare("SELECT line_user_id FROM users WHERE id IN ($placeholders) AND is_blocked = 0");
                $stmt->execute($selectedUsers);
                $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($userIds)) {
                    $r = $line->multicastMessage($userIds, $messages);
                    if ($r['code'] === 200) $sentCount = count($userIds);
                }
            }
        } elseif ($targetType === 'single') {
            $userId = $post['single_user_id'] ?? null;
            $stmt   = $db->prepare("SELECT line_user_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            if ($u) {
                $r = $line->pushMessage($u['line_user_id'], $messages);
                if ($r['code'] === 200) $sentCount = 1;
            }
        }

        return ['sentCount' => $sentCount, 'targetGroupId' => $targetGroupId];
    }

    /**
     * Process pending scheduled broadcasts
     */
    public static function processScheduled($db)
    {
        $dueStmt = $db->query("SELECT b.* FROM broadcasts b WHERE b.status = 'scheduled' AND b.scheduled_at <= NOW() LIMIT 10");
        $dueBroadcasts = $dueStmt->fetchAll();

        if (empty($dueBroadcasts)) {
            return 0;
        }

        require_once __DIR__ . '/LineAccountManager.php';
        require_once __DIR__ . '/LineAPI.php';
        require_once __DIR__ . '/AdvancedCRM.php';

        $lineManager = new LineAccountManager($db);
        $processed = 0;

        foreach ($dueBroadcasts as $due) {
            // Lock the row to prevent double sending
            $db->prepare("UPDATE broadcasts SET status = 'sending' WHERE id = ?")->execute([$due['id']]);

            $botId = $due['line_account_id'];
            if (!$botId) {
                $defaultAccount = $lineManager->getDefaultAccount();
                $botId = $defaultAccount['id'] ?? null;
            }

            if (!$botId) {
                $db->prepare("UPDATE broadcasts SET status = 'failed' WHERE id = ?")->execute([$due['id']]);
                continue;
            }

            $line = $lineManager->getLineAPI($botId);
            $crm = new AdvancedCRM($db, $botId, $line);

            // Build messages
            $dMsgType = $due['message_type'];
            $dContent = $due['content'];
            $dMessages = [];

            if ($dMsgType === 'text') {
                $dMessages = [['type' => 'text', 'text' => $dContent]];
            } elseif ($dMsgType === 'image') {
                $dMessages = [['type' => 'image', 'originalContentUrl' => $dContent, 'previewImageUrl' => $dContent]];
            } elseif ($dMsgType === 'flex') {
                $dFlex = json_decode($dContent, true);
                if ($dFlex) {
                    $dMessages = [['type' => 'flex', 'altText' => $due['title'], 'contents' => $dFlex]];
                }
            }

            // Build post array
            $dTargetType = $due['target_type'];
            $dPost = [];
            if ($dTargetType === 'tag' && !empty($due['target_group_id'])) {
                $dTagIds = json_decode($due['target_group_id'], true) ?: [];
                $dPost['tag_ids'] = $dTagIds;
            } elseif (!empty($due['target_group_id'])) {
                $dPost['target_group_id'] = $due['target_group_id'];
            }

            if (!empty($dMessages)) {
                try {
                    $dResult = self::executeBroadcastSend($db, $line, $crm, $botId, $dTargetType, $dPost, $dMessages);
                    $db->prepare("UPDATE broadcasts SET status = 'sent', sent_count = ?, sent_at = NOW() WHERE id = ?")->execute([$dResult['sentCount'], $due['id']]);
                    $processed++;
                } catch (Exception $e) {
                    error_log('Broadcast send error: ' . $e->getMessage());
                    $db->prepare("UPDATE broadcasts SET status = 'failed' WHERE id = ?")->execute([$due['id']]);
                }
            } else {
                $db->prepare("UPDATE broadcasts SET status = 'failed' WHERE id = ?")->execute([$due['id']]);
            }
        }

        return $processed;
    }
}
