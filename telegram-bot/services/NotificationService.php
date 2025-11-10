<?php
namespace TelegramBot\Services;

use TelegramBot\Services\DatabaseService;

class NotificationService {
    private $db;
    private $bot_token;
    
    public function __construct() {
        $db_service = new DatabaseService();
        $this->db = $db_service->getDatabase();
        $this->bot_token = TELEGRAM_BOT_TOKEN;
    }
    
    public function sendNotification($chat_id, $message) {
        $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
        
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
    }
    
    public function queueNotification($telegram_id, $type, $message, $data = []) {
        return $this->db->queueNotification($telegram_id, $type, $message, $data);
    }
    
    public function processPendingNotifications() {
        $notifications = $this->db->getPendingNotifications(50);
        $sent = 0;
        $failed = 0;
        
        foreach ($notifications as $notification) {
            $user = $this->db->getTelegramUserByTelegramId($notification['telegram_user_id']);
            if (!$user) {
                $this->db->markNotificationFailed($notification['id']);
                $failed++;
                continue;
            }
            
            $success = $this->sendNotification($user['chat_id'], $notification['message']);
            
            if ($success) {
                $this->db->markNotificationSent($notification['id']);
                $sent++;
            } else {
                $this->db->markNotificationFailed($notification['id']);
                $failed++;
            }
        }
        
        return ['sent' => $sent, 'failed' => $failed];
    }
}

