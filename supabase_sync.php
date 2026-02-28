<?php
require_once 'connection.php';

/**
 * Syncs a user's role from the local database to Supabase Auth app_metadata.
 * If the user does not exist in Supabase Auth, it CREATES them with a default password.
 */
function syncSupabaseMetadata($pdo, $email, $role) {
    global $supabase_service_key, $supabase_url;
    
    if ($supabase_service_key === "REPLACE_WITH_YOUR_SERVICE_ROLE_KEY" || empty($supabase_service_key)) {
        return "❌ Missing Service Role Key";
    }

    // 1. Check if User exists in Supabase Auth
    $search_url = "{$supabase_url}/auth/v1/admin/users?email=" . urlencode($email);
    $ch = curl_init($search_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "apikey: {$supabase_service_key}",
            "Authorization: Bearer {$supabase_service_key}"
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $users_data = json_decode($response, true);
    curl_close($ch);

    $users = isset($users_data['users']) ? $users_data['users'] : [];
    $target_uuid = null;
    foreach ($users as $u) {
        if (strtolower($u['email'] ?? '') === strtolower($email)) {
            $target_uuid = $u['id'];
            break;
        }
    }

    if (!$target_uuid) {
        // 2. User NOT found -> CREATE THEM in Supabase Auth
        $create_url = "{$supabase_url}/auth/v1/admin/users";
        $default_pass = "Gym12345!"; // Default password for new auth accounts
        
        $create_data = [
            "email" => $email,
            "password" => $default_pass,
            "email_confirm" => true,
            "app_metadata" => ["role" => $role]
        ];

        $ch = curl_init($create_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($create_data),
            CURLOPT_HTTPHEADER => [
                "apikey: {$supabase_service_key}",
                "Authorization: Bearer {$supabase_service_key}",
                "Content-Type: application/json"
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        $res_data = json_decode($response, true);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 201 || $status === 200) {
            return "✅ Created & Synced (Default Pass: $default_pass)";
        } else {
            return "❌ Create Failed: " . ($res_data['msg'] ?? 'Unknown Error');
        }
    } else {
        // 3. User FOUND -> UPDATE Metadata
        $update_url = "{$supabase_url}/auth/v1/admin/users/{$target_uuid}";
        $data = ["app_metadata" => ["role" => $role]];

        $ch = curl_init($update_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "apikey: {$supabase_service_key}",
                "Authorization: Bearer {$supabase_service_key}",
                "Content-Type: application/json"
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($status === 200) ? "✅ Metadata Updated" : "❌ Update Failed";
    }
}

// Check if run directly for bulk sync
if (basename($_SERVER['PHP_SELF']) == 'supabase_sync.php' && isset($_GET['bulk'])) {
    echo "<h2>Proactive Supabase Auth Sync...</h2>";
    echo "<p>This will create accounts in Supabase Auth for any database user missing one.</p>";
    
    // Skip invalid emails or placeholders
    $stmt = $pdo->query("SELECT email, role FROM users WHERE email LIKE '%@%'");
    $users = $stmt->fetchAll();
    
    foreach ($users as $u) {
        $res = syncSupabaseMetadata($pdo, $u['email'], $u['role']);
        echo "User <b>{$u['email']}</b> ({$u['role']}): $res <br>";
    }
}
?>