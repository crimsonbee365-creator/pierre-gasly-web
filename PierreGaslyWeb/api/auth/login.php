<?php
/**
 * LOGIN - User authentication
 * POST /api/auth/login.php
 * 
 * Authenticates user with email and password
 * Returns JWT token for subsequent requests
 */

require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$data = getJsonInput();
$email = sanitize($data['email'] ?? '');
$password = $data['password'] ?? '';

// Validate email
if (!isValidEmail($email)) {
    sendError('Invalid email address');
}

// Validate password
if (strlen($password) < 8) {
    sendError('Invalid password');
}

try {
    global $supabase;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Rate limiting: Check failed login attempts
    $recentAttempts = $supabase->select('activity_logs', [
        'action' => 'login_failed',
        'ip_address' => $ip
    ], 'log_id', true);
    
    if (count($recentAttempts) >= 5) {
        sendError('Too many failed login attempts. Please try again later.', 429);
    }
    
    // Attempt Supabase Auth sign in
    try {
        $authResult = $supabase->signIn($email, $password);
    } catch (Exception $e) {
        // Log failed attempt
        $supabase->insert('activity_logs', [
            'action' => 'login_failed',
            'details' => 'Invalid credentials',
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s')
        ], true);
        
        sendError('Invalid email or password');
    }
    
    if (!isset($authResult['access_token'])) {
        sendError('Login failed');
    }
    
    // Get user data from database
    $users = $supabase->select('users', ['email' => $email]);
    
    if (empty($users)) {
        sendError('User not found');
    }
    
    $user = $users[0];
    
    // Check if user account is active
    if ($user['status'] !== 'active') {
        sendError('Your account has been ' . $user['status']);
    }
    
    // Update last login timestamp
    $supabase->update('users', [
        'last_login' => date('Y-m-d H:i:s')
    ], ['user_id' => $user['user_id']], true);
    
    // Log successful login
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
            'first_login' => $user['first_login']
        ]
    ], 'Login successful! Welcome back.');
    
} catch (Exception $e) {
    logError('Login Error: ' . $e->getMessage());
    sendError('Login failed. Please try again.', 500);
}
