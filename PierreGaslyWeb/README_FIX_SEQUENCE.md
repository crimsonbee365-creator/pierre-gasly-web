# Fix for HTTP 409 duplicate primary key (23505)

If you see errors like:
- Key (product_id)=(1) already exists
- Key (user_id)=(1) already exists

It means your Postgres auto-increment sequence is out of sync (or missing).
Run `supabase_fix_sequences.sql` in Supabase SQL Editor.

After that, adding Products/Brands/Sizes/Users will work normally.
