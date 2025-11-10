<?php
/**
 * Wallet Pay Webhook Handler
 * This endpoint receives payment notifications from Wallet Pay
 * 
 * Configure webhook URL in Wallet Pay dashboard:
 * https://pay.wallet.tg/ → Settings → Webhooks
 * URL: https://parkiraj.info/telegram-bot/payment-webhook.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/WalletPayService.php';
require_once __DIR__ . '/services/DatabaseService.php';
require_once __DIR__ . '/services/ParkingService.php';
require_once __DIR__ . '/TelegramAPI.php';

header('Content-Type: application/json');

try {
    $input = file_get_contents('php://input');
    $webhook_data = json_decode($input, true);
    
    if (!$webhook_data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit();
    }
    
    error_log('Wallet Pay webhook received: ' . json_encode($webhook_data));
    
    // Verify webhook signature (implement if Wallet Pay provides it)
    $wallet_pay = new \TelegramBot\Services\WalletPayService();
    
    // Extract order information
    $order_id = $webhook_data['id'] ?? null;
    $external_id = $webhook_data['externalId'] ?? null;
    $status = $webhook_data['status'] ?? null;
    $amount = $webhook_data['amount']['amount'] ?? null;
    
    if (!$order_id || !$external_id || !$status) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit();
    }
    
    // Parse external_id to get reservation details
    // Format: reserve_{space_id}_{license_plate}_{timestamp}
    if (str_starts_with($external_id, 'reserve_')) {
        $parts = explode('_', $external_id);
        if (count($parts) >= 3) {
            $space_id = (int)$parts[1];
            $license_plate = $parts[2];
            
            // Check payment status
            if ($status === 'PAID' || $status === 'SUCCESS') {
                // Payment successful - create reservation
                $db_service = new \TelegramBot\Services\DatabaseService();
                $db = $db_service->getDatabase();
                $parking_service = new \TelegramBot\Services\ParkingService();
                
                // Create payment record
                $payment_data = [
                    'parking_space_id' => $space_id,
                    'license_plate' => $license_plate,
                    'tx_hash' => $order_id, // Use order ID as transaction identifier
                    'amount_nano' => (int)((float)$amount * 1000000000),
                    'amount_ton' => (float)$amount
                ];
                
                $payment_result = $db->createTonPayment($payment_data);
                
                if ($payment_result['success']) {
                    // Verify payment
                    $db->verifyTonPayment($order_id);
                    
                    // Reserve the space
                    $reservation_success = $parking_service->reserveSpace($space_id, $license_plate);
                    
                    if ($reservation_success) {
                        // Notify user via Telegram bot
                        $telegram = new TelegramAPI(TELEGRAM_BOT_TOKEN);
                        
                        // Find user's chat_id
                        $user_data = $db->getTelegramUserByLicensePlate($license_plate);
                        if ($user_data) {
                            $lang = $user_data['language'] ?? 'en';
                            
                            $telegram->sendMessage([
                                'chat_id' => $user_data['chat_id'],
                                'text' => \TelegramBot\Services\LanguageService::t('reserve_success_premium', $lang, [
                                    'space_id' => $space_id,
                                    'license_plate' => $license_plate,
                                    'amount_ton' => $amount
                                ]),
                                'parse_mode' => 'Markdown'
                            ]);
                        }
                        
                        http_response_code(200);
                        echo json_encode(['success' => true, 'message' => 'Payment processed and reservation created']);
                        exit();
                    } else {
                        error_log("Failed to reserve space {$space_id} for {$license_plate}");
                    }
                } else {
                    error_log("Failed to create payment record: " . ($payment_result['error'] ?? 'Unknown'));
                }
            } elseif ($status === 'EXPIRED' || $status === 'CANCELLED') {
                // Payment expired or cancelled
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => 'Payment cancelled or expired']);
                exit();
            }
        }
    }
    
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Webhook received']);
    
} catch (Exception $e) {
    error_log('Wallet Pay webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

