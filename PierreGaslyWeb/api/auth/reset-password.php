<?php
/**
 * RESET PASSWORD - Verify OTP and set new password
 * POST /api/auth/reset-password.php
 * 
 * Verifies the OTP code and updates user's password
 */

require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$data = getJsonInput();
validateRequired($data, ['email', 'otp', 'new_password']);

$email = sanitize($data['email']);
$otp = sanitize($data['otp']);
$newPassword = $data['new_password'];

// Validate email
if (!isValidEmail($email)) {
    sendError('Invalid email address');
}

// Validate OTP (6 digits)
if (strlen($otp) !== 6 || !ctype_digit($otp)) {
    sendError('OTP must be 6 digits');
}

// Validate new password strength
if (strlen($newPassword) < 8) {
    sendError('Password must be at least 8 characters');
}
if (!preg_match('/[A-Z]/', $newPassword)) {
    sendError('Password needs an uppercase letter');
}
if (!preg_match('/[a-z]/', $newPassword)) {
    sendError('Password needs a lowercase letter');
}
if (!preg_match('/[0-9]/', $newPassword)) {
    sendError('Password needs a number');
}
if (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
    sendError('Password needs a special character');
}
if (strpos($newPassword, ' ') !== false) {
    sendError('Password cannot contain spaces');
}

try {
    global $supabase;
    
    // Verify OTP first
    $authResult = $supabase->verifyOTP($email, $otp);
    
    if (!isset($authResult['access_token'])) {
        sendError('Invalid or expired OTP code');
    }
    
    // Update password in database
    $passwordHash = hashPassword($newPassword);
    $supabase->update('users', [
        'password_hash' => $passwordHash
    ], ['email' => $email], true);
    
    // Get user data
    $users = $supabase->select('users', ['email' => $email]);
    
    if (!empty($users)) {
        $user = $users[0];
        
        // Log password reset
        $supabase->insert('activity_logs', [
            'user_id' => $user['user_id'],
            'action' => 'password_reset',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'created_at' => date('Y-m-d H:i:s')
        ], true);
    }
    
    sendSuccess([
        'message' => 'Password updated successfully!'
    ], 'Password has been reset. You can now login with your new password.');
    
} catch (Exception $e) {
    logError('Reset Password Error: ' . $e->getMessage());
    sendError('Failed to reset password. Please try again.', 500);
}
