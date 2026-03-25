<?php
require_once dirname(__DIR__, 2) . '/includes/config.php';

header('Content-Type: application/json');

function pg_json(int $code, bool $success, string $message, $data = null): void {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

function pg_now(): string {
    return date('Y-m-d H:i:s');
}

function pg_supabase_key(): string {
    if (defined('SUPABASE_SERVICE_KEY') && SUPABASE_SERVICE_KEY) return SUPABASE_SERVICE_KEY;
    if (defined('SUPABASE_ANON_KEY') && SUPABASE_ANON_KEY) return SUPABASE_ANON_KEY;
    return '';
}

function pg_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $header = $value;
                break;
            }
        }
    }
    if (preg_match('/Bearer\s+(.*)$/i', (string)$header, $m)) {
        return trim($m[1]);
    }
    return null;
}

function pg_request(string $method, string $path, ?array $payload = null, ?string $bearer = null, bool $preferRepresentation = false): array {
    $url = rtrim(SUPABASE_URL, '/') . $path;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . pg_supabase_key(),
        'Authorization: Bearer ' . ($bearer ?: pg_supabase_key()),
    ];
    if ($preferRepresentation) {
        $headers[] = 'Prefer: return=representation';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $error) {
        return ['status' => 0, 'data' => null, 'raw' => $raw ?: '', 'error' => $error ?: 'Network error'];
    }

    $decoded = json_decode($raw, true);
    return ['status' => $status, 'data' => $decoded, 'raw' => $raw, 'error' => null];
}

function pg_auth_user(string $token): ?array {
    $r = pg_request('GET', '/auth/v1/user', null, $token);
    if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && is_array($r['data'])) {
        return $r['data'];
    }
    return null;
}

function pg_find_user(array $authUser): ?array {
    $email = strtolower(trim((string)($authUser['email'] ?? '')));
    $authId = (string)($authUser['id'] ?? '');

    if ($authId !== '') {
        $r = pg_request('GET', '/rest/v1/users?select=user_id,full_name,email,phone,role,status,auth_user_id&auth_user_id=eq.' . rawurlencode($authId) . '&limit=1');
        if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && !empty($r['data'][0])) {
            return $r['data'][0];
        }
    }

    if ($email !== '') {
        $r = pg_request('GET', '/rest/v1/users?select=user_id,full_name,email,phone,role,status&email=eq.' . rawurlencode($email) . '&limit=1');
        if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && !empty($r['data'][0])) {
            return $r['data'][0];
        }
    }

    return null;
}

function pg_column_missing(?string $raw, string $column): bool {
    $raw = strtolower((string)$raw);
    return strpos($raw, strtolower("could not find the '{$column}' column")) !== false
        || strpos($raw, strtolower("column {$column}")) !== false;
}

function pg_infer_fulfillment(?string $deliveryAddress): string {
    $text = strtolower((string)$deliveryAddress);
    if (strpos($text, 'branch pickup') !== false || strpos($text, 'pickup schedule:') !== false) {
        return 'pickup';
    }
    return 'cod';
}

function pg_fetch_single_order(int $orderId, int $userId): ?array {
    $attempts = [
        'order_id,order_number,customer_id,order_status,updated_at,delivery_address,fulfillment_method',
        'order_id,order_number,customer_id,order_status,updated_at,delivery_address',
    ];

    foreach ($attempts as $select) {
        $resp = pg_request('GET', '/rest/v1/orders?select=' . rawurlencode($select) . '&order_id=eq.' . $orderId . '&customer_id=eq.' . $userId . '&limit=1');
        if (($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300 && !empty($resp['data'][0])) {
            return $resp['data'][0];
        }
        if (!pg_column_missing($resp['raw'] ?? '', 'fulfillment_method')) {
            break;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pg_json(405, false, 'Method not allowed');
}

$token = pg_bearer_token();
if (!$token) pg_json(401, false, 'Unauthorized');
$authUser = pg_auth_user($token);
if (!$authUser) pg_json(401, false, 'Invalid or expired session');
$appUser = pg_find_user($authUser);
if (!$appUser) pg_json(404, false, 'User record not found');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$orderId = (int)($input['order_id'] ?? 0);
if ($orderId <= 0) {
    pg_json(422, false, 'Invalid order id');
}

$row = pg_fetch_single_order($orderId, (int)$appUser['user_id']);
if (!$row) {
    pg_json(404, false, 'Order not found');
}

$status = strtolower((string)($row['order_status'] ?? ''));
$fulfillmentMethod = $row['fulfillment_method'] ?? pg_infer_fulfillment($row['delivery_address'] ?? null);
if ($status === 'cancelled') {
    pg_json(200, true, 'Order already cancelled', [
        'order_id' => (int)$row['order_id'],
        'order_number' => $row['order_number'],
        'order_status' => 'cancelled',
        'updated_at' => $row['updated_at'] ?? null,
        'fulfillment_method' => $fulfillmentMethod,
    ]);
}
if (!in_array($status, ['pending', 'preparing'], true)) {
    pg_json(400, false, 'Only pending or preparing orders can be cancelled');
}

$timestamp = pg_now();
$patch = [
    'order_status' => 'cancelled',
    'updated_at' => $timestamp,
    'cancelled_at' => $timestamp,
];
$updateResp = pg_request('PATCH', '/rest/v1/orders?order_id=eq.' . $orderId . '&customer_id=eq.' . (int)$appUser['user_id'], $patch, null, true);
if (($updateResp['status'] ?? 0) < 200 || ($updateResp['status'] ?? 0) >= 300) {
    $fallbackResp = pg_request('PATCH', '/rest/v1/orders?order_id=eq.' . $orderId . '&customer_id=eq.' . (int)$appUser['user_id'], [
        'order_status' => 'cancelled',
        'updated_at' => $timestamp,
    ], null, true);
    if (($fallbackResp['status'] ?? 0) < 200 || ($fallbackResp['status'] ?? 0) >= 300) {
        pg_json(500, false, 'Unable to cancel order');
    }
}

$final = pg_fetch_single_order($orderId, (int)$appUser['user_id']) ?: [
    'order_id' => $orderId,
    'order_number' => $row['order_number'] ?? '',
    'order_status' => 'cancelled',
    'updated_at' => $timestamp,
    'delivery_address' => $row['delivery_address'] ?? null,
];

pg_json(200, true, 'Order cancelled successfully', [
    'order_id' => (int)($final['order_id'] ?? $orderId),
    'order_number' => $final['order_number'] ?? ($row['order_number'] ?? ''),
    'order_status' => $final['order_status'] ?? 'cancelled',
    'updated_at' => $final['updated_at'] ?? $timestamp,
    'fulfillment_method' => $final['fulfillment_method'] ?? pg_infer_fulfillment($final['delivery_address'] ?? null),
]);
