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
    $isAvailable = filter_var($data['is_available'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $isAvailable = $isAvailable !== false;

    $current = rider_get_availability($riderId);
    $payload = [
        'is_available' => $isAvailable,
        'updated_at' => date('c')
    ];
    if (!empty($current['availability_id'])) {
        $supabase->update('rider_availability', $payload, ['availability_id' => $current['availability_id']], true);
    } else {
        $payload['rider_id'] = $riderId;
        $supabase->insert('rider_availability', $payload, true);
    }

    sendSuccess([
        'is_available' => $isAvailable,
        'availability_status' => $isAvailable ? 'standby' : 'unavailable'
    ], 'Rider availability updated');
} catch (Throwable $e) {
    logError('rider/update-availability error: ' . $e->getMessage());
    sendError('Failed to update availability', 500);
}
