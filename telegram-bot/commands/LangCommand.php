<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\LanguageService;

class LangCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $text = $message->getText();
        
        // Parse: /lang en ili /lang sr
        $parts = explode(' ', $text, 2);
        if (count($parts) >= 2) {
            $new_lang = trim($parts[1]);
            if (in_array($new_lang, ['en', 'sr', 'de', 'fr', 'ar'])) {
                // Save to database
                $result = LanguageService::updateUserLanguage($user_id, $new_lang);
                
                if ($result['success']) {
                    $lang_names = [
                        'en' => 'English',
                        'sr' => 'Srpski',
                        'de' => 'Deutsch',
                        'fr' => 'Français',
                        'ar' => 'العربية'
                    ];
                    
                    $bot->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => LanguageService::t('language_changed', $new_lang, [
                            'language' => $lang_names[$new_lang]
                        ])
                    ]);
                    return;
                } else {
                    $bot->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => "❌ " . ($result['error'] ?? 'Failed to update language')
                    ]);
                    return;
                }
            } else {
                // Invalid language code - use simple English message
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => "❌ Invalid language code. Available: en, sr, de, fr, ar"
                ]);
                return;
            }
        }
        
        // Show language selection keyboard
        // Use simple multilingual message that doesn't require language detection
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🇺🇸 English', 'callback_data' => 'lang_en'],
                    ['text' => '🇷🇸 Srpski', 'callback_data' => 'lang_sr']
                ],
                [
                    ['text' => '🇩🇪 Deutsch', 'callback_data' => 'lang_de'],
                    ['text' => '🇫🇷 Français', 'callback_data' => 'lang_fr']
                ],
                [
                    ['text' => '🇸🇦 العربية', 'callback_data' => 'lang_ar']
                ]
            ]
        ];
        
        // Simple multilingual message that works without language detection
        $select_text = "🌐 Select your language / Izaberite vaš jezik / Wählen Sie Ihre Sprache / Sélectionnez votre langue / اختر لغتك:\n\n";
        $select_text .= "🇺🇸 English\n";
        $select_text .= "🇷🇸 Srpski\n";
        $select_text .= "🇩🇪 Deutsch\n";
        $select_text .= "🇫🇷 Français\n";
        $select_text .= "🇸🇦 العربية";
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $select_text,
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}

