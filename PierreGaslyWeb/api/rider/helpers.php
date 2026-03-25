<?php
require_once __DIR__ . '/../supabase_config.php';
date_default_timezone_set('Asia/Manila');

function rider_normalize_rows($value): array {
    if (!is_array($value)) return [];
    if ($value === []) return [];
    $isAssoc = array_keys($value) !== range(0, count($value) - 1);
    return $isAssoc ? [$value] : $value;
}

function rider_try_select(string $table, array $filters, string $select = '*', bool $useServiceKey = true): array {
    global $supabase;
    try {
        return rider_normalize_rows($supabase->select($table, $filters, $select, $useServiceKey));
    } catch (Throwable $e) {
        return [];
    }
}

function rider_resolve_user(): array {
    $authUser = requireAuth();
    $email = strtolower(trim((string)($authUser['email'] ?? '')));
    $authUserId = trim((string)($authUser['id'] ?? ''));

    if ($email === '' && $authUserId === '') {
        sendError('Invalid or expired token', 401);
    }

    $user = null;

    if ($authUserId !== '') {
        $rows = rider_try_select('users', ['auth_user_id' => $authUserId]);
        if (!empty($rows)) {
            $user = $rows[0];
        }
    }

    if ($user === null && $email !== '') {
        $rows = rider_try_select('users', ['email' => $email]);
        if (!empty($rows)) {
            $user = $rows[0];
        }
    }

    if ($user === null && $email !== '') {
        $rows = rider_try_select('users', ['role' => 'rider']);
        foreach ($rows as $row) {
            if (strtolower(trim((string)($row['email'] ?? ''))) === $email) {
                $user = $row;
                break;
            }
        }
    }

    if ($user === null) sendError('User record not found', 404);
    if (($user['role'] ?? '') !== 'rider') sendError('Rider account required', 403);
    if (($user['status'] ?? 'active') !== 'active') sendError('Your account has been ' . ($user['status'] ?? 'inactive'), 403);
    return $user;
}

function rider_get_availability(int $riderId): array {
    global $supabase;
    $rows = rider_normalize_rows($supabase->select('rider_availability', ['rider_id' => $riderId], '*', true));
    if (!empty($rows)) return $rows[0];
    $created = rider_normalize_rows($supabase->insert('rider_availability', [
        'rider_id' => $riderId,
        'is_available' => true,
        'updated_at' => date('c')
    ], true));
    return $created[0] ?? ['rider_id' => $riderId, 'is_available' => true];
}

function rider_active_riders(): array {
    $rows = rider_try_select('users', ['role' => 'rider']);
    return array_values(array_filter($rows, function ($row) {
        return (int)($row['user_id'] ?? 0) > 0 && strtolower((string)($row['status'] ?? 'active')) === 'active';
    }));
}

function rider_can_auto_claim(int $riderId): bool {
    $availability = rider_get_availability($riderId);
    if (empty($availability['is_available'])) {
        return false;
    }

    $activeRiders = rider_active_riders();
    $activeIds = array_map(fn($row) => (int)($row['user_id'] ?? 0), $activeRiders);
    if (count($activeIds) === 1 && (int)$activeIds[0] === $riderId) {
        return true;
    }

    $availabilityRows = rider_try_select('rider_availability', []);
    if (empty($availabilityRows)) {
        return false;
    }

    $availableIds = [];
    foreach ($availabilityRows as $row) {
        $candidateId = (int)($row['rider_id'] ?? 0);
        if ($candidateId <= 0 || !in_array($candidateId, $activeIds, true)) {
            continue;
        }
        if (!empty($row['is_available'])) {
            $availableIds[$candidateId] = $candidateId;
        }
    }

    return count($availableIds) === 1 && isset($availableIds[$riderId]);
}

function rider_claim_unassigned_orders(int $riderId): int {
    global $supabase;
    if (!rider_can_auto_claim($riderId)) {
        return 0;
    }

    $orders = rider_try_select('orders', []);
    if (empty($orders)) {
        return 0;
    }

    usort($orders, fn($a, $b) => strcmp(rider_order_primary_time($a), rider_order_primary_time($b)));
    $claimed = 0;
    foreach ($orders as $order) {
        $status = strtolower((string)($order['order_status'] ?? 'pending'));
        $assignedRider = (int)($order['rider_id'] ?? 0);
        if ($assignedRider > 0 || !in_array($status, ['pending', 'preparing', 'out_for_delivery'], true)) {
            continue;
        }

        $payload = [
            'rider_id' => $riderId,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($status === 'pending') {
            $payload['order_status'] = 'preparing';
            $payload['prepared_at'] = date('Y-m-d H:i:s');
        }
        $supabase->update('orders', $payload, ['order_id' => (int)$order['order_id']], true);
        $claimed++;
    }

    return $claimed;
}

function rider_orders_for_rider(int $riderId, bool $autoClaim = true): array {
    global $supabase;
    if ($autoClaim) {
        rider_claim_unassigned_orders($riderId);
    }
    $orders = rider_normalize_rows($supabase->select('orders', ['rider_id' => $riderId], '*', true));
    usort($orders, fn($a, $b) => strcmp(rider_order_primary_time($b), rider_order_primary_time($a)));
    return $orders;
}

function rider_order_primary_time(array $order): string {
    $orderedAt = trim((string)($order['ordered_at'] ?? ''));
    if ($orderedAt !== '') return $orderedAt;
    $createdAt = trim((string)($order['created_at'] ?? ''));
    if ($createdAt !== '') return $createdAt;
    return trim((string)($order['updated_at'] ?? ''));
}

function rider_infer_method(array $order): string {
    $payment = strtolower((string)($order['payment_method'] ?? ''));
    $stored = strtolower((string)($order['fulfillment_method'] ?? ''));
    $address = strtolower((string)($order['delivery_address'] ?? ''));
    if ($stored === 'pickup' || $payment === 'pickup' || str_contains($address, 'branch pickup') || str_contains($address, 'pickup schedule')) {
        return 'pickup';
    }
    return 'cod';
}

function rider_maps_for_orders(array $orders): array {
    global $supabase;
    $productMap = [];
    $customerMap = [];
    foreach ($orders as $order) {
        $pid = (int)($order['product_id'] ?? 0);
        if ($pid > 0 && !isset($productMap[$pid])) {
            $rows = rider_normalize_rows($supabase->select('products', ['product_id' => $pid], '*', true));
            $productMap[$pid] = $rows[0] ?? [];
        }
        $cid = (int)($order['customer_id'] ?? 0);
        if ($cid > 0 && !isset($customerMap[$cid])) {
            $rows = rider_normalize_rows($supabase->select('users', ['user_id' => $cid], '*', true));
            $customerMap[$cid] = $rows[0] ?? [];
        }
    }
    return [$productMap, $customerMap];
}

function rider_order_payload(array $order, array $productMap, array $customerMap): array {
    $pid = (int)($order['product_id'] ?? 0);
    $cid = (int)($order['customer_id'] ?? 0);
    $product = $productMap[$pid] ?? [];
    $customer = $customerMap[$cid] ?? [];
    $method = rider_infer_method($order);
    $status = (string)($order['order_status'] ?? 'pending');
    $statusChangedAt = match ($status) {
        'delivered' => (string)($order['delivered_at'] ?? $order['updated_at'] ?? rider_order_primary_time($order)),
        'cancelled' => (string)($order['cancelled_at'] ?? $order['updated_at'] ?? rider_order_primary_time($order)),
        'out_for_delivery' => (string)($order['out_for_delivery_at'] ?? $order['updated_at'] ?? rider_order_primary_time($order)),
        'preparing' => (string)($order['prepared_at'] ?? $order['updated_at'] ?? rider_order_primary_time($order)),
        default => rider_order_primary_time($order)
    };

    return [
        'order_id' => (int)($order['order_id'] ?? 0),
        'order_number' => (string)($order['order_number'] ?? ''),
        'customer_name' => (string)($customer['full_name'] ?? 'Customer'),
        'customer_phone' => (string)($customer['phone'] ?? ''),
        'delivery_address' => (string)($order['delivery_address'] ?? ''),
        'payment_method' => (string)($order['payment_method'] ?? ''),
        'order_status' => $status,
        'ordered_at' => rider_order_primary_time($order),
        'status_changed_at' => $statusChangedAt,
        'product_name' => (string)($product['product_name'] ?? 'LPG Order'),
        'size_kg' => isset($product['size_kg']) ? (int)$product['size_kg'] : null,
        'quantity' => (int)($order['quantity'] ?? 0),
        'total_amount' => (float)($order['total_amount'] ?? 0),
        'fulfillment_method' => $method
    ];
}

function rider_order_matches_filter(array $order, string $statusFilter): bool {
    $status = strtolower((string)($order['order_status'] ?? 'pending'));
    return match ($statusFilter) {
        'completed' => $status === 'delivered',
        'cancelled' => $status === 'cancelled',
        'active' => in_array($status, ['pending', 'preparing', 'out_for_delivery'], true),
        default => true,
    };
}

function rider_order_matches_date(array $order, string $date): bool {
    $status = strtolower((string)($order['order_status'] ?? 'pending'));
    $raw = match ($status) {
        'delivered' => (string)($order['delivered_at'] ?? $order['ordered_at'] ?? ''),
        'cancelled' => (string)($order['cancelled_at'] ?? $order['ordered_at'] ?? ''),
        default => rider_order_primary_time($order),
    };
    if ($raw === '') return false;
    return str_starts_with($raw, $date);
}
