<?php
// Prevent direct access
if (basename($_SERVER['PHP_SELF']) == 'connection.php') {
    die('Direct access not permitted');
}

// Include Global Security Layer
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/env.php';

// Database Configuration (Environment-driven)
$host = getenv('SUPABASE_DB_HOST') ?: ($_SERVER['SUPABASE_DB_HOST'] ?? '');
$port = getenv('SUPABASE_DB_PORT') ?: ($_SERVER['SUPABASE_DB_PORT'] ?? '6543');
$db   = getenv('SUPABASE_DB_NAME') ?: ($_SERVER['SUPABASE_DB_NAME'] ?? 'postgres');
$user = getenv('SUPABASE_DB_USER') ?: ($_SERVER['SUPABASE_DB_USER'] ?? '');
$pass = getenv('SUPABASE_DB_PASSWORD') ?: ($_SERVER['SUPABASE_DB_PASSWORD'] ?? '');

// DATA SOURCE NAME (DSN)
// We add sslmode=require because Supabase requires a secure connection.
$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

// SUPABASE CONFIGURATION
require_once __DIR__ . '/includes/supabase_config.php';

// SET TIMEZONE TO PHILIPPINES
date_default_timezone_set('Asia/Manila');

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    if (!$host || !$user || !$pass) {
        throw new RuntimeException('Database credentials are not configured. Create .env.local (or .env) by copying .env.example, then set SUPABASE_DB_HOST, SUPABASE_DB_USER, SUPABASE_DB_PASSWORD.');
    }
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Set PostgreSQL session timezone
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id SERIAL PRIMARY KEY,
            setting_key VARCHAR(255) UNIQUE NOT NULL,
            setting_value TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS walk_ins (
            id SERIAL PRIMARY KEY,
            visitor_name VARCHAR(255) NOT NULL,
            amount NUMERIC(10,2) NOT NULL DEFAULT 0,
            visit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            checked_in_by INTEGER
        )
    ");
    // Harden schema if table already exists but with missing columns
    $pdo->exec("ALTER TABLE walk_ins ADD COLUMN IF NOT EXISTS visitor_name VARCHAR(255)");
    $pdo->exec("ALTER TABLE walk_ins ADD COLUMN IF NOT EXISTS amount NUMERIC(10,2) DEFAULT 0");
    $pdo->exec("ALTER TABLE walk_ins ADD COLUMN IF NOT EXISTS visit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("ALTER TABLE walk_ins ADD COLUMN IF NOT EXISTS checked_in_by INTEGER");
    // Sales may require expires_at for membership but walk-ins set it to NULL
    $pdo->exec("ALTER TABLE sales ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS visitor_name VARCHAR(255)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_log (
            id SERIAL PRIMARY KEY,
            user_id INTEGER,
            role VARCHAR(50),
            action VARCHAR(100),
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    // Run daily cleanup of stale records
    require_once __DIR__ . '/includes/auto_cleanup.php';
    runAutoCleanup($pdo);
    // echo "Connected successfully!"; 
} catch (\Throwable $e) {
    // If on XAMPP (Localhost), show the error.
    // If on Hostinger (Live), hide it for security.
    if ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1') {
        die("Connection Failed: " . $e->getMessage());
    } else {
        // Log the actual error for admin review but show a generic message to user
        error_log("DB Connection Error: " . $e->getMessage());
        die("System Error. Please try again later. (Debug: " . $e->getMessage() . ")");
    }
}

if (!function_exists('logActivity')) {
    function logActivity($pdo, $userId, $role, $action, $details) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, role, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $role, $action, $details, $ip]);
    }
}
?>
