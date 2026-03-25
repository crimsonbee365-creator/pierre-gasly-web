<?php
/**
 * Email Verification landing page
 * GET /verify-email.php?token=xxx&uid=yyy
 */
require_once 'includes/config.php';

$token = $_GET['token'] ?? '';
$uid   = (int)($_GET['uid'] ?? 0);

$success = false;
$message = 'Invalid or expired verification link.';

if (!empty($token) && $uid > 0) {
    try {
        $db = Database::getInstance();
        $stmt = Database::getInstance()->prepare("
            SELECT id FROM activity_logs
            WHERE action = 'email_verify_token'
            AND user_id = ?
            AND details LIKE ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$uid, '%"token":"' . $token . '"%']);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($log) {
            Database::getInstance()->prepare("UPDATE users SET email_verified = 1 WHERE user_id = ?")
               ->execute([$uid]);
            $success = true;
            $message = 'Your email has been verified! You can now access the Rewards System.';
        }
    } catch (Exception $e) {
        $message = 'Verification failed. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Email Verification - Pierre Gasly</title>
<style>
  body { font-family: sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .card { background: white; border-radius: 16px; padding: 48px 36px; max-width: 420px; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,0.1); }
  .icon { font-size: 64px; margin-bottom: 20px; }
  h1 { color: #1a1a2e; margin-bottom: 12px; }
  p { color: #666; font-size: 16px; margin-bottom: 32px; }
  .btn { background: linear-gradient(135deg,#5C6BC0,#7B1FA2); color: white; padding: 14px 32px; border-radius: 10px; text-decoration: none; font-weight: bold; }
</style>
</head><body>
<div class="card">
  <div class="icon"><?= $success ? '✅' : '❌' ?></div>
  <h1><?= $success ? 'Email Verified!' : 'Verification Failed' ?></h1>
  <p><?= htmlspecialchars($message) ?></p>
  <?php if ($success): ?>
    <p>Open the Pierre Gasly app and enjoy your rewards! 🎁</p>
  <?php else: ?>
    <p>Open the app and request a new verification email.</p>
  <?php endif; ?>
</div>
</body></html>
