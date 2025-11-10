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
        
        // Parse command: /reserve (always show interactive menu)
        // Legacy support removed - always show streets list
        $parts = explode(' ', $text, 2);
        if (count($parts) >= 2) {
            $space_id_param = trim($parts[1]);
            // If space_id is provided and valid, use it; otherwise show streets
            if (!empty($space_id_param) && is_numeric($space_id_param) && (int)$space_id_param > 0) {
                $space_id = (int)$space_id_param;
                error_log("ReserveCommand: Legacy format with space_id={$space_id}");
                $this->reserveSpaceById($bot, $chat_id, $parking_service, $space_id, $user_data, $lang);
            } else {
                // Invalid or empty space_id - show streets list
                error_log("ReserveCommand: Invalid space_id parameter '{$space_id_param}', showing streets list");
                $this->showStreetsForReservation($bot, $chat_id, $parking_service, $lang);
            }
        } else {
            // No parameters - show streets list with inline keyboard
            error_log("ReserveCommand: No parameters, showing streets list");
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
    
    public function reserveSpaceById($bot, $chat_id, $parking_service, $space_id, $user_data, $lang) {
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
        
        // Check if premium zone (same as web app: api/parking-spaces.php line 90)
        $is_premium = false;
        if ($zone && $zone['is_premium'] == 1) {
            $is_premium = true;
            error_log("ReserveCommand: Zone found for space {$space_id}, is_premium: 1 (premium zone)");
        } elseif (isset($space['zone']) && $space['zone']['is_premium'] == 1) {
            // Fallback to space zone data
            $is_premium = true;
            error_log("ReserveCommand: Using space zone data for space {$space_id}, is_premium: 1 (premium zone)");
        } else {
            error_log("ReserveCommand: No premium zone found for space {$space_id}, allowing free reservation");
        }
        
        if ($is_premium) {
            // Premium zone requires payment - show payment options
            // Ensure zone data is in space array
            if (!isset($space['zone']) && $zone) {
                if (!isset($zone['name']) || !isset($zone['hourly_rate'])) {
                    error_log("ReserveCommand: Zone data incomplete for space {$space_id}: " . json_encode($zone));
                    $bot->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
                    ]);
                    return;
                }
                $space['zone'] = [
                    'id' => $zone['id'],
                    'name' => $zone['name'],
                    'is_premium' => true,
                    'hourly_rate' => (float)$zone['hourly_rate']
                ];
            }
            error_log("ReserveCommand: Premium zone detected for space {$space_id}, showing payment options");
            // Ensure space has id
            if (!isset($space['id']) || empty($space['id'])) {
                $space['id'] = $space_id;
                error_log("ReserveCommand: Added space_id to space array: {$space_id}");
            }
            error_log("ReserveCommand: Space data before showPaymentOptions: " . json_encode(['id' => $space['id'] ?? 'MISSING', 'status' => $space['status'] ?? 'MISSING']));
            $this->showPaymentOptions($bot, $chat_id, $space, $user_data, $lang);
            return;
        }
        
        error_log("ReserveCommand: Non-premium zone for space {$space_id}, allowing free reservation");
        
        // Reserve space
        $success = $parking_service->reserveSpace($space_id, $user_data['license_plate']);
        
        if ($success) {
            // Get space again to check for reservation_end_time
            $reserved_space = $parking_service->getSpaceById($space_id);
            $duration_text = "";
            
            if ($reserved_space && !empty($reserved_space['reservation_end_time'])) {
                $end_time = date('Y-m-d H:i', strtotime($reserved_space['reservation_end_time']));
                $reservation_time = strtotime($reserved_space['reservation_time']);
                $end_timestamp = strtotime($reserved_space['reservation_end_time']);
                $duration_hours = round(($end_timestamp - $reservation_time) / 3600, 1);
                $duration_text = "\n\nDuration: {$duration_hours}h\nExpires: {$end_time}";
                } else {
                    // Get max_duration_hours from zone if available
                    $max_duration_hours = 1; // Default fallback
                    if (isset($space['zone']) && isset($space['zone']['max_duration_hours']) && $space['zone']['max_duration_hours'] > 0) {
                        $max_duration_hours = (int)$space['zone']['max_duration_hours'];
                    } else {
                        // Load zone data from database if not in space array
                        $db_service = new DatabaseService();
                        $db = $db_service->getDatabase();
                        $zone_data = $db->getZoneBySpaceId($space_id);
                        if ($zone_data && isset($zone_data['max_duration_hours']) && $zone_data['max_duration_hours'] > 0) {
                            $max_duration_hours = (int)$zone_data['max_duration_hours'];
                        }
                    }
                    $duration_text = "\n\nDuration: {$max_duration_hours}h (default)";
                }
            
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_success', $lang, [
                    'space_id' => $space_id,
                    'license_plate' => $user_data['license_plate']
                ]) . $duration_text
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
        $space_id = isset($space['id']) ? (int)$space['id'] : 0;
        
        // Validate space_id
        if (empty($space_id) || $space_id <= 0) {
            error_log("showPaymentOptions: Invalid space_id from space array: " . json_encode($space));
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        // Always load zone data from database
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        $zone_data = $db->getZoneBySpaceId($space_id);
        
        if (!$zone_data || !isset($zone_data['hourly_rate'])) {
            error_log("showPaymentOptions: Zone data not found for space_id: {$space_id}");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        if (!isset($zone_data['name']) || !isset($zone_data['hourly_rate'])) {
            error_log("showPaymentOptions: Zone data incomplete for space_id: {$space_id}");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        $zone_name = $zone_data['name'];
        $hourly_rate = (float)$zone_data['hourly_rate'];
        $amount_ton = $hourly_rate;
        
        $has_wallet = !empty($user_data['ton_wallet_address']);
        
        error_log("showPaymentOptions: space_id={$space_id}, zone_name={$zone_name}, amount_ton={$amount_ton}");
        
        $text = LanguageService::t('reserve_payment_options', $lang, [
            'zone_name' => $zone_name,
            'space_id' => $space_id,
            'amount_ton' => $amount_ton
        ]);
        
        $keyboard = [
            'inline_keyboard' => []
        ];
        
        // Always show Telegram Stars option (easiest)
        $stars_callback = "payment_stars:{$space_id}";
        error_log("showPaymentOptions: Creating stars callback: '{$stars_callback}'");
        $keyboard['inline_keyboard'][] = [
            [
                'text' => LanguageService::t('payment_option_stars', $lang),
                'callback_data' => $stars_callback
            ]
        ];
        
        // Show TON wallet option if user has wallet, or setup option if not
        if ($has_wallet) {
            $ton_callback = "payment_ton:{$space_id}";
            error_log("showPaymentOptions: Creating TON callback: '{$ton_callback}'");
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => LanguageService::t('payment_option_ton', $lang),
                    'callback_data' => $ton_callback
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
        $space_id = isset($space['id']) ? (int)$space['id'] : 0;
        
        if (empty($space_id) || $space_id <= 0) {
            error_log("showPaymentInstructions: Invalid space_id from space array: " . json_encode($space));
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        // Always load zone data from database
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        $zone_data = $db->getZoneBySpaceId($space_id);
        
        if (!$zone_data || !isset($zone_data['hourly_rate'])) {
            error_log("showPaymentInstructions: Zone data not found for space_id: {$space_id}");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        if (!isset($zone_data['name']) || !isset($zone_data['hourly_rate'])) {
            error_log("showPaymentOptions: Zone data incomplete for space_id: {$space_id}");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        $zone_name = $zone_data['name'];
        $hourly_rate = (float)$zone_data['hourly_rate'];
        $amount_ton = $hourly_rate;
        
        // Use Telegram Stars for payment
        require_once __DIR__ . '/../services/TelegramStarsService.php';
        $stars_service = new \TelegramBot\Services\TelegramStarsService();
        
        // Send invoice using Telegram Stars
        $result = $stars_service->sendInvoice(
            $chat_id,
            $space_id,
            $zone_name,
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
        error_log("showTonPaymentInstructions: Called with space data - " . json_encode(['id' => $space['id'] ?? 'MISSING', 'status' => $space['status'] ?? 'MISSING', 'zone' => isset($space['zone']) ? 'SET' : 'MISSING']));
        if (function_exists('bot_log')) {
            bot_log("showTonPaymentInstructions: Called", ['space_id' => $space['id'] ?? 'MISSING', 'space_status' => $space['status'] ?? 'MISSING', 'has_zone' => isset($space['zone'])]);
        }
        
        // Get space_id from space array or use zone data that was already loaded
        $space_id = isset($space['id']) ? (int)$space['id'] : 0;
        if (empty($space_id) || $space_id <= 0) {
            $error_msg = "showTonPaymentInstructions: Invalid space_id from space array: " . json_encode($space);
            error_log($error_msg);
            if (function_exists('bot_log')) {
                bot_log($error_msg, ['space' => $space]);
            }
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        // Zone data should already be loaded in handlePaymentMethodSelection
        // But if not, load it from database (same as web app: api/parking-spaces.php line 88)
        $zone_data = null;
        if (isset($space['zone']) && isset($space['zone']['name']) && isset($space['zone']['hourly_rate'])) {
            // Use zone data from space array if available
            $zone_data = [
                'name' => $space['zone']['name'],
                'hourly_rate' => $space['zone']['hourly_rate']
            ];
            error_log("showTonPaymentInstructions: Using zone data from space array for space_id {$space_id}");
        } else {
            // Load zone data from database
            $db_service = new DatabaseService();
            $db = $db_service->getDatabase();
            $zone_data = $db->getZoneBySpaceId($space_id);
            error_log("showTonPaymentInstructions: Loaded zone data from database for space_id {$space_id}: " . json_encode($zone_data));
        }
        
        // Check zone data same way as web app (api/parking-spaces.php line 90)
        if (!$zone_data) {
            $error_msg = "showTonPaymentInstructions: Zone data not found for space_id: {$space_id}";
            error_log($error_msg);
            if (function_exists('bot_log')) {
                bot_log($error_msg, ['space_id' => $space_id]);
            }
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        // Validate zone data (same as web app expects)
        if (!isset($zone_data['name']) || empty($zone_data['name'])) {
            $error_msg = "showTonPaymentInstructions: Zone name missing for space_id: {$space_id}";
            error_log($error_msg);
            if (function_exists('bot_log')) {
                bot_log($error_msg, ['space_id' => $space_id, 'zone_data' => $zone_data]);
            }
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        if (!isset($zone_data['hourly_rate']) || $zone_data['hourly_rate'] === null) {
            $error_msg = "showTonPaymentInstructions: Zone hourly_rate missing for space_id: {$space_id}";
            error_log($error_msg);
            if (function_exists('bot_log')) {
                bot_log($error_msg, ['space_id' => $space_id, 'zone_data' => $zone_data]);
            }
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        $zone_name = $zone_data['name'];
        $hourly_rate = (float)$zone_data['hourly_rate'];
        $amount_ton = $hourly_rate;
        $wallet_address = $user_data['ton_wallet_address'] ?? '';
        
        error_log("showTonPaymentInstructions: Processing payment for space_id={$space_id}, amount_ton={$amount_ton}, zone_name={$zone_name}");
        
        // Note: Balance check is done in handlePaymentMethodSelection before calling this method
        // Use Telegram Bot API invoice for TON payment
        require_once __DIR__ . '/../services/TelegramStarsService.php';
        $payment_service = new \TelegramBot\Services\TelegramStarsService();
        
        // Send TON invoice
        $result = $payment_service->sendTonInvoice(
            $chat_id,
            $space_id,
            $zone_name,
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
        error_log("handlePaymentMethodSelection: space_id={$space_id}, payment_method={$payment_method}");
        
        if (empty($space_id) || $space_id <= 0) {
            error_log("handlePaymentMethodSelection: Invalid space_id: {$space_id}");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        $space = $parking_service->getSpaceById($space_id);
        if (!$space) {
            error_log("handlePaymentMethodSelection: Space not found for space_id: {$space_id}");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        // Ensure space has id field
        if (!isset($space['id']) || empty($space['id'])) {
            $space['id'] = $space_id;
            error_log("handlePaymentMethodSelection: Added space_id to space array: {$space_id}");
        }
        
        $space_status = isset($space['status']) ? $space['status'] : 'N/A';
        error_log("handlePaymentMethodSelection: Space found - id={$space['id']}, status={$space_status}");
        
        // Ensure space has zone data - try to load from database if missing (same as web app)
        if (!isset($space['zone'])) {
            error_log("handlePaymentMethodSelection: Space {$space_id} has no zone data. Loading from database...");
            $db_service = new DatabaseService();
            $db = $db_service->getDatabase();
            $zone = $db->getZoneBySpaceId($space_id);
            
            if ($zone) {
                if (!isset($zone['name']) || !isset($zone['hourly_rate'])) {
                    error_log("handlePaymentMethodSelection: Zone data incomplete from database for space_id: {$space_id}");
                    $bot->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
                    ]);
                    return;
                }
                error_log("handlePaymentMethodSelection: Zone found in database: " . json_encode($zone));
                // Check is_premium same way as web app (api/parking-spaces.php line 90)
                $is_premium = ($zone['is_premium'] == 1);
                $space['zone'] = [
                    'id' => (string)$zone['id'],
                    'name' => $zone['name'],
                    'is_premium' => $is_premium,
                    'hourly_rate' => (float)$zone['hourly_rate']
                ];
                error_log("handlePaymentMethodSelection: Zone loaded and added to space: " . json_encode($space['zone']));
            } else {
                // No zone found - space is not in any zone (same as web app - allows reservation without payment)
                error_log("handlePaymentMethodSelection: No zone found for space {$space_id} - space is not in a zone, but allowing payment anyway");
                $space['zone'] = null;
            }
        } else {
            error_log("handlePaymentMethodSelection: Space {$space_id} already has zone data: " . json_encode($space['zone']));
        }
        
        // Log final zone data for debugging
        error_log("handlePaymentMethodSelection: Final zone data for space {$space_id}: " . json_encode($space['zone'] ?? 'null'));
        
        // Check if this is a premium zone (same as web app: api/parking-spaces.php line 90)
        // Only premium zones require payment verification, but we allow payment for all zones
        $is_premium = false;
        if ($space['zone'] && $space['zone']['is_premium'] == 1) {
            $is_premium = true;
            error_log("handlePaymentMethodSelection: Zone is_premium: 1 (premium zone)");
        } else {
            error_log("handlePaymentMethodSelection: No premium zone - allowing payment anyway");
        }
        
        error_log("handlePaymentMethodSelection: Space {$space_id} is valid, proceeding with payment method: {$payment_method}");
        
        if ($payment_method === 'stars') {
            // Use Telegram Stars
            $this->showPaymentInstructions($bot, $chat_id, $space, $user_data, $lang);
        } elseif ($payment_method === 'ton') {
            // Always load zone data from database (same as web app: api/parking-spaces.php line 88)
            $db_service = new DatabaseService();
            $db = $db_service->getDatabase();
            $zone_data = $db->getZoneBySpaceId($space_id);
            
            error_log("handlePaymentMethodSelection: Zone data for space_id {$space_id}: " . json_encode($zone_data));
            if (function_exists('bot_log')) {
                bot_log("handlePaymentMethodSelection: Zone data for space_id {$space_id}", ['zone_data' => $zone_data]);
            }
            
            // Check zone data same way as web app (api/parking-spaces.php line 90)
            if (!$zone_data) {
                $error_msg = "handlePaymentMethodSelection: Zone data not found for space_id: {$space_id} (space is not in any zone)";
                error_log($error_msg);
                if (function_exists('bot_log')) {
                    bot_log($error_msg, ['space_id' => $space_id]);
                }
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
                ]);
                return;
            }
            
            // Validate zone data (same as web app expects)
            if (!isset($zone_data['name']) || empty($zone_data['name'])) {
                $error_msg = "handlePaymentMethodSelection: Zone name missing for space_id: {$space_id}";
                error_log($error_msg);
                if (function_exists('bot_log')) {
                    bot_log($error_msg, ['space_id' => $space_id, 'zone_data' => $zone_data]);
                }
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
                ]);
                return;
            }
            
            if (!isset($zone_data['hourly_rate']) || $zone_data['hourly_rate'] === null) {
                $error_msg = "handlePaymentMethodSelection: Zone hourly_rate missing for space_id: {$space_id}";
                error_log($error_msg);
                if (function_exists('bot_log')) {
                    bot_log($error_msg, ['space_id' => $space_id, 'zone_data' => $zone_data]);
                }
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
                ]);
                return;
            }
            
            $zone_name = $zone_data['name'];
            $hourly_rate = (float)$zone_data['hourly_rate'];
            $amount_ton = $hourly_rate;
            
            // Use TON wallet
            if (empty($user_data['ton_wallet_address'])) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('reserve_premium_no_wallet', $lang, [
                        'zone_name' => $zone_name,
                        'hourly_rate' => $hourly_rate
                    ])
                ]);
                return;
            }
            
            // Check and show wallet balance before showing payment instructions
            $wallet_address = $user_data['ton_wallet_address'];
            
            require_once __DIR__ . '/../services/TonPaymentService.php';
            $ton_service = new \TelegramBot\Services\TonPaymentService();
            
            error_log("Checking wallet balance before payment: {$wallet_address}");
            $balance_result = $ton_service->checkWalletBalance($wallet_address);
            
            if ($balance_result['success']) {
                $balance_ton = $balance_result['balance_ton'];
                error_log("Wallet balance: {$balance_ton} TON, Required: {$amount_ton} TON");
                
                // Always show balance info before payment
                $balance_info = LanguageService::t('wallet_balance_info', $lang, [
                    'balance_ton' => number_format($balance_ton, 3),
                    'required_ton' => $amount_ton
                ]);
                
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $balance_info,
                    'parse_mode' => 'Markdown'
                ]);
                
                // Check if user has enough balance
                if ($balance_ton < $amount_ton) {
                    $balance_text = LanguageService::t('wallet_insufficient_balance', $lang, [
                        'balance_ton' => number_format($balance_ton, 3),
                        'required_ton' => $amount_ton,
                        'zone_name' => $zone_name
                    ]);
                    
                    $bot->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => $balance_text,
                        'parse_mode' => 'Markdown'
                    ]);
                    return;
                }
            } else {
                // Could not check balance, but continue with payment attempt
                error_log("Could not check wallet balance: " . ($balance_result['error'] ?? 'Unknown error'));
            }
            
            $this->showTonPaymentInstructions($bot, $chat_id, $space, $user_data, $lang);
        }
    }
    
    public function handlePaymentVerification($bot, $chat_id, $parking_service, $space_id, $tx_hash, $user_data, $lang) {
        require_once __DIR__ . '/../config.php';
        
        $space = $parking_service->getSpaceById($space_id);
        if (!$space) {
            error_log("handlePaymentVerification: Space {$space_id} not found");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        // Always load zone data from database for payment verification
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        $zone_data = $db->getZoneBySpaceId($space_id);
        
        if (!$zone_data || !isset($zone_data['name']) || !isset($zone_data['hourly_rate'])) {
            error_log("handlePaymentVerification: Zone data not found or incomplete for space_id: {$space_id}");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_payment_invalid_space', $lang)
            ]);
            return;
        }
        
        $zone_name = $zone_data['name'];
        $hourly_rate = (float)$zone_data['hourly_rate'];
        
        error_log("handlePaymentVerification: Verifying payment for space {$space_id}, zone: {$zone_name}, hourly_rate: {$hourly_rate}");
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
            // Get space again to check for reservation_end_time
            $reserved_space = $parking_service->getSpaceById($space_id);
            $duration_text = "";
            
            if ($reserved_space && !empty($reserved_space['reservation_end_time'])) {
                $end_time = date('Y-m-d H:i', strtotime($reserved_space['reservation_end_time']));
                $reservation_time = strtotime($reserved_space['reservation_time']);
                $end_timestamp = strtotime($reserved_space['reservation_end_time']);
                $duration_hours = round(($end_timestamp - $reservation_time) / 3600, 1);
                $duration_text = "\n\nDuration: *{$duration_hours}h*\nExpires: *{$end_time}*";
                } else {
                    // Get max_duration_hours from zone if available
                    $max_duration_hours = 1; // Default fallback
                    if (isset($reserved_space['zone']) && isset($reserved_space['zone']['max_duration_hours']) && $reserved_space['zone']['max_duration_hours'] > 0) {
                        $max_duration_hours = (int)$reserved_space['zone']['max_duration_hours'];
                    } else {
                        // Load zone data from database if not in space array
                        $db_service = new DatabaseService();
                        $db = $db_service->getDatabase();
                        $zone_data = $db->getZoneBySpaceId($space_id);
                        if ($zone_data && isset($zone_data['max_duration_hours']) && $zone_data['max_duration_hours'] > 0) {
                            $max_duration_hours = (int)$zone_data['max_duration_hours'];
                        }
                    }
                    $duration_text = "\n\nDuration: *{$max_duration_hours}h* (default)";
                }
            
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('reserve_success_premium', $lang, [
                    'space_id' => $space_id,
                    'license_plate' => $user_data['license_plate'],
                    'amount_ton' => $hourly_rate
                ]) . $duration_text,
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

