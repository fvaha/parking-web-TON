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
    
    $fixes = [];
    
    // Fix 1: Ensure all sensors have 'live' status
    $update_sensors = $sqlite->exec("UPDATE sensors SET status = 'live' WHERE status IS NULL OR status = ''");
    $fixes[] = "Updated sensors to 'live' status";
    
    // Fix 2: Sync sensors and parking spaces (1:1 relationship)
    $sync_result = $db->syncSensorsAndParkingSpaces();
    if ($sync_result['success']) {
        $fixes = array_merge($fixes, $sync_result['fixes']);
    } else {
        $fixes[] = "Warning: Could not sync sensors and parking spaces: " . ($sync_result['error'] ?? 'Unknown error');
    }
    
    // Fix 3: Ensure all 3 default zones exist
    $zones_check = $sqlite->query("SELECT COUNT(*) as count FROM parking_zones");
    $zones_count = $zones_check->fetchArray(SQLITE3_ASSOC)['count'];
    
    if ($zones_count < 3) {
        // Create default zones if they don't exist
        $default_zones = [
            [1, 'Downtown Zone', 'High-traffic downtown area with premium pricing', '#EF4444', 3.00, 30.00, 1, 1],
            [2, 'Residential Zone', 'Residential area with standard pricing', '#10B981', 2.00, 20.00, 0, 2],
            [3, 'Commercial Zone', 'Commercial area with moderate pricing', '#F59E0B', 2.50, 25.00, 0, 4]
        ];
        
        foreach ($default_zones as $zone_data) {
            // Check if zone exists
            $check = $sqlite->prepare("SELECT id FROM parking_zones WHERE id = ?");
            $check->bindValue(1, $zone_data[0]);
            $result = $check->execute();
            
            if (!$result->fetchArray()) {
                // Zone doesn't exist, create it
                $has_max_duration = false;
                try {
                    $check_result = $sqlite->query("PRAGMA table_info(parking_zones)");
                    while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                        if ($row['name'] === 'max_duration_hours') {
                            $has_max_duration = true;
                            break;
                        }
                    }
                } catch (Exception $e) {
                    $has_max_duration = false;
                }
                
                if ($has_max_duration) {
                    $stmt = $sqlite->prepare("
                        INSERT INTO parking_zones (id, name, description, color, hourly_rate, daily_rate, is_premium, max_duration_hours)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bindValue(1, $zone_data[0]);
                    $stmt->bindValue(2, $zone_data[1]);
                    $stmt->bindValue(3, $zone_data[2]);
                    $stmt->bindValue(4, $zone_data[3]);
                    $stmt->bindValue(5, $zone_data[4]);
                    $stmt->bindValue(6, $zone_data[5]);
                    $stmt->bindValue(7, $zone_data[6]);
                    $stmt->bindValue(8, $zone_data[7]);
                } else {
                    $stmt = $sqlite->prepare("
                        INSERT INTO parking_zones (id, name, description, color, hourly_rate, daily_rate, is_premium)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bindValue(1, $zone_data[0]);
                    $stmt->bindValue(2, $zone_data[1]);
                    $stmt->bindValue(3, $zone_data[2]);
                    $stmt->bindValue(4, $zone_data[3]);
                    $stmt->bindValue(5, $zone_data[4]);
                    $stmt->bindValue(6, $zone_data[5]);
                    $stmt->bindValue(7, $zone_data[6]);
                }
                $stmt->execute();
                $fixes[] = "Created zone: {$zone_data[1]}";
            }
        }
    }
    
    // Fix 4: Clean up zone_parking_spaces relationships for deleted parking spaces
    $deleted_relationships = $sqlite->exec("
        DELETE FROM zone_parking_spaces 
        WHERE parking_space_id NOT IN (
            SELECT id FROM parking_spaces
        )
    ");
    $deleted_count = $sqlite->changes();
    if ($deleted_count > 0) {
        $fixes[] = "Cleaned up {$deleted_count} zone-parking space relationships for deleted parking spaces";
    }
    
    // Fix 4b: Ensure zone_parking_spaces relationships exist for all valid parking spaces
    // Only migrate from sensors.zone_id for parking spaces that exist and have live sensors
    $sensors_with_zones = $sqlite->query("
        SELECT s.id as sensor_id, s.zone_id, ps.id as parking_space_id
        FROM sensors s
        JOIN parking_spaces ps ON s.id = ps.sensor_id
        WHERE s.status = 'live'
        AND s.zone_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM zone_parking_spaces zps 
            WHERE zps.parking_space_id = ps.id AND zps.zone_id = s.zone_id
        )
    ");
    
    $migrated_from_sensors = 0;
    while ($row = $sensors_with_zones->fetchArray(SQLITE3_ASSOC)) {
        // Double-check that parking space still exists
        $check_space = $sqlite->prepare("SELECT id FROM parking_spaces WHERE id = ?");
        $check_space->bindValue(1, $row['parking_space_id']);
        $space_result = $check_space->execute();
        if ($space_result->fetchArray()) {
            $stmt = $sqlite->prepare("
                INSERT OR IGNORE INTO zone_parking_spaces (zone_id, parking_space_id)
                VALUES (?, ?)
            ");
            $stmt->bindValue(1, $row['zone_id']);
            $stmt->bindValue(2, $row['parking_space_id']);
            $stmt->execute();
            $migrated_from_sensors++;
        }
    }
    
    if ($migrated_from_sensors > 0) {
        $fixes[] = "Migrated {$migrated_from_sensors} zone-parking space relationships from sensors.zone_id";
    }
    
    // Fix 5: Calculate and update missing duration_minutes and total_cost for parking_usage records
    $missing_data = $sqlite->query("
        SELECT 
            pu.id,
            pu.start_time,
            pu.end_time,
            pu.parking_space_id,
            COALESCE(z.hourly_rate, 2.0) as hourly_rate
        FROM parking_usage pu
        JOIN parking_spaces ps ON pu.parking_space_id = ps.id
        LEFT JOIN zone_parking_spaces zps ON ps.id = zps.parking_space_id
        LEFT JOIN parking_zones z ON zps.zone_id = z.id
        WHERE pu.end_time IS NOT NULL
        AND (pu.duration_minutes IS NULL OR pu.total_cost IS NULL)
    ");
    
    $fixed_count = 0;
    while ($row = $missing_data->fetchArray(SQLITE3_ASSOC)) {
        // Calculate duration in minutes
        $start_timestamp = strtotime($row['start_time']);
        $end_timestamp = strtotime($row['end_time']);
        $duration_minutes = (int)round(($end_timestamp - $start_timestamp) / 60);
        
        // Calculate cost: (duration_minutes / 60) * hourly_rate
        $hourly_rate = (float)($row['hourly_rate'] ?? 2.0);
        $total_cost = round(($duration_minutes / 60.0) * $hourly_rate, 2);
        
        // Update the record
        $update_stmt = $sqlite->prepare("
            UPDATE parking_usage 
            SET duration_minutes = ?, total_cost = ?
            WHERE id = ?
        ");
        $update_stmt->bindValue(1, $duration_minutes);
        $update_stmt->bindValue(2, $total_cost);
        $update_stmt->bindValue(3, $row['id']);
        $update_stmt->execute();
        
        $fixed_count++;
    }
    
    if ($fixed_count > 0) {
        $fixes[] = "Fixed {$fixed_count} parking_usage records with missing duration or cost";
    }
    
    // Get final counts
    $final_sensors = $sqlite->query("SELECT COUNT(*) as count FROM sensors WHERE status = 'live'");
    $final_sensors_count = $final_sensors->fetchArray(SQLITE3_ASSOC)['count'];
    
    $final_spaces = $sqlite->query("
        SELECT COUNT(*) as count 
        FROM parking_spaces ps
        JOIN sensors s ON ps.sensor_id = s.id
        WHERE s.status = 'live'
    ");
    $final_spaces_count = $final_spaces->fetchArray(SQLITE3_ASSOC)['count'];
    
    $final_zones = $sqlite->query("SELECT COUNT(*) as count FROM parking_zones");
    $final_zones_count = $final_zones->fetchArray(SQLITE3_ASSOC)['count'];
    
    // Count only valid zone_parking_spaces relationships (where parking space exists)
    $final_relationships = $sqlite->query("
        SELECT COUNT(*) as count 
        FROM zone_parking_spaces zps
        JOIN parking_spaces ps ON zps.parking_space_id = ps.id
    ");
    $final_relationships_count = $final_relationships->fetchArray(SQLITE3_ASSOC)['count'];
    
    echo json_encode([
        'success' => true,
        'fixes_applied' => $fixes,
        'final_counts' => [
            'live_sensors' => $final_sensors_count,
            'parking_spaces_linked_to_live_sensors' => $final_spaces_count,
            'zones' => $final_zones_count,
            'zone_parking_spaces_relationships' => $final_relationships_count
        ],
        'message' => 'Database fixes applied successfully'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    http_response_code(500);
    error_log('API fix-database.php error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>

