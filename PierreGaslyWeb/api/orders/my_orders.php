<?php
require_once __DIR__ . '/../../api_config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(false, 'Method not allowed', null, 405);
$user = getAuthUser();
try {
    $stmt = getDB()->prepare("
        SELECT o.order_id, o.order_number, o.quantity, o.total_amount,
               o.delivery_address, o.payment_method, o.order_status, o.ordered_at,
               p.product_name, p.size_kg, b.brand_name
        FROM orders o
        JOIN products p ON o.product_id = p.product_id
        JOIN brands   b ON p.brand_id   = b.brand_id
        WHERE o.customer_id = ?
        ORDER BY o.ordered_at DESC
    ");
    $stmt->execute([$user['user_id']]);
    $orders = $stmt->fetchAll();
    foreach ($orders as &$o) {
        $o['order_id']    = (int)$o['order_id'];
        $o['quantity']    = (int)$o['quantity'];
        $o['total_amount']= (float)$o['total_amount'];
        $o['size_kg']     = (int)$o['size_kg'];
    }
    respond(true, count($orders) . ' orders found', $orders);
} catch (PDOException $e) { respond(false, 'Failed: '.$e->getMessage(), null, 500); }
