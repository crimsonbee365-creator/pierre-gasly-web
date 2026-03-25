<?php
require_once __DIR__ . '/config.php';

function analyticsArrayGet(array $row, string $key, $default = null) {
    return array_key_exists($key, $row) ? $row[$key] : $default;
}

function analyticsFloat($value): float {
    if ($value === null || $value === '') return 0.0;
    return (float)$value;
}

function analyticsInt($value, int $default = 0): int {
    if ($value === null || $value === '') return $default;
    return (int)$value;
}

function analyticsOrderDate(array $order): ?string {
    $status = strtolower(trim((string)($order['order_status'] ?? $order['status'] ?? '')));
    $preferred = ($status === 'delivered' || $status === 'completed')
        ? ($order['delivered_at'] ?? $order['completed_at'] ?? $order['created_at'] ?? $order['ordered_at'] ?? null)
        : ($order['created_at'] ?? $order['ordered_at'] ?? $order['order_date'] ?? null);

    if (!$preferred) return null;
    $ts = strtotime((string)$preferred);
    return $ts ? date('Y-m-d', $ts) : null;
}

function analyticsRewardSummary(array $order): array {
    $summary = [];
    $raw = $order['rewards_summary'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $summary = $decoded;
        }
    }
    return $summary;
}

function getAnalyticsData(string $start_date, string $end_date): array {
    $db = Database::getInstance();
    $orders = $db->select('orders');
    $users = $db->select('users');
    $products = $db->select('products');
    $brands = $db->select('brands');
    $rewardTransactions = $db->select('reward_transactions');

    $userMap = [];
    foreach ($users as $user) {
        $userMap[(string)($user['user_id'] ?? '')] = $user;
    }

    $productMap = [];
    foreach ($products as $product) {
        $productMap[(string)($product['product_id'] ?? '')] = $product;
    }

    $brandMap = [];
    foreach ($brands as $brand) {
        $brandMap[(string)($brand['brand_id'] ?? '')] = $brand;
    }

    $rewardTxByOrder = [];
    foreach ($rewardTransactions as $tx) {
        $orderId = (string)($tx['order_id'] ?? '');
        if ($orderId === '') continue;
        if (!isset($rewardTxByOrder[$orderId])) {
            $rewardTxByOrder[$orderId] = [
                'earned_points' => 0,
                'redeemed_points' => 0,
            ];
        }
        $points = (int)($tx['points'] ?? 0);
        $type = strtolower(trim((string)($tx['type'] ?? '')));
        if ($type === 'earned' || $points > 0) {
            $rewardTxByOrder[$orderId]['earned_points'] += max(0, $points);
        } elseif ($type === 'redeemed' || $points < 0) {
            $rewardTxByOrder[$orderId]['redeemed_points'] += abs($points);
        }
    }

    $inRangeOrders = [];
    $deliveredOrders = [];
    $statusSummary = [
        'pending' => 0,
        'preparing' => 0,
        'processing' => 0,
        'out_for_delivery' => 0,
        'completed' => 0,
        'delivered' => 0,
        'cancelled' => 0,
        'other' => 0,
    ];

    foreach ($orders as $order) {
        $orderDate = analyticsOrderDate($order);
        if (!$orderDate || $orderDate < $start_date || $orderDate > $end_date) {
            continue;
        }

        $inRangeOrders[] = $order;
        $status = strtolower(trim((string)($order['order_status'] ?? $order['status'] ?? 'other')));
        if (!array_key_exists($status, $statusSummary)) {
            $status = 'other';
        }
        $statusSummary[$status]++;

        if (in_array($status, ['completed', 'delivered'], true)) {
            $deliveredOrders[] = $order;
        }
    }

    $grossSales = 0.0;
    $netRevenue = 0.0;
    $rewardDiscounts = 0.0;
    $tierDiscounts = 0.0;
    $redemptionDiscounts = 0.0;
    $pointsEarned = 0;
    $pointsRedeemed = 0;
    $rewardUsageOrders = 0;
    $customerIds = [];
    $deliveredCodOrders = 0;
    $deliveredPickupOrders = 0;
    $revenueByDateMap = [];
    $topProductsMap = [];
    $topCustomersMap = [];
    $tierBreakdown = ['Bronze'=>0,'Silver'=>0,'Gold'=>0,'Platinum'=>0,'Unknown'=>0];

    foreach ($deliveredOrders as $order) {
        $summary = analyticsRewardSummary($order);
        $orderId = (string)($order['order_id'] ?? '');
        $txSummary = $rewardTxByOrder[$orderId] ?? ['earned_points' => 0, 'redeemed_points' => 0];

        $amount = analyticsFloat($order['total_amount'] ?? $order['sale_amount'] ?? 0);
        $subtotal = analyticsFloat($order['subtotal_amount'] ?? 0);
        $deliveryFee = analyticsFloat($order['delivery_fee_amount'] ?? 0);
        $tierDiscount = analyticsFloat($order['tier_discount_amount'] ?? ($summary['tier_discount_amount'] ?? 0));
        $redemptionDiscount = analyticsFloat($order['redemption_discount_amount'] ?? ($summary['redemption_discount_amount'] ?? 0));
        $orderRewardDiscount = $tierDiscount + $redemptionDiscount;
        $quantity = analyticsInt($order['quantity'] ?? 1, 1);
        if ($quantity <= 0) $quantity = 1;

        if ($subtotal > 0 || $deliveryFee > 0) {
            $grossOrderSales = $subtotal + $deliveryFee;
        } else {
            $grossOrderSales = $amount + $orderRewardDiscount;
        }

        $grossSales += $grossOrderSales;
        $netRevenue += $amount;
        $rewardDiscounts += $orderRewardDiscount;
        $tierDiscounts += $tierDiscount;
        $redemptionDiscounts += $redemptionDiscount;
        $pointsEarned += analyticsInt($order['reward_points_earned'] ?? ($summary['reward_points_earned'] ?? $txSummary['earned_points'] ?? 0));
        $pointsRedeemed += analyticsInt($order['points_redeemed'] ?? ($summary['points_redeemed'] ?? $txSummary['redeemed_points'] ?? 0));
        if ($orderRewardDiscount > 0) {
            $rewardUsageOrders++;
        }

        $tier = (string)($order['customer_tier'] ?? ($summary['tier'] ?? 'Unknown'));
        if (!isset($tierBreakdown[$tier])) {
            $tierBreakdown['Unknown']++;
        } else {
            $tierBreakdown[$tier]++;
        }

        $customerId = (string)($order['customer_id'] ?? $order['user_id'] ?? '');
        if ($customerId !== '') $customerIds[$customerId] = true;

        $paymentMethod = strtolower(trim((string)($order['payment_method'] ?? '')));
        if ($paymentMethod === 'pickup') {
            $deliveredPickupOrders++;
        } else {
            $deliveredCodOrders++;
        }

        $orderDate = analyticsOrderDate($order) ?? date('Y-m-d');
        if (!isset($revenueByDateMap[$orderDate])) {
            $revenueByDateMap[$orderDate] = [
                'date' => $orderDate,
                'orders' => 0,
                'units_sold' => 0,
                'gross_sales' => 0.0,
                'reward_discounts' => 0.0,
                'net_revenue' => 0.0,
            ];
        }
        $revenueByDateMap[$orderDate]['orders']++;
        $revenueByDateMap[$orderDate]['units_sold'] += $quantity;
        $revenueByDateMap[$orderDate]['gross_sales'] += $grossOrderSales;
        $revenueByDateMap[$orderDate]['reward_discounts'] += $orderRewardDiscount;
        $revenueByDateMap[$orderDate]['net_revenue'] += $amount;

        $productId = (string)($order['product_id'] ?? '');
        $product = $productMap[$productId] ?? null;
        $brand = ($product && !empty($product['brand_id'])) ? ($brandMap[(string)$product['brand_id']] ?? null) : null;
        $productName = $product['product_name'] ?? $order['product_name_snapshot'] ?? 'Unknown Product';
        $productKey = $productId !== '' ? $productId : $productName;
        if (!isset($topProductsMap[$productKey])) {
            $topProductsMap[$productKey] = [
                'product_name' => $productName,
                'brand_name' => $brand['brand_name'] ?? '',
                'size_kg' => $product['size_kg'] ?? null,
                'orders' => 0,
                'units_sold' => 0,
                'gross_sales' => 0.0,
                'reward_discounts' => 0.0,
                'net_revenue' => 0.0,
            ];
        }
        $topProductsMap[$productKey]['orders']++;
        $topProductsMap[$productKey]['units_sold'] += $quantity;
        $topProductsMap[$productKey]['gross_sales'] += $grossOrderSales;
        $topProductsMap[$productKey]['reward_discounts'] += $orderRewardDiscount;
        $topProductsMap[$productKey]['net_revenue'] += $amount;

        if ($customerId !== '') {
            $customer = $userMap[$customerId] ?? null;
            $customerName = $customer['full_name'] ?? $customer['name'] ?? $customer['email'] ?? 'Unknown Customer';
            if (!isset($topCustomersMap[$customerId])) {
                $topCustomersMap[$customerId] = [
                    'full_name' => $customerName,
                    'orders' => 0,
                    'units_sold' => 0,
                    'gross_sales' => 0.0,
                    'reward_discounts' => 0.0,
                    'total_spent' => 0.0,
                ];
            }
            $topCustomersMap[$customerId]['orders']++;
            $topCustomersMap[$customerId]['units_sold'] += $quantity;
            $topCustomersMap[$customerId]['gross_sales'] += $grossOrderSales;
            $topCustomersMap[$customerId]['reward_discounts'] += $orderRewardDiscount;
            $topCustomersMap[$customerId]['total_spent'] += $amount;
        }
    }

    $inventoryInsights = [];
    foreach ($products as $product) {
        $stock = (int)($product['stock_quantity'] ?? 0);
        $minimum = (int)($product['minimum_stock'] ?? 0);
        $inventoryInsights[] = [
            'product_name' => $product['product_name'] ?? 'Unknown Product',
            'stock_quantity' => $stock,
            'minimum_stock' => $minimum,
            'status' => $stock <= 0 ? 'Out of Stock' : ($stock <= $minimum ? 'Low Stock' : 'Healthy'),
        ];
    }
    usort($inventoryInsights, function($a, $b) {
        $priority = ['Out of Stock'=>0,'Low Stock'=>1,'Healthy'=>2];
        return [$priority[$a['status']] ?? 3, $a['stock_quantity']] <=> [$priority[$b['status']] ?? 3, $b['stock_quantity']];
    });

    $revenueData = array_values($revenueByDateMap);
    usort($revenueData, fn($a, $b) => strcmp($a['date'], $b['date']));

    $topProducts = array_values($topProductsMap);
    usort($topProducts, fn($a, $b) => [$b['units_sold'], $b['net_revenue'], $b['orders']] <=> [$a['units_sold'], $a['net_revenue'], $a['orders']]);

    $topCustomers = array_values($topCustomersMap);
    usort($topCustomers, fn($a, $b) => [$b['total_spent'], $b['orders']] <=> [$a['total_spent'], $a['orders']]);

    $summary = [
        'gross_sales' => round($grossSales, 2),
        'reward_discounts' => round($rewardDiscounts, 2),
        'tier_discounts' => round($tierDiscounts, 2),
        'redemption_discounts' => round($redemptionDiscounts, 2),
        'net_revenue' => round($netRevenue, 2),
        'profit' => null,
        'profit_note' => 'True profit is not available yet because product cost is not stored in the database.',
        'total_orders' => count($deliveredOrders),
        'orders_in_range' => count($inRangeOrders),
        'total_customers' => $deliveredCodOrders + $deliveredPickupOrders,
        'unique_customers' => count($customerIds),
        'delivered_cod_orders' => $deliveredCodOrders,
        'delivered_pickup_orders' => $deliveredPickupOrders,
        'avg_order_value' => count($deliveredOrders) > 0 ? round($netRevenue / count($deliveredOrders), 2) : 0,
        'avg_units_per_order' => count($deliveredOrders) > 0 ? round(array_sum(array_column($revenueData, 'units_sold')) / count($deliveredOrders), 2) : 0,
        'reward_usage_orders' => $rewardUsageOrders,
        'reward_usage_rate' => count($deliveredOrders) > 0 ? round(($rewardUsageOrders / count($deliveredOrders)) * 100, 2) : 0,
        'points_earned' => $pointsEarned,
        'points_redeemed' => $pointsRedeemed,
    ];

    $rewardImpact = [
        'orders_with_rewards' => $rewardUsageOrders,
        'reward_usage_rate' => $summary['reward_usage_rate'],
        'tier_discounts' => round($tierDiscounts, 2),
        'redemption_discounts' => round($redemptionDiscounts, 2),
        'total_reward_discounts' => round($rewardDiscounts, 2),
        'points_earned' => $pointsEarned,
        'points_redeemed' => $pointsRedeemed,
        'sales_kept_after_rewards' => round($netRevenue, 2),
        'discount_share_of_gross' => $grossSales > 0 ? round(($rewardDiscounts / $grossSales) * 100, 2) : 0,
        'tier_breakdown' => $tierBreakdown,
    ];

    return [
        'summary' => $summary,
        'revenue_data' => $revenueData,
        'top_products' => array_slice($topProducts, 0, 5),
        'top_customers' => array_slice($topCustomers, 0, 5),
        'inventory_insights' => array_slice($inventoryInsights, 0, 8),
        'status_summary' => $statusSummary,
        'reward_impact' => $rewardImpact,
        'start_date' => $start_date,
        'end_date' => $end_date,
    ];
}
