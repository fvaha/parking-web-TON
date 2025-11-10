<?php
namespace TelegramBot\Commands;

use TelegramBot\Services\DatabaseService;
use TelegramBot\Services\LanguageService;

class WalletCommand {
    public function handle($bot, $message) {
        $chat_id = $message->getChat()->getId();
        $user_id = $message->getFrom()->getId();
        $user = $message->getFrom();
        $lang = LanguageService::getLanguage($user);
        
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        
        // Get user
        $user_data = $db->getTelegramUserByTelegramId($user_id);
        if (!$user_data) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('not_linked', $lang)
            ]);
            return;
        }
        
        // Check if user already has wallet
        $has_wallet = !empty($user_data['ton_wallet_address']);
        
        if ($has_wallet) {
            // Show wallet info
            $text = LanguageService::t('wallet_info', $lang, [
                'address' => $user_data['ton_wallet_address']
            ]);
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => LanguageService::t('wallet_change', $lang),
                            'callback_data' => 'wallet_change'
                        ],
                        [
                            'text' => LanguageService::t('wallet_disconnect', $lang),
                            'callback_data' => 'wallet_disconnect'
                        ]
                    ]
                ]
            ];
        } else {
            // Show wallet creation options
            $text = LanguageService::t('wallet_not_connected', $lang);
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => LanguageService::t('wallet_create_telegram', $lang),
                            'url' => 'https://t.me/wallet'
                        ]
                    ],
                    [
                        [
                            'text' => LanguageService::t('wallet_connect_existing', $lang),
                            'callback_data' => 'wallet_connect'
                        ]
                    ]
                ]
            ];
        }
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
    }
    
    public function showWalletConnectPrompt($bot, $chat_id, $lang) {
        $text = LanguageService::t('wallet_enter_address', $lang);
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
    
    public function handleWalletAddress($bot, $chat_id, $user_id, $wallet_address, $lang) {
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        
        // Validate wallet address format (basic validation)
        $wallet_address = trim($wallet_address);
        
        // TON wallet addresses start with EQ, EQD, or 0:
        if (!preg_match('/^(EQ|EQD|0:)[A-Za-z0-9_-]{48}$/', $wallet_address)) {
            // Try to extract address from message
            if (preg_match('/(EQ[A-Za-z0-9_-]{48})/', $wallet_address, $matches)) {
                $wallet_address = $matches[1];
            } else {
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('wallet_invalid_address', $lang)
                ]);
                return;
            }
        }
        
        // Save wallet address
        $result = $db->updateTelegramUserWallet($user_id, $wallet_address);
        
        if ($result['success']) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('wallet_connected', $lang, [
                    'address' => $wallet_address
                ]),
                'parse_mode' => 'Markdown'
            ]);
        } else {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('wallet_connect_failed', $lang, [
                    'error' => $result['error'] ?? 'Unknown error'
                ])
            ]);
        }
    }
    
    public function disconnectWallet($bot, $chat_id, $user_id, $lang) {
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        
        $result = $db->updateTelegramUserWallet($user_id, null);
        
        if ($result['success']) {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('wallet_disconnected', $lang)
            ]);
        } else {
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('wallet_disconnect_failed', $lang)
            ]);
        }
    }
}

