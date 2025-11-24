<?php
/**
 * Script to set up Telegram webhook
 * Run this once to configure the webhook URL
 * Access: https://parkiraj.info/telegram-bot/setup_webhook.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/TelegramAPI.php';

header('Content-Type: text/html; charset=utf-8');

$bot_token = TELEGRAM_BOT_TOKEN;
$webhook_url = TELEGRAM_WEBHOOK_URL;

echo "<h1>Telegram Bot Webhook Setup</h1>";
echo "<pre>";

// Check if bot token is set
if (empty($bot_token)) {
    echo "❌ ERROR: Bot token is not set!\n";
    echo "Please set TELEGRAM_BOT_TOKEN in config.php\n";
    exit;
}

echo "✓ Bot token: " . substr($bot_token, 0, 10) . "...\n";
echo "✓ Webhook URL: {$webhook_url}\n\n";

// Test bot connection
echo "Testing bot connection...\n";
$bot_info_url = "https://api.telegram.org/bot{$bot_token}/getMe";
$bot_info = @file_get_contents($bot_info_url);

if ($bot_info) {
    $bot_data = json_decode($bot_info, true);
    if ($bot_data['ok']) {
        echo "✓ Bot is valid: @{$bot_data['result']['username']}\n";
        echo "  Bot name: {$bot_data['result']['first_name']}\n\n";
    } else {
        echo "❌ Bot token is invalid!\n";
        echo "Error: " . ($bot_data['description'] ?? 'Unknown error') . "\n";
        exit;
    }
} else {
    echo "❌ Failed to connect to Telegram API\n";
    exit;
}

// Check current webhook
echo "Checking current webhook...\n";
$webhook_info_url = "https://api.telegram.org/bot{$bot_token}/getWebhookInfo";
$webhook_info = @file_get_contents($webhook_info_url);

if ($webhook_info) {
    $webhook_data = json_decode($webhook_info, true);
    if ($webhook_data['ok']) {
        $info = $webhook_data['result'];
        echo "Current webhook URL: " . ($info['url'] ?: 'Not set') . "\n";
        echo "Pending updates: {$info['pending_update_count']}\n";
        if ($info['last_error_date']) {
            echo "⚠ Last error: {$info['last_error_message']} (at " . date('Y-m-d H:i:s', $info['last_error_date']) . ")\n";
        }
        echo "\n";
    }
}

// Set webhook
echo "Setting webhook to: {$webhook_url}\n";
$set_webhook_url = "https://api.telegram.org/bot{$bot_token}/setWebhook";
$set_webhook_params = [
    'url' => $webhook_url,
    'allowed_updates' => json_encode(['message', 'callback_query'])
];

$ch = curl_init($set_webhook_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($set_webhook_params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response) {
    $result = json_decode($response, true);
    if ($result['ok']) {
        echo "✓ Webhook set successfully!\n\n";
        
        // Verify webhook
        echo "Verifying webhook...\n";
        $verify_info = @file_get_contents($webhook_info_url);
        if ($verify_info) {
            $verify_data = json_decode($verify_info, true);
            if ($verify_data['ok']) {
                $verify_info = $verify_data['result'];
                echo "✓ Verified webhook URL: {$verify_info['url']}\n";
                echo "✓ Pending updates: {$verify_info['pending_update_count']}\n";
            }
        }
    } else {
        echo "❌ Failed to set webhook!\n";
        echo "Error: " . ($result['description'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "❌ Failed to connect to Telegram API\n";
}

echo "\n";
echo "========================================\n";
echo "Next steps:\n";
echo "1. Send /start to your bot in Telegram\n";
echo "2. Check if bot responds\n";
echo "3. If not, check server error logs\n";
echo "========================================\n";

echo "</pre>";
?>

