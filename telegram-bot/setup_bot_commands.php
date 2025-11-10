<?php
/**
 * Script to set up Telegram bot commands in BotFather
 * This registers commands so they appear in the bot menu
 * Run this once after setting up the webhook
 * Access: https://parkiraj.info/telegram-bot/setup_bot_commands.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/TelegramAPI.php';

header('Content-Type: text/html; charset=utf-8');

$bot_token = TELEGRAM_BOT_TOKEN;

echo "<h1>Telegram Bot Commands Setup</h1>";
echo "<pre>";

// Check if bot token is set
if (empty($bot_token)) {
    echo "❌ ERROR: Bot token is not set!\n";
    echo "Please set TELEGRAM_BOT_TOKEN in config.php\n";
    exit;
}

echo "✓ Bot token: " . substr($bot_token, 0, 10) . "...\n\n";

// Define bot commands for all languages
$commands = [
    [
        'command' => 'start',
        'description' => 'Start the bot and see welcome message'
    ],
    [
        'command' => 'link',
        'description' => 'Link your Telegram account with license plate'
    ],
    [
        'command' => 'status',
        'description' => 'Check your active parking reservations'
    ],
    [
        'command' => 'spaces',
        'description' => 'List available parking spaces'
    ],
    [
        'command' => 'weather',
        'description' => 'Get weather and air quality information'
    ],
    [
        'command' => 'preferences',
        'description' => 'Manage notification preferences'
    ],
    [
        'command' => 'reserve',
        'description' => 'Reserve a parking space'
    ],
    [
        'command' => 'wallet',
        'description' => 'Manage your TON wallet connection'
    ],
    [
        'command' => 'help',
        'description' => 'Show all available commands'
    ],
    [
        'command' => 'app',
        'description' => 'Open web application'
    ],
    [
        'command' => 'lang',
        'description' => 'Change language'
    ]
];

echo "Setting bot commands...\n";
echo "Commands to register:\n";
foreach ($commands as $cmd) {
    echo "  /{$cmd['command']} - {$cmd['description']}\n";
}
echo "\n";

// Set commands via Telegram API
$set_commands_url = "https://api.telegram.org/bot{$bot_token}/setMyCommands";
$set_commands_params = [
    'commands' => $commands
];

$ch = curl_init($set_commands_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($set_commands_params));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response) {
    $result = json_decode($response, true);
    if ($result['ok']) {
        echo "✓ Bot commands set successfully!\n\n";
        
        // Verify commands
        echo "Verifying commands...\n";
        $get_commands_url = "https://api.telegram.org/bot{$bot_token}/getMyCommands";
        $get_commands_response = @file_get_contents($get_commands_url);
        if ($get_commands_response) {
            $get_commands_data = json_decode($get_commands_response, true);
            if ($get_commands_data['ok']) {
                echo "✓ Verified commands:\n";
                foreach ($get_commands_data['result'] as $cmd) {
                    echo "  /{$cmd['command']} - {$cmd['description']}\n";
                }
            }
        }
    } else {
        echo "❌ Failed to set bot commands!\n";
        echo "Error: " . ($result['description'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "❌ Failed to connect to Telegram API\n";
}

echo "\n";
echo "========================================\n";
echo "Next steps:\n";
echo "1. Open your bot in Telegram\n";
echo "2. Type '/' to see the command menu\n";
echo "3. Try /start command\n";
echo "4. If it doesn't work, check webhook setup:\n";
echo "   https://parkiraj.info/telegram-bot/setup_webhook.php\n";
echo "========================================\n";

echo "</pre>";
?>

