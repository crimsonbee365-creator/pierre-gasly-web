<?php
require_once __DIR__ . '/../../api_config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Method not allowed', null, 405);
$user = getAuthUser();
$data = getJson();

$productId     = (int)($data['product_id'] ?? 0);
$quantity      = (int)($data['quantity'] ?? 0);
$address       = htmlspecialchars(trim($data['delivery_address'] ?? ''), ENT_QUOTES, 'UTF-8');
$paymentMethod = trim($data['payment_method'] ?? '');

if ($productId < 1) respond(false, 'Invalid product');
if ($quantity < 1)  respond(false, 'Quantity must be at least 1');
if (!$address)      respond(false, 'Delivery address is required');
if (!in_array($paymentMethod, ['cash','gcash','paymaya','card'])) respond(false, 'Invalid payment method');

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id=? AND is_active=1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if (!$product) respond(false, 'Product not found', null, 404);
    if ($product['stock_quantity'] < $quantity) respond(false, 'Insufficient stock. Available: '.$product['stock_quantity']);

    $total = round($product['price'] * $quantity, 2);
    $orderNum = 'PG-'.date('Ymd').'-'.strtoupper(substr(uniqid(),-6));

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO orders (order_number,customer_id,product_id,quantity,total_amount,delivery_address,payment_method,order_status,ordered_at,created_at) VALUES (?,?,?,?,?,?,?,'pending',NOW(),NOW())");
    $stmt->execute([$orderNum,$user['user_id'],$productId,$quantity,$total,$address,$paymentMethod]);
    $orderId = $pdo->lastInsertId();
    $pdo->prepare("UPDATE products SET stock_quantity=stock_quantity-? WHERE product_id=?")->execute([$quantity,$productId]);
    $pdo->commit();

    respond(true, 'Order placed successfully!', [
        'order_id'        => (int)$orderId,
        'order_number'    => $orderNum,
        'total_amount'    => $total,
        'status'          => 'pending',
        'product_name'    => $product['product_name'],
        'quantity'        => $quantity,
        'payment_method'  => $paymentMethod,
        'delivery_address'=> $address,
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(false, 'Order failed: '.$e->getMessage(), null, 500);
}
