-- ============================================
-- PIERRE GASLY - REWARDS SYSTEM V3 (SUPABASE)
-- Final rewards logic migration for PostgreSQL / Supabase
-- ============================================

BEGIN;

-- --------------------------------------------
-- 1) USER REWARDS STRUCTURE
-- --------------------------------------------
ALTER TABLE user_rewards
    ADD COLUMN IF NOT EXISTS lifetime_points INTEGER NOT NULL DEFAULT 0;

UPDATE user_rewards
SET lifetime_points = COALESCE(NULLIF(lifetime_points, 0), total_points, 0)
WHERE lifetime_points = 0;

ALTER TABLE user_rewards
    DROP CONSTRAINT IF EXISTS user_rewards_tier_check;

ALTER TABLE user_rewards
    ADD CONSTRAINT user_rewards_tier_check
    CHECK (tier IN ('Bronze', 'Silver', 'Gold', 'Platinum'));

-- --------------------------------------------
-- 2) ORDER REWARDS SNAPSHOT FIELDS
-- --------------------------------------------
ALTER TABLE orders ADD COLUMN IF NOT EXISTS subtotal_amount NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_fee_amount NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS tier_discount_percent NUMERIC(5,2) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS tier_discount_amount NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS points_redeemed INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS redemption_discount_amount NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS free_credits_earned NUMERIC(6,2) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS free_delivery_applied BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_cluster VARCHAR(20);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_tier VARCHAR(20);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS reward_points_earned INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS rewards_summary TEXT;

-- --------------------------------------------
-- 3) REPLACE OLD REWARDS SETTINGS WITH FINAL LOGIC
-- --------------------------------------------
DELETE FROM rewards_settings;

INSERT INTO rewards_settings (setting_key, setting_value, updated_at) VALUES
-- Base points by product type / size
('purchase_7kg_points', '60', NOW()),
('refill_7kg_points', '50', NOW()),
('purchase_11kg_points', '100', NOW()),
('refill_11kg_points', '90', NOW()),
('purchase_22kg_points', '210', NOW()),
('refill_22kg_points', '200', NOW()),
('purchase_above_22kg_points', '250', NOW()),
('refill_above_22kg_points', '220', NOW()),

-- Tier unlock thresholds (lifetime points)
('bronze_threshold', '0', NOW()),
('silver_threshold', '1800', NOW()),
('gold_threshold', '3300', NOW()),
('platinum_threshold', '7000', NOW()),

-- Automatic tier discounts
('bronze_discount_pct', '0', NOW()),
('silver_discount_pct', '2', NOW()),
('gold_discount_pct', '3', NOW()),
('platinum_discount_pct', '4', NOW()),

-- Redemption rules by tier
('bronze_redemption_points', '500', NOW()),
('bronze_redemption_value', '40', NOW()),
('silver_redemption_points', '1000', NOW()),
('silver_redemption_value', '90', NOW()),
('gold_redemption_points', '1500', NOW()),
('gold_redemption_value', '140', NOW()),
('platinum_redemption_points', '2000', NOW()),
('platinum_redemption_value', '190', NOW()),

-- Platinum free delivery rules by delivery cluster
('cluster_1_free_credits', '3', NOW()),
('cluster_2_free_credits', '5', NOW()),
('cluster_3_free_credits', '10', NOW()),
('lpg_free_credit_value', '1', NOW()),
('refill_free_credit_value', '0.5', NOW()),

-- General controls
('points_enabled', '1', NOW()),
('rewards_enabled', '1', NOW()),
('tier_discount_stacks_with_redemption', '1', NOW()),
('one_redemption_per_order', '1', NOW())
ON CONFLICT (setting_key) DO UPDATE
SET setting_value = EXCLUDED.setting_value,
    updated_at = NOW();

-- --------------------------------------------
-- 4) NORMALIZE EXISTING USER TIERS USING LIFETIME POINTS
-- --------------------------------------------
UPDATE user_rewards
SET tier = CASE
    WHEN lifetime_points >= 7000 THEN 'Platinum'
    WHEN lifetime_points >= 3300 THEN 'Gold'
    WHEN lifetime_points >= 1800 THEN 'Silver'
    ELSE 'Bronze'
END,
updated_at = NOW();

-- --------------------------------------------
-- 5) INDEXES
-- --------------------------------------------
CREATE INDEX IF NOT EXISTS idx_user_rewards_lifetime_points ON user_rewards(lifetime_points);
CREATE INDEX IF NOT EXISTS idx_user_rewards_tier ON user_rewards(tier);
CREATE INDEX IF NOT EXISTS idx_reward_transactions_user_created ON reward_transactions(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_customer_ordered_at ON orders(customer_id, ordered_at DESC);

COMMIT;

-- ============================================
-- POST-RUN CHECKS
-- ============================================
-- SELECT setting_key, setting_value FROM rewards_settings ORDER BY setting_key;
-- SELECT user_id, total_points, lifetime_points, redeemed_points, tier FROM user_rewards ORDER BY user_id;
