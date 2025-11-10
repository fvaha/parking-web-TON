<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\DatabaseService;

class PreferencesCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $text = $message->getText();
        
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        
        // Get user
        $user = $db->getTelegramUserByTelegramId($user_id);
        if (!$user) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => "❌ Account not linked. Use /link to link your account first."
            ]);
            return;
        }
        
        // Get current preferences
        $preferences = $db->getNotificationPreferences($user_id);
        
        // Parse command
        $parts = explode(' ', $text, 2);
        $subcommand = isset($parts[1]) ? trim($parts[1]) : '';
        
        if (empty($subcommand)) {
            // Show current preferences
            $pref_text = "🔔 Notification Preferences\n\n";
            $pref_text .= "Notify free spaces: " . ($preferences['notify_free_spaces'] ? '✅ Yes' : '❌ No') . "\n";
            $pref_text .= "Notify specific space: " . ($preferences['notify_specific_space'] ?? 'None') . "\n";
            $pref_text .= "Notify street: " . ($preferences['notify_street'] ?? 'None') . "\n";
            $pref_text .= "Notify zone: " . ($preferences['notify_zone'] ?? 'None') . "\n\n";
            $pref_text .= "To update preferences, use:\n";
            $pref_text .= "/preferences free on|off\n";
            $pref_text .= "/preferences space <space_id>\n";
            $pref_text .= "/preferences street <street_name>\n";
            $pref_text .= "/preferences zone <zone_id>\n";
            
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => $pref_text
            ]);
            return;
        }
        
        // Update preferences
        $pref_parts = explode(' ', $subcommand, 2);
        $pref_type = $pref_parts[0];
        $pref_value = isset($pref_parts[1]) ? trim($pref_parts[1]) : '';
        
        $update_data = [];
        
        switch ($pref_type) {
            case 'free':
                $update_data['notify_free_spaces'] = ($pref_value === 'on');
                break;
            case 'space':
                $update_data['notify_specific_space'] = !empty($pref_value) ? (int)$pref_value : null;
                break;
            case 'street':
                $update_data['notify_street'] = !empty($pref_value) ? $pref_value : null;
                break;
            case 'zone':
                $update_data['notify_zone'] = !empty($pref_value) ? (int)$pref_value : null;
                break;
            default:
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Invalid preference type. Use: free, space, street, or zone"
                ]);
                return;
        }
        
        // Merge with existing preferences
        if ($preferences) {
            $update_data = array_merge([
                'notify_free_spaces' => $preferences['notify_free_spaces'],
                'notify_specific_space' => $preferences['notify_specific_space'],
                'notify_street' => $preferences['notify_street'],
                'notify_zone' => $preferences['notify_zone']
            ], $update_data);
        }
        
        $result = $db->updateNotificationPreferences($user_id, $update_data);
        
        if ($result['success']) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => "✅ Preferences updated successfully!"
            ]);
        } else {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => "❌ Failed to update preferences: " . ($result['error'] ?? 'Unknown error')
            ]);
        }
    }
}

