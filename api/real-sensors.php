<?php
session_start();
header('Content-Type: application/json');
require_once 'cors_helper.php';
require_once 'security_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

// Check if user is authenticated and is superadmin
function checkSuperAdmin() {
    if (!isset($_SESSION['admin_user'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Not authenticated'
        ]);
        exit();
    }
    
    if ($_SESSION['admin_user']['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Access denied. Superadmin only.'
        ]);
        exit();
    }
    
    return $_SESSION['admin_user']['id'];
}

/**
 * Check if TCP server is running
 */
function checkTcpServerStatus() {
    $output = [];
    $return_var = 0;
    @exec('systemctl is-active parking-sensor-server 2>&1', $output, $return_var);
    
    if ($return_var === 0 && !empty($output) && $output[0] === 'active') {
        return 'running';
    }
    return 'stopped';
}

/**
 * Read log file and parse JSON lines
 */
function readLogFile($limit = 100, $offset = 0, $filters = []) {
    $log_file = __DIR__ . '/../logs/real-sensors.log';
    $logs = [];
    
    if (!file_exists($log_file)) {
        return $logs;
    }
    
    // Read all log files (current + rotated)
    $log_files = [$log_file];
    for ($i = 1; $i <= 5; $i++) {
        $rotated_file = $log_file . '.' . $i;
        if (file_exists($rotated_file)) {
            $log_files[] = $rotated_file;
        }
    }
    
    // Read lines from all files (newest first)
    $all_lines = [];
    foreach ($log_files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            $all_lines = array_merge($all_lines, $lines);
        }
    }
    
    // Reverse to get newest first
    $all_lines = array_reverse($all_lines);
    
    // Parse and filter
    foreach ($all_lines as $line) {
        $log_entry = json_decode($line, true);
        if (!$log_entry) {
            continue;
        }
        
        // Apply filters
        if (!empty($filters['wpsd_id']) && ($log_entry['wpsd_id'] ?? '') !== $filters['wpsd_id']) {
            continue;
        }
        
        if (!empty($filters['action']) && ($log_entry['action'] ?? '') !== $filters['action']) {
            continue;
        }
        
        if (!empty($filters['date_from'])) {
            $log_date = strtotime($log_entry['timestamp'] ?? '');
            $filter_date = strtotime($filters['date_from']);
            if ($log_date < $filter_date) {
                continue;
            }
        }
        
        if (!empty($filters['date_to'])) {
            $log_date = strtotime($log_entry['timestamp'] ?? '');
            $filter_date = strtotime($filters['date_to'] . ' 23:59:59');
            if ($log_date > $filter_date) {
                continue;
            }
        }
        
        $logs[] = $log_entry;
    }
    
    // Apply limit and offset
    if ($offset > 0 || $limit > 0) {
        $logs = array_slice($logs, $offset, $limit > 0 ? $limit : null);
    }
    
    return $logs;
}

/**
 * Calculate statistics from logs
 */
function calculateStatistics() {
    $log_file = __DIR__ . '/../logs/real-sensors.log';
    
    $stats = [
        'total_received' => 0,
        'total_updated' => 0,
        'total_ignored_reservation' => 0,
        'total_ignored_unknown' => 0,
        'total_errors' => 0
    ];
    
    if (!file_exists($log_file)) {
        return $stats;
    }
    
    // Read all log files
    $log_files = [$log_file];
    for ($i = 1; $i <= 5; $i++) {
        $rotated_file = $log_file . '.' . $i;
        if (file_exists($rotated_file)) {
            $log_files[] = $rotated_file;
        }
    }
    
    foreach ($log_files as $file) {
        $handle = fopen($file, 'r');
        if (!$handle) {
            continue;
        }
        
        while (($line = fgets($handle)) !== false) {
            $log_entry = json_decode($line, true);
            if (!$log_entry || !isset($log_entry['action'])) {
                continue;
            }
            
            $action = $log_entry['action'];
            switch ($action) {
                case 'received':
                    $stats['total_received']++;
                    break;
                case 'updated':
                    $stats['total_updated']++;
                    break;
                case 'ignored_reservation':
                    $stats['total_ignored_reservation']++;
                    break;
                case 'ignored_unknown':
                    $stats['total_ignored_unknown']++;
                    break;
                case 'error':
                    $stats['total_errors']++;
                    break;
            }
        }
        
        fclose($handle);
    }
    
    return $stats;
}

try {
    checkSuperAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
        exit();
    }
    
    // Get query parameters
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $wpsd_id = $_GET['wpsd_id'] ?? '';
    $action = $_GET['action'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $tail = isset($_GET['tail']) ? (int)$_GET['tail'] : 0;
    
    // Build filters
    $filters = [];
    if ($wpsd_id) {
        $filters['wpsd_id'] = $wpsd_id;
    }
    if ($action) {
        $filters['action'] = $action;
    }
    if ($date_from) {
        $filters['date_from'] = $date_from;
    }
    if ($date_to) {
        $filters['date_to'] = $date_to;
    }
    
    // If tail is specified, use it instead of limit/offset
    if ($tail > 0) {
        $limit = $tail;
        $offset = 0;
    }
    
    // Read logs
    $logs = readLogFile($limit, $offset, $filters);
    
    // Get statistics
    $statistics = calculateStatistics();
    
    // Get TCP server status
    $server_status = checkTcpServerStatus();
    
    // Get list of sensors with last update time
    $db = new Database();
    $sensors = $db->getSensors();
    $sensor_status = [];
    
    foreach ($sensors as $sensor) {
        // Find last log entry for this sensor
        $sensor_logs = readLogFile(1, 0, ['wpsd_id' => $sensor['wpsd_id']]);
        $last_update = null;
        if (!empty($sensor_logs)) {
            $last_update = $sensor_logs[0]['timestamp'] ?? null;
        }
        
        $sensor_status[] = [
            'id' => $sensor['id'],
            'wpsd_id' => $sensor['wpsd_id'],
            'name' => $sensor['name'],
            'status' => $sensor['status'],
            'last_update' => $last_update
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'logs' => $logs,
            'statistics' => $statistics,
            'server_status' => $server_status,
            'sensor_status' => $sensor_status,
            'total_logs' => count($logs),
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>

