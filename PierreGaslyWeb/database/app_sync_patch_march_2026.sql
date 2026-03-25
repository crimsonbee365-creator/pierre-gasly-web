-- Pierre Gasly app sync patch
-- Adds columns used by the latest mobile/web APIs while keeping the existing schema compatible.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS auth_user_id UUID;

CREATE INDEX IF NOT EXISTS idx_users_auth_user_id ON users(auth_user_id);

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS fulfillment_method VARCHAR(20)
        CHECK (fulfillment_method IN ('cod', 'pickup'));

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20);

UPDATE orders
SET fulfillment_method = CASE
    WHEN LOWER(COALESCE(delivery_address, '')) LIKE '%branch pickup%'
      OR LOWER(COALESCE(delivery_address, '')) LIKE '%pickup schedule:%' THEN 'pickup'
    ELSE 'cod'
END
WHERE fulfillment_method IS NULL;

UPDATE orders
SET contact_number = NULLIF(REGEXP_REPLACE(
    COALESCE((regexp_match(COALESCE(delivery_address, ''), 'Contact\s*Number\s*:\s*([^\n\r]+)', 'i'))[1], ''),
    '^\s+|\s+$',
    '',
    'g'
), '')
WHERE contact_number IS NULL;

ALTER TABLE user_rewards
    ADD COLUMN IF NOT EXISTS lifetime_points INTEGER NOT NULL DEFAULT 0;

UPDATE user_rewards
SET lifetime_points = COALESCE(NULLIF(lifetime_points, 0), total_points, 0)
WHERE lifetime_points = 0;

CREATE INDEX IF NOT EXISTS idx_user_rewards_lifetime_points ON user_rewards(lifetime_points);
