<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    // Get raw database connection for direct queries
    $conn = new ReflectionClass($db);
    $dbProperty = $conn->getProperty('db');
    $dbProperty->setAccessible(true);
    $sqlite = $dbProperty->getValue($db);
    
    $diagnostics = [];
    
    // Check sensors
    $sensors_result = $sqlite->query("SELECT id, name, status, COUNT(*) as count FROM sensors GROUP BY status");
    $sensors_by_status = [];
    while ($row = $sensors_result->fetchArray(SQLITE3_ASSOC)) {
        $sensors_by_status[$row['status']] = $row['count'];
    }
    $diagnostics['sensors'] = [
        'by_status' => $sensors_by_status,
        'total' => array_sum($sensors_by_status)
    ];
    
    // Check parking spaces
    $spaces_result = $sqlite->query("SELECT COUNT(*) as count FROM parking_spaces");
    $spaces_count = $spaces_result->fetchArray(SQLITE3_ASSOC)['count'];
    $diagnostics['parking_spaces'] = [
        'total' => $spaces_count
    ];
    
    // Check parking spaces linked to live sensors
    $linked_result = $sqlite->query("
        SELECT COUNT(*) as count 
        FROM parking_spaces ps
        JOIN sensors s ON ps.sensor_id = s.id
        WHERE s.status = 'live'
    ");
    $linked_count = $linked_result->fetchArray(SQLITE3_ASSOC)['count'];
    $diagnostics['parking_spaces_linked_to_live_sensors'] = $linked_count;
    
    // Check zones
    $zones_result = $sqlite->query("SELECT COUNT(*) as count FROM parking_zones");
    $zones_count = $zones_result->fetchArray(SQLITE3_ASSOC)['count'];
    $diagnostics['zones'] = [
        'total' => $zones_count
    ];
    
    // Check zone_parking_spaces relationships
    $zone_spaces_result = $sqlite->query("SELECT COUNT(*) as count FROM zone_parking_spaces");
    $zone_spaces_count = $zone_spaces_result->fetchArray(SQLITE3_ASSOC)['count'];
    $diagnostics['zone_parking_spaces_relationships'] = $zone_spaces_count;
    
    // Get sample data
    $sample_sensors = $sqlite->query("SELECT id, name, status FROM sensors LIMIT 5");
    $samples = [];
    while ($row = $sample_sensors->fetchArray(SQLITE3_ASSOC)) {
        $samples[] = $row;
    }
    $diagnostics['sample_sensors'] = $samples;
    
    echo json_encode([
        'success' => true,
        'diagnostics' => $diagnostics
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    http_response_code(500);
    error_log('API debug-database.php error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>

