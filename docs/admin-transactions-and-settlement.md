# Admin transaction filters and monthly settlement backup

## Admin panel

The panel is served at `/admin/` and requires an active row in `admin_users`. It supports filters for:

- Supplier API/provider (Digiflazz or VIPayment, based on active provider rows)
- Payment gateway (Duitku)
- Start and end date/time, using the configured `APP_TIMEZONE`; choose whether the filter applies to order creation, payment received, top-up completion, or supplier API transaction creation
- Payment status and order status

The report is read-only, does not expose the saved game-account ID/zone values, and shows at most 200 matching rows per request. It is a transaction inspection view, not a live API ping to each supplier.

## Create the first administrator

Apply the initial schema and migrations through 005 first. Run this command in the private server environment, not through a public URL:

```sh
export ADMIN_INITIAL_PASSWORD='use-a-unique-password-at-least-14-characters'
php bin/create-admin.php admin admin@example.com
unset ADMIN_INITIAL_PASSWORD
```

Use a unique password and replace the example email. The script creates a `SUPER_ADMIN` account and stores only a password hash. Do not put the initial password in Git or a committed file. Login is available at `/admin/`.

## Apply the settlement migration

After migrations 001–005, apply:

```sh
mysql -u DB_USER -p DB_NAME < database/migrations/006_monthly_settlements.sql
```

This creates `monthly_settlement_exports`, which records each month, upload state, Drive file ID, row count, and the sum of PAID orders. It prevents a completed month from being uploaded twice and blocks a second run while the same period is actively processing. After that, apply migration 007 to enable the admin-managed folder setting.

## Set the destination folder from the admin panel

After applying migration 007, sign in at `/admin/`, open **Settlement bulanan & backup Google Drive**, paste the shared folder URL copied from Google Drive (for example, a URL shaped like `https://drive.google.com/drive/folders/FOLDER_ID`), then choose **Simpan folder tujuan**. The app validates that it is an HTTPS Google Drive folder link and stores only the folder ID in `app_settings`; the panel displays only the last six characters of the saved ID.

The folder link is a destination, not authorization by itself. The Google account represented by the server's OAuth refresh token must have permission to create files in that folder. Do not paste OAuth client secrets or refresh tokens into the panel.

Apply migration 007 after migration 006:

```sh
mysql -u DB_USER -p DB_NAME < database/migrations/007_admin_drive_folder_setting.sql
```

## Google Drive OAuth configuration

The Google Drive connector in ChatGPT is not used by the website's server-side backup. PluQ-I uploads using the Google Drive API and a Google OAuth refresh token.

1. In Google Cloud Console, create/select a project and enable the Google Drive API.
2. Configure an OAuth client and grant it access to the Google account and destination Drive folder intended for PluQ backups. Obtain an OAuth refresh token with offline access and a Drive scope that permits creating and finding files created by this app (for example, `drive.file`).
3. Put the values in the server's private `.env`, outside the public document root when possible. Never commit these values:
   - `SETTLEMENT_BACKUP_ENABLED=true`
   - `GOOGLE_OAUTH_CLIENT_ID=...`
   - `GOOGLE_OAUTH_CLIENT_SECRET=...`
   - `GOOGLE_OAUTH_REFRESH_TOKEN=...`
   - `GOOGLE_DRIVE_FOLDER_ID=...` (optional fallback folder ID; the folder saved in the admin panel takes priority)
4. Keep `SETTLEMENT_BACKUP_ENABLED=false` until OAuth permissions, the folder, and a controlled upload have been verified.

The script refreshes the OAuth access token at runtime; the refresh token and client secret remain on the server. If the Google account revokes access or the refresh token expires, the settlement is marked `FAILED` and the panel shows the error for investigation.

## Run and schedule

On the first day of each month, the script creates a formatted Google Sheets workbook named `PluQ-Pembukuan-YYYY-MM` in the destination folder and records its spreadsheet ID. The workbook contains three tabs:

1. **Ringkasan Bulanan** — total order count, paid order count, paid product sales, recorded product cost, admin fees, recorded customer payment total, indicative gross margin, and payment/order status summaries.
2. **Rincian Transaksi** — one row per order relevant to the month because it was created, paid, or fulfilled in that period, with the reason included in the final column. It includes order dates, game/product, supplier API, base/selling price, admin fee, customer total, payment/order/supplier/refund statuses, and payment/supplier references. The header is styled, columns are sized, and IDR columns use currency formatting.
3. **Rekonsiliasi** — non-paid orders and orders with failed, manual-review, or refund-related statuses that should be checked before closing the books.

The workbook deliberately excludes raw customer game-account JSON and raw supplier request/response payloads. The Google OAuth authorization must include both a Drive scope that permits the app to manage its created files (such as `https://www.googleapis.com/auth/drive.file`) and the Google Sheets scope `https://www.googleapis.com/auth/spreadsheets`.

Cron example (adjust PHP and repository paths for the host):

```cron
15 2 1 * * /usr/bin/php /absolute/path/PluQ-I/bin/settle-monthly.php >> /absolute/path/PluQ-I/storage-settlement.log 2>&1
```

The command runs only on day 1 unless a completed month is explicitly supplied for controlled recovery:

```sh
php bin/settle-monthly.php --period=2026-09
```

Do not use the recovery option to overwrite a successful export. The script will skip any month already recorded as `UPLOADED`. Google Drive file metadata includes a month marker to help recover if a file was uploaded but the local database update was interrupted. The temporary CSV is removed even if the upload fails; the database keeps the failed status and error for review.

## Settlement meaning and retention

- The workbook includes records created during the period, including pending and failed records, so the monthly report can be reconciled.
- The recorded `total_amount` and the summary's paid-sales metrics use orders whose `payment_status` is `PAID` and whose `paid_at` falls inside the report month. The detail list also includes orders created or fulfilled during that month for reconciliation, so its row count can be larger than the count of payments received that month. The amount is the sum of `selling_price + admin_fee`; it is not supplier profit or a bank settlement guarantee. The sheet's indicative gross margin is `selling_price - base_price` for PAID records before payment gateway fees, operating expenses, tax adjustments, and confirmed refunds. Refunds are flagged for reconciliation and are not automatically deducted.
- This feature does not delete or archive-purge orders from MySQL. Keeping transaction history in the database preserves the operational audit trail; only the temporary export file is removed from hosting.
- A Drive upload success does not replace a database backup and restore plan. Keep independent database backups.
- This report is an operational bookkeeping aid, not a tax calculation or tax advice. Confirm tax treatment and the final tax base with a qualified accountant/tax adviser, and retain payment-gateway statements, supplier invoices, and refund evidence.
- No real Google Drive/Sheets upload has been verified from this repository/hosting environment yet.
