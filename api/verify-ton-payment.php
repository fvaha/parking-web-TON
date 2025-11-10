<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';
require_once 'security_helper.php';

set_cors_headers();
handle_preflight();

// Security: API key authentication and rate limiting
// Allow both web app and bot requests (bot calls this endpoint internally)
checkApiKey('any', true);
$client_id = getClientIdentifier();
checkRateLimit($client_id, 10, 60); // 10 requests per minute for verify endpoint

require_once '../config/database.php';

try {
    $db = new Database();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
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
    
    // Validate required fields
    if (!isset($input['space_id']) || !isset($input['tx_hash']) || !isset($input['license_plate']) || !isset($input['amount_nano'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required fields: space_id, tx_hash, license_plate, amount_nano'
        ]);
        exit();
    }
    
    // Validate and sanitize input
    $space_id = validateInteger($input['space_id'], 1);
    if ($space_id === false) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid space_id'
        ]);
        exit();
    }
    
    $tx_hash = validateTxHash($input['tx_hash']);
    if ($tx_hash === false) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid transaction hash format'
        ]);
        exit();
    }
    
    $license_plate = validateLicensePlate($input['license_plate']);
    if ($license_plate === false) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid license plate format'
        ]);
        exit();
    }
    
    $amount_nano = sanitizeString($input['amount_nano'], 50);
    if (!is_numeric($amount_nano) || $amount_nano <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid amount_nano'
        ]);
        exit();
    }
    
    // Check if payment already exists and is already used
    $existing_payment = $db->getTonPaymentByTxHash($tx_hash);
    if ($existing_payment) {
        if ($existing_payment['status'] === 'verified') {
            // Check if this payment is already used for a reservation
            $payment_used = false;
            
            // Check if any parking space uses this tx_hash
            $spaces = $db->getParkingSpaces();
            foreach ($spaces as $space) {
                if (isset($space['payment_tx_hash']) && 
                    $space['payment_tx_hash'] === $tx_hash && 
                    in_array($space['status'], ['reserved', 'occupied'])) {
                    $payment_used = true;
                    break;
                }
            }
            
            if ($payment_used) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'This payment transaction has already been used for a reservation'
                ]);
                exit();
            }
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Payment already verified',
                'payment_id' => $existing_payment['id']
            ]);
            exit();
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Payment transaction already exists but not verified'
            ]);
            exit();
        }
    }
    
    // Verify transaction on TON blockchain using TON API
    require_once __DIR__ . '/../telegram-bot/services/TonPaymentService.php';
    $ton_service = new \TelegramBot\Services\TonPaymentService();
    
    $expected_amount_ton = (float)($amount_nano / 1000000000);
    $verification_result = $ton_service->verifyTransaction($tx_hash, $expected_amount_ton);
    
    if (!$verification_result['success']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Transaction verification failed: ' . ($verification_result['error'] ?? 'Unknown error')
        ]);
        exit();
    }
    
    if (!$verification_result['verified']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Transaction not verified: ' . ($verification_result['error'] ?? 'Transaction validation failed'),
            'tx_hash' => $tx_hash
        ]);
        exit();
    }
    
    // Transaction is verified on blockchain
    
    // Calculate amount in TON from nanoTON
    $amount_ton = (float)($amount_nano / 1000000000);
    
    // Create payment record
    $payment_data = [
        'parking_space_id' => $space_id,
        'license_plate' => $license_plate,
        'tx_hash' => $tx_hash,
        'amount_nano' => $amount_nano,
        'amount_ton' => $amount_ton
    ];
    
    $payment_result = $db->createTonPayment($payment_data);
    
    if (!$payment_result['success']) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create payment record: ' . ($payment_result['error'] ?? 'Unknown error')
        ]);
        exit();
    }
    
    // Verify payment (in production, verify on blockchain first)
    // For now, we'll verify it immediately after creation
    // TODO: Implement actual blockchain verification before marking as verified
    $verify_result = $db->verifyTonPayment($tx_hash);
    
    if (!$verify_result['success']) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to verify payment: ' . ($verify_result['error'] ?? 'Unknown error')
        ]);
        exit();
    }
    
    // Get zone info to check if premium
    $zone = $db->getZoneBySpaceId($space_id);
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment verified successfully',
        'payment_id' => $payment_result['payment_id'],
        'tx_hash' => $tx_hash,
        'amount_ton' => $amount_ton,
        'is_premium' => $zone ? ($zone['is_premium'] == 1) : false
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>

