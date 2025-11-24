<?php
// Script to reset wallet_connections table to new schema
// Run once: https://parkiraj.info/config/reset_wallet_connections.php

require_once __DIR__ . '/database.php';

try {
    $db = Database::getInstance();
    $db_instance = $db->getDb();
    
    echo "<h2>Resetting wallet_connections table...</h2>";
    
    // Drop old wallet_connections table
    echo "<p>Dropping old table...</p>";
    $db_instance->exec("DROP TABLE IF EXISTS wallet_connections");
    
    // Recreate with new schema
    echo "<p>Creating new table with correct schema...</p>";
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
    
    // Recreate indexes
    echo "<p>Creating indexes...</p>";
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_license_plate ON wallet_connections(license_plate)");
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_wallet ON wallet_connections(wallet_address)");
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_device ON wallet_connections(device_id)");
    $db_instance->exec("CREATE INDEX IF NOT EXISTS idx_wallet_connections_telegram ON wallet_connections(telegram_user_id)");
    
    echo "<h3 style='color: green;'>✅ wallet_connections table reset successfully!</h3>";
    echo "<p>You can now use the application normally.</p>";
    echo "<p><a href='/'>Go to Home</a></p>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
}
?>

