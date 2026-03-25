<?php
require_once __DIR__ . '/../supabase_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    if ($value === []) return [];
    $isList = array_keys($value) === range(0, count($value) - 1);
    return $isList ? array_values(array_filter($value, 'is_array')) : [$value];
}

function safeSelectRows(string $table, array $filters): array {
    global $supabase;
    try {
        return normalizeRowList($supabase->select($table, $filters, '*', true));
    } catch (Throwable $e) {
        return [];
    }
}

function resolveDbUser(array $authUser): ?array {
    $email = strtolower(trim((string)($authUser['email'] ?? '')));
    $authUserId = trim((string)($authUser['id'] ?? ''));

    if ($authUserId !== '') {
        $rows = safeSelectRows('users', ['auth_user_id' => $authUserId]);
        if (!empty($rows)) return $rows[0];
    }

    if ($email !== '') {
        $rows = safeSelectRows('users', ['email' => $email]);
        if (!empty($rows)) return $rows[0];

        $rows = safeSelectRows('users', []);
        foreach ($rows as $row) {
            if (strtolower(trim((string)($row['email'] ?? ''))) === $email) {
                return $row;
            }
        }
    }

    return null;
}

function reviewTableSpecs(): array {
    return [
        [
            'table' => 'reviews',
            'feedback_column' => 'comment',
            'id_column' => 'review_id',
            'filters' => ['order_id', 'customer_id'],
        ],
        [
            'table' => 'ratings',
            'feedback_column' => 'feedback',
            'id_column' => 'rating_id',
            'filters' => ['order_id', 'customer_id'],
        ],
    ];
}

function ratingPayload(array $spec, int $orderId, int $customerId, int $rating, string $feedback, bool $forUpdate = false): array {
    $payload = [
        'rating' => $rating,
        $spec['feedback_column'] => $feedback,
    ];
    if ($forUpdate) {
        $payload['updated_at'] = date('c');
        return $payload;
    }

    $payload['order_id'] = $orderId;
    $payload['customer_id'] = $customerId;
    $payload['created_at'] = date('c');
    $payload['updated_at'] = date('c');
    return $payload;
}

try {
    global $supabase;

    $token = getBearerToken();
    $authUser = $supabase->getUser($token);
    $dbUser = resolveDbUser($authUser);

    if (empty($dbUser)) {
        sendError('User record not found', 404);
    }

    $customerId = (int)($dbUser['user_id'] ?? 0);
    if ($customerId < 1) {
        sendError('User record not found', 404);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($data['order_id'] ?? 0);
    $rating = (int)($data['rating'] ?? 0);
    $feedback = trim((string)($data['feedback'] ?? ''));

    if ($orderId < 1) {
        sendError('Invalid order ID', 400);
    }

    if ($rating < 1 || $rating > 5) {
        sendError('Rating must be between 1 and 5 stars', 400);
    }

    $orderRows = safeSelectRows('orders', [
        'order_id' => $orderId,
        'customer_id' => $customerId,
    ]);

    if (empty($orderRows)) {
        sendError('Order not found or does not belong to you', 404);
    }

    $order = $orderRows[0];
    $orderStatus = strtolower((string)($order['order_status'] ?? ''));
    if ($orderStatus !== 'delivered') {
        sendError('Can only rate delivered orders', 400);
    }

    foreach (reviewTableSpecs() as $spec) {
        try {
            $existingRows = safeSelectRows($spec['table'], [
                'order_id' => $orderId,
                'customer_id' => $customerId,
            ]);

            if (!empty($existingRows)) {
                $result = $supabase->update(
                    $spec['table'],
                    ratingPayload($spec, $orderId, $customerId, $rating, $feedback, true),
                    [
                        'order_id' => $orderId,
                        'customer_id' => $customerId,
                    ],
                    true
                );

                if (empty($result)) {
                    sendError('Failed to update rating', 500);
                }

                sendSuccess([
                    'order_id' => $orderId,
                    'rating' => $rating,
                    'feedback' => $feedback !== '' ? $feedback : null,
                    'updated' => true,
                    'storage' => $spec['table'],
                ], 'Rating updated successfully');
            }

            $result = $supabase->insert(
                $spec['table'],
                ratingPayload($spec, $orderId, $customerId, $rating, $feedback, false),
                true
            );

            if (!empty($result)) {
                sendSuccess([
                    'order_id' => $orderId,
                    'rating' => $rating,
                    'feedback' => $feedback !== '' ? $feedback : null,
                    'created' => true,
                    'storage' => $spec['table'],
                ], 'Rating submitted successfully');
            }
        } catch (Throwable $tableError) {
            continue;
        }
    }

    sendError('No compatible ratings table was found. Please run the latest schema patch.', 500);
} catch (Exception $e) {
    logError('rate.php error: ' . $e->getMessage());
    sendError('Failed to process rating: ' . $e->getMessage(), 500);
}
