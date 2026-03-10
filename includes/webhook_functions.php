<?php
/**
 * Webhook Helper Functions
 * All functions used by webhook.php
 */

/**
 * Get or Create User - ตรวจสอบและบันทึกผู้ใช้เสมอ (ไม่ว่าจะมาจากกลุ่มหรือแชทส่วนตัว)
 */
function getOrCreateUser($db, $line, $userId, $lineAccountId = null, $groupId = null) {
    // ตรวจสอบว่ามีผู้ใช้อยู่แล้วหรือไม่
    $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ดึงข้อมูลโปรไฟล์จาก LINE (ทุกครั้งเพื่ออัพเดทข้อมูลให้ล่าสุด)
    $profile = null;
    try {
        if ($groupId) {
            // ถ้ามาจากกลุ่ม ใช้ getGroupMemberProfile
            $profile = $line->getGroupMemberProfile($groupId, $userId);
        } else {
            // ถ้ามาจากแชทส่วนตัว ใช้ getProfile
            $profile = $line->getProfile($userId);
        }
    } catch (Exception $e) {
        error_log("getOrCreateUser profile error: " . $e->getMessage());
    }
    
    $displayName = $profile['displayName'] ?? 'Unknown';
    $pictureUrl = $profile['pictureUrl'] ?? '';
    $statusMessage = $profile['statusMessage'] ?? '';
    
    // ถ้ายังไม่มี ให้สร้างใหม่
    if (!$user) {
        // บันทึกผู้ใช้ใหม่
        try {
            $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
            if ($stmt->rowCount() > 0) {
                $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name, picture_url, status_message) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$lineAccountId, $userId, $displayName, $pictureUrl, $statusMessage]);
            } else {
                $stmt = $db->prepare("INSERT INTO users (line_user_id, display_name, picture_url, status_message) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $displayName, $pictureUrl, $statusMessage]);
            }
            
            $user = [
                'id' => $db->lastInsertId(),
                'display_name' => $displayName,
                'picture_url' => $pictureUrl,
                'line_account_id' => $lineAccountId
            ];
            
            // บันทึกเป็น follower ด้วย (ถ้ามี lineAccountId)
            if ($lineAccountId) {
                saveAccountFollower($db, $lineAccountId, $userId, $user['id'], $profile, true);
            }
            
        } catch (Exception $e) {
            error_log("getOrCreateUser insert error: " . $e->getMessage());
            // ลองดึงอีกครั้ง (อาจมี race condition)
            $stmt = $db->prepare("SELECT id, display_name, picture_url, line_account_id FROM users WHERE line_user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // ถ้ามีอยู่แล้ว ให้อัพเดทข้อมูล profile (ถ้ามีการเปลี่ยนแปลง)
        $needsUpdate = false;
        $updateFields = [];
        $updateValues = [];

        // ตรวจสอบว่ามีข้อมูลใหม่จาก profile หรือไม่
        if ($profile) {
            if (!empty($displayName) && $displayName !== 'Unknown' && $displayName !== $user['display_name']) {
                $updateFields[] = "display_name = ?";
                $updateValues[] = $displayName;
                $needsUpdate = true;
            }
            if (!empty($pictureUrl) && $pictureUrl !== $user['picture_url']) {
                $updateFields[] = "picture_url = ?";
                $updateValues[] = $pictureUrl;
                $needsUpdate = true;
            }
            if (!empty($statusMessage)) {
                $updateFields[] = "status_message = ?";
                $updateValues[] = $statusMessage;
                $needsUpdate = true;
            }
        }

        // อัพเดท line_account_id ถ้ายังไม่มี
        if ($lineAccountId && empty($user['line_account_id'])) {
            $updateFields[] = "line_account_id = ?";
            $updateValues[] = $lineAccountId;
            $needsUpdate = true;
        }

        // ทำการอัพเดทถ้ามีการเปลี่ยนแปลง
        if ($needsUpdate && !empty($updateFields)) {
            try {
                $updateValues[] = $user['id'];
                $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($updateValues);
                
                // อัพเดทข้อมูลใน array
                if (!empty($displayName) && $displayName !== 'Unknown') {
                    $user['display_name'] = $displayName;
                }
                if (!empty($pictureUrl)) {
                    $user['picture_url'] = $pictureUrl;
                }
                if ($lineAccountId) {
                    $user['line_account_id'] = $lineAccountId;
                }
            } catch (Exception $e) {
                error_log("getOrCreateUser update error: " . $e->getMessage());
            }
        }

        // อัพเดทข้อมูล follower ด้วย (ถ้ามี lineAccountId และมี profile ใหม่)
        if ($lineAccountId && $profile) {
            try {
                $stmt = $db->prepare("
                    UPDATE account_followers 
                    SET display_name = ?, 
                        picture_url = ?, 
                        status_message = ?,
                        last_interaction_at = NOW()
                    WHERE line_account_id = ? AND line_user_id = ?
                ");
                $stmt->execute([
                    $displayName,
                    $pictureUrl,
                    $statusMessage,
                    $lineAccountId,
                    $userId
                ]);
            } catch (Exception $e) {
                error_log("getOrCreateUser update follower error: " . $e->getMessage());
            }
        }
    }
    
    return $user;
}

/**
 * Save account follower - บันทึกข้อมูล follower แยกตามบอท
 */
function saveAccountFollower($db, $lineAccountId, $lineUserId, $dbUserId, $profile, $isFollow) {
    try {
        if ($isFollow) {
            // Follow event
            $stmt = $db->prepare("
                INSERT INTO account_followers 
                (line_account_id, line_user_id, user_id, display_name, picture_url, status_message, is_following, followed_at, follow_count) 
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), 1)
                ON DUPLICATE KEY UPDATE 
                    display_name = VALUES(display_name),
                    picture_url = VALUES(picture_url),
                    status_message = VALUES(status_message),
                    is_following = 1,
                    followed_at = IF(is_following = 0, NOW(), followed_at),
                    follow_count = follow_count + IF(is_following = 0, 1, 0),
                    unfollowed_at = NULL,
                    updated_at = NOW()
            ");
            $stmt->execute([
                $lineAccountId,
                $lineUserId,
                $dbUserId,
                $profile['displayName'] ?? '',
                $profile['pictureUrl'] ?? '',
                $profile['statusMessage'] ?? ''
            ]);
        } else {
            // Unfollow event
            $stmt = $db->prepare("
                UPDATE account_followers 
                SET is_following = 0, unfollowed_at = NOW(), updated_at = NOW()
                WHERE line_account_id = ? AND line_user_id = ?
            ");
            $stmt->execute([$lineAccountId, $lineUserId]);
        }
    } catch (Exception $e) {
        error_log("saveAccountFollower error: " . $e->getMessage());
    }
}

/**
 * Save account event - บันทึก event แยกตามบอท
 */
function saveAccountEvent($db, $lineAccountId, $eventType, $lineUserId, $dbUserId, $event) {
    // Skip if no line_user_id (required field)
    if (empty($lineUserId)) {
        return;
    }
    
    try {
        $eventData = json_encode($event, JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("
            INSERT INTO account_events 
            (line_account_id, event_type, line_user_id, user_id, event_data, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $lineAccountId,
            $eventType,
            $lineUserId,
            $dbUserId,
            $eventData
        ]);
        
        // อัพเดทสถิติรายวัน
        updateAccountDailyStats($db, $lineAccountId, $eventType . '_count');
        
    } catch (Exception $e) {
        error_log("saveAccountEvent error: " . $e->getMessage());
    }
}

/**
 * Update account daily stats - อัพเดทสถิติรายวัน
 */
function updateAccountDailyStats($db, $lineAccountId, $field) {
    try {
        $today = date('Y-m-d');
        $stmt = $db->prepare("
            INSERT INTO account_daily_stats (line_account_id, stat_date, {$field}) 
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE {$field} = {$field} + 1
        ");
        $stmt->execute([$lineAccountId, $today]);
    } catch (Exception $e) {
        error_log("updateAccountDailyStats error: " . $e->getMessage());
    }
}

/**
 * Update follower interaction - อัพเดทข้อมูล interaction ของ follower
 */
function updateFollowerInteraction($db, $lineAccountId, $lineUserId) {
    try {
        $stmt = $db->prepare("
            UPDATE account_followers 
            SET last_interaction_at = NOW(), interaction_count = interaction_count + 1
            WHERE line_account_id = ? AND line_user_id = ?
        ");
        $stmt->execute([$lineAccountId, $lineUserId]);
    } catch (Exception $e) {
        error_log("updateFollowerInteraction error: " . $e->getMessage());
    }
}

/**
 * Get account name by ID
 */
function getAccountName($db, $lineAccountId) {
    if (!$lineAccountId) return null;
    try {
        $stmt = $db->prepare("SELECT account_name FROM line_accounts WHERE id = ?");
        $stmt->execute([$lineAccountId]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Check user consent
 * - เช็คจาก line_user_id แทน user_id เพื่อให้ consent ใช้ได้ข้ามบอท
 */
function checkUserConsent($db, $userId, $lineUserId = null) {
    try {
        // ตรวจสอบว่ามี column consent_privacy หรือไม่
        $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'consent_privacy'");
        if ($stmt->rowCount() === 0) {
            return true; // ถ้าไม่มี column ให้ถือว่า consent แล้ว
        }
        
        // ถ้ามี lineUserId ให้เช็คจาก line_user_id (ใช้ได้ข้ามบอท)
        if ($lineUserId) {
            $stmt = $db->prepare("SELECT consent_privacy FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$lineUserId]);
        } else {
            // ถ้าไม่มี lineUserId ให้เช็คจาก id
            $stmt = $db->prepare("SELECT consent_privacy FROM users WHERE id = ?");
            $stmt->execute([$userId]);
        }
        
        $consent = $stmt->fetchColumn();
        return $consent == 1;
    } catch (Exception $e) {
        error_log("checkUserConsent error: " . $e->getMessage());
        return true; // ถ้า error ให้ถือว่า consent แล้ว
    }
}

/**
 * Get user state
 */
function getUserState($db, $userId) {
    try {
        // ดึงข้อมูลโดยไม่ตรวจสอบ expires_at ใน SQL
        $stmt = $db->prepare("SELECT * FROM user_states WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$userId]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$state) {
            return null;
        }
        
        // ตรวจสอบ expires_at ใน PHP
        if ($state['expires_at'] && strtotime($state['expires_at']) < time()) {
            // หมดอายุแล้ว - ลบออก
            clearUserState($db, $userId);
            return null;
        }
        
        return $state;
    } catch (Exception $e) {
        error_log("getUserState error: " . $e->getMessage());
        return null;
    }
}

/**
 * Set user state
 */
function setUserState($db, $userId, $state, $data = null, $expiresMinutes = 10) {
    try {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresMinutes} minutes"));
        
        // ลบ state เก่าก่อน
        clearUserState($db, $userId);
        
        // สร้าง state ใหม่
        $stmt = $db->prepare("
            INSERT INTO user_states (user_id, state, state_data, expires_at, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $state,
            $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            $expiresAt
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("setUserState error: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear user state
 */
function clearUserState($db, $userId) {
    try {
        $stmt = $db->prepare("DELETE FROM user_states WHERE user_id = ?");
        $stmt->execute([$userId]);
        return true;
    } catch (Exception $e) {
        error_log("clearUserState error: " . $e->getMessage());
        return false;
    }
}
