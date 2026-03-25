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
    $r = pg_request('GET', '/auth/v1/user', null, $token, false);
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

function pg_get_single(string $table, array $filters, string $select = '*'): ?array {
    $query = '/rest/v1/' . $table . '?select=' . rawurlencode($select);
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

function pg_get_settings(array $keys): array {
    if (empty($keys)) return [];
    $encoded = implode(',', array_map(fn($k) => '"' . str_replace('"', '\\"', $k) . '"', $keys));
    $r = pg_request('GET', '/rest/v1/system_settings?select=setting_key,setting_value&setting_key=in.(' . rawurlencode($encoded) . ')');
    $out = [];
    if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && is_array($r['data'])) {
        foreach ($r['data'] as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $out;
}

function pg_to_float($value, float $default = 0.0): float {
    return is_numeric($value) ? (float)$value : $default;
}

function pg_to_int($value, int $default = 0): int {
    return is_numeric($value) ? (int)$value : $default;
}

function pg_city_tier(string $city): array {
    $c = strtolower(trim($city));
    $cluster1 = ['dagupan city','dagupan','calasiao','binmaley','mangaldan','san fabian','lingayen','manaoag','santa barbara','sta. barbara'];
    $cluster2 = ['urdaneta city','urdaneta','malasiqui','san carlos city','san carlos','mapandan','pozorrubio','sison','bugallon','labrador','aguilar','alcala','bautista','bayambang','binalonan','villasis','basista','laoac'];
    if (in_array($c, $cluster1, true)) return ['Tier 1', 1];
    if (in_array($c, $cluster2, true)) return ['Tier 2', 2];
    return ['Tier 3', 3];
}

function pg_points_for_product(string $productName, ?int $sizeKg): int {
    $isRefill = stripos($productName, 'refill') !== false;
    $size = (int)($sizeKg ?? 0);
    if ($size <= 7) return $isRefill ? 50 : 60;
    if ($size <= 11) return $isRefill ? 90 : 100;
    if ($size <= 22) return $isRefill ? 200 : 210;
    return $isRefill ? 220 : 250;
}

function pg_free_credits_for_product(string $productName, int $qty): float {
    $isRefill = stripos($productName, 'refill') !== false;
    return $isRefill ? ($qty * 0.5) : ($qty * 1.0);
}

function pg_tier_discount_percent(string $tier): int {
    return match (strtolower(trim($tier))) {
        'silver' => 2,
        'gold' => 3,
        'platinum' => 4,
        default => 0,
    };
}

function pg_redemption_rule(string $tier): array {
    return match (strtolower(trim($tier))) {
        'silver' => [1000, 90.0],
        'gold' => [1500, 140.0],
        'platinum' => [2000, 190.0],
        default => [500, 40.0],
    };
}

function pg_tier_from_lifetime(int $lifetimePoints): string {
    if ($lifetimePoints >= 7000) return 'Platinum';
    if ($lifetimePoints >= 3300) return 'Gold';
    if ($lifetimePoints >= 1800) return 'Silver';
    return 'Bronze';
}

function pg_column_missing(?string $raw, string $column): bool {
    $raw = strtolower((string)$raw);
    return strpos($raw, strtolower("could not find the '{$column}' column")) !== false
        || strpos($raw, strtolower("column {$column}")) !== false;
}

function pg_fetch_user_rewards(int $userId): array {
    $attempts = [
        'user_id,total_points,lifetime_points,redeemed_points,tier',
        'user_id,total_points,redeemed_points,tier',
    ];
    foreach ($attempts as $select) {
        $r = pg_request('GET', '/rest/v1/user_rewards?select=' . rawurlencode($select) . '&user_id=eq.' . $userId . '&limit=1');
        if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300) {
            return [
                'row' => !empty($r['data'][0]) ? $r['data'][0] : null,
                'has_lifetime_points' => strpos($select, 'lifetime_points') !== false,
            ];
        }
        if (!pg_column_missing($r['raw'] ?? '', 'lifetime_points')) {
            break;
        }
    }
    return ['row' => null, 'has_lifetime_points' => false];
}

function pg_insert_order_with_fallbacks(array $payloads): array {
    $last = null;
    foreach ($payloads as $payload) {
        $r = pg_request('POST', '/rest/v1/orders', [$payload], null, true);
        if (($r['status'] ?? 0) >= 200 && ($r['status'] ?? 0) < 300 && !empty($r['data'][0])) {
            return ['ok' => true, 'row' => $r['data'][0]];
        }
        $last = $r;
    }
    return ['ok' => false, 'error' => $last['raw'] ?? 'Unable to create order'];
}

function pg_patch_user_rewards(int $userId, array $payloadWithLifetime, array $payloadWithoutLifetime): void {
    $patch = pg_request('PATCH', '/rest/v1/user_rewards?user_id=eq.' . $userId, $payloadWithLifetime, null, false);
    if (($patch['status'] ?? 0) >= 200 && ($patch['status'] ?? 0) < 300) {
        return;
    }
    if (pg_column_missing($patch['raw'] ?? '', 'lifetime_points')) {
        pg_request('PATCH', '/rest/v1/user_rewards?user_id=eq.' . $userId, $payloadWithoutLifetime, null, false);
    }
}

function pg_create_user_rewards(int $userId, array $payloadWithLifetime, array $payloadWithoutLifetime): void {
    $post = pg_request('POST', '/rest/v1/user_rewards', [$payloadWithLifetime], null, true);
    if (($post['status'] ?? 0) >= 200 && ($post['status'] ?? 0) < 300) {
        return;
    }
    if (pg_column_missing($post['raw'] ?? '', 'lifetime_points')) {
        pg_request('POST', '/rest/v1/user_rewards', [$payloadWithoutLifetime], null, true);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pg_json(405, false, 'Method not allowed');
}

$token = pg_bearer_token();
if (!$token) {
    pg_json(401, false, 'Unauthorized');
}

$authUser = pg_auth_user($token);
if (!$authUser) {
    pg_json(401, false, 'Invalid or expired session');
}

$appUser = pg_find_user($authUser);
if (!$appUser) {
    pg_json(404, false, 'User record not found');
}
if (isset($appUser['status']) && strtolower((string)$appUser['status']) !== 'active') {
    pg_json(403, false, 'Your account is not active');
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$productId = pg_to_int($input['product_id'] ?? 0);
$quantity = max(1, pg_to_int($input['quantity'] ?? 1));
$contactNumber = trim((string)($input['contact_number'] ?? ($appUser['phone'] ?? '')));
$requestedPayment = strtolower(trim((string)($input['payment_method'] ?? 'cash')));
$requestedFulfillment = strtolower(trim((string)($input['fulfillment_method'] ?? 'cod')));
$fulfillmentMethod = in_array($requestedFulfillment, ['pickup', 'cod', 'delivery'], true) ? $requestedFulfillment : 'cod';
if ($fulfillmentMethod === 'delivery') $fulfillmentMethod = 'cod';
$paymentMethod = $fulfillmentMethod === 'pickup' ? 'cash' : $requestedPayment;
if (!in_array($paymentMethod, ['cash', 'gcash', 'paymaya', 'card'], true)) {
    $paymentMethod = 'cash';
}

if ($productId <= 0) pg_json(422, false, 'Invalid product');
if ($contactNumber === '') pg_json(422, false, 'Contact number is required');

$product = pg_get_single('products', ['product_id' => $productId], 'product_id,product_name,size_kg,price,stock_quantity,brand_id,availability,is_active');
if (!$product) pg_json(404, false, 'Product not found');
if (array_key_exists('is_active', $product) && !$product['is_active']) pg_json(400, false, 'Product is not available');
if (isset($product['availability']) && strtolower((string)$product['availability']) === 'out_of_stock') pg_json(400, false, 'Product is out of stock');
if (isset($product['stock_quantity']) && is_numeric($product['stock_quantity']) && (int)$product['stock_quantity'] < $quantity) {
    pg_json(400, false, 'Not enough stock available');
}

$brandName = 'Pierre Gasly';
if (!empty($product['brand_id'])) {
    $brand = pg_get_single('brands', ['brand_id' => (int)$product['brand_id']], 'brand_name');
    if (!empty($brand['brand_name'])) $brandName = $brand['brand_name'];
}

$settings = pg_get_settings([
    'delivery_fee_tier_1', 'delivery_fee_tier_2', 'delivery_fee_tier_3',
    'branch_city', 'service_province'
]);
$deliveryFeeTier1 = pg_to_float($settings['delivery_fee_tier_1'] ?? 50, 50.0);
$deliveryFeeTier2 = pg_to_float($settings['delivery_fee_tier_2'] ?? 90, 90.0);
$deliveryFeeTier3 = pg_to_float($settings['delivery_fee_tier_3'] ?? 130, 130.0);
$branchCity = trim((string)($settings['branch_city'] ?? 'Dagupan City'));
$serviceProvince = trim((string)($settings['service_province'] ?? 'Pangasinan'));

$pickupDate = trim((string)($input['pickup_date'] ?? ''));
$pickupTime = trim((string)($input['pickup_time'] ?? ''));
$deliveryTier = null;
$deliveryFee = 0.0;

if ($fulfillmentMethod === 'pickup') {
    $deliveryAddress = 'Branch Pickup - Pierre Gasly LPG, ' . $branchCity . ', ' . $serviceProvince;
    if ($pickupDate !== '' || $pickupTime !== '') {
        $deliveryAddress .= "\nPickup Schedule: " . trim($pickupDate . ' ' . $pickupTime);
    }
    $deliveryAddress .= "\nContact Number: " . $contactNumber;
} else {
    $barangayStreet = trim((string)($input['barangay_street'] ?? ''));
    $cityTown = trim((string)($input['city_town'] ?? ''));
    $province = trim((string)($input['province'] ?? $serviceProvince));
    $deliveryNotes = trim((string)($input['delivery_notes'] ?? ''));
    if ($barangayStreet === '' || $cityTown === '') {
        pg_json(422, false, 'Delivery address is incomplete');
    }
    [$deliveryTier, $tierNo] = pg_city_tier($cityTown);
    $deliveryFee = match ($tierNo) {
        1 => $deliveryFeeTier1,
        2 => $deliveryFeeTier2,
        default => $deliveryFeeTier3,
    };
    $deliveryAddress = $barangayStreet . ', ' . $cityTown . ', ' . $province;
    if ($deliveryNotes !== '') {
        $deliveryAddress .= "\nNotes: " . $deliveryNotes;
    }
    $deliveryAddress .= "\nContact Number: " . $contactNumber;
}

$unitPrice = pg_to_float($product['price'] ?? 0, 0.0);
$subtotal = round($unitPrice * $quantity, 2);

$userRewardsState = pg_fetch_user_rewards((int)$appUser['user_id']);
$userRewards = $userRewardsState['row'];
$hasLifetimePoints = $userRewardsState['has_lifetime_points'];
$availablePoints = pg_to_int($userRewards['total_points'] ?? 0);
$lifetimePoints = pg_to_int($userRewards['lifetime_points'] ?? $userRewards['total_points'] ?? 0);
$redeemedPointsTotal = pg_to_int($userRewards['redeemed_points'] ?? 0);
$currentTier = (string)($userRewards['tier'] ?? 'Bronze');
$tierDiscountPercent = pg_tier_discount_percent($currentTier);
$tierDiscountAmount = round($subtotal * ($tierDiscountPercent / 100), 2);

$usePoints = !empty($input['use_points']);
$requestedPointsToRedeem = max(0, pg_to_int($input['points_to_redeem'] ?? 0));
[$requiredRedeemPoints, $redeemDiscountAmount] = pg_redemption_rule($currentTier);
$pointsRedeemed = 0;
$pointsDiscountAmount = 0.0;
if ($usePoints && $requestedPointsToRedeem > 0 && $availablePoints >= $requiredRedeemPoints && $requestedPointsToRedeem >= $requiredRedeemPoints) {
    $pointsRedeemed = $requiredRedeemPoints;
    $pointsDiscountAmount = $redeemDiscountAmount;
}

$freeCreditsEarned = pg_free_credits_for_product((string)($product['product_name'] ?? ''), $quantity);
$freeDeliveryApplied = false;
if ($fulfillmentMethod === 'cod' && strtolower($currentTier) === 'platinum') {
    $threshold = match ($deliveryTier) {
        'Tier 1' => 3.0,
        'Tier 2' => 5.0,
        default => 10.0,
    };
    if ($freeCreditsEarned >= $threshold) {
        $deliveryFee = 0.0;
        $freeDeliveryApplied = true;
    }
}

$totalAmount = round(max(0, $subtotal + $deliveryFee - $tierDiscountAmount - $pointsDiscountAmount), 2);
$estimatedPointsEarned = pg_points_for_product((string)($product['product_name'] ?? ''), isset($product['size_kg']) ? (int)$product['size_kg'] : null) * $quantity;
$availablePointsAfter = max(0, $availablePoints - $pointsRedeemed + $estimatedPointsEarned);
$newLifetimePoints = $lifetimePoints + $estimatedPointsEarned;
$newTier = pg_tier_from_lifetime($newLifetimePoints);
$orderNumber = 'PG-' . date('Ymd') . '-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$timestamp = pg_now();
$tierSnapshot = [
    'tier' => $currentTier,
    'tier_discount_percent' => $tierDiscountPercent,
    'tier_discount_amount' => $tierDiscountAmount,
    'points_discount_amount' => $pointsDiscountAmount,
];

$basePayload = [
    'order_number' => $orderNumber,
    'customer_id' => (int)$appUser['user_id'],
    'product_id' => $productId,
    'quantity' => $quantity,
    'total_amount' => $totalAmount,
    'delivery_address' => $deliveryAddress,
    'payment_method' => $paymentMethod,
    'order_status' => 'pending',
    'ordered_at' => $timestamp,
    'updated_at' => $timestamp,
];

$legacyExtendedPayload = $basePayload + [
    'contact_number' => $contactNumber,
    'fulfillment_method' => $fulfillmentMethod,
];

$modernPayload = $legacyExtendedPayload + [
    'subtotal' => $subtotal,
    'delivery_fee' => $deliveryFee,
    'points_redeemed' => $pointsRedeemed,
    'discount_applied' => $tierDiscountAmount + $pointsDiscountAmount,
    'available_points_after' => $availablePointsAfter,
    'estimated_points_earned' => $estimatedPointsEarned,
    'delivery_tier' => $deliveryTier,
    'customer_tier' => $currentTier,
    'tier_discount_amount' => $tierDiscountAmount,
    'points_discount_amount' => $pointsDiscountAmount,
    'free_credits_earned' => $freeCreditsEarned,
    'free_delivery_applied' => $freeDeliveryApplied,
    'tier_snapshot' => json_encode($tierSnapshot),
];

$insert = pg_insert_order_with_fallbacks([$modernPayload, $legacyExtendedPayload, $basePayload]);
if (!$insert['ok']) {
    pg_json(500, false, 'Order failed: Supabase API Error: ' . $insert['error']);
}

if (isset($product['stock_quantity']) && is_numeric($product['stock_quantity'])) {
    $newStock = max(0, (int)$product['stock_quantity'] - $quantity);
    pg_request('PATCH', '/rest/v1/products?product_id=eq.' . $productId, ['stock_quantity' => $newStock], null, false);
}

$rewardsPatchWithLifetime = [
    'total_points' => $availablePointsAfter,
    'lifetime_points' => $newLifetimePoints,
    'redeemed_points' => $redeemedPointsTotal + $pointsRedeemed,
    'tier' => $newTier,
    'updated_at' => $timestamp,
];
$rewardsPatchWithoutLifetime = [
    'total_points' => $availablePointsAfter,
    'redeemed_points' => $redeemedPointsTotal + $pointsRedeemed,
    'tier' => $newTier,
    'updated_at' => $timestamp,
];
$rewardsCreateWithLifetime = [
    'user_id' => (int)$appUser['user_id'],
    'total_points' => $availablePointsAfter,
    'lifetime_points' => $newLifetimePoints,
    'redeemed_points' => $pointsRedeemed,
    'tier' => $newTier,
    'created_at' => $timestamp,
    'updated_at' => $timestamp,
];
$rewardsCreateWithoutLifetime = [
    'user_id' => (int)$appUser['user_id'],
    'total_points' => $availablePointsAfter,
    'redeemed_points' => $pointsRedeemed,
    'tier' => $newTier,
    'created_at' => $timestamp,
    'updated_at' => $timestamp,
];

if ($userRewards) {
    pg_patch_user_rewards((int)$appUser['user_id'], $rewardsPatchWithLifetime, $rewardsPatchWithoutLifetime);
} else {
    pg_create_user_rewards((int)$appUser['user_id'], $rewardsCreateWithLifetime, $rewardsCreateWithoutLifetime);
}

$data = [
    'order_id' => (int)($insert['row']['order_id'] ?? 0),
    'order_number' => $orderNumber,
    'subtotal' => $subtotal,
    'delivery_fee' => $deliveryFee,
    'delivery_tier' => $deliveryTier,
    'total_amount' => $totalAmount,
    'status' => 'pending',
    'fulfillment_method' => $fulfillmentMethod,
    'product_name' => trim('Pierre Gasly' . ' • ' . $brandName),
    'quantity' => $quantity,
    'payment_method' => $paymentMethod,
    'delivery_address' => $deliveryAddress,
    'pickup_schedule' => $fulfillmentMethod === 'pickup' ? trim($pickupDate . ' at ' . $pickupTime) : null,
    'contact_number' => $contactNumber,
    'points_redeemed' => $pointsRedeemed,
    'discount_applied' => round($tierDiscountAmount + $pointsDiscountAmount, 2),
    'available_points_after' => $availablePointsAfter,
    'estimated_points_earned' => $estimatedPointsEarned,
    'has_lifetime_points' => $hasLifetimePoints,
];

pg_json(200, true, 'Order placed successfully', $data);
