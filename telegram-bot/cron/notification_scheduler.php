<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/DatabaseService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ParkingService.php';

use TelegramBot\Services\DatabaseService;
use TelegramBot\Services\NotificationService;
use TelegramBot\Services\ParkingService;

try {
    $db_service = new DatabaseService();
    $db = $db_service->getDatabase();
    $notification_service = new NotificationService();
    $parking_service = new ParkingService();
    
    // Process pending notifications
    $result = $notification_service->processPendingNotifications();
    echo "Processed notifications: {$result['sent']} sent, {$result['failed']} failed\n";
    
    // Check for reservations ending in 10 minutes
    $spaces = $parking_service->getParkingSpaces();
    $now = time();
    
    foreach ($spaces as $space) {
        if ($space['status'] === 'reserved' && $space['reservation_time'] && $space['license_plate']) {
            $user = $db->getTelegramUserByLicensePlate($space['license_plate']);
            if (!$user) {
                continue; // Skip if user not found
            }
            
            // Check user preferences for reservation expiry notifications
            $preferences = $db->getNotificationPreferences($user['telegram_user_id']);
            // Default to enabled if preference not set
            $notify_expiry = !isset($preferences['notify_reservation_expiry']) || $preferences['notify_reservation_expiry'] !== 0;
            
            if (!$notify_expiry) {
                continue; // User disabled reservation expiry notifications
            }
            
            // Use reservation_end_time if available, otherwise calculate from reservation_time + 1 hour
            if (!empty($space['reservation_end_time'])) {
                $end_timestamp = strtotime($space['reservation_end_time']);
            } else {
                // Fallback: assume 1 hour duration
                $reservation_timestamp = strtotime($space['reservation_time']);
                $end_timestamp = $reservation_timestamp + 3600; // 1 hour
            }
            
            $time_until_end = $end_timestamp - $now;
            
            // Check if reservation ends in approximately 10 minutes (within 1 minute window)
            // 540 seconds = 9 minutes, 660 seconds = 11 minutes
            if ($time_until_end > 540 && $time_until_end < 660) {
                $message = "⏰ Reminder: Your reservation at Space #{$space['id']} ends in 10 minutes!";
                $notification_service->queueNotification(
                    $user['telegram_user_id'],
                    'reservation_ending',
                    $message,
                    ['parking_space_id' => $space['id']]
                );
                echo "Queued 10-minute warning for Space #{$space['id']}, user {$user['telegram_user_id']}\n";
            }
            
            // Check if reservation has expired (more than 1 minute ago)
            // Auto-complete expired reservations
            if ($time_until_end < -60) {
                // Reservation expired more than 1 minute ago - auto-complete it
                error_log("Auto-completing expired reservation for Space #{$space['id']}, license plate: {$space['license_plate']}");
                
                // Update parking space status to vacant
                $update_result = $db->updateParkingSpaceStatus(
                    $space['id'],
                    'vacant',
                    null, // license_plate
                    null, // reservation_time
                    null, // occupied_since
                    null  // payment_tx_hash
                );
                
                if ($update_result['success']) {
                    // Send notification that reservation ended
                    $message = "✅ Your reservation at Space #{$space['id']} has ended and the space has been automatically freed.";
                    $notification_service->queueNotification(
                        $user['telegram_user_id'],
                        'reservation_ended',
                        $message,
                        ['parking_space_id' => $space['id']]
                    );
                    echo "Auto-completed expired reservation for Space #{$space['id']}, user {$user['telegram_user_id']}\n";
                } else {
                    error_log("Failed to auto-complete reservation for Space #{$space['id']}: " . ($update_result['error'] ?? 'Unknown error'));
                }
            } elseif ($time_until_end < 0 && $time_until_end > -60) {
                // Reservation just ended (within last minute) - send notification
                $message = "✅ Your reservation at Space #{$space['id']} has ended.";
                $notification_service->queueNotification(
                    $user['telegram_user_id'],
                    'reservation_ended',
                    $message,
                    ['parking_space_id' => $space['id']]
                );
                echo "Queued expiry notification for Space #{$space['id']}, user {$user['telegram_user_id']}\n";
            }
        }
    }
    
    // Also check and auto-complete reservations from reservations table if it exists
    try {
        $active_reservations = $db->getActiveReservations();
        $now = new DateTime();
        $auto_completed_count = 0;
        
        foreach ($active_reservations as $reservation) {
            if (!isset($reservation['end_time']) || empty($reservation['end_time'])) {
                continue; // Skip reservations without end_time
            }
            
            try {
                $end_time = new DateTime($reservation['end_time']);
                
                // Check if reservation has expired (more than 1 minute ago)
                if ($end_time <= $now) {
                    // Complete the reservation in database
                    $complete_result = $db->completeReservation($reservation['id']);
                    
                    if ($complete_result['success']) {
                        // Update parking space status to vacant
                        $update_result = $db->updateParkingSpaceStatus(
                            $reservation['parking_space_id'],
                            'vacant',
                            null, // license_plate
                            null, // reservation_time
                            null, // occupied_since
                            null  // payment_tx_hash
                        );
                        
                        if ($update_result['success']) {
                            $auto_completed_count++;
                            echo "Auto-completed reservation #{$reservation['id']} for Space #{$reservation['parking_space_id']}\n";
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Error processing reservation ' . $reservation['id'] . ': ' . $e->getMessage());
                continue; // Skip this reservation and continue with others
            }
        }
        
        if ($auto_completed_count > 0) {
            echo "Auto-completed {$auto_completed_count} expired reservation(s) from reservations table\n";
        }
    } catch (Exception $e) {
        error_log('Error auto-completing reservations: ' . $e->getMessage());
    }
    
    // Note: Space availability notifications are handled in the API when spaces become vacant
    // This scheduler focuses on reservation reminders and auto-completion
    
    echo "Notification scheduling completed.\n";
    echo "Total notifications processed: {$result['sent']} sent, {$result['failed']} failed\n";
    
} catch (Exception $e) {
    error_log('Notification scheduler error: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
?>

