<?php
// Prevent direct access
if (basename($_SERVER['PHP_SELF']) == 'connection.php') {
    die('Direct access not permitted');
}

// Include Global Security Layer
require_once __DIR__ . '/includes/security.php';

// 1. HOST: Copied from "@aws-1-ap-south-1.pooler.supabase.com"
$host = "aws-1-ap-south-1.pooler.supabase.com";

// 2. PORT: Always use 6543 from the DATABASE_URL (Not 5432)
$port = "6543";

// 3. DATABASE: Copied from "/postgres"
$db   = "postgres";

// 4. USER: Copied from "postgres.olczvynzhpwnaotzjaig"
$user = "postgres.olczvynzhpwnaotzjaig";

// 5. PASSWORD: You must type the password you created when you made the project.
// It is NOT "[YOUR-PASSWORD]". It is your actual secret password.
$pass = "artsgymcapstone";

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
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Set PostgreSQL session timezone
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");
    // Run daily cleanup of stale records
    require_once __DIR__ . '/includes/auto_cleanup.php';
    runAutoCleanup($pdo);
    // echo "Connected successfully!"; 
} catch (\PDOException $e) {
    // If on XAMPP (Localhost), show the error.
    // If on Hostinger (Live), hide it for security.
    if ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1') {
        die("Connection Failed: " . $e->getMessage());
    } else {
        die("System Error. Please try again later.");
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
