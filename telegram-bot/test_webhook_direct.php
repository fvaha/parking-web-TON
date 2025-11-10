<?php
/**
 * Direct test of webhook.php to see exact error
 * This simulates a Telegram webhook request
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>Direct Webhook Test</h1>";
echo "<pre>";

// Simulate a Telegram webhook update
$_POST = [];
$_GET = [];

// Create a test update JSON
$test_update = [
    'update_id' => 123456789,
    'message' => [
        'message_id' => 1,
        'from' => [
            'id' => 123456789,
            'is_bot' => false,
            'first_name' => 'Test',
            'username' => 'testuser',
            'language_code' => 'en'
        ],
        'chat' => [
            'id' => 123456789,
            'type' => 'private'
        ],
        'date' => time(),
        'text' => '/start'
    ]
];

// Simulate input stream
$input_json = json_encode($test_update);
file_put_contents('php://memory', $input_json);

echo "Testing webhook.php with simulated update...\n\n";
echo "Test update JSON:\n";
echo json_encode($test_update, JSON_PRETTY_PRINT);
echo "\n\n";

// Try to include webhook.php
try {
    // Capture output
    ob_start();
    
    // Simulate the input
    $GLOBALS['test_input'] = $input_json;
    
    // Override file_get_contents for php://input
    // We'll use a different approach - directly test the classes
    
    echo "Testing class loading...\n";
    
    require_once __DIR__ . '/config.php';
    echo "✓ Config loaded\n";
    
    require_once __DIR__ . '/TelegramAPI.php';
    echo "✓ TelegramAPI loaded\n";
    
    require_once __DIR__ . '/commands/StartCommand.php';
    echo "✓ StartCommand loaded\n";
    
    require_once __DIR__ . '/commands/LinkCommand.php';
    echo "✓ LinkCommand loaded\n";
    
    require_once __DIR__ . '/commands/StatusCommand.php';
    echo "✓ StatusCommand loaded\n";
    
    require_once __DIR__ . '/commands/SpacesCommand.php';
    echo "✓ SpacesCommand loaded\n";
    
    require_once __DIR__ . '/commands/WeatherCommand.php';
    echo "✓ WeatherCommand loaded\n";
    
    require_once __DIR__ . '/commands/PreferencesCommand.php';
    echo "✓ PreferencesCommand loaded\n";
    
    require_once __DIR__ . '/commands/ReserveCommand.php';
    echo "✓ ReserveCommand loaded\n";
    
    require_once __DIR__ . '/commands/HelpCommand.php';
    echo "✓ HelpCommand loaded\n";
    
    require_once __DIR__ . '/commands/LangCommand.php';
    echo "✓ LangCommand loaded\n";
    
    require_once __DIR__ . '/services/LanguageService.php';
    echo "✓ LanguageService loaded\n";
    
    require_once __DIR__ . '/services/DatabaseService.php';
    echo "✓ DatabaseService loaded\n";
    
    require_once __DIR__ . '/services/ParkingService.php';
    echo "✓ ParkingService loaded\n";
    
    require_once __DIR__ . '/services/WeatherService.php';
    echo "✓ WeatherService loaded\n";
    
    echo "\n✓ All classes loaded successfully!\n\n";
    
    // Test TelegramAPI
    echo "Testing TelegramAPI instantiation...\n";
    $telegram = new TelegramAPI(TELEGRAM_BOT_TOKEN);
    echo "✓ TelegramAPI instantiated\n\n";
    
    // Test getWebhookUpdate with simulated input
    echo "Testing getWebhookUpdate...\n";
    // We need to mock this since we can't override php://input easily
    echo "Note: Full webhook test requires actual HTTP request\n\n";
    
    // Test command instantiation
    echo "Testing command instantiation...\n";
    $start_cmd = new \TelegramBot\Commands\StartCommand();
    echo "✓ StartCommand instantiated\n";
    
    $link_cmd = new \TelegramBot\Commands\LinkCommand();
    echo "✓ LinkCommand instantiated\n";
    
    $reserve_cmd = new \TelegramBot\Commands\ReserveCommand();
    echo "✓ ReserveCommand instantiated\n\n";
    
    echo "✓ All tests passed!\n";
    echo "\nIf webhook still returns 500, check:\n";
    echo "1. PHP error logs on server\n";
    echo "2. Apache/Nginx error logs\n";
    echo "3. File permissions\n";
    echo "4. Database file exists and is writable\n";
    
    $output = ob_get_clean();
    echo $output;
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>

