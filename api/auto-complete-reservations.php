<?php
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

try {
    $db = new Database();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
        exit();
    }
    
    // Check if reservations table exists
    $conn = new ReflectionClass($db);
    $dbProperty = $conn->getProperty('db');
    $dbProperty->setAccessible(true);
    $sqlite = $dbProperty->getValue($db);
    
    $table_check = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reservations'");
    $has_reservations_table = ($table_check->fetchArray() !== false);
    
    if (!$has_reservations_table) {
        // No reservations table, return success with 0 completed
        echo json_encode([
            'success' => true,
            'completed_count' => 0,
            'message' => 'Reservations table does not exist'
        ]);
        exit();
    }
    
    // Get all active reservations
    $active_reservations = $db->getActiveReservations();
    $now = new DateTime();
    $completed_count = 0;
    
    if (empty($active_reservations)) {
        // No active reservations
        echo json_encode([
            'success' => true,
            'completed_count' => 0,
            'message' => 'No active reservations to complete'
        ]);
        exit();
    }
    
    foreach ($active_reservations as $reservation) {
        if (!isset($reservation['end_time']) || empty($reservation['end_time'])) {
            continue; // Skip reservations without end_time
        }
        
        try {
            $end_time = new DateTime($reservation['end_time']);
            
            // Check if reservation has expired
            if ($end_time <= $now) {
                // Complete the reservation
                $complete_result = $db->completeReservation($reservation['id']);
                
                if ($complete_result['success']) {
                    // Update parking space status to vacant
                    $db->updateParkingSpaceStatus(
                        $reservation['parking_space_id'],
                        'vacant',
                        null, // license_plate
                        null, // reservation_time
                        null, // occupied_since
                        null  // payment_tx_hash
                    );
                    
                    $completed_count++;
                }
            }
        } catch (Exception $e) {
            error_log('Error processing reservation ' . $reservation['id'] . ': ' . $e->getMessage());
            continue; // Skip this reservation and continue with others
        }
    }
    
    echo json_encode([
        'success' => true,
        'completed_count' => $completed_count,
        'message' => "Completed {$completed_count} expired reservation(s)"
    ]);
    
} catch (Throwable $e) {
    http_response_code(500);
    error_log('auto-complete-reservations.php error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>

