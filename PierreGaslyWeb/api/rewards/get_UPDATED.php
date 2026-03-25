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
    if ($isList) {
        return array_values(array_filter($value, 'is_array'));
    }
    return [];
}

function findDbUser(SupabaseClient $supabase, string $authUserId, string $email): ?array {
    if ($authUserId !== '') {
        $rows = normalizeRowList($supabase->select('users', ['auth_user_id' => $authUserId], '*', true));
        if (!empty($rows)) return $rows[0];
    }
    if ($email !== '') {
        $rows = normalizeRowList($supabase->select('users', ['email' => $email], '*', true));
        if (!empty($rows)) return $rows[0];
    }
    return null;
}

function getSettingValue(array $rows, string $key, $default = null) {
    foreach ($rows as $row) {
        if (($row['setting_key'] ?? '') === $key) {
            return $row['setting_value'] ?? $default;
        }
    }
    return $default;
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

    $dbUser = findDbUser($supabase, $authUserId, $email);
    if (empty($dbUser)) {
        sendError('User record not found', 404);
    }
    $customerId = (int)($dbUser['user_id'] ?? 0);
    if ($customerId < 1) {
        sendError('User record not found', 404);
    }

    // Load all reward settings
    $settingsRows = normalizeRowList($supabase->select('rewards_settings', [], '*', true));
    $settings = [
        'purchase_7kg_points' => (int)getSettingValue($settingsRows, 'purchase_7kg_points', 60),
        'refill_7kg_points' => (int)getSettingValue($settingsRows, 'refill_7kg_points', 50),
        'purchase_11kg_points' => (int)getSettingValue($settingsRows, 'purchase_11kg_points', 100),
        'refill_11kg_points' => (int)getSettingValue($settingsRows, 'refill_11kg_points', 90),
        'purchase_22kg_points' => (int)getSettingValue($settingsRows, 'purchase_22kg_points', 210),
        'refill_22kg_points' => (int)getSettingValue($settingsRows, 'refill_22kg_points', 200),
        'purchase_above_22kg_points' => (int)getSettingValue($settingsRows, 'purchase_above_22kg_points', 250),
        'refill_above_22kg_points' => (int)getSettingValue($settingsRows, 'refill_above_22kg_points', 220),
        'bronze_threshold' => (int)getSettingValue($settingsRows, 'bronze_threshold', 0),
        'silver_threshold' => (int)getSettingValue($settingsRows, 'silver_threshold', 1800),
        'gold_threshold' => (int)getSettingValue($settingsRows, 'gold_threshold', 3300),
        'platinum_threshold' => (int)getSettingValue($settingsRows, 'platinum_threshold', 7000),
        'bronze_discount_pct' => (int)getSettingValue($settingsRows, 'bronze_discount_pct', 0),
        'silver_discount_pct' => (int)getSettingValue($settingsRows, 'silver_discount_pct', 2),
        'gold_discount_pct' => (int)getSettingValue($settingsRows, 'gold_discount_pct', 3),
        'platinum_discount_pct' => (int)getSettingValue($settingsRows, 'platinum_discount_pct', 4),
        'bronze_redemption_points' => (int)getSettingValue($settingsRows, 'bronze_redemption_points', 500),
        'bronze_redemption_value' => (int)getSettingValue($settingsRows, 'bronze_redemption_value', 40),
        'silver_redemption_points' => (int)getSettingValue($settingsRows, 'silver_redemption_points', 1000),
        'silver_redemption_value' => (int)getSettingValue($settingsRows, 'silver_redemption_value', 90),
        'gold_redemption_points' => (int)getSettingValue($settingsRows, 'gold_redemption_points', 1500),
        'gold_redemption_value' => (int)getSettingValue($settingsRows, 'gold_redemption_value', 140),
        'platinum_redemption_points' => (int)getSettingValue($settingsRows, 'platinum_redemption_points', 2000),
        'platinum_redemption_value' => (int)getSettingValue($settingsRows, 'platinum_redemption_value', 190),
        'points_enabled' => (int)getSettingValue($settingsRows, 'points_enabled', 1),
        'rewards_enabled' => (int)getSettingValue($settingsRows, 'rewards_enabled', 1),
    ];

    // Get or create user wallet
    $walletRows = normalizeRowList($supabase->select('user_rewards', ['user_id' => $customerId], '*', true));
    if (empty($walletRows)) {
        $created = $supabase->insert('user_rewards', [
            'user_id' => $customerId,
            'total_points' => 0,
            'redeemed_points' => 0,
            'lifetime_points' => 0,
            'tier' => 'Bronze',
            'created_at' => date('c')
        ], true);
        $walletRows = $created ?: [[
            'user_id' => $customerId,
            'total_points' => 0,
            'redeemed_points' => 0,
            'lifetime_points' => 0,
            'tier' => 'Bronze'
        ]];
    }
    $wallet = $walletRows[0];

    // Determine tier based on lifetime points
    $lifetimePoints = (int)($wallet['lifetime_points'] ?? 0);
    if ($lifetimePoints >= $settings['platinum_threshold']) {
        $currentTier = 'Platinum';
    } elseif ($lifetimePoints >= $settings['gold_threshold']) {
        $currentTier = 'Gold';
    } elseif ($lifetimePoints >= $settings['silver_threshold']) {
        $currentTier = 'Silver';
    } else {
        $currentTier = 'Bronze';
    }

    // Update tier if changed
    if (($wallet['tier'] ?? 'Bronze') !== $currentTier) {
        $updatedWallet = $supabase->update('user_rewards', [
            'tier' => $currentTier,
            'updated_at' => date('c')
        ], ['user_id' => $customerId], true);
        if (!empty($updatedWallet)) {
            $wallet = $updatedWallet[0];
        } else {
            $wallet['tier'] = $currentTier;
        }
    }

    // Get tier-specific values
    $tierDiscountPct = 0;
    $redemptionPoints = 0;
    $redemptionValue = 0;
    
    if ($currentTier === 'Bronze') {
        $tierDiscountPct = $settings['bronze_discount_pct'];
        $redemptionPoints = $settings['bronze_redemption_points'];
        $redemptionValue = $settings['bronze_redemption_value'];
    } elseif ($currentTier === 'Silver') {
        $tierDiscountPct = $settings['silver_discount_pct'];
        $redemptionPoints = $settings['silver_redemption_points'];
        $redemptionValue = $settings['silver_redemption_value'];
    } elseif ($currentTier === 'Gold') {
        $tierDiscountPct = $settings['gold_discount_pct'];
        $redemptionPoints = $settings['gold_redemption_points'];
        $redemptionValue = $settings['gold_redemption_value'];
    } elseif ($currentTier === 'Platinum') {
        $tierDiscountPct = $settings['platinum_discount_pct'];
        $redemptionPoints = $settings['platinum_redemption_points'];
        $redemptionValue = $settings['platinum_redemption_value'];
    }

    $available = max(0, (int)($wallet['total_points'] ?? 0) - (int)($wallet['redeemed_points'] ?? 0));

    // Calculate progress to next tier
    if ($currentTier === 'Platinum') {
        $progressPct = 100;
        $pointsToNext = 0;
        $nextTier = null;
    } else {
        if ($currentTier === 'Bronze') {
            $target = $settings['silver_threshold'];
            $nextTier = 'Silver';
        } elseif ($currentTier === 'Silver') {
            $target = $settings['gold_threshold'];
            $nextTier = 'Gold';
        } else {
            $target = $settings['platinum_threshold'];
            $nextTier = 'Platinum';
        }
        $progressPct = min(100, (int)round(($lifetimePoints / max(1, $target)) * 100));
        $pointsToNext = max(0, $target - $lifetimePoints);
    }

    sendSuccess([
        'total_points' => (int)($wallet['total_points'] ?? 0),
        'redeemed_points' => (int)($wallet['redeemed_points'] ?? 0),
        'available_points' => $available,
        'lifetime_points' => $lifetimePoints,
        'tier' => $currentTier,
        'tier_discount_pct' => $tierDiscountPct,
        'progress_pct' => $progressPct,
        'points_to_next' => $pointsToNext,
        'next_tier' => $nextTier,
        'redemption_points' => $redemptionPoints,
        'redemption_value' => $redemptionValue,
        'points_enabled' => ((int)$settings['points_enabled']) === 1,
        'rewards_enabled' => ((int)$settings['rewards_enabled']) === 1,
        'program' => [
            'purchase_7kg_points' => $settings['purchase_7kg_points'],
            'refill_7kg_points' => $settings['refill_7kg_points'],
            'purchase_11kg_points' => $settings['purchase_11kg_points'],
            'refill_11kg_points' => $settings['refill_11kg_points'],
            'purchase_22kg_points' => $settings['purchase_22kg_points'],
            'refill_22kg_points' => $settings['refill_22kg_points'],
            'purchase_above_22kg_points' => $settings['purchase_above_22kg_points'],
            'refill_above_22kg_points' => $settings['refill_above_22kg_points'],
            'bronze_discount_pct' => $settings['bronze_discount_pct'],
            'silver_discount_pct' => $settings['silver_discount_pct'],
            'gold_discount_pct' => $settings['gold_discount_pct'],
            'platinum_discount_pct' => $settings['platinum_discount_pct'],
        ]
    ], 'Rewards fetched');
} catch (Exception $e) {
    logError('rewards/get error: ' . $e->getMessage());
    sendError('Failed to fetch rewards', 500);
}
