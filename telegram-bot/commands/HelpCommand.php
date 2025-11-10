<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\LanguageService;
use TelegramBot\Services\KeyboardService;

class HelpCommand {
    public function handle($bot, $message) {
        try {
            $chat_id = $message->getChat()->getId();
            $user = $message->getFrom();
            $lang = LanguageService::getLanguage($user);
            
            error_log("HelpCommand: Processing help command for chat {$chat_id}, language: {$lang}");
            
            $separator = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            
            // Build help text
            $text = $separator . "\n";
            
            // Get help_title - handle potential null
            $help_title = LanguageService::t('help_title', $lang);
            if (empty($help_title)) {
                $help_title = "📖 Available Commands\n\n";
            }
            $text .= "📖 *" . trim($help_title) . "*\n";
            $text .= $separator . "\n\n";
            
            // Add all help items
            $help_items = [
                'help_start',
                'help_link',
                'help_link2',
                'help_status',
                'help_spaces',
                'help_weather',
                'help_preferences',
                'help_reserve',
                'help_help',
                'help_lang'
            ];
            
            foreach ($help_items as $item) {
                $item_text = LanguageService::t($item, $lang);
                if (!empty($item_text)) {
                    $text .= $item_text;
                } else {
                    error_log("HelpCommand: Missing translation for {$item} in language {$lang}");
                }
            }
            
            $text .= "\n" . $separator . "\n";
            $help_tip = LanguageService::t('help_tip', $lang);
            if (!empty($help_tip)) {
                $text .= $help_tip . "\n";
            }
            $text .= $separator;
            
            // Get reply keyboard with commands
            $reply_keyboard = null;
            try {
                $reply_keyboard = KeyboardService::getCommandsKeyboard($lang);
                if (empty($reply_keyboard)) {
                    error_log("HelpCommand: KeyboardService returned empty keyboard");
                }
            } catch (\Throwable $kb_error) {
                error_log("HelpCommand: Error getting keyboard: " . $kb_error->getMessage());
                // Continue without keyboard
            }
            
            // Prepare message parameters
            $message_params = [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown'
            ];
            
            // Add keyboard if available
            if ($reply_keyboard) {
                $message_params['reply_markup'] = json_encode($reply_keyboard);
            }
            
            error_log("HelpCommand: Sending help message to chat {$chat_id}");
            $result = $bot->sendMessage($message_params);
            
            if ($result === false) {
                error_log("HelpCommand: Failed to send message");
                // Try without Markdown if it fails
                $message_params['parse_mode'] = null;
                $message_params['text'] = strip_tags($text); // Remove markdown
                $bot->sendMessage($message_params);
            } else {
                error_log("HelpCommand: Help message sent successfully");
            }
            
        } catch (\Throwable $e) {
            error_log("HelpCommand: Exception - " . $e->getMessage());
            error_log("HelpCommand: Stack trace - " . $e->getTraceAsString());
            
            // Try to send error message
            try {
                $chat_id = $message->getChat()->getId();
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Error showing help. Please try again later."
                ]);
            } catch (\Exception $send_error) {
                error_log("HelpCommand: Failed to send error message: " . $send_error->getMessage());
            }
        }
    }
}

