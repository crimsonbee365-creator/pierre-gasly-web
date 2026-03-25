<?php
/**
 * PIERRE GASLY - Sub Admin Password Reset Tool
 * DELETE THIS FILE after use!
 */

// Simple security - only accessible with secret key
$SECRET = 'pgas_reset_2026';
if (($_GET['key'] ?? '') !== $SECRET) {
    die('<h2>Access denied. Add ?key=pgas_reset_2026 to the URL.</h2>');
}

require_once 'includes/config.php';
$db = Database::getInstance();

$message = '';
$messageType = '';

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['new_password'] ?? '';
    $birthday = $_POST['birthday'] ?? '';

    if (empty($email) || empty($password)) {
        $message = 'Email and password are required.';
        $messageType = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.';
        $messageType = 'error';
    } else {
        $hash = hashPassword($password);

        // Build update query
        if (!empty($birthday)) {
            $result = $db->query(
                "UPDATE users SET password_hash = ?, birthday = ? WHERE email = ? AND role = 'sub_admin'",
                [$hash, $birthday, $email]
            );
        } else {
            $result = $db->query(
                "UPDATE users SET password_hash = ? WHERE email = ? AND role = 'sub_admin'",
                [$hash, $email]
            );
        }

        // Check if user was found
        $user = $db->fetchOne("SELECT * FROM users WHERE email = ? AND role = 'sub_admin'", [$email]);

        if ($user) {
            $passkey = $user['birthday']
                ? 'PGAS' . date('m-d-Y', strtotime($user['birthday']))
                : '(no birthday set — passkey won\'t work)';

            $message = "✅ Password reset successfully!<br><br>
                <strong>Email:</strong> {$user['email']}<br>
                <strong>New Password:</strong> " . htmlspecialchars($password) . "<br>
                <strong>Birthday in DB:</strong> " . ($user['birthday'] ?: 'NOT SET') . "<br>
                <strong>Passkey:</strong> <code style='font-size:1.1em;color:#6200ea;'>$passkey</code><br><br>
                <em>Test login now. Delete this file after!</em>";
            $messageType = 'success';
        } else {
            $message = "❌ No sub admin found with email: $email";
            $messageType = 'error';
        }
    }
}

// Get all sub admins for reference
$subAdmins = $db->fetchAll("SELECT user_id, full_name, email, birthday, status FROM users WHERE role = 'sub_admin'");
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Sub Admin Reset Tool</title>
<style>
  body { font-family: Arial, sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
  .card { background: white; border-radius: 12px; padding: 28px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
  h1 { color: #6200ea; margin-bottom: 4px; }
  .warning { background: #fff3e0; border-left: 4px solid #ff9800; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
  label { display: block; font-weight: bold; margin-bottom: 6px; margin-top: 14px; color: #333; }
  input { width: 100%; padding: 10px 14px; border: 2px solid #ddd; border-radius: 8px; font-size: 15px; box-sizing: border-box; }
  input:focus { border-color: #6200ea; outline: none; }
  button { margin-top: 20px; width: 100%; padding: 13px; background: #6200ea; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; }
  button:hover { background: #4a00b4; }
  .success { background: #e8f5e9; border-left: 4px solid #4caf50; padding: 16px; border-radius: 8px; margin-bottom: 20px; }
  .error   { background: #ffebee; border-left: 4px solid #f44336; padding: 16px; border-radius: 8px; margin-bottom: 20px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { background: #6200ea; color: white; padding: 10px 12px; text-align: left; }
  td { padding: 10px 12px; border-bottom: 1px solid #eee; }
  tr:hover td { background: #f9f5ff; }
  code { background: #f3e5f5; padding: 3px 8px; border-radius: 4px; font-size: 13px; }
  small { color: #888; font-size: 12px; }
</style>
</head>
<body>

<div class="card">
  <h1>🔧 Sub Admin Reset Tool</h1>
  <p style="color:#888;margin-bottom:16px;">Pierre Gasly Gas Delivery System</p>
  <div class="warning">
    ⚠️ <strong>Security Notice:</strong> Delete this file immediately after use!<br>
    File location: <code>PierreGaslyWeb/reset_subadmin.php</code>
  </div>

  <?php if ($message): ?>
  <div class="<?= $messageType ?>">
    <?= $message ?>
  </div>
  <?php endif; ?>

  <form method="POST">
    <label>Sub Admin Email *</label>
    <input type="email" name="email" required placeholder="subadmin@email.com"
           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

    <label>New Password *</label>
    <input type="text" name="new_password" required placeholder="Min 6 characters"
           value="<?= htmlspecialchars($_POST['new_password'] ?? '') ?>">

    <label>Birthday (optional — updates passkey) <small>Leave blank to keep current</small></label>
    <input type="date" name="birthday" value="<?= htmlspecialchars($_POST['birthday'] ?? '') ?>">

    <button type="submit">🔄 Reset Password</button>
  </form>
</div>

<?php if (!empty($subAdmins)): ?>
<div class="card">
  <h2 style="margin-bottom:16px;">📋 Existing Sub Admins</h2>
  <table>
    <tr><th>Name</th><th>Email</th><th>Birthday</th><th>Passkey</th><th>Status</th></tr>
    <?php foreach ($subAdmins as $a):
      $passkey = $a['birthday'] ? 'PGAS' . date('m-d-Y', strtotime($a['birthday'])) : '❌ No birthday';
    ?>
    <tr>
      <td><?= htmlspecialchars($a['full_name']) ?></td>
      <td><?= htmlspecialchars($a['email']) ?></td>
      <td><?= $a['birthday'] ?: '<span style="color:red">NOT SET</span>' ?></td>
      <td><code><?= $passkey ?></code></td>
      <td><?= $a['status'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:12px;color:#888;font-size:13px;">
    💡 Passkey format: PGAS + birthday as MM-DD-YYYY &nbsp;|&nbsp;
    Example: Jan 15 1990 → <code>PGAS01-15-1990</code>
  </p>
</div>
<?php endif; ?>

</body>
</html>
