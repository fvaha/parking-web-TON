<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Use reflection to access private db property, or use a public method
        // Let's use getParkingSpaces and getParkingZones which are public
        $parking_spaces = $db->getParkingSpaces();
        $zones = $db->getParkingZones();
        
        // Build assignments array from parking spaces
        $assignments = [];
        foreach ($parking_spaces as $space) {
            $assignments[] = [
                'space_id' => $space['id'],
                'sensor_id' => $space['sensor_id'],
                'zone_id' => $space['zone']['id'] ?? null,
                'zone_name' => $space['zone']['name'] ?? null,
                'is_premium' => $space['zone']['is_premium'] ?? false,
                'has_assignment' => !empty($space['zone'])
            ];
        }
        
        echo json_encode([
            'success' => true,
            'assignments' => $assignments,
            'zones' => $zones,
            'parking_spaces_count' => count($parking_spaces),
            'zones_count' => count($zones),
            'assigned_count' => count(array_filter($assignments, function($a) { return $a['has_assignment']; }))
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
    error_log('API check-zone-assignments.php error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

