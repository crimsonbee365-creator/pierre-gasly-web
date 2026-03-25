<?php
/**
 * PIERRE GASLY - Users Management
 * Manage Delivery Riders and Customers (Sub-Admin removed)
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Users Management';
$db = Database::getInstance();

$success = '';
$error = '';


function authAdminRequest(string $method, string $path, ?array $payload = null): array {
    $endpoint = rtrim(SUPABASE_URL, '/') . $path;
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        throw new Exception('Supabase Auth request failed');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return ['status' => $httpCode, 'data' => $decoded, 'raw' => $response];
}

function authUserExistsByEmail(string $email): bool {
    $result = authAdminRequest('GET', '/auth/v1/admin/users?email=' . urlencode($email));
    if (($result['status'] ?? 500) >= 400) {
        throw new Exception('Unable to check Supabase Auth records');
    }

    $data = $result['data'] ?? [];
    $users = [];
    if (isset($data['users']) && is_array($data['users'])) {
        $users = $data['users'];
    } elseif (isset($data[0])) {
        $users = $data;
    }

    foreach ($users as $user) {
        if (!empty($user['email']) && strtolower((string)$user['email']) === strtolower($email)) {
            return true;
        }
    }
    return false;
}

function createConfirmedAuthUser(string $email, string $password, array $metadata = []): array {
    $result = authAdminRequest('POST', '/auth/v1/admin/users', [
        'email' => $email,
        'password' => $password,
        'email_confirm' => true,
        'user_metadata' => $metadata,
        'app_metadata' => [
            'provider' => 'email',
            'role' => $metadata['role'] ?? 'rider',
        ],
    ]);

    if (($result['status'] ?? 500) >= 400) {
        $message = $result['data']['msg'] ?? $result['data']['message'] ?? $result['raw'] ?? 'Unable to create rider authentication account';
        throw new Exception($message);
    }

    return $result['data'];
}

function deleteAuthUserById(?string $authUserId): void {
    if (!$authUserId) return;
    try {
        authAdminRequest('DELETE', '/auth/v1/admin/users/' . urlencode($authUserId));
    } catch (Throwable $e) {
        error_log('Failed to rollback Supabase Auth user ' . $authUserId . ': ' . $e->getMessage());
    }
}


// Get user type filter (only rider and customer now)
$user_type = $_GET['type'] ?? 'rider';
if (!in_array($user_type, ['rider', 'customer'])) {
    $user_type = 'rider';
}

// Handle Add Rider
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rider'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $full_name = sanitize($_POST['full_name']);
        $email     = strtolower(sanitize($_POST['email']));
        $phone     = sanitize($_POST['phone']);
        $birthday  = $_POST['birthday'];

        $validation_errors = [];

        if (strlen($full_name) < 3)
            $validation_errors[] = 'Name must be at least 3 characters';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            $validation_errors[] = 'Invalid email format';

        // Accept +63, 09, or 9 prefix then normalize
        if (!preg_match('/^(?:\+63|0)?9\d{9}$/', $phone))
            $validation_errors[] = 'Phone must be a valid Philippine mobile number (9XXXXXXXXX / 09XXXXXXXXX / +639XXXXXXXXX)';

        // Normalize to 09XXXXXXXXX for storage
        $normalized_phone = $phone;
        if (preg_match('/^\+63(9\d{9})$/', $phone, $m)) { 
            $normalized_phone = '0' . $m[1]; 
        } else if (preg_match('/^(9\d{9})$/', $phone, $m)) { 
            $normalized_phone = '0' . $m[1]; 
        }


        if ($db->fetchOne("SELECT user_id FROM users WHERE email = ?", [$email]))
            $validation_errors[] = 'Email already exists';

        if ($db->fetchOne("SELECT user_id FROM users WHERE phone = ?", [$normalized_phone]))
            $validation_errors[] = 'Phone number already exists';

        try {
            if (authUserExistsByEmail($email)) {
                $validation_errors[] = 'Email already exists in authentication records';
            }
        } catch (Exception $e) {
            $validation_errors[] = 'Unable to validate rider email against authentication service';
        }

        if (!empty($validation_errors)) {
            $error = implode('<br>', $validation_errors);
        } else {
            $profile_photo = null;
            $valid_id      = null;

            // Profile photo — optional
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['profile_photo'], 'profiles', ALLOWED_IMAGE_TYPES);
                if ($upload['success']) $profile_photo = $upload['filename'];
            }

            // Valid ID — optional, images + PDF allowed
            if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['valid_id'], 'ids', ALLOWED_DOC_TYPES);
                if ($upload['success']) $valid_id = $upload['filename'];
            }

            // IMPORTANT: Use the phone number as the temporary password
            // This allows rider to login with phone as password, then they'll be prompted to change it
            $temp_password = $normalized_phone;
            $password_hash = hashPassword($temp_password);
            $authUserId = null;

            try {
                $authUser = createConfirmedAuthUser($email, $temp_password, [
                    'full_name' => $full_name,
                    'phone' => $normalized_phone,
                    'role' => 'rider'
                ]);
                $authUserId = $authUser['id'] ?? null;
            } catch (Exception $e) {
                $error = 'Failed to create rider login account: ' . htmlspecialchars($e->getMessage());
            }

            if (!$error) {
                $sql = "INSERT INTO users (auth_user_id, full_name, email, phone, birthday, password_hash, profile_photo, valid_id, role, status, email_verified, first_login) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'rider', 'active', true, true)";

                if ($db->query($sql, [$authUserId, $full_name, $email, $normalized_phone, $birthday, $password_hash, $profile_photo, $valid_id])) {
                    $success = "Rider created successfully!<br><strong>Email:</strong> $email<br><strong>Temporary Password:</strong> $normalized_phone<br><br><small style='color:#666;'>ℹ️ The rider account was created in Supabase Auth as a confirmed email account. No OTP is needed for rider signup. They can log in immediately with their email and temporary phone-number password, then change it after first login.</small>";
                    logActivity('create', 'user', "Created rider: $full_name", $db->lastInsertId());
                } else {
                    deleteAuthUserById($authUserId);
                    $error = 'Failed to create rider. ' . ($db->getLastError() ?: '');
                }
            }
        }
    }
}

// Handle Update Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $user_id    = (int)$_POST['user_id'];
        $new_status = $_POST['new_status'];

        if (!in_array($new_status, ['active', 'suspended'], true)) {
            $error = 'Invalid status value';
        } elseif ($db->query("UPDATE users SET status = ? WHERE user_id = ?", [$new_status, $user_id])) {
            $success = 'User status updated successfully!';
            logActivity('update', 'user', $user_id, "Updated status to: $new_status");
        } else {
            $error = 'Failed to update status';
        }
    }
}

// Get users based on type
$users = $db->select('users', ['role' => $user_type]);

// Sort by created_at DESC
usort($users, function($a, $b) {
    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

// Add computed fields (totals) for UI
foreach ($users as &$uRow) {
    $uid = $uRow['user_id'] ?? null;
    if (!$uid) {
        $uRow['total_orders'] = 0;
        $uRow['total_deliveries'] = 0;
        continue;
    }

    // Delivered orders count (as used by the UI)
    $deliveredOrders = $db->select('orders', ['customer_id' => $uid, 'order_status' => 'delivered'], 'order_id');
    $uRow['total_orders'] = is_array($deliveredOrders) ? count($deliveredOrders) : 0;

    // Rider deliveries count (sales rows)
    $salesRows = $db->select('sales', ['rider_id' => $uid], 'sale_id');
    $uRow['total_deliveries'] = is_array($salesRows) ? count($salesRows) : 0;
}
unset($uRow);


// Get counts (sub_admin removed)
$counts = [
    'rider'     => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE role = 'rider'")['count'],
    'customer'  => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE role = 'customer'")['count']
];

$csrfToken = generateCSRFToken();
include 'includes/header.php';
?>

<style>
.user-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 25px;
    border-bottom: 2px solid #e0e0e0;
}

.tab-btn {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    color: #666;
    transition: all 0.3s;
    text-decoration: none;
}

.tab-btn:hover { color: #667eea; }

.tab-btn.active {
    color: #667eea;
    border-bottom-color: #667eea;
}

.user-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 20px;
    align-items: center;
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

.user-info h3 { font-size: 18px; margin-bottom: 5px; }
.user-info p  { font-size: 13px; color: #666; margin: 3px 0; }

.user-actions { display: flex; gap: 8px; }

/* Password toggle */
.password-wrapper { position: relative; display: flex; flex-wrap: wrap; }
.password-wrapper .form-control { flex: 1; padding-right: 50px; }
.password-toggle-btn {
    position: absolute; right: 12px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; cursor: pointer; font-size: 18px; padding: 4px;
}
.password-wrapper .input-hint { flex-basis: 100%; margin-top: 6px; }

/* Modal styling aligned with Products */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal.active { display: flex; }

.modal-content {
    position: relative;
    background: #fff;
    border-radius: 20px;
    padding: 0;
    width: min(92vw, 720px);
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-30px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-header {
    padding: 25px 30px;
    border-bottom: 1px solid #e8ecf1;
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
}

.modal-header h3 {
    font-size: 22px;
    margin: 0;
    color: #2d3748;
    font-weight: 700;
}

.modal-body {
    padding: 30px;
    overflow-y: auto;
    max-height: calc(90vh - 180px);
}

.modal-footer {
    padding: 20px 30px;
    border-top: 1px solid #e8ecf1;
    background: #f7fafc;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.btn-close-improved {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #f1f5f9;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    z-index: 10;
}

.btn-close-improved:hover {
    background: #e2e8f0;
    transform: scale(1.06);
}

.btn-close-improved svg { color: #475569; }
.btn-close-improved:hover svg { color: #1e293b; }

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group-full { grid-column: 1 / -1; }
.form-group { margin-bottom: 0; }

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
    background: #fff;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.textarea-control {
    min-height: 92px;
    resize: vertical;
}

.field-meta {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-top: 6px;
    font-size: 12px;
    color: #94a3b8;
}

.warning-note {
    margin-top: 18px;
    padding: 14px 16px;
    background: #fff7ed;
    border: 1px solid #fdba74;
    border-radius: 12px;
}

.warning-note strong {
    color: #9a3412;
    display: block;
    margin-bottom: 8px;
}

.warning-note ol {
    margin: 0;
    padding-left: 18px;
    color: #9a3412;
    font-size: 13px;
}

.upload-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.image-upload-wrapper { position: relative; }

.image-upload-box {
    border: 3px dashed #cbd5e0;
    border-radius: 12px;
    padding: 34px 22px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background: #f7fafc;
    display: block;
    min-height: 180px;
}

.image-upload-box:hover {
    border-color: #667eea;
    background: #f5f7ff;
}

.image-upload-box.drag-over {
    border-color: #667eea;
    background: #e3f2fd;
    transform: scale(1.02);
}

.image-upload-box .upload-icon {
    font-size: 40px;
    margin-bottom: 10px;
    color: #a0aec0;
}

.image-upload-box .upload-text {
    font-size: 15px;
    color: #4a5568;
    font-weight: 600;
    margin-bottom: 4px;
}

.image-upload-box .upload-subtext {
    font-size: 12px;
    color: #94a3b8;
}

.image-upload-box input[type="file"] { display: none; }

.file-name {
    margin-top: 10px;
    font-size: 12px;
    color: #64748b;
    text-align: center;
    min-height: 18px;
    word-break: break-word;
}

.image-preview-container {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    margin-top: 12px;
    display: none;
}

.image-preview-container.active { display: block; }

.image-preview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #4a5568;
}

.image-preview {
    width: 100%;
    max-width: 260px;
    max-height: 180px;
    object-fit: contain;
    border-radius: 10px;
    display: block;
    margin: 0 auto;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.preview-file-badge {
    display: none;
    margin-top: 10px;
    text-align: center;
    padding: 10px 12px;
    background: #eef2ff;
    border-radius: 10px;
    color: #4338ca;
    font-size: 13px;
    font-weight: 600;
}

.preview-file-badge.active { display: block; }

.remove-preview-btn {
    background: #fff1f2;
    color: #e11d48;
    border: 1px solid #fecdd3;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.remove-preview-btn:hover {
    background: #ffe4e6;
}

.status-message {
    font-size: 15px;
    color: #475569;
    line-height: 1.6;
}

@media (max-width: 768px) {
    .form-grid,
    .upload-grid {
        grid-template-columns: 1fr;
    }

    .modal {
        padding: 12px;
    }

    .modal-body,
    .modal-footer,
    .modal-header {
        padding-left: 18px;
        padding-right: 18px;
    }
}
</style>

<div class="page-header">
    <div class="header-kicker">Accounts</div><h1>Users Management</h1>
    <p>Manage delivery riders and customers</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success">✓ <?php echo $success; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">✗ <?php echo $error; ?></div>
<?php endif; ?>

<!-- User Type Tabs (Sub-Admin removed) -->
<div class="user-tabs">
    <a href="?type=rider" class="tab-btn <?php echo $user_type == 'rider' ? 'active' : ''; ?>">
        Delivery Riders (<?php echo $counts['rider']; ?>)
    </a>
    <a href="?type=customer" class="tab-btn <?php echo $user_type == 'customer' ? 'active' : ''; ?>">
        Customers (<?php echo $counts['customer']; ?>)
    </a>
</div>

<!-- Action Buttons -->
<div style="margin-bottom: 25px;">
    <?php if ($user_type == 'rider'): ?>
        <button onclick="document.getElementById('addRiderModal').classList.add('active')" class="btn btn-primary">
            Add Delivery Rider
        </button>
    <?php endif; ?>
</div>

<!-- Users List -->
<?php if (empty($users)): ?>
    <div class="empty-state" style="background: white; padding: 60px; border-radius: 12px; text-align: center;">
        <div style="font-size: 64px; margin-bottom: 20px;">•</div>
        <h3>No <?php echo ucfirst(str_replace('_', ' ', $user_type)); ?>s Found</h3>
        <p style="color: #666;">
            <?php if ($user_type == 'customer'): ?>
                Customers will appear here when they sign up via the mobile app
            <?php else: ?>
                Click the button above to add your first <?php echo str_replace('_', ' ', $user_type); ?>
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <?php foreach ($users as $user): ?>
    <div class="user-card">
        <div class="user-avatar">
            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
        </div>
        
        <div class="user-info">
            <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
            <p><?php echo htmlspecialchars($user['email']); ?></p>
            <?php if ($user['phone']): ?>
                <p><?php echo htmlspecialchars($user['phone']); ?></p>
            <?php endif; ?>
            <p>
                <span class="badge badge-<?php echo $user['status'] == 'active' ? 'success' : 'danger'; ?>">
                    <?php echo ucfirst($user['status']); ?>
                </span>
                <?php if ($user['role'] === 'rider'): ?>
                <?php 
                    $avail = $user['rider_availability'] ?? 'standby';
                    $avail_badges = [
                        'standby'          => ['color'=>'#22c55e','label'=>'🟢 Standby'],
                        'out_for_delivery' => ['color'=>'#f59e0b','label'=>'Delivering'],
                        'on_leave'         => ['color'=>'#8b5cf6','label'=>'🏖️ On Leave'],
                        'off_duty'         => ['color'=>'#ef4444','label'=>'🔴 Off Duty'],
                    ];
                    $ab = $avail_badges[$avail] ?? $avail_badges['standby'];
                ?>
                <span class="badge" style="background:<?php echo $ab['color']; ?>;color:#fff;margin-left:4px;">
                    <?php echo $ab['label']; ?>
                </span>
                <?php endif; ?>

            </p>
            <?php if ($user_type == 'rider' && $user['total_deliveries'] > 0): ?>
                <p style="margin-top: 8px;"><strong><?php echo $user['total_deliveries']; ?></strong> deliveries completed</p>
            <?php endif; ?>
            <?php if ($user_type == 'customer' && $user['total_orders'] > 0): ?>
                <p style="margin-top: 8px;"><strong><?php echo $user['total_orders']; ?></strong> orders placed</p>
            <?php endif; ?>
        </div>
        
        <div class="user-actions">
            <?php if ($user['role'] != 'master_admin' && isMasterAdmin()): ?>
                <button onclick="updateStatus(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>', '<?php echo $user['status']; ?>', '<?php echo $user['role']; ?>', '<?php echo $user['rider_availability'] ?? 'standby'; ?>')" 
                        class="btn btn-sm <?php echo $user['status'] == 'active' ? 'btn-warning' : 'btn-primary'; ?>">
                    <?php echo $user['status'] == 'active' ? 'Suspend' : 'Activate'; ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Add Rider Modal -->
<div id="addRiderModal" class="modal">
    <div class="modal-content">
        <button class="btn-close-improved" type="button" onclick="closeModal('addRiderModal')" title="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        <div class="modal-header">
            <h3>Add Delivery Rider</h3>
        </div>
        <div class="modal-body">
            <form method="POST" action="" enctype="multipart/form-data" id="riderForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="form-grid">
                    <div class="form-group form-group-full">
                        <label for="riderFullName">Full Name *</label>
                        <input type="text" id="riderFullName" name="full_name" required class="form-control" placeholder="Juan Dela Cruz" maxlength="100">
                        <div class="field-meta"><span></span><span>0 / 100 characters</span></div>
                    </div>

                    <div class="form-group">
                        <label for="riderEmail">Gmail Account *</label>
                        <input type="email" id="riderEmail" name="email" required class="form-control" placeholder="rider@gmail.com">
                        <div class="field-meta"><span>Must be a valid Gmail address</span></div>
                    </div>

                    <div class="form-group">
                        <label for="riderPhone">Phone Number *</label>
                        <input type="tel" id="riderPhone" name="phone" required class="form-control" placeholder="09XX XXX XXXX" maxlength="13">
                        <div class="field-meta"><span>Format: 639123456789 or +639123456789</span></div>
                    </div>

                    <div class="form-group form-group-full">
                        <label for="riderBirthday">Birthday *</label>
                        <input type="date" id="riderBirthday" name="birthday" required class="form-control">
                    </div>
                </div>

                <div class="upload-grid" style="margin-top: 20px;">
                    <div class="form-group">
                        <label>Profile Photo</label>
                        <div class="image-upload-wrapper">
                            <label class="image-upload-box" for="profilePhoto" id="profilePhotoBox">
                                <div class="upload-icon">📷</div>
                                <div class="upload-text">Click to upload</div>
                                <div class="upload-subtext">PNG, JPG up to 5MB</div>
                                <input type="file" id="profilePhoto" name="profile_photo" accept="image/png,image/jpeg,image/jpg" onchange="previewImage(this, 'profilePhotoPreview', 'profilePhotoFileName', 'profilePhotoBox')">
                            </label>
                            <div id="profilePhotoFileName" class="file-name"></div>
                            <div id="profilePhotoPreview" class="image-preview-container">
                                <div class="image-preview-header">
                                    <span>Profile Photo Preview</span>
                                    <button type="button" class="remove-preview-btn" onclick="removePreview('profilePhoto','profilePhotoPreview','profilePhotoFileName','profilePhotoBox')">Remove</button>
                                </div>
                                <img class="image-preview" alt="Profile preview">
                                <div class="preview-file-badge"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Driver's License</label>
                        <div class="image-upload-wrapper">
                            <label class="image-upload-box" for="validID" id="validIDBox">
                                <div class="upload-icon">🪪</div>
                                <div class="upload-text">Click to upload</div>
                                <div class="upload-subtext">PDF, PNG, JPG up to 10MB</div>
                                <input type="file" id="validID" name="valid_id" accept="image/*,application/pdf" onchange="previewImage(this, 'validIDPreview', 'validIDFileName', 'validIDBox')">
                            </label>
                            <div id="validIDFileName" class="file-name"></div>
                            <div id="validIDPreview" class="image-preview-container">
                                <div class="image-preview-header">
                                    <span>Driver's License Preview</span>
                                    <button type="button" class="remove-preview-btn" onclick="removePreview('validID','validIDPreview','validIDFileName','validIDBox')">Remove</button>
                                </div>
                                <img class="image-preview" alt="ID preview">
                                <div class="preview-file-badge"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="warning-note">
                    <strong>First Login Process</strong>
                    <ol>
                        <li>The rider's phone number will be used as the temporary password.</li>
                        <li>After first login, they should change it immediately.</li>
                        <li>They can also use Forgot Password in the mobile app to reset it using OTP.</li>
                    </ol>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeModal('addRiderModal')" class="btn btn-cancel">Cancel</button>
            <button type="submit" form="riderForm" name="add_rider" class="btn btn-primary">Create Rider Account</button>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div id="updateStatusModal" class="modal">
    <div class="modal-content" style="max-width: 460px; width: min(92vw, 460px);">
        <button class="btn-close-improved" type="button" onclick="closeModal('updateStatusModal')" title="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        <form method="POST" id="statusForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="user_id" id="status_user_id">
            <input type="hidden" name="new_status" id="status_new_status">

            <div class="modal-header">
                <h3>Update User Status</h3>
            </div>
            <div class="modal-body">
                <p id="statusMessage" class="status-message">Are you sure you want to update this user's status?</p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('updateStatusModal')" class="btn btn-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('active');
}

function updateStatus(userId, userName, currentStatus, role, riderAvail) {
    const newStatus = currentStatus === 'active' ? 'suspended' : 'active';
    document.getElementById('status_user_id').value = userId;
    document.getElementById('status_new_status').value = newStatus;

    const action = newStatus === 'active' ? 'activate' : 'suspend';
    document.getElementById('statusMessage').textContent = `Are you sure you want to ${action} ${userName}?`;

    document.getElementById('updateStatusModal').classList.add('active');
}

function previewImage(input, previewContainerId, fileNameId, uploadBoxId) {
    const container = document.getElementById(previewContainerId);
    const img = container.querySelector('.image-preview');
    const badge = container.querySelector('.preview-file-badge');
    const fileName = document.getElementById(fileNameId);
    const uploadBox = document.getElementById(uploadBoxId);
    const icon = uploadBox ? uploadBox.querySelector('.upload-icon') : null;
    const text = uploadBox ? uploadBox.querySelector('.upload-text') : null;

    if (!input.files || !input.files[0]) return;

    const file = input.files[0];
    if (fileName) fileName.textContent = file.name;
    if (icon) icon.textContent = '✓';
    if (text) text.textContent = 'File selected';

    if (file.type === 'application/pdf') {
        img.removeAttribute('src');
        img.style.display = 'none';
        badge.textContent = `PDF selected: ${file.name}`;
        badge.classList.add('active');
        container.classList.add('active');
        return;
    }

    badge.textContent = '';
    badge.classList.remove('active');
    img.style.display = 'block';

    const reader = new FileReader();
    reader.onload = function(e) {
        img.src = e.target.result;
        container.classList.add('active');
    };
    reader.readAsDataURL(file);
}

function removePreview(inputId, containerId, fileNameId, uploadBoxId) {
    const input = document.getElementById(inputId);
    const container = document.getElementById(containerId);
    const img = container.querySelector('.image-preview');
    const badge = container.querySelector('.preview-file-badge');
    const fileName = document.getElementById(fileNameId);
    const uploadBox = document.getElementById(uploadBoxId);
    const icon = uploadBox ? uploadBox.querySelector('.upload-icon') : null;
    const text = uploadBox ? uploadBox.querySelector('.upload-text') : null;

    input.value = '';
    container.classList.remove('active');
    img.removeAttribute('src');
    img.style.display = 'block';
    badge.textContent = '';
    badge.classList.remove('active');
    if (fileName) fileName.textContent = '';
    if (icon) icon.textContent = inputId === 'validID' ? '🪪' : '📷';
    if (text) text.textContent = 'Click to upload';
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(modal => modal.classList.remove('active'));
    }
});
</script>

<?php include 'includes/footer.php'; ?>
