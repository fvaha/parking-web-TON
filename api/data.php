<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get both sensors and parking spaces
        try {
            $sensors = $db->getSensors();
            error_log('API data.php - getSensors returned ' . count($sensors) . ' sensors');
        } catch (Throwable $e) {
            error_log('API data.php - getSensors error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $sensors = [];
        }
        
        try {
            $parking_spaces = $db->getParkingSpaces();
            error_log('API data.php - getParkingSpaces returned ' . count($parking_spaces) . ' parking spaces');
            if (count($parking_spaces) === 0) {
                error_log('API data.php - WARNING: No parking spaces returned. This might indicate a database issue or all sensors have non-live status.');
            }
        } catch (Throwable $e) {
            error_log('API data.php - getParkingSpaces error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $parking_spaces = [];
        }
        
        echo json_encode([
            'success' => true,
            'sensors' => $sensors,
            'parking_spaces' => $parking_spaces,
            'debug' => [
                'sensors_count' => count($sensors),
                'parking_spaces_count' => count($parking_spaces)
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    error_log('API data.php error: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Don't expose internal error details to client in production
    $error_message = 'Internal server error';
    if (defined('DEBUG') && DEBUG) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
    
    echo json_encode([
        'success' => false,
        'error' => $error_message
    ], JSON_UNESCAPED_UNICODE);
}
?>
