<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    $action = $_GET['action'] ?? 'overview';
    
    switch ($action) {
        case 'overview':
            // Get basic statistics
            $stats = $db->getStatistics();
            echo json_encode([
                'success' => true,
                'data' => $stats
            ]);
            break;
            
        case 'daily_usage':
            // Get daily usage data
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            $daily_data = $db->getDailyUsage($days);
            echo json_encode([
                'success' => true,
                'data' => $daily_data
            ]);
            break;
            
        case 'hourly_usage':
            // Get hourly usage data
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            $hourly_data = $db->getHourlyUsage($days);
            echo json_encode([
                'success' => true,
                'data' => $hourly_data
            ]);
            break;
            
        case 'parking_usage':
            // Get parking usage history
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $filters = [];
            
            if (isset($_GET['license_plate'])) {
                $filters['license_plate'] = $_GET['license_plate'];
            }
            if (isset($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (isset($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }
            
            $usage_data = $db->getParkingUsage($limit, $offset, $filters);
            echo json_encode([
                'success' => true,
                'data' => $usage_data
            ]);
            break;
            
        case 'active_sessions':
            // Get current active sessions
            $sessions = $db->getActiveSessions();
            echo json_encode([
                'success' => true,
                'data' => $sessions
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action'
            ]);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
