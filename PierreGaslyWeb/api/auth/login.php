<?php
/**
 * LOGIN - User authentication
 * POST /api/auth/login.php
 *
 * Authenticates user with email and password.
 * Blocks suspended users before sign-in.
 * Supports first-login password reset flow for rider temp passwords.
 */

require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$data = getJsonInput();
$email = strtolower(trim(sanitize($data['email'] ?? '')));
$password = $data['password'] ?? '';

if (!isValidEmail($email)) {
    sendError('Invalid email address');
}

if (strlen($password) < 8) {
    sendError('Invalid password');
}

function normalizeTempPhonePassword($rawPhone): string {
    $digits = preg_replace('/[^0-9]/', '', (string)$rawPhone);

    if ($digits === '') {
        return '';
    }

    if (strlen($digits) === 10 && strpos($digits, '9') === 0) {
        return '0' . $digits;
    }

    if (strlen($digits) === 11 && strpos($digits, '09') === 0) {
        return $digits;
    }

    if (strlen($digits) === 12 && strpos($digits, '63') === 0) {
        return '0' . substr($digits, 2);
    }

    if (strlen($digits) === 13 && strpos($digits, '639') === 0) {
        return '0' . substr($digits, 2);
    }

    return '';
}

try {
    global $supabase;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limiting: allow up to 15 failed attempts within the last 2 minutes.
    $recentAttempts = $supabase->select('activity_logs', [
        'action' => 'login_failed',
        'ip_address' => $ip
    ], 'log_id,created_at', true);

    $recentFailCount = 0;
    $cutoffTimestamp = time() - 120;

    if (is_array($recentAttempts)) {
        foreach ($recentAttempts as $attempt) {
            $createdAt = strtotime((string)($attempt['created_at'] ?? ''));
            if ($createdAt !== false && $createdAt >= $cutoffTimestamp) {
                $recentFailCount++;
            }
        }
    }

    if ($recentFailCount >= 15) {
        sendError('Too many failed login attempts. Please try again in 2 minutes.', 429);
    }

    $users = $supabase->select('users', ['email' => $email]);
    if (empty($users)) {
        $supabase->insert('activity_logs', [
            'action' => 'login_failed',
            'details' => 'User not found',
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s')
        ], true);
        sendError('Invalid email or password');
    }

    $user = $users[0];

    if (($user['status'] ?? 'active') !== 'active') {
        sendError('This account is suspended. Please contact the administrator.', 403);
    }

    try {
        $authResult = $supabase->signIn($email, $password);
    } catch (Exception $e) {
        $supabase->insert('activity_logs', [
            'user_id' => $user['user_id'] ?? null,
            'action' => 'login_failed',
            'details' => 'Supabase signIn failed: ' . $e->getMessage(),
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s')
        ], true);

        sendError('Invalid email or password');
    }

    if (!isset($authResult['access_token'])) {
        sendError('Login failed');
    }

    $firstLogin = (($user['role'] ?? '') === 'rider') && !empty($user['first_login']);
    $tempPassword = normalizeTempPhonePassword($user['phone'] ?? '');
    $isStillUsingTempPhonePassword = $tempPassword !== '' && trim((string)$password) === $tempPassword;

    if ($firstLogin && !$isStillUsingTempPhonePassword) {
        $supabase->update('users', [
            'first_login' => false,
            'last_login' => date('Y-m-d H:i:s')
        ], ['user_id' => $user['user_id']], true);
        $user['first_login'] = false;
    } else {
        $supabase->update('users', [
            'last_login' => date('Y-m-d H:i:s')
        ], ['user_id' => $user['user_id']], true);
    }

    $supabase->insert('activity_logs', [
        'user_id' => $user['user_id'],
        'action' => 'login',
        'ip_address' => $ip,
        'created_at' => date('Y-m-d H:i:s')
    ], true);

    sendSuccess([
        'token' => $authResult['access_token'],
        'refresh_token' => $authResult['refresh_token'] ?? null,
        'user' => [
            'user_id' => $user['user_id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'status' => $user['status'],
            'email_verified' => $user['email_verified'],
            'profile_photo' => $user['profile_photo'],
            'first_login' => (($user['role'] ?? '') === 'rider') ? (bool)($user['first_login'] ?? false) : false
        ]
    ], 'Login successful! Welcome back.');

} catch (Exception $e) {
    logError('Login Error: ' . $e->getMessage());
    sendError('Login failed. Please try again.', 500);
}
