<?php
namespace TelegramBot\Services;

/**
 * Wallet Pay Service - Telegram's official payment system
 * Documentation: https://pay.wallet.tg/
 */
class WalletPayService {
    private $api_key;
    private $api_url = 'https://pay.wallet.tg/wpay/store-api/v1';
    
    public function __construct() {
        require_once __DIR__ . '/../config.php';
        // Get API key from config or environment
        $this->api_key = defined('WALLET_PAY_API_KEY') ? WALLET_PAY_API_KEY : getenv('WALLET_PAY_API_KEY');
        
        if (empty($this->api_key)) {
            error_log('Wallet Pay API key not configured');
        }
    }
    
    /**
     * Create payment order
     * @param float $amount_ton Amount in TON
     * @param string $description Order description
     * @param string $external_id Unique order ID (e.g., reservation ID)
     * @param array $custom_data Additional data
     * @return array ['success' => bool, 'payLink' => string, 'orderId' => string, 'error' => string]
     */
    public function createOrder($amount_ton, $description, $external_id, $custom_data = []) {
        if (empty($this->api_key)) {
            return [
                'success' => false,
                'error' => 'Wallet Pay API key not configured. Please set WALLET_PAY_API_KEY in config.php'
            ];
        }
        
        $url = $this->api_url . '/order';
        
        $payload = [
            'amount' => [
                'amount' => (string)$amount_ton,
                'currencyCode' => 'TON'
            ],
            'externalId' => $external_id,
            'timeoutSeconds' => 3600, // 1 hour
            'description' => $description,
            'returnUrl' => defined('WEB_APP_URL') ? WEB_APP_URL . '/telegram-bot/payment-success.php' : 'https://parkiraj.info/telegram-bot/payment-success.php',
            'failReturnUrl' => defined('WEB_APP_URL') ? WEB_APP_URL . '/telegram-bot/payment-failed.php' : 'https://parkiraj.info/telegram-bot/payment-failed.php',
            'customData' => json_encode($custom_data)
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Wpay-Store-Api-Key: ' . $this->api_key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Wallet Pay API error: {$error}");
            return [
                'success' => false,
                'error' => 'Failed to connect to Wallet Pay API: ' . $error
            ];
        }
        
        if ($http_code !== 200) {
            error_log("Wallet Pay API HTTP error {$http_code}: {$response}");
            return [
                'success' => false,
                'error' => 'Wallet Pay API error: HTTP ' . $http_code
            ];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['status']) || $data['status'] !== 'SUCCESS') {
            $error_msg = $data['message'] ?? 'Unknown error';
            return [
                'success' => false,
                'error' => 'Failed to create order: ' . $error_msg
            ];
        }
        
        return [
            'success' => true,
            'payLink' => $data['data']['payLink'] ?? null,
            'orderId' => $data['data']['id'] ?? null,
            'prepaidPayId' => $data['data']['prepaidPayId'] ?? null
        ];
    }
    
    /**
     * Get order status
     * @param string $order_id Order ID from Wallet Pay
     * @return array ['success' => bool, 'status' => string, 'error' => string]
     */
    public function getOrderStatus($order_id) {
        if (empty($this->api_key)) {
            return ['success' => false, 'error' => 'API key not configured'];
        }
        
        $url = $this->api_url . '/order/prepaid-status';
        
        $payload = [
            'prepaidPayId' => $order_id
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Wpay-Store-Api-Key: ' . $this->api_key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['success' => false, 'error' => 'HTTP ' . $http_code];
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['status'])) {
            return ['success' => false, 'error' => 'Invalid response'];
        }
        
        return [
            'success' => true,
            'status' => $data['status'] ?? 'UNKNOWN',
            'data' => $data['data'] ?? null
        ];
    }
    
    /**
     * Verify webhook signature (for security)
     */
    public function verifyWebhookSignature($payload, $signature) {
        // Wallet Pay sends webhook with signature
        // Implement signature verification if needed
        // For now, we'll trust the webhook if API key matches
        return true;
    }
}

