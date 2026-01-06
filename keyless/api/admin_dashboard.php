<?php
/**
 * admin_dashboard.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Define log file path in logs folder (one level up from api folder)
define('LOG_FILE', dirname(__DIR__) . '/logs/admin_debug.log');

// Ensure logs directory exists
$logs_dir = dirname(__DIR__) . '/logs';
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}

ini_set('error_log', LOG_FILE);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

$action = $_GET['action'] ?? 'dashboard_stats';

writeLog("Admin action: {$action}");

switch ($action) {
    case 'dashboard_stats':
        getDashboardStats($conn);
        break;
    
    case 'active_deliveries':
        getActiveDeliveries($conn);
        break;
    
    case 'locker_status':
        getLockerStatus($conn);
        break;
    
    case 'recent_collections':
        getRecentCollections($conn);
        break;
    
    case 'analytics':
        getAnalytics($conn);
        break;
    
    default:
        jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

/**
 * Helper function to write logs
 */
function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(LOG_FILE, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Get dashboard statistics
function getDashboardStats($conn) {
    try {
        $result = $conn->query("
            SELECT 
                (SELECT COUNT(*) FROM deliveries WHERE DATE(deposited_at) = CURDATE()) as deliveries_today,
                (SELECT COUNT(*) FROM deliveries WHERE status = 'deposited') as active_deliveries,
                (SELECT COUNT(*) FROM deliveries WHERE DATE(collected_at) = CURDATE() AND status = 'collected') as collected_today,
                (SELECT COUNT(*) FROM lockers WHERE status = 'available') as available_lockers,
                (SELECT COUNT(*) FROM lockers WHERE status = 'occupied') as occupied_lockers
        ");
        
        $stats = $result->fetch_assoc();
        
        writeLog("Dashboard stats retrieved successfully");
        jsonResponse(['success' => true, 'stats' => $stats]);
    } catch (Exception $e) {
        writeLog("Error in getDashboardStats: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

//Get all active deliveries 
function getActiveDeliveries($conn) {
    try {
        // Query deliveries that are actually deposited (not collected)
        $result = $conn->query("
            SELECT 
                d.id, d.otp, d.deposited_at, d.package_size,
                hs.locker_number, hs.tower_name, hs.resident_mobile as mobile,
                r.flat_number, r.full_name,
                TIMESTAMPDIFF(HOUR, d.deposited_at, NOW()) as hours_in_locker
            FROM hardware_sync hs
            JOIN deliveries d ON hs.delivery_id = d.id
            JOIN residents r ON d.resident_id = r.id
            WHERE hs.action = 'deposited' 
        ");
        
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $deliveries = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure hours_in_locker is at least 0
            $row['hours_in_locker'] = max(0, intval($row['hours_in_locker']));
            $deliveries[] = $row;
        }
        
        writeLog("Active deliveries retrieved: " . count($deliveries) . " rows");
        jsonResponse(['success' => true, 'deliveries' => $deliveries]);
    } catch (Exception $e) {
        writeLog("Error in getActiveDeliveries: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

/**
 * Get locker status overview
 */
function getLockerStatus($conn) {
    try {
        $result = $conn->query("
            SELECT 
                l.id,
                l.location_id,
                loc.society_name,
                loc.tower_name,
                l.locker_number,
                l.size,
                l.status,
                l.last_opened_at,
                l.last_closed_at,
                d.id as delivery_id,
                d.deposited_at,
                r.flat_number,
                r.full_name
            FROM lockers l
            JOIN locations loc ON l.location_id = loc.id
            LEFT JOIN deliveries d ON l.id = d.locker_id AND d.status = 'deposited'
            LEFT JOIN residents r ON d.resident_id = r.id
            ORDER BY l.location_id, l.locker_number
        ");
        
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $lockers = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure location_id is an integer
            $row['location_id'] = (int)$row['location_id'];
            $lockers[] = $row;
        }
        
        writeLog("Locker status retrieved: " . count($lockers) . " lockers");
        jsonResponse(['success' => true, 'lockers' => $lockers]);
    } catch (Exception $e) {
        writeLog("Error in getLockerStatus: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

/**
 * Get recent collections
 */
function getRecentCollections($conn) {
    try {
        $limit = intval($_GET['limit'] ?? 20);
        
        $stmt = $conn->prepare("
            SELECT 
                d.id,
                d.tracking_number,
                d.deposited_at,
                d.collected_at,
                l.locker_number,
                r.flat_number,
                r.full_name,
                r.mobile
            FROM deliveries d
            JOIN lockers l ON d.locker_id = l.id
            JOIN residents r ON d.resident_id = r.id
            WHERE d.status = 'collected'
            ORDER BY d.collected_at DESC
            LIMIT ?
        ");
        
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $collections = [];
        while ($row = $result->fetch_assoc()) {
            $collections[] = $row;
        }
        
        writeLog("Recent collections retrieved: " . count($collections));
        jsonResponse(['success' => true, 'collections' => $collections]);
    } catch (Exception $e) {
        writeLog("Error in getRecentCollections: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

/**
 * Get analytics data
 */
function getAnalytics($conn) {
    try {
        $analytics = [];
        
        // Deliveries per day (last 7 days)
        $result = $conn->query("
            SELECT 
                DATE(deposited_at) as date,
                COUNT(*) as count
            FROM deliveries
            WHERE deposited_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(deposited_at)
            ORDER BY date DESC
        ");
        
        $analytics['daily_deliveries'] = [];
        while ($row = $result->fetch_assoc()) {
            $analytics['daily_deliveries'][] = $row;
        }
        
        // Peak delivery hours
        $result = $conn->query("
            SELECT 
                HOUR(deposited_at) as hour,
                COUNT(*) as count
            FROM deliveries
            WHERE deposited_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY HOUR(deposited_at)
            ORDER BY count DESC
            LIMIT 5
        ");
        
        $analytics['peak_hours'] = [];
        while ($row = $result->fetch_assoc()) {
            $analytics['peak_hours'][] = $row;
        }
        
        writeLog("Analytics retrieved successfully");
        jsonResponse(['success' => true, 'analytics' => $analytics]);
    } catch (Exception $e) {
        writeLog("Error in getAnalytics: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}
?>