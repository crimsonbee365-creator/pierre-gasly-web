<?php
/**
 * FORGOT PASSWORD - Send password reset OTP
 * POST /api/auth/forgot-password.php
 * 
 * Sends a 6-digit OTP to user's email for password reset
 * User will use this OTP to verify identity before setting new password
 */

require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$data = getJsonInput();
validateRequired($data, ['email']);

$email = sanitize($data['email']);

// Validate email format
if (!isValidEmail($email)) {
    sendError('Invalid email address');
}

try {
    global $supabase;
    
    // Check if user exists
    $users = $supabase->select('users', ['email' => $email]);
    
    if (empty($users)) {
        // Don't reveal if email exists or not (security)
        sendSuccess([], 'If an account exists with this email, you will receive a password reset code.');
        exit();
    }
    
    $user = $users[0];
    
    // Send OTP for password reset
    $otpResult = $supabase->sendOTP($email);
    
    // Log password reset request
    $supabase->insert('activity_logs', [
        'user_id' => $user['user_id'],
        'action' => 'password_reset_requested',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'created_at' => date('Y-m-d H:i:s')
    ], true);
    
    sendSuccess([
        'email' => $email
    ], 'Password reset code sent! Please check your email.');
    
} catch (Exception $e) {
    logError('Forgot Password Error: ' . $e->getMessage());
    sendError('Failed to send reset code. Please try again.', 500);
}
