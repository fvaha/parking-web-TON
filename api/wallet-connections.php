<?php
header('Content-Type: application/json');

// Check if cors_helper.php exists and can be loaded
$cors_helper_path = __DIR__ . '/cors_helper.php';
if (!file_exists($cors_helper_path)) {
    error_log("wallet-connections.php: cors_helper.php not found at {$cors_helper_path}");
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
    error_log("wallet-connections.php: database.php not found at {$database_path}");
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
        error_log("wallet-connections.php: Database class not found after requiring database.php");
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
        error_log("wallet-connections.php: Failed to create Database instance");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed'
        ]);
        exit();
    }
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            // Save wallet connection (license_plate + password hash + wallet_address)
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON body'
                ]);
                exit();
            }
            
            if (!isset($input['license_plate']) || !isset($input['wallet_address'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing required fields: license_plate, wallet_address'
                ]);
                exit();
            }
            
            $license_plate = trim($input['license_plate']);
            $password = isset($input['password']) ? $input['password'] : null;
            $wallet_address = trim($input['wallet_address']);
            
            // Validate license plate
            if (strlen($license_plate) < 2 || strlen($license_plate) > 10) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid license plate length'
                ]);
                exit();
            }
            
            // Validate password only if provided (optional for manual wallet entry)
            if ($password !== null && (strlen($password) < 4 || strlen($password) > 50)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid password length (must be 4-50 characters)'
                ]);
                exit();
            }
            
            // Validate wallet address format
            if (!preg_match('/^(EQ|UQ|kQ|EQD|0:)[A-Za-z0-9_-]{46,48}$/', $wallet_address)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid wallet address format'
                ]);
                exit();
            }
            
            // Hash password only if provided (optional for manual wallet entry)
            $password_hash = null;
            if ($password !== null) {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
            }
            
            // Get optional device_id and telegram_user_id
            $device_id = isset($_POST['device_id']) ? $_POST['device_id'] : null;
            $telegram_user_id = isset($_POST['telegram_user_id']) ? (int)$_POST['telegram_user_id'] : null;
            
            // Save or update wallet connection - license_plate is the key
            $result = $db->saveWalletConnection($license_plate, $wallet_address, $password_hash, $device_id, $telegram_user_id);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Wallet connection saved successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode($result);
            }
            break;
            
        case 'GET':
            // Get wallet connection - license_plate is the primary key for web+telegram sync
            // Support multiple query methods:
            // 1. By license_plate only (auto-load, no auth)
            // 2. By license_plate + password (web with auth)
            // 3. By telegram_user_id (telegram bot)
            // 4. By device_id (web fallback)
            
            // Method 1: Get by license_plate ONLY (NO PASSWORD) - for auto-load
            if (isset($_GET['license_plate']) && !isset($_GET['password'])) {
                $license_plate = trim($_GET['license_plate']);
                
                // Get wallet connection from database
                $connection = $db->getWalletConnection($license_plate);
                
                if ($connection === false) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                    exit();
                }
                
                if (!$connection) {
                    // No wallet for this license plate yet
                    echo json_encode(['success' => true, 'wallet_address' => null]);
                    exit();
                }
                
                // Return wallet address (no password verification for auto-load)
                echo json_encode([
                    'success' => true,
                    'wallet_address' => $connection['wallet_address'],
                    'message' => 'Wallet found for license plate'
                ]);
                exit();
            }
            
            // Method 2: Get by license_plate + password (WEB with authentication)
            if (isset($_GET['license_plate']) && isset($_GET['password'])) {
                $license_plate = trim($_GET['license_plate']);
                $password = $_GET['password'];
                
                // Get wallet connection from database
                $connection = $db->getWalletConnection($license_plate);
                
                if ($connection === false) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                    exit();
                }
                
                if (!$connection) {
                    // No wallet for this license plate yet
                    echo json_encode(['success' => true, 'wallet_address' => null]);
                    exit();
                }
                
                // Verify password if one exists for this plate
                if ($connection['password_hash']) {
                    if (!password_verify($password, $connection['password_hash'])) {
                        echo json_encode(['success' => true, 'wallet_address' => null]);
                        exit();
                    }
                }
                
                // Password verified, return wallet
                echo json_encode([
                    'success' => true,
                    'wallet_address' => $connection['wallet_address'],
                    'message' => 'Wallet synced across web and Telegram'
                ]);
                exit();
            }
            
            // Method 2: Get by telegram_user_id (TELEGRAM BOT)
            if (isset($_GET['telegram_user_id'])) {
                $telegram_user_id = (int)$_GET['telegram_user_id'];
                $connection = $db->getWalletConnectionByTelegramUser($telegram_user_id);
                
                if ($connection === false) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                    exit();
                }
                
                if ($connection) {
                    echo json_encode([
                        'success' => true,
                        'wallet_address' => $connection['wallet_address'],
                        'license_plate' => $connection['license_plate'],
                        'message' => 'Wallet synced across web and Telegram'
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'wallet_address' => null,
                        'message' => 'No wallet connected yet'
                    ]);
                }
                exit();
            }
            
            // Method 3: Get by device_id (WEB FALLBACK)
            if (isset($_GET['device_id'])) {
                $device_id = trim($_GET['device_id']);
                $connection = $db->getWalletConnectionByDeviceId($device_id);
                
                if ($connection === false) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => 'Database error']);
                    exit();
                }
                
                if ($connection) {
                    echo json_encode([
                        'success' => true,
                        'wallet_address' => $connection['wallet_address'],
                        'message' => 'Wallet found by device'
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'wallet_address' => null,
                        'message' => 'No wallet connected yet'
                    ]);
                }
                exit();
            }
            
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Missing parameters. Use: (license_plate + password), telegram_user_id, or device_id'
            ]);
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
    error_log("wallet-connections.php: Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}

