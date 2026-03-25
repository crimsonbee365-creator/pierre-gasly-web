<?php
/**
 * PIERRE GASLY - SUPABASE CONFIGURATION
 * Database: PostgreSQL on Supabase
 * Authentication: Supabase Auth with Email OTP
 * Version: 2.0
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);


// --- Supabase configuration ---
// IMPORTANT: Never commit your Supabase keys.
// Set env vars on the server: SUPABASE_URL, SUPABASE_ANON_KEY, SUPABASE_SERVICE_KEY

$__localCfg = __DIR__ . '/config.local.php';
if (is_file($__localCfg)) {
    require_once $__localCfg;
}

$__supabaseUrl = getenv('SUPABASE_URL') ?: (defined('SUPABASE_URL') ? SUPABASE_URL : null);
$__supabaseAnon = getenv('SUPABASE_ANON_KEY') ?: (defined('SUPABASE_ANON_KEY') ? SUPABASE_ANON_KEY : null);
$__supabaseService = getenv('SUPABASE_SERVICE_KEY') ?: (defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : null);

if (!$__supabaseUrl || !$__supabaseAnon || !$__supabaseService) {
    http_response_code(500);
    echo json_encode(['error' => 'Missing Supabase configuration']);
    exit;
}

define('SUPABASE_URL', $__supabaseUrl);
define('SUPABASE_ANON_KEY', $__supabaseAnon);
define('SUPABASE_SERVICE_KEY', $__supabaseService);
unset($__localCfg, $__supabaseUrl, $__supabaseAnon, $__supabaseService);


// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// =============================================================================
// SUPABASE CREDENTIALS
// =============================================================================


// JWT Secret (fallback for custom tokens)
define('JWT_SECRET', 'pierre-gasly-secret-2026');
define('JWT_EXPIRY', 86400); // 24 hours

// =============================================================================
// SUPABASE API CLIENT
// =============================================================================

class SupabaseClient {
    private $url;
    private $apiKey;
    private $serviceKey;
    
    public function __construct() {
        $this->url = SUPABASE_URL;
        $this->apiKey = SUPABASE_ANON_KEY;
        $this->serviceKey = SUPABASE_SERVICE_KEY;
    }
    
    /**
     * SELECT: Query records from a table
     */
    public function select($table, $filters = [], $select = '*', $useServiceKey = false) {
        $endpoint = $this->url . '/rest/v1/' . $table . '?select=' . $select;
        
        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                $endpoint .= '&' . $key . '=eq.' . urlencode($value);
            }
        }
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . ($useServiceKey ? $this->serviceKey : $this->apiKey),
            'Authorization: Bearer ' . ($useServiceKey ? $this->serviceKey : $this->apiKey)
        ];
        
        return $this->executeCurl($endpoint, 'GET', null, $headers);
    }
    
    /**
     * INSERT: Add new records to a table
     */
    public function insert($table, $data, $useServiceKey = false) {
        $endpoint = $this->url . '/rest/v1/' . $table;
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . ($useServiceKey ? $this->serviceKey : $this->apiKey),
            'Authorization: Bearer ' . ($useServiceKey ? $this->serviceKey : $this->apiKey),
            'Prefer: return=representation'
        ];
        
        return $this->executeCurl($endpoint, 'POST', $data, $headers);
    }
    
    /**
     * UPDATE: Modify existing records
     */
    public function update($table, $data, $filters, $useServiceKey = false) {
        $endpoint = $this->url . '/rest/v1/' . $table . '?';
        
        $filterParts = [];
        foreach ($filters as $key => $value) {
            $filterParts[] = $key . '=eq.' . urlencode($value);
        }
        $endpoint .= implode('&', $filterParts);
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . ($useServiceKey ? $this->serviceKey : $this->apiKey),
            'Authorization: Bearer ' . ($useServiceKey ? $this->serviceKey : $this->apiKey),
            'Prefer: return=representation'
        ];
        
        return $this->executeCurl($endpoint, 'PATCH', $data, $headers);
    }
    
    /**
     * DELETE: Remove records from a table
     */
    public function delete($table, $filters, $useServiceKey = false) {
        $endpoint = $this->url . '/rest/v1/' . $table . '?';
        
        $filterParts = [];
        foreach ($filters as $key => $value) {
            $filterParts[] = $key . '=eq.' . urlencode($value);
        }
        $endpoint .= implode('&', $filterParts);
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . ($useServiceKey ? $this->serviceKey : $this->apiKey),
            'Authorization: Bearer ' . ($useServiceKey ? $this->serviceKey : $this->apiKey)
        ];
        
        return $this->executeCurl($endpoint, 'DELETE', null, $headers);
    }
    
    /**
     * SIGN UP: Register new user with email
     */
    public function signUp($email, $password, $metadata = []) {
        $endpoint = $this->url . '/auth/v1/signup';
        
        $data = [
            'email' => $email,
            'password' => $password,
            'data' => $metadata
        ];
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey
        ];
        
        return $this->executeCurl($endpoint, 'POST', $data, $headers);
    }
    
    /**
     * SIGN IN: Login with email and password
     */
    public function signIn($email, $password) {
        $endpoint = $this->url . '/auth/v1/token?grant_type=password';
        
        $data = [
            'email' => $email,
            'password' => $password
        ];
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey
        ];
        
        return $this->executeCurl($endpoint, 'POST', $data, $headers);
    }
    
    /**
     * SEND OTP: Send 6-digit verification code to email
     */
    public function sendOTP($email) {
        $endpoint = $this->url . '/auth/v1/otp';
        
        $data = [
            'email' => $email,
            'create_user' => false
        ];
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey
        ];
        
        return $this->executeCurl($endpoint, 'POST', $data, $headers);
    }
    
    /**
     * VERIFY OTP: Verify email OTP code
     */
    public function verifyOTP($email, $token) {
        $endpoint = $this->url . '/auth/v1/verify';
        
        $data = [
            'type' => 'email',
            'email' => $email,
            'token' => $token
        ];
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey
        ];
        
        return $this->executeCurl($endpoint, 'POST', $data, $headers);
    }
    
    /**
     * GET USER: Get authenticated user details
     */
    public function getUser($token) {
        $endpoint = $this->url . '/auth/v1/user';
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey,
            'Authorization: Bearer ' . $token
        ];
        
        return $this->executeCurl($endpoint, 'GET', null, $headers);
    }
    
    /**
     * Execute CURL request
     */
    private function executeCurl($url, $method, $data, $headers) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception('Supabase API Error: ' . $response);
        }
        
        return json_decode($response, true);
    }
}

// Initialize global Supabase client
$supabase = new SupabaseClient();

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get JSON input from request body
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

/**
 * Send success response
 */
function sendSuccess($data = [], $message = 'Success', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Send error response
 */
function sendError($message = 'Error', $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit();
}

/**
 * Sanitize user input
 */
function sanitize($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

/**
 * Validate email format
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate Philippine phone format (09XXXXXXXXX)
 */
function isValidPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return preg_match('/^(09|9)[0-9]{9}$/', $phone);
}

/**
 * Generate 6-digit OTP
 */
function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Validate required fields exist
 */
function validateRequired($data, $fields) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            sendError("Missing required field: $field", 400);
        }
    }
}

/**
 * Hash password securely
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verify password against hash
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Get authorization token from headers
 */
function getAuthToken() {
    $headers = getallheaders();
    
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            return $matches[1];
        }
    }
    
    return null;
}

/**
 * Require authentication - verify token
 */
function requireAuth() {
    global $supabase;
    
    $token = getAuthToken();
    
    if (!$token) {
        sendError('Authentication required', 401);
    }
    
    try {
        $user = $supabase->getUser($token);
        return $user;
    } catch (Exception $e) {
        sendError('Invalid or expired token', 401);
    }
}

/**
 * Log errors to file
 */
function logError($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, __DIR__ . '/error.log');
}
