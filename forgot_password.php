<?php
// 1. PHP BACKEND LOGIC
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'connection.php';

// Set Timezone to ensure PHP logs align (though we rely on DB for token)
date_default_timezone_set('Asia/Manila');

// Force POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit;
}

header('Content-Type: application/json');

$email = trim($_POST['email'] ?? '');
if (!$email) {
    echo json_encode(["status" => "error", "message" => "Email is required"]);
    exit;
}

// Check if user exists
$stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Security: pretend we sent it
    echo json_encode(["status" => "success", "message" => "Password reset link sent to $email"]);
    exit;
}

// Generate reset token
$reset_token = bin2hex(random_bytes(16));

// --- TIMEZONE FIX: Use database NOW() ---
// This ensures the expiry time matches the database server's clock exactly
$stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = NOW() + INTERVAL '1 hour' WHERE id = ?");
if (!$stmt->execute([$reset_token, $user['id']])) {
    echo json_encode(["status" => "error", "message" => "Database update failed."]);
    exit;
}

// Build Link
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$currentDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
if ($currentDir == '/') { $currentDir = ''; }

$reset_link = "$protocol://$host$currentDir/reset_password.php?token=$reset_token";

// --- SEND EMAIL VIA BREVO ---
$apiKey = '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';

$emailData = [
    "sender" => ["name" => "Arts Gym Portal", "email" => "lancegarcia841@gmail.com"],
    "to" => [["email" => $email, "name" => $user['full_name']]],
    "subject" => "Reset Your Password - Arts Gym",
    "htmlContent" => "
        <div style='font-family: Arial, sans-serif; padding:20px; border:1px solid #eee'>
            <h2 style='color:#e63946'>Password Reset Request</h2>
            <p>Hi {$user['full_name']},</p>
            <p>You requested to reset your password. Click the button below:</p>
            <a href='$reset_link' style='background:#e63946;color:#fff;padding:12px 25px;text-decoration:none;border-radius:5px;display:inline-block;font-weight:bold'>RESET PASSWORD</a>
            <p style='font-size:12px;color:#777;margin-top:15px'>Or copy this link:<br>$reset_link</p>
            <hr>
            <p style='color:#d9534f; font-weight:bold;'>⚠️ IMPORTANT: This link will expire in 1 hour.</p>
        </div>
    "
];

$ch = curl_init("https://api.brevo.com/v3/smtp/email");
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        "api-key: " . $apiKey,
        "Content-Type: application/json",
        "Accept: application/json"
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($emailData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYHOST => 0, 
    CURLOPT_SSL_VERIFYPEER => 0
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode(["status" => "success", "message" => "Password reset link sent to $email"]);
} else {
    $apiRes = json_decode($response, true);
    $reason = $apiRes['message'] ?? 'Unknown Error';
    echo json_encode(["status" => "error", "message" => "Brevo Error: $reason"]);
}
?>