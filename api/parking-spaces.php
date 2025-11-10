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
            
            $result = $db->updateParkingSpaceStatus($space_id, $input['status'], $license_plate, $reservation_time, $occupied_since, $payment_tx_hash);
            
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
                
                // If reservation was created, queue notifications
                if ($input['status'] === 'reserved' && $license_plate) {
                    // Check if user has Telegram linked
                    $telegram_user = $db->getTelegramUserByLicensePlate($license_plate);
                    if ($telegram_user) {
                        // Queue reservation confirmation
                        $zone = $db->getZoneBySpaceId($space_id);
                        $zone_name = $zone ? $zone['name'] : 'Unknown';
                        $message = "✅ Reservation confirmed!\n\nSpace #{$space_id}\nZone: {$zone_name}\nLicense Plate: {$license_plate}\nDuration: {$duration_hours} hour" . ($duration_hours > 1 ? 's' : '');
                        $db->queueNotification(
                            $telegram_user['telegram_user_id'],
                            'reservation_ending',
                            $message,
                            ['parking_space_id' => $space_id]
                        );
                        
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
