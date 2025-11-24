<?php
header('Content-Type: application/json');

// Check if cors_helper.php exists and can be loaded
$cors_helper_path = __DIR__ . '/cors_helper.php';
if (!file_exists($cors_helper_path)) {
    error_log("telegram-users.php: cors_helper.php not found at {$cors_helper_path}");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server configuration error: cors_helper.php not found'
    ]);
    exit();
}

require_once $cors_helper_path;

set_cors_headers();
handle_preflight();

// Check if database.php exists and can be loaded
$database_path = __DIR__ . '/../config/database.php';
if (!file_exists($database_path)) {
    error_log("telegram-users.php: database.php not found at {$database_path}");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server configuration error: database.php not found'
    ]);
    exit();
}

require_once $database_path;

try {
    // Check if Database class exists
    if (!class_exists('Database')) {
        error_log("telegram-users.php: Database class not found after requiring database.php");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server configuration error: Database class not available'
        ]);
        exit();
    }
    
    $db = new Database();
    
    // Verify database connection
    if (!$db) {
        error_log("telegram-users.php: Failed to create Database instance");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error: Failed to initialize database connection'
        ]);
        exit();
    }
    
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
            try {
                if (isset($_GET['telegram_id'])) {
                    $user = $db->getTelegramUserByTelegramId((int)$_GET['telegram_id']);
                    // Return success even if user not found (frontend expects success: true)
                    // Handle false return value (error occurred)
                    if ($user === false) {
                        echo json_encode([
                            'success' => true,
                            'data' => null
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true,
                            'data' => $user ? $user : null
                        ]);
                    }
                } elseif (isset($_GET['license_plate'])) {
                    $user = $db->getTelegramUserByLicensePlate(trim($_GET['license_plate']));
                    // Return success even if user not found (frontend expects success: true)
                    // Handle false return value (error occurred)
                    if ($user === false) {
                        echo json_encode([
                            'success' => true,
                            'data' => null
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true,
                            'data' => $user ? $user : null
                        ]);
                    }
                } else {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Missing parameter: telegram_id or license_plate'
                    ]);
                }
            } catch (Exception $e) {
                // Log error but return success with null data (user not linked)
                error_log('getTelegramUser error: ' . $e->getMessage());
                echo json_encode([
                    'success' => true,
                    'data' => null
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

