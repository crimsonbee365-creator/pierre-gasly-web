<?php
/**
 * PIERRE GASLY - Sales Management (Improved Layout)
 * Enhanced design with better data visualization
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Sales Management';
$db = Database::getInstance();

// Get filter parameters
$date_filter = $_GET['date'] ?? 'all';
$rider_filter = $_GET['rider'] ?? 'all';

// Build query
$where = ["o.order_status = 'delivered'"];
$params = [];

if ($date_filter === 'today') {
    $where[] = "DATE(s.sale_date) = CURRENT_DATE";
} elseif ($date_filter === 'week') {
    $where[] = "EXTRACT(YEAR FROM s.sale_date) = EXTRACT(YEAR FROM CURRENT_DATE) AND EXTRACT(WEEK FROM s.sale_date) = EXTRACT(WEEK FROM CURRENT_DATE)";
} elseif ($date_filter === 'month') {
    $where[] = "EXTRACT(MONTH FROM s.sale_date) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM s.sale_date) = EXTRACT(YEAR FROM CURRENT_DATE)";
}

if ($rider_filter !== 'all') {
    $where[] = "s.rider_id = ?";
    $params[] = (int)$rider_filter;
}

$where_clause = implode(' AND ', $where);

// Get sales
$sql = "SELECT s.*, o.order_number, o.payment_method, o.quantity,
        cu.full_name as customer_name,
        p.product_name, p.size_kg,
        b.brand_name,
        r.full_name as rider_name
        FROM sales s
        JOIN orders o ON s.order_id = o.order_id
        JOIN users cu ON o.customer_id = cu.user_id
        JOIN products p ON o.product_id = p.product_id
        JOIN brands b ON p.brand_id = b.brand_id
        LEFT JOIN users r ON s.rider_id = r.user_id
        WHERE $where_clause
        ORDER BY s.completed_at DESC";

$sales = $db->fetchAll($sql, $params);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_sales,
    COALESCE(SUM(sale_amount), 0) as total_revenue,
    COALESCE(AVG(sale_amount), 0) as average_sale,
    COALESCE(MAX(sale_amount), 0) as highest_sale
    FROM sales s
    JOIN orders o ON s.order_id = o.order_id
    WHERE $where_clause";

$stats = $db->fetchOne($stats_sql, $params);

// Ensure all keys exist with default values
if (!is_array($stats)) {
    $stats = [];
}
$stats = array_merge([
    'total_sales' => 0,
    'total_revenue' => 0,
    'average_sale' => 0,
    'highest_sale' => 0
], $stats);

// Get riders for filter
$riders = $db->fetchAll("SELECT user_id, full_name FROM users WHERE role = 'rider' ORDER BY full_name");

$csrfToken = generateCSRFToken();
include 'includes/header.php';
?>

<style>
/* Enhanced Sales Styling */
.sales-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.sales-filter-bar {
    background: white;
    padding: 20px 24px;
    border-radius: 16px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filter-section {
    margin-bottom: 20px;
}

.filter-section:last-child {
    margin-bottom: 0;
}

.filter-label {
    font-size: 13px;
    font-weight: 700;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
    display: block;
}

.filter-chips {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
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

.filter-select {
    padding: 10px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s;
    background: white;
}

.filter-select:focus {
    outline: none;
    border-color: #667eea;
}

/* Enhanced Stats Cards */
.sales-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.sales-stat-card {
    background: white;
    padding: 24px;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: all 0.3s;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.sales-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
}

.sales-stat-card.stat-primary::before { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.sales-stat-card.stat-success::before { background: linear-gradient(135deg, #4caf50 0%, #66bb6a 100%); }
.sales-stat-card.stat-info::before { background: linear-gradient(135deg, #2196f3 0%, #42a5f5 100%); }
.sales-stat-card.stat-warning::before { background: linear-gradient(135deg, #ff9800 0%, #ffa726 100%); }

.sales-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    border-color: #667eea;
}

.stat-icon {
    font-size: 36px;
    margin-bottom: 12px;
}

.stat-value {
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 8px;
    background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.stat-label {
    font-size: 13px;
    color: #718096;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Sales Transaction Cards */
.sales-grid {
    display: grid;
    gap: 16px;
}

.sale-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: all 0.3s;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 20px;
    align-items: center;
    border-left: 4px solid #4caf50;
}

.sale-card:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.sale-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.sale-details {
    flex: 1;
}

.sale-number {
    font-size: 14px;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 6px;
}

.sale-product {
    font-size: 15px;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 6px;
}

.sale-meta {
    font-size: 12px;
    color: #718096;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.sale-amount-section {
    text-align: right;
}

.sale-amount {
    font-size: 24px;
    font-weight: 800;
    color: #4caf50;
    margin-bottom: 4px;
}

.sale-date {
    font-size: 12px;
    color: #718096;
}

.empty-sales {
    text-align: center;
    padding: 80px 40px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.empty-sales-icon {
    font-size: 80px;
    margin-bottom: 20px;
    opacity: 0.5;
}
</style>

<div class="page-header">
    <h1>💰 Sales Management</h1>
    <p>Track revenue and completed transactions</p>
</div>

<!-- Statistics Cards -->
<div class="sales-stats-grid">
    <div class="sales-stat-card stat-success">
        <div class="stat-icon">💵</div>
        <div class="stat-value"><?php echo formatCurrency((float)$stats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    
    <div class="sales-stat-card stat-primary">
        <div class="stat-icon">📊</div>
        <div class="stat-value"><?php echo number_format((int)$stats['total_sales']); ?></div>
        <div class="stat-label">Total Sales</div>
    </div>
    
    <div class="sales-stat-card stat-info">
        <div class="stat-icon">📈</div>
        <div class="stat-value"><?php echo formatCurrency((float)$stats['average_sale']); ?></div>
        <div class="stat-label">Average Sale</div>
    </div>
    
    <div class="sales-stat-card stat-warning">
        <div class="stat-icon">🏆</div>
        <div class="stat-value"><?php echo formatCurrency((float)$stats['highest_sale']); ?></div>
        <div class="stat-label">Highest Sale</div>
    </div>
</div>

<!-- Enhanced Filters -->
<div class="sales-filter-bar">
    <div class="filter-section">
        <span class="filter-label">📅 Time Period</span>
        <div class="filter-chips">
            <a href="?date=all&rider=<?php echo $rider_filter; ?>" 
               class="filter-chip <?php echo $date_filter == 'all' ? 'active' : ''; ?>">
                All Time
            </a>
            <a href="?date=today&rider=<?php echo $rider_filter; ?>" 
               class="filter-chip <?php echo $date_filter == 'today' ? 'active' : ''; ?>">
                Today
            </a>
            <a href="?date=week&rider=<?php echo $rider_filter; ?>" 
               class="filter-chip <?php echo $date_filter == 'week' ? 'active' : ''; ?>">
                This Week
            </a>
            <a href="?date=month&rider=<?php echo $rider_filter; ?>" 
               class="filter-chip <?php echo $date_filter == 'month' ? 'active' : ''; ?>">
                This Month
            </a>
        </div>
    </div>
    
    <div class="filter-section">
        <span class="filter-label">🚚 Filter by Rider</span>
        <select onchange="window.location.href='?date=<?php echo $date_filter; ?>&rider='+this.value" 
                class="filter-select">
            <option value="all" <?php echo $rider_filter == 'all' ? 'selected' : ''; ?>>All Riders</option>
            <?php foreach ($riders as $rider): ?>
                <option value="<?php echo $rider['user_id']; ?>" 
                        <?php echo $rider_filter == $rider['user_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($rider['full_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Sales List -->
<?php if (empty($sales)): ?>
    <div class="empty-sales">
        <div class="empty-sales-icon">💰</div>
        <h3 style="font-size: 24px; margin-bottom: 12px; color: #2d3748;">No Sales Found</h3>
        <p style="color: #718096;">Sales transactions will appear here when orders are delivered</p>
    </div>
<?php else: ?>
    <div class="dashboard-card">
        <div class="card-header">
            <h2>💳 Sales Transactions (<?php echo count($sales); ?>)</h2>
        </div>
        <div class="card-body">
            <div class="sales-grid">
                <?php foreach ($sales as $sale): ?>
                <div class="sale-card">
                    <div class="sale-icon">✅</div>
                    
                    <div class="sale-details">
                        <div class="sale-number">#<?php echo htmlspecialchars($sale['order_number']); ?></div>
                        <div class="sale-product">
                            <?php echo htmlspecialchars($sale['brand_name'] . ' ' . $sale['product_name']); ?>
                        </div>
                        <div class="sale-meta">
                            <span>👤 <?php echo htmlspecialchars($sale['customer_name']); ?></span>
                            <span>📦 <?php echo $sale['size_kg']; ?>kg × <?php echo $sale['quantity']; ?></span>
                            <?php if ($sale['rider_name']): ?>
                                <span>🚚 <?php echo htmlspecialchars($sale['rider_name']); ?></span>
                            <?php endif; ?>
                            <span>💳 <?php echo ucfirst($sale['payment_method']); ?></span>
                        </div>
                    </div>
                    
                    <div class="sale-amount-section">
                        <div class="sale-amount"><?php echo formatCurrency($sale['sale_amount']); ?></div>
                        <div class="sale-date"><?php echo formatDate($sale['sale_date']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>