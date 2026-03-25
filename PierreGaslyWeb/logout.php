<?php
/**
 * PIERRE GASLY - Logout Handler (FIXED)
 * Destroys session and redirects to login
 */

// Start session first
session_start();

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    // Get user info for logging
    $user_id = $_SESSION['user_id'];
    
    // Log activity (if database available)
    try {
        require_once 'includes/config.php';
        logActivity('logout', 'user', $user_id, 'User logged out');
    } catch (Exception $e) {
        // Continue logout even if logging fails
    }
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php?logged_out=1');
exit();
