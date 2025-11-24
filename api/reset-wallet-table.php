<?php
// Script to reset wallet_connections table to new schema
// Run once: https://parkiraj.info/api/reset-wallet-table.php

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance();
    $db_instance = $db->getDb();
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Reset Wallet Connections</title>";
    echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:50px auto;padding:20px;background:#f5f5f5;}";
    echo ".box{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
    echo ".success{color:#10b981;} .error{color:#ef4444;} .info{color:#3b82f6;}</style>";
    echo "</head><body><div class='box'>";
    
    echo "<h2>🔧 Resetting wallet_connections table...</h2>";
    
    // Drop old wallet_connections table
    echo "<p class='info'>1. Dropping old table...</p>";
    $db_instance->exec("DROP TABLE IF EXISTS wallet_connections");
    echo "<p class='success'>✓ Old table dropped</p>";
    
    // Recreate with new schema
    echo "<p class='info'>2. Creating new table with correct schema...</p>";
    $db_instance->exec("
        CREATE TABLE wallet_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            license_plate TEXT NOT NULL UNIQUE,
            wallet_address TEXT NOT NULL,
            password_hash TEXT,
            device_id TEXT,
            telegram_user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (telegram_user_id) REFERENCES telegram_users (telegram_user_id)
        )
    ");
    echo "<p class='success'>✓ New table created</p>";
    
    // Recreate indexes
    echo "<p class='info'>3. Creating indexes...</p>";
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_license_plate ON wallet_connections(license_plate)");
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_wallet ON wallet_connections(wallet_address)");
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_device ON wallet_connections(device_id)");
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_telegram ON wallet_connections(telegram_user_id)");
    echo "<p class='success'>✓ Indexes created</p>";
    
    echo "<h3 style='color: #10b981;'>✅ wallet_connections table reset successfully!</h3>";
    echo "<p>You can now use the application normally.</p>";
    echo "<p><a href='/' style='display:inline-block;padding:10px 20px;background:#3b82f6;color:white;text-decoration:none;border-radius:4px;'>Go to Home</a></p>";
    
    echo "</div></body></html>";
    
} catch (Exception $e) {
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Error</title>";
    echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:50px auto;padding:20px;background:#f5f5f5;}";
    echo ".box{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}</style>";
    echo "</head><body><div class='box'>";
    echo "<h3 style='color: #ef4444;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
    echo "<p>Please check server error logs for more details.</p>";
    echo "</div></body></html>";
}
?>

