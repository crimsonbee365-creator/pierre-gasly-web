<?php
require_once __DIR__ . '/includes/analytics_helpers.php';
requireAdmin();
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');
extract(getAnalyticsData($start_date, $end_date));
$filename = 'Initial Report ' . date('Y-m-d h-i A') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
?>
<html>
<head>
<meta charset="UTF-8">
<style>
table{border-collapse:collapse;width:100%;margin-bottom:18px}th,td{border:1px solid #cfcfcf;padding:8px;text-align:left}th{background:#f3f3f3}h1,h2,h3,p{font-family:Arial,sans-serif}
</style>
</head>
<body>
<h1>Pierre Gasly LPG Delivery System</h1>
<h2>Initial Report</h2>
<p>Coverage: <?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></p>
<p>Generated: <?= htmlspecialchars(date('F j, Y g:i A')) ?></p>

<h3>Executive Summary</h3>
<table>
  <tr><th>Gross Sales</th><th>Reward Discounts</th><th>Net Revenue</th><th>Delivered Orders (COD)</th><th>Delivered Orders (Pick-up)</th><th>Total Delivered Orders</th><th>Customers Served</th><th>Average Order Value</th></tr>
  <tr>
    <td><?= htmlspecialchars((string)round((float)$summary['gross_sales'], 2)) ?></td>
    <td><?= htmlspecialchars((string)round((float)$summary['reward_discounts'], 2)) ?></td>
    <td><?= htmlspecialchars((string)round((float)$summary['net_revenue'], 2)) ?></td>
    <td><?= (int)$summary['delivered_cod_orders'] ?></td>
    <td><?= (int)$summary['delivered_pickup_orders'] ?></td>
    <td><?= (int)$summary['total_orders'] ?></td>
    <td><?= (int)$summary['total_customers'] ?></td>
    <td><?= htmlspecialchars((string)round((float)$summary['avg_order_value'], 2)) ?></td>
  </tr>
</table>
<p><strong>Customers Served Note:</strong> This reflects the total completed COD and Pick-up orders within the selected date range.</p>

<h3>Rewards Impact</h3>
<table>
  <tr><th>Orders with Rewards Used</th><th>Tier Discounts</th><th>Total Reward Discounts</th><th>Points Earned</th><th>Discount Share of Gross Sales</th></tr>
  <tr>
    <td><?= (int)$reward_impact['orders_with_rewards'] ?></td>
    <td><?= htmlspecialchars((string)round((float)$reward_impact['tier_discounts'], 2)) ?></td>
    <td><?= htmlspecialchars((string)round((float)$reward_impact['total_reward_discounts'], 2)) ?></td>
    <td><?= (int)$reward_impact['points_earned'] ?></td>
    <td><?= htmlspecialchars((string)round((float)$reward_impact['discount_share_of_gross'], 2)) ?>%</td>
  </tr>
</table>

<h3>Revenue by Date</h3>
<table>
  <tr><th>Date</th><th>Orders</th><th>Units Sold</th><th>Gross Sales</th><th>Rewards Discounts</th><th>Net Revenue</th></tr>
  <?php foreach ($revenue_data as $row): ?>
  <tr>
    <td><?= htmlspecialchars($row['date']) ?></td>
    <td><?= (int)$row['orders'] ?></td>
    <td><?= (int)$row['units_sold'] ?></td>
    <td><?= htmlspecialchars((string)round((float)$row['gross_sales'], 2)) ?></td>
    <td><?= htmlspecialchars((string)round((float)$row['reward_discounts'], 2)) ?></td>
    <td><?= htmlspecialchars((string)round((float)$row['net_revenue'], 2)) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h3>Top LPG Sales</h3>
<table>
  <tr><th>Product</th><th>Brand</th><th>Size (kg)</th><th>Units Sold</th><th>Orders</th><th>Gross Sales</th><th>Net Revenue</th></tr>
  <?php foreach ($top_products as $row): ?>
  <tr>
    <td><?= htmlspecialchars($row['product_name']) ?></td>
    <td><?= htmlspecialchars($row['brand_name'] ?? '') ?></td>
    <td><?= htmlspecialchars((string)($row['size_kg'] ?? '')) ?></td>
    <td><?= (int)$row['units_sold'] ?></td>
    <td><?= (int)$row['orders'] ?></td>
    <td><?= htmlspecialchars((string)round((float)$row['gross_sales'], 2)) ?></td>
    <td><?= htmlspecialchars((string)round((float)$row['net_revenue'], 2)) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h3>Top Customers</h3>
<table>
  <tr><th>Customer</th><th>Orders</th><th>Reward Discounts</th><th>Net Revenue</th></tr>
  <?php foreach ($top_customers as $row): ?>
  <tr>
    <td><?= htmlspecialchars($row['full_name']) ?></td>
    <td><?= (int)$row['orders'] ?></td>
    <td><?= htmlspecialchars((string)round((float)$row['reward_discounts'], 2)) ?></td>
    <td><?= htmlspecialchars((string)round((float)$row['total_spent'], 2)) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<h3>Inventory Insights</h3>
<table>
  <tr><th>Product</th><th>Stock</th><th>Minimum</th><th>Status</th></tr>
  <?php foreach ($inventory_insights as $row): ?>
  <tr>
    <td><?= htmlspecialchars($row['product_name']) ?></td>
    <td><?= (int)$row['stock_quantity'] ?></td>
    <td><?= (int)$row['minimum_stock'] ?></td>
    <td><?= htmlspecialchars($row['status']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
