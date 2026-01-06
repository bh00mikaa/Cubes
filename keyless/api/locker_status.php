<?php
/**
 * locker_status.php - Get Available Lockers by Size
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load config
require_once __DIR__ . '/config.php';

function respond($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Get location_id from request
$location_id = intval($_GET['location_id'] ?? 1);

try {
    // Query to get available lockers count by size
    $stmt = $conn->prepare("
        SELECT 
            l.size,
            COUNT(*) as total_count,
            SUM(CASE WHEN l.status = 'available' THEN 1 ELSE 0 END) as available_count,
            SUM(CASE WHEN l.status = 'occupied' THEN 1 ELSE 0 END) as occupied_count
        FROM lockers l
        WHERE l.location_id = ?
        AND l.status != 'maintenance'
        GROUP BY l.size
        ORDER BY 
            CASE l.size 
                WHEN 'small' THEN 1 
                WHEN 'medium' THEN 2 
                WHEN 'large' THEN 3 
            END
    ");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param('i', $location_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $availability = [];
    while ($row = $result->fetch_assoc()) {
        $availability[$row['size']] = [
            'total' => intval($row['total_count']),
            'available' => intval($row['available_count']),
            'occupied' => intval($row['occupied_count'])
        ];
    }
    
    $stmt->close();
    
    // Ensure all sizes are present even if no lockers exist
    $sizes = ['small', 'medium', 'large'];
    foreach ($sizes as $size) {
        if (!isset($availability[$size])) {
            $availability[$size] = [
                'total' => 0,
                'available' => 0,
                'occupied' => 0
            ];
        }
    }
    
    respond([
        'success' => true,
        'location_id' => $location_id,
        'availability' => $availability
    ]);
    
} catch (Exception $e) {
    respond([
        'success' => false,
        'message' => 'Database error',
        'error' => $e->getMessage()
    ], 500);
}