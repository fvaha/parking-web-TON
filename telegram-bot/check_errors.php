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

// Check config
echo "1. Checking config.php...\n";
try {
    require_once __DIR__ . '/config.php';
    if (defined('TELEGRAM_BOT_TOKEN')) {
        echo "   ✓ Config loaded, token: " . substr(TELEGRAM_BOT_TOKEN, 0, 10) . "...\n";
    } else {
        $errors[] = "TELEGRAM_BOT_TOKEN not defined";
        echo "   ❌ TELEGRAM_BOT_TOKEN not defined\n";
    }
} catch (Exception $e) {
    $errors[] = "Config error: " . $e->getMessage();
    echo "   ❌ Error: " . $e->getMessage() . "\n";
}

// Check TelegramAPI
echo "\n2. Checking TelegramAPI.php...\n";
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
echo "\n3. Checking commands...\n";
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
echo "\n4. Checking services...\n";
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
echo "\n5. Checking database...\n";
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

