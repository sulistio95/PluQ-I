# PluQ-I — Delete inactive pricing rules (2026-10-19)

## Change
- Updated `public/admin/pricing-settings.php` to add a **Hapus** action for saved pricing rules.
- Deletion is POST-only, requires an authenticated admin role with settings-management permission, and validates the existing CSRF token.
- The UI asks for confirmation before submitting.
- Only `INACTIVE` rules can be deleted. Active rules must first be deactivated; the existing overlap validation and save logic still apply.
- Successful deletion is recorded in `audit_logs` as `PRICING_RULE_DELETED` and then redirects with a success notice.
- Database deletion and audit logging run in one transaction; errors roll back the transaction.

## Commit
- Commit: `980f25cf8bb496f1efb20419928be9cb238fad52`
- File: [public/admin/pricing-settings.php](https://github.com/sulistio95/PluQ-I/blob/main/public/admin/pricing-settings.php)

## Verification status
- Code change committed to GitHub.
- Local PHP lint and browser/database verification have **not yet been run** for this change.
- After pulling the commit, run:
  ```bash
  cd /root/PluQ-I
  git pull origin main
  php -l public/admin/pricing-settings.php
  php -l tests/pricing-hybrid.php
  php tests/pricing-hybrid.php
  ```
- Then sign in to the local admin panel, open `/admin/pricing-settings.php`, confirm active rules do not offer deletion, deactivate a test rule, delete it, and verify the success message and audit log. Do this only against the local test database.

## Safety note
Do not delete all coverage for a modal-price range. Keep at least one suitable active rule for every product range that should remain available. Checkout, supplier ordering, and settlement/Google Drive backup remain disabled during local testing.
