<?php
/**
 * REGISTER - Create new user account
 * POST /api/auth/register.php
 * 
 * Flow:
 * 1. Validate user input
 * 2. Create user in Supabase Auth (sends OTP automatically)
 * 3. Store user in database
 * 4. User verifies email with OTP code
 */

require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

$data = getJsonInput();
$fullName = sanitize($data['full_name'] ?? '');
$email = sanitize($data['email'] ?? '');
$phone = sanitize($data['phone'] ?? '');
$password = $data['password'] ?? '';

// Validate full name
if (strlen($fullName) < 2) {
    sendError('Name must be at least 2 characters');
}
if (preg_match('/[0-9]/', $fullName)) {
    sendError('Name cannot contain numbers');
}
if (!preg_match("/^[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ '.,-]*$/u", $fullName)) {
    sendError('Name contains invalid characters');
}

// Validate email
if (!isValidEmail($email)) {
    sendError('Invalid email address');
}

// Validate phone (optional)
if (!empty($phone)) {
    $phone = ltrim($phone, '0');
    if (!preg_match('/^9[0-9]{9}$/', $phone)) {
        sendError('Invalid phone. Enter 10 digits starting with 9');
    }
    $phone = '0' . $phone;
}

// Validate password strength
if (strlen($password) < 8) {
    sendError('Password must be at least 8 characters');
}
if (!preg_match('/[A-Z]/', $password)) {
    sendError('Password needs an uppercase letter');
}
if (!preg_match('/[a-z]/', $password)) {
    sendError('Password needs a lowercase letter');
}
if (!preg_match('/[0-9]/', $password)) {
    sendError('Password needs a number');
}
if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
    sendError('Password needs a special character');
}
if (strpos($password, ' ') !== false) {
    sendError('Password cannot contain spaces');
}

try {
    global $supabase;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Rate limiting: Check registration attempts
    $recentRegistrations = $supabase->select('activity_logs', [
        'action' => 'register',
        'ip_address' => $ip
    ], 'log_id', true);
    
    if (count($recentRegistrations) >= 5) {
        sendError('Too many registrations. Try again later.', 429);
    }
    
    // Check if email already exists
    $existingUser = $supabase->select('users', ['email' => $email], 'user_id');
    if (!empty($existingUser)) {
        sendError('This email is already registered');
    }
    
    // Check if phone already exists
    if (!empty($phone)) {
        $existingPhone = $supabase->select('users', ['phone' => $phone], 'user_id');
        if (!empty($existingPhone)) {
            sendError('This phone number is already registered');
        }
    }
    
    // Create user in Supabase Auth (sends OTP email automatically)
    $authResult = $supabase->signUp($email, $password, [
        'full_name' => $fullName,
        'phone' => $phone,
        'role' => 'customer'
    ]);
    
    if (!isset($authResult['user']) || !isset($authResult['user']['id'])) {
        throw new Exception('Failed to create user in Supabase Auth');
    }
    
    // Create user in our database
    $userData = [
        'email' => $email,
        'password_hash' => hashPassword($password),
        'full_name' => $fullName,
        'phone' => $phone,
        'role' => 'customer',
        'status' => 'active',
        'email_verified' => false,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $newUser = $supabase->insert('users', $userData, true);
    
    if (empty($newUser) || !isset($newUser[0]['user_id'])) {
        throw new Exception('Failed to create user in database');
    }
    
    $userId = $newUser[0]['user_id'];
    
    // Log registration activity
    $supabase->insert('activity_logs', [
        'user_id' => $userId,
        'action' => 'register',
        'ip_address' => $ip,
        'created_at' => date('Y-m-d H:i:s')
    ], true);
    
    // Initialize user rewards
    $supabase->insert('user_rewards', [
        'user_id' => $userId,
        'total_points' => 0,
        'redeemed_points' => 0,
        'tier' => 'Bronze',
        'created_at' => date('Y-m-d H:i:s')
    ], true);
    
    sendSuccess([
        'user' => [
            'user_id' => $userId,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role' => 'customer',
            'status' => 'active',
            'email_verified' => false
        ],
        'message' => 'Registration successful! Please check your email for the 6-digit verification code.'
    ], 'Registration successful! Check your email for verification code.');
    
} catch (Exception $e) {
    logError('Register Error: ' . $e->getMessage());
    sendError('Registration failed: ' . $e->getMessage(), 500);
}
