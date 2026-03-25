<?php
require_once __DIR__ . '/helpers.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

try {
    global $supabase;
    $rider = rider_resolve_user();
    $riderId = (int)$rider['user_id'];
    $availability = rider_get_availability($riderId);
    $orders = rider_orders_for_rider($riderId);

    [$productMap, $customerMap] = rider_maps_for_orders($orders);
    $today = date('Y-m-d');
    $assignedToday = 0;
    $activeCount = 0;
    $completedToday = 0;
    $current = null;

    foreach ($orders as $order) {
        $status = strtolower((string)($order['order_status'] ?? 'pending'));
        $orderedAt = rider_order_primary_time($order);
        if ($orderedAt !== '' && str_starts_with($orderedAt, $today)) {
            $assignedToday++;
        }
        if (in_array($status, ['pending', 'preparing', 'out_for_delivery'], true)) {
            $activeCount++;
            if ($current === null) {
                $current = $order;
            }
        }
        $deliveredAt = (string)($order['delivered_at'] ?? '');
        if ($status === 'delivered' && $deliveredAt !== '' && str_starts_with($deliveredAt, $today)) {
            $completedToday++;
        }
    }

    sendSuccess([
        'availability_status' => !empty($availability['is_available']) ? 'standby' : 'unavailable',
        'is_available' => !empty($availability['is_available']),
        'assigned_today' => $assignedToday,
        'active_delivery' => $activeCount,
        'completed_today' => $completedToday,
        'current_assignment' => $current ? rider_order_payload($current, $productMap, $customerMap) : null,
    ], 'Rider dashboard loaded');
} catch (Throwable $e) {
    logError('rider/dashboard error: ' . $e->getMessage());
    sendError('Failed to load rider dashboard', 500);
}
