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
                
                $user_data = $db->getTelegramUserByTelegramId($user->getId());
                if ($user_data && $user_data['license_plate']) {
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
                        // Send success message based on payment type
                        if ($payment_type === 'ton') {
                            $success_text = \TelegramBot\Services\LanguageService::t('ton_payment_success', $lang, [
                                'zone_name' => $payload_data['zone_name'] ?? 'Premium Zone',
                                'space_id' => $space_id,
                                'license_plate' => $user_data['license_plate']
                            ]);
                        } else {
                            $success_text = \TelegramBot\Services\LanguageService::t('stars_payment_success', $lang, [
                                'zone_name' => $payload_data['zone_name'] ?? 'Premium Zone',
                                'space_id' => $space_id,
                                'license_plate' => $user_data['license_plate']
                            ]);
                        }
                        
                        $telegram->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => $success_text,
                            'parse_mode' => 'Markdown'
                        ]);
                        
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
        
        // Get user language
        $lang = \TelegramBot\Services\LanguageService::getLanguage($user);
        
        if ($data === 'link_account') {
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
            $space_id = (int)substr($data, 14); // Remove 'reserve_space:' prefix
            
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
            // Show wallet setup from reserve flow
            $wallet_command = new \TelegramBot\Commands\WalletCommand();
            $wallet_command->handle($telegram, $callback_query->getMessage());
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'wallet_connect') {
            // Show wallet connect prompt
            $wallet_command = new \TelegramBot\Commands\WalletCommand();
            $wallet_command->showWalletConnectPrompt($telegram, $chat_id, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'wallet_change') {
            // Show wallet connect prompt for changing
            $wallet_command = new \TelegramBot\Commands\WalletCommand();
            $wallet_command->showWalletConnectPrompt($telegram, $chat_id, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif ($data === 'wallet_disconnect') {
            // Disconnect wallet
            $wallet_command = new \TelegramBot\Commands\WalletCommand();
            $wallet_command->disconnectWallet($telegram, $chat_id, $user->getId(), $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'payment_stars:')) {
            // User selected Telegram Stars payment
            $space_id = (int)substr($data, 15); // Remove 'payment_stars:' prefix
            
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
            $reserve_command->handlePaymentMethodSelection($telegram, $chat_id, $parking_service, $space_id, 'stars', $user_data, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'payment_ton:')) {
            // User selected TON wallet payment
            $space_id = (int)substr($data, 13); // Remove 'payment_ton:' prefix
            
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
            $reserve_command->handlePaymentMethodSelection($telegram, $chat_id, $parking_service, $space_id, 'ton', $user_data, $lang);
            
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback_query->getId()
            ]);
        } elseif (str_starts_with($data, 'payment_sent:')) {
            // User sent payment, ask for transaction hash
            $space_id = (int)substr($data, 13);
            
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
                $recipient_address = defined('TON_RECIPIENT_ADDRESS') ? TON_RECIPIENT_ADDRESS : 'YOUR_TON_WALLET_ADDRESS';
                $amount_ton = $space['zone']['hourly_rate'] ?? 2.0;
                
                $text = \TelegramBot\Services\LanguageService::t('reserve_payment_instructions', $lang, [
                    'zone_name' => $space['zone']['name'],
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
    
    // Check if message is a button click (from reply keyboard)
    // If not starting with /, try to convert button text to command
    if (!str_starts_with($text, '/')) {
        // Try to get command from button text
        $user = $message->getFrom();
        $lang = \TelegramBot\Services\LanguageService::getLanguage($user);
        $command_from_button = \TelegramBot\Services\KeyboardService::getCommandFromButton($text, $lang);
        
        if ($command_from_button) {
            // Convert button text to command
            $text = $command_from_button;
            error_log("Button clicked, converted to command: {$text}");
        } else {
            // Check if this might be a wallet address or transaction hash
            $db_service = new \TelegramBot\Services\DatabaseService();
            $db = $db_service->getDatabase();
            $user_data = $db->getTelegramUserByTelegramId($user->getId());
            
            if ($user_data) {
                // Check if it looks like a TON wallet address or transaction hash
                if (preg_match('/^(EQ|EQD|0:)[A-Za-z0-9_-]{48,}$/', $text) || preg_match('/^[A-Za-z0-9]{64}$/', $text)) {
                    // Might be wallet address or TX hash
                    // Try wallet address first
                    if (preg_match('/^(EQ|EQD|0:)[A-Za-z0-9_-]{48}$/', $text)) {
                        $wallet_command = new \TelegramBot\Commands\WalletCommand();
                        $wallet_command->handleWalletAddress($telegram, $chat_id, $user->getId(), $text, $lang);
                        http_response_code(200);
                        echo json_encode(['ok' => true]);
                        exit();
                    } else {
                        // Might be transaction hash - try to verify payment
                        // Check if user has pending payment
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
                        $stmt->bindValue(1, $user_data['license_plate']);
                        $result = $stmt->execute();
                        $pending_payment = $result->fetchArray(SQLITE3_ASSOC);
                        
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
                                $text, // tx_hash
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
                    }
                }
            }
            
            error_log('Message is not a command or button: ' . substr($text, 0, 50));
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'Not a command']);
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
    
    error_log("Parsed command: '{$command}' from raw: '{$command_raw}'");
    
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
        error_log("Executing command: {$command}");
        try {
            $command_class->handle($telegram, $message);
            error_log("Command {$command} executed successfully");
        } catch (\Throwable $cmd_error) {
            error_log("Command {$command} failed: " . $cmd_error->getMessage());
            error_log("Stack trace: " . $cmd_error->getTraceAsString());
            try {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error executing command: " . $cmd_error->getMessage()
                ]);
            } catch (\Exception $send_error) {
                error_log("Failed to send error message: " . $send_error->getMessage());
            }
        }
    } else {
        error_log("No command class found for: {$command}");
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
