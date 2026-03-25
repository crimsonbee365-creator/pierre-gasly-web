<?php
/**
 * PIERRE GASLY - Customer Ratings & Reviews
 * View customer feedback for PGas Admin
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Customer Ratings';
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

// Admin response UI intentionally removed.

// Get filter
$rating_filter = $_GET['rating'] ?? 'all';

// Load raw data from Supabase REST wrapper instead of complex SQL joins.
// The Database helper supports simple table reads reliably, but this page
// previously used custom JOIN queries and COUNT filters that were not being
// resolved correctly in the current wrapper.
$allReviews = $db->select('reviews') ?: [];
$allUsers = $db->select('users') ?: [];
$allOrders = $db->select('orders') ?: [];
$allProducts = $db->select('products') ?: [];
$allBrands = $db->select('brands') ?: [];

// Build quick lookup maps
$userMap = [];
foreach ($allUsers as $user) {
    if (isset($user['user_id'])) {
        $userMap[(int)$user['user_id']] = $user;
    }
}

$orderMap = [];
foreach ($allOrders as $order) {
    if (isset($order['order_id'])) {
        $orderMap[(int)$order['order_id']] = $order;
    }
}

$productMap = [];
foreach ($allProducts as $product) {
    if (isset($product['product_id'])) {
        $productMap[(int)$product['product_id']] = $product;
    }
}

$brandMap = [];
foreach ($allBrands as $brand) {
    if (isset($brand['brand_id'])) {
        $brandMap[(int)$brand['brand_id']] = $brand;
    }
}

foreach ($allReviews as $review) {
    $ratingValue = (int)($review['rating'] ?? 0);
    if ($rating_filter !== 'all' && $ratingValue !== (int)$rating_filter) {
        continue;
    }

    $customer = $userMap[(int)($review['customer_id'] ?? 0)] ?? [];
    $order = $orderMap[(int)($review['order_id'] ?? 0)] ?? [];
    $product = $productMap[(int)($order['product_id'] ?? 0)] ?? [];
    $brand = $brandMap[(int)($product['brand_id'] ?? 0)] ?? [];
    $reviewId = (int)($review['review_id'] ?? 0);

    $review['customer_name'] = $customer['full_name'] ?? 'Unknown Customer';
    $review['order_number'] = $order['order_number'] ?? ('ORD-' . str_pad((string)($review['order_id'] ?? 0), 6, '0', STR_PAD_LEFT));
    $review['product_name'] = $product['product_name'] ?? 'Deleted Product';
    $review['brand_name'] = $brand['brand_name'] ?? 'Unknown Brand';

    $reviews[] = $review;
}

usort($reviews, function ($a, $b) {
    return pgasUtcToManilaTimestamp($b['created_at'] ?? null) <=> pgasUtcToManilaTimestamp($a['created_at'] ?? null);
});

$csrfToken = generateCSRFToken();
include 'includes/header.php';
?>

<style>
/* Ratings Page Styling */
.ratings-header-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    padding: 40px;
    color: white;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}

.ratings-header-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 40px;
    align-items: center;
}

.avg-rating-display {
    text-align: center;
}

.avg-rating-number {
    font-size: 72px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 10px;
}

.avg-rating-stars {
    font-size: 32px;
    margin-bottom: 10px;
}

.avg-rating-label {
    font-size: 14px;
    opacity: 0.9;
}

.rating-breakdown {
    flex: 1;
}

.rating-breakdown-item {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 12px;
}

.rating-breakdown-label {
    font-size: 16px;
    font-weight: 600;
    min-width: 80px;
}

.rating-breakdown-bar {
    flex: 1;
    height: 10px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    overflow: hidden;
}

.rating-breakdown-fill {
    height: 100%;
    background: white;
    border-radius: 20px;
    transition: width 0.5s;
}

.rating-breakdown-count {
    font-size: 14px;
    font-weight: 600;
    min-width: 40px;
    text-align: right;
}

/* Filter Bar */
.ratings-filter-bar {
    background: white;
    padding: 20px 24px;
    border-radius: 16px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

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

/* Review Cards */
.review-card {
    background: white;
    border-radius: 16px;
    padding: 28px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: all 0.3s;
    border-left: 6px solid #ffd700;
}

.review-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #f0f0f0;
}

.review-customer-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.review-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 700;
}

.review-customer-details h4 {
    font-size: 18px;
    margin: 0 0 5px;
    color: #2d3748;
}

.review-meta {
    font-size: 13px;
    color: #718096;
}

.review-rating-display {
    text-align: right;
}

.review-stars {
    font-size: 24px;
    margin-bottom: 5px;
}

.review-date {
    font-size: 12px;
    color: #a0aec0;
}

.review-body {
    margin-bottom: 20px;
}

.review-order-info {
    background: #f7fafc;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 13px;
    color: #718096;
    margin-bottom: 15px;
}

.review-text {
    font-size: 15px;
    line-height: 1.6;
    color: #2d3748;
    margin-bottom: 20px;
}

/* Response Section */






/* Response Form */










.empty-state {
    text-align: center;
    padding: 80px 40px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.empty-state-icon {
    font-size: 80px;
    margin-bottom: 20px;
    opacity: 0.5;
}
</style>

<div class="page-header">
    <div class="header-kicker">Feedback</div><h1>Customer Ratings & Reviews</h1>
    <p>Monitor customer satisfaction and respond to feedback</p>
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

<!-- Rating Overview -->
<div class="ratings-header-card">
    <div class="ratings-header-grid">
        <div class="avg-rating-display">
            <div class="avg-rating-number"><?php echo number_format((float)$avg_rating, 1); ?></div>
            <div class="avg-rating-stars">
                <?php 
                $avg_rating_float = (float)$avg_rating;
                $full_stars = floor($avg_rating_float);
                $half_star = ($avg_rating_float - $full_stars) >= 0.5;
                for ($i = 0; $i < $full_stars; $i++) echo '★';
                if ($half_star) echo '★';
                for ($i = 0; $i < (5 - $full_stars - ($half_star ? 1 : 0)); $i++) echo '☆';
                ?>
            </div>
            <div class="avg-rating-label">Based on <?php echo number_format((int)$stats['all']); ?> reviews</div>
        </div>

        <div class="rating-breakdown">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <?php 
                $percentage = $stats['all'] > 0 ? ($stats[$i] / $stats['all']) * 100 : 0;
                ?>
                <div class="rating-breakdown-item">
                    <div class="rating-breakdown-label">
                        <?php echo str_repeat('★', $i); ?>
                    </div>
                    <div class="rating-breakdown-bar">
                        <div class="rating-breakdown-fill" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                    <div class="rating-breakdown-count"><?php echo $stats[$i]; ?></div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="ratings-filter-bar">
    <a href="?rating=all" class="filter-chip <?php echo $rating_filter == 'all' ? 'active' : ''; ?>">
        All Reviews
        <span class="count-badge"><?php echo $stats['all']; ?></span>
    </a>
    <a href="?rating=5" class="filter-chip <?php echo $rating_filter == '5' ? 'active' : ''; ?>">
        5 Stars
        <span class="count-badge"><?php echo $stats['5']; ?></span>
    </a>
    <a href="?rating=4" class="filter-chip <?php echo $rating_filter == '4' ? 'active' : ''; ?>">
        4 Stars
        <span class="count-badge"><?php echo $stats['4']; ?></span>
    </a>
    <a href="?rating=3" class="filter-chip <?php echo $rating_filter == '3' ? 'active' : ''; ?>">
        3 Stars
        <span class="count-badge"><?php echo $stats['3']; ?></span>
    </a>
    <a href="?rating=2" class="filter-chip <?php echo $rating_filter == '2' ? 'active' : ''; ?>">
        2 Stars
        <span class="count-badge"><?php echo $stats['2']; ?></span>
    </a>
    <a href="?rating=1" class="filter-chip <?php echo $rating_filter == '1' ? 'active' : ''; ?>">
        1 Star
        <span class="count-badge"><?php echo $stats['1']; ?></span>
    </a>
</div>

<!-- Reviews List -->
<?php if (empty($reviews)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">•</div>
        <h3 style="font-size: 24px; margin-bottom: 12px; color: #2d3748;">No Reviews Yet</h3>
        <p style="color: #718096;">Customer reviews will appear here once they rate their orders</p>
    </div>
<?php else: ?>
    <?php foreach ($reviews as $review): ?>
        <div class="review-card">
            <div class="review-header">
                <div class="review-customer-info">
                    <div class="review-avatar">
                        <?php echo strtoupper(substr($review['customer_name'], 0, 1)); ?>
                    </div>
                    <div class="review-customer-details">
                        <h4><?php echo htmlspecialchars($review['customer_name']); ?></h4>
                        <div class="review-meta">
                            Order #<?php echo htmlspecialchars($review['order_number']); ?> · 
                            <?php echo htmlspecialchars($review['brand_name'] . ' ' . $review['product_name']); ?>
                        </div>
                    </div>
                </div>
                <div class="review-rating-display">
                    <div class="review-stars">
                        <?php 
                        for ($i = 0; $i < $review['rating']; $i++) echo '★';
                        for ($i = $review['rating']; $i < 5; $i++) echo '☆';
                        ?>
                    </div>
                    <div class="review-date"><?php echo pgasFormatUtcToManila($review['created_at']); ?></div>
                </div>
            </div>

            <div class="review-body">
                <?php if ($review['comment']): ?>
                    <div class="review-text">
                        <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>



<style>
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 500;
}

.alert-success {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.alert-error {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    color: #c62828;
    border: 1px solid #ef9a9a;
}
</style>

<?php include 'includes/footer.php'; ?>
