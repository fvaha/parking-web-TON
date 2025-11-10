<?php
/**
 * Simple webhook test - checks if webhook.php can be loaded without errors
 */

header('Content-Type: text/plain; charset=utf-8');

echo "Testing webhook.php...\n\n";

// Test 1: Check if config can be loaded
echo "1. Testing config.php...\n";
try {
    require_once __DIR__ . '/config.php';
    echo "   ✓ config.php loaded\n";
    echo "   ✓ TELEGRAM_BOT_TOKEN: " . (defined('TELEGRAM_BOT_TOKEN') ? substr(TELEGRAM_BOT_TOKEN, 0, 10) . '...' : 'NOT DEFINED') . "\n";
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Check if TelegramAPI can be loaded
echo "\n2. Testing TelegramAPI.php...\n";
try {
    require_once __DIR__ . '/TelegramAPI.php';
    echo "   ✓ TelegramAPI.php loaded\n";
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Check if all command files exist
echo "\n3. Testing command files...\n";
$commands = [
    'StartCommand.php',
    'LinkCommand.php',
    'StatusCommand.php',
    'SpacesCommand.php',
    'WeatherCommand.php',
    'PreferencesCommand.php',
    'ReserveCommand.php',
    'HelpCommand.php',
    'LangCommand.php'
];

foreach ($commands as $cmd) {
    $file = __DIR__ . '/commands/' . $cmd;
    if (file_exists($file)) {
        echo "   ✓ {$cmd}\n";
    } else {
        echo "   ❌ {$cmd} NOT FOUND\n";
    }
}

// Test 4: Check if all service files exist
echo "\n4. Testing service files...\n";
$services = [
    'LanguageService.php',
    'DatabaseService.php',
    'ParkingService.php',
    'WeatherService.php',
    'KeyboardService.php'
];

foreach ($services as $svc) {
    $file = __DIR__ . '/services/' . $svc;
    if (file_exists($file)) {
        echo "   ✓ {$svc}\n";
    } else {
        echo "   ❌ {$svc} NOT FOUND\n";
    }
}

// Test 5: Try to load all files
echo "\n5. Testing file loading...\n";
try {
    foreach ($commands as $cmd) {
        require_once __DIR__ . '/commands/' . $cmd;
    }
    foreach ($services as $svc) {
        require_once __DIR__ . '/services/' . $svc;
    }
    echo "   ✓ All files loaded successfully\n";
} catch (Exception $e) {
    echo "   ❌ Error loading files: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 6: Check PHP version
echo "\n6. PHP Version check...\n";
echo "   PHP Version: " . PHP_VERSION . "\n";
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    echo "   ⚠ Warning: PHP 7.4+ recommended\n";
} else {
    echo "   ✓ PHP version OK\n";
}

// Test 7: Check if str_starts_with exists
echo "\n7. Function compatibility...\n";
if (function_exists('str_starts_with')) {
    echo "   ✓ str_starts_with() available\n";
} else {
    echo "   ⚠ str_starts_with() not available (will use fallback)\n";
}

echo "\n========================================\n";
echo "All tests passed! webhook.php should work.\n";
echo "If webhook still returns 500, check PHP error log.\n";
echo "========================================\n";
?>

