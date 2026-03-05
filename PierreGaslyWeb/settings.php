<?php
/**
 * PIERRE GASLY - System Settings (Redesigned)
 * Improved UI with Master Admin database controls
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'System Settings';
$db = Database::getInstance();

$success = '';
$error = '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $settings = [
            'system_name' => sanitize($_POST['system_name']),
            'delivery_fee' => (float)$_POST['delivery_fee'],
            'contact_email' => sanitize($_POST['contact_email']),
            'contact_phone' => sanitize($_POST['contact_phone']),
            'business_hours' => sanitize($_POST['business_hours']),
            'max_login_attempts' => (int)$_POST['max_login_attempts'],
            'session_timeout' => (int)$_POST['session_timeout']
        ];
        
        $updated = 0;
        foreach ($settings as $key => $value) {
            $sql = "UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?";
            if ($db->query($sql, [$value, $_SESSION['user_id'], $key])) {
                $updated++;
            }
        }
        
        if ($updated > 0) {
            $success = "$updated settings updated successfully!";
            logActivity('update', 'settings', null, "Updated system settings");
        } else {
            $error = 'No changes made';
        }
    }
}

// Get current settings
$settingsQuery = $db->fetchAll("SELECT * FROM system_settings");
$settings = [];
foreach ($settingsQuery as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Get statistics
$stats = [
    'users' => $db->fetchOne("SELECT COUNT(*) as count FROM users")['count'],
    'products' => $db->fetchOne("SELECT COUNT(*) as count FROM products WHERE is_active = 1")['count'],
    'orders' => $db->fetchOne("SELECT COUNT(*) as count FROM orders")['count'],
    'revenue' => $db->fetchOne("SELECT COALESCE(SUM(sale_amount), 0) as total FROM sales")['total']
];

// Get recent activities (last 10)
$activities = $db->fetchAll(
    "SELECT a.*, u.full_name 
     FROM activity_logs a
     JOIN users u ON a.user_id = u.user_id
     ORDER BY a.activity_date DESC
     LIMIT 10"
);

$csrfToken = generateCSRFToken();
include 'includes/header.php';
?>

<style>
/* Enhanced Settings Page */
.settings-container {
    max-width: 1400px;
}

.settings-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 25px;
    margin-bottom: 30px;
}

.settings-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.settings-card-header {
    padding: 25px 30px;
    border-bottom: 1px solid #e8ecf1;
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
}

.settings-card-header h3 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
    color: #2d3748;
}

.settings-card-body {
    padding: 30px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 14px;
    color: #4a5568;
}

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

.input-group {
    position: relative;
}

.input-prefix {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    font-weight: 600;
    color: #718096;
}

.form-control.has-prefix {
    padding-left: 36px;
}

/* Stats Cards */
.stats-mini-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}

.stat-mini-card {
    background: linear-gradient(135deg, #f7fafc 0%, #ffffff 100%);
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid #667eea;
}

.stat-mini-value {
    font-size: 24px;
    font-weight: 800;
    color: #2d3748;
    margin-bottom: 5px;
}

.stat-mini-label {
    font-size: 12px;
    color: #718096;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Database Actions - Master Admin Only */
.database-actions {
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid #ff9800;
    margin-bottom: 20px;
}

.database-actions h4 {
    font-size: 16px;
    margin: 0 0 12px;
    color: #e65100;
    display: flex;
    align-items: center;
    gap: 8px;
}

.database-actions-grid {
    display: grid;
    gap: 10px;
}

.db-action-btn {
    width: 100%;
    padding: 12px 20px;
    border: 2px solid #f57c00;
    background: white;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    color: #e65100;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.db-action-btn:hover {
    background: #f57c00;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245, 124, 0, 0.3);
}

/* Activity Logs */
.activity-list {
    max-height: 400px;
    overflow-y: auto;
}

.activity-item {
    padding: 15px;
    background: #f7fafc;
    border-radius: 10px;
    margin-bottom: 10px;
    border-left: 3px solid #667eea;
}

.activity-item:hover {
    background: #edf2f7;
}

.activity-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.activity-user {
    font-weight: 600;
    color: #2d3748;
    font-size: 14px;
}

.activity-time {
    font-size: 12px;
    color: #a0aec0;
}

.activity-action {
    font-size: 13px;
    color: #718096;
}

.activity-action strong {
    color: #667eea;
}

/* Save Button */
.btn-save {
    width: 100%;
    padding: 14px 28px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    margin-top: 10px;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
}

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

<div class="settings-container">
    <div class="page-header">
        <h1>⚙️ System Settings</h1>
        <p>Configure your system and manage preferences</p>
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

    <div class="settings-grid">
        <!-- Settings Form -->
        <div class="settings-card">
            <div class="settings-card-header">
                <h3>🔧 General Settings</h3>
            </div>
            <div class="settings-card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <div class="form-group full-width">
                        <label>System Name</label>
                        <input type="text" name="system_name" required class="form-control" 
                               value="<?php echo htmlspecialchars($settings['system_name'] ?? 'Pierre Gasly Gas Delivery'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Delivery Fee (₱)</label>
                        <div class="input-group">
                            <span class="input-prefix">₱</span>
                            <input type="number" name="delivery_fee" step="0.01" required class="form-control has-prefix" 
                                   value="<?php echo $settings['delivery_fee'] ?? '50.00'; ?>">
                        </div>
                        <small style="color: #666; font-size: 12px;">Standard delivery fee charged to customers</small>
                    </div>

                    <div class="form-group">
                        <label>Business Hours</label>
                        <input type="text" name="business_hours" required class="form-control" 
                               value="<?php echo htmlspecialchars($settings['business_hours'] ?? '8:00 AM - 6:00 PM'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Contact Email</label>
                        <input type="email" name="contact_email" required class="form-control" 
                               value="<?php echo htmlspecialchars($settings['contact_email'] ?? 'support@pierregasly.com'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Contact Phone</label>
                        <input type="text" name="contact_phone" required class="form-control" 
                               value="<?php echo htmlspecialchars($settings['contact_phone'] ?? '09171234567'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Max Login Attempts</label>
                        <input type="number" name="max_login_attempts" required class="form-control" 
                               min="3" max="10"
                               value="<?php echo $settings['max_login_attempts'] ?? 5; ?>">
                        <small style="color: #666; font-size: 12px;">Number of failed login attempts before lockout</small>
                    </div>

                    <div class="form-group">
                        <label>Session Timeout (seconds)</label>
                        <input type="number" name="session_timeout" required class="form-control" 
                               min="1800" max="7200" step="300"
                               value="<?php echo $settings['session_timeout'] ?? 3600; ?>">
                        <small style="color: #666; font-size: 12px;">Automatic logout after inactivity (30-120 minutes)</small>
                    </div>

                    <div class="form-group full-width">
                        <button type="submit" name="update_settings" class="btn-save">
                            💾 Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <!-- System Information -->
            <div class="settings-card" style="margin-bottom: 25px;">
                <div class="settings-card-header">
                    <h3>📊 System Information</h3>
                </div>
                <div class="settings-card-body">
                    <div class="stats-mini-grid">
                        <div class="stat-mini-card">
                            <div class="stat-mini-value"><?php echo number_format($stats['users']); ?></div>
                            <div class="stat-mini-label">Total Users</div>
                        </div>
                        <div class="stat-mini-card">
                            <div class="stat-mini-value"><?php echo number_format($stats['products']); ?></div>
                            <div class="stat-mini-label">Products</div>
                        </div>
                        <div class="stat-mini-card">
                            <div class="stat-mini-value"><?php echo number_format($stats['orders']); ?></div>
                            <div class="stat-mini-label">Total Orders</div>
                        </div>
                        <div class="stat-mini-card" style="border-left-color: #4caf50;">
                            <div class="stat-mini-value" style="color: #4caf50;">
                                <?php echo formatCurrency($stats['revenue']); ?>
                            </div>
                            <div class="stat-mini-label">Total Revenue</div>
                        </div>
                    </div>
                </div>
            </div>

            

            <!-- Activity Logs -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <h3>📜 Recent Activity</h3>
                </div>
                <div class="settings-card-body">
                    <div class="activity-list">
                        <?php if (empty($activities)): ?>
                            <p style="text-align: center; color: #a0aec0; padding: 20px;">No recent activity</p>
                        <?php else: ?>
                            <?php foreach ($activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-header">
                                    <span class="activity-user"><?php echo htmlspecialchars($activity['full_name']); ?></span>
                                    <span class="activity-time"><?php echo formatDateTime($activity['activity_date']); ?></span>
                                </div>
                                <div class="activity-action">
                                    <strong><?php echo ucfirst($activity['action']); ?></strong> 
                                    <?php echo $activity['entity_type']; ?>
                                    <?php if ($activity['details']): ?>
                                        - <?php echo htmlspecialchars($activity['details']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>