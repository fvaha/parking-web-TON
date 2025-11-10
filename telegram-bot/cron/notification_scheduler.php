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
            
            // Check if reservation just ended (within last minute)
            if ($time_until_end < 0 && $time_until_end > -60) {
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
    
    // Note: Space availability notifications are handled in the API when spaces become vacant
    // This scheduler focuses on reservation reminders
    
    echo "Notification scheduling completed.\n";
    echo "Total notifications processed: {$result['sent']} sent, {$result['failed']} failed\n";
    
} catch (Exception $e) {
    error_log('Notification scheduler error: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
?>

