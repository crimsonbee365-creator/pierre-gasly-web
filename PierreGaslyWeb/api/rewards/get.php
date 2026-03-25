<?php
require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}

function getBearerToken(): string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s+(.*)$/i', (string)$auth, $matches)) {
        sendError('Unauthorized', 401);
    }
    return trim($matches[1]);
}

function normalizeRowList($value): array {
    if (!is_array($value)) return [];
    $isList = array_keys($value) === range(0, count($value) - 1);
    return $isList ? array_values(array_filter($value, 'is_array')) : [];
}

function findDbUser(SupabaseClient $supabase, string $email, string $authUserId = ''): ?array {
    if ($email !== '') {
        $rows = normalizeRowList($supabase->select('users', ['email' => $email], '*', true));
        if (!empty($rows)) return $rows[0];
    }
    if ($authUserId !== '') {
        $rows = normalizeRowList($supabase->select('users', ['auth_user_id' => $authUserId], '*', true));
        if (!empty($rows)) return $rows[0];
    }
    return null;
}

function settingsMap(array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        if (isset($row['setting_key'])) {
            $map[$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
    }
    return $map;
}

function getSetting(array $map, string $key, $default = null) {
    return $map[$key] ?? $default;
}

function tierFromLifetimePoints(int $lifetimePoints, array $settings): string {
    if ($lifetimePoints >= (int)getSetting($settings, 'platinum_threshold', 7000)) return 'Platinum';
    if ($lifetimePoints >= (int)getSetting($settings, 'gold_threshold', 3300)) return 'Gold';
    if ($lifetimePoints >= (int)getSetting($settings, 'silver_threshold', 1800)) return 'Silver';
    return 'Bronze';
}

function tierDiscountPercent(string $tier, array $settings): float {
    return match ($tier) {
        'Silver' => (float)getSetting($settings, 'silver_discount_pct', 2),
        'Gold' => (float)getSetting($settings, 'gold_discount_pct', 3),
        'Platinum' => (float)getSetting($settings, 'platinum_discount_pct', 4),
        default => (float)getSetting($settings, 'bronze_discount_pct', 0),
    };
}

function tierRedemptionRule(string $tier, array $settings): array {
    return match ($tier) {
        'Silver' => [
            'points' => (int)getSetting($settings, 'silver_redemption_points', 1000),
            'value' => (int)getSetting($settings, 'silver_redemption_value', 90),
        ],
        'Gold' => [
            'points' => (int)getSetting($settings, 'gold_redemption_points', 1500),
            'value' => (int)getSetting($settings, 'gold_redemption_value', 140),
        ],
        'Platinum' => [
            'points' => (int)getSetting($settings, 'platinum_redemption_points', 2000),
            'value' => (int)getSetting($settings, 'platinum_redemption_value', 190),
        ],
        default => [
            'points' => (int)getSetting($settings, 'bronze_redemption_points', 500),
            'value' => (int)getSetting($settings, 'bronze_redemption_value', 40),
        ],
    };
}

try {
    global $supabase;

    $token = getBearerToken();
    $authUser = $supabase->getUser($token);
    $email = (string)($authUser['email'] ?? '');
    $authUserId = (string)($authUser['id'] ?? '');
    if ($email === '' && $authUserId === '') {
        sendError('Invalid or expired token', 401);
    }

    $dbUser = findDbUser($supabase, $email, $authUserId);
    if (empty($dbUser)) {
        sendError('User record not found', 404);
    }

    $customerId = (int)($dbUser['user_id'] ?? 0);
    if ($customerId < 1) {
        sendError('User record not found', 404);
    }

    $settingsRows = normalizeRowList($supabase->select('rewards_settings', [], '*', true));
    $settings = settingsMap($settingsRows);

    $walletRows = normalizeRowList($supabase->select('user_rewards', ['user_id' => $customerId], '*', true));
    if (empty($walletRows)) {
        $created = $supabase->insert('user_rewards', [
            'user_id' => $customerId,
            'total_points' => 0,
            'redeemed_points' => 0,
            'lifetime_points' => 0,
            'tier' => 'Bronze',
            'created_at' => date('c'),
            'updated_at' => date('c')
        ], true);
        $walletRows = normalizeRowList($created);
    }

    $wallet = $walletRows[0] ?? [
        'total_points' => 0,
        'redeemed_points' => 0,
        'lifetime_points' => 0,
        'tier' => 'Bronze',
    ];

    $lifetimePoints = (int)($wallet['lifetime_points'] ?? ($wallet['total_points'] ?? 0));
    $currentTier = tierFromLifetimePoints($lifetimePoints, $settings);
    if (($wallet['tier'] ?? 'Bronze') !== $currentTier) {
        $updatedWallet = normalizeRowList($supabase->update('user_rewards', [
            'tier' => $currentTier,
            'updated_at' => date('c')
        ], ['user_id' => $customerId], true));
        if (!empty($updatedWallet)) {
            $wallet = $updatedWallet[0];
        } else {
            $wallet['tier'] = $currentTier;
        }
    }

    $availablePoints = max(0, (int)($wallet['total_points'] ?? 0) - (int)($wallet['redeemed_points'] ?? 0));
    $discountPct = tierDiscountPercent($currentTier, $settings);
    $redemptionRule = tierRedemptionRule($currentTier, $settings);

    $nextTier = null;
    $pointsToNext = 0;
    $progressPct = 100;
    if ($currentTier === 'Bronze') {
        $nextTier = 'Silver';
        $target = (int)getSetting($settings, 'silver_threshold', 1800);
    } elseif ($currentTier === 'Silver') {
        $nextTier = 'Gold';
        $target = (int)getSetting($settings, 'gold_threshold', 3300);
    } elseif ($currentTier === 'Gold') {
        $nextTier = 'Platinum';
        $target = (int)getSetting($settings, 'platinum_threshold', 7000);
    } else {
        $target = 0;
    }

    if ($nextTier !== null) {
        $baseThreshold = match ($currentTier) {
            'Silver' => (int)getSetting($settings, 'silver_threshold', 1800),
            'Gold' => (int)getSetting($settings, 'gold_threshold', 3300),
            default => 0,
        };
        $span = max(1, $target - $baseThreshold);
        $progressPct = (int)max(0, min(100, round((($lifetimePoints - $baseThreshold) / $span) * 100)));
        $pointsToNext = max(0, $target - $lifetimePoints);
    }

    $historyRows = normalizeRowList($supabase->select('reward_transactions', ['user_id' => $customerId], '*', true));
    usort($historyRows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    $historyRows = array_slice($historyRows, 0, 12);

    $history = array_map(static function (array $row): array {
        return [
            'tx_id' => (int)($row['tx_id'] ?? 0),
            'points' => (int)($row['points'] ?? 0),
            'type' => (string)($row['type'] ?? 'earned'),
            'description' => (string)($row['description'] ?? ''),
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $historyRows);

    sendSuccess([
        'tier' => $currentTier,
        'total_points' => (int)($wallet['total_points'] ?? 0),
        'redeemed_points' => (int)($wallet['redeemed_points'] ?? 0),
        'available_points' => $availablePoints,
        'lifetime_points' => $lifetimePoints,
        'tier_discount_pct' => $discountPct,
        'redemption_rule' => $redemptionRule,
        'next_tier' => $nextTier,
        'points_to_next_tier' => $pointsToNext,
        'progress_pct' => $progressPct,
        'points_enabled' => ((int)getSetting($settings, 'points_enabled', 1)) === 1,
        'rewards_enabled' => ((int)getSetting($settings, 'rewards_enabled', 1)) === 1,
        'one_redemption_per_order' => ((int)getSetting($settings, 'one_redemption_per_order', 1)) === 1,
        'stacks_with_redemption' => ((int)getSetting($settings, 'tier_discount_stacks_with_redemption', 1)) === 1,
        'free_delivery_rules' => [
            'cluster_1' => (float)getSetting($settings, 'cluster_1_free_credits', 3),
            'cluster_2' => (float)getSetting($settings, 'cluster_2_free_credits', 5),
            'cluster_3' => (float)getSetting($settings, 'cluster_3_free_credits', 10),
            'lpg_credit' => (float)getSetting($settings, 'lpg_free_credit_value', 1),
            'refill_credit' => (float)getSetting($settings, 'refill_free_credit_value', 0.5),
        ],
        'program' => [
            'purchase_7kg_points' => (int)getSetting($settings, 'purchase_7kg_points', 60),
            'refill_7kg_points' => (int)getSetting($settings, 'refill_7kg_points', 50),
            'purchase_11kg_points' => (int)getSetting($settings, 'purchase_11kg_points', 100),
            'refill_11kg_points' => (int)getSetting($settings, 'refill_11kg_points', 90),
            'purchase_22kg_points' => (int)getSetting($settings, 'purchase_22kg_points', 210),
            'refill_22kg_points' => (int)getSetting($settings, 'refill_22kg_points', 200),
            'purchase_above_22kg_points' => (int)getSetting($settings, 'purchase_above_22kg_points', 250),
            'refill_above_22kg_points' => (int)getSetting($settings, 'refill_above_22kg_points', 220),
        ],
        'history' => $history,
    ], 'Rewards fetched');
} catch (Throwable $e) {
    logError('rewards/get error: ' . $e->getMessage());
    sendError('Failed to fetch rewards', 500);
}
