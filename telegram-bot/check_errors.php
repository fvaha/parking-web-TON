<?php
/**
 * Quick error check for webhook
 * Access: https://parkiraj.info/telegram-bot/check_errors.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Webhook Error Check</h1>";
echo "<pre>";

$errors = [];

// Check .env file first
echo "1. Checking .env file...\n";
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    echo "   ✓ .env file exists at: {$env_file}\n";
    $env_content = file_get_contents($env_file);
    if (strpos($env_content, 'TELEGRAM_BOT_TOKEN') !== false) {
        echo "   ✓ TELEGRAM_BOT_TOKEN found in .env\n";
        // Try to extract token value
        if (preg_match('/TELEGRAM_BOT_TOKEN\s*=\s*([^\s\n]+)/', $env_content, $matches)) {
            $token_value = trim($matches[1], '"\'');
            if (!empty($token_value) && $token_value !== 'YOUR_TELEGRAM_BOT_TOKEN_HERE' && $token_value !== 'YOUR_BOT_TOKEN_HERE') {
                echo "   ✓ Token value is set (not placeholder)\n";
            } else {
                $errors[] = "TELEGRAM_BOT_TOKEN is set to placeholder value in .env";
                echo "   ❌ TELEGRAM_BOT_TOKEN is set to placeholder value\n";
            }
        }
    } else {
        $errors[] = "TELEGRAM_BOT_TOKEN not found in .env file";
        echo "   ❌ TELEGRAM_BOT_TOKEN not found in .env file\n";
    }
} else {
    $errors[] = ".env file not found at: {$env_file}";
    echo "   ❌ .env file not found at: {$env_file}\n";
    echo "   Please create .env file in project root with TELEGRAM_BOT_TOKEN\n";
}

// Check config
echo "\n2. Checking config.php...\n";
try {
    require_once __DIR__ . '/config.php';
    if (defined('TELEGRAM_BOT_TOKEN')) {
        $token = TELEGRAM_BOT_TOKEN;
        if (!empty($token) && $token !== 'YOUR_BOT_TOKEN_HERE') {
            echo "   ✓ Config loaded, token: " . substr($token, 0, 10) . "...\n";
            
            // Test if token is valid
            echo "   Testing token validity...\n";
            $test_url = "https://api.telegram.org/bot{$token}/getMe";
            $test_response = @file_get_contents($test_url);
            if ($test_response) {
                $test_data = json_decode($test_response, true);
                if ($test_data['ok']) {
                    echo "   ✓ Token is valid - Bot: @{$test_data['result']['username']}\n";
                } else {
                    $errors[] = "Telegram bot token is invalid: " . ($test_data['description'] ?? 'Unknown error');
                    echo "   ❌ Token is invalid: " . ($test_data['description'] ?? 'Unknown error') . "\n";
                }
            } else {
                echo "   ⚠ Could not test token (network issue?)\n";
            }
        } else {
            $errors[] = "TELEGRAM_BOT_TOKEN is empty or placeholder";
            echo "   ❌ TELEGRAM_BOT_TOKEN is empty or placeholder\n";
        }
    } else {
        $errors[] = "TELEGRAM_BOT_TOKEN not defined";
        echo "   ❌ TELEGRAM_BOT_TOKEN not defined\n";
    }
} catch (Exception $e) {
    $errors[] = "Config error: " . $e->getMessage();
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
}

// Check webhook status
if (defined('TELEGRAM_BOT_TOKEN') && !empty(TELEGRAM_BOT_TOKEN)) {
    echo "\n3. Checking webhook status...\n";
    $webhook_url = defined('TELEGRAM_WEBHOOK_URL') ? TELEGRAM_WEBHOOK_URL : 'https://parkiraj.info/telegram-bot/webhook.php';
    $webhook_info_url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/getWebhookInfo";
    $webhook_info = @file_get_contents($webhook_info_url);
    
    if ($webhook_info) {
        $webhook_data = json_decode($webhook_info, true);
        if ($webhook_data['ok']) {
            $info = $webhook_data['result'];
            echo "   Current webhook URL: " . ($info['url'] ?: 'Not set') . "\n";
            echo "   Expected URL: {$webhook_url}\n";
            
            if ($info['url'] === $webhook_url) {
                echo "   ✓ Webhook URL is correct\n";
            } else {
                $errors[] = "Webhook URL mismatch. Current: {$info['url']}, Expected: {$webhook_url}";
                echo "   ❌ Webhook URL mismatch!\n";
                echo "   Run setup_webhook.php to fix this\n";
            }
            
            echo "   Pending updates: {$info['pending_update_count']}\n";
            if (isset($info['last_error_date']) && $info['last_error_date']) {
                $error_message = isset($info['last_error_message']) ? $info['last_error_message'] : 'Unknown error';
                $errors[] = "Webhook error: {$error_message} (at " . date('Y-m-d H:i:s', $info['last_error_date']) . ")";
                echo "   ❌ Last error: {$error_message}\n";
                echo "   Error date: " . date('Y-m-d H:i:s', $info['last_error_date']) . "\n";
            } else {
                echo "   ✓ No webhook errors\n";
            }
        } else {
            echo "   ⚠ Could not get webhook info\n";
        }
    } else {
        echo "   ⚠ Could not connect to Telegram API to check webhook\n";
    }
}

// Check TelegramAPI
echo "\n4. Checking TelegramAPI.php...\n";
try {
    require_once __DIR__ . '/TelegramAPI.php';
    if (class_exists('TelegramAPI')) {
        echo "   ✓ TelegramAPI class exists\n";
    } else {
        $errors[] = "TelegramAPI class not found";
        echo "   ❌ TelegramAPI class not found\n";
    }
} catch (Exception $e) {
    $errors[] = "TelegramAPI error: " . $e->getMessage();
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}

// Check commands
echo "\n5. Checking commands...\n";
$commands = [
    'StartCommand.php',
    'LinkCommand.php',
    'ReserveCommand.php',
    'StatusCommand.php',
    'SpacesCommand.php',
    'WeatherCommand.php',
    'PreferencesCommand.php',
    'HelpCommand.php',
    'LangCommand.php'
];

foreach ($commands as $cmd) {
    $path = __DIR__ . '/commands/' . $cmd;
    if (file_exists($path)) {
        try {
            require_once $path;
            $class_name = str_replace('.php', '', $cmd);
            if (class_exists("\\TelegramBot\\Commands\\{$class_name}")) {
                echo "   ✓ {$cmd}\n";
            } else {
                $errors[] = "Class {$class_name} not found in {$cmd}";
                echo "   ❌ {$cmd} - class not found\n";
            }
        } catch (Exception $e) {
            $errors[] = "Error loading {$cmd}: " . $e->getMessage();
            echo "   ❌ {$cmd} - " . $e->getMessage() . "\n";
        }
    } else {
        $errors[] = "File not found: {$cmd}";
        echo "   ❌ {$cmd} - file not found\n";
    }
}

// Check services
echo "\n6. Checking services...\n";
$services = [
    'LanguageService.php',
    'DatabaseService.php',
    'ParkingService.php',
    'WeatherService.php'
];

foreach ($services as $svc) {
    $path = __DIR__ . '/services/' . $svc;
    if (file_exists($path)) {
        try {
            require_once $path;
            $class_name = str_replace('.php', '', $svc);
            if (class_exists("\\TelegramBot\\Services\\{$class_name}")) {
                echo "   ✓ {$svc}\n";
            } else {
                $errors[] = "Class {$class_name} not found in {$svc}";
                echo "   ❌ {$svc} - class not found\n";
            }
        } catch (Exception $e) {
            $errors[] = "Error loading {$svc}: " . $e->getMessage();
            echo "   ❌ {$svc} - " . $e->getMessage() . "\n";
        }
    } else {
        $errors[] = "File not found: {$svc}";
        echo "   ❌ {$svc} - file not found\n";
    }
}

// Check database
echo "\n7. Checking database...\n";
try {
    $db_paths = [];
    
    // First, try absolute path (most reliable for this server)
    $db_paths[] = '/home/parkiraj/public_html/config/database.php';
    
    // Second, try using DOCUMENT_ROOT
    if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $db_paths[] = $doc_root . '/config/database.php';
    }
    
    // Then try relative paths
    $db_paths[] = __DIR__ . '/../../config/database.php';
    $db_paths[] = dirname(__DIR__, 2) . '/config/database.php';
    $db_paths[] = __DIR__ . '/../../../config/database.php';
    
    // Try from script location
    if (isset($_SERVER['SCRIPT_FILENAME'])) {
        $script_dir = dirname($_SERVER['SCRIPT_FILENAME']);
        $db_paths[] = $script_dir . '/../config/database.php';
        $db_paths[] = dirname($script_dir) . '/config/database.php';
    }
    
    echo "   Searching for database.php...\n";
    echo "   Current directory: " . __DIR__ . "\n";
    echo "   Document root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "\n";
    echo "   Script filename: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'not set') . "\n\n";
    
    $db_found = false;
    $found_path = null;
    foreach ($db_paths as $path) {
        $real_path = realpath($path);
        if ($real_path && file_exists($real_path)) {
            require_once $real_path;
            $db_found = true;
            $found_path = $real_path;
            echo "   ✓ Database.php found at: {$real_path}\n";
            break;
        } elseif (file_exists($path)) {
            require_once $path;
            $db_found = true;
            $found_path = $path;
            echo "   ✓ Database.php found at: {$path}\n";
            break;
        } else {
            echo "   - Not found: {$path}\n";
        }
    }
    
    if (!$db_found) {
        // Try searching from config.php location
        $config_paths = [
            __DIR__ . '/../config.php',
            dirname(__DIR__) . '/config.php',
        ];
        
        foreach ($config_paths as $config_path) {
            if (file_exists($config_path)) {
                $config_dir = dirname($config_path);
                $db_file = $config_dir . '/../config/database.php';
                echo "   - Trying: {$db_file}\n";
                if (file_exists($db_file)) {
                    require_once $db_file;
                    $db_found = true;
                    $found_path = $db_file;
                    echo "   ✓ Database.php found at: {$db_file}\n";
                    break;
                }
            }
        }
    }
    
    if (!$db_found) {
        $errors[] = "Database.php not found. Checked multiple paths.";
        echo "   ❌ Database.php not found in any checked location\n";
        echo "   Please check that config/database.php exists relative to project root\n";
    } else {
        if (class_exists('Database')) {
            echo "   ✓ Database class exists\n";
        } else {
            $errors[] = "Database class not found";
            echo "   ❌ Database class not found (file loaded but class missing)\n";
        }
    }
} catch (Exception $e) {
    $errors[] = "Database error: " . $e->getMessage();
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    echo "   Stack trace: " . $e->getTraceAsString() . "\n";
}

// Summary
echo "\n" . str_repeat("=", 50) . "\n";
if (empty($errors)) {
    echo "✓ All checks passed! Webhook should work.\n";
} else {
    echo "❌ Found " . count($errors) . " error(s):\n\n";
    foreach ($errors as $i => $error) {
        echo ($i + 1) . ". {$error}\n";
    }
    echo "\nFix these errors and try again.\n";
}

echo "</pre>";
?>

