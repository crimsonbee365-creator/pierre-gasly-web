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

function pg_request(string $method, string $path, ?array $payload = null, ?string $bearer = null): array {
    $url = rtrim(SUPABASE_URL, '/') . $path;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . pg_supabase_key(),
        'Authorization: Bearer ' . ($bearer ?: pg_supabase_key()),
    ];

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

function pg_try_find_user_by_filter(array $filters, string $select = 'user_id,full_name,email,phone,role,status,auth_user_id'): ?array {
    $query = '/rest/v1/users?select=' . rawurlencode($select);
    foreach ($filters as $column => $value) {
        $query .= '&' . rawurlencode($column) . '=eq.' . rawurlencode((string)$value);
    }
    $query .= '&limit=1';
    $r = pg_request('GET', $query);
    if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && !empty($r['data'][0])) {
        return $r['data'][0];
    }
    return null;
}

function pg_find_user(array $authUser): ?array {
    $email = strtolower(trim((string)($authUser['email'] ?? '')));
    $authId = trim((string)($authUser['id'] ?? ''));

    if ($authId !== '') {
        $user = pg_try_find_user_by_filter(['auth_user_id' => $authId]);
        if ($user) return $user;
    }

    if ($email !== '') {
        $user = pg_try_find_user_by_filter(['email' => $email]);
        if ($user) return $user;

        $r = pg_request('GET', '/rest/v1/users?select=' . rawurlencode('user_id,full_name,email,phone,role,status') . '&email=not.is.null');
        if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && is_array($r['data'])) {
            foreach ($r['data'] as $row) {
                if (strtolower(trim((string)($row['email'] ?? ''))) === $email) {
                    return $row;
                }
            }
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

function pg_extract_contact_number(?string $deliveryAddress): ?string {
    $address = (string)$deliveryAddress;
    if (preg_match('/Contact\s*Number\s*:\s*([^
]+)/i', $address, $m)) {
        return trim($m[1]);
    }
    return null;
}

function pg_fetch_orders(int $userId): array {
    $attempts = [
        'order_id,order_number,product_id,quantity,total_amount,delivery_address,payment_method,fulfillment_method,order_status,ordered_at,updated_at,contact_number',
        'order_id,order_number,product_id,quantity,total_amount,delivery_address,payment_method,fulfillment_method,order_status,ordered_at,updated_at',
        'order_id,order_number,product_id,quantity,total_amount,delivery_address,payment_method,order_status,ordered_at,updated_at',
    ];

    foreach ($attempts as $select) {
        $resp = pg_request('GET', '/rest/v1/orders?select=' . rawurlencode($select) . '&customer_id=eq.' . $userId . '&order=updated_at.desc.nullslast,order_id.desc');
        if (($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300) {
            return is_array($resp['data']) ? $resp['data'] : [];
        }
        if (!pg_column_missing($resp['raw'] ?? '', 'fulfillment_method') && !pg_column_missing($resp['raw'] ?? '', 'contact_number')) {
            break;
        }
    }

    return [];
}

function pg_fetch_order_reviews(array $orderIds): array {
    if (empty($orderIds)) return [];
    $idList = implode(',', array_map('intval', array_keys($orderIds)));

    $attempts = [
        [
            'path' => '/rest/v1/reviews?select=' . rawurlencode('order_id,rating,comment,created_at,updated_at') . '&order_id=in.(' . $idList . ')',
            'map' => static function(array $row): array {
                return [
                    'order_id' => (int)($row['order_id'] ?? 0),
                    'rating' => (int)($row['rating'] ?? 0),
                    'feedback' => isset($row['comment']) ? trim((string)$row['comment']) : '',
                    'rated_at' => $row['updated_at'] ?? $row['created_at'] ?? null,
                ];
            }
        ],
        [
            'path' => '/rest/v1/ratings?select=' . rawurlencode('order_id,rating,feedback,created_at,updated_at') . '&order_id=in.(' . $idList . ')',
            'map' => static function(array $row): array {
                return [
                    'order_id' => (int)($row['order_id'] ?? 0),
                    'rating' => (int)($row['rating'] ?? 0),
                    'feedback' => isset($row['feedback']) ? trim((string)$row['feedback']) : '',
                    'rated_at' => $row['updated_at'] ?? $row['created_at'] ?? null,
                ];
            }
        ],
    ];

    foreach ($attempts as $attempt) {
        $resp = pg_request('GET', $attempt['path']);
        if (($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300 && is_array($resp['data'])) {
            $reviewMap = [];
            foreach ($resp['data'] as $row) {
                $mapped = $attempt['map']($row);
                if (($mapped['order_id'] ?? 0) > 0) {
                    $reviewMap[$mapped['order_id']] = [
                        'rating' => $mapped['rating'],
                        'feedback' => $mapped['feedback'] !== '' ? $mapped['feedback'] : null,
                        'rated_at' => $mapped['rated_at'],
                    ];
                }
            }
            return $reviewMap;
        }
    }

    return [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    pg_json(405, false, 'Method not allowed');
}

$token = pg_bearer_token();
if (!$token) pg_json(401, false, 'Unauthorized');
$authUser = pg_auth_user($token);
if (!$authUser) pg_json(401, false, 'Invalid or expired session');
$appUser = pg_find_user($authUser);
if (!$appUser) pg_json(404, false, 'User record not found');

$orders = pg_fetch_orders((int)$appUser['user_id']);
$productIds = [];
$orderIds = [];
foreach ($orders as $row) {
    if (!empty($row['product_id'])) $productIds[(int)$row['product_id']] = true;
    if (!empty($row['order_id'])) $orderIds[(int)$row['order_id']] = true;
}

$productMap = [];
$brandIds = [];
if (!empty($productIds)) {
    $idList = implode(',', array_keys($productIds));
    $productsResp = pg_request('GET', '/rest/v1/products?select=product_id,product_name,size_kg,brand_id&product_id=in.(' . $idList . ')');
    if (($productsResp['status'] ?? 0) >= 200 && ($productsResp['status'] ?? 0) < 300 && is_array($productsResp['data'])) {
        foreach ($productsResp['data'] as $p) {
            $productMap[(int)$p['product_id']] = $p;
            if (!empty($p['brand_id'])) $brandIds[(int)$p['brand_id']] = true;
        }
    }
}

$brandMap = [];
if (!empty($brandIds)) {
    $brandList = implode(',', array_keys($brandIds));
    $brandsResp = pg_request('GET', '/rest/v1/brands?select=brand_id,brand_name&brand_id=in.(' . $brandList . ')');
    if (($brandsResp['status'] ?? 0) >= 200 && ($brandsResp['status'] ?? 0) < 300 && is_array($brandsResp['data'])) {
        foreach ($brandsResp['data'] as $b) {
            $brandMap[(int)$b['brand_id']] = $b['brand_name'] ?? null;
        }
    }
}

$reviewMap = pg_fetch_order_reviews($orderIds);

$out = [];
foreach ($orders as $row) {
    $orderId = (int)($row['order_id'] ?? 0);
    $product = $productMap[(int)($row['product_id'] ?? 0)] ?? null;
    $brandName = null;
    if ($product && !empty($product['brand_id'])) {
        $brandName = $brandMap[(int)$product['brand_id']] ?? null;
    }
    $deliveryAddress = $row['delivery_address'] ?? null;
    $rating = $reviewMap[$orderId] ?? null;
    $out[] = [
        'order_id' => $orderId,
        'order_number' => (string)($row['order_number'] ?? ''),
        'quantity' => (int)($row['quantity'] ?? 0),
        'total_amount' => is_numeric($row['total_amount'] ?? null) ? (float)$row['total_amount'] : 0.0,
        'delivery_address' => $deliveryAddress,
        'payment_method' => $row['payment_method'] ?? null,
        'fulfillment_method' => $row['fulfillment_method'] ?? pg_infer_fulfillment($deliveryAddress),
        'contact_number' => $row['contact_number'] ?? pg_extract_contact_number($deliveryAddress),
        'order_status' => $row['order_status'] ?? null,
        'ordered_at' => $row['ordered_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'product_name' => $product['product_name'] ?? null,
        'size_kg' => isset($product['size_kg']) ? (int)$product['size_kg'] : null,
        'brand_name' => $brandName,
        'has_rating' => $rating !== null,
        'rating' => $rating,
    ];
}

pg_json(200, true, 'Orders fetched successfully', $out);
