<?php
/**
 * Pierre Gasly - API Configuration
 * Used by all mobile API endpoints
 * Supports Railway env vars with XAMPP fallback
 */

// CORS headers
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-store, no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

// ── Database ─────────────────────────────────────────────────────
define('DB_HOST',       getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',       getenv('DB_NAME')    ?: 'pierre_gasly');
define('DB_USER',       getenv('DB_USER')    ?: 'root');
define('DB_PASS',       getenv('DB_PASS')    ?: '');
define('DB_PORT',       getenv('DB_PORT')    ?: '3306');
define('JWT_SECRET',    getenv('JWT_SECRET') ?: 'PierreGasly_JWT_S3cr3t_2025!');
define('API_SITE_URL',  getenv('APP_URL')    ?: 'http://localhost/pierre-gasly-admin/');
define('JWT_EXPIRY',    86400); // 24 hours

// ── DB connection ─────────────────────────────────────────────────
function getConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── Response helpers ──────────────────────────────────────────────
function sendSuccess($data, string $message = 'Success', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit();
}

function sendError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'data' => null]);
    exit();
}

function logError(string $msg): void {
    error_log('[PGAS API] ' . $msg);
}

// ── Input parsing ─────────────────────────────────────────────────
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ── Sanitize ──────────────────────────────────────────────────────
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidPhone(string $phone): bool {
    // Philippine format: 09XXXXXXXXX (11 digits)
    return preg_match('/^09\d{9}$/', $phone) === 1;
}

function validateRequired(array $data, array $required): void {
    foreach ($required as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            sendError("Field '$field' is required");
        }
    }
}

function generateOTP(int $length = 6): string {
    return str_pad((string)random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
}

// ── JWT ───────────────────────────────────────────────────────────
function base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function generateJWT(int $userId, string $email, string $role): string {
    $header  = base64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = base64url(json_encode([
        'user_id' => $userId,
        'email'   => $email,
        'role'    => $role,
        'iat'     => time(),
        'exp'     => time() + JWT_EXPIRY,
    ]));
    $sig = base64url(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function verifyJWT(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = base64url(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    if (!$data || $data['exp'] < time()) return null;
    return $data;
}

function getAuthUser(): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) sendError('Unauthorized', 401);
    $token = substr($auth, 7);
    $data  = verifyJWT($token);
    if (!$data) sendError('Invalid or expired token', 401);
    return $data;
}

// ── OTP table (only called when needed) ───────────────────────────
function ensureOtpTable(): void {
    try {
        getConnection()->exec("
            CREATE TABLE IF NOT EXISTS otp_codes (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                phone       VARCHAR(20) NOT NULL,
                otp_code    VARCHAR(10) NOT NULL,
                expires_at  DATETIME    NOT NULL,
                verified    TINYINT(1)  DEFAULT 0,
                created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
                INDEX (phone),
                INDEX (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        logError('ensureOtpTable: ' . $e->getMessage());
    }
}
