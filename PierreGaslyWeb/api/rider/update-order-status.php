<?php
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

try {
    global $supabase;
    $rider = rider_resolve_user();
    $riderId = (int)$rider['user_id'];
    $data = getJsonInput();
    $orderId = (int)($data['order_id'] ?? 0);
    $newStatus = strtolower(trim((string)($data['order_status'] ?? '')));
    if ($orderId <= 0) sendError('Invalid order ID');
    if (!in_array($newStatus, ['out_for_delivery', 'delivered'], true)) sendError('Invalid rider status update');

    $orders = rider_normalize_rows($supabase->select('orders', ['order_id' => $orderId], '*', true));
    if (empty($orders)) sendError('Order not found', 404);
    $order = $orders[0];
    if ((int)($order['rider_id'] ?? 0) !== $riderId) sendError('This order is not assigned to your account', 403);

    $currentStatus = strtolower((string)($order['order_status'] ?? 'pending'));
    if (!in_array($currentStatus, ['pending', 'preparing', 'out_for_delivery'], true)) {
        sendError('This assignment can no longer be updated');
    }

    $update = [
        'order_status' => $newStatus,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    if ($newStatus === 'out_for_delivery') {
        $update['out_for_delivery_at'] = date('Y-m-d H:i:s');
    }
    if ($newStatus === 'delivered') {
        $update['delivered_at'] = date('Y-m-d H:i:s');
    }
    $supabase->update('orders', $update, ['order_id' => $orderId], true);

    if ($newStatus === 'delivered') {
        $existingSale = rider_normalize_rows($supabase->select('sales', ['order_id' => $orderId], '*', true));
        if (empty($existingSale)) {
            $supabase->insert('sales', [
                'order_id' => $orderId,
                'rider_id' => $riderId,
                'sale_amount' => (float)($order['total_amount'] ?? 0),
                'sale_date' => date('Y-m-d')
            ], true);
        }
    }

    rider_get_availability($riderId);
    $supabase->update('rider_availability', [
        'is_available' => $newStatus === 'delivered',
        'updated_at' => date('c')
    ], ['rider_id' => $riderId], true);

    $message = $newStatus === 'delivered' ? 'Delivery marked as completed' : 'Assignment marked as out for delivery';
    sendSuccess(['order_id' => $orderId, 'order_status' => $newStatus], $message);
} catch (Throwable $e) {
    logError('rider/update-order-status error: ' . $e->getMessage());
    sendError('Failed to update rider assignment', 500);
}
