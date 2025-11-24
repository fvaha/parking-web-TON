<?php
// Start output buffering to catch any errors
ob_start();

// Set error handler BEFORE any includes
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set error handler to catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}");
    // Don't die, just log
    return false;
});

// Set exception handler
set_exception_handler(function($exception) {
    error_log("Uncaught exception: " . $exception->getMessage());
    error_log("Stack trace: " . $exception->getTraceAsString());
    http_response_code(200); // Always return 200 to Telegram
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
    exit();
});

// Check PHP version for str_starts_with compatibility
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/TelegramAPI.php';
} catch (Throwable $e) {
    error_log("Failed to load config or TelegramAPI: " . $e->getMessage());
    error_log("Error type: " . get_class($e));
    http_response_code(200);
    ob_clean();
    echo json_encode(['ok' => false, 'error' => 'Configuration error: ' . $e->getMessage()]);
    exit();
}

// Define WEB_APP_URL if not already defined
if (!defined('WEB_APP_URL')) {
    define('WEB_APP_URL', 'https://parkiraj.info');
}

// Load command handlers with error handling
$required_files = [
    '/commands/StartCommand.php',
    '/commands/LinkCommand.php',
    '/commands/StatusCommand.php',
    '/commands/SpacesCommand.php',
    '/commands/WeatherCommand.php',
    '/commands/PreferencesCommand.php',
    '/commands/ReserveCommand.php',
    '/commands/HelpCommand.php',
    '/commands/LangCommand.php',
    '/commands/WalletCommand.php',
    '/services/LanguageService.php',
    '/services/DatabaseService.php',
    '/services/ParkingService.php',
    '/services/WeatherService.php',
    '/services/KeyboardService.php'
];

foreach ($required_files as $file) {
    $file_path = __DIR__ . $file;
    if (!file_exists($file_path)) {
        error_log("File not found: {$file_path}");
        http_response_code(200);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => "File not found: {$file}"]);
        exit();
    }
    
    try {
        require_once $file_path;
    } catch (Throwable $e) {
        error_log("Failed to load {$file}: " . $e->getMessage());
        error_log("Error type: " . get_class($e));
        error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
        http_response_code(200);
        ob_clean();
        echo json_encode(['ok' => false, 'error' => "Failed to load {$file}: " . $e->getMessage()]);
        exit();
    }
}

// Log incoming request for debugging
error_log('=== WEBHOOK START ===');
error_log('Request method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));
error_log('Content type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'UNKNOWN'));

$input = file_get_contents('php://input');
if (!empty($input)) {
    error_log('Telegram webhook received: ' . substr($input, 0, 500));
    if (function_exists('bot_log')) {
        bot_log('Telegram webhook received', ['input_length' => strlen($input)]);
    }
} else {
    error_log('Telegram webhook received: EMPTY INPUT');
}

try {
    error_log('Creating TelegramAPI instance...');
    $telegram = new TelegramAPI(TELEGRAM_BOT_TOKEN);
    error_log('TelegramAPI instance created');
    
    error_log('Getting webhook update...');
    $update = $telegram->getWebhookUpdate();
    error_log('Webhook update received: ' . ($update ? 'YES' : 'NO'));
    
    if (!$update) {
        error_log('No update received from Telegram');
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'No update']);
        exit();
    }
    
    // Handle Telegram Stars payment events (pre_checkout_query and successful_payment)
    $pre_checkout_query = $update->getPreCheckoutQuery();
    if ($pre_checkout_query) {
        // User is about to confirm payment - approve it
        require_once __DIR__ . '/services/TelegramStarsService.php';
        $stars_service = new \TelegramBot\Services\TelegramStarsService();
        
        // Parse invoice payload to get reservation details
        $payload = $pre_checkout_query->getInvoicePayload();
        $payload_data = json_decode($payload, true);
        
        if ($payload_data && isset($payload_data['space_id'])) {
            // Verify space is still available
            $parking_service = new \TelegramBot\Services\ParkingService();
            $space = $parking_service->getSpaceById($payload_data['space_id']);
            
            if ($space && $space['status'] === 'available') {
                // Approve payment
                $stars_service->answerPreCheckoutQuery($pre_checkout_query->getId(), true);
                error_log("Pre-checkout query approved for space {$payload_data['space_id']}");
            } else {
                // Decline payment - space not available
                $lang = \TelegramBot\Services\LanguageService::getLanguage($pre_checkout_query->getFrom());
                $error_msg = \TelegramBot\Services\LanguageService::t('stars_space_not_available', $lang);
                $stars_service->answerPreCheckoutQuery($pre_checkout_query->getId(), false, $error_msg);
                error_log("Pre-checkout query declined - space {$payload_data['space_id']} not available");
            }
        } else {
            // Invalid payload - decline
            $stars_service->answerPreCheckoutQuery($pre_checkout_query->getId(), false, 'Invalid payment data');
            error_log("Pre-checkout query declined - invalid payload");
        }
        
        http_response_code(200);
        echo json_encode(['ok' => true]);
        exit();
    }
    
    // Handle successful payment
    $message = $update->getMessage();
    if ($message) {
        $successful_payment = $message->getSuccessfulPayment();
        if ($successful_payment) {
            // Payment successful - verify payment first, then create reservation
            $payload = $successful_payment->getInvoicePayload();
            $payload_data = json_decode($payload, true);
            
            if ($payload_data && isset($payload_data['space_id'])) {
                $chat_id = $message->getChat()->getId();
                $user = $message->getFrom();
                $lang = \TelegramBot\Services\LanguageService::getLanguage($user);
                
                $db_service = new \TelegramBot\Services\DatabaseService();
                $db = $db_service->getDatabase();
                $parking_service = new \TelegramBot\Services\ParkingService();
                
                // Try to get user data with retry (in case link was just completed)
                $user_data = null;
                $max_retries = 3;
                for ($i = 0; $i < $max_retries; $i++) {
                    $user_data = $db->getTelegramUserByTelegramId($user->getId());
                    if ($user_data && !empty($user_data['license_plate'])) {
                        break;
                    }
                    if ($i < $max_retries - 1) {
                        // Wait a bit before retry (in case link was just completed)
                        usleep(500000); // 0.5 seconds
                    }
                }
                
                if ($user_data && !empty($user_data['license_plate'])) {
                    $space_id = $payload_data['space_id'];
                    $payment_type = $payload_data['payment_type'] ?? 'stars';
                    
                    // Get payment transaction hash
                    $tx_hash = $successful_payment->getTelegramPaymentChargeId() ?? 
                              ($payment_type === 'ton' ? 'ton_' . time() : 'stars_' . time());
                    
                    // For Telegram Stars/TON invoice payments, payment is automatically verified by Telegram
                    // But we still need to save it and verify it exists before reserving
                    
                    // Save payment record first
                    $amount_ton = $payload_data['amount_ton'] ?? 0;
                    
                    // Check if payment already exists
                    $stmt = $db->prepare("
                        SELECT * FROM ton_payments 
                        WHERE payment_tx_hash = ? 
                        AND parking_space_id = ?
                        LIMIT 1
                    ");
                    $stmt->bindValue(1, $tx_hash);
                    $stmt->bindValue(2, $space_id);
                    $result = $stmt->execute();
                    $existing_payment = $result->fetchArray(SQLITE3_ASSOC);
                    
                    if (!$existing_payment) {
                        // Create payment record with verified status (Telegram already verified it)
                        $db->exec("
                            INSERT INTO ton_payments (
                                telegram_user_id, 
                                parking_space_id, 
                                license_plate,
                                amount_ton, 
                                payment_tx_hash, 
                                status, 
                                created_at
                            ) VALUES (?, ?, ?, ?, ?, 'verified', datetime('now'))
                        ", [
                            $user->getId(),
                            $space_id,
                            $user_data['license_plate'],
                            $amount_ton,
                            $tx_hash
                        ]);
                    } else {
                        // Payment already exists - verify it's verified
                        if ($existing_payment['status'] !== 'verified') {
                            // Update to verified
                            $db->exec("
                                UPDATE ton_payments 
                                SET status = 'verified' 
                                WHERE id = ?
                            ", [$existing_payment['id']]);
                        }
                    }
                    
                    // Now verify payment exists and is verified before reserving
                    $stmt = $db->prepare("
                        SELECT * FROM ton_payments 
                        WHERE payment_tx_hash = ? 
                        AND parking_space_id = ?
                        AND status = 'verified'
                        LIMIT 1
                    ");
                    $stmt->bindValue(1, $tx_hash);
                    $stmt->bindValue(2, $space_id);
                    $result = $stmt->execute();
                    $verified_payment = $result->fetchArray(SQLITE3_ASSOC);
                    
                    if (!$verified_payment) {
                        // Payment not verified - send error
                        $error_text = \TelegramBot\Services\LanguageService::t('payment_not_verified', $lang) ?? 
                                    "Payment verification failed. Please contact support.";
                        $telegram->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => $error_text
                        ]);
                        error_log("Payment not verified for space {$space_id}, tx_hash: {$tx_hash}");
                        http_response_code(200);
                        echo json_encode(['ok' => true]);
                        exit();
                    }
                    
                    // Payment is verified - now reserve the space with payment_tx_hash
                    $success = $parking_service->reserveSpace($space_id, $user_data['license_plate'], $tx_hash);
                    
                    if ($success) {
                        // Get space to get coordinates for navigation
                        $space = $parking_service->getSpaceById($space_id);
                        
                        // Send success message based on payment type
                        if ($payment_type === 'ton') {
                            $success_text = \TelegramBot\Services\LanguageService::t('ton_payment_success', $lang, [
                                'zone_name' => $payload_data['zone_name'] ?? '',
                                'space_id' => $space_id,
                                'license_plate' => $user_data['license_plate']
                            ]);
                        } else {
                            $success_text = \TelegramBot\Services\LanguageService::t('stars_payment_success', $lang, [
                                'zone_name' => $payload_data['zone_name'] ?? '',
                                'space_id' => $space_id,
                                'license_plate' => $user_data['license_plate']
                            ]);
                        }
                        
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
                                                'text' => \TelegramBot\Services\LanguageService::t('navigate', $lang),
                                                'url' => $maps_url
                                            ]
                                        ]
                                    ]
                                ];
                            }
                        }
                        
                        $message_params = [
                            'chat_id' => $chat_id,
                            'text' => $success_text,
                            'parse_mode' => 'Markdown'
                        ];
                        
                        if ($keyboard) {
                            $message_params['reply_markup'] = json_encode($keyboard);
                        }
                        
                        $telegram->sendMessage($message_params);
                        
                        error_log("Reservation created via Telegram {$payment_type} payment for space {$space_id} with tx_hash: {$tx_hash}");
                    } else {
                        // Reservation failed
                        $error_text = $payment_type === 'ton' 
                            ? \TelegramBot\Services\LanguageService::t('ton_reservation_failed', $lang)
                            : \TelegramBot\Services\LanguageService::t('stars_reservation_failed', $lang);
                        $telegram->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => $error_text
                        ]);
                        error_log("Failed to create reservation after Telegram {$payment_type} payment for space {$space_id}");
                    }
                } else {
                    error_log("User not linked - cannot create reservation after Telegram payment");
                    // Send error message to user
                    $error_text = \TelegramBot\Services\LanguageService::t('not_linked', $lang) ?? 
                                "❌ Your Telegram account is not linked. Please use /link <license_plate> or the link from the web app.";
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => $error_text
                    ]);
                }
            }
            
            http_response_code(200);
            echo json_encode(['ok' => true]);
            exit();
        }
    }
    
    // Handle callback queries (button clicks)
    $callback_query = $update->getCallbackQuery();
    if ($callback_query) {
        $data = $callback_query->getData();
        $chat_id = $callback_query->getMessage()->getChat()->getId();
        $user = $callback_query->getFrom();
        
        error_log("Callback query received: data={$data}, chat_id={$chat_id}");
        
        // Get user language
        $lang = \TelegramBot\Services\LanguageService::getLanguage($user);
        
        // Helper function to create a fake message with command text
        $create_command_message = function($command_text) use ($user, $chat_id, $callback_query) {
            $original_message = $callback_query->getMessage();
            $message_id = isset($original_message->data['message_id']) ? $original_message->data['message_id'] : time();
            
            $message_data = [
                'message_id' => $message_id,
                'from' => [
                    'id' => $user->getId(),
                    'is_bot' => false,
                    'first_name' => $user->getFirstName() ?? 'User',
                    'username' => $user->getUsername()
                ],
                'chat' => [
                    'id' => $chat_id,
                    'type' => 'private'
                ],
                'date' => time(),
                'text' => $command_text
            ];
            return new TelegramMessage($message_data);
        };
        
        // Handle menu button callbacks (from inline keyboard menu)
        if ($data === 'menu_reserve') {
            try {
                // Answer callback first to remove loading indicator
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                $reserve_command = new \TelegramBot\Commands\ReserveCommand();
                $reserve_message = $create_command_message('/reserve');
                $reserve_command->handle($telegram, $reserve_message);
            } catch (\Throwable $e) {
                error_log("Error in menu_reserve: " . $e->getMessage());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error: ' . $e->getMessage(),
                    'show_alert' => true
                ]);
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error: " . $e->getMessage()
                ]);
            }
        } elseif ($data === 'menu_spaces') {
            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                $spaces_command = new \TelegramBot\Commands\SpacesCommand();
                $spaces_message = $create_command_message('/spaces');
                $spaces_command->handle($telegram, $spaces_message);
            } catch (\Throwable $e) {
                error_log("Error in menu_spaces: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error: ' . $e->getMessage(),
                    'show_alert' => true
                ]);
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error executing Spaces command: " . $e->getMessage()
                ]);
            }
        } elseif ($data === 'menu_status') {
            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                $status_command = new \TelegramBot\Commands\StatusCommand();
                $status_message = $create_command_message('/status');
                $status_command->handle($telegram, $status_message);
            } catch (\Throwable $e) {
                error_log("Error in menu_status: " . $e->getMessage());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif ($data === 'menu_wallet') {
            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                $wallet_command = new \TelegramBot\Commands\WalletCommand();
                $wallet_message = $create_command_message('/wallet');
                $wallet_command->handle($telegram, $wallet_message);
            } catch (\Throwable $e) {
                error_log("Error in menu_wallet: " . $e->getMessage());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif ($data === 'menu_preferences') {
            try {
                error_log("menu_preferences callback received, chat_id: {$chat_id}");
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                error_log("Creating PreferencesCommand instance...");
                $preferences_command = new \TelegramBot\Commands\PreferencesCommand();
                error_log("Creating preferences message...");
                $preferences_message = $create_command_message('/preferences');
                error_log("Calling PreferencesCommand->handle()...");
                $preferences_command->handle($telegram, $preferences_message);
                error_log("PreferencesCommand->handle() completed");
            } catch (\Throwable $e) {
                error_log("Error in menu_preferences: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error: ' . $e->getMessage(),
                    'show_alert' => true
                ]);
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error executing Preferences command: " . $e->getMessage() . "\n\nFile: " . basename($e->getFile()) . " Line: " . $e->getLine()
                ]);
            }
        } elseif ($data === 'menu_weather') {
            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                $weather_command = new \TelegramBot\Commands\WeatherCommand();
                $weather_message = $create_command_message('/weather');
                $weather_command->handle($telegram, $weather_message);
            } catch (\Throwable $e) {
                error_log("Error in menu_weather: " . $e->getMessage());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif ($data === 'menu_help') {
            try {
                error_log("menu_help callback received, chat_id: {$chat_id}");
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                error_log("Creating HelpCommand instance...");
                $help_command = new \TelegramBot\Commands\HelpCommand();
                error_log("Creating help message...");
                $help_message = $create_command_message('/help');
                error_log("Calling HelpCommand->handle()...");
                $help_command->handle($telegram, $help_message);
                error_log("HelpCommand->handle() completed");
            } catch (\Throwable $e) {
                error_log("Error in menu_help: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error: ' . $e->getMessage(),
                    'show_alert' => true
                ]);
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error executing Help command: " . $e->getMessage() . "\n\nFile: " . basename($e->getFile()) . " Line: " . $e->getLine()
                ]);
            }
        } elseif ($data === 'menu_lang') {
            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                $lang_command = new \TelegramBot\Commands\LangCommand();
                $lang_message = $create_command_message('/lang');
                $lang_command->handle($telegram, $lang_message);
            } catch (\Throwable $e) {
                error_log("Error in menu_lang: " . $e->getMessage());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error: ' . $e->getMessage(),
                    'show_alert' => true
                ]);
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error executing Language command: " . $e->getMessage()
                ]);
            }
        } elseif ($data === 'menu_start') {
            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
                $start_command = new \TelegramBot\Commands\StartCommand();
                $start_message = $create_command_message('/start');
                $start_command->handle($telegram, $start_message);
            } catch (\Throwable $e) {
                error_log("Error in menu_start: " . $e->getMessage());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif ($data === 'link_account') {
            $link_texts = [
                'en' => "🔗 To link your account, use the command:\n/link <license_plate>\n\nExample: /link ABC123",
                'sr' => "🔗 Da povežete nalog, koristite komandu:\n/link <registarska_tablica>\n\nPrimer: /link ABC123",
                'de' => "🔗 Um Ihr Konto zu verknüpfen, verwenden Sie den Befehl:\n/link <kennzeichen>\n\nBeispiel: /link ABC123",
                'fr' => "🔗 Pour lier votre compte, utilisez la commande:\n/link <plaque>\n\nExemple: /link ABC123",
                'ar' => "🔗 لربط حسابك، استخدم الأمر:\n/link <لوحة_الترخيص>\n\nمثال: /link ABC123"
            ];
            
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => $link_texts[$lang] ?? $link_texts['en']
            ]);
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'reserve_street:')) {
            // Handle street selection for reservation
            $street_name = urldecode(substr($data, 15)); // Remove 'reserve_street:' prefix
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $parking_service = new \TelegramBot\Services\ParkingService();
            
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            if (!$user_data) {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => \TelegramBot\Services\LanguageService::t('not_linked', $lang)
                ]);
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId()
                ]);
                exit();
            }
            
            $reserve_command = new \TelegramBot\Commands\ReserveCommand();
            $reserve_command->showSpacesForReservation($telegram, $chat_id, $parking_service, $street_name, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'reserve_space:')) {
            // Handle space reservation
            // Extract space_id from callback_data: "reserve_space:123"
            $parts = explode(':', $data, 2);
            $space_id_str = isset($parts[1]) ? trim($parts[1]) : '';
            $space_id = !empty($space_id_str) ? (int)$space_id_str : 0;
            
            error_log("Reserve space callback: data='{$data}', space_id_str='{$space_id_str}', space_id={$space_id}");
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $parking_service = new \TelegramBot\Services\ParkingService();
            
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            if (!$user_data) {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => \TelegramBot\Services\LanguageService::t('not_linked', $lang)
                ]);
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId()
                ]);
                exit();
            }
            
            // Validate space_id
            if (empty($space_id_str) || $space_id <= 0) {
                error_log("Reserve space: Invalid space_id extracted. data='{$data}', space_id_str='{$space_id_str}', space_id={$space_id}");
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => \TelegramBot\Services\LanguageService::t('reserve_not_found', $lang, ['space_id' => $space_id ?: 'N/A'])
                ]);
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => \TelegramBot\Services\LanguageService::t('reserve_not_found', $lang, ['space_id' => $space_id ?: 'N/A'])
                ]);
                exit();
            }
            
            $reserve_command = new \TelegramBot\Commands\ReserveCommand();
            $reserve_command->handleReservation($telegram, $chat_id, $parking_service, $space_id, $user_data, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId(),
                'text' => \TelegramBot\Services\LanguageService::t('reserve_processing', $lang)
            ]);
        } elseif ($data === 'reserve_back') {
            // Go back to streets list
            $parking_service = new \TelegramBot\Services\ParkingService();
            $reserve_command = new \TelegramBot\Commands\ReserveCommand();
            $reserve_command->showStreetsForReservation($telegram, $chat_id, $parking_service, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'wallet_setup_from_reserve') {
            try {
                // Show wallet setup from reserve flow
                $wallet_command = new \TelegramBot\Commands\WalletCommand();
                $wallet_command->handle($telegram, $callback_query->getMessage());
                
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Loading...'
                ]);
            } catch (\Throwable $e) {
                error_log("Error in wallet_setup_from_reserve: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif ($data === 'wallet_connect') {
            try {
                // Show wallet connect prompt
                $wallet_command = new \TelegramBot\Commands\WalletCommand();
                $wallet_command->showWalletConnectPrompt($telegram, $chat_id, $lang);
                
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Please enter your wallet address'
                ]);
            } catch (\Throwable $e) {
                error_log("Error in wallet_connect: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif ($data === 'wallet_change') {
            try {
                // Show wallet connect prompt for changing
                $wallet_command = new \TelegramBot\Commands\WalletCommand();
                $wallet_command->showWalletConnectPrompt($telegram, $chat_id, $lang);
                
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Please enter your new wallet address'
                ]);
            } catch (\Throwable $e) {
                error_log("Error in wallet_change: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif ($data === 'wallet_disconnect') {
            try {
                // Disconnect wallet
                $wallet_command = new \TelegramBot\Commands\WalletCommand();
                $wallet_command->disconnectWallet($telegram, $chat_id, $user->getId(), $lang);
                
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Wallet disconnected'
                ]);
            } catch (\Throwable $e) {
                error_log("Error in wallet_disconnect: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => 'Error occurred. Please try again.',
                    'show_alert' => true
                ]);
            }
        } elseif (str_starts_with($data, 'payment_stars:')) {
            // User selected Telegram Stars payment
            // Extract space_id from callback_data: "payment_stars:123"
            $parts = explode(':', $data, 2);
            $space_id_str = isset($parts[1]) ? trim($parts[1]) : '';
            $space_id = !empty($space_id_str) ? (int)$space_id_str : 0;
            
            // Validate space_id
            if (empty($space_id_str) || $space_id <= 0) {
                error_log("Payment stars: Invalid space_id extracted from callback_data: '{$data}' -> '{$space_id_str}' -> {$space_id}");
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_invalid_space', $lang)
                ]);
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_invalid_space', $lang)
                ]);
                exit();
            }
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $parking_service = new \TelegramBot\Services\ParkingService();
            
            // Try to get user data with retry (in case link was just completed)
            $user_data = null;
            $max_retries = 3;
            for ($i = 0; $i < $max_retries; $i++) {
                $user_data = $db->getTelegramUserByTelegramId($user->getId());
                if ($user_data && !empty($user_data['license_plate'])) {
                    break;
                }
                if ($i < $max_retries - 1) {
                    // Wait a bit before retry (in case link was just completed)
                    usleep(500000); // 0.5 seconds
                }
            }
            
            if (!$user_data || empty($user_data['license_plate'])) {
                error_log("Payment stars: User not linked after {$max_retries} retries for user {$user->getId()}");
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => \TelegramBot\Services\LanguageService::t('not_linked', $lang)
                ]);
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId()
                ]);
                exit();
            }
            
            $reserve_command = new \TelegramBot\Commands\ReserveCommand();
            $reserve_command->handlePaymentMethodSelection($telegram, $chat_id, $parking_service, $space_id, 'stars', $user_data, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'payment_ton:')) {
            // User selected TON wallet payment
            // Extract space_id from callback_data: "payment_ton:123"
            $parts = explode(':', $data, 2);
            $space_id_str = isset($parts[1]) ? trim($parts[1]) : '';
            $space_id = !empty($space_id_str) ? (int)$space_id_str : 0;
            
            error_log("Payment TON callback: data='{$data}', space_id_str='{$space_id_str}', space_id={$space_id}");
            if (function_exists('bot_log')) {
                bot_log("Payment TON callback", ['data' => $data, 'space_id_str' => $space_id_str, 'space_id' => $space_id, 'parts' => $parts]);
            }
            
            // Validate space_id
            if (empty($space_id_str) || $space_id <= 0) {
                $error_msg = "Payment TON: Invalid space_id extracted from callback_data: '{$data}' -> '{$space_id_str}' -> {$space_id}";
                error_log($error_msg);
                if (function_exists('bot_log')) {
                    bot_log($error_msg, ['data' => $data, 'space_id_str' => $space_id_str, 'space_id' => $space_id]);
                }
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_invalid_space', $lang)
                ]);
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId(),
                    'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_invalid_space', $lang)
                ]);
                exit();
            }
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $parking_service = new \TelegramBot\Services\ParkingService();
            
            // Try to get user data with retry (in case link was just completed)
            $user_data = null;
            $max_retries = 3;
            for ($i = 0; $i < $max_retries; $i++) {
                $user_data = $db->getTelegramUserByTelegramId($user->getId());
                if ($user_data && !empty($user_data['license_plate'])) {
                    break;
                }
                if ($i < $max_retries - 1) {
                    // Wait a bit before retry (in case link was just completed)
                    usleep(500000); // 0.5 seconds
                }
            }
            
            if (!$user_data || empty($user_data['license_plate'])) {
                error_log("Payment TON: User not linked after {$max_retries} retries for user {$user->getId()}");
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => \TelegramBot\Services\LanguageService::t('not_linked', $lang)
                ]);
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback_query->getId()
                ]);
                exit();
            }
            
            error_log("Webhook: About to call handlePaymentMethodSelection with space_id={$space_id}, payment_method=ton");
            if (function_exists('bot_log')) {
                bot_log("Webhook: About to call handlePaymentMethodSelection", ['space_id' => $space_id, 'payment_method' => 'ton']);
            }
            
            $reserve_command = new \TelegramBot\Commands\ReserveCommand();
            $reserve_command->handlePaymentMethodSelection($telegram, $chat_id, $parking_service, $space_id, 'ton', $user_data, $lang);
            
            error_log("Webhook: handlePaymentMethodSelection completed for space_id={$space_id}");
            if (function_exists('bot_log')) {
                bot_log("Webhook: handlePaymentMethodSelection completed", ['space_id' => $space_id]);
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'payment_sent:')) {
            // User sent payment, ask for transaction hash
            // Extract space_id from callback_data: "payment_sent:123"
            $parts = explode(':', $data, 2);
            $space_id_str = isset($parts[1]) ? trim($parts[1]) : '';
            $space_id = !empty($space_id_str) ? (int)$space_id_str : 0;
            
            $text = \TelegramBot\Services\LanguageService::t('reserve_payment_enter_tx', $lang);
            
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ]);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId(),
                'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_waiting_tx', $lang)
            ]);
        } elseif (str_starts_with($data, 'payment_manual:')) {
            // User wants manual payment instead of Wallet Pay
            $parts = explode(':', $data);
            $space_id = (int)$parts[1];
            $order_id = $parts[2] ?? null;
            
            // Show manual payment instructions
            $parking_service = new \TelegramBot\Services\ParkingService();
            $space = $parking_service->getSpaceById($space_id);
            
            if ($space && isset($space['zone'])) {
                $recipient_address = defined('TON_RECIPIENT_ADDRESS') && !empty(TON_RECIPIENT_ADDRESS) ? TON_RECIPIENT_ADDRESS : '';
                if (empty($recipient_address)) {
                    error_log("Webhook: TON_RECIPIENT_ADDRESS not configured");
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_invalid_space', $lang)
                    ]);
                    exit();
                }
                // Always load zone data from database
                $db_service = new \TelegramBot\Services\DatabaseService();
                $db = $db_service->getDatabase();
                $zone_data = $db->getZoneBySpaceId($space_id);
                
                if (!$zone_data || !isset($zone_data['hourly_rate'])) {
                    error_log("Webhook: Zone data not found for space_id: {$space_id}");
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_invalid_space', $lang)
                    ]);
                    exit();
                }
                
                $amount_ton = (float)$zone_data['hourly_rate'];
                if (!isset($zone_data['name'])) {
                    error_log("Webhook: Zone name not found for space_id: {$space_id}");
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_invalid_space', $lang)
                    ]);
                    exit();
                }
                $zone_name = $zone_data['name'];
                
                $text = \TelegramBot\Services\LanguageService::t('reserve_payment_instructions', $lang, [
                    'zone_name' => $zone_name,
                    'space_id' => $space_id,
                    'amount_ton' => $amount_ton,
                    'recipient_address' => $recipient_address,
                    'user_wallet' => $user_data['ton_wallet_address'] ?? 'N/A'
                ]);
                
                $text .= "\n\n" . \TelegramBot\Services\LanguageService::t('reserve_payment_auto_verify', $lang);
                
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_sent', $lang),
                                'callback_data' => "payment_sent:{$space_id}"
                            ]
                        ],
                        [
                            [
                                'text' => \TelegramBot\Services\LanguageService::t('reserve_cancel', $lang),
                                'callback_data' => 'reserve_cancel'
                            ]
                        ]
                    ]
                ];
                
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard)
                ]);
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'reserve_cancel') {
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => \TelegramBot\Services\LanguageService::t('reserve_cancelled', $lang)
            ]);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'lang_')) {
            // Language selection callback
            $selected_lang = substr($data, 5); // Remove 'lang_' prefix
            
            if (in_array($selected_lang, ['en', 'sr', 'de', 'fr', 'ar'])) {
                $result = \TelegramBot\Services\LanguageService::updateUserLanguage($user->getId(), $selected_lang);
                
                if ($result['success']) {
                    $lang_names = [
                        'en' => 'English',
                        'sr' => 'Srpski',
                        'de' => 'Deutsch',
                        'fr' => 'Français',
                        'ar' => 'العربية'
                    ];
                    
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => \TelegramBot\Services\LanguageService::t('language_changed', $selected_lang, [
                            'language' => $lang_names[$selected_lang]
                        ])
                    ]);
                } else {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "❌ " . ($result['error'] ?? 'Failed to update language')
                    ]);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'pref_free:')) {
            // Handle free spaces preference toggle
            $value = substr($data, 10); // Remove 'pref_free:' prefix
            $is_on = ($value === 'on');
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_free_spaces' => $is_on]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Free spaces notification " . ($is_on ? 'enabled' : 'disabled') . "!"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'pref_expiry:')) {
            // Handle expiry preference toggle
            $value = substr($data, 12); // Remove 'pref_expiry:' prefix
            $is_on = ($value === 'on');
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_reservation_expiry' => $is_on ? 1 : 0]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Reservation expiry notification " . ($is_on ? 'enabled' : 'disabled') . "!"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'pref_select_street') {
            // Show street selection
            $parking_service = new \TelegramBot\Services\ParkingService();
            $streets = $parking_service->getUniqueStreets();
            
            if (empty($streets)) {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ No streets available."
                ]);
            } else {
                $text = "🛣️ Select a street for notifications:\n\n";
                $keyboard = ['inline_keyboard' => []];
                $row = [];
                
                foreach ($streets as $street_name => $space_count) {
                    $row[] = [
                        'text' => "🛣️ {$street_name} ({$space_count})",
                        'callback_data' => 'pref_street:' . urlencode($street_name)
                    ];
                    
                    if (count($row) >= 2) {
                        $keyboard['inline_keyboard'][] = $row;
                        $row = [];
                    }
                }
                
                if (!empty($row)) {
                    $keyboard['inline_keyboard'][] = $row;
                }
                
                $keyboard['inline_keyboard'][] = [[
                    'text' => '🏠 Back to Preferences',
                    'callback_data' => 'menu_preferences'
                ]];
                
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'reply_markup' => json_encode($keyboard)
                ]);
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'pref_street:')) {
            // Handle street selection
            $street_name = urldecode(substr($data, 12)); // Remove 'pref_street:' prefix
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_street' => $street_name]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Street notification set to: {$street_name}"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'pref_select_zone') {
            // Show zone selection
            $parking_service = new \TelegramBot\Services\ParkingService();
            $zones = $parking_service->getZones();
            
            if (empty($zones)) {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ No zones available."
                ]);
            } else {
                $text = "📍 Select a zone for notifications:\n\n";
                $keyboard = ['inline_keyboard' => []];
                
                foreach ($zones as $zone) {
                    $keyboard['inline_keyboard'][] = [[
                        'text' => "📍 {$zone['name']} (ID: {$zone['id']})",
                        'callback_data' => 'pref_zone:' . $zone['id']
                    ]];
                }
                
                $keyboard['inline_keyboard'][] = [[
                    'text' => '🏠 Back to Preferences',
                    'callback_data' => 'menu_preferences'
                ]];
                
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'reply_markup' => json_encode($keyboard)
                ]);
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'pref_zone:')) {
            // Handle zone selection
            $zone_id = (int)substr($data, 10); // Remove 'pref_zone:' prefix
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_zone' => $zone_id]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Zone notification set to: Zone ID {$zone_id}"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'pref_select_space') {
            // Show street selection first, then spaces
            $parking_service = new \TelegramBot\Services\ParkingService();
            $streets = $parking_service->getUniqueStreets();
            
            if (empty($streets)) {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ No streets available."
                ]);
            } else {
                $text = "🛣️ First select a street, then choose a space:\n\n";
                $keyboard = ['inline_keyboard' => []];
                $row = [];
                
                foreach ($streets as $street_name => $space_count) {
                    $row[] = [
                        'text' => "🛣️ {$street_name} ({$space_count})",
                        'callback_data' => 'pref_space_street:' . urlencode($street_name)
                    ];
                    
                    if (count($row) >= 2) {
                        $keyboard['inline_keyboard'][] = $row;
                        $row = [];
                    }
                }
                
                if (!empty($row)) {
                    $keyboard['inline_keyboard'][] = $row;
                }
                
                $keyboard['inline_keyboard'][] = [[
                    'text' => '🏠 Back to Preferences',
                    'callback_data' => 'menu_preferences'
                ]];
                
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'reply_markup' => json_encode($keyboard)
                ]);
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'pref_space_street:')) {
            // Show spaces for selected street
            $street_name = urldecode(substr($data, 19)); // Remove 'pref_space_street:' prefix
            $parking_service = new \TelegramBot\Services\ParkingService();
            $spaces = $parking_service->getSpacesByStreet($street_name);
            
            if (empty($spaces)) {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ No spaces found on {$street_name}."
                ]);
            } else {
                $text = "🅿️ Select a space on {$street_name}:\n\n";
                $keyboard = ['inline_keyboard' => []];
                
                foreach ($spaces as $space) {
                    $space_name = !empty($space['sensor_name']) ? $space['sensor_name'] : "Space #{$space['id']}";
                    $keyboard['inline_keyboard'][] = [[
                        'text' => "🅿️ {$space_name}",
                        'callback_data' => 'pref_space:' . $space['id']
                    ]];
                }
                
                $keyboard['inline_keyboard'][] = [[
                    'text' => '🏠 Back to Preferences',
                    'callback_data' => 'menu_preferences'
                ]];
                
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'reply_markup' => json_encode($keyboard)
                ]);
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'pref_space:')) {
            // Handle space selection
            $space_id = (int)substr($data, 11); // Remove 'pref_space:' prefix
            
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_specific_space' => $space_id]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Space notification set to: Space ID {$space_id}"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'pref_clear_space') {
            // Clear space preference
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_specific_space' => null]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Space notification cleared!"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'pref_clear_street') {
            // Clear street preference
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_street' => null]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Street notification cleared!"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'pref_clear_zone') {
            // Clear zone preference
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                $preferences = $db->getNotificationPreferences($user->getId());
                $update_data = array_merge([
                    'notify_free_spaces' => $preferences['notify_free_spaces'],
                    'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1,
                    'notify_specific_space' => $preferences['notify_specific_space'],
                    'notify_street' => $preferences['notify_street'],
                    'notify_zone' => $preferences['notify_zone']
                ], ['notify_zone' => null]);
                
                $result = $db->updateNotificationPreferences($user->getId(), $update_data);
                if ($result['success']) {
                    $telegram->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "✅ Zone notification cleared!"
                    ]);
                    // Refresh preferences display
                    $pref_command = new \TelegramBot\Commands\PreferencesCommand();
                    $pref_message = $create_command_message('/preferences');
                    $pref_command->handle($telegram, $pref_message);
                }
            }
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        }
        exit();
    }
    
    $message = $update->getMessage();
    if (!$message) {
        error_log('No message in update');
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'No message']);
        exit();
    }
    
    $text = $message->getText();
    $chat_id = $message->getChat()->getId();
    
    // Log received command
    if ($text) {
        error_log("Received command: {$text} from chat {$chat_id}");
    }
    
    // Handle empty messages or non-command messages
    if (!$text) {
        error_log('Empty message text');
        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'No text']);
        exit();
    }
    
    // Parse command (works for both direct commands and converted reply keyboard buttons)
    // If text starts with /, it's a command - parse and execute it
    if (str_starts_with($text, '/')) {
        // Command will be processed below
    } else {
        // Text doesn't start with / - check if it's wallet address, license plate, or reply keyboard button
        $db_service = new \TelegramBot\Services\DatabaseService();
        $db = $db_service->getDatabase();
        $user = $message->getFrom();
        $user_data = $db->getTelegramUserByTelegramId($user->getId());
        $lang = \TelegramBot\Services\LanguageService::getLanguage($user);
        
        $text_trimmed = trim($text);
        
        // FIRST: Check if it looks like a TON wallet address or transaction hash
        // TON addresses can be EQ, UQ, kQ, EQD, or 0: format
        // This check must come BEFORE reply keyboard check to avoid "Unknown button" error
        error_log("Checking if text is wallet address: '{$text_trimmed}' (length: " . strlen($text_trimmed) . ")");
        
        if (preg_match('/^(EQ|UQ|kQ|EQD|0:)[A-Za-z0-9_-]{46,48}$/', $text_trimmed) || preg_match('/^[A-Za-z0-9]{64}$/', $text_trimmed)) {
            error_log("Text matches wallet address or TX hash pattern");
            // Might be wallet address or TX hash
            // Try wallet address first (46-48 chars after prefix, TON addresses can vary)
            if (preg_match('/^(EQ|UQ|kQ|EQD|0:)[A-Za-z0-9_-]{46,48}$/', $text_trimmed)) {
                error_log("Text matches wallet address format, processing...");
                // Remove reply keyboard when processing wallet address
                $telegram->removeReplyKeyboard($chat_id);
                if ($user_data) {
                    $wallet_command = new \TelegramBot\Commands\WalletCommand();
                    $wallet_command->handleWalletAddress($telegram, $chat_id, $user->getId(), $text_trimmed, $lang);
                } else {
                    // User not linked, but still try to process wallet address
                    // This allows users to connect wallet even if not fully linked
                    error_log("User not linked, but processing wallet address anyway");
                    $wallet_command = new \TelegramBot\Commands\WalletCommand();
                    $wallet_command->handleWalletAddress($telegram, $chat_id, $user->getId(), $text_trimmed, $lang);
                }
                http_response_code(200);
                echo json_encode(['ok' => true]);
                exit();
            } else {
                // Might be transaction hash - try to verify payment
                // Check if user has pending payment
                if ($user_data) {
                    error_log("Text might be transaction hash, checking for pending payments");
                    $db_service = new \TelegramBot\Services\DatabaseService();
                    $db = $db_service->getDatabase();
                    
                    // Try to find recent pending payment for this user
                    $stmt = $db->prepare("
                        SELECT tp.*, ps.id as space_id 
                        FROM ton_payments tp
                        JOIN parking_spaces ps ON tp.parking_space_id = ps.id
                        WHERE tp.license_plate = ? 
                        AND tp.status = 'pending'
                        AND tp.created_at > datetime('now', '-1 hour')
                        ORDER BY tp.created_at DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$user_data['license_plate']]);
                    $pending_payment = $stmt->fetch(\PDO::FETCH_ASSOC);
                    
                    if ($pending_payment) {
                        // Found pending payment, verify this transaction
                        $space_id = $pending_payment['space_id'];
                        $parking_service = new \TelegramBot\Services\ParkingService();
                        $reserve_command = new \TelegramBot\Commands\ReserveCommand();
                        
                        $reserve_command->handlePaymentVerification(
                            $telegram, 
                            $chat_id, 
                            $parking_service, 
                            $space_id, 
                            $text_trimmed, // tx_hash
                            $user_data, 
                            $lang
                        );
                    } else {
                        // No pending payment found
                        $telegram->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => \TelegramBot\Services\LanguageService::t('reserve_payment_tx_unknown', $lang)
                        ]);
                    }
                    
                    http_response_code(200);
                    echo json_encode(['ok' => true]);
                    exit();
                } else {
                    // Not a transaction hash either - might be wallet address with different format
                    // Try to process as wallet address anyway
                    error_log("Text doesn't match exact wallet format, but might be valid - trying to process");
                    $telegram->removeReplyKeyboard($chat_id);
                    $wallet_command = new \TelegramBot\Commands\WalletCommand();
                    $wallet_command->handleWalletAddress($telegram, $chat_id, $user->getId(), $text_trimmed, $lang);
                    http_response_code(200);
                    echo json_encode(['ok' => true]);
                    exit();
                }
            }
        }
        
        // SECOND: Check if user is not linked and text looks like a license plate
        if (!$user_data && preg_match('/^[A-Z0-9\-\s]{2,10}$/i', $text_trimmed)) {
                error_log("User not linked, text looks like license plate: '{$text_trimmed}'");
                // Remove reply keyboard when processing license plate
                $telegram->removeReplyKeyboard($chat_id);
                // Try to link account with this license plate
                // Create a new message with /link command by modifying the message object
                $link_text = "/link " . $text_trimmed;
                // Create new message data
                $message_data = [
                    'message_id' => time(),
                    'from' => [
                        'id' => $user->getId(),
                        'is_bot' => false,
                        'first_name' => $user->getFirstName() ?? 'User',
                        'username' => $user->getUsername()
                    ],
                    'chat' => [
                        'id' => $chat_id,
                        'type' => 'private'
                    ],
                    'date' => time(),
                    'text' => $link_text
                ];
                $link_message = new TelegramMessage($message_data);
                $link_command = new \TelegramBot\Commands\LinkCommand();
                $link_command->handle($telegram, $link_message);
                http_response_code(200);
                echo json_encode(['ok' => true]);
                exit();
        }
        
        // THIRD: Check if message is a button click (from reply keyboard)
        // Only check this AFTER wallet address and license plate checks
        // If not starting with /, try to convert button text to command
        $command_from_button = \TelegramBot\Services\KeyboardService::getCommandFromButton($text, $lang);
        
        if ($command_from_button) {
            // Convert button text to command
            $original_text = $text;
            $text = $command_from_button;
            error_log("Reply keyboard button clicked, original text: '{$original_text}', converted to command: '{$command_from_button}', new text: '{$text}'");
            // Update message object with new text so commands receive correct text
            if (isset($message->data['text'])) {
                $message->data['text'] = $text;
            }
            // Remove reply keyboard after use
            $telegram->removeReplyKeyboard($chat_id);
            // Continue to command processing below - don't exit here
        } else {
            // Button text not recognized - send error message
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => "❌ Unknown button: '{$text}'. Please use /help for available commands."
            ]);
            http_response_code(200);
            echo json_encode(['ok' => true]);
            exit();
        }
    }
    
    // Parse command
    $parts = explode(' ', $text, 2);
    $command_raw = $parts[0];
    
    // Remove leading '/' and handle bot mentions (e.g., /start@botname -> start)
    $command = ltrim($command_raw, '/');
    if (strpos($command, '@') !== false) {
        $command = explode('@', $command)[0];
    }
    
    // Convert to lowercase for case-insensitive matching
    $command = strtolower(trim($command));
    
    error_log("Parsed command: '{$command}' from raw: '{$command_raw}', full text: '{$text}'");
    
    // Route to command handler
    $command_class = null;
    
    switch ($command) {
        case 'start':
            $command_class = new \TelegramBot\Commands\StartCommand();
            break;
        case 'link':
            $command_class = new \TelegramBot\Commands\LinkCommand();
            break;
        case 'status':
            $command_class = new \TelegramBot\Commands\StatusCommand();
            break;
        case 'spaces':
            $command_class = new \TelegramBot\Commands\SpacesCommand();
            break;
        case 'weather':
            $command_class = new \TelegramBot\Commands\WeatherCommand();
            break;
        case 'preferences':
            $command_class = new \TelegramBot\Commands\PreferencesCommand();
            break;
        case 'reserve':
            $command_class = new \TelegramBot\Commands\ReserveCommand();
            break;
        case 'help':
            $command_class = new \TelegramBot\Commands\HelpCommand();
            break;
        case 'lang':
        case 'language':
            $command_class = new \TelegramBot\Commands\LangCommand();
            break;
        case 'wallet':
            $command_class = new \TelegramBot\Commands\WalletCommand();
            break;
        case 'app':
            // Open web app command
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '🌐 Otvori Web Aplikaciju',
                            'web_app' => ['url' => WEB_APP_URL]
                        ]
                    ]
                ]
            ];
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => '🌐 Kliknite na dugme ispod da otvorite web aplikaciju:',
                'reply_markup' => json_encode($keyboard)
            ]);
            exit();
        default:
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => "❌ Nepoznata komanda. Koristite /help za listu komandi."
            ]);
            exit();
    }
    
    if ($command_class) {
        error_log("Executing command: {$command}, message text: '{$text}'");
        try {
            // Log message object details for debugging
            $message_text = $message->getText();
            error_log("Message text before handle: '{$message_text}'");
            $command_class->handle($telegram, $message);
            error_log("Command {$command} executed successfully");
        } catch (\Throwable $cmd_error) {
            error_log("Command {$command} failed: " . $cmd_error->getMessage());
            error_log("Stack trace: " . $cmd_error->getTraceAsString());
            error_log("File: " . $cmd_error->getFile() . " Line: " . $cmd_error->getLine());
            try {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error executing command {$command}: " . $cmd_error->getMessage() . "\n\nFile: " . basename($cmd_error->getFile()) . " Line: " . $cmd_error->getLine()
                ]);
            } catch (\Exception $send_error) {
                error_log("Failed to send error message: " . $send_error->getMessage());
            }
        }
    } else {
        error_log("No command class found for: {$command}");
        $telegram->sendMessage([
            'chat_id' => $chat_id,
            'text' => "❌ Command '{$command}' not found. Use /help for available commands."
        ]);
    }
    
    // Always return 200 OK to Telegram
    ob_clean();
    http_response_code(200);
    echo json_encode(['ok' => true]);
    
} catch (Exception $e) {
    error_log('Telegram webhook error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    
    // Send error response to Telegram for debugging
    try {
        if (isset($telegram) && isset($chat_id)) {
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => '❌ Greška u botu. Molimo kontaktirajte administratora.'
            ]);
        }
    } catch (Exception $send_error) {
        error_log('Failed to send error message: ' . $send_error->getMessage());
    }
    
    // Always return 200 OK to Telegram (even on error)
    // Telegram will retry if we return 5xx, but we want to log errors
    ob_clean();
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $t) {
    // Catch any other errors (ParseError, TypeError, etc.)
    error_log('Fatal error in webhook: ' . $t->getMessage());
    error_log('Stack trace: ' . $t->getTraceAsString());
    ob_clean();
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'Fatal error']);
}
?>
