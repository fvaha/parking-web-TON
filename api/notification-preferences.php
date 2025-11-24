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
            // Get preferences
            if (!isset($_GET['telegram_id'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing parameter: telegram_id'
                ]);
                exit();
            }
            
            $preferences = $db->getNotificationPreferences((int)$_GET['telegram_id']);
            if ($preferences) {
                echo json_encode([
                    'success' => true,
                    'data' => $preferences
                ]);
            } else {
                // Return default preferences if none exist
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'notify_free_spaces' => true,
                        'notify_specific_space' => null,
                        'notify_street' => null,
                        'notify_zone' => null
                    ]
                ]);
            }
            break;
            
        case 'POST':
            // Update preferences
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON body'
                ]);
                exit();
            }
            
            if (!isset($input['telegram_id'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required field: telegram_id'
                ]);
                exit();
            }
            
            $preferences = [
                'notify_free_spaces' => $input['notify_free_spaces'] ?? true,
                'notify_specific_space' => isset($input['notify_specific_space']) ? (int)$input['notify_specific_space'] : null,
                'notify_street' => !empty($input['notify_street']) ? trim($input['notify_street']) : null,
                'notify_zone' => isset($input['notify_zone']) ? (int)$input['notify_zone'] : null
            ];
            
            $result = $db->updateNotificationPreferences((int)$input['telegram_id'], $preferences);
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(500);
                echo json_encode($result);
            }
            break;
            
        case 'DELETE':
            // Clear preferences (set to defaults)
            if (!isset($_GET['telegram_id'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing parameter: telegram_id'
                ]);
                exit();
            }
            
            $preferences = [
                'notify_free_spaces' => true,
                'notify_specific_space' => null,
                'notify_street' => null,
                'notify_zone' => null
            ];
            
            $result = $db->updateNotificationPreferences((int)$_GET['telegram_id'], $preferences);
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(500);
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
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>

