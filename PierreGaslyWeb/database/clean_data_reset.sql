-- ============================================
-- PIERRE GASLY - CLEAN DATA RESET
-- Keeps current structure and process intact
-- Leaves only master admin, PETRON / PRYCE GASES / REGASCO,
-- and the two commonly used LPG sizes: 11kg and 22kg
-- ============================================

BEGIN;

DELETE FROM reward_transactions;
DELETE FROM sales;
DELETE FROM review_responses;
DELETE FROM reviews;
DELETE FROM inventory_logs;
DELETE FROM activity_logs;
DELETE FROM orders;
DELETE FROM products;

-- Remove unwanted brands and sizes entirely after products are cleared
DELETE FROM brands WHERE brand_name NOT IN ('PETRON', 'PRYCE GASES', 'REGASCO');
DELETE FROM product_sizes WHERE size_kg NOT IN (11, 22);

UPDATE user_rewards
SET total_points = 0,
    redeemed_points = 0,
    tier = 'Bronze',
    updated_at = CURRENT_TIMESTAMP;

DELETE FROM user_rewards
WHERE user_id <> 1;

DELETE FROM rider_availability
WHERE rider_id <> 1;

DELETE FROM users
WHERE user_id <> 1;

UPDATE brands
SET is_active = CASE WHEN brand_name IN ('PETRON', 'PRYCE GASES', 'REGASCO') THEN true ELSE false END,
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO brands (brand_name, created_by, is_active, created_at, updated_at)
VALUES
('PETRON', 1, true, NOW(), NOW()),
('PRYCE GASES', 1, true, NOW(), NOW()),
('REGASCO', 1, true, NOW(), NOW())
ON CONFLICT (brand_name) DO UPDATE
SET is_active = EXCLUDED.is_active,
    updated_at = CURRENT_TIMESTAMP;

UPDATE product_sizes
SET is_active = CASE WHEN size_kg IN (11, 22) THEN true ELSE false END,
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO product_sizes (size_kg, created_by, is_active, created_at, updated_at)
VALUES
(11, 1, true, NOW(), NOW()),
(22, 1, true, NOW(), NOW())
ON CONFLICT (size_kg) DO UPDATE
SET is_active = EXCLUDED.is_active,
    updated_at = CURRENT_TIMESTAMP;

SELECT setval(pg_get_serial_sequence('users', 'user_id'), COALESCE((SELECT MAX(user_id) FROM users), 1), true);
SELECT setval(pg_get_serial_sequence('products', 'product_id'), COALESCE((SELECT MAX(product_id) FROM products), 1), true);
SELECT setval(pg_get_serial_sequence('orders', 'order_id'), COALESCE((SELECT MAX(order_id) FROM orders), 1), true);
SELECT setval(pg_get_serial_sequence('brands', 'brand_id'), COALESCE((SELECT MAX(brand_id) FROM brands), 1), true);
SELECT setval(pg_get_serial_sequence('product_sizes', 'size_id'), COALESCE((SELECT MAX(size_id) FROM product_sizes), 1), true);
SELECT setval(pg_get_serial_sequence('user_rewards', 'reward_id'), COALESCE((SELECT MAX(reward_id) FROM user_rewards), 1), true);
SELECT setval(pg_get_serial_sequence('reward_transactions', 'tx_id'), COALESCE((SELECT MAX(tx_id) FROM reward_transactions), 1), true);

COMMIT;
