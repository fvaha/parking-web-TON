<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\LanguageService;
use TelegramBot\Services\KeyboardService;

class HelpCommand {
    public function handle($bot, $message) {
        try {
            error_log("HelpCommand: handle() called");
            
            if (!$message) {
                error_log("HelpCommand: Message is null!");
                return;
            }
            
            $chat_id = $message->getChat()->getId();
            if (!$chat_id) {
                error_log("HelpCommand: Chat ID is null!");
                return;
            }
            
            $user = $message->getFrom();
            if (!$user) {
                error_log("HelpCommand: User is null!");
                return;
            }
            
            $lang = LanguageService::getLanguage($user);
            
            error_log("HelpCommand: Processing help command for chat {$chat_id}, language: {$lang}, user_id: " . $user->getId());
            
            $separator = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
            
            // Build help text
            $text = $separator . "\n";
            
            // Get help_title - handle potential null
            try {
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
                    try {
                        $item_text = LanguageService::t($item, $lang);
                        if (!empty($item_text)) {
                            $text .= $item_text;
                        } else {
                            error_log("HelpCommand: Missing translation for {$item} in language {$lang}");
                        }
                    } catch (\Exception $item_error) {
                        error_log("HelpCommand: Error getting translation for {$item}: " . $item_error->getMessage());
                    }
                }
                
                $text .= "\n" . $separator . "\n";
                try {
                    $help_tip = LanguageService::t('help_tip', $lang);
                    if (!empty($help_tip)) {
                        $text .= $help_tip . "\n";
                    }
                } catch (\Exception $tip_error) {
                    error_log("HelpCommand: Error getting help_tip: " . $tip_error->getMessage());
                }
                $text .= $separator;
            } catch (\Exception $text_error) {
                error_log("HelpCommand: Error building help text: " . $text_error->getMessage());
                // Fallback to simple help text with translations
                $fallback_help_texts = [
                    'en' => [
                        'title' => "📖 Available Commands\n\n",
                        'start' => "/start - Show welcome message\n",
                        'help' => "/help - Show this help message\n",
                        'lang' => "/lang - Change language\n",
                        'preferences' => "/preferences - Manage notification preferences\n",
                        'spaces' => "/spaces - View parking spaces\n",
                        'reserve' => "/reserve - Reserve a parking space\n",
                        'status' => "/status - Check your reservations\n",
                        'weather' => "/weather - Check weather\n",
                        'link' => "/link - Link your account\n"
                    ],
                    'sr' => [
                        'title' => "📖 Dostupne Komande\n\n",
                        'start' => "/start - Prikaži poruku dobrodošlice\n",
                        'help' => "/help - Prikaži ovu poruku pomoći\n",
                        'lang' => "/lang - Promeni jezik\n",
                        'preferences' => "/preferences - Upravljaj postavkama obaveštenja\n",
                        'spaces' => "/spaces - Pregled parking mesta\n",
                        'reserve' => "/reserve - Rezerviši parking mesto\n",
                        'status' => "/status - Proveri rezervacije\n",
                        'weather' => "/weather - Proveri vreme\n",
                        'link' => "/link - Poveži nalog\n"
                    ],
                    'de' => [
                        'title' => "📖 Verfügbare Befehle\n\n",
                        'start' => "/start - Willkommensnachricht anzeigen\n",
                        'help' => "/help - Diese Hilfenachricht anzeigen\n",
                        'lang' => "/lang - Sprache ändern\n",
                        'preferences' => "/preferences - Benachrichtigungseinstellungen verwalten\n",
                        'spaces' => "/spaces - Parkplätze anzeigen\n",
                        'reserve' => "/reserve - Parkplatz reservieren\n",
                        'status' => "/status - Reservierungen prüfen\n",
                        'weather' => "/weather - Wetter prüfen\n",
                        'link' => "/link - Konto verknüpfen\n"
                    ],
                    'fr' => [
                        'title' => "📖 Commandes Disponibles\n\n",
                        'start' => "/start - Afficher le message de bienvenue\n",
                        'help' => "/help - Afficher ce message d'aide\n",
                        'lang' => "/lang - Changer la langue\n",
                        'preferences' => "/preferences - Gérer les préférences de notification\n",
                        'spaces' => "/spaces - Voir les places de stationnement\n",
                        'reserve' => "/reserve - Réserver une place de stationnement\n",
                        'status' => "/status - Vérifier vos réservations\n",
                        'weather' => "/weather - Vérifier la météo\n",
                        'link' => "/link - Lier votre compte\n"
                    ],
                    'ar' => [
                        'title' => "📖 الأوامر المتاحة\n\n",
                        'start' => "/start - عرض رسالة الترحيب\n",
                        'help' => "/help - عرض رسالة المساعدة هذه\n",
                        'lang' => "/lang - تغيير اللغة\n",
                        'preferences' => "/preferences - إدارة تفضيلات الإشعارات\n",
                        'spaces' => "/spaces - عرض أماكن وقوف السيارات\n",
                        'reserve' => "/reserve - حجز مكان وقوف السيارات\n",
                        'status' => "/status - التحقق من حجوزاتك\n",
                        'weather' => "/weather - التحقق من الطقس\n",
                        'link' => "/link - ربط حسابك\n"
                    ]
                ];
                
                $fallback = $fallback_help_texts[$lang] ?? $fallback_help_texts['en'];
                $text = $fallback['title'];
                $text .= $fallback['start'];
                $text .= $fallback['help'];
                $text .= $fallback['lang'];
                $text .= $fallback['preferences'];
                $text .= $fallback['spaces'];
                $text .= $fallback['reserve'];
                $text .= $fallback['status'];
                $text .= $fallback['weather'];
                $text .= $fallback['link'];
            }
            
            // Create inline keyboard with menu button to go back to start
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
                        [
                            'text' => $back_texts[$lang] ?? $back_texts['en'],
                            'callback_data' => 'menu_start'
                        ]
                    ]
                ]
            ];
            
            // Prepare message parameters
            $message_params = [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ];
            
            error_log("HelpCommand: Sending help message to chat {$chat_id}, text length: " . strlen($text));
            error_log("HelpCommand: Keyboard: " . json_encode($keyboard));
            
            try {
                $result = $bot->sendMessage($message_params);
                
                if ($result === false || (is_array($result) && isset($result['ok']) && !$result['ok'])) {
                    error_log("HelpCommand: Failed to send message with Markdown, trying without Markdown");
                    // Try without Markdown if it fails
                    unset($message_params['parse_mode']);
                    // Remove markdown formatting
                    $text_plain = preg_replace('/\*([^*]+)\*/', '$1', $text);
                    $text_plain = preg_replace('/_([^_]+)_/', '$1', $text_plain);
                    $message_params['text'] = $text_plain;
                    $result = $bot->sendMessage($message_params);
                    
                    if ($result === false || (is_array($result) && isset($result['ok']) && !$result['ok'])) {
                        error_log("HelpCommand: Failed to send message even without Markdown");
                        // Last resort: send simple message
                        $fallback_texts = [
                            'en' => "📖 Help\n\nUse /start to see available commands.",
                            'sr' => "📖 Pomoć\n\nKoristite /start da vidite dostupne komande.",
                            'de' => "📖 Hilfe\n\nVerwenden Sie /start, um verfügbare Befehle anzuzeigen.",
                            'fr' => "📖 Aide\n\nUtilisez /start pour voir les commandes disponibles.",
                            'ar' => "📖 المساعدة\n\nاستخدم /start لرؤية الأوامر المتاحة."
                        ];
                        $bot->sendMessage([
                            'chat_id' => $chat_id,
                            'text' => $fallback_texts[$lang] ?? $fallback_texts['en']
                        ]);
                    } else {
                        error_log("HelpCommand: Help message sent successfully (without Markdown)");
                    }
                } else {
                    error_log("HelpCommand: Help message sent successfully");
                }
            } catch (\Exception $send_exception) {
                error_log("HelpCommand: Exception while sending: " . $send_exception->getMessage());
                // Try to send simple message
                try {
                    $fallback_texts = [
                        'en' => "📖 Help\n\nUse /start to see available commands.",
                        'sr' => "📖 Pomoć\n\nKoristite /start da vidite dostupne komande.",
                        'de' => "📖 Hilfe\n\nVerwenden Sie /start, um verfügbare Befehle anzuzeigen.",
                        'fr' => "📖 Aide\n\nUtilisez /start pour voir les commandes disponibles.",
                        'ar' => "📖 المساعدة\n\nاستخدم /start لرؤية الأوامر المتاحة."
                    ];
                    $bot->sendMessage([
                        'chat_id' => $chat_id,
                        'text' => $fallback_texts[$lang] ?? $fallback_texts['en']
                    ]);
                } catch (\Exception $e2) {
                    error_log("HelpCommand: Failed to send fallback message: " . $e2->getMessage());
                }
            }
            
        } catch (\Throwable $e) {
            error_log("HelpCommand: Exception - " . $e->getMessage());
            error_log("HelpCommand: Stack trace - " . $e->getTraceAsString());
            
            // Try to send error message
            try {
                $chat_id = $message->getChat()->getId();
                $user = $message->getFrom();
                $error_lang = LanguageService::getLanguage($user);
                $error_texts = [
                    'en' => "❌ Error showing help. Please try again later.",
                    'sr' => "❌ Greška pri prikazivanju pomoći. Molimo pokušajte ponovo kasnije.",
                    'de' => "❌ Fehler beim Anzeigen der Hilfe. Bitte versuchen Sie es später erneut.",
                    'fr' => "❌ Erreur lors de l'affichage de l'aide. Veuillez réessayer plus tard.",
                    'ar' => "❌ خطأ في عرض المساعدة. يرجى المحاولة مرة أخرى لاحقًا."
                ];
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $error_texts[$error_lang] ?? $error_texts['en']
                ]);
            } catch (\Exception $send_error) {
                error_log("HelpCommand: Failed to send error message: " . $send_error->getMessage());
            }
        }
    }
}

