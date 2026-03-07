<?php
/**
 * Pierre Gasly - Products List API
 * GET /api/products/list.php
 * Uses the same Supabase-backed Database layer as the web admin.
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-store, no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
    exit();
}

$brand = !empty($_GET['brand']) ? trim($_GET['brand']) : null;
$size = isset($_GET['size']) && $_GET['size'] !== '' ? (int)$_GET['size'] : null;
$category = !empty($_GET['category']) ? trim($_GET['category']) : null;

try {
    $db = Database::getInstance();
    $sql = "SELECT p.*, c.category_name, b.brand_name
            FROM products p
            JOIN categories c ON p.category_id = c.category_id
            JOIN brands b ON p.brand_id = b.brand_id
            WHERE p.is_active = ?
            ORDER BY b.brand_name, p.size_kg, p.product_name";

    $rows = $db->fetchAll($sql, [1]);

    $rows = array_values(array_filter($rows, function ($row) use ($brand, $size, $category) {
        if ($brand && stripos((string)($row['brand_name'] ?? ''), $brand) === false) {
            return false;
        }
        if ($size !== null && (int)($row['size_kg'] ?? 0) !== $size) {
            return false;
        }
        if ($category && stripos((string)($row['category_name'] ?? ''), $category) === false) {
            return false;
        }
        return true;
    }));

    foreach ($rows as &$row) {
        $image = trim((string)($row['product_image'] ?? ''));
        $row['product_id'] = (int)($row['product_id'] ?? 0);
        $row['size_kg'] = isset($row['size_kg']) ? (int)$row['size_kg'] : null;
        $row['stock_quantity'] = isset($row['stock_quantity']) ? (int)$row['stock_quantity'] : 0;
        $row['minimum_stock'] = isset($row['minimum_stock']) ? (int)$row['minimum_stock'] : 0;
        $row['price'] = isset($row['price']) ? (float)$row['price'] : 0.0;
        $row['image_url'] = $image !== '' ? UPLOAD_URL . 'products/' . rawurlencode($image) : null;
    }
    unset($row);

    echo json_encode([
        'success' => true,
        'message' => count($rows) . ' products found',
        'data' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('[PGAS API] products/list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load products',
        'data' => null,
    ]);
}
