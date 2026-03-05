<?php
/**
 * PIERRE GASLY - Sub Admin Password Fix Tool
 * DELETE THIS FILE after use!
 */
require_once 'includes/config.php';

$message = '';
$debugInfo = '';

// Generate correct hash for the password
$testPassword = 'JACINtoSantos365';
$testEmail    = 'JacintoSantos365@gmail.com';
$testBirthday = '1980-02-14';

// Auto-fix: update the sub admin record directly
if (isset($_GET['fix']) && $_GET['fix'] === 'now') {
    $db = Database::getInstance();
    
    // Check if user exists
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ?", [$testEmail]);
    
    if ($user) {
        // Update password hash and ensure birthday is correct
        $newHash = hashPassword($testPassword);
        $db->query(
            "UPDATE users SET password_hash = ?, birthday = ?, role = 'sub_admin', status = 'active' WHERE email = ?",
            [$newHash, $testBirthday, $testEmail]
        );
        $message = "✅ Fixed! Password hash updated for $testEmail";
    } else {
        // Create the sub admin fresh
        $newHash = hashPassword($testPassword);
        $db->query(
            "INSERT INTO users (full_name, email, password_hash, birthday, role, status, first_login) 
             VALUES (?, ?, ?, ?, 'sub_admin', 'active', 1)",
            ['Jacinto Santos', $testEmail, $newHash, $testBirthday]
        );
        $message = "✅ Created fresh sub admin account for $testEmail";
    }
    
    // Verify
    $user = $db->fetchOne("SELECT user_id, email, role, status, birthday, LEFT(password_hash,20) as hash_preview FROM users WHERE email = ?", [$testEmail]);
    $expectedPasskey = 'PGAS' . date('m-d-Y', strtotime($user['birthday']));
    $message .= "<br><br><strong>Login credentials:</strong><br>";
    $message .= "Email: $testEmail<br>";
    $message .= "Password: $testPassword<br>";
    $message .= "Passkey: $expectedPasskey<br>";
    $message .= "Role: " . $user['role'] . "<br>";
    $message .= "Status: " . $user['status'];
}

// Debug: show current state
$db = Database::getInstance();
$user = $db->fetchOne("SELECT user_id, full_name, email, role, status, birthday, LEFT(password_hash,30) as hash_preview FROM users WHERE email = ?", [$testEmail]);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Sub Admin</title>
    <style>
        body { font-family: monospace; padding: 30px; background: #1a1a2e; color: #e0e0e0; }
        .box { background: #16213e; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .success { color: #10B981; }
        .error { color: #EF4444; }
        .info { color: #60A5FA; }
        a.btn { display:inline-block; padding: 12px 24px; background: #4A4AE8; 
                color: white; text-decoration: none; border-radius: 8px; font-size: 16px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px 12px; border-bottom: 1px solid #333; }
        td:first-child { color: #94a3b8; width: 180px; }
    </style>
</head>
<body>

<h2 style="color:#fff;">🔧 Sub Admin Fix Tool</h2>

<?php if ($message): ?>
<div class="box">
    <p class="success"><?= $message ?></p>
</div>
<?php endif; ?>

<div class="box">
    <h3 class="info">Current DB State for <?= $testEmail ?></h3>
    <?php if ($user): ?>
    <table>
        <tr><td>User ID</td><td><?= $user['user_id'] ?></td></tr>
        <tr><td>Name</td><td><?= $user['full_name'] ?></td></tr>
        <tr><td>Email</td><td><?= $user['email'] ?></td></tr>
        <tr><td>Role</td><td><?= $user['role'] ?></td></tr>
        <tr><td>Status</td><td><?= $user['status'] ?></td></tr>
        <tr><td>Birthday</td><td><?= $user['birthday'] ?></td></tr>
        <tr><td>Hash preview</td><td><?= $user['hash_preview'] ?>...</td></tr>
        <tr><td>Expected passkey</td><td><strong style="color:#10B981"><?= 'PGAS' . date('m-d-Y', strtotime($user['birthday'])) ?></strong></td></tr>
        <tr><td>Password verify</td><td>
            <?php 
            $fullUser = $db->fetchOne("SELECT password_hash FROM users WHERE email = ?", [$testEmail]);
            echo password_verify($testPassword, $fullUser['password_hash']) 
                ? '<span class="success">✅ Password matches hash in DB</span>' 
                : '<span class="error">❌ Password does NOT match hash — needs fixing</span>';
            ?>
        </td></tr>
    </table>
    <?php else: ?>
    <p class="error">❌ User not found in database!</p>
    <?php endif; ?>
</div>

<div class="box">
    <h3 style="color:#fff; margin-bottom:15px;">Fix Options</h3>
    <a href="fix_subadmin.php?fix=now" class="btn">🔧 Fix Now (Reset password + birthday)</a>
    <p style="margin-top:12px; color:#94a3b8; font-size:13px;">
        This will set the password to <strong style="color:#fff">JACINtoSantos365</strong> 
        and birthday to <strong style="color:#fff">1980-02-14</strong> 
        so the passkey becomes <strong style="color:#10B981">PGAS02-14-1980</strong>
    </p>
</div>

<p style="color:#EF4444; margin-top:20px;">
    ⚠️ <strong>DELETE fix_subadmin.php from your server after use!</strong>
</p>

</body>
</html>
