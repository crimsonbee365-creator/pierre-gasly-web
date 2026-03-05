<?php
/**
 * PIERRE GASLY - Rewards Management (PREMIUM UI/UX)
 * Modern rewards system with full admin configuration
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Rewards Management';
$db = Database::getInstance();

$success = '';
$error = '';

// ══════════════════════════════════════════════════════════════════════════
// REWARDS SETTINGS TABLE (Create if not exists)
// ══════════════════════════════════════════════════════════════════════════
$db->query("
    CREATE TABLE IF NOT EXISTS `rewards_settings` (
      `setting_id`    INT AUTO_INCREMENT PRIMARY KEY,
      `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
      `setting_value` TEXT NOT NULL,
      `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Initialize default settings if empty
$settingCount = $db->fetchOne("SELECT COUNT(*) as c FROM rewards_settings")['c'] ?? 0;
if ($settingCount == 0) {
    $defaults = [
        ['bronze_points_rate', '100'],
        ['silver_points_rate', '120'],
        ['gold_points_rate', '150'],
        ['platinum_points_rate', '200'],
        ['silver_threshold', '5'],
        ['gold_threshold', '15'],
        ['platinum_threshold', '30'],
        ['redemption_rate', '500'],
        ['redemption_value', '50'],
        ['points_enabled', '1'],
    ];
    foreach ($defaults as [$key, $val]) {
        $db->query("INSERT INTO rewards_settings (setting_key, setting_value) VALUES (?, ?)", [$key, $val]);
    }
}

// Get current settings
function getSetting($key, $default = '0') {
    global $db;
    $result = $db->fetchOne("SELECT setting_value FROM rewards_settings WHERE setting_key = ?", [$key]);
    return $result['setting_value'] ?? $default;
}

$settings = [
    'bronze_rate'       => (int)getSetting('bronze_points_rate', '100'),
    'silver_rate'       => (int)getSetting('silver_points_rate', '120'),
    'gold_rate'         => (int)getSetting('gold_points_rate', '150'),
    'platinum_rate'     => (int)getSetting('platinum_points_rate', '200'),
    'silver_threshold'  => (int)getSetting('silver_threshold', '5'),
    'gold_threshold'    => (int)getSetting('gold_threshold', '15'),
    'platinum_threshold'=> (int)getSetting('platinum_threshold', '30'),
    'redemption_rate'   => (int)getSetting('redemption_rate', '500'),
    'redemption_value'  => (int)getSetting('redemption_value', '50'),
    'points_enabled'    => (int)getSetting('points_enabled', '1'),
];

// ══════════════════════════════════════════════════════════════════════════
// HANDLE SETTINGS UPDATE (Master Admin Only)
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if ($_SESSION['role'] !== 'master_admin') {
        $error = 'Only Master Admin can modify rewards settings';
    } elseif (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $updates = [
            'bronze_points_rate'    => max(0, (int)$_POST['bronze_rate']),
            'silver_points_rate'    => max(0, (int)$_POST['silver_rate']),
            'gold_points_rate'      => max(0, (int)$_POST['gold_rate']),
            'platinum_points_rate'  => max(0, (int)$_POST['platinum_rate']),
            'silver_threshold'      => max(1, (int)$_POST['silver_threshold']),
            'gold_threshold'        => max(1, (int)$_POST['gold_threshold']),
            'platinum_threshold'    => max(1, (int)$_POST['platinum_threshold']),
            'redemption_rate'       => max(1, (int)$_POST['redemption_rate']),
            'redemption_value'      => max(1, (int)$_POST['redemption_value']),
            'points_enabled'        => isset($_POST['points_enabled']) ? 1 : 0,
        ];
        
        foreach ($updates as $key => $value) {
            $db->query("UPDATE rewards_settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
        }
        
        $success = 'Rewards settings updated successfully!';
        logActivity('update', 'rewards_settings', 0, 'Updated rewards configuration');
        
        // Refresh settings
        $settings = [
            'bronze_rate'       => $updates['bronze_points_rate'],
            'silver_rate'       => $updates['silver_points_rate'],
            'gold_rate'         => $updates['gold_points_rate'],
            'platinum_rate'     => $updates['platinum_points_rate'],
            'silver_threshold'  => $updates['silver_threshold'],
            'gold_threshold'    => $updates['gold_threshold'],
            'platinum_threshold'=> $updates['platinum_threshold'],
            'redemption_rate'   => $updates['redemption_rate'],
            'redemption_value'  => $updates['redemption_value'],
            'points_enabled'    => $updates['points_enabled'],
        ];
    }
}

// ══════════════════════════════════════════════════════════════════════════
// STATS
// ══════════════════════════════════════════════════════════════════════════
$totalMembers   = $db->fetchOne("SELECT COUNT(*) as c FROM user_rewards")['c'] ?? 0;
$totalPoints    = $db->fetchOne("SELECT COALESCE(SUM(total_points),0) as s FROM user_rewards")['s'] ?? 0;
$totalRedeemed  = $db->fetchOne("SELECT COALESCE(SUM(redeemed_points),0) as s FROM user_rewards")['s'] ?? 0;
$tierCounts     = $db->fetchAll("SELECT tier, COUNT(*) as cnt FROM user_rewards GROUP BY tier");
$tierMap        = ['Bronze'=>0,'Silver'=>0,'Gold'=>0,'Platinum'=>0];
foreach ($tierCounts as $t) $tierMap[$t['tier']] = (int)$t['cnt'];

// ══════════════════════════════════════════════════════════════════════════
// MEMBERS LIST
// ══════════════════════════════════════════════════════════════════════════
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$whereClause = $search ? "AND (u.full_name LIKE ? OR u.email LIKE ?)" : "";
$params = $search ? ["%$search%", "%$search%"] : [];

$total = $db->fetchOne(
    "SELECT COUNT(*) as c FROM user_rewards r JOIN users u ON u.user_id = r.user_id WHERE 1=1 $whereClause",
    $params
)['c'] ?? 0;

$members = $db->fetchAll(
    "SELECT u.user_id, u.full_name, u.email, u.phone,
            r.total_points, r.redeemed_points, r.tier, r.updated_at,
            (SELECT COUNT(*) FROM orders WHERE customer_id=u.user_id AND order_status='delivered') as completed_orders
     FROM user_rewards r
     JOIN users u ON u.user_id = r.user_id
     WHERE 1=1 $whereClause
     ORDER BY r.total_points DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$totalPages = max(1, ceil($total / $perPage));

// ══════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════
function tierEmoji($t) { 
    return match($t) { 'Silver'=>'🥈','Gold'=>'🥇','Platinum'=>'💎',default=>'🥉' }; 
}

function tierColor($t) { 
    return match($t) { 'Silver'=>'#94A3B8','Gold'=>'#F59E0B','Platinum'=>'#A855F7',default=>'#CD7F32' }; 
}

require_once 'includes/header.php';
?>

<style>
/* Match Orders.php Styling */

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: #2d3748;
    margin: 0 0 8px 0;
}

.page-header p {
    font-size: 14px;
    color: #718096;
    margin: 0;
}

/* Alert Styling (matching orders.php) */
.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 500;
    animation: slideDown 0.3s;
}

.alert-success {
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.alert-danger {
    background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
    color: #c62828;
    border: 1px solid #ef9a9a;
}

/* Stats Cards - Full Width Layout */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 16px;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
}

.stat-icon {
    font-size: 2.5rem;
    background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
    width: 70px;
    height: 70px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-details {
    flex: 1;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.875rem;
    color: #718096;
    font-weight: 500;
}

/* Card Styling */
.card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}

.card-header {
    padding: 24px 28px;
    border-bottom: 1px solid #e8ecf1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-title {
    font-size: 18px;
    font-weight: 700;
    color: #2d3748;
    margin: 0;
}

.card-body {
    padding: 28px;
}

/* Tier Grid */
.tier-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.tier-card {
    border: 2px solid;
    border-radius: 14px;
    padding: 24px;
    text-align: center;
    background: linear-gradient(135deg, rgba(255,255,255,0.9), rgba(255,255,255,0.95));
    transition: transform 0.3s;
}

.tier-card:hover {
    transform: translateY(-4px);
}

.tier-emoji {
    font-size: 2.5rem;
    margin-bottom: 12px;
}

.tier-name {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 6px;
}

.tier-requirement {
    font-size: 0.85rem;
    color: #718096;
    margin-bottom: 8px;
}

.tier-rate {
    font-size: 0.95rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 12px;
}

.tier-count {
    font-size: 0.8rem;
    color: #a0aec0;
    padding-top: 12px;
    border-top: 1px solid rgba(0,0,0,0.08);
}

/* Info Note */
.info-note {
    background: #f0f4ff;
    border-left: 4px solid #667eea;
    padding: 14px 18px;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 10px;
}

.badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.badge-warning {
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    color: #f57c00;
}

/* Search Form */
.search-form {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
}

.search-form .form-control {
    flex: 1;
}

/* Table */
.table-responsive {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table thead tr {
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
    border-bottom: 2px solid #e8ecf1;
}

.table th {
    padding: 16px 20px;
    text-align: left;
    font-size: 13px;
    font-weight: 700;
    color: #4a5568;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table td {
    padding: 18px 20px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.table tbody tr {
    transition: background 0.2s;
}

.table tbody tr:hover {
    background: #f9fafb;
}

/* Member Info in Table (scoped so it does NOT affect sidebar) */
.member-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.member-name {
    font-weight: 600;
    font-size: 15px;
}

.member-email, .member-phone {
    font-size: 0.85rem;
    color: #718096;
}

/* Tier Badge */
.tier-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 24px;
    font-size: 0.875rem;
    font-weight: 600;
    color: white;
}

/* Points Info */
.points-info, .orders-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.points-total, .orders-count {
    font-weight: 600;
    color: #2d3748;
    font-size: 15px;
}

.points-available {
    font-size: 0.85rem;
    color: #718096;
}

/* Progress Bar */
.progress-bar {
    width: 100%;
    height: 6px;
    background: #e8ecf1;
    border-radius: 3px;
    overflow: hidden;
    margin: 6px 0;
}

.progress-fill {
    height: 100%;
    transition: width 0.3s;
}

.progress-text {
    font-size: 0.8rem;
    color: #718096;
}

.max-tier {
    font-size: 0.85rem;
    color: #A855F7;
    font-weight: 600;
}

/* Discount Value */
.discount-value {
    font-size: 1.15rem;
    font-weight: 700;
    color: #10B981;
}

.discount-label {
    font-size: 0.8rem;
    color: #718096;
}

.date-text {
    font-size: 0.85rem;
    color: #718096;
}

.text-muted {
    color: #cbd5e0;
}

/* Pagination */
.pagination {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-top: 24px;
    flex-wrap: wrap;
}

.page-link {
    padding: 10px 16px;
    border: 2px solid #e2e8f0;
    background: white;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
    text-decoration: none;
    color: #4a5568;
}

.page-link:hover {
    border-color: #667eea;
    background: #f5f7ff;
    transform: translateY(-2px);
}

.page-link.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: transparent;
}

/* Empty State */
.empty-state {
    padding: 80px 40px !important;
    text-align: center;
}

.empty-icon {
    font-size: 5rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.empty-text {
    font-size: 1.25rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 10px;
}

.empty-subtext {
    font-size: 0.95rem;
    color: #a0aec0;
}

/* Button Styling */
.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
}

.btn-secondary {
    background: #e2e8f0;
    color: #4a5568;
}

.btn-secondary:hover {
    background: #cbd5e0;
}

.btn-success {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: white;
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
}

/* Form Controls */
.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 15px;
    transition: all 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    animation: fadeIn 0.3s;
}

.modal-content {
    background: white;
    margin: 5% auto;
    border-radius: 16px;
    max-width: 800px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: slideDown 0.3s;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px 28px;
    border-bottom: 1px solid #e8ecf1;
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
}

.modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2d3748;
    margin: 0;
}

.modal-close {
    font-size: 2rem;
    font-weight: 300;
    color: #a0aec0;
    cursor: pointer;
    transition: all 0.3s;
    line-height: 1;
}

.modal-close:hover {
    color: #667eea;
    transform: rotate(90deg);
}

.modal-body {
    padding: 28px;
}

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 20px 28px;
    border-top: 1px solid #e8ecf1;
}

/* Form Sections */
.form-section {
    margin-bottom: 32px;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 2px solid #667eea;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #2d3748;
    font-size: 14px;
}

.form-hint {
    display: block;
    font-size: 0.8rem;
    color: #718096;
    margin-top: 4px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-weight: 500;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.info-box {
    background: #f0f4ff;
    border: 1px solid #667eea;
    border-radius: 8px;
    padding: 14px 18px;
    margin-top: 16px;
    font-size: 0.9rem;
    color: #2d3748;
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Responsive Design */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .tier-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .tier-grid {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .search-form {
        flex-direction: column;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
}

@media (max-width: 640px) {
    .card-body {
        padding: 20px;
    }
    
    .table th, .table td {
        padding: 12px;
        font-size: 13px;
    }
}
</style>

<div class="page-header">
    <div>
        <h1>⭐ Rewards Management</h1>
        <p>Manage customer loyalty points and tier system</p>
    </div>
    <?php if ($_SESSION['role'] === 'master_admin'): ?>
    <button class="btn btn-primary" onclick="document.getElementById('settingsModal').style.display='block'">
        <span>⚙️</span> Configure Rewards
    </button>
    <?php endif; ?>
</div>

<!-- Success/Error Messages -->
<?php if ($success): ?>
<div class="alert alert-success">
    <span style="font-size: 20px;">✓</span>
    <span><?= htmlspecialchars($success) ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger">
    <span style="font-size: 20px;">✗</span>
    <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-details">
            <div class="stat-value"><?= number_format($totalMembers) ?></div>
            <div class="stat-label">Rewards Members</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⭐</div>
        <div class="stat-details">
            <div class="stat-value"><?= number_format($totalPoints) ?></div>
            <div class="stat-label">Total Points Issued</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🎁</div>
        <div class="stat-details">
            <div class="stat-value"><?= number_format($totalRedeemed) ?></div>
            <div class="stat-label">Points Redeemed</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">💰</div>
        <div class="stat-details">
            <div class="stat-value">₱<?= number_format($totalRedeemed * $settings['redemption_value'] / $settings['redemption_rate']) ?></div>
            <div class="stat-label">Total Discounts Given</div>
        </div>
    </div>
</div>

<!-- Tier Distribution -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">🏆 Tier Distribution</h3>
    </div>
    <div class="card-body">
        <div class="tier-grid">
            <?php
            $tierDefs = [
                ['Bronze',   '🥉', '#CD7F32', 'Default',    $settings['bronze_rate'] . ' pts / 11kg'],
                ['Silver',   '🥈', '#94A3B8', $settings['silver_threshold'] . '+ orders',  $settings['silver_rate'] . ' pts / 11kg'],
                ['Gold',     '🥇', '#F59E0B', $settings['gold_threshold'] . '+ orders', $settings['gold_rate'] . ' pts / 11kg'],
                ['Platinum', '💎', '#A855F7', $settings['platinum_threshold'] . '+ orders', $settings['platinum_rate'] . ' pts / 11kg'],
            ];
            foreach ($tierDefs as [$name, $emoji, $color, $req, $rate]):
            ?>
            <div class="tier-card" style="border-color: <?= $color ?>;">
                <div class="tier-emoji"><?= $emoji ?></div>
                <div class="tier-name" style="color: <?= $color ?>;"><?= $name ?></div>
                <div class="tier-requirement"><?= $req ?></div>
                <div class="tier-rate"><?= $rate ?></div>
                <div class="tier-count"><?= $tierMap[$name] ?> members</div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="info-note">
            <span>ℹ️</span>
            <span><strong><?= $settings['redemption_rate'] ?> points</strong> = <strong>₱<?= $settings['redemption_value'] ?></strong> discount on next order</span>
            <?php if (!$settings['points_enabled']): ?>
            <span class="badge badge-warning">⚠️ Points System Disabled</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Members Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📊 Rewards Members (<?= number_format($total) ?>)</h3>
    </div>
    <div class="card-body">
        <!-- Search -->
        <form method="GET" class="search-form">
            <input type="text" name="search" class="form-control" 
                   placeholder="🔍 Search by name or email..." 
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search): ?>
            <a href="rewards.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Tier</th>
                        <th>Points</th>
                        <th>Completed Orders</th>
                        <th>Available Discount</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="6" class="empty-state">
                            <div class="empty-icon">📭</div>
                            <div class="empty-text">
                                <?= $search ? 'No members match your search.' : 'No rewards members yet.' ?>
                            </div>
                            <div class="empty-subtext">
                                Members appear here after their first completed order.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($members as $m):
                    $available   = $m['total_points'] - $m['redeemed_points'];
                    $discountVal = floor($available / $settings['redemption_rate']) * $settings['redemption_value'];
                    $nextTier = match($m['tier']) { 
                        'Bronze' => ['Silver', $settings['silver_threshold']],
                        'Silver' => ['Gold', $settings['gold_threshold']],
                        'Gold' => ['Platinum', $settings['platinum_threshold']],
                        default => ['Max', 999]
                    };
                    $progress = $nextTier[0] !== 'Max' 
                        ? min(100, round($m['completed_orders'] / $nextTier[1] * 100))
                        : 100;
                ?>
                <tr>
                    <td>
                        <div class="member-info">
                            <div class="member-name"><?= htmlspecialchars($m['full_name']) ?></div>
                            <div class="member-email"><?= htmlspecialchars($m['email']) ?></div>
                            <?php if ($m['phone']): ?>
                            <div class="user-phone"><?= htmlspecialchars($m['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="tier-badge" style="background: <?= tierColor($m['tier']) ?>;">
                            <?= tierEmoji($m['tier']) ?> <?= $m['tier'] ?>
                        </span>
                    </td>
                    <td>
                        <div class="points-info">
                            <div class="points-total"><?= number_format($m['total_points']) ?></div>
                            <div class="points-available"><?= number_format($available) ?> available</div>
                        </div>
                    </td>
                    <td>
                        <div class="orders-info">
                            <div class="orders-count"><?= $m['completed_orders'] ?> orders</div>
                            <?php if ($nextTier[0] !== 'Max'): ?>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $progress ?>%; background: <?= tierColor($m['tier']) ?>;"></div>
                            </div>
                            <div class="progress-text"><?= $progress ?>% to <?= $nextTier[0] ?></div>
                            <?php else: ?>
                            <div class="max-tier">Max tier reached ✓</div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($discountVal > 0): ?>
                        <div class="discount-value">₱<?= number_format($discountVal) ?></div>
                        <div class="discount-label">redeemable</div>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="date-text"><?= date('M d, Y', strtotime($m['updated_at'])) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>" class="page-link">« Previous</a>
            <?php endif; ?>
            
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" 
               class="page-link <?= $i==$page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>" class="page-link">Next »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Settings Modal (Master Admin Only) -->
<?php if ($_SESSION['role'] === 'master_admin'): ?>
<div id="settingsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">⚙️ Configure Rewards System</h2>
            <span class="modal-close" onclick="document.getElementById('settingsModal').style.display='none'">&times;</span>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            
            <!-- System Status -->
            <div class="form-section">
                <h3 class="section-title">System Status</h3>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="points_enabled" value="1" 
                               <?= $settings['points_enabled'] ? 'checked' : '' ?>>
                        <span>Enable Points System</span>
                    </label>
                    <small class="form-hint">When disabled, no new points will be awarded</small>
                </div>
            </div>

            <!-- Points Rates -->
            <div class="form-section">
                <h3 class="section-title">Points Earning Rates (per 11kg tank)</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>🥉 Bronze Rate</label>
                        <input type="number" name="bronze_rate" class="form-control" 
                               value="<?= $settings['bronze_rate'] ?>" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>🥈 Silver Rate</label>
                        <input type="number" name="silver_rate" class="form-control" 
                               value="<?= $settings['silver_rate'] ?>" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>🥇 Gold Rate</label>
                        <input type="number" name="gold_rate" class="form-control" 
                               value="<?= $settings['gold_rate'] ?>" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>💎 Platinum Rate</label>
                        <input type="number" name="platinum_rate" class="form-control" 
                               value="<?= $settings['platinum_rate'] ?>" min="0" required>
                    </div>
                </div>
            </div>

            <!-- Tier Thresholds -->
            <div class="form-section">
                <h3 class="section-title">Tier Unlock Requirements (completed orders)</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>🥈 Silver Tier</label>
                        <input type="number" name="silver_threshold" class="form-control" 
                               value="<?= $settings['silver_threshold'] ?>" min="1" required>
                        <small class="form-hint">orders needed</small>
                    </div>
                    <div class="form-group">
                        <label>🥇 Gold Tier</label>
                        <input type="number" name="gold_threshold" class="form-control" 
                               value="<?= $settings['gold_threshold'] ?>" min="1" required>
                        <small class="form-hint">orders needed</small>
                    </div>
                    <div class="form-group">
                        <label>💎 Platinum Tier</label>
                        <input type="number" name="platinum_threshold" class="form-control" 
                               value="<?= $settings['platinum_threshold'] ?>" min="1" required>
                        <small class="form-hint">orders needed</small>
                    </div>
                </div>
            </div>

            <!-- Redemption Settings -->
            <div class="form-section">
                <h3 class="section-title">Points Redemption</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Points Required</label>
                        <input type="number" name="redemption_rate" class="form-control" 
                               value="<?= $settings['redemption_rate'] ?>" min="1" required>
                        <small class="form-hint">points needed for discount</small>
                    </div>
                    <div class="form-group">
                        <label>Discount Value (₱)</label>
                        <input type="number" name="redemption_value" class="form-control" 
                               value="<?= $settings['redemption_value'] ?>" min="1" required>
                        <small class="form-hint">discount amount in pesos</small>
                    </div>
                </div>
                <div class="info-box">
                    <strong>Current Rate:</strong> <?= $settings['redemption_rate'] ?> points = ₱<?= $settings['redemption_value'] ?> discount
                </div>
            </div>

            <!-- Actions -->
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" 
                        onclick="document.getElementById('settingsModal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" name="update_settings" class="btn btn-success">
                    <span>💾</span> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('settingsModal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 5000);
</script>

<?php require_once 'includes/footer.php'; ?>