-- Fix duplicate key on *_pkey caused by sequences not matching table data
-- Run this in Supabase SQL Editor (Database -> SQL Editor).

-- Products
SELECT setval(pg_get_serial_sequence('products', 'product_id'),
              COALESCE((SELECT MAX(product_id) FROM products), 0) + 1,
              false);

-- Brands
SELECT setval(pg_get_serial_sequence('brands', 'brand_id'),
              COALESCE((SELECT MAX(brand_id) FROM brands), 0) + 1,
              false);

-- Product Sizes
SELECT setval(pg_get_serial_sequence('product_sizes', 'size_id'),
              COALESCE((SELECT MAX(size_id) FROM product_sizes), 0) + 1,
              false);

-- Users
SELECT setval(pg_get_serial_sequence('users', 'user_id'),
              COALESCE((SELECT MAX(user_id) FROM users), 0) + 1,
              false);

-- If pg_get_serial_sequence returns NULL, it means the column is not SERIAL/IDENTITY.
-- In that case, you need to set a DEFAULT nextval(...) or convert the column to IDENTITY.
