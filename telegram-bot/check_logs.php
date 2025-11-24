<?php
/**
 * Check Error Logs
 * 
 * This script helps you find and view error logs for the Telegram bot.
 * 
 * Access: https://parkiraj.info/telegram-bot/check_logs.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo "Telegram Bot Error Logs Checker\n";
echo "========================================\n\n";

// Get PHP error log location
$php_error_log = ini_get('error_log');
echo "1. PHP error_log setting:\n";
if ($php_error_log) {
    echo "   Location: {$php_error_log}\n";
    if (file_exists($php_error_log)) {
        $size = filesize($php_error_log);
        echo "   Status: EXISTS (" . number_format($size / 1024, 2) . " KB)\n";
        echo "   Last modified: " . date('Y-m-d H:i:s', filemtime($php_error_log)) . "\n";
    } else {
        echo "   Status: NOT FOUND\n";
    }
} else {
    echo "   Location: Using system default\n";
}

echo "\n";

// Common log locations on shared hosting
$common_log_locations = [
    __DIR__ . '/bot_errors.log', // Custom bot log file
    __DIR__ . '/../error_log',
    __DIR__ . '/../logs/error_log',
    __DIR__ . '/error_log',
    __DIR__ . '/logs/error_log',
    '/home/parkiraj/public_html/error_log',
    '/home/parkiraj/public_html/logs/error_log',
    '/home/parkiraj/public_html/telegram-bot/error_log',
    '/home/parkiraj/public_html/telegram-bot/logs/error_log',
    '/home/parkiraj/public_html/telegram-bot/bot_errors.log',
    $_SERVER['DOCUMENT_ROOT'] . '/error_log',
    $_SERVER['DOCUMENT_ROOT'] . '/logs/error_log',
    $_SERVER['DOCUMENT_ROOT'] . '/telegram-bot/bot_errors.log',
];

echo "2. Checking common log locations:\n";
$found_logs = [];
foreach ($common_log_locations as $log_path) {
    if (file_exists($log_path) && is_readable($log_path)) {
        $size = filesize($log_path);
        $found_logs[] = [
            'path' => $log_path,
            'size' => $size,
            'modified' => filemtime($log_path)
        ];
        echo "   ✓ FOUND: {$log_path}\n";
        echo "     Size: " . number_format($size / 1024, 2) . " KB\n";
        echo "     Last modified: " . date('Y-m-d H:i:s', filemtime($log_path)) . "\n";
    }
}

if (empty($found_logs)) {
    echo "   No log files found in common locations.\n";
}

echo "\n";

// Check server error log
echo "3. Server information:\n";
echo "   Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "\n";
echo "   Script Path: " . __DIR__ . "\n";
echo "   Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "\n";

echo "\n";

// Check custom bot log file
$bot_log_file = __DIR__ . '/bot_errors.log';
if (file_exists($bot_log_file)) {
    $size = filesize($bot_log_file);
    echo "   ✓ BOT LOG: {$bot_log_file}\n";
    echo "     Size: " . number_format($size / 1024, 2) . " KB\n";
    echo "     Last modified: " . date('Y-m-d H:i:s', filemtime($bot_log_file)) . "\n";
    $found_logs[] = [
        'path' => $bot_log_file,
        'size' => $size,
        'modified' => filemtime($bot_log_file)
    ];
}

// Show recent errors if we found a log file
if (!empty($found_logs)) {
    // Prioritize bot_errors.log if it exists
    $bot_log = null;
    foreach ($found_logs as $log) {
        if (strpos($log['path'], 'bot_errors.log') !== false) {
            $bot_log = $log;
            break;
        }
    }
    
    // Use bot log if available, otherwise use largest log file
    if ($bot_log) {
        $log_file = $bot_log['path'];
    } else {
        usort($found_logs, function($a, $b) {
            return $b['size'] - $a['size'];
        });
        $log_file = $found_logs[0]['path'];
    }
    
    echo "\n4. Recent errors from: {$log_file}\n";
    echo "   (Last 100 lines, filtered for bot-related errors)\n";
    echo "   " . str_repeat("-", 70) . "\n";
    
    // Read last 100 lines
    $lines = file($log_file);
    if ($lines) {
        $recent_lines = array_slice($lines, -100);
        $filtered_count = 0;
        foreach ($recent_lines as $line) {
            // Show all lines from bot_errors.log, filter others
            if (strpos($log_file, 'bot_errors.log') !== false) {
                echo $line;
                $filtered_count++;
            } elseif (stripos($line, 'telegram') !== false || 
                stripos($line, 'ReserveCommand') !== false || 
                stripos($line, 'handlePaymentMethodSelection') !== false ||
                stripos($line, 'showTonPaymentInstructions') !== false ||
                stripos($line, 'Zone data') !== false ||
                stripos($line, 'space_id') !== false ||
                stripos($line, 'Payment TON') !== false ||
                stripos($line, 'Payment stars') !== false) {
                echo $line;
                $filtered_count++;
            }
        }
        if ($filtered_count === 0 && strpos($log_file, 'bot_errors.log') === false) {
            echo "   (No bot-related errors found in last 100 lines)\n";
        }
    }
} else {
    echo "4. No log files found to display.\n";
    echo "\n";
    echo "To find your error logs:\n";
    echo "1. Check your hosting control panel (cPanel, Plesk, etc.)\n";
    echo "2. Look for 'Error Logs' or 'Logs' section\n";
    echo "3. Check your hosting provider's documentation\n";
    echo "4. Contact your hosting support\n";
}

echo "\n";
echo "========================================\n";
echo "End of log check\n";
echo "========================================\n";
?>

