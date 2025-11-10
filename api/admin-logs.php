<?php
session_start();
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

// Check if user is authenticated and has appropriate role
function checkAuth($required_role = 'admin') {
    if (!isset($_SESSION['admin_user'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Not authenticated'
        ]);
        exit();
    }
    
    if ($required_role === 'superadmin' && $_SESSION['admin_user']['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Insufficient permissions'
        ]);
        exit();
    }
    
    return $_SESSION['admin_user']['id'];
}

try {
    $db = new Database();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get admin logs (only superadmin can view logs)
        $admin_user_id = checkAuth('superadmin');
        
        // Parse query parameters
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        $filters = [];
        if (isset($_GET['admin_user_id'])) $filters['admin_user_id'] = (int)$_GET['admin_user_id'];
        if (isset($_GET['action'])) $filters['action'] = $_GET['action'];
        if (isset($_GET['table_name'])) $filters['table_name'] = $_GET['table_name'];
        if (isset($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
        if (isset($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];
        
        // Validate limits
        $limit = max(1, min(1000, $limit)); // Between 1 and 1000
        $offset = max(0, $offset);
        
        $logs = $db->getAdminLogs($limit, $offset, $filters);
        
        echo json_encode([
            'success' => true,
            'data' => $logs,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($logs)
            ]
        ]);
    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
