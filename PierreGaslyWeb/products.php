<?php
/**
 * PIERRE GASLY - Products Management (PREMIUM UI/UX)
 * Modern, clean, and professional design
 */

require_once 'includes/config.php';
requireAdmin();

$pageTitle = 'Products Management';
$db = Database::getInstance();

$success = '';
$error = '';

// Get categories
$categories = $db->fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY category_name");
$brands = $db->fetchAll("SELECT * FROM brands WHERE is_active = 1 ORDER BY brand_name");
$sizes = $db->fetchAll("SELECT * FROM product_sizes WHERE is_active = 1 ORDER BY size_kg");

// Handle Add Brand
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_brand'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $brand_name = strtoupper(sanitize($_POST['brand_name']));
        $existing = $db->fetchOne("SELECT brand_id FROM brands WHERE brand_name = ?", [$brand_name]);
        
        if ($existing) {
            $error = 'Brand already exists';
        } else {
            $sql = "INSERT INTO brands (brand_name, created_by) VALUES (?, ?)";
            if ($db->query($sql, [$brand_name, $_SESSION['user_id']])) {
                $success = "Brand '$brand_name' added successfully!";
                logActivity('create', 'brand', $db->lastInsertId(), "Added brand: $brand_name");
                $brands = $db->fetchAll("SELECT * FROM brands WHERE is_active = 1 ORDER BY brand_name");
            } else {
                $error = 'Failed to add brand. ' . ($db->getLastError() ?: '');
            }
        }
    }
}

// Handle Add Size
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_size'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $size_kg = (int)$_POST['size_kg'];
        
        if ($size_kg <= 0) {
            $error = 'Please enter a valid size';
        } else {
            $existing = $db->fetchOne("SELECT size_id FROM product_sizes WHERE size_kg = ?", [$size_kg]);
            
            if ($existing) {
                $error = 'Size already exists';
            } else {
                $sql = "INSERT INTO product_sizes (size_kg, created_by) VALUES (?, ?)";
                if ($db->query($sql, [$size_kg, $_SESSION['user_id']])) {
                    $success = "Size {$size_kg}kg added successfully!";
                    logActivity('create', 'size', $db->lastInsertId(), "Added size: {$size_kg}kg");
                    $sizes = $db->fetchAll("SELECT * FROM product_sizes WHERE is_active = 1 ORDER BY size_kg");
                } else {
                    $error = 'Failed to add size. ' . ($db->getLastError() ?: '');
                }
            }
        }
    }
}

// Handle Add Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $brand_id = (int)$_POST['brand_id'];
        $product_name = sanitize($_POST['product_name']);
        $category_id = (int)$_POST['category_id'];
        $size_kg = (int)$_POST['size_kg'];
        $price = (float)$_POST['price'];
        $stock_quantity = (int)$_POST['stock_quantity'];
        $minimum_stock = (int)$_POST['minimum_stock'];
        $description = sanitize($_POST['description']);
        $availability = $_POST['availability'];
        
        $product_image = null;
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['product_image'], 'products');
            if ($upload['success']) {
                $product_image = $upload['filename'];
            } else {
                $error = $upload['message'];
            }
        }
        
        if (empty($error)) {
            // Auto-set availability based on stock quantity
            if ((int)$stock_quantity <= 0) {
                $availability = 'out_of_stock';
            } elseif (empty($availability)) {
                $availability = 'available';
            }

            $sql = "INSERT INTO products (category_id, brand_id, product_name, size_kg, price, stock_quantity, minimum_stock, description, product_image, availability, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            if ($db->query($sql, [$category_id, $brand_id, $product_name, $size_kg, $price, $stock_quantity, $minimum_stock, $description, $product_image, $availability, 1, $_SESSION['user_id']])) {
                $success = 'Product added successfully!';
                logActivity('create', 'product', $db->lastInsertId(), "Added product: $product_name");
            } else {
                $error = 'Failed to add product. ' . ($db->getLastError() ?: '');
            }
        }
    }
}

// Handle Edit Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token';
    } else {
        $product_id = (int)$_POST['product_id'];
        $brand_id = (int)$_POST['brand_id'];
        $product_name = sanitize($_POST['product_name']);
        $category_id = (int)$_POST['category_id'];
        $size_kg = (int)$_POST['size_kg'];
        $price = (float)$_POST['price'];
        $stock_quantity = (int)$_POST['stock_quantity'];
        $minimum_stock = (int)$_POST['minimum_stock'];
        $description = sanitize($_POST['description']);
        $availability = $_POST['availability'];
        
        $product_image = $_POST['existing_image'];
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['product_image'], 'products');
            if ($upload['success']) {
                $product_image = $upload['filename'];
                if (!empty($_POST['existing_image']) && file_exists(UPLOADS_PATH . 'products/' . $_POST['existing_image'])) {
                    unlink(UPLOADS_PATH . 'products/' . $_POST['existing_image']);
                }
            }
        }
        // Auto-set availability based on stock quantity
        if ((int)$stock_quantity <= 0) {
            $availability = 'out_of_stock';
        } elseif (empty($availability)) {
            $availability = 'available';
        }

        $sql = "UPDATE products SET category_id = ?, brand_id = ?, product_name = ?, size_kg = ?, price = ?, stock_quantity = ?, minimum_stock = ?, description = ?, product_image = ?, availability = ? WHERE product_id = ?";
        
        if ($db->query($sql, [$category_id, $brand_id, $product_name, $size_kg, $price, $stock_quantity, $minimum_stock, $description, $product_image, $availability, $product_id])) {
            $success = 'Product updated successfully!';
            logActivity('update', 'product', $product_id, "Updated product: $product_name");
        } else {
            $error = 'Failed to update product';
        }
    }
}

// Handle Archive Product
if (isset($_GET['archive'])) {
    $product_id = (int)$_GET['archive'];
    $product = $db->fetchOne("SELECT * FROM products WHERE product_id = ?", [$product_id]);
    
    if ($product) {
        if ($db->query("UPDATE products SET is_active = ? WHERE product_id = ?", [0, $product_id])) {
            logActivity('archive', 'product', $product_id, "Archived product: " . $product['product_name']);
            header('Location: products.php?view=active&success=archived');
            exit;
        } else {
            $error = 'Failed to archive product. ' . ($db->getLastError() ?: '');
        }
    }
}

// Handle Restore Product (from archive)
if (isset($_GET['restore'])) {
    $product_id = (int)$_GET['restore'];
    $product = $db->fetchOne("SELECT * FROM products WHERE product_id = ?", [$product_id]);
    
    if ($product) {
        if ($db->query("UPDATE products SET is_active = ? WHERE product_id = ?", [1, $product_id])) {
            logActivity('restore', 'product', $product_id, "Restored product: " . $product['product_name']);
            header('Location: products.php?view=active&success=restored');
            exit;
        } else {
            $error = 'Failed to restore product. ' . ($db->getLastError() ?: '');
        }
    }
}

// Show success messages from redirects
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'archived') {
        $success = 'Product archived successfully!';
    } elseif ($_GET['success'] === 'restored') {
        $success = 'Product restored successfully!';
    }
}

// Get view mode (active or archived)
$view_mode = $_GET['view'] ?? 'active';

// Get all products based on view mode
$is_active = $view_mode === 'active' ? 1 : 0;
$sql = "SELECT p.*, c.category_name, b.brand_name
        FROM products p
        JOIN categories c ON p.category_id = c.category_id
        JOIN brands b ON p.brand_id = b.brand_id
        WHERE p.is_active = ?
        ORDER BY b.brand_name, p.size_kg, p.product_name";
$products = $db->fetchAll($sql, [$is_active]);

// Get counts
$active_count = $db->fetchOne("SELECT COUNT(*) as count FROM products WHERE is_active = 1")['count'];
$archived_count = $db->fetchOne("SELECT COUNT(*) as count FROM products WHERE is_active = 0")['count'];

$editProduct = null;
if (isset($_GET['edit'])) {
    $editProduct = $db->fetchOne("SELECT * FROM products WHERE product_id = ?", [(int)$_GET['edit']]);
}

$csrfToken = generateCSRFToken();
include 'includes/header.php';
?>

<style>
/* Premium Modern Styling */
.page-header { margin-bottom: 30px; }

/* Quick Actions Section - IMPROVED */
.quick-actions-section {
    background: linear-gradient(135deg, #f5f7fa 0%, #ffffff 100%);
    padding: 25px 30px;
    border-radius: 16px;
    margin-bottom: 30px;
    border: 1px solid #e8ecf1;
}

.actions-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.actions-header h3 {
    font-size: 18px;
    font-weight: 700;
    color: #2d3748;
    margin: 0;
}

.action-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* Primary Button - Prominent */
.btn-primary-large {
    padding: 12px 24px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.btn-primary-large:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

/* Secondary Buttons - Outline Style */
.btn-outline {
    padding: 10px 20px;
    background: white;
    color: #667eea;
    border: 2px solid #667eea;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-outline:hover {
    background: #667eea;
    color: white;
}

/* Pill Badges for Brands/Sizes */
.data-pills {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e8ecf1;
}

.pill-label {
    font-size: 13px;
    font-weight: 600;
    color: #718096;
    margin-bottom: 8px;
    display: block;
}

.pills-container {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.pill {
    display: inline-flex;
    align-items: center;
    padding: 6px 14px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    color: #4a5568;
    transition: all 0.2s;
}

.pill:hover {
    border-color: #667eea;
    background: #f7fafc;
    transform: translateY(-1px);
}

.pill-brand {
    background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
    border-color: #667eea;
    color: #667eea;
}

.pill-size {
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    border-color: #f57c00;
    color: #e65100;
}

/* Product Cards */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
    margin-top: 20px;
}

.product-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: all 0.3s;
    border: 2px solid transparent;
}

.product-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.12);
    border-color: #667eea;
}

.product-brand {
    font-size: 22px;
    font-weight: 700;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 8px;
}

.product-name {
    font-size: 15px;
    color: #4a5568;
    margin-bottom: 12px;
    line-height: 1.5;
}

.product-size {
    display: inline-block;
    background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
    color: #e65100;
    padding: 8px 16px;
    border-radius: 24px;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 14px;
}

.product-price {
    font-size: 28px;
    font-weight: 800;
    background: linear-gradient(135deg, #2e7d32 0%, #66bb6a 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 14px;
}

.product-stock {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-top: 1px solid #f0f0f0;
    margin-top: 12px;
}

.stock-label {
    font-size: 13px;
    color: #718096;
    font-weight: 500;
}

.product-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

/* Modal Improvements */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.modal.active { display: flex; }

.modal-content {
    background: white;
    border-radius: 20px;
    padding: 0;
    max-width: 700px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
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

.btn-close {
    float: right;
    font-size: 28px;
    cursor: pointer;
    color: #a0aec0;
    line-height: 1;
    transition: all 0.2s;
}

.btn-close:hover {
    color: #667eea;
    transform: rotate(90deg);
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

/* Form Styling - 2 Column Grid */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group-full {
    grid-column: 1 / -1;
}

.form-group {
    margin-bottom: 0;
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

/* Input Groups with Prefix/Suffix */
.input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.input-prefix {
    position: absolute;
    left: 16px;
    font-size: 15px;
    font-weight: 600;
    color: #718096;
    pointer-events: none;
}

.input-suffix {
    position: absolute;
    right: 16px;
    font-size: 13px;
    font-weight: 600;
    color: #718096;
    background: #f7fafc;
    padding: 4px 8px;
    border-radius: 6px;
    pointer-events: none;
}

.form-control.has-prefix {
    padding-left: 36px;
}

.form-control.has-suffix {
    padding-right: 50px;
}

/* Image Upload - Premium Style */
.image-upload-wrapper {
    position: relative;
}

.image-upload-box {
    border: 3px dashed #cbd5e0;
    border-radius: 12px;
    padding: 40px 30px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background: #f7fafc;
    display: block;
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
    font-size: 48px;
    margin-bottom: 12px;
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
    color: #a0aec0;
    margin-top: 6px;
}

.image-upload-box input[type="file"] {
    display: none;
}

.image-preview {
    margin-top: 15px;
    text-align: center;
    display: none;
}

.image-preview.show {
    display: block;
}

.image-preview img {
    max-width: 100%;
    max-height: 250px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.image-preview-name {
    margin-top: 10px;
    font-size: 13px;
    color: #666;
    font-weight: 500;
}

/* Buttons */
.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
}

.btn-cancel {
    background: #e2e8f0;
    color: #4a5568;
}

.btn-cancel:hover {
    background: #cbd5e0;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 13px;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

/* Empty State */
.empty-products {
    text-align: center;
    padding: 80px 40px;
    background: white;
    border-radius: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.empty-products-icon {
    font-size: 80px;
    margin-bottom: 24px;
    opacity: 0.7;
}

.empty-products h3 {
    font-size: 24px;
    margin-bottom: 12px;
    color: #2d3748;
    font-weight: 700;
}

.empty-products p {
    color: #718096;
    margin-bottom: 30px;
    font-size: 15px;
}

/* Alert Messages */
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

<div class="page-header">
    <h1>📦 Products Management</h1>
    <p>Manage your gas tank products inventory</p>
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

<!-- Quick Actions Section - IMPROVED -->
<div class="quick-actions-section">
    <div class="actions-header">
        <h3>⚡ Quick Actions</h3>
        <div style="display: flex; gap: 10px;">
            <a href="?view=active" class="filter-btn <?php echo $view_mode == 'active' ? 'active' : ''; ?>">
                📦 Active (<?php echo $active_count; ?>)
            </a>
            <a href="?view=archived" class="filter-btn <?php echo $view_mode == 'archived' ? 'active' : ''; ?>">
                📁 Archived (<?php echo $archived_count; ?>)
            </a>
        </div>
    </div>
    
    <div class="action-buttons">
        <!-- PRIMARY: Add Product Button -->
        <button onclick="openProductModal()" class="btn-primary-large">
            ➕ Add New Product
        </button>
        
        <!-- SECONDARY: Add Brand Button (Outline) -->
        <button onclick="document.getElementById('brandModal').classList.add('active')" class="btn-outline">
            🏷️ Add Brand
        </button>
        
        <!-- SECONDARY: Add Size Button (Outline) -->
        <button onclick="document.getElementById('sizeModal').classList.add('active')" class="btn-outline">
            📏 Add Size
        </button>
    </div>
    
    <!-- Data Pills Section -->
    <div class="data-pills">
        <div style="margin-bottom: 12px;">
            <span class="pill-label">Available Brands:</span>
            <div class="pills-container">
                <?php foreach ($brands as $brand): ?>
                    <span class="pill pill-brand"><?php echo htmlspecialchars($brand['brand_name']); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div>
            <span class="pill-label">Available Sizes:</span>
            <div class="pills-container">
                <?php foreach ($sizes as $size): ?>
                    <span class="pill pill-size"><?php echo $size['size_kg']; ?> kg</span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Products Display -->
<?php if (empty($products)): ?>
    <div class="empty-products">
        <div class="empty-products-icon">📦</div>
        <h3>No Products Yet</h3>
        <p>Start by adding your first gas tank product</p>
        <button onclick="openProductModal()" class="btn-primary-large">
            ➕ Add First Product
        </button>
    </div>
<?php else: ?>
    <div class="dashboard-card">
        <div class="card-header">
            <h2>📋 All Products (<?php echo count($products); ?>)</h2>
        </div>
        <div class="card-body">
            <div class="products-grid">
                <?php foreach ($products as $product): ?>
                <div class="product-card">
                    <div class="product-brand"><?php echo htmlspecialchars($product['brand_name']); ?></div>
                    <div class="product-name"><?php echo htmlspecialchars($product['product_name']); ?></div>
                    <div class="product-size">🏋️ <?php echo $product['size_kg']; ?> kg</div>
                    <div class="product-price"><?php echo formatCurrency($product['price']); ?></div>
                    
                    <div class="product-stock">
                        <div class="stock-label">Stock:</div>
                        <div>
                            <?php
                            $stockColor = $product['stock_quantity'] <= $product['minimum_stock'] ? 'warning' : 'success';
                            ?>
                            <span class="badge badge-<?php echo $stockColor; ?>">
                                <?php echo $product['stock_quantity']; ?> units
                            </span>
                        </div>
                    </div>
                    
                    <div class="product-stock">
                        <div class="stock-label">Status:</div>
                        <span class="badge badge-<?php echo $product['availability'] == 'available' ? 'success' : 'danger'; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $product['availability'])); ?>
                        </span>
                    </div>
                    
                    <div class="product-actions">
                        <a href="?edit=<?php echo $product['product_id']; ?>&view=<?php echo $view_mode; ?>" class="btn-sm btn-primary" style="flex: 1; text-align: center; text-decoration: none;">
                            ✏️ Edit
                        </a>
                        <?php if ($view_mode == 'active'): ?>
                            <a href="#" 
                               class="btn-sm btn-warning" 
                               style="flex: 1; text-align: center; text-decoration: none; background: #ff9800; color: white;"
                               onclick="event.preventDefault(); showConfirmPopup('Archive Product', 'This product will be moved to archived view. You can restore it later.', function() { window.location.href='?archive=<?php echo $product['product_id']; ?>&view=<?php echo $view_mode; ?>&confirmed=1'; })">
                                📁 Archive
                            </a>
                        <?php else: ?>
                            <a href="#" 
                               class="btn-sm btn-success" 
                               style="flex: 1; text-align: center; text-decoration: none; background: #4caf50; color: white;"
                               onclick="event.preventDefault(); showConfirmPopup('Restore Product', 'This product will be moved back to active products.', function() { window.location.href='?restore=<?php echo $product['product_id']; ?>&view=<?php echo $view_mode; ?>'; })">
                                ♻️ Restore
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Add Brand Modal - Simple -->
<div id="brandModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <span class="btn-close" onclick="document.getElementById('brandModal').classList.remove('active')">&times;</span>
            <h3>🏷️ Add New Brand</h3>
        </div>
        <div class="modal-body">
            <form method="POST" action="" id="brandForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label>Brand Name *</label>
                    <input type="text" name="brand_name" required class="form-control" 
                           placeholder="e.g., PETRON, SOLANE, TOTAL" style="text-transform: uppercase;">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="document.getElementById('brandModal').classList.remove('active')" class="btn btn-cancel">
                Cancel
            </button>
            <button type="submit" form="brandForm" name="add_brand" class="btn btn-primary">
                Add Brand
            </button>
        </div>
    </div>
</div>

<!-- Add Size Modal - Simple -->
<div id="sizeModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <span class="btn-close" onclick="document.getElementById('sizeModal').classList.remove('active')">&times;</span>
            <h3>📏 Add New Size</h3>
        </div>
        <div class="modal-body">
            <form method="POST" action="" id="sizeForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <div class="form-group">
                    <label>Size (kg) *</label>
                    <div class="input-group">
                        <input type="number" name="size_kg" required class="form-control has-suffix" 
                               placeholder="e.g., 11, 22, 50" min="1">
                        <span class="input-suffix">kg</span>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="document.getElementById('sizeModal').classList.remove('active')" class="btn btn-cancel">
                Cancel
            </button>
            <button type="submit" form="sizeForm" name="add_size" class="btn btn-primary">
                Add Size
            </button>
        </div>
    </div>
</div>

<!-- Add/Edit Product Modal - PREMIUM 2-COLUMN GRID -->
<div id="productModal" class="modal <?php echo $editProduct ? 'active' : ''; ?>">
    <div class="modal-content">
        <div class="modal-header">
            <span class="btn-close" onclick="closeProductModal()">&times;</span>
            <h3><?php echo $editProduct ? '✏️ Edit Product' : '➕ Add New Product'; ?></h3>
        </div>
        <div class="modal-body">
            <form method="POST" action="" enctype="multipart/form-data" id="productForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <?php if ($editProduct): ?>
                    <input type="hidden" name="product_id" value="<?php echo $editProduct['product_id']; ?>">
                    <input type="hidden" name="existing_image" value="<?php echo $editProduct['product_image']; ?>">
                <?php endif; ?>
                
                <!-- 2-COLUMN GRID -->
                <div class="form-grid">
                    <!-- Row 1: Category & Brand -->
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category_id" required class="form-control">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>" 
                                    <?php echo ($editProduct && $editProduct['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Brand *</label>
                        <select name="brand_id" required class="form-control">
                            <option value="">Select Brand</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?php echo $brand['brand_id']; ?>"
                                    <?php echo ($editProduct && $editProduct['brand_id'] == $brand['brand_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($brand['brand_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Row 2: Product Name (Full Width) -->
                    <div class="form-group form-group-full">
                        <label>Product Name *</label>
                        <input type="text" name="product_name" required class="form-control" 
                               value="<?php echo $editProduct ? htmlspecialchars($editProduct['product_name']) : ''; ?>"
                               placeholder="e.g., LPG Gas Tank">
                    </div>
                    
                    <!-- Row 3: Price & Size with Input Groups -->
                    <div class="form-group">
                        <label>Price *</label>
                        <div class="input-group">
                            <span class="input-prefix">₱</span>
                            <input type="number" name="price" step="0.01" required class="form-control has-prefix" 
                                   value="<?php echo $editProduct ? $editProduct['price'] : ''; ?>"
                                   placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Size *</label>
                        <div class="input-group">
                            <select name="size_kg" required class="form-control has-suffix">
                                <option value="">Select Size</option>
                                <?php foreach ($sizes as $size): ?>
                                    <option value="<?php echo $size['size_kg']; ?>"
                                        <?php echo ($editProduct && $editProduct['size_kg'] == $size['size_kg']) ? 'selected' : ''; ?>>
                                        <?php echo $size['size_kg']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="input-suffix">kg</span>
                        </div>
                    </div>
                    
                    <!-- Row 4: Stock Quantity & Minimum Stock -->
                    <div class="form-group">
                        <label>Stock Quantity *</label>
                        <input type="number" name="stock_quantity" required class="form-control" 
                               value="<?php echo $editProduct ? $editProduct['stock_quantity'] : ''; ?>"
                               placeholder="0">
                    </div>
                    
                    <div class="form-group">
                        <label>Minimum Stock *</label>
                        <input type="number" name="minimum_stock" required class="form-control" 
                               value="<?php echo $editProduct ? $editProduct['minimum_stock'] : '10'; ?>"
                               placeholder="10">
                    </div>
                    
                    <!-- Row 5: Description (Full Width) -->
                    <div class="form-group form-group-full">
                        <label>Description</label>
                        <textarea name="description" rows="3" class="form-control" 
                                  placeholder="Product description..."><?php echo $editProduct ? htmlspecialchars($editProduct['description']) : ''; ?></textarea>
                    </div>
                    
                    <!-- Row 6: Image Upload & Availability -->
                    <div class="form-group">
                        <label>Product Image</label>
                        <div class="image-upload-wrapper">
                            <label class="image-upload-box" for="productImage" id="uploadBox">
                                <div class="upload-icon">📷</div>
                                <div class="upload-text">Click or Drag & Drop</div>
                                <div class="upload-subtext">JPG, PNG (Max 5MB)</div>
                                <input type="file" id="productImage" name="product_image" accept="image/*">
                            </label>
                            <div id="imagePreview" class="image-preview"></div>
                        </div>
                        <?php if ($editProduct && $editProduct['product_image']): ?>
                            <small style="color: #666; display: block; margin-top: 8px;">Current: <?php echo $editProduct['product_image']; ?></small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Availability *</label>
                        <select name="availability" required class="form-control">
                            <option value="available" <?php echo ($editProduct && $editProduct['availability'] == 'available') ? 'selected' : ''; ?>>Available</option>
                            <option value="out_of_stock" <?php echo ($editProduct && $editProduct['availability'] == 'out_of_stock') ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" onclick="closeProductModal()" class="btn btn-cancel">
                Cancel
            </button>
            <button type="submit" form="productForm" name="<?php echo $editProduct ? 'edit_product' : 'add_product'; ?>" class="btn btn-primary">
                <?php echo $editProduct ? '💾 Update Product' : '➕ Add Product'; ?>
            </button>
        </div>
    </div>
</div>

<script>
function openProductModal() {
    document.getElementById('productModal').classList.add('active');
}

function closeProductModal() {
    document.getElementById('productModal').classList.remove('active');
    <?php if ($editProduct): ?>
    window.location.href = 'products.php?view=<?php echo $view_mode; ?>';
    <?php endif; ?>
}

// Enhanced Image Upload with Drag & Drop
const uploadBox = document.getElementById('uploadBox');
const fileInput = document.getElementById('productImage');
const imagePreview = document.getElementById('imagePreview');

// Prevent default drag behaviors
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    uploadBox.addEventListener(eventName, preventDefaults, false);
    document.body.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

// Highlight drop area when dragging
['dragenter', 'dragover'].forEach(eventName => {
    uploadBox.addEventListener(eventName, () => {
        uploadBox.classList.add('drag-over');
    }, false);
});

['dragleave', 'drop'].forEach(eventName => {
    uploadBox.addEventListener(eventName, () => {
        uploadBox.classList.remove('drag-over');
    }, false);
});

// Handle dropped files
uploadBox.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    
    if (files.length > 0) {
        fileInput.files = files;
        previewImage(files[0]);
    }
}

// Handle file input change
fileInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        previewImage(this.files[0]);
    }
});

function previewImage(file) {
    // Validate file type
    if (!file.type.startsWith('image/')) {
        alert('Please select an image file');
        return;
    }
    
    // Validate file size (5MB)
    if (file.size > 5242880) {
        alert('File size must be less than 5MB');
        return;
    }
    
    const reader = new FileReader();
    
    reader.onload = function(e) {
        imagePreview.innerHTML = `
            <img src="${e.target.result}" alt="Preview">
            <div class="image-preview-name">${file.name}</div>
        `;
        imagePreview.classList.add('show');
        
        // Update upload box text
        uploadBox.querySelector('.upload-text').textContent = 'Image Selected!';
        uploadBox.querySelector('.upload-icon').textContent = '✅';
        uploadBox.style.borderColor = '#4caf50';
        uploadBox.style.background = '#e8f5e9';
    };
    
    reader.readAsDataURL(file);
}
</script>

<?php include 'includes/footer.php'; ?>