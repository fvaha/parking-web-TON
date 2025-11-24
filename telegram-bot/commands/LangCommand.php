<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\LanguageService;

class LangCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $text = $message->getText();
        
        error_log("LangCommand: Received text: '{$text}'");
        
        // Parse: /lang en ili /lang sr
        $parts = explode(' ', $text, 2);
        error_log("LangCommand: Parts count: " . count($parts) . ", parts: " . json_encode($parts));
        
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
                // Invalid language code - show translated error message
                $error_texts = [
                    'en' => "❌ Invalid language code. Available: en, sr, de, fr, ar",
                    'sr' => "❌ Nevažeći kod jezika. Dostupno: en, sr, de, fr, ar",
                    'de' => "❌ Ungültiger Sprachcode. Verfügbar: en, sr, de, fr, ar",
                    'fr' => "❌ Code de langue invalide. Disponible: en, sr, de, fr, ar",
                    'ar' => "❌ رمز لغة غير صالح. متاح: en, sr, de, fr, ar"
                ];
                $user = $message->getFrom();
                $lang = LanguageService::getLanguage($user);
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $error_texts[$lang] ?? $error_texts['en']
                ]);
                return;
            }
        }
        
        // Get user language for back button text
        $user = $message->getFrom();
        $lang = LanguageService::getLanguage($user);
        
        // Show language selection keyboard
        // Use simple multilingual message that doesn't require language detection
        $back_texts = [
            'en' => '🏠 Back to Menu',
            'sr' => '🏠 Nazad na Meni',
            'de' => '🏠 Zurück zum Menü',
            'fr' => '🏠 Retour au Menu',
            'ar' => '🏠 العودة إلى القائمة'
        ];
        
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
                ],
                [
                    ['text' => $back_texts[$lang] ?? $back_texts['en'], 'callback_data' => 'menu_start']
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

