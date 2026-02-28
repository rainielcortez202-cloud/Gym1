<?php
require 'connection.php';
session_start();

$token = $_GET['token'] ?? '';

if (!$token) {
    header("Location: login.php?error=invalid_token");
    exit;
}

try {
    // Find user with this approval token
    $stmt = $pdo->prepare("SELECT id, email, full_name, verification_token FROM users WHERE verification_token LIKE ?");
    $stmt->execute(["%$token%"]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['verification_token']) {
        header("Location: login.php?error=invalid_token");
        exit;
    }

    $token_data = json_decode($user['verification_token'], true);

    // Check if this is an email change request and token matches
    if (!isset($token_data['type']) || $token_data['type'] !== 'email_change' || 
        !isset($token_data['approval_token']) || $token_data['approval_token'] !== $token ||
        !isset($token_data['new_email'])) {
        header("Location: login.php?error=invalid_token");
        exit;
    }

    $new_email = $token_data['new_email'];
    $user_id = $user['id'];

    // Validate new email format
    if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid new email format: " . $new_email);
        header("Location: login.php?error=invalid_email");
        exit;
    }

    // Check if new email is already in use
    $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt_check->execute([$new_email, $user_id]);
    if ($stmt_check->fetch()) {
        header("Location: login.php?error=email_in_use");
        exit;
    }

    // Log for debugging
    error_log("Sending verification email to new address: " . $new_email . " for user ID: " . $user_id);

    // Generate verification token for NEW email
    $verification_token = bin2hex(random_bytes(16));
    
    // Update: store new token data for new email verification
    $new_token_data = json_encode([
        "type" => "email_change_verify",
        "verification_token" => $verification_token,
        "old_email" => $user['email'],
        "new_email" => $new_email
    ]);

    $stmt_update = $pdo->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
    $stmt_update->execute([$new_token_data, $user_id]);

    // Send verification email to NEW email
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $currentDir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
    if ($currentDir == '/') { $currentDir = ''; }
    
    $verify_link = "$protocol://$host$currentDir/verify_email.php?token=$verification_token";

    $subject = "Verify Your New Email Address - Arts Gym";
    $bodyHtml = "
        <div style='font-family:Arial,sans-serif;padding:20px'>
            <h2>Verify Your New Email Address</h2>
            <p>Hi {$user['full_name']},</p>
            <p>Your email change request has been approved. Please verify your new email address by clicking the button below:</p>
            <p><strong>New email:</strong> $new_email</p>
            <p><a href='$verify_link' style='display:inline-block;padding:12px 25px;background:#e63946;color:#fff;text-decoration:none;border-radius:5px;font-weight:bold;margin-top:15px;'>VERIFY NEW EMAIL</a></p>
            <p style='margin-top:20px;font-size:12px;color:#777;'>
                If you did not request this change, please contact support immediately.
            </p>
        </div>
    ";

    // Send via Brevo
    $apiKey = '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';
    $emailData = [
        "sender" => ["name" => "Arts Gym Portal", "email" => "lancegarcia841@gmail.com"],
        "to" => [[ "email" => $new_email, "name" => $user['full_name'] ]],
        "subject" => $subject,
        "htmlContent" => $bodyHtml
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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Check if email was sent successfully
    if ($curlError) {
        error_log("Brevo CURL Error: " . $curlError);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Email Error</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f8f9fa; }
                .card { max-width: 500px; padding: 30px; }
            </style>
        </head>
        <body>
            <div class="card shadow">
                <div class="text-center">
                    <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                    <h4 class="mb-3">Email Sending Error</h4>
                    <p class="text-muted">There was an error sending the verification email. Please try requesting the email change again.</p>
                    <a href="login.php" class="btn btn-danger mt-3">Go to Login</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    if ($httpCode !== 201) {
        error_log("Brevo API Error: HTTP $httpCode - Response: " . $response);
        $errorMsg = "Failed to send verification email";
        if ($httpCode === 400 || $httpCode === 401) {
            $errorMsg = "Email service configuration error. Please contact support.";
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Email Error</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f8f9fa; }
                .card { max-width: 500px; padding: 30px; }
            </style>
        </head>
        <body>
            <div class="card shadow">
                <div class="text-center">
                    <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                    <h4 class="mb-3">Email Sending Error</h4>
                    <p class="text-muted"><?= htmlspecialchars($errorMsg) ?></p>
                    <p class="text-muted small">HTTP Code: <?= $httpCode ?></p>
                    <a href="login.php" class="btn btn-danger mt-3">Go to Login</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Show success page only if email was sent successfully
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Email Change Approved</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f8f9fa; }
            .card { max-width: 500px; padding: 30px; }
        </style>
    </head>
    <body>
        <div class="card shadow">
            <div class="text-center">
                <div class="mb-3"><i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i></div>
                <h4 class="mb-3">Email Change Approved</h4>
                <p class="text-muted">We've sent a verification email to your new email address:</p>
                <p class="text-muted"><strong><?= htmlspecialchars($new_email) ?></strong></p>
                <p class="text-muted small">Please check your inbox (and spam folder) and click the verification link to complete the email change.</p>
                <a href="login.php" class="btn btn-danger mt-3">Go to Login</a>
            </div>
        </div>
    </body>
    </html>
    <?php

} catch (Exception $e) {
    header("Location: login.php?error=server_error");
    exit;
}
