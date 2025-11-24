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
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // Get all admin users (only superadmin can see all users)
            $admin_user_id = checkAuth('superadmin');
            $admin_users = $db->getAdminUsers();
            
            echo json_encode([
                'success' => true,
                'data' => $admin_users
            ]);
            break;
            
        case 'POST':
            // Add new admin user (only superadmin can add users)
            $admin_user_id = checkAuth('superadmin');
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON input'
                ]);
                exit();
            }
            
            // Validate required fields
            $required_fields = ['username', 'password', 'email'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field]) || empty($input[$field])) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => "Missing required field: $field"
                    ]);
                    exit();
                }
            }
            
            // Validate role
            if (isset($input['role']) && !in_array($input['role'], ['admin', 'superadmin'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid role. Must be admin or superadmin'
                ]);
                exit();
            }
            
            $result = $db->addAdminUser($input, $admin_user_id);
            
            if ($result['success']) {
                http_response_code(201);
                echo json_encode($result);
            } else {
                http_response_code(500);
                echo json_encode($result);
            }
            break;
            
        case 'PUT':
            // Update existing admin user (only superadmin can update users)
            $admin_user_id = checkAuth('superadmin');
            
            $path_parts = explode('/', $_SERVER['REQUEST_URI']);
            $user_id = end($path_parts);
            
            if (!is_numeric($user_id)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid user ID'
                ]);
                exit();
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON input'
                ]);
                exit();
            }
            
            // Validate role if provided
            if (isset($input['role']) && !in_array($input['role'], ['admin', 'superadmin'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid role. Must be admin or superadmin'
                ]);
                exit();
            }
            
            $result = $db->updateAdminUser($user_id, $input, $admin_user_id);
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode($result);
            }
            break;
            
        case 'DELETE':
            // Delete admin user (only superadmin can delete users)
            $admin_user_id = checkAuth('superadmin');
            
            $path_parts = explode('/', $_SERVER['REQUEST_URI']);
            $user_id = end($path_parts);
            
            if (!is_numeric($user_id)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid user ID'
                ]);
                exit();
            }
            
            $result = $db->deleteAdminUser($user_id, $admin_user_id);
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode($result);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error' => 'Method not allowed'
            ]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
