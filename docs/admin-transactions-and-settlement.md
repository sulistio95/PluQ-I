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

On the first day of each month, the script exports orders created in the preceding calendar month to a CSV file, uploads it to Drive, records the Drive file ID, and removes its local temporary CSV file. The CSV includes order/payment/provider statuses, amounts, references, game and product names, and fulfillment time. It deliberately excludes the raw customer game-account JSON and raw provider request/response payloads.

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

- The CSV includes all orders created during the period, including pending and failed records, so the monthly report can be reconciled.
- The recorded `total_amount` is the sum of `selling_price + admin_fee` for orders whose payment status is `PAID`; it is not supplier profit or a bank settlement guarantee.
- This feature does not delete or archive-purge orders from MySQL. Keeping transaction history in the database preserves the operational audit trail; only the temporary export file is removed from hosting.
- A Drive upload success does not replace a database backup and restore plan. Keep independent database backups.
- No real Google Drive upload has been verified from this repository/hosting environment yet.
