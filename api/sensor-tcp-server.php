#!/usr/bin/env php
<?php
/**
 * Parking Sensor TCP Server
 * Listens on port 6000 for hex data from parking sensors
 * Automatically updates parking space status in the database
 */

// Prevent execution via web server
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line\n");
}

// Check if socket extension is loaded
if (!extension_loaded('sockets')) {
    die("Error: PHP sockets extension is not loaded. Please install php-sockets extension.\n");
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Configuration
define('TCP_PORT', 6000);
define('TCP_HOST', '0.0.0.0');
define('LOG_DIR', __DIR__ . '/../logs');
define('LOG_FILE', LOG_DIR . '/real-sensors.log');
define('MAX_LOG_SIZE', 10 * 1024 * 1024); // 10MB

// Ensure log directory exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

/**
 * Write log entry to file (JSON Lines format)
 */
function writeLog($action, $wpsd_id, $wdc_id, $raw_hex, $parsed_data, $message = '') {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'wpsd_id' => $wpsd_id ?? null,
        'wdc_id' => $wdc_id ?? null,
        'action' => $action, // received, updated, ignored_reservation, ignored_unknown, error
        'raw_hex' => $raw_hex ?? null,
        'parsed_data' => $parsed_data ?? null,
        'message' => $message
    ];
    
    $log_line = json_encode($log_entry) . "\n";
    
    // Rotate log if too large
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > MAX_LOG_SIZE) {
        rotateLog();
    }
    
    file_put_contents(LOG_FILE, $log_line, FILE_APPEND | LOCK_EX);
}

/**
 * Rotate log file
 */
function rotateLog() {
    $max_backups = 5;
    
    // Rotate existing backups
    for ($i = $max_backups - 1; $i >= 1; $i--) {
        $old_file = LOG_FILE . '.' . $i;
        $new_file = LOG_FILE . '.' . ($i + 1);
        if (file_exists($old_file)) {
            rename($old_file, $new_file);
        }
    }
    
    // Move current log to .1
    if (file_exists(LOG_FILE)) {
        rename(LOG_FILE, LOG_FILE . '.1');
    }
}

/**
 * Rearrange ID (convert from little-endian to big-endian)
 */
function rearrangeId($id) {
    if (strlen($id) !== 8) {
        return $id;
    }
    return substr($id, 6, 2) . substr($id, 4, 2) . substr($id, 2, 2) . substr($id, 0, 2);
}

/**
 * Parse sensor data from raw hex bytes
 */
function parseSensorData($raw_data) {
    try {
        $hex_string = bin2hex($raw_data);
        
        if (strlen($hex_string) < 28) {
            throw new Exception("Data too short: " . strlen($hex_string) . " bytes");
        }
        
        // Extract IDs and status indicator
        $wdc_id = strtoupper(substr($hex_string, 12, 8));
        $wpsd_id = strtoupper(substr($hex_string, 20, 8));
        $occupancy_status_indicator = strtoupper(substr($hex_string, 10, 2));
        
        // Validate IDs
        if (!preg_match('/^[0-9A-F]{8}$/', $wdc_id)) {
            throw new Exception("Invalid wdc_id format: {$wdc_id}");
        }
        if (!preg_match('/^[0-9A-F]{8}$/', $wpsd_id)) {
            throw new Exception("Invalid wpsd_id format: {$wpsd_id}");
        }
        
        // Rearrange IDs
        $rearranged_wdc_id = rearrangeId($wdc_id);
        $rearranged_wpsd_id = rearrangeId($wpsd_id);
        
        // Validate occupancy status
        if (!in_array($occupancy_status_indicator, ['00', '01', '02', '03'])) {
            throw new Exception("Invalid occupancy status: {$occupancy_status_indicator}");
        }
        
        // Map status
        $occupancy_status = in_array($occupancy_status_indicator, ['01', '02', '03']) ? 'Occupied' : 'Vacant';
        
        return [
            'wdc_id' => $rearranged_wdc_id,
            'wpsd_id' => $rearranged_wpsd_id,
            'occupancy_status' => $occupancy_status,
            'raw_hex' => $hex_string
        ];
    } catch (Exception $e) {
        error_log("Error parsing sensor data: " . $e->getMessage());
        return null;
    }
}

/**
 * Find sensor by WPSD ID in database
 */
function findSensorByWpsdId($db, $wpsd_id) {
    try {
        $stmt = $db->prepare("SELECT id, wpsd_id, wdc_id, status FROM sensors WHERE wpsd_id = ?");
        $stmt->bindValue(1, $wpsd_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($result) {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            return $row ? $row : null;
        }
        return null;
    } catch (Exception $e) {
        error_log("Error finding sensor: " . $e->getMessage());
        return null;
    }
}

/**
 * Find parking space by sensor ID
 */
function findParkingSpaceBySensorId($db, $sensor_id) {
    try {
        $stmt = $db->prepare("SELECT id, status FROM parking_spaces WHERE sensor_id = ?");
        $stmt->bindValue(1, $sensor_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        if ($result) {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            return $row ? $row : null;
        }
        return null;
    } catch (Exception $e) {
        error_log("Error finding parking space: " . $e->getMessage());
        return null;
    }
}

/**
 * Process sensor data and update parking space status
 */
function processSensorData($db, $parsed_data) {
    $wpsd_id = $parsed_data['wpsd_id'];
    $wdc_id = $parsed_data['wdc_id'];
    $occupancy_status = $parsed_data['occupancy_status'];
    $raw_hex = $parsed_data['raw_hex'];
    
    // Find sensor in database
    $sensor = findSensorByWpsdId($db, $wpsd_id);
    
    if (!$sensor) {
        writeLog('ignored_unknown', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Sensor not found in database");
        return;
    }
    
    // Check if sensor is live
    if ($sensor['status'] !== 'live') {
        writeLog('ignored_unknown', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Sensor status is not 'live'");
        return;
    }
    
    // Find parking space
    $parking_space = findParkingSpaceBySensorId($db, $sensor['id']);
    
    if (!$parking_space) {
        writeLog('error', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Parking space not found for sensor");
        return;
    }
    
    $current_status = $parking_space['status'];
    $parking_space_id = $parking_space['id'];
    
    // CRITICAL: Never update if status is 'reserved'
    if ($current_status === 'reserved') {
        writeLog('ignored_reservation', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Parking space is reserved, ignoring sensor update");
        return;
    }
    
    // Map Occupied/Vacant to occupied/vacant
    $new_status = strtolower($occupancy_status);
    
    // Only update if status actually changed
    if ($current_status === $new_status) {
        writeLog('received', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Status unchanged: {$current_status}");
        return;
    }
    
    // Update parking space status
    try {
        $result = $db->updateParkingSpaceStatusFromSensor($parking_space_id, $new_status);
        
        if ($result['success']) {
            $occupied_since = ($new_status === 'occupied') ? date('Y-m-d H:i:s') : null;
            writeLog('updated', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Status updated from {$current_status} to {$new_status}");
        } else {
            writeLog('error', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Failed to update: " . ($result['error'] ?? 'Unknown error'));
        }
    } catch (Exception $e) {
        writeLog('error', $wpsd_id, $wdc_id, $raw_hex, $parsed_data, "Exception: " . $e->getMessage());
    }
}

/**
 * Handle client connection
 */
function handleClient($client_socket, $db) {
    $addr = '';
    $port = 0;
    $client_address = @socket_getpeername($client_socket, $addr, $port) ? "{$addr}:{$port}" : "unknown";
    error_log("Accepted connection from {$client_address}");
    
    try {
        while (true) {
            $data = @socket_read($client_socket, 1024, PHP_BINARY_READ);
            
            if ($data === false || strlen($data) === 0) {
                break;
            }
            
            // Parse sensor data
            $parsed_data = parseSensorData($data);
            
            if ($parsed_data) {
                writeLog('received', $parsed_data['wpsd_id'], $parsed_data['wdc_id'], $parsed_data['raw_hex'], $parsed_data);
                processSensorData($db, $parsed_data);
            } else {
                $hex_string = bin2hex($data);
                writeLog('error', null, null, $hex_string, null, "Failed to parse sensor data");
            }
        }
    } catch (Exception $e) {
        error_log("Error handling client: " . $e->getMessage());
        writeLog('error', null, null, null, null, "Client error: " . $e->getMessage());
    } finally {
        @socket_close($client_socket);
        error_log("Connection closed from {$client_address}");
    }
}

/**
 * Start TCP server
 */
function startTcpServer() {
    // Create socket
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    
    if ($socket === false) {
        error_log("Failed to create socket: " . socket_strerror(socket_last_error()));
        exit(1);
    }
    
    // Set socket options
    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    
    // Bind socket
    if (!socket_bind($socket, TCP_HOST, TCP_PORT)) {
        $error = socket_strerror(socket_last_error($socket));
        error_log("Failed to bind socket: {$error}");
        socket_close($socket);
        exit(1);
    }
    
    // Listen for connections
    if (!socket_listen($socket, 5)) {
        $error = socket_strerror(socket_last_error($socket));
        error_log("Failed to listen on socket: {$error}");
        socket_close($socket);
        exit(1);
    }
    
    error_log("TCP server started on " . TCP_HOST . ":" . TCP_PORT);
    writeLog('received', null, null, null, null, "TCP server started");
    
    // Initialize database connection
    $db = new Database();
    
    // Main loop - accept connections
    while (true) {
        $client_socket = @socket_accept($socket);
        
        if ($client_socket === false) {
            continue;
        }
        
        // Handle client in current process (for simplicity)
        // In production, you might want to fork or use async I/O
        handleClient($client_socket, $db);
    }
    
    socket_close($socket);
}

// Start server
try {
    startTcpServer();
} catch (Exception $e) {
    error_log("Fatal error: " . $e->getMessage());
    writeLog('error', null, null, null, null, "Fatal error: " . $e->getMessage());
    exit(1);
}
?>

