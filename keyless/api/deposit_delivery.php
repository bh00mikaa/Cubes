<?php
// deposit_delivery.php - Package Deposit Handler

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('LOG_FILE', dirname(__DIR__) . '/logs/deposit_debug.log');

$logs_dir = dirname(__DIR__) . '/logs';
if (!file_exists($logs_dir)) {
    @mkdir($logs_dir, 0755, true);
}

ini_set('error_log', LOG_FILE);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!file_exists(__DIR__ . '/config.php')) {
    respond(['success' => false, 'message' => 'Configuration file not found'], 500);
}

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'deposit';
writeLog("Action: {$action}");

switch ($action) {
    case 'get_towers':
        getTowers($conn);
        break;
    
    case 'get_flats':
        getFlats($conn);
        break;
    
    case 'verify_flat':
        verifyFlat($conn);
        break;
    
    case 'get_resident_name':
        getResidentName($conn);
        break;
    
    case 'deposit':
        depositPackage($conn);
        break;
    
    case 'test_email':
        testEmail();
        break;
    

    default:
        respond(['success' => false, 'message' => 'Invalid action'], 400);
}

// ==================== HELPER FUNCTIONS ====================

function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents(LOG_FILE, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getCurrentISTTimestamp() {
    $dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    return $dt->format('Y-m-d H:i:s');
}

function getCurrentISTDate() {
    $dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    return $dt->format('Y-m-d');
}

function getFutureISTTimestamp($hours) {
    $dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $dt->modify("+{$hours} hours");
    return $dt->format('Y-m-d H:i:s');
}

function formatISTTimestamp($timestamp, $format = 'Y-m-d H:i:s') {
    try {
        $dt = new DateTime($timestamp, new DateTimeZone('Asia/Kolkata'));
        return $dt->format($format);
    } catch (Exception $e) {
        return $timestamp;
    }
}

// ==================== API FUNCTIONS ====================

function getTowers($conn) {
    try {
        writeLog("getTowers called");
        
        $stmt = $conn->prepare("
            SELECT id, tower_name, society_name 
            FROM locations 
            WHERE status = 'active'
            ORDER BY tower_name ASC
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $towers = [];
        while ($row = $result->fetch_assoc()) {
            $towers[] = $row;
        }
        
        $stmt->close();
        
        writeLog("Towers found: " . count($towers));
        respond(['success' => true, 'towers' => $towers]);
        
    } catch (Exception $e) {
        writeLog("ERROR in getTowers: " . $e->getMessage());
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function getFlats($conn) {
    try {
        $location_id = intval($_GET['location_id'] ?? 1);
        writeLog("getFlats called for location_id: {$location_id}");
        
        $stmt = $conn->prepare("
            SELECT DISTINCT flat_number 
            FROM residents 
            WHERE location_id = ? AND status = 'active'
            ORDER BY flat_number ASC
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('i', $location_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $flats = [];
        while ($row = $result->fetch_assoc()) {
            $flats[] = $row['flat_number'];
        }
        
        $stmt->close();
        
        writeLog("Flats found: " . count($flats));
        respond(['success' => true, 'flats' => $flats]);
        
    } catch (Exception $e) {
        writeLog("ERROR in getFlats: " . $e->getMessage());
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function verifyFlat($conn) {
    try {
        $flat_number = trim($_POST['flat_number'] ?? '');
        $location_id = intval($_POST['location_id'] ?? 1);
        
        writeLog("verifyFlat: flat={$flat_number}, location={$location_id}");
        
        if (empty($flat_number)) {
            respond(['success' => false, 'message' => 'Flat number required']);
        }
        
        $stmt = $conn->prepare("
            SELECT id, flat_number, full_name 
            FROM residents 
            WHERE location_id = ? AND flat_number = ? AND status = 'active'
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('is', $location_id, $flat_number);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($resident) {
            respond([
                'success' => true,
                'message' => 'Flat verified',
                'flat_exists' => true
            ]);
        } else {
            respond([
                'success' => false,
                'message' => 'Flat not registered',
                'flat_exists' => false
            ]);
        }
        
    } catch (Exception $e) {
        writeLog("ERROR in verifyFlat: " . $e->getMessage());
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function getResidentName($conn) {
    try {
        $location_id = intval($_POST['location_id'] ?? $_GET['location_id'] ?? 0);
        $flat_number = trim($_POST['flat_number'] ?? $_GET['flat_number'] ?? '');
        
        writeLog("getResidentName: location_id={$location_id}, flat={$flat_number}");
        
        if (empty($flat_number) || $location_id <= 0) {
            respond(['success' => false, 'message' => 'Invalid parameters']);
        }
        
        $stmt = $conn->prepare("
            SELECT full_name, mobile, email 
            FROM residents 
            WHERE location_id = ? AND flat_number = ? AND status = 'active' 
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('is', $location_id, $flat_number);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($resident) {
            respond([
                'success' => true,
                'full_name' => $resident['full_name'],
                'mobile' => $resident['mobile'],
                'email' => $resident['email']
            ]);
        } else {
            respond(['success' => false, 'message' => 'Resident not found']);
        }
        
    } catch (Exception $e) {
        writeLog("ERROR in getResidentName: " . $e->getMessage());
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function depositPackage($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'Invalid request method'], 405);
    }

    $flat_number = trim($_POST['flat_number'] ?? '');
    $package_size = trim($_POST['package_size'] ?? '');
    $tracking_number = trim($_POST['tracking_number'] ?? '');
    $delivery_company = trim($_POST['delivery_company'] ?? 'Other');
    $location_id = intval($_POST['location_id'] ?? 1);
    
    writeLog("Deposit: flat={$flat_number}, size={$package_size}, location={$location_id}, company={$delivery_company}");
    
    // Validation
    if (empty($flat_number)) {
        respond(['success' => false, 'message' => 'Flat number is required']);
    }
    
    if (empty($package_size)) {
        respond(['success' => false, 'message' => 'Package size is required']);
    }
    
    if (!in_array($package_size, ['small', 'medium', 'large'])) {
        respond(['success' => false, 'message' => 'Invalid package size. Must be: small, medium, or large']);
    }

    try {
        $conn->begin_transaction();
        
        // 1. Get resident
        $stmt = $conn->prepare("
            SELECT id, flat_number, full_name, mobile, email 
            FROM residents 
            WHERE location_id = ? AND flat_number = ? AND status = 'active'
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('is', $location_id, $flat_number);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$resident) {
            $conn->rollback();
            respond(['success' => false, 'message' => "Apartment {$flat_number} is not registered"]);
        }
        
        writeLog("Resident found: {$resident['full_name']} (ID: {$resident['id']})");
        
        // 2. Find available locker
        $stmt = $conn->prepare("
            SELECT l.id, l.locker_number 
            FROM lockers l
            WHERE l.location_id = ? 
            AND l.size = ? 
            AND l.status = 'available'
            AND l.id NOT IN (
                SELECT locker_id 
                FROM deliveries 
                WHERE location_id = ? 
                AND status IN ('deposited', 'deposit_requested')
            )
            ORDER BY l.locker_number
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('isi', $location_id, $package_size, $location_id);
        $stmt->execute();
        $locker = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$locker) {
            $conn->rollback();
            respond(['success' => false, 'message' => "No available {$package_size} locker found"]);
        }
        
        writeLog("Locker found: {$locker['locker_number']} (ID: {$locker['id']})");
        
        // 3. Generate OTP and timestamps
        $otp = generateOTP(); // Use function from config.php
        $otp_expiry = getFutureISTTimestamp(48);
        $deposited_at = getCurrentISTTimestamp();
        $delivery_date = getCurrentISTDate();
        
        // 4. Get tower name
        $stmt = $conn->prepare("SELECT tower_name, society_name FROM locations WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param('i', $location_id);
        $stmt->execute();
        $location_result = $stmt->get_result()->fetch_assoc();
        $tower_name = $location_result['tower_name'] ?? 'Unknown';
        $society_name = $location_result['society_name'] ?? 'Unknown';
        $stmt->close();
        
        writeLog("Tower: {$tower_name}, OTP: {$otp}");
        
        // 5. Insert delivery record
        $stmt = $conn->prepare("
            INSERT INTO deliveries 
            (location_id, tower_name, locker_id, resident_id, tracking_number, package_size, 
             otp, otp_expires_at, deposited_at, delivery_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'deposited')
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('isiissssss',
            $location_id, $tower_name, $locker['id'], $resident['id'],
            $tracking_number, $package_size, $otp, $otp_expiry,
            $deposited_at, $delivery_date
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $delivery_id = $conn->insert_id;
        $stmt->close();
        
        writeLog("Delivery created: ID={$delivery_id}");
        
        // 6. Update locker status
        $stmt = $conn->prepare("
            UPDATE lockers 
            SET status = 'occupied', last_opened_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('i', $locker['id']);
        $stmt->execute();
        $stmt->close();
        
        writeLog("Locker {$locker['id']} marked as occupied");
        
        // 7. Create hardware_sync entry
        $stmt = $conn->prepare("
            INSERT INTO hardware_sync 
            (delivery_id, locker_id, locker_number, tower_name, resident_mobile, 
             otp_entered, action, deposit_requested_at, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 'deposit_requested', NOW(), 1)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('iissss',
            $delivery_id, $locker['id'], $locker['locker_number'],
            $tower_name, $resident['mobile'], $otp
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $stmt->close();
        writeLog("Hardware sync created");
        
        // 8. Send email notification
        $email_result = sendDepositEmail(
            $resident['email'], $resident['full_name'], $otp,
            $locker['locker_number'], $flat_number, $resident['mobile'],
            $delivery_company, $otp_expiry, $tower_name, $society_name
        );
        
        // 9. Update notification status
        $stmt = $conn->prepare("UPDATE deliveries SET email_sent = ? WHERE id = ?");
        $email_flag = $email_result['success'] ? 1 : 0;
        $stmt->bind_param('ii', $email_flag, $delivery_id);
        $stmt->execute();
        $stmt->close();
        
        // 10. Log email notification
        if ($email_result['success']) {
            $stmt = $conn->prepare("
                INSERT INTO sms_log (delivery_id, recipient_mobile, message_text, message_type, status)
                VALUES (?, ?, ?, 'delivery_notification', 'sent')
            ");
            $msg = "Email sent to: {$resident['email']}";
            $stmt->bind_param('iss', $delivery_id, $resident['email'], $msg);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        writeLog("Transaction committed successfully");
        
        respond([
            'success' => true,
            'message' => 'Package deposited successfully!',
            'data' => [
                'delivery_id' => $delivery_id,
                'locker_number' => $locker['locker_number'],
                'tower_name' => $tower_name,
                'society_name' => $society_name,
                'flat_number' => $flat_number,
                'resident_name' => $resident['full_name'],
                'package_size' => $package_size,
                'tracking_number' => $tracking_number ?: 'N/A',
                'delivery_company' => $delivery_company,
                'otp' => $otp,
                'status' => 'deposited',
                'email_sent' => $email_result['success']
            ]
        ]);
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        writeLog("ERROR in depositPackage: " . $e->getMessage());
        respond(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

function testEmail() {
    $email = $_GET['email'] ?? 'test@example.com';
    
    $result = sendEmailFixed(
        $email, 'Test User',
        'Test Email from Keyless Cube',
        '<h1>Test Email</h1><p>If you receive this, email is working!</p>'
    );
    
    respond([
        'success' => $result['success'],
        'message' => $result['message'],
        'email' => $email
    ]);
}

// ==================== NOTIFICATION FUNCTIONS ====================

function sendDepositEmail($email, $name, $otp, $locker, $flat, $mobile, $company, $otp_expiry, $tower, $society) {
    if (empty($email)) {
        return ['success' => false, 'message' => 'Email not provided'];
    }
    
    $expiry = formatISTTimestamp($otp_expiry, 'd M Y, g:i A');
    
    $subject = "Package Delivered - Locker {$locker}";
    $body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><style>
        body{font-family:Arial;color:#333;background:#f4f4f4;margin:0;padding:0}
        .container{max-width:600px;margin:20px auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1)}
        .header{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:32px;text-align:center}
        .content{padding:32px}
        .otp-box{background:#f8f9fa;padding:24px;border-radius:8px;margin:24px 0;text-align:center;border:3px dashed #1a1a2e}
        .otp-code{font-size:48px;font-weight:bold;color:#1a1a2e;letter-spacing:12px}
        .info-table{width:100%;margin:20px 0}
        .info-table td{padding:14px;border-bottom:1px solid #e5e7eb}
        .info-table td:first-child{font-weight:600;color:#666;width:40%}
        .footer{text-align:center;padding:20px;background:#f8f9fa;color:#999;font-size:13px}
    </style></head>
    <body>
        <div class='container'>
            <div class='header'><h1>📦 Package Delivered!</h1></div>
            <div class='content'>
                <p>Dear <strong>{$name}</strong>,</p>
                <p>Your package from <strong>{$company}</strong> has been delivered to locker <strong>{$locker}</strong>.</p>
                <div class='otp-box'>
                    <p style='margin:0;font-size:14px;color:#666'>Your OTP Code</p>
                    <div class='otp-code'>{$otp}</div>
                    <p style='margin:0;font-size:12px;color:#999'>Valid until {$expiry}</p>
                </div>
                <table class='info-table'>
                    <tr><td>Locker Number</td><td><strong>{$locker}</strong></td></tr>
                    <tr><td>Tower</td><td><strong>{$tower}</strong></td></tr>
                    <tr><td>Apartment</td><td><strong>{$flat}</strong></td></tr>
                    <tr><td>Delivered By</td><td><strong>{$company}</strong></td></tr>
                    <tr><td>Mobile</td><td><strong>{$mobile}</strong></td></tr>
                </table>
                <div style='background:#f8f9fa;padding:20px;border-radius:8px;margin:20px 0;border-left:4px solid #1a1a2e'>
                    <h3 style='margin-top:0'>Collection Steps:</h3>
                    <ol style='padding-left:20px'>
                        <li>Visit the Keyless Cube kiosk</li>
                        <li>Tap 'Collect Package'</li>
                        <li>Select apartment: <strong>{$flat}</strong></li>
                        <li>Enter name: <strong>{$name}</strong></li>
                        <li>Enter mobile: <strong>{$mobile}</strong></li>
                        <li>Enter OTP: <strong>{$otp}</strong></li>
                        <li>Collect from locker <strong>{$locker}</strong></li>
                    </ol>
                </div>
            </div>
            <div class='footer'><p><strong>KEYLESS CUBE</strong></p><p>Available 24/7 • Contactless & Secure</p></div>
        </div>
    </body>
    </html>";
    
    return sendEmailFixed($email, $name, $subject, $body);
}

function sendEmailFixed($to, $name, $subject, $body) {
    $result = ['success' => false, 'message' => ''];
    
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result['message'] = 'Invalid email';
        writeLog("Invalid email: {$to}");
        return $result;
    }
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Keyless Cube <noreply@vistech.co.in>\r\n";
    $headers .= "Reply-To: noreply@vistech.co.in\r\n";
    
    $sent = @mail($to, $subject, $body, $headers);
    
    $result['success'] = $sent;
    $result['message'] = $sent ? 'Email sent' : 'Email failed';
    
    writeLog("Email " . ($sent ? 'sent' : 'failed') . " to: {$to}");
    
    return $result;
}
?>