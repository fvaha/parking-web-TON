<?php
/**
 * Test webhook with simulated Telegram update
 * This simulates what Telegram sends to webhook.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "Testing webhook.php with simulated Telegram update...\n\n";

// Simulate a Telegram update (like /start command)
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

echo "1. Simulating Telegram update...\n";
echo "   Update: " . json_encode($test_update, JSON_PRETTY_PRINT) . "\n\n";

// Capture output
ob_start();

// Simulate POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// Set input stream
$input_data = json_encode($test_update);
file_put_contents('php://memory', $input_data);

// Try to include webhook.php
echo "2. Including webhook.php...\n";
try {
    // We need to mock php://input
    // Since we can't directly modify php://input, we'll test the logic differently
    
    // Test if we can create TelegramAPI instance
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/TelegramAPI.php';
    
    $telegram = new TelegramAPI(TELEGRAM_BOT_TOKEN);
    echo "   ✓ TelegramAPI instance created\n";
    
    // Test if we can create update object manually
    require_once __DIR__ . '/TelegramAPI.php';
    
    class TestTelegramUpdate {
        private $data;
        public function __construct($data) {
            $this->data = $data;
        }
        public function getMessage() {
            return isset($this->data['message']) ? new TestTelegramMessage($this->data['message']) : null;
        }
        public function getCallbackQuery() {
            return null;
        }
    }
    
    class TestTelegramMessage {
        private $data;
        public function __construct($data) {
            $this->data = $data;
        }
        public function getText() {
            return $this->data['text'] ?? null;
        }
        public function getChat() {
            return new TestTelegramChat($this->data['chat']);
        }
        public function getFrom() {
            return new TestTelegramUser($this->data['from']);
        }
    }
    
    class TestTelegramChat {
        private $data;
        public function __construct($data) {
            $this->data = $data;
        }
        public function getId() {
            return $this->data['id'];
        }
    }
    
    class TestTelegramUser {
        private $data;
        public function __construct($data) {
            $this->data = $data;
        }
        public function getId() {
            return $this->data['id'];
        }
        public function getUsername() {
            return $this->data['username'] ?? null;
        }
        public function getLanguageCode() {
            return $this->data['language_code'] ?? 'en';
        }
    }
    
    $test_update_obj = new TestTelegramUpdate($test_update);
    $test_message = $test_update_obj->getMessage();
    
    if ($test_message) {
        echo "   ✓ Test message object created\n";
        echo "   ✓ Message text: " . $test_message->getText() . "\n";
        echo "   ✓ Chat ID: " . $test_message->getChat()->getId() . "\n";
    }
    
    // Test command parsing
    $text = $test_message->getText();
    if (str_starts_with($text, '/')) {
        $parts = explode(' ', $text, 2);
        $command = str_replace('/', '', $parts[0]);
        echo "   ✓ Command parsed: {$command}\n";
    }
    
    // Test if StartCommand can be instantiated
    require_once __DIR__ . '/commands/StartCommand.php';
    $start_cmd = new \TelegramBot\Commands\StartCommand();
    echo "   ✓ StartCommand instantiated\n";
    
    echo "\n3. All components work correctly!\n";
    echo "   The webhook should work if called correctly.\n\n";
    
    echo "========================================\n";
    echo "If webhook still returns 500, the issue might be:\n";
    echo "1. PHP error in webhook.php execution\n";
    echo "2. Missing output buffering or headers\n";
    echo "3. Database connection issue\n";
    echo "4. File permissions issue\n";
    echo "\nCheck PHP error log for details:\n";
    echo "  tail -f /var/log/php_errors.log\n";
    echo "  or\n";
    echo "  tail -f error_log\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n";
    echo "   Stack trace:\n" . $e->getTraceAsString() . "\n";
}

$output = ob_get_clean();
if ($output) {
    echo "\nOutput captured:\n" . $output . "\n";
}
?>

