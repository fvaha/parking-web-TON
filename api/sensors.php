<?php
session_start();
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

// Check if user is authenticated
function checkAuth() {
    if (!isset($_SESSION['admin_user'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Not authenticated'
        ]);
        exit();
    }
    
    return $_SESSION['admin_user']['id'];
}

try {
    $db = new Database();
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // Get all sensors
            $sensors = $db->getSensors();
            echo json_encode([
                'success' => true,
                'data' => $sensors
            ]);
            break;
            
        case 'POST':
            // Add new sensor (requires authentication)
            $admin_user_id = checkAuth();
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
            $required_fields = ['name', 'wpsd_id', 'street_name', 'latitude', 'longitude'];
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
            
            $result = $db->addSensor($input, $admin_user_id);
            
            if ($result['success']) {
                http_response_code(201);
                echo json_encode($result);
            } else {
                // Check if it's a duplicate error (409 Conflict)
                if (isset($result['error']) && (strpos($result['error'], 'already exists') !== false || strpos($result['error'], 'UNIQUE constraint') !== false)) {
                    http_response_code(409); // Conflict
                } else {
                    http_response_code(500);
                }
                echo json_encode($result);
            }
            break;
            
        case 'PUT':
            // Update existing sensor (requires authentication)
            $admin_user_id = checkAuth();
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON input'
                ]);
                exit();
            }
            
            // Check if sensor ID is provided
            if (!isset($input['id']) || !is_numeric($input['id'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing or invalid sensor ID'
                ]);
                exit();
            }
            
            $sensor_id = $input['id'];
            
            // Validate required fields
            $required_fields = ['name', 'wpsd_id', 'street_name', 'latitude', 'longitude'];
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
            
            $result = $db->updateSensor($sensor_id, $input, $admin_user_id);
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode($result);
            }
            break;
            
        case 'DELETE':
            // Delete sensor (requires authentication)
            $admin_user_id = checkAuth();
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['id']) || !is_numeric($input['id'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing or invalid sensor ID'
                ]);
                exit();
            }
            
            $sensor_id = $input['id'];
            
            $result = $db->deleteSensor($sensor_id, $admin_user_id);
            
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
