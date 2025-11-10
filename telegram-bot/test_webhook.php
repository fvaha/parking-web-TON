<?php
/**
 * Test script to verify webhook is working
 * Access this file directly in browser: https://parkiraj.info/telegram-bot/test_webhook.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/TelegramAPI.php';

header('Content-Type: application/json');

$test_results = [
    'config_loaded' => defined('TELEGRAM_BOT_TOKEN'),
    'bot_token_set' => !empty(TELEGRAM_BOT_TOKEN),
    'telegram_api_class_exists' => class_exists('TelegramAPI'),
    'commands_exist' => [
        'StartCommand' => file_exists(__DIR__ . '/commands/StartCommand.php'),
        'LinkCommand' => file_exists(__DIR__ . '/commands/LinkCommand.php'),
        'HelpCommand' => file_exists(__DIR__ . '/commands/HelpCommand.php'),
    ],
    'webhook_url' => defined('TELEGRAM_WEBHOOK_URL') ? TELEGRAM_WEBHOOK_URL : 'Not defined',
    'web_app_url' => defined('WEB_APP_URL') ? WEB_APP_URL : 'Not defined',
    'no_composer_needed' => true,
];

// Test if we can create Telegram API instance
if ($test_results['bot_token_set']) {
    try {
        $telegram = new TelegramAPI(TELEGRAM_BOT_TOKEN);
        $test_results['api_connection'] = 'OK';
        
        // Test API call
        $bot_info = @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/getMe");
        if ($bot_info) {
            $bot_data = json_decode($bot_info, true);
            $test_results['bot_info'] = $bot_data['ok'] ? $bot_data['result'] : 'Failed';
        } else {
            $test_results['bot_info'] = 'Failed to fetch';
        }
    } catch (Exception $e) {
        $test_results['api_connection'] = 'FAILED: ' . $e->getMessage();
    }
} else {
    $test_results['api_connection'] = 'SKIPPED - Missing bot token';
}

// Check webhook status
if ($test_results['bot_token_set']) {
    $webhook_info = @file_get_contents("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/getWebhookInfo");
    if ($webhook_info) {
        $test_results['webhook_info'] = json_decode($webhook_info, true);
    } else {
        $test_results['webhook_info'] = 'Failed to fetch';
    }
}

echo json_encode($test_results, JSON_PRETTY_PRINT);
