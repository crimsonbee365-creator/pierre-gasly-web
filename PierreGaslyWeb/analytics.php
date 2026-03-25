<?php
require_once __DIR__ . '/includes/analytics_helpers.php';
requireAdmin();

$pageTitle = 'Analytics';
$start_date = $_GET['start'] ?? $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end']   ?? $_GET['end_date']   ?? date('Y-m-t');
extract(getAnalyticsData($start_date, $end_date));
$reportQuery = http_build_query(['start' => $start_date, 'end' => $end_date]);
include 'includes/header.php';
?>
<div class="page-header">
    <div class="header-kicker">Performance</div>
    <h1>Analytics</h1>
    <p>Track gross sales, rewards impact, net revenue, customers, and export-ready summaries from one page.</p>
</div>
<style>
.analytics-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.analytics-section,.chart-grid,.table-grid,.reward-grid{display:grid;gap:20px;margin-bottom:24px}
.analytics-section{grid-template-columns:2fr 1fr}
.chart-grid,.table-grid,.reward-grid{grid-template-columns:1fr 1fr}
.analytics-box{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.08);padding:20px}
.analytics-box canvas{width:100%!important;height:320px!important}
.insight-note{color:#667085;font-size:13px;margin-top:6px}
.metric-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.metric-card{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.08);padding:18px}
.metric-card.split-card{padding:0;overflow:hidden}
.split-card .split-header{padding:18px 18px 10px 18px}
.split-card .split-body{display:grid;grid-template-columns:1fr 1px 1fr;align-items:stretch}
.split-card .split-col{padding:14px 18px 18px 18px}
.split-card .divider{background:#e5e7eb;width:1px}
.split-card .split-label{font-size:12px;color:#667085;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px}
.split-card .split-value{font-size:28px;font-weight:700;color:#101828;margin-bottom:4px}
.metric-card .label{font-size:12px;color:#667085;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px}
.metric-card .value{font-size:28px;font-weight:700;color:#101828;margin-bottom:4px}
.metric-card .sub{font-size:12px;color:#667085}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600}
.badge.ok{background:#e8f5e9;color:#2e7d32}.badge.low{background:#fff3e0;color:#ef6c00}.badge.out{background:#ffebee;color:#c62828}
.data-table{width:100%;border-collapse:collapse;table-layout:auto}.data-table thead th{font-size:13px;font-weight:700;color:#344054;text-align:left;padding:12px 14px;border-bottom:1px solid #e5e7eb;white-space:nowrap}.data-table tbody td{padding:12px 14px;border-bottom:1px solid #f0f2f5;vertical-align:top;color:#101828}.data-table tbody tr:last-child td{border-bottom:none}.data-table small{display:block;margin-top:4px;line-height:1.4}.analytics-box h3,.dashboard-card h3{margin:0 0 8px 0}.table-note{color:#667085;font-size:13px;margin:0 0 14px 0}
@media (max-width:1200px){.metric-grid{grid-template-columns:repeat(2,1fr)}.analytics-section,.chart-grid,.table-grid,.reward-grid{grid-template-columns:1fr}}
@media (max-width:700px){.metric-grid{grid-template-columns:1fr}.split-card .split-body{grid-template-columns:1fr}.split-card .divider{display:none}.split-card .split-col + .split-col{border-top:1px solid #e5e7eb}}
</style>

<div class="dashboard-card" style="margin-bottom:24px;">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:15px;align-items:flex-end;flex-wrap:wrap;justify-content:space-between;">
      <div style="display:flex;gap:15px;align-items:flex-end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;"><label>Start Date</label><input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>" class="form-control"></div>
        <div class="form-group" style="margin:0;"><label>End Date</label><input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>" class="form-control"></div>
        <button type="submit" class="btn btn-primary">Filter</button>
      </div>
      <div class="analytics-actions">
        <a class="btn btn-success" href="sales_export_excel.php?<?= htmlspecialchars($reportQuery) ?>">Download Excel</a>
        <a class="btn btn-primary" target="_blank" href="sales_export_pdf.php?<?= htmlspecialchars($reportQuery) ?>">Download PDF</a>
      </div>
    </form>
    <p class="insight-note">Exports now use the same structure as this analytics page: sales flow, rewards impact, top products, customers, and inventory.</p>
  </div>
</div>

<div class="metric-grid">
  <div class="metric-card"><div class="label">Gross Sales</div><div class="value"><?= formatCurrency($summary['gross_sales']) ?></div><div class="sub">Before rewards discounts</div></div>
  <div class="metric-card"><div class="label">Rewards Discounts</div><div class="value"><?= formatCurrency($summary['reward_discounts']) ?></div><div class="sub"><?= number_format($summary['reward_usage_orders']) ?> orders used rewards</div></div>
  <div class="metric-card"><div class="label">Net Revenue</div><div class="value"><?= formatCurrency($summary['net_revenue']) ?></div><div class="sub">Amount actually collected from delivered orders</div></div>

  <div class="metric-card split-card">
    <div class="split-header">
      <div class="label">Delivered Orders</div>
      <div class="sub">Completed orders grouped by fulfillment method</div>
    </div>
    <div class="split-body">
      <div class="split-col">
        <div class="split-label">COD</div>
        <div class="split-value"><?= number_format($summary['delivered_cod_orders']) ?></div>
        <div class="sub">Cash on Delivery completed</div>
      </div>
      <div class="divider"></div>
      <div class="split-col">
        <div class="split-label">Pick-up</div>
        <div class="split-value"><?= number_format($summary['delivered_pickup_orders']) ?></div>
        <div class="sub">Branch pickup completed</div>
      </div>
    </div>
  </div>
  <div class="metric-card"><div class="label">Customer Served</div><div class="value"><?= number_format($summary['total_customers']) ?></div><div class="sub">Total of completed COD and Pick-up orders</div></div>
  <div class="metric-card"><div class="label">Avg Order Value</div><div class="value"><?= formatCurrency($summary['avg_order_value']) ?></div><div class="sub">Based on net revenue</div></div>
</div>

<div class="analytics-section">
  <div class="analytics-box">
    <h3>Sales Flow</h3>
    <p class="insight-note">This compares gross sales, rewards discounts, and final net revenue by date.</p>
    <canvas id="salesFlowChart"></canvas>
  </div>
  <div class="analytics-box">
    <h3>Order Status Breakdown</h3>
    <p class="insight-note">Shows where orders are sitting across the selected period.</p>
    <canvas id="statusChart"></canvas>
  </div>
</div>

<div class="reward-grid">
  <div class="analytics-box">
    <h3>Rewards Impact Summary</h3>
    <table class="data-table">
      <thead><tr><th>Metric</th><th>Value</th></tr></thead>
      <tbody>
        <tr><td>Orders with Rewards Used</td><td><?= number_format($reward_impact['orders_with_rewards']) ?></td></tr>
        <tr><td>Reward Usage Rate</td><td><?= number_format($reward_impact['reward_usage_rate'], 2) ?>%</td></tr>
        <tr><td>Tier Discounts Given</td><td><?= formatCurrency($reward_impact['tier_discounts']) ?></td></tr>
        <tr><td>Total Reward Discounts</td><td><?= formatCurrency($reward_impact['total_reward_discounts']) ?></td></tr>
        <tr><td>Discount Share of Gross Sales</td><td><?= number_format($reward_impact['discount_share_of_gross'], 2) ?>%</td></tr>
        <tr><td>Points Earned</td><td><?= number_format($reward_impact['points_earned']) ?></td></tr>
      </tbody>
    </table>
  </div>
  <div class="analytics-box">
    <h3>Customer Tier Mix on Delivered Orders</h3>
    <p class="insight-note">Useful for showing which tier levels are generating completed sales.</p>
    <canvas id="tierMixChart"></canvas>
  </div>
</div>

<div class="chart-grid">
  <div class="analytics-box">
    <h3>Top LPG by Units Sold</h3>
    <p class="insight-note">Ranks products by actual delivered quantity, not just order count.</p>
    <canvas id="topProductsChart"></canvas>
  </div>
  <div class="analytics-box">
    <h3>Top Customers by Net Revenue</h3>
    <p class="insight-note">Highlights customers contributing the highest delivered revenue.</p>
    <canvas id="topCustomersChart"></canvas>
  </div>
</div>

<div class="table-grid">
  <div class="dashboard-card">
    <div class="card-header"><h3>Top LPG Sales</h3></div>
    <div class="card-body">
      <?php if (empty($top_products)): ?>
        <p style="color:#888;text-align:center;">No delivered LPG sales yet</p>
      <?php else: ?>
        <p class="table-note">Delivered LPG products ranked by units sold, with gross sales before rewards and net revenue after discounts.</p>
        <table class="data-table">
          <thead><tr><th>Product</th><th>Units Sold</th><th>Orders</th><th>Gross Sales</th><th>Net Revenue</th></tr></thead>
          <tbody>
          <?php foreach ($top_products as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['product_name']) ?><br><small style="color:#888;"><?= htmlspecialchars(trim(($p['brand_name'] ?? '') . (($p['size_kg'] !== null && $p['size_kg'] !== '') ? ' · ' . $p['size_kg'] . 'kg' : ''))) ?></small></td>
              <td><?= (int)$p['units_sold'] ?></td>
              <td><?= (int)$p['orders'] ?></td>
              <td><?= formatCurrency($p['gross_sales']) ?></td>
              <td><?= formatCurrency($p['net_revenue']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="dashboard-card">
    <div class="card-header"><h3>Top Customers by Net Revenue</h3></div>
    <div class="card-body">
      <?php if (empty($top_customers)): ?>
        <p style="color:#888;text-align:center;">No customer sales yet</p>
      <?php else: ?>
        <p class="table-note">Customers ranked by delivered order value after reward discounts.</p>
        <table class="data-table">
          <thead><tr><th>Customer</th><th>Orders</th><th>Reward Discounts</th><th>Net Revenue</th></tr></thead>
          <tbody>
          <?php foreach ($top_customers as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['full_name']) ?></td>
              <td><?= (int)$c['orders'] ?></td>
              <td><?= formatCurrency($c['reward_discounts']) ?></td>
              <td><?= formatCurrency($c['total_spent']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="dashboard-card">
    <div class="card-header"><h3>Revenue by Date</h3></div>
    <div class="card-body">
      <?php if (empty($revenue_data)): ?>
        <p style="color:#888;text-align:center;">No data for selected period</p>
      <?php else: ?>
        <p class="table-note">Daily breakdown of delivered orders, units sold, rewards discounts, and final collected revenue.</p>
        <table class="data-table">
          <thead><tr><th>Date</th><th>Orders</th><th>Units</th><th>Gross Sales</th><th>Rewards</th><th>Net Revenue</th></tr></thead>
          <tbody>
          <?php foreach ($revenue_data as $row): ?>
            <tr>
              <td><?= formatDate($row['date']) ?></td>
              <td><?= (int)$row['orders'] ?></td>
              <td><?= (int)$row['units_sold'] ?></td>
              <td><?= formatCurrency($row['gross_sales']) ?></td>
              <td><?= formatCurrency($row['reward_discounts']) ?></td>
              <td><?= formatCurrency($row['net_revenue']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="dashboard-card">
    <div class="card-header"><h3>Inventory Insights</h3></div>
    <div class="card-body">
      <?php if (empty($inventory_insights)): ?>
        <p style="color:#888;text-align:center;">No inventory data yet</p>
      <?php else: ?>
        <p class="table-note">Current stock position to help explain whether sales performance is affected by inventory levels.</p>
        <table class="data-table">
          <thead><tr><th>Product</th><th>Stock</th><th>Minimum</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($inventory_insights as $item): ?>
            <?php $badgeClass = $item['status'] === 'Healthy' ? 'ok' : ($item['status'] === 'Low Stock' ? 'low' : 'out'); ?>
            <tr>
              <td><?= htmlspecialchars($item['product_name']) ?></td>
              <td><?= (int)$item['stock_quantity'] ?></td>
              <td><?= (int)$item['minimum_stock'] ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($item['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const revenueLabels = <?= json_encode(array_map(fn($row) => formatDate($row['date']), $revenue_data)) ?>;
const grossSalesValues = <?= json_encode(array_map(fn($row) => round((float)$row['gross_sales'], 2), $revenue_data)) ?>;
const rewardDiscountValues = <?= json_encode(array_map(fn($row) => round((float)$row['reward_discounts'], 2), $revenue_data)) ?>;
const netRevenueValues = <?= json_encode(array_map(fn($row) => round((float)$row['net_revenue'], 2), $revenue_data)) ?>;
const productLabels = <?= json_encode(array_map(fn($row) => trim($row['product_name'] . (($row['size_kg'] !== null && $row['size_kg'] !== '') ? ' ' . $row['size_kg'] . 'kg' : '')), $top_products)) ?>;
const productUnits = <?= json_encode(array_map(fn($row) => (int)$row['units_sold'], $top_products)) ?>;
const customerLabels = <?= json_encode(array_map(fn($row) => $row['full_name'], $top_customers)) ?>;
const customerSpent = <?= json_encode(array_map(fn($row) => round((float)$row['total_spent'], 2), $top_customers)) ?>;
const statusEntries = <?= json_encode(array_filter($status_summary)) ?>;
const statusLabels = Object.keys(statusEntries).map(v => v.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase()));
const statusValues = Object.values(statusEntries);
const tierMixEntries = <?= json_encode(array_filter($reward_impact['tier_breakdown'])) ?>;
const tierMixLabels = Object.keys(tierMixEntries);
const tierMixValues = Object.values(tierMixEntries);
const peso = value => '₱' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

new Chart(document.getElementById('salesFlowChart'), {
  type:'bar',
  data:{labels:revenueLabels,datasets:[
    {label:'Gross Sales',data:grossSalesValues},
    {label:'Rewards Discounts',data:rewardDiscountValues},
    {label:'Net Revenue',data:netRevenueValues}
  ]},
  options:{
    responsive:true,
    maintainAspectRatio:false,
    interaction:{mode:'index',intersect:false},
    plugins:{tooltip:{callbacks:{label:(ctx)=> `${ctx.dataset.label}: ${peso(ctx.parsed.y)}`}}},
    scales:{y:{beginAtZero:true,ticks:{callback:(value)=> peso(value)}}}
  }
});
new Chart(document.getElementById('statusChart'), {type:'doughnut',data:{labels:statusLabels,datasets:[{data:statusValues}]},options:{responsive:true,maintainAspectRatio:false}});
new Chart(document.getElementById('tierMixChart'), {type:'pie',data:{labels:tierMixLabels,datasets:[{data:tierMixValues}]},options:{responsive:true,maintainAspectRatio:false}});
new Chart(document.getElementById('topProductsChart'), {type:'bar',data:{labels:productLabels,datasets:[{label:'Units Sold',data:productUnits}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}}}});
new Chart(document.getElementById('topCustomersChart'), {type:'bar',data:{labels:customerLabels,datasets:[{label:'Net Revenue',data:customerSpent}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{beginAtZero:true}}}});
</script>
<?php include 'includes/footer.php'; ?>
