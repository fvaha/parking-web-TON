<?php
echo "<h1>Initializing Parking Database</h1>";

// Check if SQLite3 extension is available
if (!extension_loaded('sqlite3')) {
    echo "<p style='color: red;'>❌ SQLite3 extension is not loaded!</p>";
    exit();
}

echo "<p style='color: green;'>✅ SQLite3 extension is loaded</p>";

try {
    // Include the database class
    require_once 'config/database.php';
    
    // Create database instance (this will create tables and initial data)
    $db = new Database();
    echo "<p style='color: green;'>✅ Database initialized successfully!</p>";
    
    // Test getting sensors
    $sensors = $db->getSensors();
    echo "<p style='color: green;'>✅ Retrieved " . count($sensors) . " sensors</p>";
    
    // Test getting parking spaces
    $parking_spaces = $db->getParkingSpaces();
    echo "<p style='color: green;'>✅ Retrieved " . count($parking_spaces) . " parking spaces</p>";
    
    // Show database file info
    $dbPath = __DIR__ . '/database/parking.db';
    if (file_exists($dbPath)) {
        $size = filesize($dbPath);
        echo "<p style='color: green;'>✅ Database file created: " . $dbPath . " (Size: " . number_format($size) . " bytes)</p>";
    }
    
    echo "<h3>Database Ready!</h3>";
    echo "<p>You can now:</p>";
    echo "<ul>";
    echo "<li>Test the API endpoints</li>";
    echo "<li>Connect your frontend</li>";
    echo "<li>Manage sensors through the admin panel</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
