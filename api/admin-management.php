<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($_GET['id'])) {
                $admin = $db->getAdminUser($_GET['id']);
                if ($admin) {
                    echo json_encode([
                        'success' => true,
                        'data' => $admin
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Admin user not found'
                    ]);
                }
            } else {
                $admins = $db->getAdminUsers();
                echo json_encode([
                    'success' => true,
                    'data' => $admins
                ]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['username']) || !isset($input['password']) || !isset($input['email'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required fields'
                ]);
                exit;
            }
            
            // Check if trying to create superadmin
            if (isset($input['role']) && $input['role'] === 'superadmin') {
                // TODO: Get actual admin user ID from session and check permissions
                if (!$db->canCreateSuperAdmin(1)) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Only superadmins can create other superadmins'
                    ]);
                    exit;
                }
            }
            
            $admin_data = [
                'username' => $input['username'],
                'password' => $input['password'],
                'email' => $input['email'],
                'role' => $input['role'] ?? 'admin'
            ];
            
            $admin_id = $db->addAdminUser($admin_data, 1); // TODO: Get actual admin user ID from session
            
            if ($admin_id) {
                echo json_encode([
                    'success' => true,
                    'data' => ['id' => $admin_id],
                    'message' => 'Admin user created successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to create admin user'
                ]);
            }
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $admin_id = $_GET['id'] ?? null;
            
            if (!$admin_id) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Admin user ID required'
                ]);
                exit;
            }
            
            // Handle password change
            if (isset($input['action']) && $input['action'] === 'change_password') {
                if (!isset($input['new_password'])) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'New password required'
                    ]);
                    exit;
                }
                
                if ($db->changeAdminPassword($admin_id, $input['new_password'], 1)) { // TODO: Get actual admin user ID from session
                    echo json_encode([
                        'success' => true,
                        'message' => 'Password changed successfully'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to change password'
                    ]);
                }
                exit;
            }
            
            // Handle regular update
            if (!isset($input['username']) || !isset($input['email'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required fields'
                ]);
                exit;
            }
            
            // Check if trying to change role to superadmin
            if (isset($input['role']) && $input['role'] === 'superadmin') {
                // TODO: Get actual admin user ID from session and check permissions
                if (!$db->canCreateSuperAdmin(1)) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Only superadmins can promote users to superadmin'
                    ]);
                    exit;
                }
            }
            
            $admin_data = [
                'username' => $input['username'],
                'email' => $input['email'],
                'role' => $input['role'] ?? 'admin'
            ];
            
            if ($db->updateAdminUser($admin_id, $admin_data, 1)) { // TODO: Get actual admin user ID from session
                echo json_encode([
                    'success' => true,
                    'message' => 'Admin user updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to update admin user'
                ]);
            }
            break;
            
        case 'DELETE':
            $admin_id = $_GET['id'] ?? null;
            
            if (!$admin_id) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Admin user ID required'
                ]);
                exit;
            }
            
            // Prevent superadmin from deleting themselves
            $admin = $db->getAdminUser($admin_id);
            if ($admin && $admin['role'] === 'superadmin') {
                // TODO: Get actual admin user ID from session
                if ($admin_id == 1) { // Assuming 1 is the current superadmin
                    echo json_encode([
                        'success' => false,
                        'error' => 'Cannot delete your own superadmin account'
                    ]);
                    exit;
                }
            }
            
            if ($db->deleteAdminUser($admin_id, 1)) { // TODO: Get actual admin user ID from session
                echo json_encode([
                    'success' => true,
                    'message' => 'Admin user deleted successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to delete admin user'
                ]);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Method not allowed'
            ]);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
