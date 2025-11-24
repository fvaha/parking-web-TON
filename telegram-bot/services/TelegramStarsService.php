<?php
namespace TelegramBot\Services;

require_once __DIR__ . '/../TelegramAPI.php';

/**
 * Telegram Stars Service - Telegram's native payment system
 * Uses Telegram Bot API sendInvoice method with XTR currency
 * Documentation: https://core.telegram.org/bots/api#sendinvoice
 */
class TelegramStarsService {
    private $bot;
    
    public function __construct() {
        require_once __DIR__ . '/../config.php';
        $bot_token = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : getenv('TELEGRAM_BOT_TOKEN');
        $this->bot = new \TelegramBot\TelegramAPI($bot_token);
    }
    
    /**
     * Send invoice for parking reservation
     * @param int $chat_id Telegram chat ID
     * @param int $space_id Parking space ID
     * @param string $zone_name Zone name
     * @param float $amount_ton Amount in TON (will be converted to Stars)
     * @param string $license_plate User's license plate
     * @param string $lang User language
     * @return array ['success' => bool, 'error' => string]
     */
    public function sendInvoice($chat_id, $space_id, $zone_name, $amount_ton, $license_plate, $lang) {
        // Convert TON to Stars (approximate: 1 TON ≈ 2-3 USD, 1 Star = 0.01 USD)
        // For simplicity, we'll use: 1 TON = 200 Stars (conservative estimate)
        // You can adjust this conversion rate based on current TON/USD price
        $amount_stars = (int)($amount_ton * 200);
        
        // Minimum 1 Star
        if ($amount_stars < 1) {
            $amount_stars = 1;
        }
        
        // Create unique invoice payload (will be returned in successful_payment)
        $payload = json_encode([
            'space_id' => $space_id,
            'zone_name' => $zone_name,
            'license_plate' => $license_plate,
            'amount_ton' => $amount_ton,
            'timestamp' => time()
        ]);
        
        // Get localized title and description
        require_once __DIR__ . '/LanguageService.php';
        $title = \TelegramBot\Services\LanguageService::t('stars_invoice_title', $lang, [
            'zone_name' => $zone_name,
            'space_id' => $space_id
        ]);
        
        $description = \TelegramBot\Services\LanguageService::t('stars_invoice_description', $lang, [
            'zone_name' => $zone_name,
            'space_id' => $space_id,
            'license_plate' => $license_plate
        ]);
        
        // Send invoice using Telegram Bot API
        // For Telegram Stars, provider_token is not needed (can be empty string)
        $result = $this->bot->sendInvoice([
            'chat_id' => $chat_id,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'provider_token' => '', // Not needed for Telegram Stars (XTR currency)
            'currency' => 'XTR', // XTR is the currency code for Telegram Stars
            'prices' => [
                [
                    'label' => \TelegramBot\Services\LanguageService::t('stars_price_label', $lang),
                    'amount' => $amount_stars // Amount in Stars (smallest unit, 1 Star = 1)
                ]
            ],
            'max_tip_amount' => 0, // Optional: allow tips (0 = no tips)
            'suggested_tip_amounts' => [], // Optional: suggested tip amounts
            'start_parameter' => 'reserve_' . $space_id,
            'provider_data' => $payload,
            'photo_url' => '', // Optional: photo URL
            'photo_size' => 0,
            'photo_width' => 0,
            'photo_height' => 0,
            'need_name' => false,
            'need_phone_number' => false,
            'need_email' => false,
            'need_shipping_address' => false,
            'send_phone_number_to_provider' => false,
            'send_email_to_provider' => false,
            'is_flexible' => false
        ]);
        
        if (isset($result['ok']) && $result['ok']) {
            return [
                'success' => true,
                'message_id' => $result['result']['message_id'] ?? null
            ];
        } else {
            $error = $result['description'] ?? 'Unknown error';
            error_log("Telegram Stars invoice error: " . $error);
            return [
                'success' => false,
                'error' => $error
            ];
        }
    }
    
    /**
     * Send TON invoice for parking reservation
     * @param int $chat_id Telegram chat ID
     * @param int $space_id Parking space ID
     * @param string $zone_name Zone name
     * @param float $amount_ton Amount in TON
     * @param string $license_plate User's license plate
     * @param string $lang User language
     * @return array ['success' => bool, 'error' => string]
     */
    public function sendTonInvoice($chat_id, $space_id, $zone_name, $amount_ton, $license_plate, $lang) {
        // Convert TON to nanoTON (smallest unit: 1 TON = 1,000,000,000 nanoTON)
        $amount_nanoton = (int)($amount_ton * 1000000000);
        
        // Minimum 1 nanoTON
        if ($amount_nanoton < 1) {
            $amount_nanoton = 1;
        }
        
        // Create unique invoice payload (will be returned in successful_payment)
        $payload = json_encode([
            'space_id' => $space_id,
            'zone_name' => $zone_name,
            'license_plate' => $license_plate,
            'amount_ton' => $amount_ton,
            'payment_type' => 'ton',
            'timestamp' => time()
        ]);
        
        // Get localized title and description
        require_once __DIR__ . '/LanguageService.php';
        $title = \TelegramBot\Services\LanguageService::t('ton_invoice_title', $lang, [
            'zone_name' => $zone_name,
            'space_id' => $space_id
        ]);
        
        $description = \TelegramBot\Services\LanguageService::t('ton_invoice_description', $lang, [
            'zone_name' => $zone_name,
            'space_id' => $space_id,
            'license_plate' => $license_plate,
            'amount_ton' => $amount_ton
        ]);
        
        // Send invoice using Telegram Bot API with TON currency
        // For TON, provider_token is not needed (can be empty string)
        $result = $this->bot->sendInvoice([
            'chat_id' => $chat_id,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'provider_token' => '', // Not needed for TON payments
            'currency' => 'TON', // TON currency code
            'prices' => [
                [
                    'label' => \TelegramBot\Services\LanguageService::t('ton_price_label', $lang),
                    'amount' => $amount_nanoton // Amount in nanoTON (smallest unit)
                ]
            ],
            'max_tip_amount' => 0, // Optional: allow tips (0 = no tips)
            'suggested_tip_amounts' => [], // Optional: suggested tip amounts
            'start_parameter' => 'reserve_ton_' . $space_id,
            'provider_data' => $payload,
            'photo_url' => '', // Optional: photo URL
            'photo_size' => 0,
            'photo_width' => 0,
            'photo_height' => 0,
            'need_name' => false,
            'need_phone_number' => false,
            'need_email' => false,
            'need_shipping_address' => false,
            'send_phone_number_to_provider' => false,
            'send_email_to_provider' => false,
            'is_flexible' => false
        ]);
        
        if (isset($result['ok']) && $result['ok']) {
            return [
                'success' => true,
                'message_id' => $result['result']['message_id'] ?? null
            ];
        } else {
            $error = $result['description'] ?? 'Unknown error';
            error_log("Telegram TON invoice error: " . $error);
            return [
                'success' => false,
                'error' => $error
            ];
        }
    }
    
    /**
     * Answer pre-checkout query (called before payment confirmation)
     * @param string $pre_checkout_query_id Query ID from Telegram
     * @param bool $ok True to approve, false to decline
     * @param string $error_message Error message if declining
     * @return array API response
     */
    public function answerPreCheckoutQuery($pre_checkout_query_id, $ok = true, $error_message = '') {
        return $this->bot->answerPreCheckoutQuery([
            'pre_checkout_query_id' => $pre_checkout_query_id,
            'ok' => $ok,
            'error_message' => $error_message
        ]);
    }
}

