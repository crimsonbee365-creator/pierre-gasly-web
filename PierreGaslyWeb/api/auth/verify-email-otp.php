<?php
/**
 * VERIFY EMAIL OTP - Verify 6-digit code sent to email
 * POST /api/auth/verify-email-otp.php
 * 
 * Verifies the OTP code sent by Supabase Auth
 * Marks user's email as verified and logs them in
 */

require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$data = getJsonInput();
validateRequired($data, ['email', 'otp']);

$email = sanitize($data['email']);
$otp = sanitize($data['otp']);

// Validate email format
if (!isValidEmail($email)) {
    sendError('Invalid email address');
}

// Validate OTP format (6 digits)
if (strlen($otp) !== 6 || !ctype_digit($otp)) {
    sendError('OTP must be 6 digits');
}

try {
    global $supabase;
    
    // Verify OTP with Supabase Auth
    $authResult = $supabase->verifyOTP($email, $otp);
    
    if (!isset($authResult['access_token'])) {
        sendError('Invalid or expired OTP code');
    }
    
    // Update user record - mark email as verified
    $supabase->update('users', [
        'email_verified' => true,
        'last_login' => date('Y-m-d H:i:s')
    ], ['email' => $email], true);
    
    // Get full user data from database
    $users = $supabase->select('users', ['email' => $email]);
    
    if (empty($users)) {
        sendError('User not found in database');
    }
    
    $user = $users[0];
    
    // Log email verification
    $supabase->insert('activity_logs', [
        'user_id' => $user['user_id'],
        'action' => 'email_verified',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
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
            'email_verified' => true,
            'profile_photo' => $user['profile_photo']
        ]
    ], 'Email verified successfully! Welcome to Pierre Gasly.');
    
} catch (Exception $e) {
    logError('Verify OTP Error: ' . $e->getMessage());
    sendError('Verification failed. Please check your code and try again.', 400);
}
