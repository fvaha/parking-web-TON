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
    $warning_time = $now + (10 * 60); // 10 minutes from now
    
    foreach ($spaces as $space) {
        if ($space['status'] === 'reserved' && $space['reservation_time'] && $space['license_plate']) {
            $reservation_timestamp = strtotime($space['reservation_time']);
            $time_until_end = $reservation_timestamp - $now;
            
            // Check if reservation ends in approximately 10 minutes (within 1 minute window)
            if ($time_until_end > 540 && $time_until_end < 660) {
                $user = $db->getTelegramUserByLicensePlate($space['license_plate']);
                if ($user) {
                    $message = "⏰ Reminder: Your reservation at Space #{$space['id']} ends in 10 minutes!";
                    $notification_service->queueNotification(
                        $user['telegram_user_id'],
                        'reservation_ending',
                        $message,
                        ['parking_space_id' => $space['id']]
                    );
                }
            }
            
            // Check if reservation just ended (within last minute)
            if ($time_until_end < 0 && $time_until_end > -60) {
                $user = $db->getTelegramUserByLicensePlate($space['license_plate']);
                if ($user) {
                    $message = "✅ Your reservation at Space #{$space['id']} has ended.";
                    $notification_service->queueNotification(
                        $user['telegram_user_id'],
                        'reservation_ended',
                        $message,
                        ['parking_space_id' => $space['id']]
                    );
                }
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

