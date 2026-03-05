<?php
/**
 * GET /api/rewards/get.php
 * Returns current user's rewards data: points, tier, history, progress
 */
require_once __DIR__ . '/../../api_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendError('Method not allowed', 405);
$user = getAuthUser();

// Tier thresholds (completed orders)
$TIERS = [
    'Bronze'   => ['min' => 0,  'max' => 4,  'rate' => 100, 'next' => 'Silver',   'next_at' => 5],
    'Silver'   => ['min' => 5,  'max' => 14, 'rate' => 120, 'next' => 'Gold',     'next_at' => 15],
    'Gold'     => ['min' => 15, 'max' => 29, 'rate' => 150, 'next' => 'Platinum', 'next_at' => 30],
    'Platinum' => ['min' => 30, 'max' => 999,'rate' => 200, 'next' => null,       'next_at' => null],
];

try {
    $pdo = getDB();

    // Get or initialise rewards row
    $stmt = $pdo->prepare("SELECT * FROM user_rewards WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
    $rewards = $stmt->fetch();

    if (!$rewards) {
        $pdo->prepare("INSERT IGNORE INTO user_rewards (user_id, total_points, tier) VALUES (?, 0, 'Bronze')")
            ->execute([$user['user_id']]);
        $rewards = ['total_points' => 0, 'redeemed_points' => 0, 'tier' => 'Bronze'];
    }

    // Count completed orders
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM orders WHERE customer_id = ? AND order_status = 'delivered'");
    $stmt->execute([$user['user_id']]);
    $completedOrders = (int)$stmt->fetchColumn();

    // Recalculate tier based on completed orders & update if changed
    if      ($completedOrders >= 30) $currentTier = 'Platinum';
    elseif  ($completedOrders >= 15) $currentTier = 'Gold';
    elseif  ($completedOrders >= 5)  $currentTier = 'Silver';
    else                             $currentTier = 'Bronze';

    if ($currentTier !== $rewards['tier']) {
        $pdo->prepare("UPDATE user_rewards SET tier = ? WHERE user_id = ?")
            ->execute([$currentTier, $user['user_id']]);
        $rewards['tier'] = $currentTier;
    }

    $tier     = $TIERS[$currentTier];
    $available = (int)$rewards['total_points'] - (int)$rewards['redeemed_points'];

    // Progress to next tier
    $progressPct  = 0;
    $ordersToNext = 0;
    if ($tier['next']) {
        $ordersInThisTier = $completedOrders - $tier['min'];
        $tierRange        = $tier['next_at'] - $tier['min'];
        $progressPct      = min(100, (int)round($ordersInThisTier / $tierRange * 100));
        $ordersToNext     = max(0, $tier['next_at'] - $completedOrders);
    } else {
        $progressPct  = 100;
        $ordersToNext = 0;
    }

    // Recent 10 transactions
    $stmt = $pdo->prepare("
        SELECT tx_id, points, type, description, created_at
        FROM reward_transactions
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user['user_id']]);
    $history = $stmt->fetchAll();

    respond(true, [
        'total_points'     => (int)$rewards['total_points'],
        'redeemed_points'  => (int)$rewards['redeemed_points'],
        'available_points' => $available,
        'tier'             => $currentTier,
        'points_rate'      => $tier['rate'],
        'completed_orders' => $completedOrders,
        'progress_pct'     => $progressPct,
        'orders_to_next'   => $ordersToNext,
        'next_tier'        => $tier['next'],
        'discount_per_500' => 50,
        'history'          => $history,
    ], 'Rewards fetched');

} catch (PDOException $e) {
    logError('rewards/get: ' . $e->getMessage());
    sendError('Failed to fetch rewards', 500);
}
