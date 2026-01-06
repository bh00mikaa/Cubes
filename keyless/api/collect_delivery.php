<?php
//collect_delivery.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

define('LOG_FILE', dirname(__DIR__) . '/logs/collect_debug.log');

$logs_dir = dirname(__DIR__) . '/logs';
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0755, true);
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

$action = $_GET['action'] ?? $_POST['action'] ?? 'collect';

writeLog("Action: {$action}");

switch ($action) {
    case 'get_active_flats':
        getActiveFlats($conn);
        break;
    
    case 'collect':
        collectPackage($conn);
        break;
    
    default:
        respond(['success' => false, 'message' => 'Invalid action'], 400);
}

function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

function getActiveFlats($conn) {
    try {
        $location_id = intval($_GET['location_id'] ?? 0);
        $tower_name = trim($_GET['tower_name'] ?? '');

        writeLog("getActiveFlats called for Location: $location_id, Tower: $tower_name");

        $query = "
            SELECT 
                r.flat_number, 
                l.tower_name, 
                d.location_id,
                r.full_name,   
                r.mobile,
                COUNT(d.id) as delivery_count
            FROM deliveries d
            JOIN residents r ON d.resident_id = r.id
            JOIN locations l ON d.location_id = l.id
            WHERE d.status = 'deposited'
            AND r.status = 'active'
        ";

        $params = [];
        $types = "";

        if ($location_id > 0) {
            $query .= " AND d.location_id = ? ";
            $types .= "i";
            $params[] = $location_id;
        }

        if (!empty($tower_name)) {
            $query .= " AND l.tower_name = ? ";
            $types .= "s";
            $params[] = $tower_name;
        }

        $query .= " GROUP BY r.flat_number, l.tower_name, d.location_id, r.full_name, r.mobile
                    ORDER BY r.flat_number ASC";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Query Prepare Failed: " . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $flats = [];
        while ($row = $result->fetch_assoc()) {
            $flats[] = $row;
        }

        $stmt->close();

        respond([
            'success' => true,
            'flats' => $flats,
            'count' => count($flats)
        ]);

    } catch (Exception $e) {
        writeLog("Error in getActiveFlats: " . $e->getMessage());
        respond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

function collectPackage($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'Invalid request method'], 405);
    }

    $mobile = trim($_POST['mobile'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $flat_number = trim($_POST['flat_number'] ?? '');
    $resident_name = trim($_POST['resident_name'] ?? '');
    $location_id = intval($_POST['location_id'] ?? 1);
    
    writeLog("Collect: mobile={$mobile}, flat={$flat_number}, otp={$otp}");
    
    if (empty($mobile) || empty($otp) || empty($flat_number) || empty($resident_name)) {
        respond(['success' => false, 'message' => 'All fields required']);
    }
    
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        respond(['success' => false, 'message' => 'Invalid mobile']);
    }
    
    if (strlen($otp) !== 6 || !ctype_digit($otp)) {
        respond(['success' => false, 'message' => 'OTP must be 6 digits']);
    }

    try {
        $conn->begin_transaction();
        
        $stmt = $conn->prepare("
            SELECT id, full_name, email 
            FROM residents 
            WHERE mobile = ? AND flat_number = ? AND location_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('ssi', $mobile, $flat_number, $location_id);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$resident) {
            $conn->rollback();
            respond(['success' => false, 'message' => 'Resident not found']);
        }
        
        if (strcasecmp(trim($resident['full_name']), trim($resident_name)) !== 0) {
            $conn->rollback();
            respond(['success' => false, 'message' => 'Incorrect name: ' . $resident['full_name']]);
        }
        
        $stmt = $conn->prepare("
            SELECT d.id as delivery_id, d.locker_id, d.otp_expires_at, d.otp_attempts,
                   d.package_size, l.locker_number, loc.tower_name, loc.society_name,
                   r.flat_number, r.full_name, r.email
            FROM deliveries d
            JOIN lockers l ON d.locker_id = l.id
            JOIN locations loc ON d.location_id = loc.id
            JOIN residents r ON d.resident_id = r.id
            WHERE r.id = ? AND d.location_id = ? AND d.status = 'deposited' AND d.otp = ?
            LIMIT 1
        ");
        $stmt->bind_param('iis', $resident['id'], $location_id, $otp);
        $stmt->execute();
        $delivery = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$delivery) {
            $stmt = $conn->prepare("
                UPDATE deliveries 
                SET otp_attempts = otp_attempts + 1 
                WHERE resident_id = ? AND location_id = ? AND status = 'deposited'
            ");
            $stmt->bind_param('ii', $resident['id'], $location_id);
            $stmt->execute();
            $stmt->close();
            
            // Now check the updated attempt count
            $stmt = $conn->prepare("
                SELECT otp_attempts 
                FROM deliveries 
                WHERE resident_id = ? AND location_id = ? AND status = 'deposited'
                ORDER BY otp_attempts DESC
                LIMIT 1
            ");
            $stmt->bind_param('ii', $resident['id'], $location_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $conn->rollback();
            
            $attempts = $result ? $result['otp_attempts'] : 0;
            
            if ($attempts >= 3) {
                respond([
                    'success' => false,
                    'message' => 'Maximum OTP attempts (3) exceeded. Please contact support or wait for assistance.'
                ]);
            }
            
            respond([
                'success' => false,
                'message' => "Invalid OTP. Attempt {$attempts} of 3."
            ]);
        }
        
        // Update hardware_sync
        $stmt = $conn->prepare("
            UPDATE hardware_sync 
            SET action = 'collect_requested', collect_requested_at = NOW(), 
                otp_entered = ?, is_active = 0
            WHERE delivery_id = ? AND is_active = 1
        ");
        $stmt->bind_param('si', $otp, $delivery['delivery_id']);
        $stmt->execute();
        if ($stmt->affected_rows == 0) {
            $stmt->close();
            $stmt = $conn->prepare("
                INSERT INTO hardware_sync 
                (delivery_id, locker_id, locker_number, tower_name, resident_mobile, otp_entered, 
                 action, collect_requested_at, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 'collect_requested', NOW(), 0)
            ");
            $stmt->bind_param('iissss', 
                $delivery['delivery_id'], $delivery['locker_id'], $delivery['locker_number'],
                $delivery['tower_name'], $mobile, $otp
            );
            $stmt->execute();
        }
        $stmt->close();
        
        // Update delivery
        $stmt = $conn->prepare("UPDATE deliveries SET status = 'collected', collected_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $delivery['delivery_id']);
        $stmt->execute();
        $stmt->close();
        
        // Update locker
        $stmt = $conn->prepare("UPDATE lockers SET status = 'available', last_opened_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $delivery['locker_id']);
        $stmt->execute();
        $stmt->close();
        
        // Log access
        $stmt = $conn->prepare("
            INSERT INTO locker_access_log (delivery_id, locker_id, action, actor_type, actor_details, otp_entered, success)
            VALUES (?, ?, 'collect_requested', 'resident', ?, ?, 1)
        ");
        $details = $delivery['full_name'] . ' - ' . $delivery['flat_number'];
        $stmt->bind_param('iiss', $delivery['delivery_id'], $delivery['locker_id'], $details, $otp);
        $stmt->execute();
        $stmt->close();
        
        // Send confirmation email
        sendCollectionEmail($delivery['email'], $delivery['full_name'], $delivery['locker_number'],
                          $delivery['flat_number'], $delivery['tower_name'], $delivery['society_name'],
                          $delivery['package_size']);
        
        // Check remaining
        $stmt = $conn->prepare("
            SELECT COUNT(*) as remaining 
            FROM deliveries 
            WHERE resident_id = ? AND location_id = ? AND status = 'deposited' AND id != ?
        ");
        $stmt->bind_param('iii', $resident['id'], $location_id, $delivery['delivery_id']);
        $stmt->execute();
        $rem = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $conn->commit();
        
        $msg = "Proceed to locker {$delivery['locker_number']}";
        if ($rem['remaining'] > 0) {
            $msg .= ". You have {$rem['remaining']} more package(s) waiting.";
        }
        
        respond([
            'success' => true,
            'message' => 'Package collected successfully!',
            'data' => [
                'locker_number' => $delivery['locker_number'],
                'location_name' => $delivery['tower_name'],
                'remaining_packages' => $rem['remaining'],
                'instruction' => $msg
            ]
        ]);
        
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        writeLog("ERROR: " . $e->getMessage());
        respond(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

function sendOTPEmail($email, $name, $otp, $locker, $flat, $tower, $society, $size, $is_regen = false) {
    if (empty($email)) return;
    
    $subject = $is_regen ? "New OTP Generated - Locker {$locker}" : "Package Delivered - Locker {$locker}";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><style>
        body{font-family:Arial;color:#333;background:#f4f4f4;margin:0;padding:0}
        .container{max-width:600px;margin:20px auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1)}
        .header{background:linear-gradient(135deg,#3498db,#2980b9);color:#fff;padding:32px;text-align:center}
        .content{padding:32px}
        .otp-box{background:#f8f9fa;padding:24px;border-radius:8px;margin:24px 0;text-align:center;border:2px dashed #3498db}
        .otp-code{font-size:36px;font-weight:bold;color:#3498db;letter-spacing:8px}
        .info-box{background:#f8f9fa;padding:20px;border-radius:8px;margin:20px 0;border-left:4px solid #3498db}
        .footer{text-align:center;padding:20px;background:#f8f9fa;color:#999;font-size:13px}
    </style></head>
    <body>
        <div class='container'>
            <div class='header'><h1>" . ($is_regen ? "🔄 New OTP Generated" : "📦 Package Delivered") . "</h1></div>
            <div class='content'>
                <p>Dear <strong>{$name}</strong>,</p>
                <p>" . ($is_regen ? "A new OTP has been generated" : "Your package has been delivered") . " to locker <strong>{$locker}</strong>.</p>
                <div class='otp-box'>
                    <p style='margin:0;font-size:14px;color:#666'>Your OTP Code</p>
                    <div class='otp-code'>{$otp}</div>
                    <p style='margin:0;font-size:12px;color:#999'>Valid until {$expiry}</p>
                </div>
                <div class='info-box'>
                    <p><strong>Locker:</strong> {$locker}</p>
                    <p><strong>Apartment:</strong> {$flat}</p>
                    <p><strong>Location:</strong> {$tower}, {$society}</p>
                    <p><strong>Size:</strong> {$size}</p>
                </div>
            </div>
            <div class='footer'><p><strong>KEYLESS CUBE</strong></p></div>
        </div>
    </body>
    </html>";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Keyless Cube <noreply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    
    @mail($email, $subject, $body, $headers);
}

function sendCollectionEmail($email, $name, $locker, $flat, $tower, $society, $size) {
    if (empty($email)) return;
    
    $time = date('d M Y, g:i A');
    $body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><style>
        body{font-family:Arial;color:#333;background:#f4f4f4;margin:0;padding:0}
        .container{max-width:600px;margin:20px auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1)}
        .header{background:linear-gradient(135deg,#27ae60,#2ecc71);color:#fff;padding:32px;text-align:center}
        .content{padding:32px}
        .info-box{background:#f8f9fa;padding:20px;border-radius:8px;margin:20px 0;border-left:4px solid #27ae60}
        .footer{text-align:center;padding:20px;background:#f8f9fa;color:#999;font-size:13px}
    </style></head>
    <body>
        <div class='container'>
            <div class='header'><h1>✓ Collection Confirmed</h1></div>
            <div class='content'>
                <p>Dear <strong>{$name}</strong>,</p>
                <p>Your request to collect from locker <strong>{$locker}</strong> is confirmed.</p>
                <div class='info-box'>
                    <p><strong>Locker:</strong> {$locker}</p>
                    <p><strong>Apartment:</strong> {$flat}</p>
                    <p><strong>Location:</strong> {$tower}, {$society}</p>
                    <p><strong>Time:</strong> {$time}</p>
                </div>
                <p style='color:#27ae60;font-weight:bold'>✓ Proceed to the locker to collect your package.</p>
            </div>
            <div class='footer'><p><strong>KEYLESS CUBE</strong></p></div>
        </div>
    </body>
    </html>";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Keyless Cube <noreply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    
    @mail($email, "Package Collection - Locker {$locker}", $body, $headers);
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>