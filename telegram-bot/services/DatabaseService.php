<?php
namespace TelegramBot\Services;

// Try multiple paths for database.php
// Structure: telegram-bot/services/DatabaseService.php -> need to find config/database.php
// Server structure: /home/parkiraj/public_html/
$db_paths = [];

// First, try absolute path (most reliable for this server)
$db_paths[] = '/home/parkiraj/public_html/config/database.php';

// Second, try using DOCUMENT_ROOT (most reliable on servers)
if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $db_paths[] = $doc_root . '/config/database.php';
}

// Then try relative paths from current file location
$db_paths[] = __DIR__ . '/../../config/database.php';  // From services/ -> ../ -> ../ -> config/
$db_paths[] = dirname(__DIR__, 2) . '/config/database.php'; // Using dirname
$db_paths[] = __DIR__ . '/../../../config/database.php'; // Alternative

// Try from script location
if (isset($_SERVER['SCRIPT_FILENAME'])) {
    $script_dir = dirname($_SERVER['SCRIPT_FILENAME']);
    $db_paths[] = $script_dir . '/../config/database.php';
    $db_paths[] = dirname($script_dir) . '/config/database.php';
}

// Try from config.php location (if it exists)
$config_paths = [
    __DIR__ . '/../config.php',
    dirname(__DIR__) . '/config.php',
];

$db_loaded = false;
$tried_paths = [];
foreach ($db_paths as $path) {
    $tried_paths[] = $path;
    // Normalize path
    $real_path = realpath($path);
    if ($real_path && file_exists($real_path)) {
        require_once $real_path;
        $db_loaded = true;
        break;
    }
    // Also try without realpath in case of symlinks
    if (file_exists($path)) {
        require_once $path;
        $db_loaded = true;
        break;
    }
}

if (!$db_loaded) {
    // Try to find it by searching common locations
    $search_paths = [
        dirname(__DIR__) . '/../config/database.php',
        dirname(__DIR__) . '/../../config/database.php',
    ];
    
    foreach ($search_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $db_loaded = true;
            break;
        }
    }
}

if (!$db_loaded) {
    // Last resort: try to find config.php and use its location
    $config_paths = [
        __DIR__ . '/../config.php',
        dirname(__DIR__) . '/config.php',
    ];
    
    foreach ($config_paths as $config_path) {
        if (file_exists($config_path)) {
            $config_dir = dirname($config_path);
            $db_file = $config_dir . '/../config/database.php';
            if (file_exists($db_file)) {
                require_once $db_file;
                $db_loaded = true;
                break;
            }
        }
    }
}

if (!$db_loaded) {
    error_log('Database.php not found. Tried paths: ' . implode(', ', $tried_paths));
    throw new \Exception('Database.php not found. Please check file structure.');
}

class DatabaseService {
    private $db;
    
    public function __construct() {
        // Database class should be in global namespace
        if (!class_exists('Database')) {
            throw new \Exception('Database class not found. Make sure config/database.php is loaded.');
        }
        $this->db = new \Database();
    }
    
    public function getDatabase() {
        return $this->db;
    }
    
    public function getAllTelegramUsers() {
        $result = $this->db->query("
            SELECT * FROM telegram_users WHERE is_active = 1
        ");
        
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        
        return $users;
    }
}

