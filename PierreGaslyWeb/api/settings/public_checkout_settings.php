<?php
require_once __DIR__ . '/../supabase_config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed', 405);
}
function getSetting(array $rows, string $key, $default = null) {
    foreach ($rows as $row) {
        if (($row['setting_key'] ?? '') === $key) return $row['setting_value'] ?? $default;
    }
    return $default;
}
try {
    global $supabase;
    $rows = $supabase->select('system_settings', [], '*', true);
    sendSuccess([
        'delivery_fee_tier_1' => (float)getSetting($rows, 'delivery_fee_tier_1', 50),
        'delivery_fee_tier_2' => (float)getSetting($rows, 'delivery_fee_tier_2', 90),
        'delivery_fee_tier_3' => (float)getSetting($rows, 'delivery_fee_tier_3', 130),
        'service_province' => (string)getSetting($rows, 'service_province', 'Pangasinan'),
        'branch_city' => (string)getSetting($rows, 'branch_city', 'Dagupan City'),
        'opening_time' => (string)getSetting($rows, 'opening_time', '08:00'),
        'closing_time' => (string)getSetting($rows, 'closing_time', '18:00'),
        'pickup_enabled' => (string)getSetting($rows, 'pickup_enabled', '1') === '1',
        'cod_enabled' => (string)getSetting($rows, 'cod_enabled', '1') === '1'
    ], 'Checkout settings loaded');
} catch (Exception $e) {
    logError('public_checkout_settings error: ' . $e->getMessage());
    sendError('Failed to load checkout settings', 500);
}
