INSERT INTO system_settings (setting_key, setting_value, created_at, updated_at)
VALUES
('delivery_fee_tier_1', '50.00', NOW(), NOW()),
('delivery_fee_tier_2', '90.00', NOW(), NOW()),
('delivery_fee_tier_3', '130.00', NOW(), NOW()),
('branch_city', 'Dagupan City', NOW(), NOW()),
('service_province', 'Pangasinan', NOW(), NOW()),
('cod_enabled', '1', NOW(), NOW()),
('pickup_enabled', '1', NOW(), NOW()),
('opening_time', '08:00', NOW(), NOW()),
('closing_time', '18:00', NOW(), NOW()),
('pickup_after_hours_rule', 'Pickup requests placed after closing time are processed the next day', NOW(), NOW())
ON DUPLICATE KEY UPDATE
setting_value = VALUES(setting_value),
updated_at = NOW();
