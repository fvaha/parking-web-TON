<?php
class Database {
    private $db;
    
    public function __construct() {
        $dbPath = __DIR__ . '/../database/parking.db';
        
        // Ensure database directory exists
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            if (!mkdir($dbDir, 0755, true)) {
                throw new Exception("Failed to create database directory: {$dbDir}");
            }
        }
        
        try {
            $this->db = new SQLite3($dbPath);
            $this->db->enableExceptions(true);
            
            // Enable WAL mode for better concurrency (prevents "database is locked" errors)
            $this->db->exec("PRAGMA journal_mode=WAL;");
            $this->db->exec("PRAGMA busy_timeout=15000;"); // Wait up to 15 seconds if locked (increased for high concurrency)
            $this->db->exec("PRAGMA synchronous=NORMAL;"); // Better performance with WAL
            $this->db->exec("PRAGMA cache_size=-64000;"); // 64MB cache for better performance
            $this->db->exec("PRAGMA temp_store=MEMORY;"); // Store temp tables in memory
            $this->db->exec("PRAGMA mmap_size=268435456;"); // 256MB memory-mapped I/O for faster reads
            
            // Create tables if they don't exist
            $this->createTables();
        } catch (Exception $e) {
            error_log("Database initialization error: " . $e->getMessage());
            throw new Exception("Failed to initialize database: " . $e->getMessage());
        }
    }
    
    private function createTables() {
        // Create admin_users table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                role TEXT DEFAULT 'admin' CHECK (role IN ('superadmin', 'admin')),
                is_active BOOLEAN DEFAULT 1,
                last_login DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create admin_logs table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS admin_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                table_name TEXT,
                record_id INTEGER,
                old_values TEXT,
                new_values TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (admin_user_id) REFERENCES admin_users (id)
            )
        ");
        
        // Create sensors table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sensors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                wpsd_id TEXT UNIQUE NOT NULL,
                wdc_id TEXT,
                name TEXT NOT NULL,
                status TEXT DEFAULT 'live',
                latitude REAL NOT NULL,
                longitude REAL NOT NULL,
                street_name TEXT NOT NULL,
                zone_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (zone_id) REFERENCES parking_zones (id)
            )
        ");
        
        // Create parking_spaces table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS parking_spaces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sensor_id INTEGER NOT NULL,
                status TEXT DEFAULT 'vacant',
                license_plate TEXT,
                reservation_time DATETIME,
                occupied_since DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sensor_id) REFERENCES sensors (id)
            )
        ");
        
        // Create parking_usage table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS parking_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                license_plate TEXT NOT NULL,
                parking_space_id INTEGER NOT NULL,
                start_time DATETIME NOT NULL,
                end_time DATETIME,
                duration_minutes INTEGER,
                total_cost REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parking_space_id) REFERENCES parking_spaces (id)
            )
        ");
        
        // Create reservations table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS reservations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                license_plate TEXT NOT NULL,
                parking_space_id INTEGER NOT NULL,
                start_time DATETIME NOT NULL,
                end_time DATETIME NOT NULL,
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parking_space_id) REFERENCES parking_spaces (id)
            )
        ");
        
        // Create parking_zones table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS parking_zones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                description TEXT,
                color TEXT DEFAULT '#3B82F6',
                hourly_rate REAL DEFAULT 2.00,
                daily_rate REAL DEFAULT 20.00,
                is_active BOOLEAN DEFAULT 1,
                is_premium BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create zone_parking_spaces table (many-to-many relationship)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS zone_parking_spaces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zone_id INTEGER NOT NULL,
                parking_space_id INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (zone_id) REFERENCES parking_zones (id) ON DELETE CASCADE,
                FOREIGN KEY (parking_space_id) REFERENCES parking_spaces (id) ON DELETE CASCADE,
                UNIQUE(zone_id, parking_space_id)
            )
        ");
        
        // Create telegram_users table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS telegram_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_user_id INTEGER UNIQUE NOT NULL,
                username TEXT,
                license_plate TEXT NOT NULL,
                chat_id INTEGER NOT NULL,
                language TEXT DEFAULT 'en' CHECK (language IN ('en', 'sr', 'de', 'fr', 'ar')),
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Add language column if it doesn't exist (migration)
        try {
            $this->db->exec("ALTER TABLE telegram_users ADD COLUMN language TEXT DEFAULT 'en' CHECK (language IN ('en', 'sr', 'de', 'fr', 'ar'))");
        } catch (Exception $e) {
            // Column already exists, ignore
        }
        
        // Add ton_wallet_address column if it doesn't exist (migration)
        try {
            $this->db->exec("ALTER TABLE telegram_users ADD COLUMN ton_wallet_address TEXT");
        } catch (Exception $e) {
            // Column already exists, ignore
        }
        
        // Add payment_tx_hash column to parking_spaces if it doesn't exist (migration)
        try {
            $this->db->exec("ALTER TABLE parking_spaces ADD COLUMN payment_tx_hash TEXT");
        } catch (Exception $e) {
            // Column already exists, ignore
        }
        
        // Migrate existing sensor zone_id to zone_parking_spaces table
        // This ensures that parking spaces are properly linked to zones
        try {
            // Get all sensors with zone_id that don't have corresponding zone_parking_spaces entries
            $result = $this->db->query("
                SELECT s.id as sensor_id, s.zone_id, ps.id as parking_space_id
                FROM sensors s
                JOIN parking_spaces ps ON s.id = ps.sensor_id
                WHERE s.zone_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM zone_parking_spaces zps 
                    WHERE zps.parking_space_id = ps.id AND zps.zone_id = s.zone_id
                )
            ");
            
            $migrated_count = 0;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!empty($row['zone_id']) && !empty($row['parking_space_id'])) {
                    $stmt = $this->db->prepare("
                        INSERT OR IGNORE INTO zone_parking_spaces (zone_id, parking_space_id)
                        VALUES (?, ?)
                    ");
                    $stmt->bindValue(1, $row['zone_id']);
                    $stmt->bindValue(2, $row['parking_space_id']);
                    $stmt->execute();
                    $migrated_count++;
                }
            }
            
            if ($migrated_count > 0) {
                error_log("Migrated {$migrated_count} parking space-zone relationships from sensors.zone_id");
            }
        } catch (Exception $e) {
            // Migration failed, but don't break initialization
            error_log("Zone migration warning: " . $e->getMessage());
        }
        
        // Create ton_payments table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ton_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_id INTEGER,
                parking_space_id INTEGER NOT NULL,
                license_plate TEXT NOT NULL,
                tx_hash TEXT UNIQUE NOT NULL,
                amount_nano TEXT NOT NULL,
                amount_ton REAL NOT NULL,
                status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'verified', 'failed')),
                verified_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (reservation_id) REFERENCES reservations (id)
            )
        ");
        
        // Create notification_preferences table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS notification_preferences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_user_id INTEGER NOT NULL,
                license_plate TEXT NOT NULL,
                notify_free_spaces BOOLEAN DEFAULT 1,
                notify_specific_space INTEGER,
                notify_street TEXT,
                notify_zone INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (telegram_user_id) REFERENCES telegram_users (telegram_user_id)
            )
        ");
        
        // Create notification_queue table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS notification_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_user_id INTEGER NOT NULL,
                type TEXT NOT NULL CHECK (type IN ('reservation_ending', 'space_available', 'reservation_ended')),
                message TEXT NOT NULL,
                reservation_id INTEGER,
                parking_space_id INTEGER,
                scheduled_at DATETIME NOT NULL,
                sent_at DATETIME,
                status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed')),
                FOREIGN KEY (telegram_user_id) REFERENCES telegram_users (telegram_user_id)
            )
        ");
        
        // Initialize with default data if tables are empty
        $this->initializeDefaultData();
        
        // Migrate existing tables if needed
        $this->migrateTables();
    }
    
    private function initializeDefaultData() {
        // Initialize admin users if table is empty
        $result = $this->db->query("SELECT COUNT(*) as count FROM admin_users");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($row['count'] == 0) {
            // Create superadmin user (password: superadmin123)
            $superadmin_hash = password_hash('superadmin123', PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("
                INSERT INTO admin_users (username, password_hash, email, role)
                VALUES (?, ?, ?, 'superadmin')
            ");
            $stmt->bindValue(1, 'superadmin');
            $stmt->bindValue(2, $superadmin_hash);
            $stmt->bindValue(3, 'superadmin@parking.com');
            $stmt->execute();
            
            // Create default admin user (password: admin123)
            $admin_hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt2 = $this->db->prepare("
                INSERT INTO admin_users (username, password_hash, email, role)
                VALUES (?, ?, ?, 'admin')
            ");
            $stmt2->bindValue(1, 'admin');
            $stmt2->bindValue(2, $admin_hash);
            $stmt2->bindValue(3, 'admin@parking.com');
            $stmt2->execute();
        }
        
        // Initialize sensors if table is empty
        $result = $this->db->query("SELECT COUNT(*) as count FROM sensors");
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($row['count'] == 0) {
            $sensors = [
                ['81CAE175', '81CAE530', 'Parking Space 1', 43.1422626446047, 20.5180587785345, 'Ulica Avnoja'],
                ['81CAE075', '81CAE530', 'Parking Space 2', 43.14226117678309, 20.5181459503221, 'Ulica Avnoja'],
                ['81CAE276', '81CAE531', 'Parking Space 3', 43.1380000000000, 20.5150000000000, 'Ulica Kralja Milutina'],
                ['81CAE377', '81CAE532', 'Parking Space 4', 43.1400000000000, 20.5200000000000, 'Ulica Kralja Milutina'],
                ['81CAE478', '81CAE533', 'Parking Space 5', 43.1360000000000, 20.5100000000000, 'Ulica Nemanjina'],
                ['81CAE579', '81CAE534', 'Parking Space 6', 43.1440000000000, 20.5250000000000, 'Ulica Nemanjina'],
                ['81CAE680', '81CAE535', 'Parking Space 7', 43.1350000000000, 20.5050000000000, 'Ulica Dusanova'],
                ['81CAE781', '81CAE536', 'Parking Space 8', 43.1450000000000, 20.5300000000000, 'Ulica Dusanova']
            ];
            
            $stmt = $this->db->prepare("
                INSERT INTO sensors (wpsd_id, wdc_id, name, latitude, longitude, street_name, status)
                VALUES (?, ?, ?, ?, ?, ?, 'live')
            ");
            
            foreach ($sensors as $sensor) {
                $stmt->bindValue(1, $sensor[0]);
                $stmt->bindValue(2, $sensor[1]);
                $stmt->bindValue(3, $sensor[2]);
                $stmt->bindValue(4, $sensor[3]);
                $stmt->bindValue(5, $sensor[4]);
                $stmt->bindValue(6, $sensor[5]);
                $stmt->execute();
            }
            
            // Create parking spaces for each sensor
            $this->db->exec("
                INSERT INTO parking_spaces (sensor_id, status)
                SELECT id, 'vacant' FROM sensors
            ");
            
            // Create default parking zones
            $zones = [
                ['Downtown Zone', 'High-traffic downtown area with premium pricing', '#EF4444', 3.00, 30.00],
                ['Residential Zone', 'Residential area with standard pricing', '#10B981', 2.00, 20.00],
                ['Commercial Zone', 'Commercial area with moderate pricing', '#F59E0B', 2.50, 25.00]
            ];
            
            $stmt = $this->db->prepare("
                INSERT INTO parking_zones (name, description, color, hourly_rate, daily_rate)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($zones as $zone) {
                $stmt->bindValue(1, $zone[0]);
                $stmt->bindValue(2, $zone[1]);
                $stmt->bindValue(3, $zone[2]);
                $stmt->bindValue(4, $zone[3]);
                $stmt->bindValue(5, $zone[4]);
                $stmt->execute();
            }
            
            // Assign parking spaces to zones (alternating for demo)
            $zone_assignments = [
                [1, 1], [1, 2], [2, 3], [2, 4], [3, 5], [3, 6], [1, 7], [2, 8]
            ];
            
            $stmt = $this->db->prepare("
                INSERT INTO zone_parking_spaces (zone_id, parking_space_id)
                VALUES (?, ?)
            ");
            
            foreach ($zone_assignments as $assignment) {
                $stmt->bindValue(1, $assignment[0]);
                $stmt->bindValue(2, $assignment[1]);
                $stmt->execute();
            }
        }
    }
    
    // Admin user methods
    public function authenticateAdmin($username, $password) {
        $stmt = $this->db->prepare("
            SELECT id, username, password_hash, role, is_active
            FROM admin_users 
            WHERE username = ? AND is_active = 1
        ");
        $stmt->bindValue(1, $username);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Update last login
            $this->updateLastLogin($user['id']);
            return $user;
        }
        
        return false;
    }
    
    public function updateLastLogin($user_id) {
        $stmt = $this->db->prepare("
            UPDATE admin_users 
            SET last_login = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->bindValue(1, $user_id);
        $stmt->execute();
    }
    
    public function getAdminUsers() {
        $result = $this->db->query("
            SELECT id, username, email, role, is_active, last_login, created_at
            FROM admin_users
            ORDER BY created_at DESC
        ");
        
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        
        return $users;
    }
    
    public function addAdminUser($data, $admin_user_id) {
        $stmt = $this->db->prepare("
            INSERT INTO admin_users (username, password_hash, email, role)
            VALUES (?, ?, ?, ?)
        ");
        
        $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt->bindValue(1, $data['username']);
        $stmt->bindValue(2, $password_hash);
        $stmt->bindValue(3, $data['email']);
        $stmt->bindValue(4, $data['role'] ?? 'admin');
        
        $result = $stmt->execute();
        
        if ($result) {
            $new_user_id = $this->db->lastInsertRowID();
            
            // Log the action
            $this->logAdminAction($admin_user_id, 'CREATE', 'admin_users', $new_user_id, null, [
                'username' => $data['username'],
                'email' => $data['email'],
                'role' => $data['role'] ?? 'admin'
            ]);
            
            return ['success' => true, 'user_id' => $new_user_id];
        }
        
        return ['success' => false, 'error' => 'Failed to add admin user'];
    }
    
    public function updateAdminUser($id, $data, $admin_user_id) {
        $updates = [];
        $params = [];
        $param_count = 1;
        
        if (isset($data['email'])) {
            $updates[] = "email = ?";
            $params[] = $data['email'];
            $param_count++;
        }
        
        if (isset($data['role'])) {
            $updates[] = "role = ?";
            $params[] = $data['role'];
            $param_count++;
        }
        
        if (isset($data['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = $data['is_active'] ? 1 : 0;
            $param_count++;
        }
        
        if (isset($data['password'])) {
            $updates[] = "password_hash = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            $param_count++;
        }
        
        if (empty($updates)) {
            return ['success' => false, 'error' => 'No fields to update'];
        }
        
        $updates[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $id;
        
        $sql = "UPDATE admin_users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        foreach ($params as $index => $param) {
            $stmt->bindValue($index + 1, $param);
        }
        
        $result = $stmt->execute();
        
        if ($result && $this->db->changes() > 0) {
            // Log the action
            $this->logAdminAction($admin_user_id, 'UPDATE', 'admin_users', $id, null, $data);
            return ['success' => true, 'message' => 'Admin user updated successfully'];
        }
        
        return ['success' => false, 'error' => 'Admin user not found or update failed'];
    }
    
    public function deleteAdminUser($id, $admin_user_id) {
        // Get user info before deletion for logging
        $stmt = $this->db->prepare("SELECT username, email, role FROM admin_users WHERE id = ?");
        $stmt->bindValue(1, $id);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$user) {
            return ['success' => false, 'error' => 'Admin user not found'];
        }
        
        // Don't allow superadmin to delete themselves
        if ($user['role'] === 'superadmin') {
            return ['success' => false, 'error' => 'Cannot delete superadmin user'];
        }
        
        $stmt2 = $this->db->prepare("DELETE FROM admin_users WHERE id = ?");
        $stmt2->bindValue(1, $id);
        $result = $stmt2->execute();
        
        if ($result && $this->db->changes() > 0) {
            // Log the action
            $this->logAdminAction($admin_user_id, 'DELETE', 'admin_users', $id, $user, null);
            return ['success' => true, 'message' => 'Admin user deleted successfully'];
        }
        
        return ['success' => false, 'error' => 'Failed to delete admin user'];
    }
    
    // Logging methods
    public function logAdminAction($admin_user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null) {
        $stmt = $this->db->prepare("
            INSERT INTO admin_logs (admin_user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bindValue(1, $admin_user_id);
        $stmt->bindValue(2, $action);
        $stmt->bindValue(3, $table_name);
        $stmt->bindValue(4, $record_id);
        $stmt->bindValue(5, $old_values ? json_encode($old_values) : null);
        $stmt->bindValue(6, $new_values ? json_encode($new_values) : null);
        $stmt->bindValue(7, $_SERVER['REMOTE_ADDR'] ?? null);
        $stmt->bindValue(8, $_SERVER['HTTP_USER_AGENT'] ?? null);
        
        $stmt->execute();
    }
    
    public function getAdminLogs($limit = 100, $offset = 0, $filters = []) {
        $where_conditions = [];
        $params = [];
        $param_count = 1;
        
        if (!empty($filters['admin_user_id'])) {
            $where_conditions[] = "al.admin_user_id = ?";
            $params[] = $filters['admin_user_id'];
            $param_count++;
        }
        
        if (!empty($filters['action'])) {
            $where_conditions[] = "al.action = ?";
            $params[] = $filters['action'];
            $param_count++;
        }
        
        if (!empty($filters['table_name'])) {
            $where_conditions[] = "al.table_name = ?";
            $params[] = $filters['table_name'];
            $param_count++;
        }
        
        if (!empty($filters['date_from'])) {
            $where_conditions[] = "al.created_at >= ?";
            $params[] = $filters['date_from'];
            $param_count++;
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = "al.created_at <= ?";
            $params[] = $filters['date_to'];
            $param_count++;
        }
        
        $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
        
        $sql = "
            SELECT 
                al.*,
                au.username as admin_username,
                au.role as admin_role
            FROM admin_logs al
            LEFT JOIN admin_users au ON al.admin_user_id = au.id
            {$where_clause}
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $index => $param) {
            $stmt->bindValue($index + 1, $param);
        }
        
        $result = $stmt->execute();
        
        $logs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $logs[] = $row;
        }
        
        return $logs;
    }
    
    // Migration methods
    private function migrateTables() {
        // Check if zone_id column exists in sensors table
        $result = $this->db->query("PRAGMA table_info(sensors)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        if (!in_array('zone_id', $columns)) {
            // Add zone_id column to sensors table
            $this->db->exec("ALTER TABLE sensors ADD COLUMN zone_id INTEGER");
            
            // Create index for better performance
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_sensors_zone_id ON sensors(zone_id)");
        }
        
        // Check if is_premium column exists in parking_zones table
        $result = $this->db->query("PRAGMA table_info(parking_zones)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        if (!in_array('is_premium', $columns)) {
            // Add is_premium column to parking_zones table
            $this->db->exec("ALTER TABLE parking_zones ADD COLUMN is_premium BOOLEAN DEFAULT 0");
            
            // Set Downtown Zone as premium (zone id 1)
            $this->db->exec("UPDATE parking_zones SET is_premium = 1 WHERE id = 1");
        }
        
        // Check if max_duration_hours column exists in parking_zones table
        $result = $this->db->query("PRAGMA table_info(parking_zones)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        if (!in_array('max_duration_hours', $columns)) {
            // Add max_duration_hours column to parking_zones table
            $this->db->exec("ALTER TABLE parking_zones ADD COLUMN max_duration_hours INTEGER DEFAULT 4");
            
            // Set default max durations for zones
            // Zone 1: 1 hour, Zone 2: 2 hours, Zone 3: 4 hours
            $this->db->exec("UPDATE parking_zones SET max_duration_hours = 1 WHERE id = 1");
            $this->db->exec("UPDATE parking_zones SET max_duration_hours = 2 WHERE id = 2");
            $this->db->exec("UPDATE parking_zones SET max_duration_hours = 4 WHERE id = 3");
        }
        
        // Create indexes for better performance
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_telegram_users_telegram_id ON telegram_users(telegram_user_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_telegram_users_license_plate ON telegram_users(license_plate)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ton_payments_tx_hash ON ton_payments(tx_hash)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_ton_payments_reservation_id ON ton_payments(reservation_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_notification_queue_status ON notification_queue(status)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_notification_queue_scheduled_at ON notification_queue(scheduled_at)");
    }
    
    // Sensor methods with logging
    public function getSensors() {
        try {
            // First check if sensors table has any data
            $count_result = $this->db->query("SELECT COUNT(*) as count FROM sensors");
            $sensor_count = $count_result ? $count_result->fetchArray(SQLITE3_ASSOC)['count'] : 0;
            error_log("getSensors: Total sensors in database: {$sensor_count}");
            
            if ($sensor_count == 0) {
                error_log('getSensors: No sensors found in database');
                return [];
            }
            
            // Check if max_duration_hours column exists in parking_zones
            $has_max_duration_hours = false;
            try {
                $check_result = $this->db->query("PRAGMA table_info(parking_zones)");
                while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                    if ($row['name'] === 'max_duration_hours') {
                        $has_max_duration_hours = true;
                        break;
                    }
                }
            } catch (Exception $e) {
                $has_max_duration_hours = false;
            }
            
            $max_duration_select = $has_max_duration_hours ? 'z.max_duration_hours as zone_max_duration_hours,' : 'NULL as zone_max_duration_hours,';
            
            // First get all sensors with a simple query
            $simple_sql = "
                SELECT 
                    s.id,
                    s.wpsd_id,
                    s.wdc_id,
                    s.name,
                    s.status,
                    s.latitude,
                    s.longitude,
                    s.street_name,
                    s.zone_id,
                    s.created_at,
                    s.updated_at
                FROM sensors s
                ORDER BY s.id
            ";
            
            error_log("getSensors: Executing simple query to get all sensors");
            $result = $this->db->query($simple_sql);
            
            if (!$result) {
                error_log('getSensors: Query failed - ' . $this->db->lastErrorMsg());
                return [];
            }
            
            // Get all zones for later lookup
            $zones_map = [];
            try {
                $zones_result = $this->db->query("
                    SELECT z.id, z.name, z.color, z.hourly_rate, z.daily_rate, z.is_premium, 
                           {$max_duration_select}
                           zps.parking_space_id
                    FROM parking_zones z
                    JOIN zone_parking_spaces zps ON z.id = zps.zone_id
                ");
                if ($zones_result) {
                    while ($zone_row = $zones_result->fetchArray(SQLITE3_ASSOC)) {
                        $parking_space_id = $zone_row['parking_space_id'];
                        if (!isset($zones_map[$parking_space_id])) {
                            $zones_map[$parking_space_id] = [
                                'id' => (string)$zone_row['id'],
                                'name' => $zone_row['name'],
                                'color' => $zone_row['color'],
                                'hourly_rate' => (float)$zone_row['hourly_rate'],
                                'daily_rate' => (float)$zone_row['daily_rate'],
                                'is_premium' => ($zone_row['is_premium'] == 1 || $zone_row['is_premium'] === true || $zone_row['is_premium'] === '1'),
                                'max_duration_hours' => isset($zone_row['zone_max_duration_hours']) ? (int)$zone_row['zone_max_duration_hours'] : null
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('getSensors: Error loading zones: ' . $e->getMessage());
            }
            
            // Get parking space to sensor mapping for zone lookup
            $space_to_sensor_map = [];
            try {
                $space_result = $this->db->query("SELECT id, sensor_id FROM parking_spaces");
                if ($space_result) {
                    while ($space_row = $space_result->fetchArray(SQLITE3_ASSOC)) {
                        $space_to_sensor_map[$space_row['sensor_id']] = $space_row['id'];
                    }
                }
            } catch (Exception $e) {
                error_log('getSensors: Error loading parking spaces: ' . $e->getMessage());
            }
            
            // Process sensors and add zone info
            $sensors = [];
            $row_count = 0;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!$row) {
                    break;
                }
                $row_count++;
                
                // Find zone for this sensor via parking space
                $zone = null;
                $sensor_id = $row['id'];
                if (isset($space_to_sensor_map[$sensor_id])) {
                    $parking_space_id = $space_to_sensor_map[$sensor_id];
                    if (isset($zones_map[$parking_space_id])) {
                        $zone = $zones_map[$parking_space_id];
                    }
                }
                
                $sensors[] = [
                    'id' => $row['id'] ?? null,
                    'wpsd_id' => $row['wpsd_id'] ?? null,
                    'wdc_id' => $row['wdc_id'] ?? null,
                    'name' => $row['name'] ?? null,
                    'status' => $row['status'] ?? 'live',
                    'coordinates' => [
                        'lat' => $row['latitude'] ?? 0.0,
                        'lng' => $row['longitude'] ?? 0.0
                    ],
                    'street_name' => $row['street_name'] ?? null,
                    'zone_id' => $row['zone_id'] ?? null,
                    'zone' => $zone,
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null
                ];
            }
            
            error_log("getSensors: Processed {$row_count} sensor rows, returning " . count($sensors) . " sensors");
            return $sensors;
        } catch (Exception $e) {
            error_log('getSensors error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return [];
        }
    }
    
    // Zone management methods
    public function getParkingZones() {
        // Check if max_duration_hours column exists
        $has_max_duration_hours = false;
        try {
            $check_result = $this->db->query("PRAGMA table_info(parking_zones)");
            while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'max_duration_hours') {
                    $has_max_duration_hours = true;
                    break;
                }
            }
        } catch (Exception $e) {
            $has_max_duration_hours = false;
        }
        
        $max_duration_select = $has_max_duration_hours ? 'z.max_duration_hours,' : 'NULL as max_duration_hours,';
        
        $result = $this->db->query("
            SELECT 
                z.id,
                z.name,
                z.description,
                z.color,
                z.hourly_rate,
                z.daily_rate,
                z.is_active,
                z.is_premium,
                {$max_duration_select}
                z.created_at,
                z.updated_at,
                COUNT(zps.parking_space_id) as space_count
            FROM parking_zones z
            LEFT JOIN zone_parking_spaces zps ON z.id = zps.zone_id
            GROUP BY z.id
            ORDER BY z.name
        ");
        
        $zones = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $zones[] = $row;
        }
        
        return $zones;
    }
    
    public function getParkingZone($id) {
        // Check if max_duration_hours column exists
        $has_max_duration = false;
        try {
            $check_result = $this->db->query("PRAGMA table_info(parking_zones)");
            while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'max_duration_hours') {
                    $has_max_duration = true;
                    break;
                }
            }
        } catch (Exception $e) {
            $has_max_duration = false;
        }
        
        // Use SELECT * which will automatically include all existing columns
        // SQLite will handle missing columns gracefully
        $stmt = $this->db->prepare("
            SELECT * FROM parking_zones WHERE id = ?
        ");
        $stmt->bindValue(1, $id);
        $result = $stmt->execute();
        
        $zone = $result->fetchArray(SQLITE3_ASSOC);
        
        // If max_duration_hours doesn't exist, add it as null
        if ($zone && !$has_max_duration && !isset($zone['max_duration_hours'])) {
            $zone['max_duration_hours'] = null;
        }
        
        return $zone;
    }
    
    public function addParkingZone($data, $admin_user_id) {
        // Check if max_duration_hours column exists
        $has_max_duration = false;
        try {
            $check_result = $this->db->query("PRAGMA table_info(parking_zones)");
            while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'max_duration_hours') {
                    $has_max_duration = true;
                    break;
                }
            }
        } catch (Exception $e) {
            $has_max_duration = false;
        }
        
        if ($has_max_duration) {
            $stmt = $this->db->prepare("
                INSERT INTO parking_zones (name, description, color, hourly_rate, daily_rate, is_premium, max_duration_hours)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bindValue(1, $data['name']);
            $stmt->bindValue(2, $data['description']);
            $stmt->bindValue(3, $data['color']);
            $stmt->bindValue(4, $data['hourly_rate']);
            $stmt->bindValue(5, $data['daily_rate']);
            $stmt->bindValue(6, isset($data['is_premium']) ? ($data['is_premium'] ? 1 : 0) : 0);
            $stmt->bindValue(7, isset($data['max_duration_hours']) ? (int)$data['max_duration_hours'] : 4);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO parking_zones (name, description, color, hourly_rate, daily_rate, is_premium)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bindValue(1, $data['name']);
            $stmt->bindValue(2, $data['description']);
            $stmt->bindValue(3, $data['color']);
            $stmt->bindValue(4, $data['hourly_rate']);
            $stmt->bindValue(5, $data['daily_rate']);
            $stmt->bindValue(6, isset($data['is_premium']) ? ($data['is_premium'] ? 1 : 0) : 0);
        }
        
        if ($stmt->execute()) {
            $zone_id = $this->db->lastInsertRowID();
            $this->logAdminAction($admin_user_id, 'CREATE', 'parking_zones', $zone_id, null, json_encode($data));
            return $zone_id;
        }
        
        return false;
    }
    
    public function updateParkingZone($id, $data, $admin_user_id) {
        $old_data = $this->getParkingZone($id);
        
        // Check if max_duration_hours column exists
        $has_max_duration = false;
        try {
            $check_result = $this->db->query("PRAGMA table_info(parking_zones)");
            while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === 'max_duration_hours') {
                    $has_max_duration = true;
                    break;
                }
            }
        } catch (Exception $e) {
            $has_max_duration = false;
        }
        
        if ($has_max_duration) {
            $stmt = $this->db->prepare("
                UPDATE parking_zones 
                SET name = ?, description = ?, color = ?, hourly_rate = ?, daily_rate = ?, is_premium = ?, max_duration_hours = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->bindValue(1, $data['name']);
            $stmt->bindValue(2, $data['description']);
            $stmt->bindValue(3, $data['color']);
            $stmt->bindValue(4, $data['hourly_rate']);
            $stmt->bindValue(5, $data['daily_rate']);
            $stmt->bindValue(6, isset($data['is_premium']) ? ($data['is_premium'] ? 1 : 0) : 0);
            $stmt->bindValue(7, isset($data['max_duration_hours']) ? (int)$data['max_duration_hours'] : 4);
            $stmt->bindValue(8, $id);
        } else {
            $stmt = $this->db->prepare("
                UPDATE parking_zones 
                SET name = ?, description = ?, color = ?, hourly_rate = ?, daily_rate = ?, is_premium = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->bindValue(1, $data['name']);
            $stmt->bindValue(2, $data['description']);
            $stmt->bindValue(3, $data['color']);
            $stmt->bindValue(4, $data['hourly_rate']);
            $stmt->bindValue(5, $data['daily_rate']);
            $stmt->bindValue(6, isset($data['is_premium']) ? ($data['is_premium'] ? 1 : 0) : 0);
            $stmt->bindValue(7, $id);
        }
        
        if ($stmt->execute()) {
            $this->logAdminAction($admin_user_id, 'UPDATE', 'parking_zones', $id, json_encode($old_data), json_encode($data));
            return true;
        }
        
        return false;
    }
    
    public function deleteParkingZone($id, $admin_user_id) {
        $old_data = $this->getParkingZone($id);
        
        $stmt = $this->db->prepare("
            DELETE FROM parking_zones WHERE id = ?
        ");
        $stmt->bindValue(1, $id);
        
        if ($stmt->execute()) {
            $this->logAdminAction($admin_user_id, 'DELETE', 'parking_zones', $id, json_encode($old_data), null);
            return true;
        }
        
        return false;
    }
    
    public function assignParkingSpaceToZone($zone_id, $parking_space_id, $admin_user_id) {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO zone_parking_spaces (zone_id, parking_space_id)
            VALUES (?, ?)
        ");
        
        $stmt->bindValue(1, $zone_id);
        $stmt->bindValue(2, $parking_space_id);
        
        if ($stmt->execute()) {
            $this->logAdminAction($admin_user_id, 'ASSIGN', 'zone_parking_spaces', $parking_space_id, null, json_encode(['zone_id' => $zone_id]));
            return true;
        }
        
        return false;
    }
    
    public function removeParkingSpaceFromZone($zone_id, $parking_space_id, $admin_user_id) {
        $stmt = $this->db->prepare("
            DELETE FROM zone_parking_spaces 
            WHERE zone_id = ? AND parking_space_id = ?
        ");
        
        $stmt->bindValue(1, $zone_id);
        $stmt->bindValue(2, $parking_space_id);
        
        if ($stmt->execute()) {
            $this->logAdminAction($admin_user_id, 'REMOVE', 'zone_parking_spaces', $parking_space_id, json_encode(['zone_id' => $zone_id]), null);
            return true;
        }
        
        return false;
    }
    
    public function getParkingSpaceZone($parking_space_id) {
        $stmt = $this->db->prepare("
            SELECT z.* FROM parking_zones z
            JOIN zone_parking_spaces zps ON z.id = zps.zone_id
            WHERE zps.parking_space_id = ?
        ");
        $stmt->bindValue(1, $parking_space_id);
        $result = $stmt->execute();
        
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    public function isZonePremium($zone_id) {
        $stmt = $this->db->prepare("
            SELECT is_premium FROM parking_zones WHERE id = ?
        ");
        $stmt->bindValue(1, $zone_id);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row && $row['is_premium'] == 1;
    }
    
    public function getZoneBySpaceId($space_id) {
        return $this->getParkingSpaceZone($space_id);
    }
    
    public function getZoneParkingSpaces($zone_id) {
        $stmt = $this->db->prepare("
            SELECT ps.*, s.name as sensor_name, s.street_name
            FROM parking_spaces ps
            JOIN sensors s ON ps.sensor_id = s.id
            JOIN zone_parking_spaces zps ON ps.id = zps.parking_space_id
            WHERE zps.zone_id = ?
        ");
        $stmt->bindValue(1, $zone_id);
        $result = $stmt->execute();
        
        $spaces = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $spaces[] = $row;
        }
        
        return $spaces;
    }
    
    // Enhanced admin user management methods
    public function changeAdminPassword($id, $new_password, $admin_user_id) {
        $old_data = $this->getAdminUser($id);
        
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("
            UPDATE admin_users 
            SET password_hash = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $stmt->bindValue(1, $password_hash);
        $stmt->bindValue(2, $id);
        
        if ($stmt->execute()) {
            $this->logAdminAction($admin_user_id, 'PASSWORD_CHANGE', 'admin_users', $id, null, json_encode(['password_changed' => true]));
            return true;
        }
        
        return false;
    }
    
    public function getAdminUser($id) {
        $stmt = $this->db->prepare("
            SELECT id, username, email, role, is_active, last_login, created_at, updated_at
            FROM admin_users WHERE id = ?
        ");
        $stmt->bindValue(1, $id);
        $result = $stmt->execute();
        
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    public function isSuperAdmin($user_id) {
        $stmt = $this->db->prepare("
            SELECT role FROM admin_users WHERE id = ?
        ");
        $stmt->bindValue(1, $user_id);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row && $row['role'] === 'superadmin';
    }
    
    public function canCreateSuperAdmin($user_id) {
        // Only superadmins can create other superadmins
        return $this->isSuperAdmin($user_id);
    }
    
    public function addSensor($data, $admin_user_id) {
        $stmt = $this->db->prepare("
            INSERT INTO sensors (name, wpsd_id, wdc_id, street_name, latitude, longitude, zone_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bindValue(1, $data['name']);
        $stmt->bindValue(2, $data['wpsd_id']);
        $stmt->bindValue(3, $data['wdc_id'] ?? null);
        $stmt->bindValue(4, $data['street_name']);
        $stmt->bindValue(5, $data['latitude']);
        $stmt->bindValue(6, $data['longitude']);
        $stmt->bindValue(7, $data['zone_id'] ?? null);
        
        $result = $stmt->execute();
        
        if ($result) {
            $sensorId = $this->db->lastInsertRowID();
            
            // Create associated parking space
            $stmt2 = $this->db->prepare("
                INSERT INTO parking_spaces (sensor_id, status)
                VALUES (?, 'vacant')
            ");
            $stmt2->bindValue(1, $sensorId);
            $stmt2->execute();
            
            // Log the action
            $this->logAdminAction($admin_user_id, 'CREATE', 'sensors', $sensorId, null, $data);
            
            return ['success' => true, 'sensor_id' => $sensorId];
        }
        
        return ['success' => false, 'error' => 'Failed to add sensor'];
    }
    
    public function updateSensor($id, $data, $admin_user_id) {
        // Get old values for logging
        $stmt = $this->db->prepare("SELECT * FROM sensors WHERE id = ?");
        $stmt->bindValue(1, $id);
        $result = $stmt->execute();
        $old_values = $result->fetchArray(SQLITE3_ASSOC);
        
        $stmt2 = $this->db->prepare("
            UPDATE sensors 
            SET name = ?, wpsd_id = ?, wdc_id = ?, street_name = ?, latitude = ?, longitude = ?, zone_id = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $stmt2->bindValue(1, $data['name']);
        $stmt2->bindValue(2, $data['wpsd_id']);
        $stmt2->bindValue(3, $data['wdc_id'] ?? null);
        $stmt2->bindValue(4, $data['street_name']);
        $stmt2->bindValue(5, $data['latitude']);
        $stmt2->bindValue(6, $data['longitude']);
        $stmt2->bindValue(7, $data['zone_id'] ?? null);
        $stmt2->bindValue(8, $id);
        
        $result = $stmt2->execute();
        
        if ($result && $this->db->changes() > 0) {
            // Update zone_parking_spaces relationship for parking space associated with this sensor
            // First, find the parking space for this sensor
            $space_stmt = $this->db->prepare("SELECT id FROM parking_spaces WHERE sensor_id = ?");
            $space_stmt->bindValue(1, $id);
            $space_result = $space_stmt->execute();
            $parking_space = $space_result->fetchArray(SQLITE3_ASSOC);
            
            if ($parking_space) {
                $parking_space_id = $parking_space['id'];
                
                // Remove existing zone assignments for this parking space
                $remove_stmt = $this->db->prepare("DELETE FROM zone_parking_spaces WHERE parking_space_id = ?");
                $remove_stmt->bindValue(1, $parking_space_id);
                $remove_stmt->execute();
                
                // If zone_id is provided, create new assignment
                if (!empty($data['zone_id'])) {
                    $zone_id = $data['zone_id'];
                    $assign_stmt = $this->db->prepare("
                        INSERT OR REPLACE INTO zone_parking_spaces (zone_id, parking_space_id)
                        VALUES (?, ?)
                    ");
                    $assign_stmt->bindValue(1, $zone_id);
                    $assign_stmt->bindValue(2, $parking_space_id);
                    $assign_stmt->execute();
                }
            }
            
            // Log the action
            $this->logAdminAction($admin_user_id, 'UPDATE', 'sensors', $id, $old_values, $data);
            return ['success' => true, 'message' => 'Sensor updated successfully'];
        }
        
        return ['success' => false, 'error' => 'Sensor not found or update failed'];
    }
    
    public function deleteSensor($id, $admin_user_id) {
        // Get sensor info before deletion for logging
        $stmt = $this->db->prepare("SELECT * FROM sensors WHERE id = ?");
        $stmt->bindValue(1, $id);
        $result = $stmt->execute();
        $sensor = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$sensor) {
            return ['success' => false, 'error' => 'Sensor not found'];
        }
        
        // Instead of deleting, set status to 'deleted'
        $stmt2 = $this->db->prepare("
            UPDATE sensors 
            SET status = 'deleted', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt2->bindValue(1, $id);
        $result = $stmt2->execute();
        
        if ($result && $this->db->changes() > 0) {
            // Log the action
            $this->logAdminAction($admin_user_id, 'DELETE', 'sensors', $id, $sensor, ['status' => 'deleted']);
            return ['success' => true, 'message' => 'Sensor deleted successfully'];
        }
        
        return ['success' => false, 'error' => 'Sensor not found'];
    }
    
    // Parking space methods
    public function getParkingSpaces() {
        try {
            // Check if payment_tx_hash column exists
            $has_payment_tx_hash = false;
            try {
                $check_result = $this->db->query("PRAGMA table_info(parking_spaces)");
                while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                    if ($row['name'] === 'payment_tx_hash') {
                        $has_payment_tx_hash = true;
                        break;
                    }
                }
            } catch (Exception $e) {
                // If check fails, assume column doesn't exist
                $has_payment_tx_hash = false;
            }
            
            // Check if max_duration_hours column exists in parking_zones
            $has_max_duration_hours = false;
            try {
                $check_result = $this->db->query("PRAGMA table_info(parking_zones)");
                while ($row = $check_result->fetchArray(SQLITE3_ASSOC)) {
                    if ($row['name'] === 'max_duration_hours') {
                        $has_max_duration_hours = true;
                        break;
                    }
                }
            } catch (Exception $e) {
                $has_max_duration_hours = false;
            }
            
            // Check if reservations table exists
            $has_reservations_table = false;
            try {
                $check_result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reservations'");
                $has_reservations_table = ($check_result->fetchArray() !== false);
            } catch (Exception $e) {
                $has_reservations_table = false;
            }
            
            // Build SELECT query with conditional columns
            $payment_tx_hash_select = $has_payment_tx_hash ? 'ps.payment_tx_hash,' : 'NULL as payment_tx_hash,';
            $max_duration_select = $has_max_duration_hours ? 'z.max_duration_hours as zone_max_duration_hours,' : 'NULL as zone_max_duration_hours,';
            $reservation_end_select = $has_reservations_table ? 'r.end_time as reservation_end_time' : 'NULL as reservation_end_time';
            $reservation_join = $has_reservations_table ? 'LEFT JOIN reservations r ON ps.id = r.parking_space_id AND r.status = \'active\'' : '';
            
            // First, check how many parking spaces exist total
            $total_spaces_result = $this->db->query("SELECT COUNT(*) as count FROM parking_spaces");
            $total_spaces = $total_spaces_result ? $total_spaces_result->fetchArray(SQLITE3_ASSOC)['count'] : 0;
            error_log("getParkingSpaces: Total parking spaces in database: {$total_spaces}");
            
            // Check how many sensors with 'live' status exist
            $live_sensors_result = $this->db->query("SELECT COUNT(*) as count FROM sensors WHERE status = 'live'");
            $live_sensors = $live_sensors_result ? $live_sensors_result->fetchArray(SQLITE3_ASSOC)['count'] : 0;
            error_log("getParkingSpaces: Sensors with 'live' status: {$live_sensors}");
            
            // Check how many parking spaces are linked to live sensors
            $linked_spaces_result = $this->db->query("
                SELECT COUNT(*) as count 
                FROM parking_spaces ps
                JOIN sensors s ON ps.sensor_id = s.id
                WHERE s.status = 'live'
            ");
            $linked_spaces = $linked_spaces_result ? $linked_spaces_result->fetchArray(SQLITE3_ASSOC)['count'] : 0;
            error_log("getParkingSpaces: Parking spaces linked to live sensors: {$linked_spaces}");
            
            $result = $this->db->query("
                SELECT 
                    ps.id,
                    ps.sensor_id,
                    ps.status,
                    ps.license_plate,
                    ps.reservation_time,
                    ps.occupied_since,
                    {$payment_tx_hash_select}
                    ps.created_at,
                    ps.updated_at,
                    s.name as sensor_name,
                    s.street_name,
                    s.latitude,
                    s.longitude,
                    z.id as zone_id,
                    z.name as zone_name,
                    z.is_premium as zone_is_premium,
                    z.hourly_rate as zone_hourly_rate,
                    z.daily_rate as zone_daily_rate,
                    z.color as zone_color,
                    {$max_duration_select}
                    {$reservation_end_select}
                FROM parking_spaces ps
                JOIN sensors s ON ps.sensor_id = s.id
                LEFT JOIN zone_parking_spaces zps ON ps.id = zps.parking_space_id
                LEFT JOIN parking_zones z ON zps.zone_id = z.id
                {$reservation_join}
                WHERE s.status = 'live'
                ORDER BY ps.id
            ");
            
            if (!$result) {
                error_log('getParkingSpaces: Query failed - ' . $this->db->lastErrorMsg());
                return [];
            }
            
            $parking_spaces = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!$row) {
                    break;
                }
                
                $space = [
                    'id' => $row['id'] ?? null,
                    'sensor_id' => $row['sensor_id'] ?? null,
                    'status' => $row['status'] ?? 'vacant',
                    'coordinates' => [
                        'lat' => $row['latitude'] ?? 0.0,
                        'lng' => $row['longitude'] ?? 0.0
                    ],
                    'license_plate' => $row['license_plate'] ?? null,
                    'reservation_time' => $row['reservation_time'] ?? null,
                    'reservation_end_time' => $row['reservation_end_time'] ?? null,
                    'occupied_since' => $row['occupied_since'] ?? null,
                    'payment_tx_hash' => $row['payment_tx_hash'] ?? null
                ];
                
                // Add sensor name and street name if available
                if (!empty($row['sensor_name'])) {
                    $space['sensor_name'] = $row['sensor_name'];
                }
                if (!empty($row['street_name'])) {
                    $space['street_name'] = $row['street_name'];
                }
                
                // Add zone information if exists
                if (!empty($row['zone_id'])) {
                    // Convert is_premium to boolean (handle both 1/0 and true/false)
                    $is_premium = false;
                    if (isset($row['zone_is_premium'])) {
                        $is_premium = ($row['zone_is_premium'] == 1 || $row['zone_is_premium'] === true || $row['zone_is_premium'] === '1');
                    }
                    
                    $space['zone'] = [
                        'id' => (string)$row['zone_id'], // Convert to string for consistency with frontend
                        'name' => $row['zone_name'] ?? null,
                        'is_premium' => $is_premium,
                        'hourly_rate' => isset($row['zone_hourly_rate']) ? (float)$row['zone_hourly_rate'] : null,
                        'daily_rate' => isset($row['zone_daily_rate']) ? (float)$row['zone_daily_rate'] : null,
                        'color' => $row['zone_color'] ?? null,
                        'max_duration_hours' => isset($row['zone_max_duration_hours']) ? (int)$row['zone_max_duration_hours'] : null
                    ];
                }
                
                $parking_spaces[] = $space;
            }
            
            return $parking_spaces;
        } catch (Exception $e) {
            error_log('getParkingSpaces error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return [];
        }
    }
    
    public function updateParkingSpaceStatus($id, $status, $license_plate = null, $reservation_time = null, $occupied_since = null, $payment_tx_hash = null) {
        return $this->executeWithRetry(function() use ($id, $status, $license_plate, $reservation_time, $occupied_since, $payment_tx_hash) {
            $stmt = $this->db->prepare("
                UPDATE parking_spaces 
                SET status = ?, license_plate = ?, reservation_time = ?, occupied_since = ?, payment_tx_hash = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->bindValue(1, $status);
            $stmt->bindValue(2, $license_plate);
            $stmt->bindValue(3, $reservation_time);
            $stmt->bindValue(4, $occupied_since);
            $stmt->bindValue(5, $payment_tx_hash);
            $stmt->bindValue(6, $id);
            
            $result = $stmt->execute();
            
            if ($result && $this->db->changes() > 0) {
                // Track parking usage when status changes
                $this->trackParkingUsage($id, $status, $license_plate);
                return ['success' => true, 'message' => 'Parking space updated successfully'];
            }
            
            return ['success' => false, 'error' => 'Parking space not found'];
        }, 5, 50000); // 5 retries, 50ms base delay
    }
    
    // Track parking usage for analytics
    private function trackParkingUsage($parking_space_id, $status, $license_plate) {
        if ($status === 'occupied' && $license_plate) {
            // Start new usage session
            $stmt = $this->db->prepare("
                INSERT INTO parking_usage (license_plate, parking_space_id, start_time)
                VALUES (?, ?, CURRENT_TIMESTAMP)
            ");
            $stmt->bindValue(1, $license_plate);
            $stmt->bindValue(2, $parking_space_id);
            $stmt->execute();
        } elseif ($status === 'vacant' && $license_plate) {
            // Complete usage session
            $stmt = $this->db->prepare("
                UPDATE parking_usage 
                SET end_time = CURRENT_TIMESTAMP,
                    duration_minutes = ROUND((julianday('now') - julianday(start_time)) * 24 * 60),
                    total_cost = ROUND((julianday('now') - julianday(start_time)) * 24 * 60 * 0.5)
                WHERE parking_space_id = ? AND end_time IS NULL
                ORDER BY start_time DESC
                LIMIT 1
            ");
            $stmt->bindValue(1, $parking_space_id);
            $stmt->execute();
        }
    }
    
    // Get real-time statistics
    public function getStatistics() {
        // Get current parking space counts
        $result = $this->db->query("
            SELECT 
                COUNT(*) as total_spaces,
                SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_spaces,
                SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END) as vacant_spaces,
                SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_spaces
            FROM parking_spaces ps
            JOIN sensors s ON ps.sensor_id = s.id
            WHERE s.status = 'live'
        ");
        $counts = $result->fetchArray(SQLITE3_ASSOC);
        
        // Get revenue and duration statistics
        $result2 = $this->db->query("
            SELECT 
                COUNT(*) as total_sessions,
                COALESCE(SUM(duration_minutes), 0) as total_duration,
                COALESCE(SUM(total_cost), 0) as total_revenue,
                COALESCE(AVG(duration_minutes), 0) as avg_duration
            FROM parking_usage 
            WHERE end_time IS NOT NULL
        ");
        $usage_stats = $result2->fetchArray(SQLITE3_ASSOC);
        
        // Calculate utilization rate
        $total_spaces = $counts['total_spaces'] ?: 0;
        $utilization_rate = $total_spaces > 0 
            ? (($counts['occupied_spaces'] + $counts['reserved_spaces']) / $total_spaces) * 100 
            : 0;
        
        return [
            'total_spaces' => (int)$total_spaces,
            'occupied_spaces' => (int)$counts['occupied_spaces'],
            'vacant_spaces' => (int)$counts['vacant_spaces'],
            'reserved_spaces' => (int)$counts['reserved_spaces'],
            'utilization_rate' => round($utilization_rate, 2),
            'average_duration' => round($usage_stats['avg_duration']),
            'total_revenue' => round($usage_stats['total_revenue'], 2),
            'total_sessions' => (int)$usage_stats['total_sessions']
        ];
    }
    
    // Get daily usage statistics
    public function getDailyUsage($days = 7) {
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%w', start_time) as day_of_week,
                COUNT(*) as count,
                COALESCE(SUM(total_cost), 0) as revenue
            FROM parking_usage 
            WHERE start_time >= date('now', '-{$days} days')
                AND end_time IS NOT NULL
            GROUP BY strftime('%w', start_time)
            ORDER BY day_of_week
        ");
        
        $result = $stmt->execute();
        $daily_data = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $daily_data[] = [
                'date' => $day_names[$row['day_of_week']],
                'count' => (int)$row['count'],
                'revenue' => round($row['revenue'], 2)
            ];
        }
        
        return $daily_data;
    }
    
    // Get hourly usage statistics
    public function getHourlyUsage($days = 7) {
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', start_time) as hour,
                COUNT(*) as count
            FROM parking_usage 
            WHERE start_time >= date('now', '-{$days} days')
                AND end_time IS NOT NULL
            GROUP BY strftime('%H', start_time)
            ORDER BY hour
        ");
        
        $result = $stmt->execute();
        $hourly_data = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $hourly_data[] = [
                'hour' => (int)$row['hour'],
                'count' => (int)$row['count']
            ];
        }
        
        return $hourly_data;
    }
    
    // Get parking usage history
    public function getParkingUsage($limit = 100, $offset = 0, $filters = []) {
        $where_conditions = [];
        $params = [];
        $param_count = 1;
        
        if (!empty($filters['license_plate'])) {
            $where_conditions[] = "license_plate LIKE ?";
            $params[] = '%' . $filters['license_plate'] . '%';
            $param_count++;
        }
        
        if (!empty($filters['date_from'])) {
            $where_conditions[] = "start_time >= ?";
            $params[] = $filters['date_from'];
            $param_count++;
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = "start_time <= ?";
            $params[] = $filters['date_to'];
            $param_count++;
        }
        
        $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
        
        $sql = "
            SELECT 
                pu.*,
                ps.id as parking_space_id,
                s.street_name
            FROM parking_usage pu
            JOIN parking_spaces ps ON pu.parking_space_id = ps.id
            JOIN sensors s ON ps.sensor_id = s.id
            {$where_clause}
            ORDER BY pu.start_time DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $index => $param) {
            $stmt->bindValue($index + 1, $param);
        }
        
        $result = $stmt->execute();
        $usage = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage[] = [
                'id' => $row['id'],
                'license_plate' => $row['license_plate'],
                'parking_space_id' => $row['parking_space_id'],
                'street_name' => $row['street_name'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'duration_minutes' => $row['duration_minutes'],
                'total_cost' => $row['total_cost'],
                'created_at' => $row['created_at']
            ];
        }
        
        return $usage;
    }
    
    // Get active sessions (current reservations and occupied spaces)
    public function getActiveSessions() {
        $result = $this->db->query("
            SELECT 
                ps.id as parking_space_id,
                ps.license_plate,
                ps.status,
                ps.reservation_time,
                ps.occupied_since,
                s.street_name,
                s.latitude,
                s.longitude
            FROM parking_spaces ps
            JOIN sensors s ON ps.sensor_id = s.id
            WHERE ps.status IN ('reserved', 'occupied')
                AND ps.license_plate IS NOT NULL
                AND s.status = 'live'
            ORDER BY ps.updated_at DESC
        ");
        
        $sessions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sessions[] = [
                'parking_space_id' => $row['parking_space_id'],
                'license_plate' => $row['license_plate'],
                'status' => $row['status'],
                'reservation_time' => $row['reservation_time'],
                'occupied_since' => $row['occupied_since'],
                'street_name' => $row['street_name'],
                'coordinates' => [
                    'lat' => $row['latitude'],
                    'lng' => $row['longitude']
                ]
            ];
        }
        
        return $sessions;
    }
    
    // TON Payment methods
    public function createTonPayment($data) {
        $stmt = $this->db->prepare("
            INSERT INTO ton_payments (reservation_id, parking_space_id, license_plate, tx_hash, amount_nano, amount_ton, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->bindValue(1, $data['reservation_id'] ?? null);
        $stmt->bindValue(2, $data['parking_space_id']);
        $stmt->bindValue(3, $data['license_plate']);
        $stmt->bindValue(4, $data['tx_hash']);
        $stmt->bindValue(5, $data['amount_nano']);
        $stmt->bindValue(6, $data['amount_ton']);
        
        if ($stmt->execute()) {
            return ['success' => true, 'payment_id' => $this->db->lastInsertRowID()];
        }
        
        return ['success' => false, 'error' => 'Failed to create payment record'];
    }
    
    public function verifyTonPayment($tx_hash) {
        // Update payment status to verified
        $stmt = $this->db->prepare("
            UPDATE ton_payments 
            SET status = 'verified', verified_at = CURRENT_TIMESTAMP
            WHERE tx_hash = ? AND status = 'pending'
        ");
        $stmt->bindValue(1, $tx_hash);
        
        if ($stmt->execute() && $this->db->changes() > 0) {
            return ['success' => true, 'message' => 'Payment verified'];
        }
        
        return ['success' => false, 'error' => 'Payment not found or already verified'];
    }
    
    public function getTonPaymentByTxHash($tx_hash) {
        $stmt = $this->db->prepare("
            SELECT * FROM ton_payments WHERE tx_hash = ?
        ");
        $stmt->bindValue(1, $tx_hash);
        $result = $stmt->execute();
        
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    public function getTonPaymentsByReservation($reservation_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM ton_payments WHERE reservation_id = ?
        ");
        $stmt->bindValue(1, $reservation_id);
        $result = $stmt->execute();
        
        $payments = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $payments[] = $row;
        }
        
        return $payments;
    }
    
    // Reservation methods
    public function createReservation($data) {
        // Check if reservations table exists
        $has_reservations_table = false;
        try {
            $check_result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reservations'");
            $has_reservations_table = ($check_result->fetchArray() !== false);
        } catch (Exception $e) {
            $has_reservations_table = false;
        }
        
        if (!$has_reservations_table) {
            // Table doesn't exist, return success but don't create reservation record
            return ['success' => true, 'reservation_id' => null, 'note' => 'Reservations table does not exist'];
        }
        
        // Check if active reservation already exists for this space
        $existing = $this->db->prepare("
            SELECT id FROM reservations 
            WHERE parking_space_id = ? AND status = 'active'
        ");
        $existing->bindValue(1, $data['parking_space_id']);
        $result = $existing->execute();
        $existing_reservation = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($existing_reservation) {
            // Update existing reservation
            $stmt = $this->db->prepare("
                UPDATE reservations 
                SET license_plate = ?, start_time = ?, end_time = ?, status = 'active', created_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->bindValue(1, $data['license_plate']);
            $stmt->bindValue(2, $data['start_time']);
            $stmt->bindValue(3, $data['end_time']);
            $stmt->bindValue(4, $existing_reservation['id']);
            
            if ($stmt->execute()) {
                return ['success' => true, 'reservation_id' => $existing_reservation['id']];
            }
        } else {
            // Create new reservation
            $stmt = $this->db->prepare("
                INSERT INTO reservations (license_plate, parking_space_id, start_time, end_time, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bindValue(1, $data['license_plate']);
            $stmt->bindValue(2, $data['parking_space_id']);
            $stmt->bindValue(3, $data['start_time']);
            $stmt->bindValue(4, $data['end_time']);
            $stmt->bindValue(5, $data['status'] ?? 'active');
            
            if ($stmt->execute()) {
                $reservation_id = $this->db->lastInsertRowID();
                return ['success' => true, 'reservation_id' => $reservation_id];
            }
        }
        
        return ['success' => false, 'error' => 'Failed to create reservation'];
    }
    
    public function getActiveReservations() {
        // Check if reservations table exists
        $has_reservations_table = false;
        try {
            $check_result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reservations'");
            $has_reservations_table = ($check_result->fetchArray() !== false);
        } catch (Exception $e) {
            $has_reservations_table = false;
        }
        
        if (!$has_reservations_table) {
            return [];
        }
        
        $result = $this->db->query("
            SELECT * FROM reservations 
            WHERE status = 'active' 
            ORDER BY end_time ASC
        ");
        
        $reservations = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $reservations[] = $row;
        }
        
        return $reservations;
    }
    
    public function completeReservation($reservation_id) {
        // Check if reservations table exists
        $has_reservations_table = false;
        try {
            $check_result = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reservations'");
            $has_reservations_table = ($check_result->fetchArray() !== false);
        } catch (Exception $e) {
            $has_reservations_table = false;
        }
        
        if (!$has_reservations_table) {
            return ['success' => false, 'error' => 'Reservations table does not exist'];
        }
        
        $stmt = $this->db->prepare("
            UPDATE reservations 
            SET status = 'completed' 
            WHERE id = ?
        ");
        $stmt->bindValue(1, $reservation_id);
        
        if ($stmt->execute()) {
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => 'Failed to complete reservation'];
    }
    
    // Telegram user methods
    public function linkTelegramUser($telegram_id, $username, $license_plate, $chat_id) {
        try {
            error_log("Database::linkTelegramUser: Starting - telegram_id={$telegram_id}, username={$username}, license_plate={$license_plate}, chat_id={$chat_id}");
            
            // Check if user already exists
            $existing = $this->getTelegramUserByTelegramId($telegram_id);
            
            if ($existing) {
                error_log("Database::linkTelegramUser: User exists, updating");
                // Update existing user
                $stmt = $this->db->prepare("
                    UPDATE telegram_users 
                    SET username = ?, license_plate = ?, chat_id = ?, is_active = 1, updated_at = CURRENT_TIMESTAMP
                    WHERE telegram_user_id = ?
                ");
                $stmt->bindValue(1, $username);
                $stmt->bindValue(2, $license_plate);
                $stmt->bindValue(3, $chat_id);
                $stmt->bindValue(4, $telegram_id);
                
                if ($stmt->execute()) {
                    error_log("Database::linkTelegramUser: Update successful");
                    return ['success' => true, 'message' => 'Telegram user updated'];
                } else {
                    $error = $this->db->lastErrorMsg();
                    error_log("Database::linkTelegramUser: Update failed - {$error}");
                    return ['success' => false, 'error' => "Update failed: {$error}"];
                }
            } else {
                error_log("Database::linkTelegramUser: User does not exist, creating new");
                // Create new user
                $stmt = $this->db->prepare("
                    INSERT INTO telegram_users (telegram_user_id, username, license_plate, chat_id)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->bindValue(1, $telegram_id);
                $stmt->bindValue(2, $username);
                $stmt->bindValue(3, $license_plate);
                $stmt->bindValue(4, $chat_id);
                
                if ($stmt->execute()) {
                    error_log("Database::linkTelegramUser: Insert successful");
                    return ['success' => true, 'message' => 'Telegram user linked'];
                } else {
                    $error = $this->db->lastErrorMsg();
                    error_log("Database::linkTelegramUser: Insert failed - {$error}");
                    return ['success' => false, 'error' => "Insert failed: {$error}"];
                }
            }
        } catch (Exception $e) {
            error_log("Database::linkTelegramUser: Exception - " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function getTelegramUserByTelegramId($telegram_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM telegram_users WHERE telegram_user_id = ? AND is_active = 1
        ");
        $stmt->bindValue(1, $telegram_id);
        $result = $stmt->execute();
        
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    public function getTelegramUserByLicensePlate($license_plate) {
        $stmt = $this->db->prepare("
            SELECT * FROM telegram_users WHERE license_plate = ? AND is_active = 1
        ");
        $stmt->bindValue(1, $license_plate);
        $result = $stmt->execute();
        
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    public function updateTelegramUserWallet($telegram_user_id, $wallet_address) {
        try {
            $stmt = $this->db->prepare("
                UPDATE telegram_users 
                SET ton_wallet_address = ?, updated_at = CURRENT_TIMESTAMP
                WHERE telegram_user_id = ?
            ");
            $stmt->bindValue(1, $wallet_address);
            $stmt->bindValue(2, $telegram_user_id);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Wallet address updated'];
            } else {
                $error = $this->db->lastErrorMsg();
                return ['success' => false, 'error' => "Update failed: {$error}"];
            }
        } catch (Exception $e) {
            error_log("Database::updateTelegramUserWallet: Exception - " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Notification preferences methods
    public function updateNotificationPreferences($telegram_id, $preferences) {
        // Check if preferences exist
        $existing = $this->getNotificationPreferences($telegram_id);
        
        if ($existing) {
            // Update existing preferences
            $stmt = $this->db->prepare("
                UPDATE notification_preferences 
                SET notify_free_spaces = ?, notify_specific_space = ?, notify_street = ?, notify_zone = ?, updated_at = CURRENT_TIMESTAMP
                WHERE telegram_user_id = ?
            ");
            $stmt->bindValue(1, isset($preferences['notify_free_spaces']) ? ($preferences['notify_free_spaces'] ? 1 : 0) : 1);
            $stmt->bindValue(2, $preferences['notify_specific_space'] ?? null);
            $stmt->bindValue(3, $preferences['notify_street'] ?? null);
            $stmt->bindValue(4, $preferences['notify_zone'] ?? null);
            $stmt->bindValue(5, $telegram_id);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Preferences updated'];
            }
        } else {
            // Get user info to get license plate
            $user = $this->getTelegramUserByTelegramId($telegram_id);
            if (!$user) {
                return ['success' => false, 'error' => 'Telegram user not found'];
            }
            
            // Create new preferences
            $stmt = $this->db->prepare("
                INSERT INTO notification_preferences (telegram_user_id, license_plate, notify_free_spaces, notify_specific_space, notify_street, notify_zone)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bindValue(1, $telegram_id);
            $stmt->bindValue(2, $user['license_plate']);
            $stmt->bindValue(3, isset($preferences['notify_free_spaces']) ? ($preferences['notify_free_spaces'] ? 1 : 0) : 1);
            $stmt->bindValue(4, $preferences['notify_specific_space'] ?? null);
            $stmt->bindValue(5, $preferences['notify_street'] ?? null);
            $stmt->bindValue(6, $preferences['notify_zone'] ?? null);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Preferences created'];
            }
        }
        
        return ['success' => false, 'error' => 'Failed to update preferences'];
    }
    
    public function getNotificationPreferences($telegram_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM notification_preferences WHERE telegram_user_id = ?
        ");
        $stmt->bindValue(1, $telegram_id);
        $result = $stmt->execute();
        
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    // Notification queue methods
    public function queueNotification($telegram_id, $type, $message, $data = []) {
        $stmt = $this->db->prepare("
            INSERT INTO notification_queue (telegram_user_id, type, message, reservation_id, parking_space_id, scheduled_at, status)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 'pending')
        ");
        
        $stmt->bindValue(1, $telegram_id);
        $stmt->bindValue(2, $type);
        $stmt->bindValue(3, $message);
        $stmt->bindValue(4, $data['reservation_id'] ?? null);
        $stmt->bindValue(5, $data['parking_space_id'] ?? null);
        
        if ($stmt->execute()) {
            return ['success' => true, 'notification_id' => $this->db->lastInsertRowID()];
        }
        
        return ['success' => false, 'error' => 'Failed to queue notification'];
    }
    
    public function getPendingNotifications($limit = 100) {
        $stmt = $this->db->prepare("
            SELECT * FROM notification_queue 
            WHERE status = 'pending' AND scheduled_at <= CURRENT_TIMESTAMP
            ORDER BY scheduled_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit);
        $result = $stmt->execute();
        
        $notifications = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $notifications[] = $row;
        }
        
        return $notifications;
    }
    
    public function markNotificationSent($notification_id) {
        $stmt = $this->db->prepare("
            UPDATE notification_queue 
            SET status = 'sent', sent_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->bindValue(1, $notification_id);
        
        return $stmt->execute();
    }
    
    public function markNotificationFailed($notification_id) {
        $stmt = $this->db->prepare("
            UPDATE notification_queue 
            SET status = 'failed'
            WHERE id = ?
        ");
        $stmt->bindValue(1, $notification_id);
        
        return $stmt->execute();
    }
    
    public function query($sql) {
        return $this->db->query($sql);
    }
    
    public function prepare($sql) {
        return $this->db->prepare($sql);
    }
    
    public function exec($sql, $params = []) {
        if (empty($params)) {
            return $this->db->exec($sql);
        } else {
            $stmt = $this->db->prepare($sql);
            if ($stmt) {
                foreach ($params as $index => $param) {
                    $stmt->bindValue($index + 1, $param);
                }
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
            return false;
        }
    }
    
    /**
     * Execute a database operation with automatic retry on lock errors
     * @param callable $operation Function that performs the database operation
     * @param int $max_retries Maximum number of retry attempts
     * @param int $base_delay Base delay in microseconds (default 100ms)
     * @return mixed Result of the operation
     * @throws Exception If operation fails after all retries
     */
    public function executeWithRetry($operation, $max_retries = 3, $base_delay = 100000) {
        $last_exception = null;
        
        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
                $last_exception = $e;
                
                // Check if it's a database locked error
                if (stripos($error_msg, 'database is locked') !== false || 
                    stripos($error_msg, 'locked') !== false ||
                    stripos($error_msg, 'SQLITE_BUSY') !== false) {
                    
                    if ($attempt < $max_retries - 1) {
                        // Exponential backoff: 100ms, 200ms, 400ms, etc.
                        $delay = $base_delay * pow(2, $attempt);
                        usleep($delay);
                        continue; // Retry
                    } else {
                        // Max retries reached
                        error_log("Database operation failed after {$max_retries} attempts: " . $error_msg);
                        throw new Exception("Database is busy. Please try again in a moment.");
                    }
                } else {
                    // Other error - don't retry, throw immediately
                    throw $e;
                }
            }
        }
        
        // Should never reach here, but just in case
        throw $last_exception ?? new Exception("Database operation failed");
    }
    
    public function __destruct() {
        if ($this->db) {
            // Close connection immediately to free locks
            $this->db->close();
            $this->db = null;
        }
    }
}
?>
