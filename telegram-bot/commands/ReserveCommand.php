<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\DatabaseService;
use TelegramBot\Services\ParkingService;
use TelegramBot\Services\LanguageService;

class ReserveCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $user = $message->getFrom();
        $text = $message->getText();
        $lang = LanguageService::getLanguage($user);
        
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        $parking_service = new ParkingService();
        
        // Get user
        $user_data = $db->getTelegramUserByTelegramId($user_id);
        if (!$user_data) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('not_linked', $lang)
            ]);
            return;
        }
        
        // Parse command: /reserve <space_id> (legacy support) or just /reserve (new interactive)
        $parts = explode(' ', $text, 2);
        if (count($parts) >= 2) {
            // Legacy: /reserve <space_id>
            $space_id = (int)trim($parts[1]);
            $this->reserveSpaceById($bot, $chat_id, $parking_service, $space_id, $user_data, $lang);
        } else {
            // New: Show streets list with inline keyboard
            $this->showStreetsForReservation($bot, $chat_id, $parking_service, $lang);
        }
    }
    
    public function showStreetsForReservation($bot, $chat_id, $parking_service, $lang) {
        $streets = $parking_service->getUniqueStreets();
        
        if (empty($streets)) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_no_streets', $lang)
            ]);
            return;
        }
        
        $text = LanguageService::t('reserve_select_street', $lang);
        
        // Create inline keyboard with streets
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        $count = 0;
        
        foreach ($streets as $street_name => $space_count) {
            $count++;
            $button_text = "🛣️ {$street_name} ({$space_count})";
            $row[] = [
                'text' => $button_text,
                'callback_data' => "reserve_street:" . urlencode($street_name)
            ];
            
            // Add 2 buttons per row
            if (count($row) >= 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        
        // Add remaining buttons
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    
    public function showSpacesForReservation($bot, $chat_id, $parking_service, $street_name, $lang) {
        $spaces = $parking_service->getSpacesByStreet($street_name);
        
        if (empty($spaces)) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_no_spaces_street', $lang, ['street' => $street_name])
            ]);
            return;
        }
        
        $text = LanguageService::t('reserve_select_space', $lang, ['street' => $street_name]);
        
        // Create inline keyboard with parking spaces
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        
        foreach ($spaces as $space) {
            $space_name = !empty($space['sensor_name']) ? $space['sensor_name'] : "Space #{$space['id']}";
            $button_text = "🅿️ {$space_name}";
            
            // Truncate if too long
            if (mb_strlen($button_text) > 30) {
                $button_text = mb_substr($button_text, 0, 27) . '...';
            }
            
            $row[] = [
                'text' => $button_text,
                'callback_data' => "reserve_space:{$space['id']}"
            ];
            
            // Add 1 button per row (spaces can have long names)
            $keyboard['inline_keyboard'][] = $row;
            $row = [];
        }
        
        // Add back button
        $keyboard['inline_keyboard'][] = [[
            'text' => LanguageService::t('back', $lang),
            'callback_data' => 'reserve_back'
        ]];
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    
    private function reserveSpaceById($bot, $chat_id, $parking_service, $space_id, $user_data, $lang) {
        $space = $parking_service->getSpaceById($space_id);
        
        if (!$space) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_not_found', $lang, ['space_id' => $space_id])
            ]);
            return;
        }
        
        if ($space['status'] !== 'vacant') {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_not_vacant', $lang, [
                    'space_id' => $space_id,
                    'status' => $space['status']
                ])
            ]);
            return;
        }
        
        // Check if premium zone requires payment
        // Get zone info directly from database to ensure we have correct data
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        $zone = $db->getZoneBySpaceId($space_id);
        
        $is_premium = false;
        if ($zone && isset($zone['is_premium'])) {
            // Check if is_premium is 1, true, or '1'
            $is_premium = ($zone['is_premium'] == 1 || $zone['is_premium'] === true || $zone['is_premium'] === '1');
            error_log("ReserveCommand: Zone found for space {$space_id}, is_premium: " . var_export($zone['is_premium'], true) . ", result: " . ($is_premium ? 'true' : 'false'));
        } elseif (isset($space['zone']) && isset($space['zone']['is_premium'])) {
            // Fallback to space zone data
            $is_premium = ($space['zone']['is_premium'] == 1 || $space['zone']['is_premium'] === true || $space['zone']['is_premium'] === '1');
            error_log("ReserveCommand: Using space zone data for space {$space_id}, is_premium: " . var_export($space['zone']['is_premium'], true) . ", result: " . ($is_premium ? 'true' : 'false'));
        } else {
            error_log("ReserveCommand: No zone found for space {$space_id}, allowing free reservation");
        }
        
        if ($is_premium) {
            // Premium zone requires payment - show payment options
            // Ensure zone data is in space array
            if (!isset($space['zone']) && $zone) {
                $space['zone'] = [
                    'id' => $zone['id'],
                    'name' => $zone['name'] ?? 'Premium Zone',
                    'is_premium' => true,
                    'hourly_rate' => $zone['hourly_rate'] ?? 2.0
                ];
            }
            error_log("ReserveCommand: Premium zone detected for space {$space_id}, showing payment options");
            $this->showPaymentOptions($bot, $chat_id, $space, $user_data, $lang);
            return;
        }
        
        error_log("ReserveCommand: Non-premium zone for space {$space_id}, allowing free reservation");
        
        // Reserve space
        $success = $parking_service->reserveSpace($space_id, $user_data['license_plate']);
        
        if ($success) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_success', $lang, [
                    'space_id' => $space_id,
                    'license_plate' => $user_data['license_plate']
                ])
            ]);
        } else {
            // Get more detailed error message
            $error_msg = LanguageService::t('reserve_failed', $lang);
            
            // Try to get error from logs or provide more context
            error_log("ReserveCommand: Failed to reserve space {$space_id} for license plate {$user_data['license_plate']}");
            
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => $error_msg . "\n\n" . LanguageService::t('reserve_failed_help', $lang) ?? 
                         "Please check if the space is still available and try again."
            ]);
        }
    }
    
    private function showPaymentOptions($bot, $chat_id, $space, $user_data, $lang) {
        $zone = $space['zone'];
        $hourly_rate = $zone['hourly_rate'] ?? 2.0;
        $amount_ton = $hourly_rate;
        $space_id = $space['id'];
        $has_wallet = !empty($user_data['ton_wallet_address']);
        
        $text = LanguageService::t('reserve_payment_options', $lang, [
            'zone_name' => $zone['name'],
            'space_id' => $space_id,
            'amount_ton' => $amount_ton
        ]);
        
        $keyboard = [
            'inline_keyboard' => []
        ];
        
        // Always show Telegram Stars option (easiest)
        $keyboard['inline_keyboard'][] = [
            [
                'text' => LanguageService::t('payment_option_stars', $lang),
                'callback_data' => "payment_stars:{$space_id}"
            ]
        ];
        
        // Show TON wallet option if user has wallet, or setup option if not
        if ($has_wallet) {
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => LanguageService::t('payment_option_ton', $lang),
                    'callback_data' => "payment_ton:{$space_id}"
                ]
            ];
        } else {
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => LanguageService::t('payment_option_ton_setup', $lang),
                    'callback_data' => 'wallet_setup_from_reserve'
                ]
            ];
        }
        
        $keyboard['inline_keyboard'][] = [
            [
                'text' => LanguageService::t('reserve_cancel', $lang),
                'callback_data' => 'reserve_cancel'
            ]
        ];
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    
    private function showPaymentInstructions($bot, $chat_id, $space, $user_data, $lang) {
        $zone = $space['zone'];
        $hourly_rate = $zone['hourly_rate'] ?? 2.0;
        $amount_ton = $hourly_rate;
        $space_id = $space['id'];
        
        // Use Telegram Stars for payment
        require_once __DIR__ . '/../services/TelegramStarsService.php';
        $stars_service = new \TelegramBot\Services\TelegramStarsService();
        
        // Send invoice using Telegram Stars
        $result = $stars_service->sendInvoice(
            $chat_id,
            $space_id,
            $zone['name'],
            $amount_ton,
            $user_data['license_plate'],
            $lang
        );
        
        if ($result['success']) {
            // Invoice sent successfully - Telegram will show payment interface
            // The payment will be handled via webhook (pre_checkout_query and successful_payment)
            error_log("Telegram Stars invoice sent successfully for space {$space_id}");
        } else {
            // Failed to send invoice - show error message
            $error_text = LanguageService::t('stars_invoice_error', $lang, [
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => $error_text,
                'parse_mode' => 'Markdown'
            ]);
            
            error_log("Failed to send Telegram Stars invoice: " . ($result['error'] ?? 'Unknown error'));
        }
    }
    
    private function showTonPaymentInstructions($bot, $chat_id, $space, $user_data, $lang) {
        $zone = $space['zone'];
        $hourly_rate = $zone['hourly_rate'] ?? 2.0;
        $amount_ton = $hourly_rate;
        $space_id = $space['id'];
        
        // Use Telegram Bot API invoice for TON payment
        require_once __DIR__ . '/../services/TelegramStarsService.php';
        $payment_service = new \TelegramBot\Services\TelegramStarsService();
        
        // Send TON invoice
        $result = $payment_service->sendTonInvoice(
            $chat_id,
            $space_id,
            $zone['name'],
            $amount_ton,
            $user_data['license_plate'],
            $lang
        );
        
        if ($result['success']) {
            // Invoice sent successfully - Telegram will show payment interface
            // The payment will be handled via webhook (pre_checkout_query and successful_payment)
            error_log("Telegram TON invoice sent successfully for space {$space_id}");
        } else {
            // Failed to send invoice - show error message
            $error_text = LanguageService::t('ton_invoice_error', $lang, [
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => $error_text,
                'parse_mode' => 'Markdown'
            ]);
            
            error_log("Failed to send Telegram TON invoice: " . ($result['error'] ?? 'Unknown error'));
        }
    }
    
    public function handlePaymentMethodSelection($bot, $chat_id, $parking_service, $space_id, $payment_method, $user_data, $lang) {
        $space = $parking_service->getSpaceById($space_id);
        if (!$space || !isset($space['zone']) || !$space['zone']['is_premium']) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        if ($payment_method === 'stars') {
            // Use Telegram Stars
            $this->showPaymentInstructions($bot, $chat_id, $space, $user_data, $lang);
        } elseif ($payment_method === 'ton') {
            // Use TON wallet
            if (empty($user_data['ton_wallet_address'])) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('reserve_premium_no_wallet', $lang, [
                        'zone_name' => $space['zone']['name'],
                        'hourly_rate' => $space['zone']['hourly_rate']
                    ])
                ]);
                return;
            }
            $this->showTonPaymentInstructions($bot, $chat_id, $space, $user_data, $lang);
        }
    }
    
    public function handlePaymentVerification($bot, $chat_id, $parking_service, $space_id, $tx_hash, $user_data, $lang) {
        require_once __DIR__ . '/../config.php';
        
        $space = $parking_service->getSpaceById($space_id);
        if (!$space || !isset($space['zone']) || !$space['zone']['is_premium']) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        $hourly_rate = $space['zone']['hourly_rate'] ?? 2.0;
        $amount_nano = (int)($hourly_rate * 1000000000);
        
        // Show "verifying" message
        $verifying_msg = $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => LanguageService::t('reserve_payment_verifying', $lang)
        ]);
        
        // Verify payment via API (which now checks blockchain)
        $api_url = API_BASE_URL . '/api/verify-ton-payment.php';
        $payment_data = [
            'space_id' => $space_id,
            'tx_hash' => $tx_hash,
            'license_plate' => $user_data['license_plate'],
            'amount_nano' => $amount_nano
        ];
        
        // Get API key from environment or config
        $api_key = getenv('WEB_APP_API_KEY') ?: getenv('BOT_API_KEY') ?: '';
        
        $headers = ['Content-Type: application/json'];
        if (!empty($api_key)) {
            $headers[] = 'X-API-Key: ' . $api_key;
        }
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds timeout
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Delete verifying message
        if (isset($verifying_msg['result']['message_id'])) {
            $bot->deleteMessage([
                'chat_id' => $chat_id,
                'message_id' => $verifying_msg['result']['message_id']
            ]);
        }
        
        if ($http_code !== 200) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_verification_failed', $lang)
            ]);
            return;
        }
        
        $result = json_decode($response, true);
        if (!$result || !$result['success']) {
            $error_msg = $result['error'] ?? 'Unknown error';
            
            // If transaction not found, suggest waiting
            if (strpos($error_msg, 'not found') !== false || strpos($error_msg, 'not verified') !== false) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('reserve_payment_not_found', $lang) . "\n\n" . 
                             LanguageService::t('reserve_payment_wait_retry', $lang)
                ]);
            } else {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('reserve_payment_verification_failed', $lang, [
                        'error' => $error_msg
                    ])
                ]);
            }
            return;
        }
        
        // Payment verified - get tx_hash from result
        $verified_tx_hash = $result['tx_hash'] ?? $tx_hash;
        
        // Verify payment exists and is verified in database before reserving
        $db_service = new \TelegramBot\Services\DatabaseService();
        $db = $db_service->getDatabase();
        
        $stmt = $db->prepare("
            SELECT * FROM ton_payments 
            WHERE payment_tx_hash = ? 
            AND parking_space_id = ?
            AND status = 'verified'
            LIMIT 1
        ");
        $stmt->bindValue(1, $verified_tx_hash);
        $stmt->bindValue(2, $space_id);
        $result = $stmt->execute();
        $verified_payment = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$verified_payment) {
            // Payment not verified in database - send error
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('payment_not_verified', $lang) ?? 
                         "Payment verification failed. Please contact support."
            ]);
            error_log("Payment not verified in database for space {$space_id}, tx_hash: {$verified_tx_hash}");
            return;
        }
        
        // Payment is verified - now reserve the space with payment_tx_hash
        $success = $parking_service->reserveSpace($space_id, $user_data['license_plate'], $verified_tx_hash);
        
        if ($success) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_success_premium', $lang, [
                    'space_id' => $space_id,
                    'license_plate' => $user_data['license_plate'],
                    'amount_ton' => $hourly_rate
                ]),
                'parse_mode' => 'Markdown'
            ]);
        } else {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_failed', $lang)
            ]);
        }
    }
    
    public function handleReservation($bot, $chat_id, $parking_service, $space_id, $user_data, $lang) {
        $this->reserveSpaceById($bot, $chat_id, $parking_service, $space_id, $user_data, $lang);
    }
}

