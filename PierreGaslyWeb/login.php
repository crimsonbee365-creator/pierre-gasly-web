<?php
/**
 * PIERRE GASLY - Login Page (Complete Fixed with Passkey Hint)
 */

require_once 'includes/config.php';

// If already logged in, redirect
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';
$showPasskeyForm = false;
$email = '';

// Handle Login Step 1 (Email & Password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_step1'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $email = sanitize($_POST['email']);
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password';
        } else {
            $db = Database::getInstance();
            
            $user = $db->fetchOne(
                "SELECT * FROM users WHERE email = ? AND (role = 'master_admin' OR role = 'sub_admin')",
                [$email]
            );

            if ($user && verifyPassword($password, $user['password_hash'])) {
                if ($user['role'] === 'master_admin') {
                    // Master Admin: Direct login
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    $db->query("UPDATE users SET last_login = NOW() WHERE user_id = ?", [$user['user_id']]);
                    logActivity('login', 'system', null, 'Master admin logged in');
                    
                    header('Location: dashboard.php');
                    exit;
                } else {
                    // Sub Admin: Require passkey
                    $_SESSION['temp_user_id'] = $user['user_id'];
                    $_SESSION['temp_email'] = $user['email'];
                    $showPasskeyForm = true;
                }
            } else {
                $error = 'Invalid email or password';
            }
        }
    }
}

// Handle Login Step 2 (Passkey for Sub Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_step2'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $passkey = $_POST['passkey'] ?? '';
        
        if (empty($passkey)) {
            $error = 'Please enter your passkey';
            $showPasskeyForm = true;
        } else {
            $db = Database::getInstance();
            
            $user = $db->fetchOne(
                "SELECT * FROM users WHERE user_id = ?",
                [$_SESSION['temp_user_id']]
            );

            if ($user && $user['birthday']) {
                // Format: PGAS01-15-1990 (PGASMM-DD-YYYY)
                $expectedPasskey = 'PGAS' . date('m-d-Y', strtotime($user['birthday']));
                
                if ($passkey === $expectedPasskey) {
                    // Successful login
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];
                    
                    unset($_SESSION['temp_user_id']);
                    unset($_SESSION['temp_email']);

                    $db->query("UPDATE users SET last_login = NOW() WHERE user_id = ?", [$user['user_id']]);
                    logActivity('login', 'system', null, 'Sub admin logged in');
                    
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid passkey. Please check your passkey format.';
                    $showPasskeyForm = true;
                }
            } else {
                $error = 'Account error. Please contact administrator.';
            }
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pierre Gasly - Admin Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideIn 0.4s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 50px 40px;
            text-align: center;
            color: white;
        }

        .login-icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .login-header h1 {
            font-size: 32px;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .login-header p {
            font-size: 16px;
            opacity: 0.95;
        }

        .login-body {
            padding: 40px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            animation: fadeIn 0.3s;
            line-height: 1.5;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .alert-error {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: #c62828;
            border: 2px solid #f44336;
        }

        .alert-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
            border: 2px solid #2196f3;
        }

        .passkey-info {
            background: #f7fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border-left: 4px solid #667eea;
        }

        .passkey-info h3 {
            font-size: 18px;
            margin-bottom: 16px;
            color: #2d3748;
        }

        .passkey-hint {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px;
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            border-radius: 8px;
            font-size: 13px;
            color: #e65100;
            border-left: 3px solid #ff9800;
            line-height: 1.6;
        }

        .passkey-hint strong {
            color: #bf360c;
        }

        .passkey-example {
            margin-top: 12px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .passkey-example strong {
            color: #667eea;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #4a5568;
        }

        .password-wrapper {
            position: relative;
            display: block;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .password-wrapper .form-control {
            padding-right: 50px;
        }

        .password-toggle-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            font-size: 20px;
            color: #a0aec0;
            transition: all 0.3s;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            z-index: 10;
        }

        .password-toggle-btn:hover {
            background: #f0f0f0;
            color: #667eea;
        }

        .password-toggle-btn:active {
            transform: translateY(-50%) scale(0.95);
        }

        .btn-login {
            width: 100%;
            padding: 16px;
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

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-back {
            width: 100%;
            padding: 14px;
            background: #e2e8f0;
            color: #4a5568;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 12px;
            transition: all 0.3s;
            text-decoration: none;
            display: block;
            text-align: center;
        }

        .btn-back:hover {
            background: #cbd5e0;
        }

        @media (max-width: 480px) {
            .login-container {
                max-width: 100%;
            }
            
            .login-header {
                padding: 40px 30px;
            }
            
            .login-body {
                padding: 30px 25px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-icon">🔐</div>
            <h1>Pierre Gasly</h1>
            <p>Admin Panel Login</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span style="font-size: 20px;">⚠️</span>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!$showPasskeyForm): ?>
                <!-- Step 1: Email and Password -->
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               required 
                               class="form-control"
                               placeholder="Enter your email" 
                               autocomplete="off"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   required 
                                   class="form-control"
                                   placeholder="Enter your password"
                                   autocomplete="off">
                            <button type="button" 
                                    class="password-toggle-btn" 
                                    onclick="togglePasswordVisibility('password')"
                                    title="Show/Hide Password">
                                <span id="password-icon">👁️</span>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" name="login_step1" class="btn-login">
                        Login
                    </button>
                </form>
            <?php else: ?>
                <!-- Step 2: Passkey (Sub Admin Only) -->
                <div class="alert alert-info">
                    <span>🔑</span>
                    <span>Sub Admin verification required</span>
                </div>
                
                <div class="passkey-info">
                    <h3>Enter Your PGAS Passkey</h3>
                    
                    <div class="passkey-hint">
                        <span style="font-size: 20px;">💡</span>
                        <div>
                            <strong>Format: PGAS + Your Birthday</strong><br>
                            Your passkey is <strong>PGAS</strong> followed by your birthday in <strong>MM-DD-YYYY</strong> format
                        </div>
                    </div>
                    
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <div class="form-group">
                        <label for="passkey">PGAS Passkey</label>
                        <div class="password-wrapper">
                            <input type="password" 
                                   id="passkey" 
                                   name="passkey" 
                                   required 
                                   class="form-control"
                                   placeholder="PGAS01-15-1990" 
                                   pattern="PGAS\d{2}-\d{2}-\d{4}"
                                   maxlength="16"
                                   autocomplete="off">
                            <button type="button" 
                                    class="password-toggle-btn" 
                                    onclick="togglePasswordVisibility('passkey')"
                                    title="Show/Hide Passkey">
                                <span id="passkey-icon">👁️</span>
                            </button>
                        </div>
                        <small style="color: #718096; font-size: 12px; display: block; margin-top: 6px;">
                            Remember: PGAS + Month-Day-Year (PGASMM-DD-YYYY)
                        </small>
                    </div>
                    
                    <button type="submit" name="login_step2" class="btn-login">
                        Verify & Login
                    </button>
                    
                    <a href="login.php" class="btn-back">
                        ← Back to Login
                    </a>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(inputId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(inputId + '-icon');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.textContent = '👁️‍🗨️';
            } else {
                input.type = 'password';
                icon.textContent = '👁️';
            }
        }

        // Auto-focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            const firstInput = document.querySelector('input[type="email"], input[type="password"]');
            if (firstInput) {
                firstInput.focus();
            }
        });
    </script>
</body>
</html>