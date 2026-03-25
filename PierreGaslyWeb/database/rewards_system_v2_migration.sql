-- ============================================
-- PIERRE GASLY REWARDS SYSTEM V2.0 MIGRATION
-- ============================================
-- This migration updates the rewards system from percentage-based to fixed discount tiers
-- with lifetime points tracking and new redemption rules

-- Step 1: Add lifetime_points column to customers table
ALTER TABLE customers 
ADD COLUMN lifetime_points INT DEFAULT 0 COMMENT 'Total points ever earned (never decreases)' 
AFTER total_points;

-- Step 2: Initialize lifetime_points with current total_points for existing customers
UPDATE customers 
SET lifetime_points = total_points 
WHERE lifetime_points = 0;

-- Step 3: Drop old rewards settings table if it exists
DROP TABLE IF EXISTS rewards_settings;

-- Step 4: Create new rewards_settings table with updated structure
CREATE TABLE rewards_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES admins(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 5: Insert new rewards settings
INSERT INTO rewards_settings (setting_key, setting_value, description) VALUES
-- Points earning (same as before)
('purchase_11kg_points', '40', 'Base points for 11kg tank purchase'),
('refill_11kg_points', '25', 'Base points for 11kg tank refill'),
('purchase_22kg_points', '80', 'Base points for 22kg tank purchase'),
('refill_22kg_points', '50', 'Base points for 22kg tank refill'),

-- NEW: Tier unlock thresholds based on LIFETIME points
('bronze_threshold', '0', 'Lifetime points needed for Bronze (default at signup)'),
('silver_threshold', '1800', 'Lifetime points needed for Silver tier'),
('gold_threshold', '3300', 'Lifetime points needed for Gold tier'),
('platinum_threshold', '7000', 'Lifetime points needed for Platinum tier'),

-- NEW: Tier discount percentages (applied to order total)
('bronze_discount', '0', 'Bronze tier automatic discount (0%)'),
('silver_discount', '2', 'Silver tier automatic discount (2%)'),
('gold_discount', '3', 'Gold tier automatic discount (3%)'),
('platinum_discount', '4', 'Platinum tier automatic discount (4%)'),

-- NEW: Redemption rules per tier (points required : discount value)
('bronze_redemption_points', '500', 'Points needed for Bronze redemption'),
('bronze_redemption_value', '40', 'Discount value for Bronze redemption (₱40)'),
('silver_redemption_points', '1000', 'Points needed for Silver redemption'),
('silver_redemption_value', '90', 'Discount value for Silver redemption (₱90)'),
('gold_redemption_points', '1500', 'Points needed for Gold redemption'),
('gold_redemption_value', '140', 'Discount value for Gold redemption (₱140)'),
('platinum_redemption_points', '2000', 'Points needed for Platinum redemption'),
('platinum_redemption_value', '190', 'Discount value for Platinum redemption (₱190)'),

-- NEW: Free delivery cluster requirements (for Platinum only)
('cluster_1_free_credits', '3', 'Free credits needed for Cluster 1 free delivery'),
('cluster_2_free_credits', '5', 'Free credits needed for Cluster 2 free delivery'),
('cluster_3_free_credits', '10', 'Free credits needed for Cluster 3 free delivery'),

-- System settings
('points_enabled', '1', 'Enable/disable points earning system'),
('rewards_enabled', '1', 'Enable/disable entire rewards program')

ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    description = VALUES(description);

-- Step 6: Create delivery_clusters table if not exists
CREATE TABLE IF NOT EXISTS delivery_clusters (
    cluster_id INT AUTO_INCREMENT PRIMARY KEY,
    cluster_name VARCHAR(50) NOT NULL,
    cluster_number INT NOT NULL UNIQUE,
    base_delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 50.00,
    free_credits_required INT NOT NULL COMMENT 'Free credits needed for Platinum free delivery',
    barangays TEXT COMMENT 'JSON array of barangays in this cluster',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 7: Insert default delivery clusters
INSERT INTO delivery_clusters (cluster_name, cluster_number, base_delivery_fee, free_credits_required, barangays) VALUES
('Cluster 1', 1, 50.00, 3, '["Dagupan City Center", "Downtown"]'),
('Cluster 2', 2, 75.00, 5, '["Nearby Areas", "Suburbs"]'),
('Cluster 3', 3, 100.00, 10, '["Remote Areas", "Outskirts"]')
ON DUPLICATE KEY UPDATE 
    free_credits_required = VALUES(free_credits_required);

-- Step 8: Add tier column to customers if not exists
ALTER TABLE customers 
ADD COLUMN tier VARCHAR(20) DEFAULT 'Bronze' COMMENT 'Current rewards tier based on lifetime points'
AFTER lifetime_points;

-- Step 9: Update existing customer tiers based on their lifetime_points
UPDATE customers 
SET tier = CASE 
    WHEN lifetime_points >= 7000 THEN 'Platinum'
    WHEN lifetime_points >= 3300 THEN 'Gold'
    WHEN lifetime_points >= 1800 THEN 'Silver'
    ELSE 'Bronze'
END;

-- Step 10: Create rewards_transactions table for tracking all rewards activity
CREATE TABLE IF NOT EXISTS rewards_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    order_id INT,
    transaction_type ENUM('earn', 'redeem', 'tier_upgrade') NOT NULL,
    points_change INT NOT NULL COMMENT 'Positive for earn, negative for redeem',
    lifetime_points_after INT NOT NULL,
    redeemable_points_after INT NOT NULL,
    tier_before VARCHAR(20),
    tier_after VARCHAR(20),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_order (order_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 11: Add columns to orders table for rewards tracking
ALTER TABLE orders 
ADD COLUMN tier_discount_percent DECIMAL(5,2) DEFAULT 0 COMMENT 'Tier discount % applied'
AFTER total_price;

ALTER TABLE orders 
ADD COLUMN tier_discount_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Tier discount amount in pesos'
AFTER tier_discount_percent;

ALTER TABLE orders 
ADD COLUMN points_redeemed INT DEFAULT 0 COMMENT 'Points used for redemption discount'
AFTER tier_discount_amount;

ALTER TABLE orders 
ADD COLUMN redemption_discount_amount DECIMAL(10,2) DEFAULT 0 COMMENT 'Redemption discount amount in pesos'
AFTER points_redeemed;

ALTER TABLE orders 
ADD COLUMN free_credits_earned DECIMAL(5,2) DEFAULT 0 COMMENT 'Free credits from this order (for Platinum free delivery)'
AFTER redemption_discount_amount;

ALTER TABLE orders 
ADD COLUMN free_delivery_applied BOOLEAN DEFAULT 0 COMMENT 'Whether Platinum free delivery was applied'
AFTER free_credits_earned;

ALTER TABLE orders 
ADD COLUMN delivery_cluster INT COMMENT 'Delivery cluster for this order'
AFTER free_delivery_applied;

ALTER TABLE orders 
ADD COLUMN customer_tier VARCHAR(20) COMMENT 'Customer tier at time of order'
AFTER delivery_cluster;

-- Step 12: Create index for better performance
CREATE INDEX idx_customer_tier ON customers(tier);
CREATE INDEX idx_lifetime_points ON customers(lifetime_points);

-- ============================================
-- VERIFICATION QUERIES (Run these to check)
-- ============================================

-- Check customers with lifetime points
-- SELECT customer_id, full_name, email, total_points, lifetime_points, tier 
-- FROM customers 
-- ORDER BY lifetime_points DESC;

-- Check rewards settings
-- SELECT * FROM rewards_settings ORDER BY setting_key;

-- Check delivery clusters
-- SELECT * FROM delivery_clusters;

-- ============================================
-- ROLLBACK SCRIPT (if needed)
-- ============================================
-- -- ALTER TABLE customers DROP COLUMN lifetime_points;
-- -- ALTER TABLE customers DROP COLUMN tier;
-- -- DROP TABLE IF EXISTS rewards_settings;
-- -- DROP TABLE IF EXISTS rewards_transactions;
-- -- DROP TABLE IF EXISTS delivery_clusters;
