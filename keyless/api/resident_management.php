<?php
/**
 * resident_management.php - Resident Management API
 * Handles all CRUD operations for residents
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/resident_debug.log');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_residents';

file_put_contents(__DIR__ . '/logs/resident_debug.log', date('Y-m-d H:i:s') . " - Action: {$action}\n", FILE_APPEND);

switch ($action) {
    case 'get_residents':
        getResidents($conn);
        break;
    
    case 'get_resident_by_flat':
        getResidentByFlat($conn);
        break;
    
    case 'add_resident':
        addResident($conn);
        break;
    
    case 'update_resident':
        updateResident($conn);
        break;
    
    case 'delete_resident':
        deleteResident($conn);
        break;
    
    case 'get_all_residents':
        getAllResidents($conn);
        break;
    
    case 'search_residents':
        searchResidents($conn);
        break;
    
    default:
        respond(['success' => false, 'message' => 'Invalid action'], 400);
}

/**
 * Get residents by location and/or flat number
 */
function getResidents($conn) {
    try {
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $flat_number = isset($_GET['flat_number']) ? trim($_GET['flat_number']) : '';
        
        $query = "
            SELECT 
                r.id,
                r.location_id,
                r.flat_number,
                r.full_name,
                r.mobile,
                r.email,
                r.alternate_mobile,
                r.status,
                r.created_at,
                r.updated_at,
                l.society_name,
                l.tower_name
            FROM residents r
            JOIN locations l ON r.location_id = l.id
            WHERE 1=1
        ";
        
        $params = [];
        $types = '';
        
        if ($location_id > 0) {
            $query .= " AND r.location_id = ?";
            $params[] = $location_id;
            $types .= 'i';
        }
        
        if (!empty($flat_number)) {
            $query .= " AND r.flat_number = ?";
            $params[] = $flat_number;
            $types .= 's';
        }
        
        $query .= " ORDER BY r.flat_number ASC, r.full_name ASC";
        
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $residents = [];
        while ($row = $result->fetch_assoc()) {
            $residents[] = $row;
        }
        
        $stmt->close();
        
        respond([
            'success' => true,
            'residents' => $residents,
            'count' => count($residents)
        ]);
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/logs/resident_debug.log', date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n", FILE_APPEND);
        respond(['success' => false, 'message' => 'Database error', 'debug' => $e->getMessage()], 500);
    }
}

/**
 * Get resident by flat number
 */
function getResidentByFlat($conn) {
    try {
        $location_id = intval($_GET['location_id'] ?? 1);
        $flat_number = trim($_GET['flat_number'] ?? '');
        
        if (empty($flat_number)) {
            respond(['success' => false, 'message' => 'Flat number required']);
        }
        
        $stmt = $conn->prepare("
            SELECT 
                r.id,
                r.location_id,
                r.flat_number,
                r.full_name,
                r.mobile,
                r.email,
                r.alternate_mobile,
                r.status,
                l.society_name,
                l.tower_name
            FROM residents r
            JOIN locations l ON r.location_id = l.id
            WHERE r.location_id = ? AND r.flat_number = ?
            LIMIT 1
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('is', $location_id, $flat_number);
        $stmt->execute();
        $result = $stmt->get_result();
        $resident = $result->fetch_assoc();
        $stmt->close();
        
        if ($resident) {
            respond([
                'success' => true,
                'resident' => $resident
            ]);
        } else {
            respond([
                'success' => false,
                'message' => 'Resident not found'
            ]);
        }
    } catch (Exception $e) {
        respond(['success' => false, 'message' => 'Database error', 'debug' => $e->getMessage()], 500);
    }
}

/**
 * Add new resident
 */
function addResident($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'Invalid request method'], 405);
    }
    
    try {
        $location_id = intval($_POST['location_id'] ?? 0);
        $flat_number = trim($_POST['flat_number'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $alternate_mobile = trim($_POST['alternate_mobile'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        
        // Validation
        if ($location_id <= 0) {
            respond(['success' => false, 'message' => 'Valid location required']);
        }
        
        if (empty($flat_number)) {
            respond(['success' => false, 'message' => 'Flat number required']);
        }
        
        if (empty($full_name)) {
            respond(['success' => false, 'message' => 'Full name required']);
        }
        
        if (empty($mobile)) {
            respond(['success' => false, 'message' => 'Mobile number required']);
        }
        
        if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
            respond(['success' => false, 'message' => 'Invalid mobile number format']);
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'Invalid email format']);
        }
        
        // Check if flat number already exists for this location
        $stmt = $conn->prepare("
            SELECT id FROM residents 
            WHERE location_id = ? AND flat_number = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('is', $location_id, $flat_number);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($existing) {
            respond(['success' => false, 'message' => 'This flat number already has an active resident']);
        }
        
        // Insert new resident
        $stmt = $conn->prepare("
            INSERT INTO residents 
            (location_id, flat_number, full_name, mobile, email, alternate_mobile, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('issssss', 
            $location_id,
            $flat_number,
            $full_name,
            $mobile,
            $email,
            $alternate_mobile,
            $status
        );
        
        if ($stmt->execute()) {
            $resident_id = $conn->insert_id;
            $stmt->close();
            
            // Fetch the newly created resident
            $stmt = $conn->prepare("
                SELECT 
                    r.*,
                    l.society_name,
                    l.tower_name
                FROM residents r
                JOIN locations l ON r.location_id = l.id
                WHERE r.id = ?
            ");
            $stmt->bind_param('i', $resident_id);
            $stmt->execute();
            $resident = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            respond([
                'success' => true,
                'message' => 'Resident added successfully',
                'resident' => $resident
            ]);
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/logs/resident_debug.log', date('Y-m-d H:i:s') . " - Add Error: " . $e->getMessage() . "\n", FILE_APPEND);
        respond(['success' => false, 'message' => 'Failed to add resident', 'debug' => $e->getMessage()], 500);
    }
}

/**
 * Update resident
 */
function updateResident($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'Invalid request method'], 405);
    }
    
    try {
        $resident_id = intval($_POST['id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $alternate_mobile = trim($_POST['alternate_mobile'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        
        // Validation
        if ($resident_id <= 0) {
            respond(['success' => false, 'message' => 'Valid resident ID required']);
        }
        
        if (empty($full_name)) {
            respond(['success' => false, 'message' => 'Full name required']);
        }
        
        if (empty($mobile)) {
            respond(['success' => false, 'message' => 'Mobile number required']);
        }
        
        if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
            respond(['success' => false, 'message' => 'Invalid mobile number format']);
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'Invalid email format']);
        }
        
        // Check if resident exists
        $stmt = $conn->prepare("SELECT id FROM residents WHERE id = ?");
        $stmt->bind_param('i', $resident_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$existing) {
            respond(['success' => false, 'message' => 'Resident not found']);
        }
        
        // Update resident
        $stmt = $conn->prepare("
            UPDATE residents 
            SET full_name = ?,
                mobile = ?,
                email = ?,
                alternate_mobile = ?,
                status = ?
            WHERE id = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param('sssssi',
            $full_name,
            $mobile,
            $email,
            $alternate_mobile,
            $status,
            $resident_id
        );
        
        if ($stmt->execute()) {
            $stmt->close();
            
            // Fetch updated resident
            $stmt = $conn->prepare("
                SELECT 
                    r.*,
                    l.society_name,
                    l.tower_name
                FROM residents r
                JOIN locations l ON r.location_id = l.id
                WHERE r.id = ?
            ");
            $stmt->bind_param('i', $resident_id);
            $stmt->execute();
            $resident = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            respond([
                'success' => true,
                'message' => 'Resident updated successfully',
                'resident' => $resident
            ]);
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/logs/resident_debug.log', date('Y-m-d H:i:s') . " - Update Error: " . $e->getMessage() . "\n", FILE_APPEND);
        respond(['success' => false, 'message' => 'Failed to update resident', 'debug' => $e->getMessage()], 500);
    }
}

/**
 * Delete (deactivate) resident
 */
function deleteResident($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['success' => false, 'message' => 'Invalid request method'], 405);
    }
    
    try {
        $resident_id = intval($_POST['id'] ?? 0);
        
        if ($resident_id <= 0) {
            respond(['success' => false, 'message' => 'Valid resident ID required']);
        }
        
        // Check if resident has active deliveries
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM deliveries 
            WHERE resident_id = ? AND status = 'deposited'
        ");
        $stmt->bind_param('i', $resident_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($result['count'] > 0) {
            respond([
                'success' => false,
                'message' => 'Cannot delete resident with active deliveries. Please wait until packages are collected.'
            ]);
        }
        
        // Soft delete - set status to inactive
        $stmt = $conn->prepare("UPDATE residents SET status = 'inactive' WHERE id = ?");
        $stmt->bind_param('i', $resident_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            respond([
                'success' => true,
                'message' => 'Resident deactivated successfully'
            ]);
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/logs/resident_debug.log', date('Y-m-d H:i:s') . " - Delete Error: " . $e->getMessage() . "\n", FILE_APPEND);
        respond(['success' => false, 'message' => 'Failed to delete resident', 'debug' => $e->getMessage()], 500);
    }
}

/**
 * Get all residents (with pagination)
 */
function getAllResidents($conn) {
    try {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 50;
        $offset = ($page - 1) * $per_page;
        
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        
        // Count total
        $count_query = "SELECT COUNT(*) as total FROM residents WHERE 1=1";
        $params = [];
        $types = '';
        
        if ($location_id > 0) {
            $count_query .= " AND location_id = ?";
            $params[] = $location_id;
            $types .= 'i';
        }
        
        $stmt = $conn->prepare($count_query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        
        // Fetch residents
        $query = "
            SELECT 
                r.*,
                l.society_name,
                l.tower_name
            FROM residents r
            JOIN locations l ON r.location_id = l.id
            WHERE 1=1
        ";
        
        if ($location_id > 0) {
            $query .= " AND r.location_id = ?";
        }
        
        $query .= " ORDER BY r.flat_number ASC, r.full_name ASC LIMIT ? OFFSET ?";
        
        $stmt = $conn->prepare($query);
        
        if ($location_id > 0) {
            $stmt->bind_param('iii', $location_id, $per_page, $offset);
        } else {
            $stmt->bind_param('ii', $per_page, $offset);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $residents = [];
        while ($row = $result->fetch_assoc()) {
            $residents[] = $row;
        }
        
        $stmt->close();
        
        respond([
            'success' => true,
            'residents' => $residents,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page)
            ]
        ]);
    } catch (Exception $e) {
        respond(['success' => false, 'message' => 'Database error', 'debug' => $e->getMessage()], 500);
    }
}

/**
 * Search residents
 */
function searchResidents($conn) {
    try {
        $search = trim($_GET['search'] ?? '');
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        
        if (empty($search)) {
            respond(['success' => false, 'message' => 'Search term required']);
        }
        
        $search_term = "%{$search}%";
        
        $query = "
            SELECT 
                r.*,
                l.society_name,
                l.tower_name
            FROM residents r
            JOIN locations l ON r.location_id = l.id
            WHERE (
                r.flat_number LIKE ? OR
                r.full_name LIKE ? OR
                r.mobile LIKE ? OR
                r.email LIKE ?
            )
        ";
        
        $params = [$search_term, $search_term, $search_term, $search_term];
        $types = 'ssss';
        
        if ($location_id > 0) {
            $query .= " AND r.location_id = ?";
            $params[] = $location_id;
            $types .= 'i';
        }
        
        $query .= " ORDER BY r.flat_number ASC LIMIT 50";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $residents = [];
        while ($row = $result->fetch_assoc()) {
            $residents[] = $row;
        }
        
        $stmt->close();
        
        respond([
            'success' => true,
            'residents' => $residents,
            'count' => count($residents)
        ]);
    } catch (Exception $e) {
        respond(['success' => false, 'message' => 'Database error', 'debug' => $e->getMessage()], 500);
    }
}

function respond($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>