-- ============================================
-- PIERRE GASLY - SUPABASE MIGRATION
-- Main SQL Structure (cleaned seed data)
-- Keeps the current table structure and process intact
-- Date: March 2026
-- ============================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS users (
    user_id SERIAL PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    role VARCHAR(20) NOT NULL DEFAULT 'customer' CHECK (role IN ('master_admin', 'sub_admin', 'rider', 'customer')),
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'banned')),
    profile_photo VARCHAR(255),
    valid_id VARCHAR(255),
    birthday DATE,
    passkey VARCHAR(50),
    first_login BOOLEAN DEFAULT true,
    email_verified BOOLEAN DEFAULT false,
    last_login TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT true
);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);

CREATE TABLE IF NOT EXISTS categories (
    category_id SERIAL PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT true
);

CREATE TABLE IF NOT EXISTS brands (
    brand_id SERIAL PRIMARY KEY,
    brand_name VARCHAR(100) UNIQUE NOT NULL,
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT true
);

CREATE TABLE IF NOT EXISTS product_sizes (
    size_id SERIAL PRIMARY KEY,
    size_kg INTEGER UNIQUE NOT NULL,
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT true
);

CREATE TABLE IF NOT EXISTS products (
    product_id SERIAL PRIMARY KEY,
    category_id INTEGER NOT NULL REFERENCES categories(category_id) ON DELETE CASCADE,
    brand_id INTEGER NOT NULL REFERENCES brands(brand_id) ON DELETE CASCADE,
    product_name VARCHAR(200) NOT NULL,
    size_kg INTEGER NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INTEGER NOT NULL DEFAULT 0,
    minimum_stock INTEGER NOT NULL DEFAULT 10,
    description TEXT,
    product_image VARCHAR(255),
    availability VARCHAR(20) DEFAULT 'available' CHECK (availability IN ('available', 'out_of_stock')),
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT true
);
CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id);
CREATE INDEX IF NOT EXISTS idx_products_brand ON products(brand_id);
CREATE INDEX IF NOT EXISTS idx_products_stock ON products(stock_quantity, minimum_stock);
CREATE INDEX IF NOT EXISTS idx_products_brand_size ON products(brand_id, size_kg);

CREATE TABLE IF NOT EXISTS orders (
    order_id SERIAL PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    customer_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(product_id) ON DELETE CASCADE,
    rider_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    total_amount DECIMAL(10,2) NOT NULL,
    delivery_address TEXT NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' CHECK (payment_method IN ('cash', 'gcash', 'paymaya', 'card')),
    order_status VARCHAR(30) NOT NULL DEFAULT 'pending' CHECK (order_status IN ('pending', 'preparing', 'out_for_delivery', 'delivered', 'cancelled')),
    ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    prepared_at TIMESTAMP,
    out_for_delivery_at TIMESTAMP,
    delivered_at TIMESTAMP,
    cancelled_at TIMESTAMP,
    updated_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_orders_customer ON orders(customer_id);
CREATE INDEX IF NOT EXISTS idx_orders_product ON orders(product_id);
CREATE INDEX IF NOT EXISTS idx_orders_rider ON orders(rider_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(order_status);
CREATE INDEX IF NOT EXISTS idx_orders_customer_status ON orders(customer_id, order_status);
CREATE INDEX IF NOT EXISTS idx_orders_rider_status ON orders(rider_id, order_status);

CREATE TABLE IF NOT EXISTS sales (
    sale_id SERIAL PRIMARY KEY,
    order_id INTEGER NOT NULL REFERENCES orders(order_id) ON DELETE CASCADE,
    rider_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    sale_amount DECIMAL(10,2) NOT NULL,
    sale_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sales_order ON sales(order_id);
CREATE INDEX IF NOT EXISTS idx_sales_rider_date ON sales(rider_id, sale_date);

CREATE TABLE IF NOT EXISTS reviews (
    review_id SERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    order_id INTEGER NOT NULL REFERENCES orders(order_id) ON DELETE CASCADE,
    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_reviews_customer ON reviews(customer_id);
CREATE INDEX IF NOT EXISTS idx_reviews_order ON reviews(order_id);

CREATE TABLE IF NOT EXISTS review_responses (
    response_id SERIAL PRIMARY KEY,
    review_id INTEGER NOT NULL REFERENCES reviews(review_id) ON DELETE CASCADE,
    admin_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    response_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_logs (
    log_id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(product_id) ON DELETE CASCADE,
    change_type VARCHAR(20) NOT NULL CHECK (change_type IN ('add', 'subtract', 'adjust')),
    quantity_change INTEGER NOT NULL,
    old_quantity INTEGER NOT NULL,
    new_quantity INTEGER NOT NULL,
    reason TEXT,
    created_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_logs (
    log_id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_activity_user ON activity_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_action ON activity_logs(action);
CREATE INDEX IF NOT EXISTS idx_activity_ip ON activity_logs(ip_address);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_rewards (
    reward_id SERIAL PRIMARY KEY,
    user_id INTEGER UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    total_points INTEGER NOT NULL DEFAULT 0,
    redeemed_points INTEGER NOT NULL DEFAULT 0,
    tier VARCHAR(20) NOT NULL DEFAULT 'Bronze' CHECK (tier IN ('Bronze', 'Silver', 'Gold', 'Platinum')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reward_transactions (
    tx_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    order_id INTEGER REFERENCES orders(order_id) ON DELETE SET NULL,
    points INTEGER NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'earned' CHECK (type IN ('earned', 'redeemed')),
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rewards_settings (
    setting_id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rider_availability (
    availability_id SERIAL PRIMARY KEY,
    rider_id INTEGER UNIQUE NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    is_available BOOLEAN DEFAULT true,
    current_latitude DECIMAL(10, 8),
    current_longitude DECIMAL(11, 8),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (user_id, email, password_hash, full_name, phone, role, status, birthday, email_verified, created_at)
VALUES (1, 'PierreGasly2025@gmail.com', '$2a$12$uywJjsIyulemoRKK9.RGQeCfaV72NaMzZzCpung.TGmj8rXSHyl6e', 'Master Administrator', '09171234567', 'master_admin', 'active', '2000-01-24', true, NOW())
ON CONFLICT (email) DO NOTHING;

INSERT INTO categories (category_id, category_name, description, created_by, created_at) VALUES
(1, 'LPG Gas Tank', 'Liquefied Petroleum Gas tanks for household and commercial use', 1, NOW()),
(2, 'Gas Refill', 'LPG gas refill service', 1, NOW())
ON CONFLICT DO NOTHING;

INSERT INTO brands (brand_id, brand_name, created_by, created_at, is_active) VALUES
(1, 'PETRON', 1, NOW(), true),
(2, 'PRYCE GASES', 1, NOW(), true),
(3, 'REGASCO', 1, NOW(), true)
ON CONFLICT (brand_name) DO UPDATE SET is_active = EXCLUDED.is_active;

INSERT INTO product_sizes (size_id, size_kg, created_by, created_at, is_active) VALUES
(1, 11, 1, NOW(), true),
(2, 22, 1, NOW(), true)
ON CONFLICT (size_kg) DO UPDATE SET is_active = EXCLUDED.is_active;

INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at) VALUES
('system_name', 'Pierre Gasly Gas Delivery', 1, NOW()),
('delivery_fee', '10.00', 1, NOW()),
('contact_email', 'support@pierregasly.com', 1, NOW()),
('contact_phone', '09171234567', 1, NOW()),
('business_hours', '8:00 AM - 6:00 PM', 1, NOW()),
('max_login_attempts', '5', 1, NOW()),
('session_timeout', '3600', 1, NOW())
ON CONFLICT (setting_key) DO NOTHING;

INSERT INTO rewards_settings (setting_key, setting_value) VALUES
('bronze_points_rate', '100'),
('silver_points_rate', '120'),
('gold_points_rate', '150'),
('platinum_points_rate', '200'),
('silver_threshold', '5'),
('gold_threshold', '15'),
('platinum_threshold', '30'),
('redemption_rate', '500'),
('redemption_value', '50'),
('points_enabled', '1')
ON CONFLICT (setting_key) DO NOTHING;

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

DROP TRIGGER IF EXISTS update_users_updated_at ON users;
DROP TRIGGER IF EXISTS update_categories_updated_at ON categories;
DROP TRIGGER IF EXISTS update_brands_updated_at ON brands;
DROP TRIGGER IF EXISTS update_product_sizes_updated_at ON product_sizes;
DROP TRIGGER IF EXISTS update_products_updated_at ON products;
DROP TRIGGER IF EXISTS update_orders_updated_at ON orders;
DROP TRIGGER IF EXISTS update_reviews_updated_at ON reviews;
DROP TRIGGER IF EXISTS update_review_responses_updated_at ON review_responses;
DROP TRIGGER IF EXISTS update_system_settings_updated_at ON system_settings;
DROP TRIGGER IF EXISTS update_user_rewards_updated_at ON user_rewards;
DROP TRIGGER IF EXISTS update_rewards_settings_updated_at ON rewards_settings;
DROP TRIGGER IF EXISTS update_rider_availability_updated_at ON rider_availability;

CREATE TRIGGER update_users_updated_at BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_categories_updated_at BEFORE UPDATE ON categories FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_brands_updated_at BEFORE UPDATE ON brands FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_product_sizes_updated_at BEFORE UPDATE ON product_sizes FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_products_updated_at BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_orders_updated_at BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_reviews_updated_at BEFORE UPDATE ON reviews FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_review_responses_updated_at BEFORE UPDATE ON review_responses FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_system_settings_updated_at BEFORE UPDATE ON system_settings FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_user_rewards_updated_at BEFORE UPDATE ON user_rewards FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_rewards_settings_updated_at BEFORE UPDATE ON rewards_settings FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_rider_availability_updated_at BEFORE UPDATE ON rider_availability FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE brands ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_sizes ENABLE ROW LEVEL SECURITY;
ALTER TABLE products ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE sales ENABLE ROW LEVEL SECURITY;
ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE review_responses ENABLE ROW LEVEL SECURITY;
ALTER TABLE inventory_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE activity_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE system_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_rewards ENABLE ROW LEVEL SECURITY;
ALTER TABLE reward_transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE rewards_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE rider_availability ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Public read access to products" ON products;
DROP POLICY IF EXISTS "Public read access to categories" ON categories;
DROP POLICY IF EXISTS "Public read access to brands" ON brands;
DROP POLICY IF EXISTS "Public read access to product_sizes" ON product_sizes;
DROP POLICY IF EXISTS "Public read access to reviews" ON reviews;
DROP POLICY IF EXISTS "Users can read own data" ON users;
DROP POLICY IF EXISTS "Users can update own data" ON users;
DROP POLICY IF EXISTS "Customers read own orders" ON orders;
DROP POLICY IF EXISTS "Customers create orders" ON orders;
DROP POLICY IF EXISTS "Users read own rewards" ON user_rewards;
DROP POLICY IF EXISTS "Users read own reward transactions" ON reward_transactions;

CREATE POLICY "Public read access to products" ON products FOR SELECT USING (true);
CREATE POLICY "Public read access to categories" ON categories FOR SELECT USING (true);
CREATE POLICY "Public read access to brands" ON brands FOR SELECT USING (true);
CREATE POLICY "Public read access to product_sizes" ON product_sizes FOR SELECT USING (true);
CREATE POLICY "Public read access to reviews" ON reviews FOR SELECT USING (true);
CREATE POLICY "Users can read own data" ON users FOR SELECT USING (auth.uid()::text = user_id::text OR role IN ('master_admin', 'sub_admin'));
CREATE POLICY "Users can update own data" ON users FOR UPDATE USING (auth.uid()::text = user_id::text);
CREATE POLICY "Customers read own orders" ON orders FOR SELECT USING (customer_id::text = auth.uid()::text OR EXISTS (SELECT 1 FROM users WHERE user_id::text = auth.uid()::text AND role IN ('master_admin', 'sub_admin', 'rider')));
CREATE POLICY "Customers create orders" ON orders FOR INSERT WITH CHECK (customer_id::text = auth.uid()::text);
CREATE POLICY "Users read own rewards" ON user_rewards FOR SELECT USING (user_id::text = auth.uid()::text);
CREATE POLICY "Users read own reward transactions" ON reward_transactions FOR SELECT USING (user_id::text = auth.uid()::text);
