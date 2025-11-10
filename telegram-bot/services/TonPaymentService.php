<?php
namespace TelegramBot\Services;

class TonPaymentService {
    private $recipient_address;
    
    public function __construct() {
        require_once __DIR__ . '/../config.php';
        $this->recipient_address = defined('TON_RECIPIENT_ADDRESS') ? TON_RECIPIENT_ADDRESS : '';
    }
    
    /**
     * Verify TON transaction on blockchain
     * @param string $tx_hash Transaction hash
     * @param float $expected_amount_ton Expected amount in TON
     * @param string $from_address Optional: expected sender address
     * @return array ['success' => bool, 'verified' => bool, 'error' => string, 'tx_data' => array]
     */
    public function verifyTransaction($tx_hash, $expected_amount_ton, $from_address = null) {
        if (empty($tx_hash)) {
            return ['success' => false, 'verified' => false, 'error' => 'Transaction hash is required'];
        }
        
        // Check if tx_hash is a BOC (Bag of Cells) - BOC is usually longer and base64-like
        // Transaction hash is usually shorter hex string
        $is_boc = strlen($tx_hash) > 100 || preg_match('/^[A-Za-z0-9+\/]+=*$/', $tx_hash);
        
        if ($is_boc) {
            // Try to extract transaction hash from BOC using TON API
            // First, try to parse BOC and get transaction hash
            $boc_api_url = "https://tonapi.io/v2/blockchain/parse-boc?boc=" . urlencode($tx_hash);
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Accept: application/json\r\n",
                    'timeout' => 10
                ]
            ]);
            
            $boc_response = @file_get_contents($boc_api_url, false, $context);
            
            if ($boc_response) {
                $boc_data = json_decode($boc_response, true);
                // Try to get transaction hash from parsed BOC
                if (isset($boc_data['hash'])) {
                    $tx_hash = $boc_data['hash'];
                } elseif (isset($boc_data['transaction']['hash'])) {
                    $tx_hash = $boc_data['transaction']['hash'];
                }
            }
            
            // If we couldn't extract hash from BOC, try alternative approach:
            // Search for recent transactions to our address and match by amount
            // This is a fallback if BOC parsing doesn't work
            if (strlen($tx_hash) > 100) {
                // Still looks like BOC, try to find transaction by searching recent transactions
                $search_url = "https://tonapi.io/v2/blockchain/accounts/{$this->recipient_address}/transactions?limit=10";
                $search_response = @file_get_contents($search_url, false, $context);
                
                if ($search_response) {
                    $search_data = json_decode($search_response, true);
                    if (isset($search_data['transactions']) && is_array($search_data['transactions'])) {
                        // Find most recent transaction matching expected amount
                        $expected_nano = (int)($expected_amount_ton * 1000000000);
                        $tolerance = 1000000; // 0.001 TON tolerance
                        
                        foreach ($search_data['transactions'] as $tx) {
                            if (isset($tx['in_msg']['value'])) {
                                $tx_amount = (int)$tx['in_msg']['value'];
                                if (abs($tx_amount - $expected_nano) <= $tolerance) {
                                    // Found matching transaction
                                    if (isset($tx['hash'])) {
                                        $tx_hash = $tx['hash'];
                                        break;
                                    } elseif (isset($tx['transaction']['hash'])) {
                                        $tx_hash = $tx['transaction']['hash'];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Try TON API v2 with transaction hash
        $api_url = "https://tonapi.io/v2/blockchain/transactions/{$tx_hash}";
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => 10
            ]
        ]);
        
        $response = @file_get_contents($api_url, false, $context);
        
        if (!$response) {
            // Try alternative API endpoint - search by address
            $api_url2 = "https://toncenter.com/api/v2/getTransactions?address=" . urlencode($this->recipient_address) . "&limit=10&api_key=";
            $response = @file_get_contents($api_url2, false, $context);
            
            if (!$response) {
                return ['success' => false, 'verified' => false, 'error' => 'Failed to connect to TON API'];
            }
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            return ['success' => false, 'verified' => false, 'error' => 'Invalid API response'];
        }
        
        // Check if transaction exists
        if (isset($data['transaction'])) {
            $tx = $data['transaction'];
        } elseif (isset($data['result']) && is_array($data['result']) && count($data['result']) > 0) {
            // Alternative API format
            $tx = $data['result'][0];
        } else {
            return ['success' => false, 'verified' => false, 'error' => 'Transaction not found on blockchain'];
        }
        
        // Verify transaction is successful
        if (isset($tx['success']) && !$tx['success']) {
            return ['success' => true, 'verified' => false, 'error' => 'Transaction failed on blockchain'];
        }
        
        // Check if transaction is to our address
        $to_address = null;
        if (isset($tx['out_msgs']) && is_array($tx['out_msgs']) && count($tx['out_msgs']) > 0) {
            // This is outgoing transaction, skip
            return ['success' => true, 'verified' => false, 'error' => 'Transaction is outgoing, not incoming'];
        }
        
        // Check incoming messages
        if (isset($tx['in_msg'])) {
            $in_msg = $tx['in_msg'];
            $to_address = $in_msg['destination']['address'] ?? null;
            $amount_nano = $in_msg['value'] ?? 0;
            $from_addr = $in_msg['source']['address'] ?? null;
        } elseif (isset($tx['in_message'])) {
            $in_msg = $tx['in_message'];
            $to_address = $in_msg['destination']['address'] ?? null;
            $amount_nano = $in_msg['value'] ?? 0;
            $from_addr = $in_msg['source']['address'] ?? null;
        } else {
            return ['success' => true, 'verified' => false, 'error' => 'No incoming message found in transaction'];
        }
        
        // Normalize addresses for comparison
        $normalized_recipient = $this->normalizeAddress($this->recipient_address);
        $normalized_to = $this->normalizeAddress($to_address);
        
        if ($normalized_to !== $normalized_recipient) {
            return ['success' => true, 'verified' => false, 'error' => 'Transaction is not to our address'];
        }
        
        // Verify amount
        $amount_ton = (float)($amount_nano / 1000000000);
        $expected_nano = (int)($expected_amount_ton * 1000000000);
        
        // Allow small difference for fees
        $tolerance = 1000000; // 0.001 TON tolerance
        if (abs($amount_nano - $expected_nano) > $tolerance) {
            return [
                'success' => true, 
                'verified' => false, 
                'error' => "Amount mismatch. Expected: {$expected_amount_ton} TON, Got: {$amount_ton} TON"
            ];
        }
        
        // Verify sender if provided
        if ($from_address) {
            $normalized_from = $this->normalizeAddress($from_address);
            $normalized_tx_from = $this->normalizeAddress($from_addr);
            
            if ($normalized_from !== $normalized_tx_from) {
                return ['success' => true, 'verified' => false, 'error' => 'Sender address mismatch'];
            }
        }
        
        return [
            'success' => true,
            'verified' => true,
            'tx_data' => [
                'hash' => $tx_hash,
                'amount_nano' => $amount_nano,
                'amount_ton' => $amount_ton,
                'from' => $from_addr,
                'to' => $to_address,
                'timestamp' => $tx['utime'] ?? time()
            ]
        ];
    }
    
    /**
     * Normalize TON address to compare different formats
     */
    private function normalizeAddress($address) {
        if (empty($address)) {
            return '';
        }
        
        // Remove common prefixes and convert to lowercase
        $address = strtolower(trim($address));
        
        // If it's user-friendly format (UQ...), we need to decode it
        // For now, just compare as-is and handle common formats
        return $address;
    }
    
    /**
     * Check if transaction exists and is pending (not yet confirmed)
     */
    public function checkTransactionStatus($tx_hash) {
        $result = $this->verifyTransaction($tx_hash, 0); // Amount check not needed for status
        
        if (!$result['success']) {
            return ['status' => 'error', 'error' => $result['error']];
        }
        
        if ($result['verified']) {
            return ['status' => 'confirmed', 'tx_data' => $result['tx_data'] ?? null];
        }
        
        return ['status' => 'pending', 'error' => $result['error'] ?? 'Transaction not found'];
    }
}

