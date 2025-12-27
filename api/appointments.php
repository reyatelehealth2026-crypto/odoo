<?php
/**
 * Appointments API - ระบบนัดหมายพบเภสัชกร
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    switch ($action) {
        case 'pharmacists':
            handleGetPharmacists($db);
            break;
        case 'pharmacist_detail':
            handlePharmacistDetail($db);
            break;
        case 'available_slots':
            handleAvailableSlots($db);
            break;
        case 'book':
            handleBook($db, $input ?? $_POST);
            break;
        case 'my_appointments':
            handleMyAppointments($db);
            break;
        case 'today_appointments':
            handleTodayAppointments($db);
            break;
        case 'detail':
            handleAppointmentDetail($db);
            break;
        case 'cancel':
            handleCancel($db, $input ?? $_POST);
            break;
        case 'rate':
            handleRate($db, $input ?? $_POST);
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage());
}

/**
 * ดึงรายชื่อเภสัชกร
 */
function handleGetPharmacists($db) {
    $lineAccountId = $_GET['line_account_id'] ?? 1;
    
    try {
        // Get column names from pharmacists table
        $columns = $db->query("SHOW COLUMNS FROM pharmacists")->fetchAll(PDO::FETCH_COLUMN);
        $columnSet = array_flip($columns);
        
        // Build SELECT with only existing columns
        $selectCols = ['id', 'name'];
        $optionalCols = ['title', 'specialty', 'sub_specialty', 'hospital', 'license_no', 'bio', 
                         'consulting_areas', 'work_experience', 'image_url', 'rating', 'review_count', 
                         'consultation_fee', 'consultation_duration', 'is_available', 'is_active', 'line_account_id'];
        
        foreach ($optionalCols as $col) {
            if (isset($columnSet[$col])) {
                $selectCols[] = $col;
            }
        }
        
        $selectStr = implode(', ', $selectCols);
        
        // Build WHERE clause
        $whereConditions = [];
        $hasIsActive = isset($columnSet['is_active']);
        $hasIsAvailable = isset($columnSet['is_available']);
        
        if ($hasIsActive) {
            $whereConditions[] = "is_active = 1";
        }
        
        $whereClause = count($whereConditions) > 0 ? "WHERE " . implode(' AND ', $whereConditions) : "";
        
        // Get all active pharmacists
        $sql = "SELECT {$selectStr} FROM pharmacists {$whereClause} ORDER BY id DESC";
        $stmt = $db->query($sql);
        $pharmacists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("handleGetPharmacists: Found " . count($pharmacists) . " pharmacists");
        
        // Get case count for each pharmacist (with error handling)
        foreach ($pharmacists as &$p) {
            $p['case_count'] = 0;
            // Set defaults for missing columns
            if (!isset($p['title'])) $p['title'] = '';
            if (!isset($p['specialty'])) $p['specialty'] = 'เภสัชกร';
            if (!isset($p['is_available'])) $p['is_available'] = 1;
            if (!isset($p['rating'])) $p['rating'] = 5.0;
            if (!isset($p['review_count'])) $p['review_count'] = 0;
            if (!isset($p['consultation_fee'])) $p['consultation_fee'] = 0;
            if (!isset($p['consultation_duration'])) $p['consultation_duration'] = 15;
            
            try {
                $stmt2 = $db->prepare("SELECT COUNT(*) FROM appointments WHERE pharmacist_id = ? AND status = 'completed'");
                $stmt2->execute([$p['id']]);
                $p['case_count'] = $stmt2->fetchColumn() ?: 0;
            } catch (Exception $e) {
                // appointments table may not exist
            }
            
            // Get insurances (with error handling)
            $p['insurances'] = [];
            try {
                $stmt3 = $db->prepare("
                    SELECT i.id, i.name, i.logo_url 
                    FROM pharmacist_insurances pi 
                    JOIN insurances i ON pi.insurance_id = i.id 
                    WHERE pi.pharmacist_id = ?
                ");
                $stmt3->execute([$p['id']]);
                $p['insurances'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Tables may not exist
            }
        }
        
        jsonResponse(true, 'OK', ['pharmacists' => $pharmacists]);
        
    } catch (Exception $e) {
        error_log("handleGetPharmacists error: " . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage(), ['pharmacists' => []]);
    }
}

/**
 * ดึงรายละเอียดเภสัชกร
 */
function handlePharmacistDetail($db) {
    $pharmacistId = $_GET['pharmacist_id'] ?? 0;
    
    if (empty($pharmacistId)) {
        jsonResponse(false, 'Missing pharmacist_id');
    }
    
    $stmt = $db->prepare("
        SELECT p.*, 
               (SELECT COUNT(*) FROM appointments WHERE pharmacist_id = p.id AND status = 'completed') as case_count
        FROM pharmacists p 
        WHERE p.id = ? AND p.is_active = 1
    ");
    $stmt->execute([$pharmacistId]);
    $pharmacist = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pharmacist) {
        jsonResponse(false, 'ไม่พบข้อมูลเภสัชกร');
    }
    
    // Get schedules
    $stmt = $db->prepare("SELECT day_of_week, start_time, end_time FROM pharmacist_schedules WHERE pharmacist_id = ? AND is_available = 1");
    $stmt->execute([$pharmacistId]);
    $pharmacist['schedules'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get insurances (with error handling)
    $pharmacist['insurances'] = [];
    try {
        $stmt = $db->prepare("
            SELECT i.id, i.name, i.logo_url 
            FROM pharmacist_insurances pi 
            JOIN insurances i ON pi.insurance_id = i.id 
            WHERE pi.pharmacist_id = ?
        ");
        $stmt->execute([$pharmacistId]);
        $pharmacist['insurances'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Tables may not exist, continue without insurances
    }
    
    jsonResponse(true, 'OK', ['pharmacist' => $pharmacist]);
}

/**
 * ดึงช่วงเวลาว่าง
 */
function handleAvailableSlots($db) {
    $pharmacistId = $_GET['pharmacist_id'] ?? 0;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (empty($pharmacistId)) {
        jsonResponse(false, 'Missing pharmacist_id');
    }
    
    // Validate date (not in past, not too far in future)
    $selectedDate = new DateTime($date);
    $today = new DateTime('today');
    $maxDate = (new DateTime('today'))->modify('+30 days');
    
    if ($selectedDate < $today) {
        jsonResponse(false, 'ไม่สามารถจองวันที่ผ่านมาแล้ว');
    }
    if ($selectedDate > $maxDate) {
        jsonResponse(false, 'สามารถจองล่วงหน้าได้ไม่เกิน 30 วัน');
    }
    
    // Get pharmacist info
    $stmt = $db->prepare("SELECT consultation_duration FROM pharmacists WHERE id = ?");
    $stmt->execute([$pharmacistId]);
    $pharmacist = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pharmacist) {
        jsonResponse(false, 'ไม่พบข้อมูลเภสัชกร');
    }
    
    $duration = $pharmacist['consultation_duration'] ?: 15;
    $dayOfWeek = $selectedDate->format('w'); // 0-6
    
    // Check if holiday
    $stmt = $db->prepare("SELECT id FROM pharmacist_holidays WHERE pharmacist_id = ? AND holiday_date = ?");
    $stmt->execute([$pharmacistId, $date]);
    if ($stmt->fetch()) {
        jsonResponse(true, 'OK', ['slots' => [], 'message' => 'วันหยุด']);
    }
    
    // Get schedule for this day
    $stmt = $db->prepare("SELECT start_time, end_time FROM pharmacist_schedules WHERE pharmacist_id = ? AND day_of_week = ? AND is_available = 1");
    $stmt->execute([$pharmacistId, $dayOfWeek]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$schedule) {
        jsonResponse(true, 'OK', ['slots' => [], 'message' => 'ไม่มีตารางในวันนี้']);
    }
    
    // Get booked slots
    $stmt = $db->prepare("
        SELECT appointment_time, duration 
        FROM appointments 
        WHERE pharmacist_id = ? AND appointment_date = ? AND status NOT IN ('cancelled', 'no_show')
    ");
    $stmt->execute([$pharmacistId, $date]);
    $bookedSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate available slots
    $slots = [];
    $startTime = new DateTime($date . ' ' . $schedule['start_time']);
    $endTime = new DateTime($date . ' ' . $schedule['end_time']);
    $now = new DateTime();
    
    while ($startTime < $endTime) {
        $slotEnd = clone $startTime;
        $slotEnd->modify("+{$duration} minutes");
        
        if ($slotEnd > $endTime) break;
        
        // Skip if in the past (for today)
        if ($selectedDate->format('Y-m-d') === $today->format('Y-m-d') && $startTime <= $now) {
            $startTime->modify("+{$duration} minutes");
            continue;
        }
        
        // Check if slot is booked
        $isBooked = false;
        foreach ($bookedSlots as $booked) {
            $bookedStart = new DateTime($date . ' ' . $booked['appointment_time']);
            $bookedEnd = clone $bookedStart;
            $bookedEnd->modify("+{$booked['duration']} minutes");
            
            if ($startTime < $bookedEnd && $slotEnd > $bookedStart) {
                $isBooked = true;
                break;
            }
        }
        
        $slots[] = [
            'time' => $startTime->format('H:i'),
            'available' => !$isBooked
        ];
        
        $startTime->modify("+{$duration} minutes");
    }
    
    jsonResponse(true, 'OK', ['slots' => $slots, 'duration' => $duration]);
}

/**
 * จองนัดหมาย
 */
function handleBook($db, $data) {
    $lineUserId = $data['line_user_id'] ?? '';
    $lineAccountId = $data['line_account_id'] ?? 1;
    $pharmacistId = $data['pharmacist_id'] ?? 0;
    $date = $data['date'] ?? '';
    $time = $data['time'] ?? '';
    $symptoms = $data['symptoms'] ?? '';
    $type = $data['type'] ?? 'scheduled';
    
    if (empty($lineUserId)) {
        jsonResponse(false, 'กรุณาเข้าสู่ระบบ');
    }
    if (empty($pharmacistId) || empty($date) || empty($time)) {
        jsonResponse(false, 'ข้อมูลไม่ครบถ้วน');
    }
    
    // Get user
    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }
    
    // Get pharmacist
    $stmt = $db->prepare("SELECT id, consultation_fee, consultation_duration FROM pharmacists WHERE id = ? AND is_active = 1");
    $stmt->execute([$pharmacistId]);
    $pharmacist = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pharmacist) {
        jsonResponse(false, 'ไม่พบข้อมูลเภสัชกร');
    }
    
    $duration = $pharmacist['consultation_duration'] ?: 15;
    $endTime = date('H:i:s', strtotime($time) + ($duration * 60));
    
    // Check if slot is still available
    $stmt = $db->prepare("
        SELECT id FROM appointments 
        WHERE pharmacist_id = ? AND appointment_date = ? AND appointment_time = ? 
        AND status NOT IN ('cancelled', 'no_show')
    ");
    $stmt->execute([$pharmacistId, $date, $time]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'ช่วงเวลานี้ถูกจองแล้ว กรุณาเลือกเวลาอื่น');
    }
    
    // Check if user already has appointment at same time
    $stmt = $db->prepare("
        SELECT id FROM appointments 
        WHERE user_id = ? AND appointment_date = ? AND appointment_time = ? 
        AND status NOT IN ('cancelled', 'no_show')
    ");
    $stmt->execute([$user['id'], $date, $time]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'คุณมีนัดหมายในเวลานี้แล้ว');
    }
    
    // Generate appointment ID
    $appointmentId = 'APT' . date('ymdHis') . rand(100, 999);
    
    // Create appointment
    $stmt = $db->prepare("
        INSERT INTO appointments (
            line_account_id, appointment_id, user_id, pharmacist_id,
            appointment_date, appointment_time, end_time, duration,
            type, symptoms, consultation_fee, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')
    ");
    $stmt->execute([
        $lineAccountId, $appointmentId, $user['id'], $pharmacistId,
        $date, $time, $endTime, $duration,
        $type, $symptoms, $pharmacist['consultation_fee']
    ]);
    
    jsonResponse(true, 'จองนัดหมายสำเร็จ!', [
        'appointment_id' => $appointmentId,
        'date' => $date,
        'time' => $time,
        'duration' => $duration
    ]);
}

/**
 * ดึงนัดหมายวันนี้ (สำหรับ Admin)
 */
function handleTodayAppointments($db) {
    $lineAccountId = $_GET['line_account_id'] ?? 1;
    $today = date('Y-m-d');
    
    $stmt = $db->prepare("
        SELECT a.*, 
               p.name as pharmacist_name, p.image_url as pharmacist_image,
               u.display_name as user_name, u.first_name, u.last_name, u.phone as user_phone,
               u.picture_url as user_picture
        FROM appointments a
        JOIN pharmacists p ON a.pharmacist_id = p.id
        JOIN users u ON a.user_id = u.id
        WHERE a.appointment_date = ?
          AND (a.line_account_id = ? OR a.line_account_id IS NULL)
          AND a.status NOT IN ('cancelled')
        ORDER BY a.appointment_time ASC
    ");
    $stmt->execute([$today, $lineAccountId]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format user name
    foreach ($appointments as &$apt) {
        $apt['user_name'] = $apt['first_name'] ?: $apt['user_name'] ?: 'ลูกค้า';
        if ($apt['last_name']) {
            $apt['user_name'] .= ' ' . $apt['last_name'];
        }
    }
    
    jsonResponse(true, 'OK', ['appointments' => $appointments]);
}

/**
 * ดึงนัดหมายของผู้ใช้
 */
function handleMyAppointments($db) {
    $lineUserId = $_GET['line_user_id'] ?? '';
    $status = $_GET['status'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 50);
    
    if (empty($lineUserId)) {
        jsonResponse(false, 'Missing line_user_id');
    }
    
    // Get user
    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }
    
    $sql = "
        SELECT a.*, p.name as pharmacist_name, p.title as pharmacist_title, 
               p.specialty, p.image_url as pharmacist_image
        FROM appointments a
        JOIN pharmacists p ON a.pharmacist_id = p.id
        WHERE a.user_id = ?
    ";
    $params = [$user['id']];
    
    if (!empty($status)) {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate upcoming and past
    $today = date('Y-m-d');
    $upcoming = [];
    $past = [];
    
    foreach ($appointments as $apt) {
        if ($apt['appointment_date'] >= $today && !in_array($apt['status'], ['completed', 'cancelled', 'no_show'])) {
            $upcoming[] = $apt;
        } else {
            $past[] = $apt;
        }
    }
    
    jsonResponse(true, 'OK', [
        'upcoming' => $upcoming,
        'past' => $past,
        'all' => $appointments
    ]);
}

/**
 * ดึงรายละเอียดนัดหมาย
 */
function handleAppointmentDetail($db) {
    $appointmentId = $_GET['appointment_id'] ?? '';
    $lineUserId = $_GET['line_user_id'] ?? '';
    
    if (empty($appointmentId)) {
        jsonResponse(false, 'Missing appointment_id');
    }
    
    $stmt = $db->prepare("
        SELECT a.*, p.name as pharmacist_name, p.title as pharmacist_title,
               p.specialty, p.image_url as pharmacist_image, p.bio as pharmacist_bio
        FROM appointments a
        JOIN pharmacists p ON a.pharmacist_id = p.id
        WHERE a.appointment_id = ?
    ");
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        jsonResponse(false, 'ไม่พบนัดหมายนี้');
    }
    
    // Verify ownership
    if (!empty($lineUserId)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $appointment['user_id'] != $user['id']) {
            jsonResponse(false, 'ไม่มีสิทธิ์ดูนัดหมายนี้');
        }
    }
    
    jsonResponse(true, 'OK', ['appointment' => $appointment]);
}

/**
 * ยกเลิกนัดหมาย
 */
function handleCancel($db, $data) {
    $appointmentId = $data['appointment_id'] ?? '';
    $lineUserId = $data['line_user_id'] ?? '';
    $reason = $data['reason'] ?? '';
    
    if (empty($appointmentId) || empty($lineUserId)) {
        jsonResponse(false, 'ข้อมูลไม่ครบถ้วน');
    }
    
    // Get user
    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }
    
    // Get appointment
    $stmt = $db->prepare("SELECT * FROM appointments WHERE appointment_id = ? AND user_id = ?");
    $stmt->execute([$appointmentId, $user['id']]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        jsonResponse(false, 'ไม่พบนัดหมายนี้');
    }
    
    if (in_array($appointment['status'], ['completed', 'cancelled'])) {
        jsonResponse(false, 'ไม่สามารถยกเลิกนัดหมายนี้ได้');
    }
    
    // Check if can cancel (at least 2 hours before)
    $appointmentDateTime = new DateTime($appointment['appointment_date'] . ' ' . $appointment['appointment_time']);
    $now = new DateTime();
    $diff = $now->diff($appointmentDateTime);
    $hoursUntil = ($diff->days * 24) + $diff->h;
    
    if ($appointmentDateTime <= $now) {
        jsonResponse(false, 'ไม่สามารถยกเลิกนัดหมายที่ผ่านไปแล้ว');
    }
    
    // Update status
    $stmt = $db->prepare("
        UPDATE appointments 
        SET status = 'cancelled', cancelled_by = 'user', cancelled_reason = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$reason, $appointment['id']]);
    
    jsonResponse(true, 'ยกเลิกนัดหมายสำเร็จ');
}

/**
 * ให้คะแนนนัดหมาย
 */
function handleRate($db, $data) {
    $appointmentId = $data['appointment_id'] ?? '';
    $lineUserId = $data['line_user_id'] ?? '';
    $rating = (int)($data['rating'] ?? 0);
    $review = $data['review'] ?? '';
    
    if (empty($appointmentId) || empty($lineUserId) || $rating < 1 || $rating > 5) {
        jsonResponse(false, 'ข้อมูลไม่ถูกต้อง');
    }
    
    // Get user
    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }
    
    // Get appointment
    $stmt = $db->prepare("SELECT * FROM appointments WHERE appointment_id = ? AND user_id = ? AND status = 'completed'");
    $stmt->execute([$appointmentId, $user['id']]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        jsonResponse(false, 'ไม่พบนัดหมายหรือยังไม่เสร็จสิ้น');
    }
    
    if ($appointment['rating']) {
        jsonResponse(false, 'คุณให้คะแนนนัดหมายนี้แล้ว');
    }
    
    // Update rating
    $stmt = $db->prepare("UPDATE appointments SET rating = ?, review = ? WHERE id = ?");
    $stmt->execute([$rating, $review, $appointment['id']]);
    
    // Update pharmacist rating
    $stmt = $db->prepare("
        UPDATE pharmacists SET 
            rating = (SELECT AVG(rating) FROM appointments WHERE pharmacist_id = ? AND rating IS NOT NULL),
            review_count = (SELECT COUNT(*) FROM appointments WHERE pharmacist_id = ? AND rating IS NOT NULL)
        WHERE id = ?
    ");
    $stmt->execute([$appointment['pharmacist_id'], $appointment['pharmacist_id'], $appointment['pharmacist_id']]);
    
    jsonResponse(true, 'ขอบคุณสำหรับการให้คะแนน');
}

function jsonResponse($success, $message, $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        ...$data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
