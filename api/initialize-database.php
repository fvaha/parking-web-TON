<?php
// Script to initialize complete database from scratch
// Run once: https://parkiraj.info/api/initialize-database.php

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

try {
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Initialize Database</title>";
    echo "<style>body{font-family:Arial,sans-serif;max-width:900px;margin:50px auto;padding:20px;background:#f5f5f5;}";
    echo ".box{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);margin-bottom:20px;}";
    echo ".success{color:#10b981;} .error{color:#ef4444;} .info{color:#3b82f6;} pre{background:#f3f4f6;padding:10px;border-radius:4px;overflow-x:auto;}</style>";
    echo "</head><body>";
    
    echo "<div class='box'>";
    echo "<h1>🔧 Database Initialization</h1>";
    echo "<p class='info'>Creating complete database with all tables...</p>";
    echo "</div>";
    
    // Initialize database - this will create all tables
    echo "<div class='box'>";
    echo "<h3>Step 1: Initializing Database Instance</h3>";
    $db = Database::getInstance();
    echo "<p class='success'>✓ Database instance created</p>";
    echo "</div>";
    
    // Verify all tables were created
    echo "<div class='box'>";
    echo "<h3>Step 2: Verifying Tables</h3>";
    
    $db_instance = $db->getDb();
    $tables_query = $db_instance->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    
    $tables = [];
    while ($row = $tables_query->fetchArray(SQLITE3_ASSOC)) {
        $tables[] = $row['name'];
    }
    
    echo "<p class='info'>Found " . count($tables) . " tables:</p>";
    echo "<pre>";
    foreach ($tables as $table) {
        echo "✓ " . htmlspecialchars($table) . "\n";
    }
    echo "</pre>";
    echo "</div>";
    
    // Show database file info
    echo "<div class='box'>";
    echo "<h3>Step 3: Database File Information</h3>";
    
    $db_path = __DIR__ . '/../database/parking.db';
    if (file_exists($db_path)) {
        $size = filesize($db_path);
        $size_kb = round($size / 1024, 2);
        echo "<p class='success'>✓ Database file exists</p>";
        echo "<p><strong>Path:</strong> " . htmlspecialchars($db_path) . "</p>";
        echo "<p><strong>Size:</strong> " . $size_kb . " KB</p>";
        echo "<p><strong>Readable:</strong> " . (is_readable($db_path) ? "✓ Yes" : "✗ No") . "</p>";
        echo "<p><strong>Writable:</strong> " . (is_writable($db_path) ? "✓ Yes" : "✗ No") . "</p>";
    } else {
        echo "<p class='error'>✗ Database file not found!</p>";
    }
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<h2 style='color: #10b981;'>✅ Database initialized successfully!</h2>";
    echo "<p>All tables have been created. You can now use the application.</p>";
    echo "<p><a href='/' style='display:inline-block;padding:10px 20px;background:#3b82f6;color:white;text-decoration:none;border-radius:4px;margin-right:10px;'>Go to Home</a>";
    echo "<a href='/api/data.php' style='display:inline-block;padding:10px 20px;background:#10b981;color:white;text-decoration:none;border-radius:4px;'>Test API</a></p>";
    echo "</div>";
    
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Error</title>";
    echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:50px auto;padding:20px;background:#f5f5f5;}";
    echo ".box{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}</style>";
    echo "</head><body><div class='box'>";
    echo "<h3 style='color: #ef4444;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
    echo "<p><strong>Stack trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "<p>Please check:</p>";
    echo "<ul>";
    echo "<li>Database folder exists and is writable</li>";
    echo "<li>SQLite3 extension is enabled</li>";
    echo "<li>File permissions are correct</li>";
    echo "</ul>";
    echo "</div></body></html>";
}
?>

