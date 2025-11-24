<?php
header('Content-Type: application/json');
require_once '../config/database.php';

try {
    $db = new Database();
    
    // First, test the query directly
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
    }
    
    // Now call getParkingSpaces with error handling
    $parking_spaces = [];
    $error_occurred = false;
    $error_message = '';
    $error_trace = null;
    
    // Enable error reporting to see all errors
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        error_log("test-getParkingSpaces.php: About to call getParkingSpaces()");
        $parking_spaces = $db->getParkingSpaces();
        error_log("test-getParkingSpaces.php: getParkingSpaces() returned " . count($parking_spaces) . " spaces");
    } catch (Exception $e) {
        $error_occurred = true;
        $error_message = $e->getMessage();
        $error_trace = $e->getTraceAsString();
        error_log("test-getParkingSpaces.php: Exception caught - " . $error_message);
        error_log("test-getParkingSpaces.php: Exception trace - " . $error_trace);
    } catch (Throwable $e) {
        $error_occurred = true;
        $error_message = $e->getMessage();
        $error_trace = $e->getTraceAsString();
        error_log("test-getParkingSpaces.php: Throwable caught - " . $error_message);
        error_log("test-getParkingSpaces.php: Throwable trace - " . $error_trace);
    }
    
    // Also check if there's a database connection issue
    $db_check = $db->query("SELECT COUNT(*) as count FROM parking_spaces");
    $db_count = 0;
    if ($db_check) {
        $db_row = $db_check->fetchArray(SQLITE3_ASSOC);
        $db_count = $db_row['count'] ?? 0;
    }
    
    // Check zone_parking_spaces relationships
    $zone_relationships = [];
    $zone_relationships_check = $db->query("SELECT * FROM zone_parking_spaces");
    if ($zone_relationships_check) {
        while ($row = $zone_relationships_check->fetchArray(SQLITE3_ASSOC)) {
            $zone_relationships[] = $row;
        }
    }
    
    // Check zones
    $zones_check = $db->query("SELECT id, name, is_premium FROM parking_zones");
    $zones_list = [];
    if ($zones_check) {
        while ($row = $zones_check->fetchArray(SQLITE3_ASSOC)) {
            $zones_list[] = $row;
        }
    }
    
    // Check if parking spaces from getParkingSpaces have zones
    $spaces_with_zones = 0;
    $spaces_without_zones = [];
    $spaces_with_zones_details = [];
    foreach ($parking_spaces as $space) {
        if (isset($space['zone']) && !empty($space['zone'])) {
            $spaces_with_zones++;
            $spaces_with_zones_details[] = [
                'space_id' => $space['id'],
                'zone' => $space['zone']
            ];
        } else {
            $spaces_without_zones[] = $space['id'];
        }
    }
    
    // Test zone query directly with space IDs from getParkingSpaces
    $test_zone_query_results = [];
    if (!empty($parking_spaces)) {
        $space_ids = array_map(function($space) {
            return $space['id'];
        }, $parking_spaces);
        $space_ids_string = implode(',', $space_ids);
        
        // Test 1: Check if relationships exist
        $test_query_1 = "SELECT COUNT(*) as count FROM zone_parking_spaces WHERE parking_space_id IN ({$space_ids_string})";
        $test_result_1 = $db->query($test_query_1);
        $test_count_1 = 0;
        if ($test_result_1) {
            $row = $test_result_1->fetchArray(SQLITE3_ASSOC);
            $test_count_1 = $row['count'] ?? 0;
        }
        
        // Test 2: Get actual zone data
        $test_query_2 = "
            SELECT 
                zps.parking_space_id,
                zps.zone_id,
                z.id as zone_id_from_zones,
                z.name as zone_name,
                z.is_premium,
                z.hourly_rate,
                z.daily_rate,
                z.color
            FROM zone_parking_spaces zps
            JOIN parking_zones z ON zps.zone_id = z.id
            WHERE zps.parking_space_id IN ({$space_ids_string})
            ORDER BY zps.parking_space_id
        ";
        $test_result_2 = $db->query($test_query_2);
        if ($test_result_2) {
            while ($row = $test_result_2->fetchArray(SQLITE3_ASSOC)) {
                $test_zone_query_results[] = $row;
            }
        }
        
        // Test 3: Check each space individually
        $individual_space_tests = [];
        foreach ($space_ids as $space_id) {
            $test_query_3 = "
                SELECT z.*, zps.parking_space_id
                FROM parking_zones z
                JOIN zone_parking_spaces zps ON z.id = zps.zone_id
                WHERE zps.parking_space_id = {$space_id}
                LIMIT 1
            ";
            $test_result_3 = $db->query($test_query_3);
            $zone_data = null;
            if ($test_result_3) {
                $zone_row = $test_result_3->fetchArray(SQLITE3_ASSOC);
                if ($zone_row) {
                    $zone_data = $zone_row;
                }
            }
            
            // Find space in getParkingSpaces result
            $space_in_result = null;
            foreach ($parking_spaces as $sp) {
                if ($sp['id'] == $space_id) {
                    $space_in_result = $sp;
                    break;
                }
            }
            
            $individual_space_tests[] = [
                'space_id' => $space_id,
                'space_id_type' => gettype($space_id),
                'zone_found_in_db' => $zone_data !== null,
                'zone_data_from_db' => $zone_data,
                'space_has_zone_in_result' => isset($space_in_result['zone']) && !empty($space_in_result['zone']),
                'space_zone_in_result' => $space_in_result['zone'] ?? null,
                'space_keys' => $space_in_result ? array_keys($space_in_result) : []
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'direct_query_count' => count($test_rows),
        'direct_query_rows' => $test_rows,
        'getParkingSpaces_count' => count($parking_spaces),
        'getParkingSpaces_spaces' => $parking_spaces,
        'first_space' => !empty($parking_spaces) ? $parking_spaces[0] : null,
        'first_space_keys' => !empty($parking_spaces) ? array_keys($parking_spaces[0]) : [],
        'first_space_has_zone' => !empty($parking_spaces) ? (isset($parking_spaces[0]['zone']) && !empty($parking_spaces[0]['zone'])) : false,
        'error_occurred' => $error_occurred,
        'error_message' => $error_message,
        'error_trace' => $error_trace ?? null,
        'db_total_spaces' => $db_count,
        'zone_relationships' => $zone_relationships,
        'zone_relationships_count' => count($zone_relationships),
        'zones_list' => $zones_list,
        'zones_count' => count($zones_list),
        'spaces_with_zones' => $spaces_with_zones,
        'spaces_with_zones_details' => $spaces_with_zones_details,
        'spaces_without_zones' => $spaces_without_zones,
        'spaces_without_zones_count' => count($spaces_without_zones),
        'test_zone_query' => [
            'space_ids_used' => $space_ids ?? [],
            'space_ids_string' => $space_ids_string ?? '',
            'relationships_count' => $test_count_1,
            'zone_query_results' => $test_zone_query_results,
            'individual_space_tests' => $individual_space_tests ?? []
        ]
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

