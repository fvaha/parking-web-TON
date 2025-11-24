<?php
/**
 * Simple Telegram Bot API wrapper using pure HTTP requests
 * No Composer dependencies needed!
 */
class TelegramAPI {
    private $bot_token;
    private $api_url;
    
    public function __construct($bot_token) {
        $this->bot_token = $bot_token;
        $this->api_url = "https://api.telegram.org/bot{$bot_token}";
    }
    
    /**
     * Send HTTP request to Telegram API
     */
    private function request($method, $params = []) {
        $url = $this->api_url . '/' . $method;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Telegram API curl error: {$error}");
            return false;
        }
        
        if ($http_code !== 200) {
            error_log("Telegram API HTTP error {$http_code}: {$response}");
            return false;
        }
        
        $data = json_decode($response, true);
        return $data;
    }
    
    /**
     * Get webhook update from input stream
     */
    public function getWebhookUpdate() {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
        }
        
        $update = json_decode($input, true);
        return $update ? new TelegramUpdate($update) : null;
    }
    
    /**
     * Send message
     */
    public function sendMessage($params) {
        return $this->request('sendMessage', $params);
    }
    
    /**
     * Answer callback query
     */
    public function answerCallbackQuery($params) {
        return $this->request('answerCallbackQuery', $params);
    }
    
    /**
     * Send invoice (for Telegram Stars payments)
     */
    public function sendInvoice($params) {
        return $this->request('sendInvoice', $params);
    }
    
    /**
     * Answer pre-checkout query (before payment confirmation)
     */
    public function answerPreCheckoutQuery($params) {
        return $this->request('answerPreCheckoutQuery', $params);
    }
    
    /**
     * Remove reply keyboard (hide keyboard)
     */
    public function removeReplyKeyboard($chat_id, $text = null) {
        $params = [
            'chat_id' => $chat_id,
            'reply_markup' => json_encode(['remove_keyboard' => true])
        ];
        if ($text !== null) {
            $params['text'] = $text;
        }
        return $this->request('sendMessage', $params);
    }
}

/**
 * Simple wrapper for Telegram Update object
 */
class TelegramUpdate {
    private $data;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function getCallbackQuery() {
        return isset($this->data['callback_query']) 
            ? new TelegramCallbackQuery($this->data['callback_query']) 
            : null;
    }
    
    public function getMessage() {
        return isset($this->data['message']) 
            ? new TelegramMessage($this->data['message']) 
            : null;
    }
    
    public function getPreCheckoutQuery() {
        return isset($this->data['pre_checkout_query']) 
            ? new TelegramPreCheckoutQuery($this->data['pre_checkout_query']) 
            : null;
    }
}

/**
 * Simple wrapper for Telegram Callback Query
 */
class TelegramCallbackQuery {
    private $data;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function getId() {
        return $this->data['id'] ?? null;
    }
    
    public function getData() {
        return $this->data['data'] ?? null;
    }
    
    public function getMessage() {
        return isset($this->data['message']) 
            ? new TelegramMessage($this->data['message']) 
            : null;
    }
    
    public function getFrom() {
        return isset($this->data['from']) 
            ? new TelegramUser($this->data['from']) 
            : null;
    }
}

/**
 * Simple wrapper for Telegram Message
 */
class TelegramMessage {
    public $data; // Make public for access to successful_payment
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function getChat() {
        return isset($this->data['chat']) 
            ? new TelegramChat($this->data['chat']) 
            : null;
    }
    
    public function getText() {
        return $this->data['text'] ?? null;
    }
    
    public function getFrom() {
        return isset($this->data['from']) 
            ? new TelegramUser($this->data['from']) 
            : null;
    }
    
    public function getSuccessfulPayment() {
        return isset($this->data['successful_payment']) 
            ? new TelegramSuccessfulPayment($this->data['successful_payment']) 
            : null;
    }
}

/**
 * Simple wrapper for Telegram Chat
 */
class TelegramChat {
    private $data;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function getId() {
        return $this->data['id'] ?? null;
    }
}

/**
 * Simple wrapper for Telegram User
 */
class TelegramUser {
    private $data;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function getId() {
        return $this->data['id'] ?? null;
    }
    
    public function getUsername() {
        return $this->data['username'] ?? null;
    }
    
    public function getFirstName() {
        return $this->data['first_name'] ?? null;
    }
    
    /**
     * Get user's language code from Telegram
     * Note: This field is OPTIONAL in Telegram Bot API
     * It may reflect system language, not necessarily Telegram app language
     * Returns null if not provided by Telegram
     */
    public function getLanguageCode() {
        return $this->data['language_code'] ?? null;
    }
}

/**
 * Simple wrapper for Telegram Pre-Checkout Query
 */
class TelegramPreCheckoutQuery {
    private $data;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function getId() {
        return $this->data['id'] ?? null;
    }
    
    public function getFrom() {
        return isset($this->data['from']) 
            ? new TelegramUser($this->data['from']) 
            : null;
    }
    
    public function getCurrency() {
        return $this->data['currency'] ?? null;
    }
    
    public function getTotalAmount() {
        return $this->data['total_amount'] ?? null;
    }
    
    public function getInvoicePayload() {
        return $this->data['invoice_payload'] ?? null;
    }
}

/**
 * Simple wrapper for Telegram Successful Payment
 */
class TelegramSuccessfulPayment {
    private $data;
    
    public function __construct($data) {
        $this->data = $data;
    }
    
    public function getCurrency() {
        return $this->data['currency'] ?? null;
    }
    
    public function getTotalAmount() {
        return $this->data['total_amount'] ?? null;
    }
    
    public function getInvoicePayload() {
        return $this->data['invoice_payload'] ?? null;
    }
    
    public function getTelegramPaymentChargeId() {
        return $this->data['telegram_payment_charge_id'] ?? null;
    }
    
    public function getProviderPaymentChargeId() {
        return $this->data['provider_payment_charge_id'] ?? null;
    }
}

