<?php
/**
 * REGISTER - Create new user account
 * POST /api/auth/register.php
 *
 * Flow:
 * 1. Validate user input
 * 2. Check duplicate email/phone in local users table
 * 3. Check duplicate email in Supabase Auth (service key)
 * 4. Create user in Supabase Auth (sends OTP automatically)
 * 5. Store user in local database
 * 6. User verifies email with OTP code
 */

require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

function normalizePhoneForStorage($rawPhone) {
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

    return null;
}

function phoneVariants($normalizedPhone) {
    if ($normalizedPhone === '') {
        return [];
    }

    $tenDigit = substr($normalizedPhone, 1);

    $variants = [
        $normalizedPhone,
        $tenDigit,
        '63' . $tenDigit,
        '+63' . $tenDigit,
    ];

    return array_values(array_unique(array_filter($variants)));
}

function findDuplicatePhoneUser($supabase, $normalizedPhone) {
    foreach (phoneVariants($normalizedPhone) as $variant) {
        $match = $supabase->select('users', ['phone' => $variant], 'user_id,email_verified,phone', true);
        if (!empty($match)) {
            return $match[0];
        }
    }

    return null;
}

function authUserExistsByEmail($email) {
    $endpoint = rtrim(SUPABASE_URL, '/') . '/auth/v1/admin/users?email=' . urlencode($email);

    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        throw new Exception('Unable to check authentication records');
    }

    if ($httpCode >= 400) {
        throw new Exception('Supabase Auth duplicate check failed');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new Exception('Invalid response from Supabase Auth duplicate check');
    }

    $users = [];
    if (isset($decoded['users']) && is_array($decoded['users'])) {
        $users = $decoded['users'];
    } elseif (isset($decoded[0])) {
        $users = $decoded;
    }

    foreach ($users as $user) {
        if (!empty($user['email']) && strtolower((string)$user['email']) === strtolower($email)) {
            return true;
        }
    }

    return false;
}

function authResponseLooksLikeDuplicate($authResult, $email) {
    $candidates = [];

    foreach (['error_description', 'msg', 'message'] as $key) {
        if (isset($authResult[$key]) && is_string($authResult[$key])) {
            $candidates[] = $authResult[$key];
        }
    }

    if (isset($authResult['error']['message']) && is_string($authResult['error']['message'])) {
        $candidates[] = $authResult['error']['message'];
    }

    foreach ($candidates as $message) {
        if (
            stripos($message, 'already registered') !== false ||
            stripos($message, 'already exists') !== false ||
            stripos($message, 'user already registered') !== false ||
            stripos($message, 'duplicate') !== false
        ) {
            return true;
        }
    }

    if (!empty($authResult['user']) && is_array($authResult['user'])) {
        $authUser = $authResult['user'];
        $authEmail = strtolower((string)($authUser['email'] ?? ''));
        $identities = $authUser['identities'] ?? null;

        if ($authEmail === strtolower($email) && is_array($identities) && count($identities) === 0) {
            return true;
        }
    }

    return false;
}

$data = getJsonInput();

$fullName = sanitize($data['full_name'] ?? '');
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');
$birthDate = sanitize($data['birth_date'] ?? '');
$isAgeVerified = !empty($data['is_age_verified']) || !empty($data['age_verified']);

$rawPhone = trim((string)($data['phone'] ?? ''));
$phone = normalizePhoneForStorage($rawPhone);

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
if ($rawPhone !== '' && $phone === null) {
    sendError('Invalid phone number. Use 09XXXXXXXXX');
}

// Validate birth date / age
if (!$birthDate) {
    sendError('Birth date is required');
}

try {
    $dob = new DateTime($birthDate);
    $today = new DateTime('today');
    $age = $dob->diff($today)->y;

    if ($age < 18 || !$isAgeVerified) {
        sendError('Registration is only available for users 18 years old and above.');
    }
} catch (Exception $e) {
    sendError('Invalid birth date');
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

    // Optional rate limiting via activity logs
    $recentRegistrations = $supabase->select(
        'activity_logs',
        [
            'action' => 'register',
            'ip_address' => $ip
        ],
        'log_id',
        true
    );

    if (is_array($recentRegistrations) && count($recentRegistrations) >= 5) {
        sendError('Too many registrations. Try again later.', 429);
    }

    // Check duplicate email in local users table
    $existingUser = $supabase->select(
        'users',
        ['email' => $email],
        'user_id,email_verified',
        true
    );

    if (!empty($existingUser)) {
        $existing = $existingUser[0];
        if (!empty($existing['email_verified'])) {
            sendError('This email is already registered', 409);
        } else {
            sendError('This email is already pending verification', 409);
        }
    }

    // Check duplicate phone in local users table using common variants
    if ($phone !== '') {
        $existingPhone = findDuplicatePhoneUser($supabase, $phone);

        if (!empty($existingPhone)) {
            if (!empty($existingPhone['email_verified'])) {
                sendError('This phone number is already registered', 409);
            } else {
                sendError('This phone number is already pending verification', 409);
            }
        }
    }

    // Check duplicate email directly in Supabase Auth before signup
    if (authUserExistsByEmail($email)) {
        sendError('This email is already registered', 409);
    }

    // Create user in Supabase Auth
    $authResult = $supabase->signUp($email, $password, [
        'full_name' => $fullName,
        'phone' => $phone,
        'role' => 'customer',
        'birth_date' => $birthDate,
        'age_verified' => true
    ]);

    if (!is_array($authResult)) {
        throw new Exception('Invalid response from Supabase Auth');
    }

    if (authResponseLooksLikeDuplicate($authResult, $email)) {
        sendError('This email is already registered', 409);
    }

    // Double-check local duplicates before insert
    $existingUserAfterAuth = $supabase->select('users', ['email' => $email], 'user_id', true);
    if (!empty($existingUserAfterAuth)) {
        sendError('This email is already registered', 409);
    }

    if ($phone !== '') {
        $existingPhoneAfterAuth = findDuplicatePhoneUser($supabase, $phone);
        if (!empty($existingPhoneAfterAuth)) {
            sendError('This phone number is already registered', 409);
        }
    }

    // Create user in local database
    $userData = [
        'email' => $email,
        'password_hash' => hashPassword($password),
        'full_name' => $fullName,
        'phone' => $phone !== '' ? $phone : null,
        'role' => 'customer',
        'birth_date' => $birthDate,
        'is_age_verified' => true,
        'status' => 'active',
        'first_login' => false,
        'email_verified' => false,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $newUser = $supabase->insert('users', $userData, true);

    if (empty($newUser) || !isset($newUser[0]['user_id'])) {
        throw new Exception('Failed to create user in database');
    }

    $userId = $newUser[0]['user_id'];

    // Log activity
    $supabase->insert('activity_logs', [
        'user_id' => $userId,
        'action' => 'register',
        'ip_address' => $ip,
        'created_at' => date('Y-m-d H:i:s')
    ], true);

    // Initialize rewards
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
            'birth_date' => $birthDate,
            'is_age_verified' => true,
            'email_verified' => false
        ],
        'message' => 'Registration successful! Please check your email for the 6-digit verification code.'
    ], 'Registration successful! Check your email for verification code.');

} catch (Exception $e) {
    logError('Register Error: ' . $e->getMessage());

    $message = $e->getMessage();

    if (
        stripos($message, 'User already registered') !== false ||
        stripos($message, 'already registered') !== false ||
        stripos($message, 'already exists') !== false ||
        stripos($message, 'duplicate') !== false
    ) {
        sendError('This email is already registered', 409);
    }

    sendError('Registration failed: ' . $message, 500);
}
