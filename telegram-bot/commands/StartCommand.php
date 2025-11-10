<?php
namespace TelegramBot\Commands;

require_once __DIR__ . '/../config.php';
use TelegramBot\Services\LanguageService;
use TelegramBot\Services\KeyboardService;

class StartCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user = $message->getFrom();
        $web_app_url = defined('WEB_APP_URL') ? WEB_APP_URL : 'https://parkiraj.info';
        
        // Get user language (always returns valid language code)
        $lang = LanguageService::getLanguage($user);
        
        // Ensure lang is valid, fallback to 'en' if not
        if (!in_array($lang, ['en', 'sr', 'de', 'fr', 'ar'])) {
            $lang = 'en';
        }
        
        // Create a large welcome message with all commands
        $separator = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        
        // Get localized bot name
        $bot_names = [
            'en' => 'PARKIRAJ.INFO BOT',
            'sr' => 'PARKIRAJ.INFO BOT',
            'de' => 'PARKIRAJ.INFO BOT',
            'fr' => 'PARKIRAJ.INFO BOT',
            'ar' => 'بوت باركيراج.إنفو'
        ];
        
        $tip_texts = [
            'en' => '💡 *TIP:* Use /help for detailed information about each command.',
            'sr' => '💡 *TIP:* Koristite /help za detaljne informacije o svakoj komandi.',
            'de' => '💡 *TIP:* Verwenden Sie /help für detaillierte Informationen zu jedem Befehl.',
            'fr' => '💡 *ASTUCE:* Utilisez /help pour des informations détaillées sur chaque commande.',
            'ar' => '💡 *نصيحة:* استخدم /help للحصول على معلومات مفصلة حول كل أمر.'
        ];
        
        $commands_title = [
            'en' => '📋 *AVAILABLE COMMANDS:*',
            'sr' => '📋 *DOSTUPNE KOMANDE:*',
            'de' => '📋 *VERFÜGBARE BEFEHLE:*',
            'fr' => '📋 *COMMANDES DISPONIBLES:*',
            'ar' => '📋 *الأوامر المتاحة:*'
        ];
        
        $text = $separator . "\n";
        $text .= "🚗 *" . ($bot_names[$lang] ?? $bot_names['en']) . "*\n";
        $text .= $separator . "\n\n";
        
        $text .= LanguageService::t('welcome', $lang);
        $text .= "\n" . ($commands_title[$lang] ?? $commands_title['en']) . "\n\n";
        $text .= "🔹 " . LanguageService::t('cmd_start', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_link', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_status', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_spaces', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_weather', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_preferences', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_reserve', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_help', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_app', $lang);
        $text .= "🔹 " . LanguageService::t('cmd_lang', $lang);
        
        $text .= "\n" . LanguageService::t('link_account', $lang);
        $text .= LanguageService::t('link_format', $lang);
        $text .= LanguageService::t('link_format2', $lang);
        $text .= LanguageService::t('link_format3', $lang);
        
        $text .= "\n" . $separator . "\n";
        $text .= ($tip_texts[$lang] ?? $tip_texts['en']) . "\n";
        $text .= $separator;
        
        // Create inline keyboard with button to open web app
        $keyboard_texts = [
            'en' => ['Open Web App', 'Link Account'],
            'sr' => ['Otvori Web Aplikaciju', 'Poveži Nalog'],
            'de' => ['Web-App öffnen', 'Konto verknüpfen'],
            'fr' => ["Ouvrir l'App Web", 'Lier le Compte'],
            'ar' => ['فتح التطبيق الإلكتروني', 'ربط الحساب']
        ];
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🌐 ' . $keyboard_texts[$lang][0],
                        'web_app' => ['url' => $web_app_url]
                    ]
                ],
                [
                    [
                        'text' => '🔗 ' . $keyboard_texts[$lang][1],
                        'callback_data' => 'link_account'
                    ]
                ]
            ]
        ];
        
        // Get reply keyboard with commands
        $reply_keyboard = KeyboardService::getCommandsKeyboard($lang);
        
        // Combine inline keyboard and reply keyboard
        // We'll use reply keyboard as main, and keep inline for web app
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($reply_keyboard)
        ]);
        
        // Send separate message with inline keyboard for web app
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => '🌐 ' . ($keyboard_texts[$lang][0] ?? 'Open Web App'),
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}

