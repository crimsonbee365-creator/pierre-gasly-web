<?php
/**
 * PIERRE GASLY - Orders Management (Improved Layout)
 * Modern, clean design with better organization
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Orders Management';
$db = Database::getInstance();

$success = '';
$error = '';

function pgasParseUtcToManila($datetime) {
    if ($datetime === null || $datetime === '') {
        return null;
    }

    try {
        $manilaTimezone = new DateTimeZone('Asia/Manila');
        $raw = trim((string)$datetime);

        if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $raw)) {
            $dateTime = new DateTimeImmutable($raw);
        } else {
            $dateTime = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        }

        return $dateTime->setTimezone($manilaTimezone);
    } catch (Throwable $e) {
        return null;
    }
}

function pgasUtcToManilaTimestamp($datetime) {
    $dateTime = pgasParseUtcToManila($datetime);
    return $dateTime ? $dateTime->getTimestamp() : 0;
}

function pgasFormatUtcToManila($datetime, $format = 'M d, Y g:i A') {
    $dateTime = pgasParseUtcToManila($datetime);
    if ($dateTime) {
        return $dateTime->format($format);
    }

    return formatDateTime($datetime, $format);
}

function ensureRewardsRuntimeTables($db) {
    $db->query("CREATE TABLE IF NOT EXISTS `rewards_settings` (
        `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $defaults = [
        'purchase_11kg_points' => '100',
        'refill_11kg_points' => '60',
        'purchase_22kg_points' => '180',
        'refill_22kg_points' => '120',
        'bronze_bonus_pct' => '0',
        'silver_bonus_pct' => '10',
        'gold_bonus_pct' => '20',
        'platinum_bonus_pct' => '30',
        'silver_threshold' => '5',
        'gold_threshold' => '15',
        'platinum_threshold' => '30',
        'redemption_rate' => '500',
        'redemption_value' => '50',
        'points_enabled' => '1',
    ];
    foreach ($defaults as $key => $value) {
        $db->query("INSERT IGNORE INTO rewards_settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
    }

    $db->query("CREATE TABLE IF NOT EXISTS `reward_transactions` (
        `tx_id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `points` INT NOT NULL DEFAULT 0,
        `type` VARCHAR(30) NOT NULL DEFAULT 'earned',
        `description` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function rewardSettingValue($db, $key, $default = 0) {
    $row = $db->fetchOne("SELECT setting_value FROM rewards_settings WHERE setting_key = ?", [$key]);
    return isset($row['setting_value']) ? (int)$row['setting_value'] : (int)$default;
}

function rewardTierFromDeliveredOrders($count, $db) {
    $silver = rewardSettingValue($db, 'silver_threshold', 5);
    $gold = rewardSettingValue($db, 'gold_threshold', 15);
    $platinum = rewardSettingValue($db, 'platinum_threshold', 30);
    if ($count >= $platinum) return 'Platinum';
    if ($count >= $gold) return 'Gold';
    if ($count >= $silver) return 'Silver';
    return 'Bronze';
}

function rewardBonusPercentForTier($tier, $db) {
    return match ($tier) {
        'Silver' => rewardSettingValue($db, 'silver_bonus_pct', 10),
        'Gold' => rewardSettingValue($db, 'gold_bonus_pct', 20),
        'Platinum' => rewardSettingValue($db, 'platinum_bonus_pct', 30),
        default => rewardSettingValue($db, 'bronze_bonus_pct', 0),
    };
}

function rewardBasePointsForProduct($product, $db) {
    $sizeKg = (int)($product['size_kg'] ?? 0);
    $name = strtolower((string)($product['product_name'] ?? ''));
    $isRefill = strpos($name, 'refill') !== false;

    if ($sizeKg === 22) {
        return $isRefill ? rewardSettingValue($db, 'refill_22kg_points', 120) : rewardSettingValue($db, 'purchase_22kg_points', 180);
    }
    if ($sizeKg === 11) {
        return $isRefill ? rewardSettingValue($db, 'refill_11kg_points', 60) : rewardSettingValue($db, 'purchase_11kg_points', 100);
    }

    $fallback = $isRefill ? rewardSettingValue($db, 'refill_11kg_points', 60) : rewardSettingValue($db, 'purchase_11kg_points', 100);
    return max(1, (int)round($fallback * (($sizeKg > 0 ? $sizeKg : 11) / 11)));
}


function systemSettingValue($db, $key, $default = '') {
    $row = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
    return isset($row['setting_value']) ? (string)$row['setting_value'] : (string)$default;
}

function normalizeFulfillmentMethod($order) {
    $method = strtolower((string)($order['payment_method'] ?? ''));
    if ($method === 'pickup') {
        return 'pickup';
    }
    return 'cod';
}

function extractPickupScheduleText($deliveryAddress) {
    $address = (string)$deliveryAddress;
    if (preg_match('/Pickup Schedule:\s*(.+)$/mi', $address, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function orderPhaseSummary($order, $db) {
    $status = strtolower((string)($order['order_status'] ?? 'pending'));
    $method = normalizeFulfillmentMethod($order);
    $open = systemSettingValue($db, 'opening_time', '08:00');
    $close = systemSettingValue($db, 'closing_time', '18:00');
    $hours = date('g:i A', strtotime($open)) . ' - ' . date('g:i A', strtotime($close));
    $pickupSchedule = extractPickupScheduleText($order['delivery_address'] ?? '');

    if ($method === 'pickup') {
        $labelMap = [
            'pending' => 'Pickup request received',
            'preparing' => 'Preparing for pickup',
            'out_for_delivery' => 'Ready for pickup',
            'delivered' => 'Picked up',
            'cancelled' => 'Pickup cancelled',
        ];
        $stepMap = [
            'pending' => 1,
            'preparing' => 2,
            'out_for_delivery' => 3,
            'delivered' => 4,
            'cancelled' => 0,
        ];
        $hint = $pickupSchedule !== ''
            ? 'Scheduled pickup: ' . $pickupSchedule . ' · Store hours: ' . $hours
            : 'Store hours: ' . $hours;
        return [
            'method_label' => 'Branch Pick-up',
            'method_icon' => '🏪',
            'phase_label' => $labelMap[$status] ?? 'Pickup in progress',
            'phase_hint' => $hint,
            'steps' => ['Request received', 'Preparing order', 'Ready for pickup', 'Picked up'],
            'active_step' => $stepMap[$status] ?? 1,
            'schedule' => $pickupSchedule,
            'store_hours' => $hours,
        ];
    }

    $labelMap = [
        'pending' => 'Order received',
        'preparing' => 'Preparing order',
        'out_for_delivery' => 'Rider on the way',
        'delivered' => 'Delivered',
        'cancelled' => 'Delivery cancelled',
    ];
    $stepMap = [
        'pending' => 1,
        'preparing' => 2,
        'out_for_delivery' => 3,
        'delivered' => 4,
        'cancelled' => 0,
    ];
    return [
        'method_label' => 'Cash on Delivery',
        'method_icon' => '🛵',
        'phase_label' => $labelMap[$status] ?? 'Delivery in progress',
        'phase_hint' => 'Track progression from request to doorstep delivery.',
        'steps' => ['Order received', 'Preparing order', 'Rider on the way', 'Delivered'],
        'active_step' => $stepMap[$status] ?? 1,
        'schedule' => '',
        'store_hours' => $hours,
    ];
}

function awardDeliveredOrderRewards($db, $order, $product) {
    ensureRewardsRuntimeTables($db);
    if (rewardSettingValue($db, 'points_enabled', 1) !== 1) {
        return;
    }

    $customerId = (int)($order['customer_id'] ?? 0);
    if ($customerId < 1) {
        return;
    }

    $existing = $db->fetchOne(
        "SELECT tx_id FROM reward_transactions WHERE user_id = ? AND type = 'earned' AND description LIKE ? LIMIT 1",
        [$customerId, '%order #' . ($order['order_number'] ?? $order['order_id']) . '%']
    );
    if ($existing) {
        return;
    }

    $completedOrders = (int)($db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM orders WHERE customer_id = ? AND (order_status = 'delivered' OR order_id = ?)",
        [$customerId, $order['order_id']]
    )['cnt'] ?? 0);

    $tier = rewardTierFromDeliveredOrders($completedOrders, $db);
    $bonusPercent = rewardBonusPercentForTier($tier, $db);
    $basePoints = rewardBasePointsForProduct($product, $db);
    $quantity = max(1, (int)($order['quantity'] ?? 1));
    $earnedPoints = (int)round(($basePoints * $quantity) * (1 + ($bonusPercent / 100)));

    $wallet = $db->fetchOne("SELECT * FROM user_rewards WHERE user_id = ?", [$customerId]);
    if (!$wallet) {
        $db->query("INSERT INTO user_rewards (user_id, total_points, redeemed_points, tier) VALUES (?, ?, 0, ?)", [$customerId, 0, $tier]);
        $wallet = ['total_points' => 0];
    }

    $db->query(
        "UPDATE user_rewards SET total_points = ?, tier = ?, updated_at = NOW() WHERE user_id = ?",
        [(int)($wallet['total_points'] ?? 0) + $earnedPoints, $tier, $customerId]
    );

    $label = (strpos(strtolower((string)($product['product_name'] ?? '')), 'refill') !== false) ? 'refill' : 'purchase';
    $description = 'Points earned for delivered order #' . ($order['order_number'] ?? $order['order_id']) . ' (' . ($product['size_kg'] ?? '?') . 'kg ' . $label . ', x' . $quantity . ', +' . $bonusPercent . '% tier bonus)';
    $db->query(
        "INSERT INTO reward_transactions (user_id, points, type, description, created_at) VALUES (?, ?, 'earned', ?, NOW())",
        [$customerId, $earnedPoints, $description]
    );
}

// Get all active riders
$riderRows = $db->select('users');
$riders = array_values(array_filter($riderRows, function($user) {
    return (($user['role'] ?? '') === 'rider') && (($user['status'] ?? '') === 'active');
}));
usort($riders, function($a, $b) {
    return strcasecmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
});

// Handle Assign Rider
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_rider'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $order_id = (int)$_POST['order_id'];
        $rider_id = (int)$_POST['rider_id'];
        
        $sql = "UPDATE orders SET rider_id = ?, order_status = 'preparing', prepared_at = (NOW() AT TIME ZONE 'Asia/Manila'), updated_by = ? WHERE order_id = ?";
        
        if ($db->query($sql, [$rider_id, $_SESSION['user_id'], $order_id])) {
            $success = 'Rider assigned successfully!';
            logActivity('update', 'order', $order_id, "Assigned rider to order");
        } else {
            $error = 'Failed to assign rider';
        }
    }
}

// Handle Update Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $order_id = (int)$_POST['order_id'];
        $new_status = $_POST['new_status'];
        
        $timestamp_field = '';
        switch ($new_status) {
            case 'preparing':
                $timestamp_field = "prepared_at = (NOW() AT TIME ZONE 'Asia/Manila')";
                break;
            case 'out_for_delivery':
                $timestamp_field = "out_for_delivery_at = (NOW() AT TIME ZONE 'Asia/Manila')";
                break;
            case 'delivered':
                $timestamp_field = "delivered_at = (NOW() AT TIME ZONE 'Asia/Manila')";
                $order = $db->fetchOne("SELECT * FROM orders WHERE order_id = ?", [$order_id]);
                if ($order) {
                    $existingSale = $db->fetchOne("SELECT sale_id FROM sales WHERE order_id = ? LIMIT 1", [$order_id]);
                    if (!$existingSale) {
                        $sale_sql = "INSERT INTO sales (order_id, rider_id, sale_amount, sale_date) VALUES (?, ?, ?, ?)";
                        $db->query($sale_sql, [$order_id, $order['rider_id'], $order['total_amount'], date('Y-m-d')]);
                    }
                }
                break;
            case 'cancelled':
                $timestamp_field = "cancelled_at = (NOW() AT TIME ZONE 'Asia/Manila')";
                break;
        }
        
        $sql = "UPDATE orders SET order_status = ?, $timestamp_field, updated_by = ? WHERE order_id = ?";
        
        if ($db->query($sql, [$new_status, $_SESSION['user_id'], $order_id])) {
            if ($new_status === 'delivered') {
                $updatedOrder = $db->fetchOne("SELECT * FROM orders WHERE order_id = ?", [$order_id]);
                $rewardProduct = $db->fetchOne("SELECT * FROM products WHERE product_id = ?", [$updatedOrder['product_id'] ?? 0]);
                if ($updatedOrder && $rewardProduct) {
                    awardDeliveredOrderRewards($db, $updatedOrder, $rewardProduct);
                }
            }
            $success = 'Order status updated successfully!';
            logActivity('update', 'order', $order_id, "Updated status to: $new_status");
        } else {
            $error = 'Failed to update status';
        }
    }
}

// Get filter
$status_filter = $_GET['status'] ?? 'all';
$method_filter = strtolower((string)($_GET['method'] ?? 'all'));
if (!in_array($method_filter, ['all', 'cod', 'pickup'], true)) {
    $method_filter = 'all';
}

// Build query
$where = "WHERE 1=1";
$params = [];

if ($status_filter !== 'all') {
    $where .= " AND o.order_status = ?";
    $params[] = $status_filter;
}

// Get orders
$allOrders = $db->select('orders');

// Sort newest first
usort($allOrders, function($a, $b) {
    return pgasUtcToManilaTimestamp($b['ordered_at'] ?? null) <=> pgasUtcToManilaTimestamp($a['ordered_at'] ?? null);
});

// Apply method + status filters
$filtered = array_values(array_filter($allOrders, function($o) use ($status_filter, $method_filter) {
    $matchesMethod = $method_filter === 'all' || normalizeFulfillmentMethod($o) === $method_filter;
    $matchesStatus = $status_filter === 'all' || (($o['order_status'] ?? '') === $status_filter);
    return $matchesMethod && $matchesStatus;
}));

// Enrich orders (customer/product/brand/rider)
$orders = [];
foreach ($filtered as $order) {
    $customer = $db->select('users', ['user_id' => $order['customer_id']]);
    $customer = $customer[0] ?? [];
    $order['customer_name'] = $customer['full_name'] ?? 'Unknown';
    $order['customer_phone'] = $customer['phone'] ?? '';

    $product = $db->select('products', ['product_id' => $order['product_id']]);
    $product = $product[0] ?? [];
    $order['product_name'] = $product['product_name'] ?? 'Unknown';
    $order['size_kg'] = $product['size_kg'] ?? ($order['size_kg'] ?? '');

    if (!empty($product['brand_id'])) {
        $brand = $db->select('brands', ['brand_id' => $product['brand_id']]);
        $order['brand_name'] = $brand[0]['brand_name'] ?? 'Unknown';
    } else {
        $order['brand_name'] = 'Unknown';
    }

    if (!empty($order['rider_id'])) {
        $rider = $db->select('users', ['user_id' => $order['rider_id']]);
        $order['rider_name'] = $rider[0]['full_name'] ?? 'Unassigned';
    } else {
        $order['rider_name'] = 'Unassigned';
    }

    $order['phase_ui'] = orderPhaseSummary($order, $db);
    $orders[] = $order;
}

// Get statistics
$stats = [
    'all' => count($allOrders),
    'pending' => count(array_filter($allOrders, function($o){ return ($o['order_status'] ?? '') === 'pending'; })),
    'preparing' => count(array_filter($allOrders, function($o){ return ($o['order_status'] ?? '') === 'preparing'; })),
    'out_for_delivery' => count(array_filter($allOrders, function($o){ return ($o['order_status'] ?? '') === 'out_for_delivery'; })),
    'delivered' => count(array_filter($allOrders, function($o){ return ($o['order_status'] ?? '') === 'delivered'; })),
    'cancelled' => count(array_filter($allOrders, function($o){ return ($o['order_status'] ?? '') === 'cancelled'; })),
];
$methodStats = [
    'all' => $stats['all'],
    'cod' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'cod'; })),
    'pickup' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'pickup'; })),
];
$statusStatsByMethod = [
    'all' => $stats,
    'cod' => [
        'all' => $methodStats['cod'],
        'pending' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'cod' && ($o['order_status'] ?? '') === 'pending'; })),
        'preparing' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'cod' && ($o['order_status'] ?? '') === 'preparing'; })),
        'out_for_delivery' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'cod' && ($o['order_status'] ?? '') === 'out_for_delivery'; })),
        'delivered' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'cod' && ($o['order_status'] ?? '') === 'delivered'; })),
        'cancelled' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'cod' && ($o['order_status'] ?? '') === 'cancelled'; })),
    ],
    'pickup' => [
        'all' => $methodStats['pickup'],
        'pending' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'pickup' && ($o['order_status'] ?? '') === 'pending'; })),
        'preparing' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'pickup' && ($o['order_status'] ?? '') === 'preparing'; })),
        'out_for_delivery' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'pickup' && ($o['order_status'] ?? '') === 'out_for_delivery'; })),
        'delivered' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'pickup' && ($o['order_status'] ?? '') === 'delivered'; })),
        'cancelled' => count(array_filter($allOrders, function($o){ return normalizeFulfillmentMethod($o) === 'pickup' && ($o['order_status'] ?? '') === 'cancelled'; })),
    ],
];
$currentStatusStats = $statusStatsByMethod[$method_filter] ?? $stats;

// Get single order for details
$viewOrder = null;
if (isset($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    foreach ($allOrders as $o) {
        if ((int)($o['order_id'] ?? 0) === $viewId) {
            $viewOrder = $o;
            break;
        }
    }

    if ($viewOrder) {
        $customer = $db->select('users', ['user_id' => $viewOrder['customer_id']]);
        $customer = $customer[0] ?? [];
        $viewOrder['customer_name'] = $customer['full_name'] ?? 'Unknown';
        $viewOrder['customer_phone'] = $customer['phone'] ?? '';
        $viewOrder['customer_email'] = $customer['email'] ?? '';

        $product = $db->select('products', ['product_id' => $viewOrder['product_id']]);
        $product = $product[0] ?? [];
        $viewOrder['product_name'] = $product['product_name'] ?? 'Unknown';
        $viewOrder['size_kg'] = $product['size_kg'] ?? ($viewOrder['size_kg'] ?? '');
        $viewOrder['price'] = $product['price'] ?? ($viewOrder['price'] ?? 0);

        if (!empty($product['brand_id'])) {
            $brand = $db->select('brands', ['brand_id' => $product['brand_id']]);
            $viewOrder['brand_name'] = $brand[0]['brand_name'] ?? 'Unknown';
        } else {
            $viewOrder['brand_name'] = 'Unknown';
        }

        $viewOrder['phase_ui'] = orderPhaseSummary($viewOrder, $db);

        if (!empty($viewOrder['rider_id'])) {
            $rider = $db->select('users', ['user_id' => $viewOrder['rider_id']]);
            $rider = $rider[0] ?? [];
            $viewOrder['rider_name'] = $rider['full_name'] ?? 'Unassigned';
            $viewOrder['rider_phone'] = $rider['phone'] ?? '';
        } else {
            $viewOrder['rider_name'] = 'Unassigned';
            $viewOrder['rider_phone'] = '';
        }
    }
}


$csrfToken = generateCSRFToken();
include 'includes/header.php';
?>

<style>
/* Enhanced Orders Styling */
.orders-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.orders-stats {
    display: flex;
    gap: 15px;
}

.orders-filter-stack { display:grid; gap:14px; margin-bottom:24px; }
.orders-filter-bar {
    background: white;
    padding: 20px 24px;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-bar-title { font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin-right:4px; }

.filter-chip {
    padding: 10px 20px;
    border: 2px solid #e2e8f0;
    background: white;
    border-radius: 24px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
    text-decoration: none;
    color: #4a5568;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-chip:hover {
    border-color: #667eea;
    background: #f5f7ff;
    transform: translateY(-2px);
}

.filter-chip.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: transparent;
}

.count-badge {
    background: rgba(255,255,255,0.3);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
}

.filter-chip.active .count-badge {
    background: rgba(255,255,255,0.2);
}

/* Enhanced Order Cards */
.orders-grid {
    display: grid;
    gap: 20px;
}

.order-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: all 0.3s;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.order-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 6px;
    height: 100%;
}

.order-card.status-pending::before { background: linear-gradient(135deg, #ff9800 0%, #ffa726 100%); }
.order-card.status-preparing::before { background: linear-gradient(135deg, #2196f3 0%, #42a5f5 100%); }
.order-card.status-out_for_delivery::before { background: linear-gradient(135deg, #9c27b0 0%, #ba68c8 100%); }
.order-card.status-delivered::before { background: linear-gradient(135deg, #4caf50 0%, #66bb6a 100%); }
.order-card.status-cancelled::before { background: linear-gradient(135deg, #f44336 0%, #ef5350 100%); }

.order-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    border-color: #667eea;
}

.order-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f0f0f0;
}

.order-number-section {
    flex: 1;
}

.order-number {
    font-size: 20px;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 6px;
}

.order-date {
    font-size: 13px;
    color: #718096;
}

.order-card-body {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.order-info-group {
    background: #f7fafc;
    padding: 16px;
    border-radius: 12px;
}

.info-label {
    font-size: 12px;
    color: #718096;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.info-value {
    font-size: 15px;
    color: #2d3748;
    font-weight: 600;
}

.info-value.highlight {
    font-size: 20px;
    color: #4caf50;
    font-weight: 800;
}

.order-card-footer {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.rider-badge {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    padding: 10px 16px;
    border-radius: 24px;
    font-size: 13px;
    font-weight: 600;
    color: #667eea;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}


.phase-panel {
    background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%);
    border: 1px solid #d9e7ff;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 18px;
}
.phase-header { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:12px; flex-wrap:wrap; }
.phase-method { font-size: 13px; font-weight: 700; color:#4c51bf; text-transform: uppercase; letter-spacing: .04em; }
.phase-label { font-size: 18px; font-weight: 700; color:#1f2937; margin-top:4px; }
.phase-hint { font-size: 13px; color:#64748b; margin-top:4px; }
.phase-timeline { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.phase-step { border-radius: 12px; border: 1px solid #d7deea; background: white; padding: 10px 12px; min-height: 72px; }
.phase-step-number { width:24px; height:24px; border-radius:999px; background:#e2e8f0; color:#475569; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; margin-bottom:8px; }
.phase-step.active { border-color:#667eea; background:#eef2ff; }
.phase-step.active .phase-step-number { background:#667eea; color:white; }
.phase-step.done { border-color:#86efac; background:#f0fdf4; }
.phase-step.done .phase-step-number { background:#16a34a; color:white; }
.phase-step-title { font-size: 13px; font-weight: 600; color:#334155; line-height:1.3; }
.method-pill { padding:8px 12px; border-radius:999px; background:#ffffff; border:1px solid #d9e7ff; color:#334155; font-size:13px; font-weight:600; white-space:nowrap; }
.pickup-note { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:10px; padding:10px 12px; font-size:13px; font-weight:600; margin-top:12px; }
@media (max-width: 768px) { .phase-timeline { grid-template-columns: 1fr 1fr; } }

/* Modal Improvements */
.modal-detail-section {
    margin-bottom: 24px;
}

.modal-detail-section h4 {
    font-size: 14px;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-grid {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 12px;
}

.detail-row {
    display: contents;
}

.detail-label {
    font-weight: 600;
    color: #718096;
    font-size: 14px;
}

.detail-value {
    color: #2d3748;
    font-size: 14px;
}

/* Improved Close Button */
.btn-close-improved {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #f1f5f9;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    z-index: 10;
}

.btn-close-improved:hover {
    background: #e2e8f0;
    transform: scale(1.1);
}

.btn-close-improved svg {
    color: #475569;
}

.btn-close-improved:hover svg {
    color: #1e293b;
}

/* Fix Modal Content Overflow */
.modal-content {
    word-wrap: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
}

.detail-value {
    word-break: break-word;
    overflow-wrap: anywhere;
}
</style>

<div class="page-header">
    <div class="header-kicker">Operations</div><h1>Orders Management</h1>
    <p>Manage and track all customer orders</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success">
        <span style="font-size: 20px;">✓</span>
        <span><?php echo $success; ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <span style="font-size: 20px;">✗</span>
        <span><?php echo $error; ?></span>
    </div>
<?php endif; ?>

<!-- Enhanced Filter Bar -->
<div class="orders-filter-stack">
    <div class="orders-filter-bar">
        <span class="filter-bar-title">Order Type</span>
        <a href="?method=all&status=all" class="filter-chip <?php echo $method_filter == 'all' ? 'active' : ''; ?>">
            All Orders
            <span class="count-badge"><?php echo $methodStats['all']; ?></span>
        </a>
        <a href="?method=cod&status=all" class="filter-chip <?php echo $method_filter == 'cod' ? 'active' : ''; ?>">
            COD
            <span class="count-badge"><?php echo $methodStats['cod']; ?></span>
        </a>
        <a href="?method=pickup&status=all" class="filter-chip <?php echo $method_filter == 'pickup' ? 'active' : ''; ?>">
            Pick-up
            <span class="count-badge"><?php echo $methodStats['pickup']; ?></span>
        </a>
    </div>

    <div class="orders-filter-bar">
        <span class="filter-bar-title">Phase</span>
        <a href="?method=<?php echo urlencode($method_filter); ?>&status=all" class="filter-chip <?php echo $status_filter == 'all' ? 'active' : ''; ?>">
            All Phases
            <span class="count-badge"><?php echo $currentStatusStats['all']; ?></span>
        </a>
        <a href="?method=<?php echo urlencode($method_filter); ?>&status=pending" class="filter-chip <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">
            <?php echo $method_filter === 'pickup' ? 'Request Received' : 'Order Received'; ?>
            <span class="count-badge"><?php echo $currentStatusStats['pending']; ?></span>
        </a>
        <a href="?method=<?php echo urlencode($method_filter); ?>&status=preparing" class="filter-chip <?php echo $status_filter == 'preparing' ? 'active' : ''; ?>">
            Preparing
            <span class="count-badge"><?php echo $currentStatusStats['preparing']; ?></span>
        </a>
        <a href="?method=<?php echo urlencode($method_filter); ?>&status=out_for_delivery" class="filter-chip <?php echo $status_filter == 'out_for_delivery' ? 'active' : ''; ?>">
            <?php echo $method_filter === 'pickup' ? 'Ready for Pickup' : 'Rider on the Way'; ?>
            <span class="count-badge"><?php echo $currentStatusStats['out_for_delivery']; ?></span>
        </a>
        <a href="?method=<?php echo urlencode($method_filter); ?>&status=delivered" class="filter-chip <?php echo $status_filter == 'delivered' ? 'active' : ''; ?>">
            <?php echo $method_filter === 'pickup' ? 'Picked Up' : 'Delivered'; ?>
            <span class="count-badge"><?php echo $currentStatusStats['delivered']; ?></span>
        </a>
        <a href="?method=<?php echo urlencode($method_filter); ?>&status=cancelled" class="filter-chip <?php echo $status_filter == 'cancelled' ? 'active' : ''; ?>">
            Cancelled
            <span class="count-badge"><?php echo $currentStatusStats['cancelled']; ?></span>
        </a>
    </div>
</div>

<!-- Orders Grid -->
<?php if (empty($orders)): ?>
    <div class="empty-state" style="background: white; padding: 80px 40px; border-radius: 20px; text-align: center;">
        <div style="font-size: 80px; margin-bottom: 20px; opacity: 0.5;">•</div>
        <h3 style="font-size: 24px; margin-bottom: 12px; color: #2d3748;">No Orders Found</h3>
        <p style="color: #718096;">Orders will appear here when customers place them</p>
    </div>
<?php else: ?>
    <div class="orders-grid">
        <?php foreach ($orders as $order): ?>
        <div class="order-card status-<?php echo $order['order_status']; ?>">
            <div class="order-card-header">
                <div class="order-number-section">
                    <div class="order-number" style="font-size: 16px; font-weight: 700; color: #667eea; background: #f0f4ff; padding: 8px 16px; border-radius: 8px; display: inline-block;">
                        #<?php echo htmlspecialchars($order['order_number']); ?>
                    </div>
                    <div class="order-date" style="margin-top: 8px; font-size: 13px; color: #64748b;">
                        <?php echo pgasFormatUtcToManila($order['ordered_at']); ?>
                    </div>
                </div>
                <div>
                    <?php
                    $statusColors = [
                        'pending' => 'warning',
                        'preparing' => 'info',
                        'out_for_delivery' => 'primary',
                        'delivered' => 'success',
                        'cancelled' => 'danger'
                    ];
                    $color = $statusColors[$order['order_status']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?php echo $color; ?>" style="font-size: 14px; padding: 8px 16px;">
                        <?php echo ucwords(str_replace('_', ' ', $order['order_status'])); ?>
                    </span>
                </div>
            </div>
            
            <div class="phase-panel">
                <div class="phase-header">
                    <div>
                        <div class="phase-method"><?php echo htmlspecialchars($order['phase_ui']['method_label']); ?></div>
                        <div class="phase-label"><?php echo htmlspecialchars($order['phase_ui']['phase_label']); ?></div>
                        <div class="phase-hint"><?php echo htmlspecialchars($order['phase_ui']['phase_hint']); ?></div>
                    </div>
                    <div class="method-pill"><?php echo $order['phase_ui']['method_icon']; ?> <?php echo htmlspecialchars($order['phase_ui']['method_label']); ?></div>
                </div>
                <div class="phase-timeline">
                    <?php foreach ($order['phase_ui']['steps'] as $idx => $stepTitle): $stepNo = $idx + 1; $stepClass = ''; if ($order['phase_ui']['active_step'] >= $stepNo && $order['order_status'] !== 'cancelled') { $stepClass = ($order['phase_ui']['active_step'] > $stepNo) ? 'done' : 'active'; } ?>
                    <div class="phase-step <?php echo $stepClass; ?>">
                        <div class="phase-step-number"><?php echo $stepNo; ?></div>
                        <div class="phase-step-title"><?php echo htmlspecialchars($stepTitle); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($order['phase_ui']['schedule'] !== ''): ?>
                    <div class="pickup-note">Pickup schedule: <?php echo htmlspecialchars($order['phase_ui']['schedule']); ?></div>
                <?php endif; ?>
            </div>

            <div class="order-card-body">
                <div class="order-info-group">
                    <div class="info-label">Customer</div>
                    <div class="info-value"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                    <div style="font-size: 12px; color: #a0aec0; margin-top: 4px;">
                        <?php echo htmlspecialchars($order['customer_phone']); ?>
                    </div>
                </div>
                
                <div class="order-info-group">
                    <div class="info-label">Product</div>
                    <div class="info-value"><?php echo htmlspecialchars($order['brand_name']); ?></div>
                    <div style="font-size: 13px; color: #718096; margin-top: 4px;">
                        <?php echo htmlspecialchars($order['product_name']); ?> (<?php echo $order['size_kg']; ?>kg)
                    </div>
                </div>
                
                <div class="order-info-group">
                    <div class="info-label">Amount</div>
                    <div class="info-value highlight"><?php echo formatCurrency($order['total_amount']); ?></div>
                    <div style="font-size: 12px; color: #a0aec0; margin-top: 4px;">
                        <?php echo htmlspecialchars($order['phase_ui']['method_label']); ?>
                    </div>
                </div>
            </div>
            
            <?php $isPickupOrder = normalizeFulfillmentMethod($order) === 'pickup'; ?>
            <div style="margin-bottom: 16px; display:flex; gap:10px; flex-wrap:wrap;">
                <?php if (!$isPickupOrder): ?>
                    <span class="rider-badge">
                        Rider: <?php echo htmlspecialchars($order['rider_name'] ?: 'Unassigned'); ?>
                    </span>
                <?php endif; ?>
                <?php if ($isPickupOrder): ?>
                    <span class="rider-badge" style="background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%); color:#c2410c;">
                        Pickup Window: <?php echo htmlspecialchars($order['phase_ui']['store_hours']); ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="order-card-footer">
                <a href="?view=<?php echo $order['order_id']; ?>" class="btn-sm btn-primary">
                    View Details
                </a>
                
                <?php if (!$isPickupOrder && $order['order_status'] == 'pending'): ?>
                    <button onclick="assignRider(<?php echo $order['order_id']; ?>)" class="btn-sm btn-primary">
                        Assign Rider
                    </button>
                <?php endif; ?>
                
                <?php if ($order['order_status'] != 'delivered' && $order['order_status'] != 'cancelled'): ?>
                    <button onclick="updateStatus(<?php echo $order['order_id']; ?>, '<?php echo $isPickupOrder ? 'pickup' : 'cod'; ?>', '<?php echo htmlspecialchars($order['order_status'], ENT_QUOTES); ?>')" class="btn-sm btn-primary">
                        Update Status
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- View Order Modal -->
<?php if ($viewOrder): ?>
<div id="viewModal" class="modal active">
    <div class="modal-content" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
        <button class="btn-close-improved" onclick="window.location.href='orders.php'" title="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        <div class="modal-header">
            <h3>Order Details - #<?php echo htmlspecialchars($viewOrder['order_number']); ?></h3>
        </div>
        <div class="modal-body">
            <div class="modal-detail-section">
                <h4>Order Status</h4>
                <span class="badge badge-<?php echo $statusColors[$viewOrder['order_status']] ?? 'secondary'; ?>" style="font-size: 16px; padding: 10px 20px;">
                    <?php echo ucwords(str_replace('_', ' ', $viewOrder['order_status'])); ?>
                </span>
            </div>
            
            <div class="modal-detail-section">
                <div class="phase-panel" style="margin-top: 16px;">
                    <div class="phase-header">
                        <div>
                            <div class="phase-method"><?php echo htmlspecialchars($viewOrder['phase_ui']['method_label']); ?></div>
                            <div class="phase-label"><?php echo htmlspecialchars($viewOrder['phase_ui']['phase_label']); ?></div>
                            <div class="phase-hint"><?php echo htmlspecialchars($viewOrder['phase_ui']['phase_hint']); ?></div>
                        </div>
                        <div class="method-pill"><?php echo $viewOrder['phase_ui']['method_icon']; ?> <?php echo htmlspecialchars($viewOrder['phase_ui']['method_label']); ?></div>
                    </div>
                    <div class="phase-timeline">
                        <?php foreach ($viewOrder['phase_ui']['steps'] as $idx => $stepTitle): $stepNo = $idx + 1; $stepClass = ''; if ($viewOrder['phase_ui']['active_step'] >= $stepNo && $viewOrder['order_status'] !== 'cancelled') { $stepClass = ($viewOrder['phase_ui']['active_step'] > $stepNo) ? 'done' : 'active'; } ?>
                        <div class="phase-step <?php echo $stepClass; ?>">
                            <div class="phase-step-number"><?php echo $stepNo; ?></div>
                            <div class="phase-step-title"><?php echo htmlspecialchars($stepTitle); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($viewOrder['phase_ui']['schedule'] !== ''): ?>
                        <div class="pickup-note">Pickup schedule: <?php echo htmlspecialchars($viewOrder['phase_ui']['schedule']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-detail-section">
                <h4>Customer Information</h4>
                <div class="detail-grid">
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['customer_name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Phone:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['customer_phone']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['customer_email']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label"><?php echo normalizeFulfillmentMethod($viewOrder) === 'pickup' ? 'Pickup Details:' : 'Address:'; ?></div>
                        <div class="detail-value"><?php echo nl2br(htmlspecialchars($viewOrder['delivery_address'])); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Fulfillment:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['phase_ui']['method_label']); ?></div>
                    </div>
                    <?php if (normalizeFulfillmentMethod($viewOrder) === 'pickup'): ?>
                    <div class="detail-row">
                        <div class="detail-label">Store Hours:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['phase_ui']['store_hours']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="modal-detail-section">
                <h4>Product Details</h4>
                <div class="detail-grid">
                    <div class="detail-row">
                        <div class="detail-label">Product:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['brand_name'] . ' ' . $viewOrder['product_name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Size:</div>
                        <div class="detail-value"><?php echo $viewOrder['size_kg']; ?>kg</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Quantity:</div>
                        <div class="detail-value"><?php echo $viewOrder['quantity']; ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Price:</div>
                        <div class="detail-value"><?php echo formatCurrency($viewOrder['price']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Total:</div>
                        <div class="detail-value" style="font-size: 20px; font-weight: 700; color: #4caf50;">
                            <?php echo formatCurrency($viewOrder['total_amount']); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($viewOrder['rider_name']): ?>
            <div class="modal-detail-section">
                <h4>Delivery Rider</h4>
                <div class="detail-grid">
                    <div class="detail-row">
                        <div class="detail-label">Rider:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['rider_name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Contact:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($viewOrder['rider_phone']); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Assign Rider Modal -->
<div id="assignRiderModal" class="modal">
    <div class="modal-content modal-compact" style="max-width: 460px;">
        <button type="button" class="btn-close btn-close-large" aria-label="Close assign rider dialog" onclick="closeModal('assignRiderModal')">&times;</button>
        <div class="modal-header modal-header-compact">
            <div>
                <div class="modal-kicker">Dispatch</div>
                <h3>Assign Delivery Rider</h3>
            </div>
        </div>
        <div class="modal-body modal-body-compact">
            <div class="modal-note-card">
                <div class="modal-note-title">Rider assignment</div>
                <div class="modal-note-text">Assign an active rider so the order can move from <strong>Order received</strong> to <strong>Preparing order</strong>.</div>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="order_id" id="assign_order_id">
                
                <div class="form-group">
                    <label>Select Rider *</label>
                    <select name="rider_id" required class="form-control form-control-lg">
                        <option value="">Choose a rider...</option>
                        <?php foreach ($riders as $rider): ?>
                            <option value="<?php echo $rider['user_id']; ?>">
                                <?php echo htmlspecialchars($rider['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($riders)): ?>
                        <div class="field-helper field-helper-error">No active delivery riders found. Check the rider account status in Users.</div>
                    <?php else: ?>
                        <div class="field-helper">Only active rider accounts appear here.</div>
                    <?php endif; ?>
                </div>
                
                <button type="submit" name="assign_rider" class="btn btn-primary btn-full">
                    Assign Rider
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div id="updateStatusModal" class="modal">
    <div class="modal-content modal-compact" style="max-width: 500px;">
        <button type="button" class="btn-close btn-close-large" aria-label="Close update status dialog" onclick="closeModal('updateStatusModal')">&times;</button>
        <div class="modal-header modal-header-compact">
            <div>
                <div class="modal-kicker">Order Progress</div>
                <h3>Update Order Status</h3>
            </div>
        </div>
        <div class="modal-body modal-body-compact">
            <div id="statusFlowCard" class="modal-note-card">
                <div class="modal-note-title" id="statusFlowTitle">Cash on Delivery flow</div>
                <div class="modal-note-text" id="statusFlowText">Keep the status aligned with the four customer-facing phases shown on the order card.</div>
                <div id="statusFlowSteps" class="mini-phase-list"></div>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="order_id" id="status_order_id">
                
                <div class="form-group">
                    <label>New Status *</label>
                    <select name="new_status" id="status_select" required class="form-control form-control-lg"></select>
                    <div class="field-helper">Cancelled stays available in case the order needs to be stopped.</div>
                </div>
                
                <button type="submit" name="update_status" class="btn btn-primary btn-full">
                    Save Status Update
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const statusFlowConfig = {
    cod: {
        title: 'Cash on Delivery flow',
        text: 'Match the four delivery phases visible to the customer.',
        options: [
            { value: 'pending', label: 'Order received' },
            { value: 'preparing', label: 'Preparing order' },
            { value: 'out_for_delivery', label: 'Rider on the way' },
            { value: 'delivered', label: 'Delivered' },
            { value: 'cancelled', label: 'Cancelled' }
        ]
    },
    pickup: {
        title: 'Branch Pick-up flow',
        text: 'Use the same four pickup phases shown on the order card.',
        options: [
            { value: 'pending', label: 'Pickup request received' },
            { value: 'preparing', label: 'Preparing order' },
            { value: 'out_for_delivery', label: 'Ready for pickup' },
            { value: 'delivered', label: 'Picked up' },
            { value: 'cancelled', label: 'Cancelled' }
        ]
    }
};

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function assignRider(orderId) {
    document.getElementById('assign_order_id').value = orderId;
    document.getElementById('assignRiderModal').classList.add('active');
}

function updateStatus(orderId, method, currentStatus) {
    document.getElementById('status_order_id').value = orderId;
    const config = statusFlowConfig[method] || statusFlowConfig.cod;
    const select = document.getElementById('status_select');
    const title = document.getElementById('statusFlowTitle');
    const text = document.getElementById('statusFlowText');
    const steps = document.getElementById('statusFlowSteps');

    title.textContent = config.title;
    text.textContent = config.text;
    select.innerHTML = '<option value="">Choose status...</option>';
    steps.innerHTML = '';

    config.options.forEach((option, index) => {
        const optionEl = document.createElement('option');
        optionEl.value = option.value;
        optionEl.textContent = option.label;
        if (option.value === currentStatus) {
            optionEl.selected = true;
        }
        select.appendChild(optionEl);

        if (option.value !== 'cancelled') {
            const step = document.createElement('div');
            step.className = 'mini-phase-step' + (option.value === currentStatus ? ' active' : '');
            step.innerHTML = '<span class="mini-phase-number">' + (index + 1) + '</span><span>' + option.label + '</span>';
            steps.appendChild(step);
        }
    });

    document.getElementById('updateStatusModal').classList.add('active');
}

window.addEventListener('click', function(event) {
    ['assignRiderModal', 'updateStatusModal'].forEach(function(id) {
        const modal = document.getElementById(id);
        if (event.target === modal) {
            closeModal(id);
        }
    });
});
</script>

<style>
/* Modal & Form Styles */
.modal { display: none; position: fixed; inset: 0; width: 100%; height: 100%; padding: 24px; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; }
.modal.active { display: flex; }
.modal-content { position: relative; background: white; border-radius: 24px; padding: 0; width: min(92vw, 760px); max-height: min(90vh, 900px); overflow-y: auto; box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28); }
.modal-content.modal-compact { overflow: visible; max-height: none; }
.modal-header { padding: 25px 30px; border-bottom: 1px solid #e8ecf1; background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%); }
.modal-header-compact { padding: 28px 32px 22px; padding-right: 88px; }
.modal-header h3 { font-size: 22px; margin: 0; color: #2d3748; font-weight: 700; }
.modal-kicker { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: #667eea; margin-bottom: 8px; }
.btn-close { border: none; background: transparent; cursor: pointer; color: #94a3b8; line-height: 1; transition: all 0.2s; }
.btn-close-large { position: absolute; top: 16px; right: 16px; width: 48px; height: 48px; border-radius: 14px; background: #eef2f7; font-size: 32px; display:flex; align-items:center; justify-content:center; z-index: 3; }
.btn-close-large:hover { color: #475569; background: #e2e8f0; transform: scale(1.04); }
.modal-body { padding: 30px; }
.modal-body-compact { padding: 26px 32px 32px; }
.modal-note-card { background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%); border: 1px solid #dbe7ff; border-radius: 16px; padding: 16px 18px; margin-bottom: 18px; }
.modal-note-title { font-size: 14px; font-weight: 800; color: #334155; margin-bottom: 6px; }
.modal-note-text { font-size: 13px; color: #64748b; line-height: 1.5; }
.mini-phase-list { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
.mini-phase-step { display:flex; align-items:center; gap: 10px; background: white; border: 1px solid #dbe1ea; border-radius: 12px; padding: 10px 12px; font-size: 13px; font-weight: 600; color: #334155; }
.mini-phase-step.active { border-color: #667eea; background: #eef2ff; }
.mini-phase-number { width: 24px; height: 24px; border-radius: 999px; background: #e2e8f0; display:flex; align-items:center; justify-content:center; font-size: 12px; font-weight: 800; color:#475569; }
.mini-phase-step.active .mini-phase-number { background: #667eea; color: white; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 700; font-size: 14px; color: #4a5568; }
.form-control { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 15px; transition: all 0.3s; background: #fff; }
.form-control-lg { min-height: 52px; }
.form-control:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.12); }
.field-helper { margin-top: 8px; font-size: 12px; color: #64748b; }
.field-helper-error { color: #b91c1c; font-weight: 600; }
.btn { padding: 13px 24px; border: none; border-radius: 12px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.3s; }
.btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; box-shadow: 0 8px 20px rgba(102, 126, 234, 0.26); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(102, 126, 234, 0.34); }
.btn-full { width: 100%; }
.btn-sm { padding: 8px 16px; font-size: 13px; }
.alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 500; }
.alert-success { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); color: #2e7d32; border: 1px solid #a5d6a7; }
.alert-error { background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%); color: #c62828; border: 1px solid #ef9a9a; }
@media (max-width: 640px) {
    .modal { padding: 16px; }
    .modal-body-compact, .modal-header-compact { padding-left: 20px; padding-right: 20px; }
    .mini-phase-list { grid-template-columns: 1fr; }
}
</style>

<?php include 'includes/footer.php'; ?>
