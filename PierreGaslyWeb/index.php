<?php
/**
 * PIERRE GASLY - Index Redirect
 * Redirects to appropriate page based on login status
 */

session_start();

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit();
