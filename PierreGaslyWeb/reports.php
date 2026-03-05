<?php
/**
 * PIERRE GASLY - Reports & Analytics
 */
require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Reports & Analytics';
$db = Database::getInstance();

// Date range
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date   = $_GET['end']   ?? date('Y-m-t');

// Revenue by Date
try {
    $revenue_data = $db->fetchAll(
        "SELECT sale_date AS date, SUM(sale_amount) AS revenue, COUNT(*) AS orders
         FROM sales
         WHERE sale_date BETWEEN ? AND ?
         GROUP BY sale_date
         ORDER BY sale_date ASC",
        [$start_date, $end_date]
    );
} catch (Exception $e) { $revenue_data = []; }

// Top Products
try {
    $top_products = $db->fetchAll(
        "SELECT p.product_name, b.brand_name, p.size_kg,
                COUNT(o.order_id) AS orders,
                SUM(o.total_amount) AS revenue
         FROM orders o
         JOIN products p ON o.product_id = p.product_id
         JOIN brands b ON p.brand_id = b.brand_id
         WHERE o.order_status = 'delivered'
         GROUP BY p.product_id, p.product_name, b.brand_name, p.size_kg
         ORDER BY orders DESC
         LIMIT 5",
        []
    );
} catch (Exception $e) { $top_products = []; }

// Top Riders
try {
    $top_riders = $db->fetchAll(
        "SELECT u.full_name, COUNT(s.sale_id) AS deliveries, SUM(s.sale_amount) AS revenue
         FROM sales s
         JOIN users u ON s.rider_id = u.user_id
         WHERE s.sale_date BETWEEN ? AND ?
         GROUP BY s.rider_id, u.full_name
         ORDER BY deliveries DESC
         LIMIT 5",
        [$start_date, $end_date]
    );
} catch (Exception $e) { $top_riders = []; }

// Top Customers
try {
    $top_customers = $db->fetchAll(
        "SELECT u.full_name, COUNT(o.order_id) AS orders, SUM(o.total_amount) AS total_spent
         FROM orders o
         JOIN users u ON o.customer_id = u.user_id
         WHERE o.order_status = 'delivered'
         GROUP BY o.customer_id, u.full_name
         ORDER BY total_spent DESC
         LIMIT 5",
        []
    );
} catch (Exception $e) { $top_customers = []; }

// Summary Stats
try {
    $summary = $db->fetchOne(
        "SELECT
            COUNT(DISTINCT o.customer_id) AS total_customers,
            COUNT(o.order_id) AS total_orders,
            COALESCE(SUM(o.total_amount), 0) AS total_revenue,
            COALESCE(AVG(o.total_amount), 0) AS avg_order_value
         FROM orders o
         WHERE o.order_status = 'delivered'",
        []
    );
} catch (Exception $e) { $summary = []; }

include 'includes/header.php';
?>

<div class="page-header">
    <h1>📈 Reports & Analytics</h1>
    <p>Business insights and performance metrics</p>
</div>

<!-- Date Range Filter -->
<div class="dashboard-card" style="margin-bottom:25px;">
    <div class="card-body">
        <form method="GET" style="display:flex;gap:15px;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="margin:0;">
                <label>Start Date</label>
                <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>" class="form-control">
            </div>
            <div class="form-group" style="margin:0;">
                <label>End Date</label>
                <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:25px;">
    <div class="stat-card">
        <div class="stat-label">Total Revenue</div>
        <div class="stat-value"><?= formatCurrency($summary['total_revenue'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= number_format($summary['total_orders'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Customers</div>
        <div class="stat-value"><?= number_format($summary['total_customers'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg Order Value</div>
        <div class="stat-value"><?= formatCurrency($summary['avg_order_value'] ?? 0) ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:25px;">

    <!-- Top Products -->
    <div class="dashboard-card">
        <div class="card-header"><h3>🏆 Top Products</h3></div>
        <div class="card-body">
            <?php if (empty($top_products)): ?>
                <p style="color:#888;text-align:center;">No data yet</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Product</th><th>Orders</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($top_products as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['product_name']) ?><br>
                        <small style="color:#888;"><?= htmlspecialchars($p['brand_name']) ?> · <?= $p['size_kg'] ?>kg</small>
                    </td>
                    <td><?= $p['orders'] ?></td>
                    <td><?= formatCurrency($p['revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Riders -->
    <div class="dashboard-card">
        <div class="card-header"><h3>🚚 Top Riders</h3></div>
        <div class="card-body">
            <?php if (empty($top_riders)): ?>
                <p style="color:#888;text-align:center;">No data yet</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Rider</th><th>Deliveries</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($top_riders as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['full_name']) ?></td>
                    <td><?= $r['deliveries'] ?></td>
                    <td><?= formatCurrency($r['revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Customers -->
    <div class="dashboard-card">
        <div class="card-header"><h3>👥 Top Customers</h3></div>
        <div class="card-body">
            <?php if (empty($top_customers)): ?>
                <p style="color:#888;text-align:center;">No data yet</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Customer</th><th>Orders</th><th>Total Spent</th></tr></thead>
                <tbody>
                <?php foreach ($top_customers as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['full_name']) ?></td>
                    <td><?= $c['orders'] ?></td>
                    <td><?= formatCurrency($c['total_spent']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Revenue by Date -->
    <div class="dashboard-card">
        <div class="card-header"><h3>📊 Revenue by Date</h3></div>
        <div class="card-body">
            <?php if (empty($revenue_data)): ?>
                <p style="color:#888;text-align:center;">No data for selected period</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Orders</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($revenue_data as $row): ?>
                <tr>
                    <td><?= formatDate($row['date']) ?></td>
                    <td><?= $row['orders'] ?></td>
                    <td><?= formatCurrency($row['revenue']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include 'includes/footer.php'; ?>
