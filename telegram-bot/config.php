<?php
// Helper function to load .env file
function loadEnv($file_path) {
    if (!file_exists($file_path)) {
        return;
    }
    
    $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            // Set environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

// Load .env file from project root
$env_file = __DIR__ . '/../.env';
loadEnv($env_file);

// Telegram Bot Configuration
// SECURITY: Never hardcode bot tokens or API keys in this file. Always use environment variables.
// The bot token is loaded from TELEGRAM_BOT_TOKEN environment variable.
$telegram_bot_token = getenv('TELEGRAM_BOT_TOKEN');
if (!$telegram_bot_token) {
    error_log('ERROR: TELEGRAM_BOT_TOKEN is not set in environment variables');
    die('TELEGRAM_BOT_TOKEN is required. Please set it in your .env file.');
}
define('TELEGRAM_BOT_TOKEN', $telegram_bot_token);

define('TELEGRAM_WEBHOOK_URL', getenv('TELEGRAM_WEBHOOK_URL') ?: 'https://parkiraj.info/telegram-bot/webhook.php');
define('DATABASE_PATH', __DIR__ . '/../database/parking.db');
define('API_BASE_URL', 'https://parkiraj.info');
define('WEB_APP_URL', 'https://parkiraj.info');

$weather_api_key = getenv('WEATHER_API_KEY');
if (!$weather_api_key) {
    error_log('WARNING: WEATHER_API_KEY is not set in environment variables');
}
define('WEATHER_API_KEY', $weather_api_key ?: '');
// TON Wallet address for receiving payments
// Bounceable format (EQ...) - recommended for receiving payments
// User-friendly: UQBahXxgN8ErwSBkCGEyuPEzg3-PdeodtGTbpSzGNjKs6LXQ
// Raw format: 0:5a857c6037c12bc12064086132b8f133837f8f75ea1db464dba52cc63632ace8
// Can be set in .env file as TON_RECIPIENT_ADDRESS
define('TON_RECIPIENT_ADDRESS', getenv('TON_RECIPIENT_ADDRESS') ?: 'EQBahXxgN8ErwSBkCGEyuPEzg3-PdeodtGTbpSzGNjKs6OgV');

// Wallet Pay API Key (for automatic payments in Telegram bot)
// Get your API key from: https://pay.wallet.tg/
// Register as merchant and get your API key
define('WALLET_PAY_API_KEY', getenv('WALLET_PAY_API_KEY') ?: '');

// Timezone
date_default_timezone_set('Europe/Belgrade');

// Custom error log for Telegram bot
$bot_log_file = __DIR__ . '/bot_errors.log';
if (!file_exists($bot_log_file)) {
    // Create log file if it doesn't exist
    @touch($bot_log_file);
    @chmod($bot_log_file, 0666);
}

// Define custom error log function for bot
if (!function_exists('bot_log')) {
    function bot_log($message, $context = []) {
        global $bot_log_file;
        $timestamp = date('Y-m-d H:i:s');
        $context_str = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        $log_message = "[{$timestamp}] {$message}{$context_str}\n";
        @file_put_contents($bot_log_file, $log_message, FILE_APPEND | LOCK_EX);
        // Also log to PHP error log
        error_log($message . $context_str);
    }
}
?>

