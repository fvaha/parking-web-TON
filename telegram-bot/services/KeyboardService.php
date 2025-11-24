<?php
namespace TelegramBot\Services;

class KeyboardService {
    /**
     * Get reply keyboard with all available commands
     */
    public static function getCommandsKeyboard($lang = 'en') {
        // Command labels in different languages
        $labels = [
            'en' => [
                'start' => '🏠 Start',
                'link' => '🔗 Link Account',
                'status' => '📋 Status',
                'spaces' => '🅿️ Spaces',
                'weather' => '☁️ Weather',
                'preferences' => '⚙️ Preferences',
                'reserve' => '✅ Reserve',
                'help' => '❓ Help',
                'app' => '🌐 Web App',
                'lang' => '🌍 Language'
            ],
            'sr' => [
                'start' => '🏠 Početak',
                'link' => '🔗 Poveži Nalog',
                'status' => '📋 Status',
                'spaces' => '🅿️ Mesta',
                'weather' => '☁️ Vreme',
                'preferences' => '⚙️ Postavke',
                'reserve' => '✅ Rezerviši',
                'help' => '❓ Pomoć',
                'app' => '🌐 Web Aplikacija',
                'lang' => '🌍 Jezik'
            ],
            'de' => [
                'start' => '🏠 Start',
                'link' => '🔗 Konto Verknüpfen',
                'status' => '📋 Status',
                'spaces' => '🅿️ Parkplätze',
                'weather' => '☁️ Wetter',
                'preferences' => '⚙️ Einstellungen',
                'reserve' => '✅ Reservieren',
                'help' => '❓ Hilfe',
                'app' => '🌐 Web-App',
                'lang' => '🌍 Sprache'
            ],
            'fr' => [
                'start' => '🏠 Démarrer',
                'link' => '🔗 Lier le Compte',
                'status' => '📋 Statut',
                'spaces' => '🅿️ Places',
                'weather' => '☁️ Météo',
                'preferences' => '⚙️ Préférences',
                'reserve' => '✅ Réserver',
                'help' => '❓ Aide',
                'app' => '🌐 App Web',
                'lang' => '🌍 Langue'
            ],
            'ar' => [
                'start' => '🏠 البداية',
                'link' => '🔗 ربط الحساب',
                'status' => '📋 الحالة',
                'spaces' => '🅿️ الأماكن',
                'weather' => '☁️ الطقس',
                'preferences' => '⚙️ التفضيلات',
                'reserve' => '✅ حجز',
                'help' => '❓ المساعدة',
                'app' => '🌐 التطبيق الإلكتروني',
                'lang' => '🌍 اللغة'
            ]
        ];
        
        $lang_labels = $labels[$lang] ?? $labels['en'];
        
        // Create keyboard layout
        // First row: Start, Help
        // Second row: Link Account, Status
        // Third row: Spaces, Weather
        // Fourth row: Preferences, Reserve
        // Fifth row: Web App, Language
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => $lang_labels['start']],
                    ['text' => $lang_labels['help']]
                ],
                [
                    ['text' => $lang_labels['link']],
                    ['text' => $lang_labels['status']]
                ],
                [
                    ['text' => $lang_labels['spaces']],
                    ['text' => $lang_labels['weather']]
                ],
                [
                    ['text' => $lang_labels['preferences']],
                    ['text' => $lang_labels['reserve']]
                ],
                [
                    ['text' => $lang_labels['app']],
                    ['text' => $lang_labels['lang']]
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'selective' => false
        ];
        
        return $keyboard;
    }
    
    /**
     * Get command from button text (reverse lookup)
     * Checks all languages to find the command
     */
    public static function getCommandFromButton($button_text, $lang = 'en') {
        $labels = [
            'en' => [
                '🏠 Start' => '/start',
                '🔗 Link Account' => '/link',
                '📋 Status' => '/status',
                '🅿️ Spaces' => '/spaces',
                '☁️ Weather' => '/weather',
                '⚙️ Preferences' => '/preferences',
                '✅ Reserve' => '/reserve',
                '❓ Help' => '/help',
                '🌐 Web App' => '/app',
                '🌍 Language' => '/lang'
            ],
            'sr' => [
                '🏠 Početak' => '/start',
                '🔗 Poveži Nalog' => '/link',
                '📋 Status' => '/status',
                '🅿️ Mesta' => '/spaces',
                '☁️ Vreme' => '/weather',
                '⚙️ Postavke' => '/preferences',
                '✅ Rezerviši' => '/reserve',
                '❓ Pomoć' => '/help',
                '🌐 Web Aplikacija' => '/app',
                '🌍 Jezik' => '/lang'
            ],
            'de' => [
                '🏠 Start' => '/start',
                '🔗 Konto Verknüpfen' => '/link',
                '📋 Status' => '/status',
                '🅿️ Parkplätze' => '/spaces',
                '☁️ Wetter' => '/weather',
                '⚙️ Einstellungen' => '/preferences',
                '✅ Reservieren' => '/reserve',
                '❓ Hilfe' => '/help',
                '🌐 Web-App' => '/app',
                '🌍 Sprache' => '/lang'
            ],
            'fr' => [
                '🏠 Démarrer' => '/start',
                '🔗 Lier le Compte' => '/link',
                '📋 Statut' => '/status',
                '🅿️ Places' => '/spaces',
                '☁️ Météo' => '/weather',
                '⚙️ Préférences' => '/preferences',
                '✅ Réserver' => '/reserve',
                '❓ Aide' => '/help',
                '🌐 App Web' => '/app',
                '🌍 Langue' => '/lang'
            ],
            'ar' => [
                '🏠 البداية' => '/start',
                '🔗 ربط الحساب' => '/link',
                '📋 الحالة' => '/status',
                '🅿️ الأماكن' => '/spaces',
                '☁️ الطقس' => '/weather',
                '⚙️ التفضيلات' => '/preferences',
                '✅ حجز' => '/reserve',
                '❓ المساعدة' => '/help',
                '🌐 التطبيق الإلكتروني' => '/app',
                '🌍 اللغة' => '/lang'
            ]
        ];
        
        // First try user's language
        if (isset($labels[$lang]) && isset($labels[$lang][$button_text])) {
            return $labels[$lang][$button_text];
        }
        
        // If not found, check all languages
        foreach ($labels as $lang_code => $lang_labels) {
            if (isset($lang_labels[$button_text])) {
                return $lang_labels[$button_text];
            }
        }
        
        return null;
    }
}

