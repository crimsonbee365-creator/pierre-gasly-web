<?php
/**
 * Pierre Gasly - Products List API
 * GET /api/products/list.php
 */
require_once __DIR__ . '/../../api_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendError('Method not allowed', 405);

$brand    = !empty($_GET['brand'])    ? sanitize($_GET['brand'])    : null;
$size     = !empty($_GET['size'])     ? (int)$_GET['size']          : null;
$category = !empty($_GET['category']) ? sanitize($_GET['category']) : null;

$sql = "
    SELECT
        p.product_id,
        p.product_name,
        b.brand_name,
        c.category_name,
        p.size_kg,
        CAST(p.price AS DECIMAL(10,2)) AS price,
        p.stock_quantity,
        p.minimum_stock,
        p.description,
        p.availability,
        p.product_image,
        CASE
            WHEN p.product_image IS NOT NULL AND TRIM(p.product_image) != ''
            THEN CONCAT('" . API_SITE_URL . "uploads/products/', p.product_image)
            ELSE NULL
        END AS image_url
    FROM  products    p
    JOIN  brands      b ON b.brand_id    = p.brand_id
    JOIN  categories  c ON c.category_id = p.category_id
    WHERE p.is_active = 1
";
$params = [];

if ($brand)    { $sql .= " AND b.brand_name LIKE ?";    $params[] = "%$brand%"; }
if ($size)     { $sql .= " AND p.size_kg = ?";           $params[] = $size; }
if ($category) { $sql .= " AND c.category_name LIKE ?"; $params[] = "%$category%"; }

$sql .= " ORDER BY p.stock_quantity DESC, b.brand_name ASC, p.size_kg ASC";

try {
    $stmt = getConnection()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['product_id']     = (int)$r['product_id'];
        $r['size_kg']        = (int)$r['size_kg'];
        $r['stock_quantity'] = (int)$r['stock_quantity'];
        $r['minimum_stock']  = (int)$r['minimum_stock'];
        $r['price']          = (float)$r['price'];
    }
    unset($r);

    sendSuccess($rows, count($rows) . ' products found');
} catch (PDOException $e) {
    logError('products/list: ' . $e->getMessage());
    sendError('Failed to load products', 500);
}
