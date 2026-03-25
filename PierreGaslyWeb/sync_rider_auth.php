<?php
require_once 'includes/config.php';
requireAdmin();

function authAdminRequest(string $method, string $path, ?array $payload = null): array {
    $endpoint = rtrim(SUPABASE_URL, '/') . $path;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        throw new Exception('Supabase Auth request failed');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return ['status' => $httpCode, 'data' => $decoded, 'raw' => $response];
}

function authUserExistsByEmail(string $email): bool {
    $result = authAdminRequest('GET', '/auth/v1/admin/users?email=' . urlencode($email));
    if (($result['status'] ?? 500) >= 400) {
        throw new Exception('Unable to check Supabase Auth records');
    }
    $data = $result['data'] ?? [];
    $users = [];
    if (isset($data['users']) && is_array($data['users'])) {
        $users = $data['users'];
    } elseif (isset($data[0])) {
        $users = $data;
    }
    foreach ($users as $user) {
        if (!empty($user['email']) && strtolower((string)$user['email']) === strtolower($email)) {
            return true;
        }
    }
    return false;
}

function findAuthUserByEmail(string $email): ?array {
    $result = authAdminRequest('GET', '/auth/v1/admin/users?email=' . urlencode($email));
    if (($result['status'] ?? 500) >= 400) {
        throw new Exception('Unable to check Supabase Auth records');
    }
    $data = $result['data'] ?? [];
    $users = [];
    if (isset($data['users']) && is_array($data['users'])) {
        $users = $data['users'];
    } elseif (isset($data[0])) {
        $users = $data;
    }
    foreach ($users as $user) {
        if (!empty($user['email']) && strtolower((string)$user['email']) === strtolower($email)) {
            return $user;
        }
    }
    return null;
}

function createConfirmedAuthUser(string $email, string $password, array $metadata = []): array {
    $result = authAdminRequest('POST', '/auth/v1/admin/users', [
        'email' => $email,
        'password' => $password,
        'email_confirm' => true,
        'user_metadata' => $metadata,
        'app_metadata' => [
            'provider' => 'email',
            'role' => $metadata['role'] ?? 'rider',
        ],
    ]);

    if (($result['status'] ?? 500) >= 400) {
        $message = $result['data']['msg'] ?? $result['data']['message'] ?? $result['raw'] ?? 'Unable to create auth user';
        throw new Exception($message);
    }
    return $result['data'];
}

$db = Database::getInstance();
$rows = $db->select('users', ['role' => 'rider']);
$created = [];
$skipped = [];
$failed = [];

foreach ($rows as $row) {
    $email = strtolower(trim((string)($row['email'] ?? '')));
    $phone = trim((string)($row['phone'] ?? ''));
    $userId = (int)($row['user_id'] ?? 0);
    if ($email === '' || $phone === '') {
        $failed[] = ['email' => $email, 'reason' => 'Missing email or phone'];
        continue;
    }

    try {
        $authUser = findAuthUserByEmail($email);
        if (!$authUser) {
            $authUser = createConfirmedAuthUser($email, $phone, [
                'full_name' => $row['full_name'] ?? '',
                'phone' => $phone,
                'role' => 'rider'
            ]);
            $created[] = $email;
        } else {
            $skipped[] = $email;
        }

        $authUserId = $authUser['id'] ?? null;
        if ($authUserId && $userId > 0) {
            $db->query(
                "UPDATE users SET auth_user_id = ?, email_verified = true, first_login = true WHERE user_id = ?",
                [$authUserId, $userId]
            );
        }
    } catch (Exception $e) {
        $failed[] = ['email' => $email, 'reason' => $e->getMessage()];
    }
}

logActivity('update', 'user', 'Backfilled rider auth accounts: ' . count($created));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rider Auth Sync</title>
<style>body{font-family:Arial,sans-serif;background:#f5f7fb;padding:24px}.card{background:#fff;border-radius:16px;padding:24px;max-width:900px;margin:0 auto;box-shadow:0 8px 24px rgba(0,0,0,.08)}h1{margin-top:0}.pill{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.ok{background:#e8f8ee;color:#178f4a}.warn{background:#fff4db;color:#a36a00}.bad{background:#fdeaea;color:#c12d2d}ul{padding-left:20px}</style>
</head>
<body>
<div class="card">
<h1>Rider Auth Sync Completed</h1>
<p>This creates missing Supabase Auth accounts for existing rider rows using the rider email and phone number as the temporary password, then links the generated Supabase auth user ID back into the <code>users.auth_user_id</code> column.</p>
<p><span class="pill ok">Created: <?= count($created) ?></span> <span class="pill warn">Already existed: <?= count($skipped) ?></span> <span class="pill bad">Failed: <?= count($failed) ?></span></p>
<?php if ($created): ?>
<h3>Created</h3>
<ul><?php foreach ($created as $email): ?><li><?= htmlspecialchars($email) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($skipped): ?>
<h3>Already existed</h3>
<ul><?php foreach ($skipped as $email): ?><li><?= htmlspecialchars($email) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($failed): ?>
<h3>Failed</h3>
<ul><?php foreach ($failed as $item): ?><li><strong><?= htmlspecialchars($item['email'] ?: '(missing email)') ?></strong> — <?= htmlspecialchars($item['reason']) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p><a href="users.php">Back to Users</a></p>
</div>
</body>
</html>
