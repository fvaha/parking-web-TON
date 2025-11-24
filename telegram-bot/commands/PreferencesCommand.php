<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\DatabaseService;
use TelegramBot\Services\LanguageService;

class PreferencesCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $text = $message->getText();
        
        error_log("PreferencesCommand: Received text: '{$text}'");
        
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
        
        // Parse command - handle both /preferences and just "preferences"
        $text_trimmed = trim($text);
        // Remove leading / if present
        if (substr($text_trimmed, 0, 1) === '/') {
            $text_trimmed = substr($text_trimmed, 1);
        }
        // Remove "preferences" part to get subcommand
        $text_lower = strtolower($text_trimmed);
        if (substr($text_lower, 0, 11) === 'preferences') {
            $parts = explode(' ', $text_trimmed, 2);
            $subcommand = isset($parts[1]) ? trim($parts[1]) : '';
        } else {
            // If text doesn't start with "preferences", treat as subcommand
            $subcommand = $text_trimmed;
        }
        
        error_log("PreferencesCommand: Original text: '{$text}', Parsed subcommand: '{$subcommand}' (empty: " . (empty($subcommand) ? 'yes' : 'no') . ")");
        
        if (empty($subcommand)) {
            // Get user language
            $user_obj = $message->getFrom();
            $lang = LanguageService::getLanguage($user_obj);
            
            // Show current preferences with translations
            $pref_title_texts = [
                'en' => "🔔 Notification Preferences\n\n",
                'sr' => "🔔 Postavke Obaveštenja\n\n",
                'de' => "🔔 Benachrichtigungseinstellungen\n\n",
                'fr' => "🔔 Préférences de Notification\n\n",
                'ar' => "🔔 تفضيلات الإشعارات\n\n"
            ];
            
            $notify_free_texts = [
                'en' => "Notify free spaces: ",
                'sr' => "Obaveštavaj za slobodna mesta: ",
                'de' => "Benachrichtigen bei freien Plätzen: ",
                'fr' => "Notifier pour places libres: ",
                'ar' => "إشعار للأماكن المجانية: "
            ];
            
            $notify_expiry_texts = [
                'en' => "Notify reservation expiry (10 min): ",
                'sr' => "Obaveštavaj o isteku rezervacije (10 min): ",
                'de' => "Benachrichtigen bei Reservierungsablauf (10 Min): ",
                'fr' => "Notifier expiration réservation (10 min): ",
                'ar' => "إشعار انتهاء الحجز (10 دقائق): "
            ];
            
            $notify_space_texts = [
                'en' => "Notify specific space: ",
                'sr' => "Obaveštavaj za određeno mesto: ",
                'de' => "Benachrichtigen für bestimmten Platz: ",
                'fr' => "Notifier pour place spécifique: ",
                'ar' => "إشعار لمكان محدد: "
            ];
            
            $notify_street_texts = [
                'en' => "Notify street: ",
                'sr' => "Obaveštavaj za ulicu: ",
                'de' => "Benachrichtigen für Straße: ",
                'fr' => "Notifier pour rue: ",
                'ar' => "إشعار للشارع: "
            ];
            
            $notify_zone_texts = [
                'en' => "Notify zone: ",
                'sr' => "Obaveštavaj za zonu: ",
                'de' => "Benachrichtigen für Zone: ",
                'fr' => "Notifier pour zone: ",
                'ar' => "إشعار للمنطقة: "
            ];
            
            $none_texts = [
                'en' => 'None',
                'sr' => 'Nema',
                'de' => 'Keine',
                'fr' => 'Aucun',
                'ar' => 'لا شيء'
            ];
            
            $yes_texts = [
                'en' => '✅ Yes',
                'sr' => '✅ Da',
                'de' => '✅ Ja',
                'fr' => '✅ Oui',
                'ar' => '✅ نعم'
            ];
            
            $no_texts = [
                'en' => '❌ No',
                'sr' => '❌ Ne',
                'de' => '❌ Nein',
                'fr' => '❌ Non',
                'ar' => '❌ لا'
            ];
            
            $click_buttons_texts = [
                'en' => "Click buttons below to update preferences:\n",
                'sr' => "Kliknite dugmad ispod da ažurirate postavke:\n",
                'de' => "Klicken Sie auf die Schaltflächen unten, um Einstellungen zu aktualisieren:\n",
                'fr' => "Cliquez sur les boutons ci-dessous pour mettre à jour les préférences:\n",
                'ar' => "انقر على الأزرار أدناه لتحديث التفضيلات:\n"
            ];
            
            $pref_text = $pref_title_texts[$lang] ?? $pref_title_texts['en'];
            $pref_text .= $notify_free_texts[$lang] ?? $notify_free_texts['en'];
            $pref_text .= ($preferences['notify_free_spaces'] ? ($yes_texts[$lang] ?? $yes_texts['en']) : ($no_texts[$lang] ?? $no_texts['en'])) . "\n";
            $pref_text .= $notify_expiry_texts[$lang] ?? $notify_expiry_texts['en'];
            $pref_text .= ((!isset($preferences['notify_reservation_expiry']) || $preferences['notify_reservation_expiry'] != 0) ? ($yes_texts[$lang] ?? $yes_texts['en']) : ($no_texts[$lang] ?? $no_texts['en'])) . "\n";
            $pref_text .= $notify_space_texts[$lang] ?? $notify_space_texts['en'];
            $pref_text .= ($preferences['notify_specific_space'] ?? ($none_texts[$lang] ?? $none_texts['en'])) . "\n";
            $pref_text .= $notify_street_texts[$lang] ?? $notify_street_texts['en'];
            $pref_text .= ($preferences['notify_street'] ?? ($none_texts[$lang] ?? $none_texts['en'])) . "\n";
            $pref_text .= $notify_zone_texts[$lang] ?? $notify_zone_texts['en'];
            $pref_text .= ($preferences['notify_zone'] ?? ($none_texts[$lang] ?? $none_texts['en'])) . "\n\n";
            $pref_text .= $click_buttons_texts[$lang] ?? $click_buttons_texts['en'];
            
            // Create inline keyboard with update options
            $back_texts = [
                'en' => '🏠 Back to Menu',
                'sr' => '🏠 Nazad na Meni',
                'de' => '🏠 Zurück zum Menü',
                'fr' => '🏠 Retour au Menu',
                'ar' => '🏠 العودة إلى القائمة'
            ];
            
            $free_on_text = [
                'en' => '✅ Free: ON',
                'sr' => '✅ Slobodno: UKLJUČENO',
                'de' => '✅ Frei: EIN',
                'fr' => '✅ Libre: ON',
                'ar' => '✅ مجاني: تشغيل'
            ];
            $free_off_text = [
                'en' => '❌ Free: OFF',
                'sr' => '❌ Slobodno: ISKLJUČENO',
                'de' => '❌ Frei: AUS',
                'fr' => '❌ Libre: OFF',
                'ar' => '❌ مجاني: إيقاف'
            ];
            $expiry_on_text = [
                'en' => '✅ Expiry: ON',
                'sr' => '✅ Istek: UKLJUČENO',
                'de' => '✅ Ablauf: EIN',
                'fr' => '✅ Expiration: ON',
                'ar' => '✅ انتهاء: تشغيل'
            ];
            $expiry_off_text = [
                'en' => '❌ Expiry: OFF',
                'sr' => '❌ Istek: ISKLJUČENO',
                'de' => '❌ Ablauf: AUS',
                'fr' => '❌ Expiration: OFF',
                'ar' => '❌ انتهاء: إيقاف'
            ];
            
            $select_space_text = [
                'en' => '🅿️ Select Space',
                'sr' => '🅿️ Izaberi Mesto',
                'de' => '🅿️ Platz Auswählen',
                'fr' => '🅿️ Sélectionner Place',
                'ar' => '🅿️ اختر المكان'
            ];
            
            $select_street_text = [
                'en' => '🛣️ Select Street',
                'sr' => '🛣️ Izaberi Ulicu',
                'de' => '🛣️ Straße Auswählen',
                'fr' => '🛣️ Sélectionner Rue',
                'ar' => '🛣️ اختر الشارع'
            ];
            
            $select_zone_text = [
                'en' => '📍 Select Zone',
                'sr' => '📍 Izaberi Zonu',
                'de' => '📍 Zone Auswählen',
                'fr' => '📍 Sélectionner Zone',
                'ar' => '📍 اختر المنطقة'
            ];
            
            $clear_space_text = [
                'en' => '🗑️ Clear Space',
                'sr' => '🗑️ Obriši Mesto',
                'de' => '🗑️ Platz Löschen',
                'fr' => '🗑️ Effacer Place',
                'ar' => '🗑️ مسح المكان'
            ];
            
            $clear_street_text = [
                'en' => '🗑️ Clear Street',
                'sr' => '🗑️ Obriši Ulicu',
                'de' => '🗑️ Straße Löschen',
                'fr' => '🗑️ Effacer Rue',
                'ar' => '🗑️ مسح الشارع'
            ];
            
            $clear_zone_text = [
                'en' => '🗑️ Clear Zone',
                'sr' => '🗑️ Obriši Zonu',
                'de' => '🗑️ Zone Löschen',
                'fr' => '🗑️ Effacer Zone',
                'ar' => '🗑️ مسح المنطقة'
            ];
            
            $keyboard = [
                'inline_keyboard' => [
                    // First row: Free spaces toggle
                    [
                        [
                            'text' => ($preferences['notify_free_spaces'] ? $free_off_text[$lang] ?? $free_off_text['en'] : $free_on_text[$lang] ?? $free_on_text['en']),
                            'callback_data' => 'pref_free:' . ($preferences['notify_free_spaces'] ? 'off' : 'on')
                        ]
                    ],
                    // Second row: Expiry toggle
                    [
                        [
                            'text' => ((!isset($preferences['notify_reservation_expiry']) || $preferences['notify_reservation_expiry'] != 0) ? $expiry_off_text[$lang] ?? $expiry_off_text['en'] : $expiry_on_text[$lang] ?? $expiry_on_text['en']),
                            'callback_data' => 'pref_expiry:' . ((!isset($preferences['notify_reservation_expiry']) || $preferences['notify_reservation_expiry'] != 0) ? 'off' : 'on')
                        ]
                    ],
                    // Third row: Space selection
                    [
                        [
                            'text' => ($select_space_text[$lang] ?? $select_space_text['en']),
                            'callback_data' => 'pref_select_space'
                        ]
                    ],
                    // Fourth row: Street selection
                    [
                        [
                            'text' => ($select_street_text[$lang] ?? $select_street_text['en']),
                            'callback_data' => 'pref_select_street'
                        ]
                    ],
                    // Fifth row: Zone selection
                    [
                        [
                            'text' => ($select_zone_text[$lang] ?? $select_zone_text['en']),
                            'callback_data' => 'pref_select_zone'
                        ]
                    ],
                    // Sixth row: Clear space/street/zone
                    [
                        [
                            'text' => ($clear_space_text[$lang] ?? $clear_space_text['en']),
                            'callback_data' => 'pref_clear_space'
                        ],
                        [
                            'text' => ($clear_street_text[$lang] ?? $clear_street_text['en']),
                            'callback_data' => 'pref_clear_street'
                        ]
                    ],
                    [
                        [
                            'text' => ($clear_zone_text[$lang] ?? $clear_zone_text['en']),
                            'callback_data' => 'pref_clear_zone'
                        ]
                    ],
                    // Last row: Back to Menu
                    [
                        [
                            'text' => $back_texts[$lang] ?? $back_texts['en'],
                            'callback_data' => 'menu_start'
                        ]
                    ]
                ]
            ];
            
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => $pref_text,
                'reply_markup' => json_encode($keyboard)
            ]);
            return;
        }
        
        // Update preferences
        $pref_parts = explode(' ', $subcommand, 2);
        $pref_type = $pref_parts[0];
        $pref_value = isset($pref_parts[1]) ? trim($pref_parts[1]) : '';
        
        error_log("PreferencesCommand: Updating preference - type: '{$pref_type}', value: '{$pref_value}'");
        
        // Error message texts for invalid preference type
        $invalid_pref_texts = [
            'en' => "❌ Invalid preference type. Use: free, expiry, space, street, or zone",
            'sr' => "❌ Nevažeći tip postavke. Koristite: free, expiry, space, street, ili zone",
            'de' => "❌ Ungültiger Einstellungstyp. Verwenden Sie: free, expiry, space, street oder zone",
            'fr' => "❌ Type de préférence invalide. Utilisez: free, expiry, space, street ou zone",
            'ar' => "❌ نوع تفضيل غير صالح. استخدم: free, expiry, space, street أو zone"
        ];
        
        $update_data = [];
        
        switch ($pref_type) {
            case 'free':
                $update_data['notify_free_spaces'] = ($pref_value === 'on');
                break;
            case 'expiry':
            case 'expiration':
            case 'reservation':
                $update_data['notify_reservation_expiry'] = ($pref_value === 'on') ? 1 : 0;
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
                    'text' => ($invalid_pref_texts[$lang] ?? $invalid_pref_texts['en'])
                ]);
                return;
        }
        
        // Merge with existing preferences
        if ($preferences) {
            $update_data = array_merge([
                'notify_free_spaces' => $preferences['notify_free_spaces'],
                'notify_reservation_expiry' => $preferences['notify_reservation_expiry'] ?? 1, // Default enabled
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

