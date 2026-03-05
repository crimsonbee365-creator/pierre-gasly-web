<?php
/**
 * SEND EMAIL OTP - Send or resend 6-digit verification code
 * POST /api/auth/send-email-otp.php
 * 
 * Sends a new OTP code to the user's email
 * Used for: Resending verification codes, Password reset
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
    
    // Check if user exists in database
    $users = $supabase->select('users', ['email' => $email]);
    
    if (empty($users)) {
        sendError('No account found with this email address');
    }
    
    $user = $users[0];
    
    // Send OTP via Supabase Auth
    $otpResult = $supabase->sendOTP($email);
    
    // Log OTP request
    $supabase->insert('activity_logs', [
        'user_id' => $user['user_id'],
        'action' => 'otp_requested',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'created_at' => date('Y-m-d H:i:s')
    ], true);
    
    sendSuccess([
        'email' => $email,
        'expires_in' => 600 // 10 minutes
    ], 'Verification code sent! Please check your email inbox.');
    
} catch (Exception $e) {
    logError('Send OTP Error: ' . $e->getMessage());
    sendError('Failed to send verification code. Please try again.', 500);
}
