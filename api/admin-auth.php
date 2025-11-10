<?php
session_start();
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing action parameter'
        ]);
        exit();
    }
    
    // check_session doesn't need database access - handle it first
    if ($input['action'] === 'check_session') {
        if (isset($_SESSION['admin_user'])) {
            echo json_encode([
                'success' => true,
                'authenticated' => true,
                'user' => $_SESSION['admin_user']
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'authenticated' => false
            ]);
        }
        exit();
    }
    
    // For other actions, require database
    require_once '../config/database.php';
    
    try {
        $db = new Database();
        
        switch ($input['action']) {
            case 'login':
                if (!isset($input['username']) || !isset($input['password'])) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Missing username or password'
                    ]);
                    exit();
                }
                
                $user = $db->authenticateAdmin($input['username'], $input['password']);
                
                if ($user) {
                    // Store user info in session
                    $_SESSION['admin_user'] = [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'role' => $user['role']
                    ];
                    
                    // Log login action (with error handling in case of lock)
                    try {
                        $db->logAdminAction($user['id'], 'LOGIN', 'admin_users', $user['id'], null, [
                            'username' => $user['username'],
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
                        ]);
                    } catch (Exception $log_error) {
                        // Log error but don't fail login
                        error_log('Failed to log admin login: ' . $log_error->getMessage());
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'user' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'role' => $user['role']
                        ],
                        'message' => 'Login successful'
                    ]);
                } else {
                    http_response_code(401);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid username or password'
                    ]);
                }
                break;
                
            case 'logout':
                if (isset($_SESSION['admin_user'])) {
                    // Log logout action (with error handling)
                    try {
                        $db->logAdminAction($_SESSION['admin_user']['id'], 'LOGOUT', 'admin_users', $_SESSION['admin_user']['id'], null, [
                            'username' => $_SESSION['admin_user']['username'],
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
                        ]);
                    } catch (Exception $log_error) {
                        // Log error but don't fail logout
                        error_log('Failed to log admin logout: ' . $log_error->getMessage());
                    }
                    
                    // Clear session
                    session_destroy();
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Logout successful'
                ]);
                break;
                
            default:
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid action'
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
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
}
?>
