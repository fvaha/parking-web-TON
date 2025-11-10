<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            // Link/update Telegram user
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON body'
                ]);
                exit();
            }
            
            if (!isset($input['telegram_id']) || !isset($input['license_plate']) || !isset($input['chat_id'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required fields: telegram_id, license_plate, chat_id'
                ]);
                exit();
            }
            
            $result = $db->linkTelegramUser(
                (int)$input['telegram_id'],
                $input['username'] ?? null,
                trim($input['license_plate']),
                (int)$input['chat_id']
            );
            
            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(500);
                echo json_encode($result);
            }
            break;
            
        case 'GET':
            // Get user by Telegram ID or license plate
            if (isset($_GET['telegram_id'])) {
                $user = $db->getTelegramUserByTelegramId((int)$_GET['telegram_id']);
                // Return success even if user not found (frontend expects success: true)
                echo json_encode([
                    'success' => true,
                    'data' => $user ? $user : null
                ]);
            } elseif (isset($_GET['license_plate'])) {
                $user = $db->getTelegramUserByLicensePlate(trim($_GET['license_plate']));
                // Return success even if user not found (frontend expects success: true)
                echo json_encode([
                    'success' => true,
                    'data' => $user ? $user : null
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing parameter: telegram_id or license_plate'
                ]);
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

