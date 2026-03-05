<?php
/**
 * PIERRE GASLY - Improved Dashboard
 * Modern, clean design with better statistics visualization
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Dashboard';

$db = Database::getInstance();

// Get statistics
$sql = "SELECT COALESCE(SUM(sale_amount), 0) as total_today 
        FROM sales 
        WHERE sale_date = CURRENT_DATE";
$salesToday = $db->fetchOne($sql)['total_today'];

$sql = "SELECT COALESCE(SUM(sale_amount), 0) as total_month 
        FROM sales 
        WHERE EXTRACT(MONTH FROM sale_date) = EXTRACT(MONTH FROM CURRENT_DATE) 
        AND EXTRACT(YEAR FROM sale_date) = EXTRACT(YEAR FROM CURRENT_DATE)";
$salesMonth = $db->fetchOne($sql)['total_month'];

$sql = "SELECT COUNT(*) as total FROM orders";
$totalOrders = $db->fetchOne($sql)['total'];

$sql = "SELECT COUNT(*) as total FROM orders WHERE order_status = 'pending'";
$pendingOrders = $db->fetchOne($sql)['total'];

$sql = "SELECT COUNT(*) as total FROM products WHERE is_active = 1";
$totalProducts = $db->fetchOne($sql)['total'];

$sql = "SELECT COUNT(*) as total FROM users WHERE role = 'customer'";
$totalCustomers = $db->fetchOne($sql)['total'];

$sql = "SELECT COUNT(*) as total FROM users WHERE role = 'rider' AND status = 'active'";
$totalRiders = $db->fetchOne($sql)['total'];

$sql = "SELECT COUNT(*) as total FROM users WHERE role = 'sub_admin' AND status = 'active'";
$totalSubAdmins = $db->fetchOne($sql)['total'];

// Recent Orders
$sql = "SELECT o.*, 
        cu.full_name as customer_name,
        p.product_name, p.size_kg,
        b.brand_name,
        r.full_name as rider_name
        FROM orders o
        JOIN users cu ON o.customer_id = cu.user_id
        JOIN products p ON o.product_id = p.product_id
        JOIN brands b ON p.brand_id = b.brand_id
        LEFT JOIN users r ON o.rider_id = r.user_id
        ORDER BY o.ordered_at DESC
        LIMIT 5";
$recentOrders = $db->fetchAll($sql);

// Low Stock Alerts
$sql = "SELECT p.*, b.brand_name
        FROM products p
        JOIN brands b ON p.brand_id = b.brand_id
        WHERE p.stock_quantity <= p.minimum_stock
        AND p.is_active = 1
        ORDER BY p.stock_quantity ASC
        LIMIT 5";
$lowStockProducts = $db->fetchAll($sql);

// Top Products This Month
$sql = "SELECT p.product_name, p.size_kg, b.brand_name, COUNT(o.order_id) as orders
        FROM orders o
        JOIN products p ON o.product_id = p.product_id
        JOIN brands b ON p.brand_id = b.brand_id
        WHERE EXTRACT(MONTH FROM o.ordered_at) = EXTRACT(MONTH FROM CURRENT_DATE)
        AND EXTRACT(YEAR FROM o.ordered_at) = EXTRACT(YEAR FROM CURRENT_DATE)
        GROUP BY p.product_id, p.product_name, p.size_kg, b.brand_name
        ORDER BY orders DESC
        LIMIT 3";
$topProducts = $db->fetchAll($sql);

include 'includes/header.php';
?>

<style>
/* Premium Dashboard Styling */
.dashboard-welcome {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    padding: 40px;
    color: white;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}

.welcome-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.welcome-title h1 {
    font-size: 32px;
    margin-bottom: 8px;
    font-weight: 700;
}

.welcome-subtitle {
    font-size: 16px;
    opacity: 0.9;
}

.welcome-date {
    text-align: right;
    font-size: 14px;
    opacity: 0.9;
}

/* Enhanced Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 28px;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    transition: all 0.3s;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.stat-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.15);
    border-color: #667eea;
}

.stat-card.stat-primary::before { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-card.stat-success::before { background: linear-gradient(135deg, #4caf50 0%, #66bb6a 100%); }
.stat-card.stat-warning::before { background: linear-gradient(135deg, #ff9800 0%, #ffa726 100%); }
.stat-card.stat-info::before { background: linear-gradient(135deg, #2196f3 0%, #42a5f5 100%); }

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
}

.stat-icon {
    font-size: 40px;
    width: 70px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
}

.stat-content {
    flex: 1;
    padding-left: 20px;
}

.stat-value {
    font-size: 36px;
    font-weight: 800;
    margin-bottom: 6px;
    background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.stat-label {
    font-size: 14px;
    color: #718096;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-trend {
    font-size: 12px;
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.trend-up {
    color: #4caf50;
}

.trend-down {
    color: #f44336;
}

/* Dashboard Grid */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap: 24px;
    margin-bottom: 30px;
}

.dashboard-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
    transition: all 0.3s;
}

.dashboard-card:hover {
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
}

.card-header {
    padding: 24px 28px;
    border-bottom: 1px solid #e8ecf1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
}

.card-header h2 {
    font-size: 18px;
    font-weight: 700;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-body {
    padding: 24px 28px;
}

.btn-link {
    color: #667eea;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-link:hover {
    color: #5568d3;
}

/* Order Card */
.order-item {
    padding: 18px 20px;
    background: #f7fafc;
    border-radius: 12px;
    margin-bottom: 14px;
    transition: all 0.3s;
    border: 2px solid transparent;
}

.order-item:hover {
    background: white;
    border-color: #667eea;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.order-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.order-number {
    font-weight: 700;
    color: #667eea;
    font-size: 15px;
}

.order-item-body {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 15px;
    font-size: 13px;
}

.order-info-label {
    color: #718096;
    font-weight: 500;
}

.order-info-value {
    color: #2d3748;
    font-weight: 600;
}

/* Alert Item */
.alert-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px 20px;
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    border-radius: 12px;
    border-left: 4px solid #ff9800;
    margin-bottom: 14px;
    transition: all 0.3s;
}

.alert-item:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(255, 152, 0, 0.2);
}

.alert-icon {
    font-size: 28px;
}

.alert-content {
    flex: 1;
}

.alert-title {
    font-weight: 700;
    margin-bottom: 4px;
    color: #2d3748;
    font-size: 14px;
}

.alert-subtitle {
    font-size: 13px;
    color: #718096;
}

.alert-action {
    margin-left: auto;
}

/* Top Products */
.top-product-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 16px 18px;
    background: #f7fafc;
    border-radius: 12px;
    margin-bottom: 12px;
    transition: all 0.3s;
}

.top-product-item:hover {
    background: white;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.product-rank {
    font-size: 24px;
    font-weight: 800;
    color: #cbd5e0;
    min-width: 30px;
}

.product-rank.rank-1 {
    color: #ffd700;
}

.product-rank.rank-2 {
    color: #c0c0c0;
}

.product-rank.rank-3 {
    color: #cd7f32;
}

.product-details {
    flex: 1;
}

.product-name {
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 4px;
    font-size: 14px;
}

.product-meta {
    font-size: 12px;
    color: #718096;
}

.product-orders {
    font-weight: 700;
    color: #667eea;
    font-size: 16px;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #a0aec0;
}

.empty-state-icon {
    font-size: 48px;
    margin-bottom: 12px;
    opacity: 0.5;
}
</style>

<div class="dashboard-welcome">
    <div class="welcome-header">
        <div class="welcome-title">
            <h1>👋 Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
            <p class="welcome-subtitle">Here's what's happening with your business today</p>
        </div>
        <div class="welcome-date">
            <div><?php echo date('l'); ?></div>
            <div style="font-size: 18px; font-weight: 700;"><?php echo date('F d, Y'); ?></div>
        </div>
    </div>
</div>

<!-- Enhanced Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card stat-primary">
        <div class="stat-header">
            <div class="stat-icon">💰</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo formatCurrency($salesToday); ?></div>
                <div class="stat-label">Sales Today</div>
            </div>
        </div>
    </div>

    <div class="stat-card stat-success">
        <div class="stat-header">
            <div class="stat-icon">📈</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo formatCurrency($salesMonth); ?></div>
                <div class="stat-label">This Month</div>
            </div>
        </div>
    </div>

    <div class="stat-card stat-warning">
        <div class="stat-header">
            <div class="stat-icon">📦</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $pendingOrders; ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
        </div>
    </div>

    <div class="stat-card stat-info">
        <div class="stat-header">
            <div class="stat-icon">🛍️</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $totalOrders; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon">📦</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $totalProducts; ?></div>
                <div class="stat-label">Products</div>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon">👥</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $totalCustomers; ?></div>
                <div class="stat-label">Customers</div>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon">🚚</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $totalRiders; ?></div>
                <div class="stat-label">Riders</div>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon">👨‍💼</div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $totalSubAdmins; ?></div>
                <div class="stat-label">Sub Admins</div>
            </div>
        </div>
    </div>
</div>

<!-- Dashboard Grid -->
<div class="dashboard-grid">
    <!-- Recent Orders -->
    <div class="dashboard-card">
        <div class="card-header">
            <h2>📋 Recent Orders</h2>
            <a href="orders.php" class="btn-link">View All →</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentOrders)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📦</div>
                    <p>No recent orders</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentOrders as $order): ?>
                <div class="order-item">
                    <div class="order-item-header">
                        <span class="order-number">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                        <?php
                        $statusColors = [
                            'pending' => 'warning',
                            'preparing' => 'info',
                            'out_for_delivery' => 'primary',
                            'delivered' => 'success',
                            'cancelled' => 'danger'
                        ];
                        $color = $statusColors[$order['order_status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?php echo $color; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $order['order_status'])); ?>
                        </span>
                    </div>
                    <div class="order-item-body">
                        <div>
                            <div class="order-info-label">Customer</div>
                            <div class="order-info-value"><?php echo htmlspecialchars($order['customer_name']); ?></div>
                        </div>
                        <div>
                            <div class="order-info-label">Amount</div>
                            <div class="order-info-value" style="color: #4caf50;"><?php echo formatCurrency($order['total_amount']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Low Stock Alerts -->
    <div class="dashboard-card">
        <div class="card-header">
            <h2>⚠️ Low Stock Alerts</h2>
            <a href="products.php" class="btn-link">Manage Stock →</a>
        </div>
        <div class="card-body">
            <?php if (empty($lowStockProducts)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">✅</div>
                    <p>All products well stocked!</p>
                </div>
            <?php else: ?>
                <?php foreach ($lowStockProducts as $product): ?>
                <div class="alert-item">
                    <div class="alert-icon">⚠️</div>
                    <div class="alert-content">
                        <div class="alert-title">
                            <?php echo htmlspecialchars($product['brand_name'] . ' ' . $product['product_name']); ?>
                        </div>
                        <div class="alert-subtitle">
                            <strong><?php echo $product['stock_quantity']; ?></strong> units left (Min: <?php echo $product['minimum_stock']; ?>)
                        </div>
                    </div>
                    <div class="alert-action">
                        <a href="products.php?edit=<?php echo $product['product_id']; ?>" class="btn-sm btn-primary">Restock</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Top Products This Month -->
<?php if (!empty($topProducts)): ?>
<div class="dashboard-card">
    <div class="card-header">
        <h2>🏆 Top Products This Month</h2>
        <a href="reports.php" class="btn-link">View Reports →</a>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px;">
            <?php foreach ($topProducts as $index => $product): ?>
            <div class="top-product-item">
                <div class="product-rank rank-<?php echo $index + 1; ?>">#<?php echo $index + 1; ?></div>
                <div class="product-details">
                    <div class="product-name"><?php echo htmlspecialchars($product['brand_name'] . ' ' . $product['product_name']); ?></div>
                    <div class="product-meta"><?php echo $product['size_kg']; ?>kg</div>
                </div>
                <div class="product-orders"><?php echo $product['orders']; ?> orders</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>