<?php
session_start();
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
                $zone = $db->getParkingZone($_GET['id']);
                if ($zone) {
                    echo json_encode([
                        'success' => true,
                        'data' => $zone
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Zone not found'
                    ]);
                }
            } else {
                $zones = $db->getParkingZones();
                echo json_encode([
                    'success' => true,
                    'data' => $zones
                ]);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['name']) || !isset($input['hourly_rate']) || !isset($input['daily_rate'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required fields: name, hourly_rate, daily_rate'
                ]);
                exit;
            }
            
            $zone_data = [
                'name' => $input['name'],
                'description' => $input['description'] ?? '',
                'color' => $input['color'] ?? '#3B82F6',
                'hourly_rate' => (float)$input['hourly_rate'],
                'daily_rate' => (float)$input['daily_rate'],
                'is_premium' => isset($input['is_premium']) ? (bool)$input['is_premium'] : false,
                'max_duration_hours' => isset($input['max_duration_hours']) ? (int)$input['max_duration_hours'] : 4
            ];
            
            // TODO: Get actual admin user ID from session
            $admin_user_id = 1; // Temporary fix
            
            $zone_id = $db->addParkingZone($zone_data, $admin_user_id);
            
            if ($zone_id) {
                echo json_encode([
                    'success' => true,
                    'data' => ['id' => $zone_id],
                    'message' => 'Zone created successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to create zone'
                ]);
            }
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            $zone_id = $_GET['id'] ?? null;
            
            if (!$zone_id) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Zone ID required'
                ]);
                exit;
            }
            
            if (!isset($input['name']) || !isset($input['hourly_rate']) || !isset($input['daily_rate'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required fields: name, hourly_rate, daily_rate'
                ]);
                exit;
            }
            
            $zone_data = [
                'name' => $input['name'],
                'description' => $input['description'] ?? '',
                'color' => $input['color'] ?? '#3B82F6',
                'hourly_rate' => (float)$input['hourly_rate'],
                'daily_rate' => (float)$input['daily_rate'],
                'is_premium' => isset($input['is_premium']) ? (bool)$input['is_premium'] : false,
                'max_duration_hours' => isset($input['max_duration_hours']) ? (int)$input['max_duration_hours'] : 4
            ];
            
            // TODO: Get actual admin user ID from session
            $admin_user_id = 1; // Temporary fix
            
            if ($db->updateParkingZone($zone_id, $zone_data, $admin_user_id)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Zone updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to update zone'
                ]);
            }
            break;
            
        case 'DELETE':
            $zone_id = $_GET['id'] ?? null;
            
            if (!$zone_id) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Zone ID required'
                ]);
                exit;
            }
            
            // TODO: Get actual admin user ID from session
            $admin_user_id = 1; // Temporary fix
            
            if ($db->deleteParkingZone($zone_id, $admin_user_id)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Zone deleted successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to delete zone'
                ]);
            }
            break;
            
        case 'POST':
            // Check if this is a space assignment request
            $action = $_GET['action'] ?? null;
            
            // Handle space assignment: POST /api/zones.php?action=assign&zone_id=1&space_id=2
            if ($action === 'assign') {
                $input = json_decode(file_get_contents('php://input'), true);
                $zone_id = $_GET['zone_id'] ?? $input['zone_id'] ?? null;
                $space_id = $_GET['space_id'] ?? $input['space_id'] ?? null;
                
                if (!$zone_id || !$space_id) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Zone ID and Space ID required'
                    ]);
                    exit;
                }
                
                // TODO: Get actual admin user ID from session
                $admin_user_id = 1; // Temporary fix
                
                if ($db->assignParkingSpaceToZone($zone_id, $space_id, $admin_user_id)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Parking space assigned to zone successfully'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to assign parking space to zone'
                    ]);
                }
                break;
            }
            
            // Handle space removal: POST /api/zones.php?action=remove&zone_id=1&space_id=2
            if ($action === 'remove') {
                $input = json_decode(file_get_contents('php://input'), true);
                $zone_id = $_GET['zone_id'] ?? $input['zone_id'] ?? null;
                $space_id = $_GET['space_id'] ?? $input['space_id'] ?? null;
                
                if (!$zone_id || !$space_id) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Zone ID and Space ID required'
                    ]);
                    exit;
                }
                
                // TODO: Get actual admin user ID from session
                $admin_user_id = 1; // Temporary fix
                
                if ($db->removeParkingSpaceFromZone($zone_id, $space_id, $admin_user_id)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Parking space removed from zone successfully'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to remove parking space from zone'
                    ]);
                }
                break;
            }
            
            // Fall through to original POST handler for zone creation
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['name']) || !isset($input['hourly_rate']) || !isset($input['daily_rate'])) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required fields: name, hourly_rate, daily_rate'
                ]);
                exit;
            }
            
            $zone_data = [
                'name' => $input['name'],
                'description' => $input['description'] ?? '',
                'color' => $input['color'] ?? '#3B82F6',
                'hourly_rate' => (float)$input['hourly_rate'],
                'daily_rate' => (float)$input['daily_rate'],
                'is_premium' => isset($input['is_premium']) ? (bool)$input['is_premium'] : false,
                'max_duration_hours' => isset($input['max_duration_hours']) ? (int)$input['max_duration_hours'] : 4
            ];
            
            // TODO: Get actual admin user ID from session
            $admin_user_id = 1; // Temporary fix
            
            $zone_id = $db->addParkingZone($zone_data, $admin_user_id);
            
            if ($zone_id) {
                echo json_encode([
                    'success' => true,
                    'data' => ['id' => $zone_id],
                    'message' => 'Zone created successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to create zone'
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
