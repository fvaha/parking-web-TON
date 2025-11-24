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
            
            // Attach zone info for each space - optimized to use single query
            if (!empty($parking_spaces)) {
                // Get all space IDs
                $space_ids = array_map(function($space) {
                    return (int)$space['id'];
                }, $parking_spaces);
                
                // Get all zones for these spaces in one query
                $space_ids_string = implode(',', $space_ids);
                $zones_query = "
                    SELECT 
                        zps.parking_space_id,
                        z.id,
                        z.name,
                        z.color,
                        z.hourly_rate,
                        z.daily_rate,
                        z.is_premium,
                        z.max_duration_hours
                    FROM zone_parking_spaces zps
                    JOIN parking_zones z ON zps.zone_id = z.id
                    WHERE zps.parking_space_id IN ({$space_ids_string})
                ";
                
                $zones_result = $db->query($zones_query);
                $zones_map = [];
                if ($zones_result) {
                    while ($zone_row = $zones_result->fetchArray(SQLITE3_ASSOC)) {
                        $space_id = (int)$zone_row['parking_space_id'];
                        if (!isset($zones_map[$space_id])) {
                            $zones_map[$space_id] = [
                                'id' => (string)$zone_row['id'],
                                'name' => $zone_row['name'],
                                'color' => $zone_row['color'],
                                'hourly_rate' => isset($zone_row['hourly_rate']) ? (float)$zone_row['hourly_rate'] : null,
                                'daily_rate' => isset($zone_row['daily_rate']) ? (float)$zone_row['daily_rate'] : null,
                                'is_premium' => ($zone_row['is_premium'] == 1 || $zone_row['is_premium'] === true || $zone_row['is_premium'] === '1'),
                                'max_duration_hours' => isset($zone_row['max_duration_hours']) ? (int)$zone_row['max_duration_hours'] : null
                            ];
                        }
                    }
                }
                
                // Attach zones to spaces
                foreach ($parking_spaces as &$space) {
                    $space_id = (int)$space['id'];
                    if (isset($zones_map[$space_id])) {
                        $space['zone'] = $zones_map[$space_id];
                    } else {
                        $space['zone'] = null;
                    }
                }
                unset($space); // Break reference
            }
        } catch (Throwable $e) {
            error_log('API data.php - getParkingSpaces error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $parking_spaces = [];
        }
        
        // Add debug information to response
        $debug_info = [
            'sensors_count' => count($sensors),
            'parking_spaces_count' => count($parking_spaces),
            'server_time' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION
        ];
        
        // If no parking spaces, add more debug info
        if (count($parking_spaces) === 0) {
            try {
                // Quick check: count total parking spaces in DB
                $db_check = new Database();
                $total_check = $db_check->query("SELECT COUNT(*) as count FROM parking_spaces");
                $total_count = $total_check ? $total_check->fetchArray(SQLITE3_ASSOC)['count'] : 0;
                $debug_info['total_parking_spaces_in_db'] = $total_count;
                
                // Check live sensors
                $live_check = $db_check->query("SELECT COUNT(*) as count FROM sensors WHERE status = 'live'");
                $live_count = $live_check ? $live_check->fetchArray(SQLITE3_ASSOC)['count'] : 0;
                $debug_info['live_sensors_count'] = $live_count;
                
                // Check linked spaces
                $linked_check = $db_check->query("
                    SELECT COUNT(*) as count 
                    FROM parking_spaces ps
                    JOIN sensors s ON ps.sensor_id = s.id
                    WHERE s.status = 'live'
                ");
                $linked_count = $linked_check ? $linked_check->fetchArray(SQLITE3_ASSOC)['count'] : 0;
                $debug_info['linked_spaces_count'] = $linked_count;
            } catch (Exception $e) {
                $debug_info['debug_check_error'] = $e->getMessage();
            }
        }
        
        echo json_encode([
            'success' => true,
            'sensors' => $sensors,
            'parking_spaces' => $parking_spaces,
            'debug' => $debug_info
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
    
    // Check for common issues
    $error_details = [];
    $error_message = 'Internal server error';
    
    // Check if SQLite3 extension is loaded
    if (!extension_loaded('sqlite3')) {
        $error_message = 'SQLite3 extension is not available';
        $error_details['sqlite3_extension'] = false;
    } else {
        $error_details['sqlite3_extension'] = true;
    }
    
    // Check database file path
    $dbPath = __DIR__ . '/../database/parking.db';
    $error_details['db_path'] = $dbPath;
    $error_details['db_exists'] = file_exists($dbPath);
    $error_details['db_readable'] = is_readable($dbPath);
    $error_details['db_writable'] = is_writable($dbPath);
    
    // Check database directory
    $dbDir = dirname($dbPath);
    $error_details['db_dir_exists'] = is_dir($dbDir);
    $error_details['db_dir_writable'] = is_writable($dbDir);
    
    // In development or if DEBUG is enabled, show more details
    $is_debug = (defined('DEBUG') && DEBUG) || 
                ($_SERVER['SERVER_NAME'] ?? '') === 'localhost' ||
                strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                (isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true'));
    
    if ($is_debug) {
        $error_message = 'Database error: ' . $e->getMessage();
        $error_details['exception_message'] = $e->getMessage();
        $error_details['exception_file'] = $e->getFile();
        $error_details['exception_line'] = $e->getLine();
    }
    
    echo json_encode([
        'success' => false,
        'error' => $error_message,
        'details' => $error_details
    ], JSON_UNESCAPED_UNICODE);
}
?>
