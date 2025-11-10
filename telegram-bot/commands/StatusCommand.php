<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\DatabaseService;
use TelegramBot\Services\ParkingService;

class StatusCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        $parking_service = new ParkingService();
        
        // Get user
        error_log("StatusCommand: Checking user {$user_id}");
        $user = $db->getTelegramUserByTelegramId($user_id);
        if (!$user) {
            error_log("StatusCommand: User not found");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => "❌ Account not linked. Use /link <license_plate> to link your account first.\n\nExample: /link NP-078-HH"
            ]);
            return;
        }
        error_log("StatusCommand: User found - license_plate: {$user['license_plate']}");
        
        // Get active reservations
        $reservations = $parking_service->getActiveReservations($user['license_plate']);
        
        if (empty($reservations)) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => "📋 No active reservations.\n\nLicense Plate: {$user['license_plate']}"
            ]);
            return;
        }
        
        $text = "📋 Active Reservations\n\n";
        $text .= "License Plate: {$user['license_plate']}\n\n";
        
        foreach ($reservations as $space) {
            $text .= "🅿️ Space ID: {$space['id']}\n";
            $text .= "Status: " . strtoupper($space['status']) . "\n";
            if ($space['reservation_time']) {
                $text .= "Reserved: " . date('Y-m-d H:i', strtotime($space['reservation_time'])) . "\n";
            }
            if ($space['occupied_since']) {
                $text .= "Occupied: " . date('Y-m-d H:i', strtotime($space['occupied_since'])) . "\n";
            }
            $text .= "\n";
        }
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text
        ]);
    }
}

