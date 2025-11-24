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
            
            // Create keyboard with wallet opening options
            // Telegram Bot API 6.0+ supports 'wallet' button type for direct wallet opening
            // This opens Telegram wallet directly in supported clients
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => LanguageService::t('wallet_create_telegram', $lang),
                            // Use wallet button type (Telegram Bot API 6.0+)
                            // This opens Telegram wallet directly in supported clients
                            // For older clients that don't support 'wallet' type,
                            // Telegram API will return an error, so we'll handle it gracefully
                            'wallet' => true
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
            
            // Note: 'wallet' => true opens Telegram wallet directly in modern clients
            // If the API call fails (older clients), we can catch and retry with URL
            // But for now, let's try wallet button first as it provides better UX
        }
        
        // Try to send message with wallet button
        // If wallet button is not supported, fall back to URL
        $result = $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ]);
        
        // If wallet button failed (older clients), retry with URL
        if (!$result || (isset($result['ok']) && !$result['ok'])) {
            error_log("WalletCommand: Wallet button not supported, falling back to URL");
            // Fallback to URL for older clients
            if (!$has_wallet) {
                $keyboard['inline_keyboard'][0][0] = [
                    'text' => LanguageService::t('wallet_create_telegram', $lang),
                    'url' => 'https://t.me/wallet'
                ];
                
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($keyboard)
                ]);
            }
        }
    }
    
    public function showWalletConnectPrompt($bot, $chat_id, $lang) {
        $text = LanguageService::t('wallet_enter_address', $lang);
        
        // Remove reply keyboard so user can type wallet address freely
        $bot->removeReplyKeyboard($chat_id);
        
        $bot->sendMessage([
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
    
    public function handleWalletAddress($bot, $chat_id, $user_id, $wallet_address, $lang) {
        error_log("WalletCommand::handleWalletAddress: Starting - user_id={$user_id}, address={$wallet_address}");
        
        $db_service = new DatabaseService();
        $db = $db_service->getDatabase();
        
        if (!$db) {
            error_log("WalletCommand::handleWalletAddress: Database connection failed");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => "❌ Database error. Please contact administrator."
            ]);
            return;
        }
        
        // Validate wallet address format (basic validation)
        $wallet_address = trim($wallet_address);
        error_log("WalletCommand::handleWalletAddress: Trimmed address='{$wallet_address}', length=" . strlen($wallet_address));
        
        // TON wallet addresses can start with EQ, UQ, kQ, EQD, or 0:
        // EQ/UQ/kQ are base64url encoded addresses (typically 46-48 chars after prefix)
        // 0: is raw format
        if (!preg_match('/^(EQ|UQ|kQ|EQD|0:)[A-Za-z0-9_-]{46,48}$/', $wallet_address)) {
            error_log("WalletCommand::handleWalletAddress: Address doesn't match exact format, trying to extract...");
            // Try to extract address from message (check for any valid prefix)
            if (preg_match('/((EQ|UQ|kQ|EQD)[A-Za-z0-9_-]{46,48})/', $wallet_address, $matches)) {
                $wallet_address = $matches[1];
                error_log("WalletCommand::handleWalletAddress: Extracted address='{$wallet_address}'");
            } elseif (preg_match('/(0:[A-Za-z0-9_-]{46,48})/', $wallet_address, $matches)) {
                $wallet_address = $matches[1];
                error_log("WalletCommand::handleWalletAddress: Extracted address='{$wallet_address}'");
            } else {
                error_log("WalletCommand::handleWalletAddress: Invalid address format");
                $bot->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => LanguageService::t('wallet_invalid_address', $lang)
                ]);
                return;
            }
        }
        
        // Check if user exists in database
        $user_data = $db->getTelegramUserByTelegramId($user_id);
        if (!$user_data) {
            error_log("WalletCommand::handleWalletAddress: User not found in database, cannot save wallet address");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('not_linked', $lang) . "\n\n" . LanguageService::t('wallet_enter_address', $lang)
            ]);
            return;
        }
        
        error_log("WalletCommand::handleWalletAddress: User found, saving wallet address...");
        
        // Save wallet address
        $result = $db->updateTelegramUserWallet($user_id, $wallet_address);
        
        error_log("WalletCommand::handleWalletAddress: Update result: " . json_encode($result));
        
        if ($result['success']) {
            error_log("WalletCommand::handleWalletAddress: Wallet address saved successfully");
            $bot->sendMessage([
                'chat_id' => $chat_id,
                'text' => LanguageService::t('wallet_connected', $lang, [
                    'address' => $wallet_address
                ]),
                'parse_mode' => 'Markdown'
            ]);
        } else {
            error_log("WalletCommand::handleWalletAddress: Failed to save wallet address: " . ($result['error'] ?? 'Unknown error'));
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

