<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';
require_once 'security_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    // Helper to safely extract numeric ID from multiple URL styles
    $get_space_id = function(): ?int {
        // Prefer PATH_INFO if available: /api/parking-spaces.php/4 -> "/4"
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if ($pathInfo) {
            if (preg_match('#/(\d+)#', $pathInfo, $m)) {
                return (int)$m[1];
            }
        }
        // Fallback to parse REQUEST_URI path
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if ($requestPath) {
            // Match .../parking-spaces.php/123 or trailing slash variations
            if (preg_match('#parking-spaces\.php/(\d+)#', $requestPath, $m)) {
                return (int)$m[1];
            }
            // Also support query-string style: parking-spaces.php?id=123
            if (isset($_GET['id']) && is_numeric($_GET['id'])) {
                return (int)$_GET['id'];
            }
        }
        return null;
    };
    
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // GET is public (no API key required) - used by web app and bot
            // Get all parking spaces
            $parking_spaces = $db->getParkingSpaces();
            
            // Attach zone info for each space - optimized to use single query
            if (!empty($parking_spaces)) {
                // Get all space IDs
                $space_ids = array_map(function($space) {
                    return (int)$space['id'];
                }, $parking_spaces);
                
                // Get all zones for these spaces in one query
                $space_ids_string = implode(',', $space_ids);
                $zones_query = "
                    SELECT 
                        zps.parking_space_id,
                        z.id,
                        z.name,
                        z.color,
                        z.hourly_rate,
                        z.daily_rate,
                        z.is_premium,
                        z.max_duration_hours
                    FROM zone_parking_spaces zps
                    JOIN parking_zones z ON zps.zone_id = z.id
                    WHERE zps.parking_space_id IN ({$space_ids_string})
                ";
                
                $zones_result = $db->query($zones_query);
                $zones_map = [];
                if ($zones_result) {
                    while ($zone_row = $zones_result->fetchArray(SQLITE3_ASSOC)) {
                        $space_id = (int)$zone_row['parking_space_id'];
                        if (!isset($zones_map[$space_id])) {
                            $zones_map[$space_id] = [
                                'id' => (string)$zone_row['id'],
                                'name' => $zone_row['name'],
                                'color' => $zone_row['color'],
                                'hourly_rate' => isset($zone_row['hourly_rate']) ? (float)$zone_row['hourly_rate'] : null,
                                'daily_rate' => isset($zone_row['daily_rate']) ? (float)$zone_row['daily_rate'] : null,
                                'is_premium' => ($zone_row['is_premium'] == 1 || $zone_row['is_premium'] === true || $zone_row['is_premium'] === '1'),
                                'max_duration_hours' => isset($zone_row['max_duration_hours']) ? (int)$zone_row['max_duration_hours'] : null
                            ];
                        }
                    }
                }
                
                // Attach zones to spaces
                foreach ($parking_spaces as &$space) {
                    $space_id = (int)$space['id'];
                    if (isset($zones_map[$space_id])) {
                        $space['zone'] = $zones_map[$space_id];
                    } else {
                        $space['zone'] = null;
                    }
                }
                unset($space); // Break reference
            }
            
            echo json_encode([
                'success' => true,
                'data' => $parking_spaces
            ]);
            break;
            
        case 'PUT':
            // Security: API key authentication for PUT (modifying data)
            // Allow both web app and bot requests (bot calls this endpoint internally)
            checkApiKey('any', true);
            $client_id = getClientIdentifier();
            checkRateLimit($client_id, 50, 60); // 50 requests per minute for PUT endpoint
            // Update parking space status
            $space_id = $get_space_id();
            if ($space_id === null) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid or missing parking space ID in URL'
                ]);
                exit();
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON body'
                ]);
                exit();
            }

            if (!isset($input['status'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing status field'
                ]);
                exit();
            }
            
            // Check if this is a reservation for a premium zone
            if ($input['status'] === 'reserved') {
                $zone = $db->getZoneBySpaceId($space_id);
                
                if ($zone && $zone['is_premium'] == 1) {
                    // Premium zone requires TON payment
                    if (!isset($input['payment_tx_hash'])) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'error' => 'Premium zone requires TON payment. Please provide payment_tx_hash.',
                            'is_premium' => true,
                            'zone_name' => $zone['name'],
                            'hourly_rate' => $zone['hourly_rate']
                        ]);
                        exit();
                    }
                    
                    // Verify payment exists and is verified
                    $payment = $db->getTonPaymentByTxHash($input['payment_tx_hash']);
                    if (!$payment || $payment['status'] !== 'verified') {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'error' => 'Invalid or unverified payment. Please complete payment first.',
                            'is_premium' => true
                        ]);
                        exit();
                    }
                    
                    // Check if payment is for this space
                    if ($payment['parking_space_id'] != $space_id) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'error' => 'Payment is not for this parking space.',
                            'is_premium' => true
                        ]);
                        exit();
                    }
                }
            }
            
            $license_plate = $input['license_plate'] ?? null;
            $reservation_time = $input['reservation_time'] ?? null;
            $occupied_since = $input['occupied_since'] ?? null;
            $payment_tx_hash = $input['payment_tx_hash'] ?? null;
            $duration_hours = isset($input['duration_hours']) ? (int)$input['duration_hours'] : 1;
            
            // Validate duration_hours against zone max_duration_hours if this is a reservation
            if ($input['status'] === 'reserved') {
                $zone = $db->getZoneBySpaceId($space_id);
                if ($zone && isset($zone['max_duration_hours']) && $zone['max_duration_hours'] !== null) {
                    $max_duration = (int)$zone['max_duration_hours'];
                    if ($duration_hours > $max_duration) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'error' => "Maximum reservation duration for this zone is {$max_duration} hour(s). You requested {$duration_hours} hour(s).",
                            'max_duration_hours' => $max_duration,
                            'requested_duration_hours' => $duration_hours
                        ]);
                        exit();
                    }
                }
                
                // Also validate minimum duration (at least 1 hour)
                if ($duration_hours < 1) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Reservation duration must be at least 1 hour.',
                        'requested_duration_hours' => $duration_hours
                    ]);
                    exit();
                }
            }
            
            // Calculate end_time based on duration_hours
            if ($reservation_time && $input['status'] === 'reserved') {
                $reservation_timestamp = strtotime($reservation_time);
                $end_timestamp = $reservation_timestamp + ($duration_hours * 3600); // duration_hours in seconds
                $end_time = date('Y-m-d H:i:s', $end_timestamp);
            } else {
                $end_time = null;
            }
            
            // Security check: If reserving, verify that space is currently vacant
            if ($input['status'] === 'reserved') {
                // Get current parking space to check status
                $parking_spaces = $db->getParkingSpaces();
                $current_space = null;
                foreach ($parking_spaces as $ps) {
                    if ($ps['id'] == $space_id) {
                        $current_space = $ps;
                        break;
                    }
                }
                
                if (!$current_space) {
                    http_response_code(404);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Parking space not found.'
                    ]);
                    exit();
                }
                
                // Check if space is already reserved or occupied
                if ($current_space['status'] !== 'vacant') {
                    http_response_code(409);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Parking space is already ' . $current_space['status'] . '. Cannot reserve.',
                        'current_status' => $current_space['status']
                    ]);
                    exit();
                }
            }
            
            // Security check: If changing status to 'vacant', verify that the user owns the reservation
            if ($input['status'] === 'vacant') {
                // Get current parking space to check license_plate
                $parking_spaces = $db->getParkingSpaces();
                $current_space = null;
                foreach ($parking_spaces as $ps) {
                    if ($ps['id'] == $space_id) {
                        $current_space = $ps;
                        break;
                    }
                }
                
                if ($current_space && !empty($current_space['license_plate'])) {
                    // Require license_plate to be provided and it must match the current reservation
                    if ($license_plate === null) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'error' => 'License plate is required to complete a session.'
                        ]);
                        exit();
                    }
                    
                    if ($license_plate !== $current_space['license_plate']) {
                        http_response_code(403);
                        echo json_encode([
                            'success' => false,
                            'error' => 'You can only complete sessions for your own license plate.'
                        ]);
                        exit();
                    }
                }
            }
            
            $result = $db->updateParkingSpaceStatus($space_id, $input['status'], $license_plate, $reservation_time, $occupied_since, $payment_tx_hash, $input['status'] === 'reserved' ? 'vacant' : null);
            
            if ($result['success']) {
                // If reservation was created, also create reservation record with end_time
                if ($input['status'] === 'reserved' && $license_plate && $reservation_time && $end_time) {
                    // Create or update reservation record
                    $reservation_data = [
                        'license_plate' => $license_plate,
                        'parking_space_id' => $space_id,
                        'start_time' => $reservation_time,
                        'end_time' => $end_time,
                        'status' => 'active'
                    ];
                    $db->createReservation($reservation_data);
                }
                
                // If reservation was created, send notification with navigation
                if ($input['status'] === 'reserved' && $license_plate) {
                    // Check if user has Telegram linked
                    $telegram_user = $db->getTelegramUserByLicensePlate($license_plate);
                    if ($telegram_user) {
                        // Get space coordinates for navigation
                        $parking_spaces = $db->getParkingSpaces();
                        $space = null;
                        foreach ($parking_spaces as $ps) {
                            if ($ps['id'] == $space_id) {
                                $space = $ps;
                                break;
                            }
                        }
                        $zone = $db->getZoneBySpaceId($space_id);
                        $zone_name = $zone ? $zone['name'] : 'Unknown';
                        
                        // Get user language
                        $lang = $telegram_user['language'] ?? 'en';
                        
                        // Load config and LanguageService for translations
                        if (!defined('TELEGRAM_BOT_TOKEN')) {
                            require_once __DIR__ . '/../telegram-bot/config.php';
                        }
                        require_once __DIR__ . '/../telegram-bot/services/LanguageService.php';
                        $navigate_text = \TelegramBot\Services\LanguageService::t('navigate', $lang);
                        
                        // Create message
                        $message = "✅ Reservation confirmed!\n\nSpace #{$space_id}\nZone: {$zone_name}\nLicense Plate: {$license_plate}\nDuration: {$duration_hours} hour" . ($duration_hours > 1 ? 's' : '');
                        
                        // Create navigation keyboard if coordinates are available
                        $keyboard = null;
                        if ($space && isset($space['coordinates']) && isset($space['coordinates']['lat']) && isset($space['coordinates']['lng'])) {
                            $lat = $space['coordinates']['lat'];
                            $lng = $space['coordinates']['lng'];
                            if ($lat != 0.0 && $lng != 0.0) {
                                // Create Google Maps URL - will open in app if installed, otherwise in browser
                                $maps_url = "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}";
                                $keyboard = [
                                    'inline_keyboard' => [
                                        [
                                            [
                                                'text' => $navigate_text,
                                                'url' => $maps_url
                                            ]
                                        ]
                                    ]
                                ];
                            }
                        }
                        
                        // Send message directly via Telegram API
                        if (defined('TELEGRAM_BOT_TOKEN') && !empty(TELEGRAM_BOT_TOKEN)) {
                            $telegram_url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
                            $message_data = [
                                'chat_id' => $telegram_user['chat_id'],
                                'text' => $message,
                                'parse_mode' => 'Markdown'
                            ];
                            
                            if ($keyboard) {
                                $message_data['reply_markup'] = json_encode($keyboard);
                            }
                            
                            $ch = curl_init($telegram_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($message_data));
                            curl_exec($ch);
                            curl_close($ch);
                        }
                        
                        // Schedule ending warning (10 minutes before)
                        if ($reservation_time && $end_time) {
                            $warning_time = date('Y-m-d H:i:s', strtotime($end_time . ' -10 minutes'));
                            $warning_message = "⏰ Reminder: Your reservation at Space #{$space_id} ends in 10 minutes!";
                            $db->queueNotification(
                                $telegram_user['telegram_user_id'],
                                'reservation_ending',
                                $warning_message,
                                ['parking_space_id' => $space_id]
                            );
                        }
                    }
                }
                
                // If space status changed to vacant, check for availability notifications
                if ($input['status'] === 'vacant') {
                    // Get zone info
                    $zone = $db->getZoneBySpaceId($space_id);
                    $zone_id = $zone ? $zone['id'] : null;
                    
                    // Get users with matching preferences
                    try {
                        $sql = "
                            SELECT np.*, tu.telegram_user_id, tu.chat_id
                            FROM notification_preferences np
                            JOIN telegram_users tu ON np.telegram_user_id = tu.telegram_user_id
                            WHERE tu.is_active = 1
                            AND (
                                np.notify_free_spaces = 1
                                OR np.notify_specific_space = {$space_id}
                                " . ($zone_id ? "OR np.notify_zone = {$zone_id}" : "") . "
                            )
                        ";
                        
                        $result = $db->query($sql);
                        if ($result) {
                            while ($pref = $result->fetchArray(SQLITE3_ASSOC)) {
                                $message = "🅿️ Parking space #{$space_id} is now available!";
                                if ($zone) {
                                    $message .= "\nZone: {$zone['name']}";
                                }
                                $db->queueNotification(
                                    $pref['telegram_user_id'],
                                    'space_available',
                                    $message,
                                    ['parking_space_id' => $space_id]
                                );
                            }
                        }
                    } catch (Exception $e) {
                        // Skip notification queuing on error
                        error_log('Failed to queue availability notifications: ' . $e->getMessage());
                    }
                }
                
                echo json_encode($result);
            } else {
                // Check if error is due to status mismatch (space already reserved/occupied)
                if (isset($result['error']) && strpos($result['error'], 'status has changed') !== false) {
                    http_response_code(409); // Conflict - space status changed
                } else {
                    http_response_code(404);
                }
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
