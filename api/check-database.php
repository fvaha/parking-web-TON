<?php
header('Content-Type: application/json');
require_once '../config/database.php';

try {
    $db = new Database();
    
    $results = [];
    
    // 1. Check total parking spaces
    $total_spaces = $db->query("SELECT COUNT(*) as count FROM parking_spaces");
    $total_count = $total_spaces ? $total_spaces->fetchArray(SQLITE3_ASSOC)['count'] : 0;
    $results['total_parking_spaces'] = $total_count;
    
    // 2. Check live sensors
    $live_sensors = $db->query("SELECT COUNT(*) as count FROM sensors WHERE status = 'live'");
    $live_count = $live_sensors ? $live_sensors->fetchArray(SQLITE3_ASSOC)['count'] : 0;
    $results['live_sensors'] = $live_count;
    
    // 3. Check parking spaces linked to live sensors
    $linked = $db->query("
        SELECT COUNT(*) as count 
        FROM parking_spaces ps
        INNER JOIN sensors s ON ps.sensor_id = s.id
        WHERE s.status = 'live'
    ");
    $linked_count = $linked ? $linked->fetchArray(SQLITE3_ASSOC)['count'] : 0;
    $results['linked_spaces'] = $linked_count;
    
    // 4. Get sample parking spaces with their sensors
    $sample = $db->query("
        SELECT 
            ps.id as space_id,
            ps.sensor_id,
            ps.status as space_status,
            s.id as sensor_db_id,
            s.name as sensor_name,
            s.status as sensor_status,
            s.wpsd_id,
            s.wdc_id
        FROM parking_spaces ps
        LEFT JOIN sensors s ON ps.sensor_id = s.id
        LIMIT 10
    ");
    
    $samples = [];
    while ($row = $sample->fetchArray(SQLITE3_ASSOC)) {
        $samples[] = $row;
    }
    $results['sample_spaces'] = $samples;
    
    // 5. Test the exact query from getParkingSpaces
    $test_query = "
        SELECT 
            ps.id,
            ps.sensor_id,
            ps.status,
            ps.license_plate,
            ps.reservation_time,
            ps.occupied_since,
            NULL as payment_tx_hash,
            ps.created_at,
            ps.updated_at,
            s.name as sensor_name,
            s.street_name,
            s.latitude,
            s.longitude
        FROM parking_spaces ps
        INNER JOIN sensors s ON ps.sensor_id = s.id
        WHERE s.status = 'live'
        ORDER BY ps.id
        LIMIT 5
    ";
    
    $test_result = $db->query($test_query);
    $test_rows = [];
    if ($test_result) {
        while ($row = $test_result->fetchArray(SQLITE3_ASSOC)) {
            $test_rows[] = $row;
        }
    } else {
        $results['test_query_error'] = $db->lastErrorMsg();
    }
    $results['test_query_results'] = $test_rows;
    $results['test_query_count'] = count($test_rows);
    
    // 6. Check if payment_tx_hash column exists
    $has_payment_col = false;
    $col_check = $db->query("PRAGMA table_info(parking_spaces)");
    while ($col = $col_check->fetchArray(SQLITE3_ASSOC)) {
        if ($col['name'] === 'payment_tx_hash') {
            $has_payment_col = true;
            break;
        }
    }
    $results['has_payment_tx_hash_column'] = $has_payment_col;
    
    // 7. Check sensor_id relationships
    $orphan_spaces = $db->query("
        SELECT ps.id, ps.sensor_id
        FROM parking_spaces ps
        LEFT JOIN sensors s ON ps.sensor_id = s.id
        WHERE s.id IS NULL
    ");
    $orphans = [];
    while ($row = $orphan_spaces->fetchArray(SQLITE3_ASSOC)) {
        $orphans[] = $row;
    }
    $results['orphan_spaces'] = $orphans;
    $results['orphan_count'] = count($orphans);
    
    echo json_encode([
        'success' => true,
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>

