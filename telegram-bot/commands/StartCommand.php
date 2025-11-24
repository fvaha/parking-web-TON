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
        
        // Create modern inline keyboard menu (like SUCH bot style)
        $menu_texts = [
            'en' => [
                'reserve' => '✅ Reserve Space',
                'spaces' => '🅿️ Available Spaces',
                'status' => '📋 My Reservations',
                'link' => '🔗 Link Account',
                'wallet' => '💼 Wallet',
                'preferences' => '⚙️ Preferences',
                'weather' => '☁️ Weather',
                'web_app' => '🌐 Web App',
                'help' => '❓ Help',
                'lang' => '🌍 Language'
            ],
            'sr' => [
                'reserve' => '✅ Rezerviši Mesto',
                'spaces' => '🅿️ Dostupna Mesta',
                'status' => '📋 Moje Rezervacije',
                'link' => '🔗 Poveži Nalog',
                'wallet' => '💼 Novčanik',
                'preferences' => '⚙️ Postavke',
                'weather' => '☁️ Vreme',
                'web_app' => '🌐 Web Aplikacija',
                'help' => '❓ Pomoć',
                'lang' => '🌍 Jezik'
            ],
            'de' => [
                'reserve' => '✅ Platz Reservieren',
                'spaces' => '🅿️ Verfügbare Plätze',
                'status' => '📋 Meine Reservierungen',
                'link' => '🔗 Konto Verknüpfen',
                'wallet' => '💼 Geldbörse',
                'preferences' => '⚙️ Einstellungen',
                'weather' => '☁️ Wetter',
                'web_app' => '🌐 Web-App',
                'help' => '❓ Hilfe',
                'lang' => '🌍 Sprache'
            ],
            'fr' => [
                'reserve' => '✅ Réserver Place',
                'spaces' => '🅿️ Places Disponibles',
                'status' => '📋 Mes Réservations',
                'link' => '🔗 Lier le Compte',
                'wallet' => '💼 Portefeuille',
                'preferences' => '⚙️ Préférences',
                'weather' => '☁️ Météo',
                'web_app' => '🌐 App Web',
                'help' => '❓ Aide',
                'lang' => '🌍 Langue'
            ],
            'ar' => [
                'reserve' => '✅ حجز مكان',
                'spaces' => '🅿️ الأماكن المتاحة',
                'status' => '📋 حجوزاتي',
                'link' => '🔗 ربط الحساب',
                'wallet' => '💼 المحفظة',
                'preferences' => '⚙️ التفضيلات',
                'weather' => '☁️ الطقس',
                'web_app' => '🌐 التطبيق الإلكتروني',
                'help' => '❓ المساعدة',
                'lang' => '🌍 اللغة'
            ]
        ];
        
        $menu = $menu_texts[$lang] ?? $menu_texts['en'];
        
        // Create inline keyboard with organized menu buttons
        $keyboard = [
            'inline_keyboard' => [
                // First row: Main actions
                [
                    [
                        'text' => $menu['reserve'],
                        'callback_data' => 'menu_reserve'
                    ],
                    [
                        'text' => $menu['spaces'],
                        'callback_data' => 'menu_spaces'
                    ]
                ],
                // Second row: Status and Account
                [
                    [
                        'text' => $menu['status'],
                        'callback_data' => 'menu_status'
                    ],
                    [
                        'text' => $menu['link'],
                        'callback_data' => 'link_account'
                    ]
                ],
                // Third row: Wallet and Preferences
                [
                    [
                        'text' => $menu['wallet'],
                        'callback_data' => 'menu_wallet'
                    ],
                    [
                        'text' => $menu['preferences'],
                        'callback_data' => 'menu_preferences'
                    ]
                ],
                // Fourth row: Weather and Web App
                [
                    [
                        'text' => $menu['weather'],
                        'callback_data' => 'menu_weather'
                    ],
                    [
                        'text' => $menu['web_app'],
                        'web_app' => ['url' => $web_app_url]
                    ]
                ],
                // Fifth row: Help and Language
                [
                    [
                        'text' => $menu['help'],
                        'callback_data' => 'menu_help'
                    ],
                    [
                        'text' => $menu['lang'],
                        'callback_data' => 'menu_lang'
                    ]
                ]
            ]
        ];
        
        // Remove any existing reply keyboard first (clean state)
        $bot->removeReplyKeyboard($chat_id);
        
        // Send message with inline keyboard menu
        // Users can also use / commands directly or the menu button (/) for quick access
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
}


