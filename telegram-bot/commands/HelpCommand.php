<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\LanguageService;
use TelegramBot\Services\KeyboardService;

class HelpCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user = $message->getFrom();
        $lang = LanguageService::getLanguage($user);
        
        $separator = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        
        $text = $separator . "\n";
        $text .= "📖 *" . LanguageService::t('help_title', $lang) . "*\n";
        $text .= $separator . "\n\n";
        
        $text .= LanguageService::t('help_start', $lang);
        $text .= LanguageService::t('help_link', $lang);
        $text .= LanguageService::t('help_link2', $lang);
        $text .= LanguageService::t('help_status', $lang);
        $text .= LanguageService::t('help_spaces', $lang);
        $text .= LanguageService::t('help_weather', $lang);
        $text .= LanguageService::t('help_preferences', $lang);
        $text .= LanguageService::t('help_reserve', $lang);
        $text .= LanguageService::t('help_help', $lang);
        $text .= LanguageService::t('help_lang', $lang);
        
        $text .= "\n" . $separator . "\n";
        $text .= LanguageService::t('help_tip', $lang) . "\n";
        $text .= $separator;
        
        // Get reply keyboard with commands
        $user = $message->getFrom();
        $reply_keyboard = KeyboardService::getCommandsKeyboard($lang);
        
        error_log("HelpCommand: Sending help message to chat {$chat_id}");
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($reply_keyboard)
        ]);
    }
}

