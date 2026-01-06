<?php
/**
 * RESIDENTIAL SMART LOCKER - DATABASE CONFIGURATION
 * For Bluehost MySQL Database
 */
// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production

// Set timezone FIRST (before any operations)
date_default_timezone_set('Asia/Kolkata');

// Database credentials 
define('DB_SERVER');
define('DB_USERNAME');
define('DB_PASSWORD');
define('DB_NAME');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    error_log("Database Connection Failed: " . $conn->connect_error);
    
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed. Please contact support.'
        ]);
        exit;
    }
    
    die("Connection failed. Please try again later.");
}

// CRITICAL: Set charset and timezone immediately after connection
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+05:30'");

// System settings
define('OTP_VALIDITY_HOURS', 48);
define('MAX_OTP_ATTEMPTS', 3);
define('SYSTEM_EMAIL', 'panels@authentic.co.in');
define('SYSTEM_PHONE', '124422279097');

/**
 * Get system setting from database
 */
function getSetting($key, $default = '') {
    global $conn;
    
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return $row['setting_value'];
    }
    
    $stmt->close();
    return $default;
}

/**
 * Update system setting
 */
function updateSetting($key, $value) {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = ?
    ");
    $stmt->bind_param('sss', $key, $value, $value);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Generate 6-digit OTP
 */
function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Sanitize input
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate mobile number (Indian)
 */
function isValidMobile($mobile) {
    return preg_match('/^[6-9]\d{9}$/', $mobile);
}

/**
 * Format mobile number for display
 */
function formatMobile($mobile) {
    if (strlen($mobile) === 10) {
        return substr($mobile, 0, 2) . 'XXXXXX' . substr($mobile, -2);
    }
    return $mobile;
}

/**
 * Log activity
 */
function logActivity($message, $level = 'INFO') {
    if (basename(__DIR__) === 'api') {
        $log_dir = dirname(__DIR__) . '/logs';
    } else {
        $log_dir = __DIR__ . '/logs';
    }
    
    $log_file = $log_dir . '/system_' . date('Y-m-d') . '.log';
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    error_log($log_entry, 3, $log_file);
}

/**
 * Send JSON response
 */
function jsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>
