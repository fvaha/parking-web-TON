<?php
namespace TelegramBot\Services;

use TelegramBot\Services\DatabaseService;

// Ensure config is loaded
if (!defined('API_BASE_URL')) {
    require_once __DIR__ . '/../config.php';
}

class ParkingService {
    private $db;
    private $api_base_url;
    
    public function __construct() {
        $db_service = new DatabaseService();
        $this->db = $db_service->getDatabase();
        $this->api_base_url = defined('API_BASE_URL') ? API_BASE_URL : 'https://parkiraj.info';
    }
    
    public function getParkingSpaces() {
        return $this->db->getParkingSpaces();
    }
    
    public function getAvailableSpaces() {
        $spaces = $this->getParkingSpaces();
        return array_filter($spaces, function($space) {
            return $space['status'] === 'vacant';
        });
    }
    
    public function getSpaceById($space_id) {
        $spaces = $this->getParkingSpaces();
        foreach ($spaces as $space) {
            if ($space['id'] == $space_id) {
                return $space;
            }
        }
        return null;
    }
    
    public function getZones() {
        return $this->db->getParkingZones();
    }
    
    public function reserveSpace($space_id, $license_plate, $payment_tx_hash = null) {
        $current_time = date('Y-m-d H:i:s');
        
        $data = [
            'status' => 'reserved',
            'license_plate' => $license_plate,
            'reservation_time' => $current_time
        ];
        
        if ($payment_tx_hash) {
            $data['payment_tx_hash'] = $payment_tx_hash;
        }
        
        // Get API key from environment or config
        $api_key = getenv('WEB_APP_API_KEY') ?: getenv('BOT_API_KEY') ?: '';
        
        $headers = [
            'Content-Type: application/json',
            'User-Agent: TelegramBot/1.0 PHP/' . PHP_VERSION
        ];
        if (!empty($api_key)) {
            $headers[] = 'X-API-Key: ' . $api_key;
        }
        
        $url = $this->api_base_url . '/api/parking-spaces.php/' . $space_id;
        
        error_log("ParkingService::reserveSpace - URL: {$url}");
        error_log("ParkingService::reserveSpace - Data: " . json_encode($data));
        error_log("ParkingService::reserveSpace - API Key: " . (!empty($api_key) ? 'SET' : 'NOT SET'));
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        error_log("ParkingService::reserveSpace - HTTP Code: {$http_code}");
        error_log("ParkingService::reserveSpace - Response: " . substr($response, 0, 500));
        if ($curl_error) {
            error_log("ParkingService::reserveSpace - CURL Error: {$curl_error}");
        }
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            if ($result && isset($result['success'])) {
                return $result['success'];
            } else {
                error_log("ParkingService::reserveSpace - Invalid response format: " . $response);
                return false;
            }
        } else {
            // Log error details
            $error_data = json_decode($response, true);
            $error_msg = $error_data['error'] ?? "HTTP {$http_code}";
            error_log("ParkingService::reserveSpace - Failed: {$error_msg}");
            return false;
        }
    }
    
    public function getActiveReservations($license_plate) {
        $spaces = $this->getParkingSpaces();
        return array_filter($spaces, function($space) use ($license_plate) {
            return ($space['status'] === 'reserved' || $space['status'] === 'occupied') 
                && $space['license_plate'] === $license_plate;
        });
    }
    
    public function getUniqueStreets() {
        $spaces = $this->getAvailableSpaces();
        $streets = [];
        foreach ($spaces as $space) {
            if (!empty($space['street_name'])) {
                $street_name = $space['street_name'];
                if (!isset($streets[$street_name])) {
                    $streets[$street_name] = 0;
                }
                $streets[$street_name]++;
            }
        }
        ksort($streets);
        return $streets;
    }
    
    public function getSpacesByStreet($street_name) {
        $spaces = $this->getAvailableSpaces();
        return array_filter($spaces, function($space) use ($street_name) {
            return isset($space['street_name']) && $space['street_name'] === $street_name;
        });
    }
}

