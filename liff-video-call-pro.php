    <?php
    /**
     * Video Call Pro - UI + API
     * ถ้ามี appointment parameter = แสดงหน้า UI
     * ถ้ามี action parameter = ทำงานเป็น API
     */

    // Parse liff.state if present (LIFF sends params this way)
    // PHP converts "liff.state" to "liff_state" automatically
    $liffState = $_GET['liff_state'] ?? null;
    
    // Also check raw query string for liff.state (in case PHP didn't parse it)
    if (!$liffState && isset($_SERVER['QUERY_STRING'])) {
        if (preg_match('/liff\.state=([^&]+)/', $_SERVER['QUERY_STRING'], $matches)) {
            $liffState = urldecode($matches[1]);
        }
    }
    
    if ($liffState) {
        parse_str(ltrim($liffState, '?'), $liffParams);
        $_GET = array_merge($_GET, $liffParams);
    }

    // Handle page redirect (e.g., page=appointments)
    if (isset($_GET['page'])) {
        $page = $_GET['page'];
        $account = $_GET['account'] ?? 1;
        
        // Get base URL from config or construct it
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'likesms.net';
        $baseUrl = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        
        switch ($page) {
            case 'appointments':
                header('Location: ' . $baseUrl . '/liff-my-appointments.php?account=' . $account);
                exit;
            case 'appointment':
                header('Location: ' . $baseUrl . '/liff-appointment.php?account=' . $account);
                exit;
            default:
                // Continue to video call
                break;
        }
    }

    // Check if this is a UI request (has appointment or pharmacist param and no action)
    $isUIRequest = (isset($_GET['appointment']) || isset($_GET['pharmacist']) || (!isset($_GET['action']) && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_POST))) && !isset($_GET['action']);

    if (!$isUIRequest) {
        // API Mode
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
    }

    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit(0);
    }

    // Error handling - catch all errors (only for API mode)
    if (!$isUIRequest) {
        set_error_handler(function($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // Catch fatal errors
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $error['message']]);
            }
        });
    }

    try {
        require_once __DIR__ . '/config/config.php';
        require_once __DIR__ . '/config/database.php';
        
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        if ($isUIRequest) {
            die('<h1>Error</h1><p>Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</p>');
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }

    // ========== UI MODE ==========
    if ($isUIRequest) {
        $appointmentId = $_GET['appointment'] ?? null;
        $pharmacistId = $_GET['pharmacist'] ?? null;
        $lineAccountId = $_GET['account'] ?? 1;
        $appointmentInfo = null;
        $pharmacistInfo = null;
        
        // If no appointment or pharmacist, show default video call page
        $showDefaultPage = !$appointmentId && !$pharmacistId;

        if ($appointmentId) {
            try {
                $stmt = $db->prepare("
                    SELECT a.*, p.name as pharmacist_name, p.title as pharmacist_title, p.image_url as pharmacist_image
                    FROM appointments a
                    JOIN pharmacists p ON a.pharmacist_id = p.id
                    WHERE a.appointment_id = ?
                ");
                $stmt->execute([$appointmentId]);
                $appointmentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($appointmentInfo) {
                    $lineAccountId = $appointmentInfo['line_account_id'];
                }
            } catch (Exception $e) {}
        }
        
        // Direct pharmacist video call (without appointment)
        if ($pharmacistId && !$appointmentInfo) {
            try {
                $stmt = $db->prepare("SELECT id, name, title, image_url, specialty FROM pharmacists WHERE id = ?");
                $stmt->execute([$pharmacistId]);
                $pharmacistInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        // Get Video LIFF ID from database
        $videoLiffId = '';
        try {
            $stmt = $db->prepare("SELECT liff_video_id, liff_id FROM line_accounts WHERE id = ? OR is_default = 1 ORDER BY is_default DESC LIMIT 1");
            $stmt->execute([$lineAccountId]);
            $row = $stmt->fetch();
            if ($row) {
                $videoLiffId = $row['liff_video_id'] ?? $row['liff_id'] ?? '';
            }
        } catch (Exception $e) {}

        if (empty($videoLiffId) && defined('LIFF_ID')) {
            $videoLiffId = LIFF_ID;
        }
        
        // Output UI HTML
        include __DIR__ . '/includes/video-call-ui.php';
        exit;
    }

    // Check if tables exist and auto-migrate
    try {
        $db->query("SELECT 1 FROM video_calls LIMIT 1");
        
        // Auto-add missing columns to video_call_signals
        $cols = $db->query("DESCRIBE video_call_signals")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('from_who', $cols)) {
            $db->exec("ALTER TABLE video_call_signals ADD COLUMN from_who VARCHAR(20) DEFAULT 'customer'");
        }
        if (!in_array('processed', $cols)) {
            $db->exec("ALTER TABLE video_call_signals ADD COLUMN processed TINYINT(1) DEFAULT 0");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'ยังไม่ได้รัน migration กรุณาเปิด run_video_call_migration.php ก่อน']);
        exit;
    }

    // GET requests
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
        
        // Debug endpoint - ดูข้อมูลทั้งหมด
        if ($action === 'debug') {
            $stmt = $db->query("SELECT * FROM video_calls ORDER BY created_at DESC LIMIT 10");
            $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt2 = $db->query("SELECT COUNT(*) as total FROM video_calls");
            $total = $stmt2->fetch()['total'];
            
            $stmt3 = $db->query("SELECT status, COUNT(*) as cnt FROM video_calls GROUP BY status");
            $byStatus = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'total_calls' => $total,
                'by_status' => $byStatus,
                'recent_calls' => $calls
            ], JSON_PRETTY_PRINT);
            exit;
        }
        
        if ($action === 'check_calls') {
            $accountId = $_GET['account_id'] ?? null;
            
            // ดึงสายทั้งหมดที่รอรับ พร้อมข้อมูลผู้ใช้จาก users table
            // First check if users table has phone column
            $hasPhone = false;
            try {
                $cols = $db->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
                $hasPhone = in_array('phone', $cols);
            } catch (Exception $e) {}
            
            // Include empty status as well (bug fix - some calls have empty status)
            $sql = "SELECT vc.id, vc.room_id, vc.user_id, vc.line_user_id, 
                        COALESCE(u.display_name, vc.display_name, 'ลูกค้า') as display_name, 
                        COALESCE(u.picture_url, vc.picture_url) as picture_url, 
                        vc.line_account_id, vc.status, vc.created_at" . 
                        ($hasPhone ? ", u.phone" : "") . "
                    FROM video_calls vc 
                    LEFT JOIN users u ON vc.user_id = u.id OR vc.line_user_id = u.line_user_id
                    WHERE (vc.status IN ('pending', 'ringing', '') OR vc.status IS NULL)
                    AND vc.ended_at IS NULL
                    ORDER BY vc.created_at DESC
                    LIMIT 20";
            
            try {
                $stmt = $db->query($sql);
                $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Fallback: simple query without join - include empty status
                $stmt = $db->query("SELECT * FROM video_calls WHERE (status IN ('pending', 'ringing', '') OR status IS NULL) AND ended_at IS NULL ORDER BY created_at DESC LIMIT 20");
                $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true, 
                'calls' => $calls, 
                'count' => count($calls)
            ]);
            exit;
        }
        
        if ($action === 'get_status') {
            $callId = $_GET['call_id'] ?? '';
            
            $stmt = $db->prepare("SELECT * FROM video_calls WHERE id = ? OR room_id = ?");
            $stmt->execute([$callId, $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($call) {
                // Get latest signal
                $stmt = $db->prepare("SELECT * FROM video_call_signals WHERE call_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$call['id']]);
                $signal = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'status' => $call['status'],
                    'signal' => $signal ? [
                        'type' => $signal['signal_type'],
                        'data' => json_decode($signal['signal_data'], true)
                    ] : null
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Call not found']);
            }
            exit;
        }
        
        // Get signals for WebRTC
        if ($action === 'get_signals') {
            $callId = $_GET['call_id'] ?? '';
            $forWho = $_GET['for'] ?? ''; // 'admin' or 'customer'
            
            // Get call ID
            $stmt = $db->prepare("SELECT id FROM video_calls WHERE id = ? OR room_id = ?");
            $stmt->execute([$callId, $callId]);
            $call = $stmt->fetch();
            
            if (!$call) {
                echo json_encode(['success' => false, 'error' => 'Call not found']);
                exit;
            }
            
            // Check if from_who column exists
            $hasFromWho = false;
            try {
                $cols = $db->query("DESCRIBE video_call_signals")->fetchAll(PDO::FETCH_COLUMN);
                $hasFromWho = in_array('from_who', $cols);
            } catch (Exception $e) {}
            
            // Get unprocessed signals for this recipient
            $fromWho = $forWho === 'admin' ? 'customer' : 'admin';
            
            // Debug: log all signals first
            $allSignals = $db->prepare("SELECT id, signal_type, from_who, processed FROM video_call_signals WHERE call_id = ? ORDER BY created_at ASC");
            $allSignals->execute([$call['id']]);
            $allSigs = $allSignals->fetchAll(PDO::FETCH_ASSOC);
            
            // SIMPLE: Get all signals for this call, filter in PHP
            $stmt = $db->prepare("SELECT * FROM video_call_signals WHERE call_id = ? ORDER BY created_at ASC");
            $stmt->execute([$call['id']]);
            $allSignalsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filter based on who is asking
            $signals = [];
            $debugFilter = [];
            foreach ($allSignalsRaw as $sig) {
                $sigFrom = $sig['from_who'] ?? '';
                $sigType = $sig['signal_type'] ?? '';
                $processed = $sig['processed'] ?? 0;
                
                $debugFilter[] = [
                    'id' => $sig['id'],
                    'type' => $sigType,
                    'from' => $sigFrom,
                    'processed' => $processed,
                    'forWho' => $forWho,
                    'match' => false
                ];
                
                if ($forWho === 'customer') {
                    // Customer wants signals FROM admin
                    if ($sigFrom === 'admin') {
                        // answer: always include (ignore processed)
                        // ice-candidate: only if not processed
                        if ($sigType === 'answer' || ($sigType === 'ice-candidate' && !$processed)) {
                            $signals[] = $sig;
                            $debugFilter[count($debugFilter)-1]['match'] = true;
                        }
                    }
                } else {
                    // Admin wants signals FROM customer
                    if ($sigFrom === 'customer') {
                        // offer: always include (ignore processed)
                        // ice-candidate: only if not processed
                        if ($sigType === 'offer' || ($sigType === 'ice-candidate' && !$processed)) {
                            $signals[] = $sig;
                            $debugFilter[count($debugFilter)-1]['match'] = true;
                        }
                    }
                }
            }
            // NOTE: Don't overwrite $signals here!
            
            // Mark as processed (except offer and answer - they need to be received by both sides)
            if (!empty($signals)) {
                // Only mark ice-candidate as processed
                $iceIds = array_column(array_filter($signals, function($s) {
                    return $s['signal_type'] === 'ice-candidate';
                }), 'id');
                
                if (!empty($iceIds)) {
                    $placeholders = implode(',', array_fill(0, count($iceIds), '?'));
                    $db->prepare("UPDATE video_call_signals SET processed = 1 WHERE id IN ($placeholders)")->execute($iceIds);
                }
            }
            
            // Format signals
            $formatted = array_map(function($s) {
                return [
                    'id' => $s['id'],
                    'signal_type' => $s['signal_type'],
                    'signal_data' => json_decode($s['signal_data'], true)
                ];
            }, $signals);
            
            echo json_encode([
                'success' => true, 
                'signals' => $formatted, 
                'debug' => [
                    'call_id' => $call['id'],
                    'for' => $forWho,
                    'found_count' => count($signals),
                    'all_signals' => $allSigs,
                    'filter_debug' => $debugFilter
                ]
            ]);
            exit;
        }
    }

    // POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_POST['action'] ?? '';
        
        if ($action === 'create') {
            try {
                // Create new call
                $lineUserId = $input['user_id'] ?? '';
                $displayName = $input['display_name'] ?? 'ลูกค้า';
                $pictureUrl = $input['picture_url'] ?? '';
                $accountId = $input['account_id'] ?? null;
                
                // Validate account_id exists
                if ($accountId) {
                    $stmt = $db->prepare("SELECT id FROM line_accounts WHERE id = ?");
                    $stmt->execute([$accountId]);
                    if (!$stmt->fetch()) {
                        $accountId = null; // Set to null if not found
                    }
                }
                
                // Get or create user
                $userId = null;
                if ($lineUserId && $lineUserId !== 'guest') {
                    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
                    $stmt->execute([$lineUserId]);
                    $user = $stmt->fetch();
                    $userId = $user ? $user['id'] : null;
                }
                
                $roomId = 'call_' . uniqid() . '_' . time();
                
                // Check which columns exist in video_calls table
                $columns = [];
                try {
                    $colResult = $db->query("SHOW COLUMNS FROM video_calls");
                    $columns = $colResult->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                    $columns = ['room_id', 'status', 'created_at'];
                }
                
                // Build dynamic insert based on available columns
                $insertCols = ['room_id', 'status', 'created_at'];
                $insertVals = [$roomId, 'ringing', date('Y-m-d H:i:s')];
                
                if (in_array('user_id', $columns)) {
                    $insertCols[] = 'user_id';
                    $insertVals[] = $userId;
                }
                if (in_array('line_user_id', $columns)) {
                    $insertCols[] = 'line_user_id';
                    $insertVals[] = $lineUserId ?: null;
                }
                if (in_array('display_name', $columns)) {
                    $insertCols[] = 'display_name';
                    $insertVals[] = $displayName;
                }
                if (in_array('picture_url', $columns)) {
                    $insertCols[] = 'picture_url';
                    $insertVals[] = $pictureUrl;
                }
                if (in_array('line_account_id', $columns)) {
                    $insertCols[] = 'line_account_id';
                    $insertVals[] = $accountId;
                }
                
                $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                $colNames = implode(', ', $insertCols);
                
                $stmt = $db->prepare("INSERT INTO video_calls ($colNames) VALUES ($placeholders)");
                $stmt->execute($insertVals);
                
                $callId = $db->lastInsertId();
                
                echo json_encode(['success' => true, 'call_id' => $callId, 'room_id' => $roomId]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Create call failed: ' . $e->getMessage()]);
            }
            exit;
        }
        
        if ($action === 'answer') {
            $callId = $input['call_id'] ?? '';
            
            $stmt = $db->prepare("UPDATE video_calls SET status = 'active', answered_at = NOW() WHERE id = ? OR room_id = ?");
            $stmt->execute([$callId, $callId]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        if ($action === 'reject') {
            $callId = $input['call_id'] ?? '';
            
            $stmt = $db->prepare("UPDATE video_calls SET status = 'rejected', ended_at = NOW() WHERE id = ? OR room_id = ?");
            $stmt->execute([$callId, $callId]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        if ($action === 'end') {
            $callId = $input['call_id'] ?? '';
            $duration = $input['duration'] ?? 0;
            $notes = $input['notes'] ?? null;
            
            // Build update query dynamically
            $updateFields = ['status = ?', 'duration = ?', 'ended_at = NOW()'];
            $updateValues = ['completed', $duration];
            
            if ($notes !== null) {
                $updateFields[] = 'notes = ?';
                $updateValues[] = $notes;
            }
            
            $updateValues[] = $callId;
            $updateValues[] = $callId;
            
            $sql = "UPDATE video_calls SET " . implode(', ', $updateFields) . " WHERE id = ? OR room_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($updateValues);
            
            echo json_encode(['success' => true, 'duration' => $duration]);
            exit;
        }
        
        // Save consultation notes - Requirement 6.7
        if ($action === 'save_notes') {
            $callId = $input['call_id'] ?? '';
            $notes = $input['notes'] ?? '';
            
            $stmt = $db->prepare("UPDATE video_calls SET notes = ? WHERE id = ? OR room_id = ?");
            $stmt->execute([$notes, $callId, $callId]);
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        // Get call summary - Requirement 6.7
        if ($action === 'get_summary') {
            $callId = $input['call_id'] ?? $_GET['call_id'] ?? '';
            
            $stmt = $db->prepare("
                SELECT vc.*, 
                       u.display_name as user_display_name,
                       u.picture_url as user_picture_url
                FROM video_calls vc
                LEFT JOIN users u ON vc.user_id = u.id
                WHERE vc.id = ? OR vc.room_id = ?
            ");
            $stmt->execute([$callId, $callId]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($call) {
                echo json_encode([
                    'success' => true,
                    'call' => [
                        'id' => $call['id'],
                        'room_id' => $call['room_id'],
                        'status' => $call['status'],
                        'duration' => (int)$call['duration'],
                        'notes' => $call['notes'],
                        'created_at' => $call['created_at'],
                        'answered_at' => $call['answered_at'],
                        'ended_at' => $call['ended_at'],
                        'user' => [
                            'display_name' => $call['user_display_name'] ?? $call['display_name'],
                            'picture_url' => $call['user_picture_url'] ?? $call['picture_url']
                        ]
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Call not found']);
            }
            exit;
        }
        
        if ($action === 'signal') {
            $callId = $input['call_id'] ?? '';
            $signalType = $input['signal_type'] ?? '';
            $signalData = $input['signal_data'] ?? [];
            $fromWho = $input['from'] ?? 'customer'; // 'admin' or 'customer'
            
            // Get call ID if room_id provided
            $stmt = $db->prepare("SELECT id FROM video_calls WHERE id = ? OR room_id = ?");
            $stmt->execute([$callId, $callId]);
            $call = $stmt->fetch();
            
            if ($call) {
                // Always try to insert with from_who
                try {
                    $stmt = $db->prepare("INSERT INTO video_call_signals (call_id, signal_type, signal_data, from_who, processed, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
                    $stmt->execute([$call['id'], $signalType, json_encode($signalData), $fromWho]);
                } catch (Exception $e) {
                    // Fallback without from_who
                    $stmt = $db->prepare("INSERT INTO video_call_signals (call_id, signal_type, signal_data, created_at) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$call['id'], $signalType, json_encode($signalData)]);
                }
                
                $insertId = $db->lastInsertId();
                echo json_encode([
                    'success' => true, 
                    'signal_type' => $signalType, 
                    'from' => $fromWho,
                    'signal_id' => $insertId,
                    'call_id' => $call['id']
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Call not found']);
            }
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
