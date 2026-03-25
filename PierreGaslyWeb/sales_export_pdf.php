<?php
require_once __DIR__ . '/includes/analytics_helpers.php';
requireAdmin();
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');
extract(getAnalyticsData($start_date, $end_date));
$title = 'Initial Report ' . date('Y-m-d h-i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body{font-family:Arial,sans-serif;margin:24px;color:#1f2937}
.header{margin-bottom:20px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px}
.card{border:1px solid #d1d5db;border-radius:10px;padding:14px}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.summary .card strong{display:block;font-size:22px;margin-top:6px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border:1px solid #d1d5db;padding:8px;text-align:left;font-size:12px}th{background:#f3f4f6}
canvas{width:100%!important;height:260px!important}.actions{margin-bottom:18px}.actions button{padding:10px 14px;border:0;border-radius:8px;cursor:pointer}
.note{font-size:12px;color:#6b7280;margin-top:6px}
@media print{.actions{display:none}body{margin:10mm}}
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Save as PDF</button></div>
<div class="header">
  <h1>Pierre Gasly LPG Delivery System</h1>
  <h2><?= htmlspecialchars($title) ?></h2>
  <p>Coverage: <?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></p>
  <p>Generated: <?= htmlspecialchars(date('F j, Y g:i A')) ?></p>
</div>
<div class="summary" style="grid-template-columns:repeat(3,1fr);">
  <div class="card">Gross Sales<strong><?= formatCurrency($summary['gross_sales']) ?></strong></div>
  <div class="card">Reward Discounts<strong><?= formatCurrency($summary['reward_discounts']) ?></strong></div>
  <div class="card">Net Revenue<strong><?= formatCurrency($summary['net_revenue']) ?></strong></div>
</div>
<div class="summary" style="grid-template-columns:repeat(3,1fr);">
  <div class="card">Delivered Orders<strong>COD: <?= number_format($summary['delivered_cod_orders']) ?> | Pick-up: <?= number_format($summary['delivered_pickup_orders']) ?></strong><div class="note">Total delivered orders: <?= number_format($summary['total_orders']) ?></div></div>
  <div class="card">Customers Served<strong><?= number_format($summary['total_customers']) ?></strong><div class="note">Combined completed COD and Pick-up orders</div></div>
  <div class="card">Avg Order Value<strong><?= formatCurrency($summary['avg_order_value']) ?></strong></div>
</div>
<div class="grid">
  <div class="card"><h3>Sales Flow</h3><canvas id="pdfSalesFlowChart"></canvas></div>
  <div class="card"><h3>Order Status Breakdown</h3><canvas id="pdfStatusChart"></canvas></div>
</div>
<div class="grid">
  <div class="card">
    <h3>Rewards Impact Summary</h3>
    <table>
      <tr><th>Metric</th><th>Value</th></tr>
      <tr><td>Orders with Rewards Used</td><td><?= number_format($reward_impact['orders_with_rewards']) ?></td></tr>
      <tr><td>Tier Discounts Given</td><td><?= formatCurrency($reward_impact['tier_discounts']) ?></td></tr>
      <tr><td>Total Reward Discounts</td><td><?= formatCurrency($reward_impact['total_reward_discounts']) ?></td></tr>
      <tr><td>Points Earned</td><td><?= number_format($reward_impact['points_earned']) ?></td></tr>
    </table>
  </div>
  <div class="card">
    <h3>Top LPG Sales</h3>
    <table>
      <tr><th>Product</th><th>Units Sold</th><th>Net Revenue</th></tr>
      <?php foreach ($top_products as $row): ?>
      <tr>
        <td><?= htmlspecialchars(trim($row['product_name'] . (($row['size_kg'] !== null && $row['size_kg'] !== '') ? ' ' . $row['size_kg'] . 'kg' : ''))) ?></td>
        <td><?= (int)$row['units_sold'] ?></td>
        <td><?= formatCurrency($row['net_revenue']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<div class="grid">
  <div class="card">
    <h3>Revenue by Date</h3>
    <table>
      <tr><th>Date</th><th>Orders</th><th>Units</th><th>Gross Sales</th><th>Rewards</th><th>Net Revenue</th></tr>
      <?php foreach ($revenue_data as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['date']) ?></td>
        <td><?= (int)$row['orders'] ?></td>
        <td><?= (int)$row['units_sold'] ?></td>
        <td><?= formatCurrency($row['gross_sales']) ?></td>
        <td><?= formatCurrency($row['reward_discounts']) ?></td>
        <td><?= formatCurrency($row['net_revenue']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="card">
    <h3>Inventory Insights</h3>
    <table>
      <tr><th>Product</th><th>Stock</th><th>Status</th></tr>
      <?php foreach ($inventory_insights as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['product_name']) ?></td>
        <td><?= (int)$row['stock_quantity'] ?></td>
        <td><?= htmlspecialchars($row['status']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<script>
const revenueLabels = <?= json_encode(array_map(fn($row) => formatDate($row['date']), $revenue_data)) ?>;
const grossSalesValues = <?= json_encode(array_map(fn($row) => round((float)$row['gross_sales'], 2), $revenue_data)) ?>;
const rewardDiscountValues = <?= json_encode(array_map(fn($row) => round((float)$row['reward_discounts'], 2), $revenue_data)) ?>;
const netRevenueValues = <?= json_encode(array_map(fn($row) => round((float)$row['net_revenue'], 2), $revenue_data)) ?>;
const statusEntries = <?= json_encode(array_filter($status_summary)) ?>;
const statusLabels = Object.keys(statusEntries).map(v => v.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase()));
const statusValues = Object.values(statusEntries);
const peso = value => '₱' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
new Chart(document.getElementById('pdfSalesFlowChart'), {type:'bar',data:{labels:revenueLabels,datasets:[{label:'Gross Sales',data:grossSalesValues},{label:'Rewards Discounts',data:rewardDiscountValues},{label:'Net Revenue',data:netRevenueValues}]},options:{responsive:true,maintainAspectRatio:false,animation:false,interaction:{mode:'index',intersect:false},plugins:{tooltip:{callbacks:{label:(ctx)=> `${ctx.dataset.label}: ${peso(ctx.parsed.y)}`}}},scales:{y:{beginAtZero:true,ticks:{callback:(value)=> peso(value)}}}}});
new Chart(document.getElementById('pdfStatusChart'), {type:'pie',data:{labels:statusLabels,datasets:[{data:statusValues}]},options:{responsive:true,maintainAspectRatio:false,animation:false}});
setTimeout(()=>window.print(),800);
</script>
</body>
</html>
