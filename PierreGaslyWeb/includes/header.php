<?php
/**
 * PIERRE GASLY - Admin Header
 * Common header with sidebar navigation
 */

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/validation-fixed.css">
    <script src="assets/js/validation-fixed.js"></script>
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        .sidebar-logo {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .sidebar-title {
            font-size: 20px;
            font-weight: 600;
        }

        .sidebar-subtitle {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 5px;
        }

        .sidebar-nav {
            padding: 20px 0;
        }

        .nav-item {
            display: block;
            padding: 14px 25px;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.1);
            padding-left: 30px;
        }

        .nav-item.active {
            background: rgba(255,255,255,0.2);
            border-left: 4px solid white;
        }

        .nav-icon {
            font-size: 20px;
            width: 24px;
            text-align: center;
        }
        .nav-icon svg { width: 18px; height: 18px; display: block; }


        .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: #ffffff;
        }

        .user-role {
            font-size: 11px;
            color: rgba(255,255,255,0.75);
            opacity: 0.9;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            padding: 30px;
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #666;
            font-size: 14px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        .stat-icon {
            font-size: 36px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #f5f7fa;
        }

        .stat-primary .stat-icon { background: #e3f2fd; }
        .stat-success .stat-icon { background: #e8f5e9; }
        .stat-warning .stat-icon { background: #fff3e0; }
        .stat-info .stat-icon { background: #f3e5f5; }

        .stat-details {
            flex: 1;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: #666;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 18px;
        }

        .card-body {
            padding: 25px;
        }

        .btn-link {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-link:hover {
            text-decoration: underline;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: #f5f7fa;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            border-bottom: 2px solid #e0e0e0;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: #f9f9f9;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-primary { background: #e3f2fd; color: #1976d2; }
        .badge-success { background: #e8f5e9; color: #388e3c; }
        .badge-warning { background: #fff3e0; color: #f57c00; }
        .badge-danger { background: #ffebee; color: #d32f2f; }
        .badge-info { background: #f3e5f5; color: #7b1fa2; }
        .badge-secondary { background: #f5f5f5; color: #666; }

        /* Alert List */
        .alert-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .alert-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #fff3e0;
            border-radius: 8px;
            border-left: 4px solid #ff9800;
        }

        .alert-icon {
            font-size: 24px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .alert-subtitle {
            font-size: 13px;
            color: #666;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-logout {
            width: 100%;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }

        /* Container */
        .dashboard-container {
            max-width: 1400px;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">⛽</div>
            <div class="sidebar-title">Pierre Gasly</div>
            <div class="sidebar-subtitle">Admin Panel</div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg></span>
                <span>Dashboard</span>
            </a>
            <a href="products.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' || basename($_SERVER['PHP_SELF']) == 'products_premium.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21 8l-9-5-9 5 9 5 9-5zm-9 7L3 10v10l9 5 9-5V10l-9 5z"/></svg></span>
                <span>Products</span>
            </a>
            <a href="orders.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM7.2 14h9.9c.8 0 1.5-.5 1.8-1.2l2.1-6.1A1 1 0 0 0 20 5H6.2L5.8 3.4A1 1 0 0 0 4.8 3H3a1 1 0 0 0 0 2h1.1l2.1 9.4c.2.9 1 1.6 2 1.6z"/></svg></span>
                <span>Orders</span>
            </a>
            <a href="sales.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 1a11 11 0 1 0 0 22 11 11 0 0 0 0-22zm1 17.9V20h-2v-1.1c-1.4-.3-2.6-1.2-3.1-2.6l1.8-1c.3.9 1.1 1.7 2.3 1.7 1 0 2-.4 2-1.4 0-1-1.1-1.3-2.5-1.7-1.6-.4-3.4-1-3.4-3.2 0-1.7 1.2-2.9 2.9-3.2V4h2v1.1c1.2.2 2.2.9 2.7 2l-1.7 1c-.3-.7-1-1.2-1.9-1.2-.9 0-1.7.4-1.7 1.3s.9 1.2 2.4 1.6c1.7.5 3.5 1.1 3.5 3.4 0 1.8-1.2 3-3 3.3z"/></svg></span>
                <span>Sales</span>
            </a>
            <a href="users.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16 11c1.7 0 3-1.3 3-3s-1.3-3-3-3-3 1.3-3 3 1.3 3 3 3zM8 11c1.7 0 3-1.3 3-3S9.7 5 8 5 5 6.3 5 8s1.3 3 3 3zm0 2c-2.3 0-7 1.2-7 3.5V19h14v-2.5C15 14.2 10.3 13 8 13zm8 0c-.3 0-.7 0-1.1.1 1.1.8 1.9 1.8 1.9 3.4V19h7v-2.5c0-2.3-4.7-3.5-6.8-3.5z"/></svg></span>
                <span>Users</span>
            </a>
            <a href="ratings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'ratings.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 17.3l-6.2 3.7 1.6-7.1L2 9.2l7.2-.6L12 2l2.8 6.6 7.2.6-5.4 4.7 1.6 7.1z"/></svg></span>
                <span>Ratings</span>
            </a>
            <a href="reports.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 3h2v18H3V3zm4 10h2v8H7v-8zm4-6h2v14h-2V7zm4 4h2v10h-2V11zm4-8h2v18h-2V3z"/></svg></span>
                <span>Reports</span>
            </a>
            <?php if (isAdmin()): ?>

            <a href="rewards.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'rewards.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 4h-1V2h-2v2H10V2H8v2H7C5.9 4 5 4.9 5 6v3c0 1.1.9 2 2 2h1v2c0 1.7 1.3 3 3 3h2c1.7 0 3-1.3 3-3v-2h1c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 5h-1V6h1v3zM7 9V6h1v3H7zm9 4c0 .6-.4 1-1 1h-4c-.6 0-1-.4-1-1v-2h6v2z"/></svg></span>
                <span class="nav-label">Rewards</span>
            </a>
            <a href="settings.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' || basename($_SERVER['PHP_SELF']) == 'settings_improved.php' ? 'active' : ''; ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19.4 13a7.6 7.6 0 0 0 0-2l2-1.6-2-3.4-2.4 1a7.8 7.8 0 0 0-1.7-1L15 2h-6l-.3 3a7.8 7.8 0 0 0-1.7 1l-2.4-1-2 3.4 2 1.6a7.6 7.6 0 0 0 0 2l-2 1.6 2 3.4 2.4-1a7.8 7.8 0 0 0 1.7 1L9 22h6l.3-3a7.8 7.8 0 0 0 1.7-1l2.4 1 2-3.4-2-1.6zM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5z"/></svg></span>
                <span>Settings</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                    <div class="user-role"><?php echo ucwords(str_replace('_', ' ', $_SESSION['role'])); ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn btn-logout">Logout →</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">

<!-- Custom Confirmation Popup -->
<div id="confirmPopup" class="confirm-popup">
    <div class="confirm-popup-content">
        <div class="confirm-popup-header">
            <div class="confirm-popup-title" id="confirmTitle">Confirm Action</div>
            <div class="confirm-popup-message" id="confirmMessage">Are you sure?</div>
        </div>
        <div class="confirm-popup-buttons">
            <button class="confirm-popup-btn btn-cancel" onclick="closeConfirmPopup()">Cancel</button>
            <button class="confirm-popup-btn btn-confirm" id="confirmButton" onclick="confirmAction()">Confirm</button>
        </div>
    </div>
</div>

<style>
/* Custom Confirmation Popup */
.confirm-popup {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.confirm-popup.active {
    display: flex;
}

.confirm-popup-content {
    background: white;
    border-radius: 20px;
    padding: 30px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: popupSlideIn 0.3s ease;
}

@keyframes popupSlideIn {
    from {
        opacity: 0;
        transform: scale(0.9) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.confirm-popup-header {
    margin-bottom: 20px;
}

.confirm-popup-title {
    font-size: 20px;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 8px;
}

.confirm-popup-message {
    font-size: 15px;
    color: #718096;
    line-height: 1.5;
}

.confirm-popup-buttons {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}

.confirm-popup-btn {
    flex: 1;
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.confirm-popup-btn.btn-confirm {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.confirm-popup-btn.btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
}

.confirm-popup-btn.btn-cancel {
    background: #e2e8f0;
    color: #4a5568;
}

.confirm-popup-btn.btn-cancel:hover {
    background: #cbd5e0;
}
</style>

<script>
let confirmCallback = null;

function showConfirmPopup(title, message, callback) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    document.getElementById('confirmPopup').classList.add('active');
    confirmCallback = callback;
}

function closeConfirmPopup() {
    document.getElementById('confirmPopup').classList.remove('active');
    confirmCallback = null;
}

function confirmAction() {
    if (confirmCallback) {
        confirmCallback();
    }
    closeConfirmPopup();
}

// Override all onclick confirm dialogs
document.addEventListener('DOMContentLoaded', function() {
    // Replace all onclick="return confirm(...)" with custom popup
    document.querySelectorAll('[onclick*="confirm"]').forEach(function(element) {
        const onclickAttr = element.getAttribute('onclick');
        if (onclickAttr && onclickAttr.includes('confirm(')) {
            // Extract the confirm message
            const match = onclickAttr.match(/confirm\(['"](.+?)['"]\)/);
            if (match) {
                const message = match[1];
                const restOfCode = onclickAttr.replace(/return confirm\(.+?\);?/, '').replace(/if\s*\(!?confirm\(.+?\)\)\s*{?/, '');
                
                element.removeAttribute('onclick');
                element.addEventListener('click', function(e) {
                    e.preventDefault();
                    showConfirmPopup('Confirm Action', message, function() {
                        // Get the href if it's a link
                        if (element.tagName === 'A' && element.href) {
                            window.location.href = element.href;
                        } else if (restOfCode) {
                            eval(restOfCode);
                        }
                    });
                });
            }
        }
    });
});
</script>