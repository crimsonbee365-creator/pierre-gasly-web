<?php
/**
 * PIERRE GASLY - Users Management
 * Manage Sub Admins, Riders, and Customers
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Users Management';
$db = Database::getInstance();

$success = '';
$error = '';

// Get user type filter
$user_type = $_GET['type'] ?? 'sub_admin';

// Handle Add Sub Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sub_admin'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $full_name = sanitize($_POST['full_name']);
        $email     = sanitize($_POST['email']);
        $password  = $_POST['password'];
        $birthday  = $_POST['birthday'];

        $validation_errors = [];

        if (strlen($full_name) < 3)
            $validation_errors[] = 'Name must be at least 3 characters';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            $validation_errors[] = 'Invalid email format';

        if (strlen($password) < 8)
            $validation_errors[] = 'Password must be at least 8 characters';

        if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password))
            $validation_errors[] = 'Password must contain both letters and numbers';

        if ($db->fetchOne("SELECT user_id FROM users WHERE email = ?", [$email]))
            $validation_errors[] = 'Email already exists';

        if (!empty($validation_errors)) {
            $error = implode('<br>', $validation_errors);
        } else {
            $password_hash = hashPassword($password);
            $sql = "INSERT INTO users (full_name, email, password_hash, birthday, role, status) 
                    VALUES (?, ?, ?, ?, 'sub_admin', 'active')";
            if ($db->query($sql, [$full_name, $email, $password_hash, $birthday])) {
                $success = "Sub Admin created successfully! Email: $email | Password: $password | PGAS Passkey: PGAS" . date('m-d-Y', strtotime($birthday));
                logActivity('create', 'user', $db->lastInsertId(), "Created sub admin: $full_name");
            } else {
                $error = 'Failed to create sub admin. ' . ($db->getLastError() ?: '');
            }
        }
    }
}

// Handle Add Rider
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rider'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $full_name = sanitize($_POST['full_name']);
        $email     = sanitize($_POST['email']);
        $phone     = sanitize($_POST['phone']);
        $address   = sanitize($_POST['address']);
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
        if (preg_match('/^\+63(9\d{9})$/', $phone, $m)) { $phone = '0' . $m[1]; }
        else if (preg_match('/^(9\d{9})$/', $phone, $m)) { $phone = '0' . $m[1]; }

        if (strlen($address) < 10)
            $validation_errors[] = 'Address must be at least 10 characters';

        if ($db->fetchOne("SELECT user_id FROM users WHERE email = ?", [$email]))
            $validation_errors[] = 'Email already exists';

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

            $temp_password = generateTempPassword();
            $password_hash = hashPassword($temp_password);

            $sql = "INSERT INTO users (full_name, email, phone, address, birthday, password_hash, profile_photo, valid_id, role, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'rider', 'active')";

            if ($db->query($sql, [$full_name, $email, $phone, $address, $birthday, $password_hash, $profile_photo, $valid_id])) {
                $success = "Rider created successfully!<br>Email: <strong>$email</strong><br>Temporary Password: <strong>$temp_password</strong><br>Please provide these credentials to the rider.";
                logActivity('create', 'user', $db->lastInsertId(), "Created rider: $full_name");
            } else {
                $error = 'Failed to create rider. ' . ($db->getLastError() ?: '');
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
        
        if ($db->query("UPDATE users SET status = ? WHERE user_id = ?", [$new_status, $user_id])) {
            $success = 'User status updated successfully!';
            logActivity('update', 'user', $user_id, "Updated status to: $new_status");
        } else {
            $error = 'Failed to update status';
        }
    }
}

// Get users based on type
// Fetch users by role (Supabase-friendly; avoids complex subqueries)
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


// Get counts
$counts = [
    'sub_admin' => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE role = 'sub_admin'")['count'],
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

/* Image preview */
.image-preview-container {
    background: #f7fafc; border: 2px dashed #cbd5e0;
    border-radius: 12px; padding: 14px; margin-top: 10px; display: none;
}
.image-preview-container.active { display: block; }
.image-preview-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0;
    font-size: 13px; font-weight: 600; color: #4a5568;
}
.image-preview {
    width: 100%; max-width: 360px; height: auto; max-height: 220px;
    object-fit: contain; border-radius: 8px; display: block; margin: 0 auto;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.remove-preview-btn {
    background: #fee; color: #f44336; border: 1px solid #f44336;
    padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;
}
.remove-preview-btn:hover { background: #f44336; color: white; }
</style>

<div class="page-header">
    <h1>👥 Users Management</h1>
    <p>Manage sub admins, delivery riders, and customers</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success">✓ <?php echo $success; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">✗ <?php echo $error; ?></div>
<?php endif; ?>

<!-- User Type Tabs -->
<div class="user-tabs">
    <a href="?type=sub_admin" class="tab-btn <?php echo $user_type == 'sub_admin' ? 'active' : ''; ?>">
        👨‍💼 Sub Admins (<?php echo $counts['sub_admin']; ?>)
    </a>
    <a href="?type=rider" class="tab-btn <?php echo $user_type == 'rider' ? 'active' : ''; ?>">
        🚚 Delivery Riders (<?php echo $counts['rider']; ?>)
    </a>
    <a href="?type=customer" class="tab-btn <?php echo $user_type == 'customer' ? 'active' : ''; ?>">
        👤 Customers (<?php echo $counts['customer']; ?>)
    </a>
</div>

<!-- Action Buttons -->
<div style="margin-bottom: 25px;">
    <?php if ($user_type == 'sub_admin' && isMasterAdmin()): ?>
        <button onclick="document.getElementById('addSubAdminModal').classList.add('active')" class="btn btn-primary">
            ➕ Add Sub Admin
        </button>
    <?php endif; ?>
    
    <?php if ($user_type == 'rider'): ?>
        <button onclick="document.getElementById('addRiderModal').classList.add('active')" class="btn btn-primary">
            ➕ Add Delivery Rider
        </button>
    <?php endif; ?>
</div>

<!-- Users List -->
<?php if (empty($users)): ?>
    <div class="empty-state" style="background: white; padding: 60px; border-radius: 12px; text-align: center;">
        <div style="font-size: 64px; margin-bottom: 20px;">👥</div>
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
            <p>📧 <?php echo htmlspecialchars($user['email']); ?></p>
            <?php if ($user['phone']): ?>
                <p>📱 <?php echo htmlspecialchars($user['phone']); ?></p>
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
                        'out_for_delivery' => ['color'=>'#f59e0b','label'=>'🚚 Delivering'],
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
                <p style="margin-top: 8px;">🚚 <strong><?php echo $user['total_deliveries']; ?></strong> deliveries completed</p>
            <?php endif; ?>
            <?php if ($user_type == 'customer' && $user['total_orders'] > 0): ?>
                <p style="margin-top: 8px;">📦 <strong><?php echo $user['total_orders']; ?></strong> orders placed</p>
            <?php endif; ?>
        </div>
        
        <div class="user-actions">
            <?php if ($user['role'] != 'master_admin' && isMasterAdmin()): ?>
                <button onclick="updateStatus(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>', '<?php echo $user['status']; ?>', '<?php echo $user['role']; ?>', '<?php echo $user['rider_availability'] ?? 'standby'; ?>')" 
                        class="btn btn-sm <?php echo $user['status'] == 'active' ? 'btn-warning' : 'btn-primary'; ?>">
                    <?php echo $user['status'] == 'active' ? '🔒 Suspend' : '✅ Activate'; ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Add Sub Admin Modal -->
<div id="addSubAdminModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <span class="btn-close" onclick="document.getElementById('addSubAdminModal').classList.remove('active')">&times;</span>
            <h3>➕ Add Sub Admin</h3>
        </div>
        <div class="modal-body">
            <form method="POST" action="" id="subAdminForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required class="form-control"
                           placeholder="John Doe" maxlength="100">
                    <div class="input-hint"></div>
                </div>
                
                <div class="form-group">
                    <label>Email (Gmail) *</label>
                    <input type="email" name="email" required class="form-control"
                           placeholder="johndoe@gmail.com">
                    <div class="input-hint"></div>
                </div>
                
                <div class="form-group">
                    <label>Password *</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" required class="form-control"
                               placeholder="Minimum 8 characters" id="subAdminPassword">
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('subAdminPassword')">
                            <span id="subAdminPassword-icon">👁️</span>
                        </button>
                        <div class="input-hint"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Birthday (MM/DD/YYYY) *</label>
                    <input type="date" name="birthday" required class="form-control">
                    <div class="input-hint"></div>
                    <small style="color: #666; font-size: 12px; display:block; margin-top:4px;">
                        📝 PGAS Passkey format: <strong>PGASMM-DD-YYYY</strong><br>
                        Example: Jan 15, 1990 → Passkey: <strong>PGAS01-15-1990</strong>
                    </small>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="document.getElementById('addSubAdminModal').classList.remove('active')" class="btn btn-cancel">
                Cancel
            </button>
            <button type="submit" form="subAdminForm" name="add_sub_admin" class="btn btn-primary">
                Create Sub Admin
            </button>
        </div>
    </div>
</div>

<!-- Add Rider Modal -->
<div id="addRiderModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <span class="btn-close" onclick="document.getElementById('addRiderModal').classList.remove('active')">&times;</span>
            <h3>🚚 Add Delivery Rider</h3>
        </div>
        <div class="modal-body">
            <form method="POST" action="" enctype="multipart/form-data" id="riderForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required class="form-control"
                               placeholder="Juan Dela Cruz" maxlength="100">
                        <div class="input-hint"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" required class="form-control"
                               placeholder="09XXXXXXXXX" maxlength="11">
                        <div class="input-hint"></div>
                    </div>
                    
                    <div class="form-group form-group-full">
                        <label>System Email *</label>
                        <input type="email" name="email" required class="form-control"
                               placeholder="PGASDeliver01@gmail.com">
                        <div class="input-hint"></div>
                        <small style="color:#666;font-size:12px;display:block;margin-top:4px;">Example: PGASDeliver01@gmail.com</small>
                    </div>
                    
                    <div class="form-group form-group-full">
                        <label>Address *</label>
                        <textarea name="address" required class="form-control" rows="2"
                                  placeholder="Complete address"></textarea>
                        <div class="input-hint"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Birthday *</label>
                        <input type="date" name="birthday" required class="form-control">
                        <div class="input-hint"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Profile Photo</label>
                        <input type="file" name="profile_photo" accept="image/*" class="form-control"
                               id="profilePhoto" onchange="previewImage(this,'profilePhotoPreview')">
                    </div>

                    <div class="form-group form-group-full">
                        <div id="profilePhotoPreview" class="image-preview-container">
                            <div class="image-preview-header">
                                <span>📷 Profile Photo Preview</span>
                                <button type="button" class="remove-preview-btn"
                                        onclick="removePreview('profilePhoto','profilePhotoPreview')">✕ Remove</button>
                            </div>
                            <img src="" alt="Preview" class="image-preview">
                        </div>
                    </div>
                    
                    <div class="form-group form-group-full">
                        <label>Driver's License</label>
                        <input type="file" name="valid_id" accept="image/*,application/pdf"  class="form-control"
                               id="validId" onchange="previewImage(this,'validIdPreview')">
                    </div>

                    <div class="form-group form-group-full">
                        <div id="validIdPreview" class="image-preview-container">
                            <div class="image-preview-header">
                                <span>🪪 Driver's License Preview</span>
                                <button type="button" class="remove-preview-btn"
                                        onclick="removePreview('validId','validIdPreview')">✕ Remove</button>
                            </div>
                            <img src="" alt="Preview" class="image-preview">
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="document.getElementById('addRiderModal').classList.remove('active')" class="btn btn-cancel">
                Cancel
            </button>
            <button type="submit" form="riderForm" name="add_rider" class="btn btn-primary">
                Create Rider
            </button>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div id="updateStatusModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <span class="btn-close" onclick="document.getElementById('updateStatusModal').classList.remove('active')">&times;</span>
            <h3>Update User Status</h3>
        </div>
        <div class="modal-body">
            <form method="POST" action="" id="statusForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="user_id" id="status_user_id">
                
                <p>Change status for: <strong id="status_user_name"></strong></p>
                
                <div class="form-group">
                    <label>New Status *</label>
                    <div id="account_status_section">
                        <label>Account Status</label>
                        <select name="new_status" class="form-control" id="status_select">
                            <option value="active">✅ Active</option>
                            <option value="suspended">🔒 Suspended</option>
                            <option value="banned">🚫 Banned</option>
                        </select>
                    </div>
                    <div id="rider_availability_section" style="margin-top:15px;display:none;">
                        <label>Rider Availability</label>
                        <select name="new_availability" class="form-control" id="availability_select">
                            <option value="standby">🟢 Standby (Available)</option>
                            <option value="out_for_delivery">🚚 Out for Delivery</option>
                            <option value="on_leave">🏖️ On Leave</option>
                            <option value="off_duty">🔴 Off Duty</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="document.getElementById('updateStatusModal').classList.remove('active')" class="btn btn-cancel">
                Cancel
            </button>
            <button type="submit" form="statusForm" name="update_status" class="btn btn-primary">
                Update Status
            </button>
        </div>
    </div>
</div>

<script>
function updateStatus(userId, userName, currentStatus) {
    document.getElementById('status_user_id').value = userId;
    document.getElementById('status_user_name').textContent = userName;
    document.getElementById('status_select').value = currentStatus == 'active' ? 'suspended' : 'active';
    document.getElementById('updateStatusModal').classList.add('active');
}

function togglePassword(id) {
    const input = document.getElementById(id);
    const icon  = document.getElementById(id + '-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.textContent = '👁️‍🗨️';
    } else {
        input.type = 'password';
        icon.textContent = '👁️';
    }
}

function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    const img     = preview.querySelector('.image-preview');
    if (!input.files || !input.files[0]) return;

    if (input.files[0].type === 'application/pdf') {
        img.style.display = 'none';
        let pdfLabel = preview.querySelector('.pdf-name');
        if (!pdfLabel) {
            pdfLabel = document.createElement('p');
            pdfLabel.className = 'pdf-name';
            pdfLabel.style.cssText = 'text-align:center;color:#667eea;font-weight:600;padding:10px 0;';
            preview.appendChild(pdfLabel);
        }
        pdfLabel.textContent = '📄 ' + input.files[0].name;
        preview.classList.add('active');
        return;
    }

    img.style.display = '';
    const reader = new FileReader();
    reader.onload = e => { img.src = e.target.result; preview.classList.add('active'); };
    reader.readAsDataURL(input.files[0]);
}

function removePreview(inputId, previewId) {
    document.getElementById(inputId).value = '';
    const p = document.getElementById(previewId);
    p.classList.remove('active');
    const pdfLabel = p.querySelector('.pdf-name');
    if (pdfLabel) pdfLabel.remove();
    const img = p.querySelector('.image-preview');
    if (img) { img.src = ''; img.style.display = ''; }
}
</script>

<style>
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; }
.modal.active { display: flex; }
.modal-content { background: white; border-radius: 20px; padding: 0; width: 90%; max-height: 90vh; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.modal-header { padding: 25px 30px; border-bottom: 1px solid #e8ecf1; background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%); }
.modal-header h3 { font-size: 22px; margin: 0; color: #2d3748; font-weight: 700; }
.btn-close { float: right; font-size: 28px; cursor: pointer; color: #a0aec0; line-height: 1; transition: all 0.2s; }
.btn-close:hover { color: #667eea; transform: rotate(90deg); }
.modal-body { padding: 30px; overflow-y: auto; max-height: calc(90vh - 180px); }
.modal-footer { padding: 20px 30px; border-top: 1px solid #e8ecf1; background: #f7fafc; display: flex; justify-content: flex-end; gap: 12px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.form-group-full { grid-column: 1 / -1; }
.form-group { margin-bottom: 0; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: #4a5568; }
.form-control { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 15px; transition: all 0.3s; box-sizing: border-box; }
.form-control:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
.btn { padding: 12px 24px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; }
.btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4); }
.btn-cancel { background: #e2e8f0; color: #4a5568; }
.btn-cancel:hover { background: #cbd5e0; }
.btn-sm { padding: 8px 16px; font-size: 13px; }
.btn-warning { background: #ff9800; color: white; }
.btn-warning:hover { background: #f57c00; }
.alert { padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 500; }
.alert-success { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); color: #2e7d32; border: 1px solid #a5d6a7; }
.alert-error { background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%); color: #c62828; border: 1px solid #ef9a9a; }
</style>

<?php include 'includes/footer.php'; ?>