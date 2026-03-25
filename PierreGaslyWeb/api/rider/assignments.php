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
    $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'active')));
    if (!in_array($statusFilter, ['active', 'completed', 'cancelled', 'all'], true)) {
        $statusFilter = 'active';
    }
    $date = trim((string)($_GET['date'] ?? ''));

    $orders = rider_orders_for_rider($riderId);

    $filtered = array_values(array_filter($orders, function ($order) use ($statusFilter, $date) {
        if (!rider_order_matches_filter($order, $statusFilter)) return false;
        if ($date !== '' && !rider_order_matches_date($order, $date)) return false;
        return true;
    }));

    [$productMap, $customerMap] = rider_maps_for_orders($filtered);
    $payload = array_map(fn($order) => rider_order_payload($order, $productMap, $customerMap), $filtered);
    sendSuccess($payload, count($payload) . ' assignments found');
} catch (Throwable $e) {
    logError('rider/assignments error: ' . $e->getMessage());
    sendError('Failed to load rider assignments', 500);
}
