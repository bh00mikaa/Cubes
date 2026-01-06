<?php
/**
 * admin_auth.php
 */
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_error.log');
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database configuration
require_once 'config.php';

// Convert mysqli to PDO using the correct constants from config.php
try {
    $pdo = new PDO(
        "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    $pdo->exec("SET time_zone = '+05:30'");
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database connection failed'
    ]);
    exit;
}

// Start session
session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'check_admin':
        checkAdmin($pdo);
        break;
    case 'login':
        loginAdmin($pdo);
        break;
    case 'send_reset_otp':
        sendResetOTP($pdo);
        break;
    case 'reset_password_with_otp':
        resetPasswordWithOTP($pdo);
        break;
    case 'logout':
        logoutAdmin();
        break;
    case 'check_session':
        checkSession($pdo);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

/**
 * Check if admin exists
 */
function checkAdmin($pdo) {
    $username = trim($_POST['username'] ?? '');
    
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, email, status FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin) {
            if ($admin['status'] !== 'active') {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Your account has been deactivated. Please contact the administrator.'
                ]);
                return;
            }
            echo json_encode([
                'success' => true, 
                'admin_exists' => true,
                'email' => $admin['email']
            ]);
        } else {
            echo json_encode([
                'success' => true, 
                'admin_exists' => false
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error'
        ]);
    }
}

/**
 * Login admin
 */
function loginAdmin($pdo) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, username, password_hash, full_name, email, mobile, role, status, location_id 
            FROM admins 
            WHERE username = ?
        ");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
            return;
        }
        
        if ($admin['status'] !== 'active') {
            echo json_encode(['success' => false, 'message' => 'Your account has been deactivated']);
            return;
        }
        
        if (password_verify($password, $admin['password_hash'])) {
            // Update last login
            $stmt = $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?");
            $stmt->execute([$admin['id']]);
            
            // Set session
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['admin_location_id'] = $admin['location_id'];
            $_SESSION['logged_in'] = true;
            
            echo json_encode([
                'success' => true, 
                'message' => 'Login successful',
                'admin' => [
                    'id' => $admin['id'],
                    'username' => $admin['username'],
                    'full_name' => $admin['full_name'],
                    'role' => $admin['role'],
                    'location_id' => $admin['location_id']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error'
        ]);
    }
}

/**
 * Send reset OTP
 */
function sendResetOTP($pdo) {
    $username = trim($_POST['username'] ?? '');
    
    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, email, full_name FROM admins WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            echo json_encode(['success' => false, 'message' => 'Admin account not found']);
            return;
        }
        
        // Generate 6-digit OTP
        $otp = sprintf("%06d", mt_rand(0, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        // Create password_resets table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(10) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_email (email),
                INDEX idx_token (token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Store OTP in database
        $stmt = $pdo->prepare("
            INSERT INTO password_resets (email, token, expires_at, created_at) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                token = VALUES(token), 
                expires_at = VALUES(expires_at), 
                created_at = NOW()
        ");
        $stmt->execute([$admin['email'], $otp, $expires]);
        
        // Send email
        $to = $admin['email'];
        $subject = "Password Reset Code - Keyless Cubes Admin";
        
        $message = "Hello {$admin['full_name']},\n\n";
        $message .= "Your password reset code is: $otp\n\n";
        $message .= "This code will expire in 15 minutes.\n\n";
        $message .= "If you didn't request this, please ignore this email.\n\n";
        $message .= "Best regards,\nKeyless Cubes Team";
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: Keyless Cube <noreply@vistech.co.in>\r\n";
        $headers .= "Reply-To: noreply@vistech.co.in\r\n";
        
        if (mail($to, $subject, $message, $headers)) {
            echo json_encode([
                'success' => true, 
                'message' => 'Verification code sent to your registered email'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Failed to send verification code. Please try again.'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error'
        ]);
    }
}

/**
 * Reset password with OTP
 */
function resetPasswordWithOTP($pdo) {
    $username = trim($_POST['username'] ?? '');
    $otp = $_POST['otp'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    
    if (empty($username) || empty($otp) || empty($newPassword)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        return;
    }
    
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        return;
    }
    
    try {
        // Get admin email
        $stmt = $pdo->prepare("SELECT email FROM admins WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            echo json_encode(['success' => false, 'message' => 'Admin account not found']);
            return;
        }
        
        // Verify OTP
        $stmt = $pdo->prepare("
            SELECT * FROM password_resets 
            WHERE email = ? AND token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$admin['email'], $otp]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired verification code']);
            return;
        }
        
        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE username = ?");
        
        if ($stmt->execute([$hashedPassword, $username])) {
            // Delete used OTP
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt->execute([$admin['email']]);
            
            echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to reset password']);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error'
        ]);
    }
}

/**
 * Logout admin
 */
function logoutAdmin() {
    session_start();
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
}

/**
 * Check session
 */
function checkSession($pdo) {
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['admin_id'])) {
        try {
            // Verify admin still exists and is active
            $stmt = $pdo->prepare("
                SELECT id, username, full_name, role
                FROM admins 
                WHERE id = ? AND status = 'active'
            ");
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch();
            
            if ($admin) {
                echo json_encode([
                    'success' => true, 
                    'logged_in' => true,
                    'admin' => $admin
                ]);
            } else {
                session_destroy();
                echo json_encode(['success' => false, 'logged_in' => false]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'logged_in' => false]);
        }
    } else {
        echo json_encode(['success' => false, 'logged_in' => false]);
    }
}
?>