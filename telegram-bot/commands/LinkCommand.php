<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\DatabaseService;
use TelegramBot\Services\LanguageService;

class LinkCommand {
    public function handle($bot, $message) {
        try {
            $chat_id = $message->getChat()->getId();
            $user_id = $message->getFrom()->getId();
            $user = $message->getFrom();
            $username = $user->getUsername();
            $text = $message->getText();
            
            error_log("LinkCommand: Starting - chat_id={$chat_id}, user_id={$user_id}, text={$text}");
            
            $lang = LanguageService::getLanguage($user);
            
            $db_service = new DatabaseService();
            $db = $db_service->getDatabase();
            
            // Parse command: /link username license_plate
            // Also support: /link license_plate (uses Telegram username)
            // If just /link (from keyboard button), ask for license plate
            $parts = explode(' ', $text, 3);
            
            // If only /link command without license plate, ask user to enter it
            if (count($parts) < 2) {
                $link_enter_plate = LanguageService::t('link_enter_plate', $lang) ?? 
                    "🔗 *Link Account*\n\nPlease enter your license plate number.\n\nExample: `ABC123` or `AB-123-CD`";
                
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $link_enter_plate,
                    'parse_mode' => 'Markdown'
                ]);
                return;
            }
            
            // If only 2 parts, use Telegram username and license plate
            if (count($parts) === 2) {
                $input_username = $username ?: 'user_' . $user_id;
                $license_plate = trim($parts[1]);
            } else {
                $input_username = trim($parts[1]);
                $license_plate = trim($parts[2]);
            }
            
            // Validate username
            if (empty($input_username)) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('username_invalid', $lang)
                ]);
                return;
            }
            
            // Validate license plate
            if (empty($license_plate)) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('link_invalid_format', $lang)
                ]);
                return;
            }
            
            // Validate license plate length
            $license_plate_trimmed = trim($license_plate);
            if (strlen($license_plate_trimmed) < 2) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('license_plate_min_length', $lang)
                ]);
                return;
            }
            
            if (strlen($license_plate_trimmed) > 10) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('license_plate_max_length', $lang)
                ]);
                return;
            }
            
            // Validate license plate characters (letters, numbers, hyphens, spaces)
            if (!preg_match('/^[A-Z0-9\-\s]+$/i', $license_plate_trimmed)) {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('license_plate_invalid_chars', $lang)
                ]);
                return;
            }
            
            // Normalize license plate to uppercase
            $license_plate = strtoupper($license_plate_trimmed);
            
            // Link user
            error_log("LinkCommand: Attempting to link user {$user_id} with username '{$input_username}' and license plate '{$license_plate}'");
            
            if (!$db) {
                error_log("LinkCommand: Database is null!");
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Database error. Please contact administrator."
                ]);
                return;
            }
            
            $result = $db->linkTelegramUser($user_id, $input_username, $license_plate, $chat_id);
            
            error_log("LinkCommand: Result: " . json_encode($result));
            
            if ($result && isset($result['success']) && $result['success']) {
                $success_message = LanguageService::t('link_success', $lang, [
                    'username' => $input_username,
                    'license_plate' => $license_plate
                ]);
                error_log("LinkCommand: Sending success message");
                $response = $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $success_message
                ]);
                error_log("LinkCommand: SendMessage response: " . json_encode($response));
            } else {
                $error_msg = $result['error'] ?? ($result['message'] ?? 'Unknown error');
                $error_message = LanguageService::t('link_failed', $lang, [
                    'error' => $error_msg
                ]);
                error_log("LinkCommand: Sending error message: {$error_message}");
                $response = $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $error_message
                ]);
                error_log("LinkCommand: SendMessage error response: " . json_encode($response));
            }
        } catch (\Throwable $e) {
            error_log("LinkCommand: Exception - " . $e->getMessage());
            error_log("LinkCommand: File - " . $e->getFile() . " Line - " . $e->getLine());
            error_log("LinkCommand: Stack trace - " . $e->getTraceAsString());
            
            $error_text = "❌ Error linking account: " . $e->getMessage();
            if (isset($chat_id)) {
                try {
                    $bot->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => $error_text
                    ]);
                } catch (\Exception $send_error) {
                    error_log("LinkCommand: Failed to send error message - " . $send_error->getMessage());
                    // Try one more time with simple message
                    try {
                        $bot->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => "❌ Error occurred. Please try again later."
                        ]);
                    } catch (\Exception $send_error2) {
                        error_log("LinkCommand: Failed to send fallback message - " . $send_error2->getMessage());
                    }
                }
            } else {
                error_log("LinkCommand: Cannot send error - chat_id is not set");
            }
        }
    }
}

