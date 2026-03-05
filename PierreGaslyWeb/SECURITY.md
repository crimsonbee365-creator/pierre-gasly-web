# Security Notes (Supabase keys)

## What happened
GitHub detected that a Supabase key was committed to the repository. Treat this as **publicly leaked**.

## What to do now (required)
1. **Rotate / revoke the leaked key** in Supabase:
   - Supabase Dashboard → Project Settings → API → (Service Role Key) → rotate.
2. **Update your hosting environment variables** (Railway):
   - SUPABASE_URL
   - SUPABASE_ANON_KEY
   - SUPABASE_SERVICE_KEY (service_role)
3. **Remove the key from Git history**:
   - Preferred: `git filter-repo` (or BFG) to purge the secret from all commits.
   - Then force-push to GitHub.
4. **Verify**:
   - Deploy again.
   - Confirm GitHub secret scanning alert is marked as resolved.

## Do not commit secrets
This project now reads keys from environment variables, or from local-only files:
- `includes/config.local.php`
- `api/config.local.php`

These files are ignored by git via `.gitignore`.
