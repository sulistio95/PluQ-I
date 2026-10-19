## Admin catalog sync — 2026-10-19

- Added explicit admin actions to persist VIPayment or Digiflazz catalog data after supplier game mappings have been saved.
- Added rollback protection: a full supplier snapshot with zero matches to active game mappings no longer commits stale/unavailable flags over existing catalog entries.
- CI results observed so far: PHP quality and PHP/JavaScript syntax passed on commits `3f4cb21f` and `21715ee4`; fulfillment safety was still running when checked. The latest handoff/docs commit also triggered new workflow runs, which must finish before final CI status is declared.
- Local database/browser verification remains pending. No customer checkout, supplier order, or payment is triggered by catalog sync.

## 2026-10-19 — Assertion kapasitas kedua mengikuti tampilan Habis

- Run fulfillment safety pada commit `d247a811c3c9e4e048e9bf610d441d481483a598` melewati assertion pertama setelah koreksi, lalu menemukan assertion kedua yang masih mengharapkan katalog kosong ketika reservasi kedua menghabiskan kapasitas. Ini juga ekspektasi lama yang bertentangan dengan keputusan nominal tetap tampil.
- Memperbarui assertion kedua agar mengharapkan satu baris nominal dengan `is_available=false`; tes reservasi dan quote checkout tetap memeriksa penolakan sisi server.
- Commit tes: `6285888f1abfc57c10782a50b9ac4bbea23e1c5a`. Hasil CI setelah commit terbaru masih harus diperiksa.

## 2026-10-19 — Koreksi tes integrasi setelah keputusan label Habis

- Run CI awal pada commit `d1fabb7dee45f9ad2e5526606312c8408bfcdd14`: PHP quality lulus, PHP/JavaScript syntax lulus, tetapi fulfillment safety gagal karena tes integrasi MySQL masih mengharapkan katalog kosong untuk penawaran yang melewati kapasitas saldo.
- Assertion tersebut tidak sesuai perilaku baru. Tes `tests/provider-budget-reservation-mysql-integration.php` diperbarui agar mengharapkan satu nominal dengan `is_available=false`, sambil tetap menguji bahwa `checkout_quote()` menolak pembelian.
- Commit koreksi: `0437cc493ca2ec3cd07203355938f3a4f76a082a`. Hasil CI untuk commit ini harus diverifikasi sebelum menyatakan pengujian selesai.
- Tidak ada transaksi Duitku, pesanan supplier, atau backup Google Drive yang dijalankan.

## 2026-10-19 — Nominal tetap terlihat saat semua supplier tidak tersedia

- Keputusan produk diperbarui atas persetujuan pengguna: jika beberapa supplier menawarkan nominal yang sama, pilih supplier termurah yang memenuhi syarat. Jika tidak ada supplier yang dapat melayani, nominal tetap terlihat sebagai **Habis**.
- Kode katalog memilih penawaran tersedia terlebih dahulu; jika semuanya tidak tersedia, memilih cache termurah sebagai baris tampilan dengan `is_available=false`. Frontend menampilkan label `Habis`, bukan tombol pilih.
- Ketersediaan tampilan tidak mengizinkan transaksi. Quote/checkout tetap harus memvalidasi ulang status produk, supplier, saldo, reservasi, dan aturan harga di server.
- Tes regresi katalog diperbarui; hasil eksekusi tes dan CI setelah commit masih menunggu. Ini bukan uji browser atau database lokal.
- Pembayaran, pemesanan supplier, dan backup Google Drive tetap nonaktif selama pengujian.

## 2026-10-19 — VIPayment dipaksa menggunakan IPv4

- Commit `e034368f646d6689448b19f392903d52945bf7bc` menambahkan `CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4` pada helper bersama `vipayment_post()`. Semua request VIPayment yang memakai helper ini dipaksa memilih IPv4, bukan IPv6.
- Alasan: pengguna melihat error whitelist yang menyebut IPv6 dan menduga whitelist profil hanya menerima IPv4. IP publik IPv4 terdeteksi berbeda oleh beberapa endpoint; whitelist harus memuat alamat IPv4 yang benar-benar terlihat oleh server VIPayment.
- Status verifikasi: perubahan sudah dikomit ke GitHub, tetapi lint lokal, CI setelah commit, dan uji katalog nyata setelah whitelist belum diverifikasi. Jangan klaim masalah telah selesai sebelum uji ulang.
- Langkah uji aman: pull perubahan, jalankan `php -l app/services/vipayment.php`, kemudian gunakan tombol pratinjau katalog baca-saja. Ini tidak seharusnya membuat pesanan. Jangan mengaktifkan checkout, supplier fulfillment, atau backup Drive.


## Update — 2026-10-19: Masa berlaku invoice Duitku

- Masa berlaku invoice kini dapat diatur dari **Pengaturan API → Duitku → Masa berlaku invoice**, default 30 menit, dengan validasi 5–1440 menit. Kolom kosong mempertahankan nilai yang tersimpan; nilai lingkungan `DUITKU_EXPIRY_MINUTES` menjadi fallback.
- Permintaan Duitku menggunakan `expiryPeriod` dari konfigurasi yang sama dengan `payment_transactions.gateway_expiry_at`, sehingga batas waktu gateway dan batas waktu lokal tidak lagi menggunakan angka hard-coded yang terpisah.
- Menambahkan `bin/reconcile-expired-payments.php` sebagai CLI rekonsiliasi. Script hanya memeriksa invoice PENDING yang melewati tenggat dan menanyakan status Duitku sandbox. Status lokal menjadi EXPIRED hanya jika Duitku mengonfirmasi status gagal/kedaluwarsa; PROCESS tetap pending, sedangkan SUCCESS tidak memicu fulfillment dan perlu ditangani melalui callback/rekonsiliasi terpisah. Pembaruan memakai row lock dan memeriksa ulang status untuk mencegah callback yang datang bersamaan ditimpa.
- Script tidak menerima request web dan tidak memanggil endpoint pembelian supplier. Jadwalkan melalui cron PHP CLI yang tersedia di hosting (misalnya setiap 15 menit); jangan membuka script sebagai URL publik.
- Tes batas nilai ditambahkan ke `tests/duitku-transaction-status.php`. GitHub Actions **PHP quality** dan **PHP/JavaScript syntax** lulus pada commit `94fbe6e7fd1b73762f591f061fe791f9fa637b38`; workflow fulfillment safety masih berjalan saat catatan ini diperbarui. Ini belum menggantikan uji lokal/browser dan uji Duitku sandbox nyata. Tetap pertahankan `CHECKOUT_PAYMENTS_ENABLED=false`, `SUPPLIER_ORDERS_ENABLED=false`, dan backup Drive nonaktif sampai uji staging terkontrol selesai.
## Hybrid pricing CI follow-up — 2026-10-19

- The first MySQL integration workflow caught a syntax error in the correlated SQL subqueries added for percentage pricing (the subquery's `SELECT` keyword was missing). This was a real CI failure, not a passing test.
- Corrected both `app/services/catalog.php` and `app/services/checkout_quote.php` in commits `c27b930eeb75d330e9118e389fec54326b29ba3e` and `e3a8d3b87bceaa3b3411cbed16aa6defe37e6e48`.
- PHP quality and PHP/JavaScript syntax workflows had passed on the earlier commit, but the MySQL integration workflow failed at the catalog query. The post-fix CI result is pending; verify that before treating this change as complete.
- No live payment, supplier order, or Drive backup was triggered.

## Pengaturan keuntungan nominal/persentase — 2026-10-19

- Menambahkan `public/admin/pricing-settings.php` dan tautan **Pengaturan Keuntungan** pada panel admin. ADMIN/SUPER_ADMIN dapat menambah dan mengubah rentang modal, memilih keuntungan nominal (Rp) atau persentase, mengaktifkan/menonaktifkan aturan, dan melihat waktu pembaruan. OPERATOR hanya dapat melihat.
- Migration `database/migrations/012_pricing_rules_hybrid.sql` menambah `profit_type` dan `profit_value`; data lama dipertahankan dengan menyalin `profit_amount` ke `profit_value` sebagai FIXED.
- Perhitungan di `app/services/pricing.php`, katalog publik, dan validasi harga checkout menggunakan aturan yang sama. Persentase dihitung dari modal supplier dan dibulatkan ke rupiah terdekat; biaya admin invoice tetap terpisah. Rentang aktif yang tumpang tindih ditolak dan perubahan dicatat di audit log dalam transaksi DB.
- Menambahkan `tests/pricing-hybrid.php` dan memasukkannya ke workflow PHP quality. Preflight kini mewajibkan migration `012_pricing_rules_hybrid`.
- Commits: migration `9aad765bf710aa9716861c66df58dbe39c947a73`; halaman admin `81b5ee4a05eae463e8f836c33c401d50b9bf6fac`; service `66ddc234ea0b01ef1d4ff0d99d57f6b674d3da0b`; katalog `86d67429412405f73751ab8aee5f2a86d6f19c82`; quote `8ca22bfa5bff9f6edf65c740a0cc002ad6cdca8f`; navigasi `b113a9ef8aa96e77c02baab016d1222c236b00b3`; preflight `e5e8aea7204213d8e53e5f6f54bf2eb949f11b9f`; test `cd0e0145a8728cd61fd4a50fa2984093f77a45da`; workflow `53c004b0d9589b9439c1b229444c27c398a3a0bd`.
- Belum diklaim lulus CI, lint lokal, atau uji browser/database. Sebelum digunakan, tarik perubahan, terapkan migration 012 setelah migration 011, lalu periksa halaman, pengubahan aturan, harga katalog dan kutipan checkout. Jangan mengaktifkan pembayaran, pemesanan supplier, atau backup Drive saat pengujian.

## Homepage layout clarification — 2026-10-19

- Updated `public/index.php` so the existing **PluQ** brand remains in its original top-left header position and appearance; it was not redesigned or moved.
- Homepage content order is now: unchanged top-left PluQ brand → banner area → game search field → game catalog/list. Search filtering remains connected to the existing catalog JavaScript.
- Updated `public/assets/catalog.css` to make the search field full-width below the banner and keep spacing responsive on mobile. Existing banner rotation code still advances every 3 seconds when multiple active banners exist.
- Source change committed as `3fac5a148b383e962cdf6f6296dcf32fb82ec75a`; CSS commit: `7c7e73019485a28df667ca10989249521b115aeb`.
- Runtime/browser screenshot verification and GitHub Actions verification are not yet claimed. Check layout at desktop/mobile sizes and confirm the banner/search/list order once local browser access is available. Keep checkout, supplier ordering, and Google Drive backup disabled.


## Admin template settings — 2026-10-19

- Added `public/admin/template-settings.php` and linked it from the admin navigation as **Pengaturan Template**.
- The page supports creating/editing banners using an HTTPS image URL, optional click-through destination, display order, and active/inactive status. Active banners are already exposed by the existing `GET /api/banners` catalog endpoint via `catalog_banners()`.
- Banner changes require an authenticated ADMIN/SUPER_ADMIN role, CSRF validation, and write audit-log entries. Deactivation preserves the record instead of deleting it.
- This first iteration uses image URLs; direct file upload and scheduling are not implemented in this page yet. The homepage slider's visual behavior (3-second autoplay, loop, arrows, indicators, pause-on-hover, mobile swipe) still needs browser verification and may require frontend changes if not already present.
- No real browser/database end-to-end test or GitHub Actions result is claimed for this commit. Keep checkout, supplier orders, and Drive backup disabled while testing.

# PluQ-I implementation and readiness audit

Audit baseline: GitHub default branch at the time this file was added. This is a source-code review, not a live-hosting or payment certification.

## Admin API settings UI verification — 2026-10-19

- After correcting a PHP argument-unpacking syntax error in the new settings page, all three GitHub Actions workflows passed on commit `bda15ed334c5dd318f362c079cfb6b652bcee286`: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39200932151), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39200932012), and [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/39200931982).
- CI proves syntax and existing regression suites pass; it does **not** prove that saving/decrypting credentials or the new page's interactive requests were tested end-to-end. Local browser/database verification remains pending.

## Admin API settings UI — 2026-10-19

- Added `public/admin/api-settings.php` and an admin navigation link for Duitku, Digiflazz, and VIPayment credentials.
- Credentials entered through the UI are encrypted with PHP Sodium secretbox and stored in `app_settings`; `APP_KEY` must be a stable, private random secret (at least 32 characters). Keep a secure copy of APP_KEY; changing it makes stored values unreadable.
- Provider service configuration now checks encrypted admin settings before falling back to environment values. Empty form fields preserve saved values. The UI does not display stored secrets.
- Duitku button validates local sandbox configuration only. Digiflazz/VIPayment balance buttons make explicit read-only balance/profile requests; they do not create top-up orders. Checkout, supplier fulfillment, and Drive backup flags remain unchanged and disabled by default.
- **Verification pending:** PHP lint, browser/login flow, encrypted save/reload, and supplier read-only requests have not yet been run against the user's local laptop or CI. Do not enter real credentials until those checks are completed. Use staging/sandbox credentials first.

## Status legend

- **Implemented in repository**: corresponding code or documentation exists.
- **Source-reviewed**: relevant paths were inspected for the behavior described.
- **Runtime verification pending**: behavior has not been executed against a configured MySQL/MariaDB instance, provider sandbox, or hosting account.
- **Blocked for production**: do not enable live payments or supplier execution.

## Latest verification update — 2026-10-16

- Local MySQL/PHP checks reported in the active session passed for provider budget reservation boundaries/idempotency/concurrency, checkout idempotency and deadlock recovery using a mocked gateway creator, and concurrent queue claiming; the listed PHP regression test groups also passed.
- The worker lease-revocation integration test did not execute because the local database had no game/product rows. Commit `93de6ee` removes that hidden fixture dependency by creating a unique temporary game and product for the test and deleting them in cleanup. It is committed; local rerun and CI verification are still pending.
- Safety finding: `tests/queue_recovery_integration.sh` must not be run as-is because it broadly marks all pending/retry jobs DONE and forces `SUPPLIER_ORDERS_ENABLED=true` for the queue worker. Keep payment, supplier execution, and Drive backup disabled.
- The results above are local test evidence only. Hosting compatibility, a public HTTPS callback, a real Duitku sandbox transaction, supplier API execution, cron scheduling on the target host, and Google Drive permissions remain unverified.

## Latest verification update — 2026-10-15

- GitHub Actions on audit head `7edd7244c856968ecc4666978c3ee0787074adeb` completed successfully: PHP quality run `38692917813`, PHP/JavaScript syntax run `38692917809`, and fulfillment safety/MySQL integration run `38692917819`.
- The preceding runbook head `833bebe1` also passed PHP quality (`38692868185`), syntax (`38692868196`), and fulfillment safety (`38692868253`).
- Regression coverage includes stale queue recovery, preservation of existing payment-conflict `MANUAL_REVIEW` reasons, reservation state resolution, and sandbox callback isolation when supplier ordering is disabled.
- These are repository CI results. They do **not** prove the app runs on the user's shared-hosting account and are **not** a real Duitku sandbox transaction. Hosting, public HTTPS callback, host cron, target MariaDB, supplier endpoints, and Google Drive permissions remain unverified. Live payment and supplier execution remain blocked.

## Stage-by-stage audit

| Stage | Source review | Remaining verification / blocker |
|---|---|---|
| PHP bootstrap and environment | PDO uses exceptions, associative fetches, and native prepared statements; environment loading skips already-defined variables. | Verify document root/private `.env`, file permissions, HTTPS, PHP extensions, and production error handling on the selected host. |
| Schema and migrations | Foundation schema plus migrations 002–011 cover catalog/operations, checkout fields, manual-review states, settlements, admin Drive setting, login throttling, latest-refund lookup, checkout request fingerprints, and provider budget reservations. The latest CI fulfillment workflow passes with the migration set. | Still apply on a clean target MySQL/MariaDB database, test upgrade from an existing database, and verify compatibility with the exact shared-hosting database version. |
| Catalog and pricing | Catalog chooses an available cheapest offer by normalized product name; quote recalculates price, checks reserve balance and exclusions, and validates dynamic account fields. | Run pricing boundary tests and DB-backed catalog sync tests using real supplier catalogs; validate every game's account field names. |
| Supplier game mapping | Admin page allows authorized admins to activate/deactivate mappings between local games and provider game codes; changes are audited. | Configure exact Digiflazz brand/game codes and VIPayment game names; compare synced product counts and samples before enabling any order. |
| Checkout and idempotency | Idempotency key is bound to a normalized request fingerprint; duplicate-key races re-read the winning order; the Duitku payment URL is persisted even if a fast callback arrives first. UUID input is now validated against the 8-4-4-4-12 hexadecimal shape; unit tests cover valid UUIDs, case/whitespace normalization and malformed keys. These tests passed PHP quality run `38215219816` and syntax run `38215219946`; fulfillment run `38215219929` also passed. Source review confirmed the checkout key validator enforces the 8-4-4-4-12 hexadecimal shape. This audit found the existing test file did not actually assert valid/malformed UUID cases; commit `6fa1d80` adds valid lowercase, uppercase/whitespace-normalized, and malformed-key cases. CI for the UUID cases passed in runs `38237778503` and `38237778648`. Review of concurrent checkout handling found that MySQL deadlocks/serialization conflicts fail closed but did not re-read the winning checkout. Commit `103cf16` now classifies duplicate-key, deadlock, and lock-wait conflicts for a post-rollback lookup, reusing only an existing row with matching fingerprint and saved payment URL; it never re-calls Duitku on that path. Commit `59c893b` adds unit coverage for duplicate-key/deadlock SQLSTATEs and unrelated errors. The first quality run (`38238953683`) caught a test-fixture error because `PDOException` requires an integer code; commit `c2568d9` fixes the test fixture. Corrected commit `c2568d98b0` passed PHP quality (`38239039733`), syntax (`38239039554`), and fulfillment (`38239039583`). An older documentation commit (`5920d6f0a5`) still showed the pre-fix fixture and failed PHP quality; later corrected commits passed all workflows. Commit `623d0c6` adds a bounded wait (up to 22 seconds) for the winner's payment URL when a concurrent request finds a committed order before the mocked/real gateway response has been persisted. A MySQL integration test using a mocked Duitku creator has now been added in `tests/checkout-idempotency-concurrency-integration.php` and wired into the fulfillment workflow (commits `e64061e` and `d6f848f`); it tests concurrent same-key calls, exactly one order/payment/gateway call, URL reuse, and changed-payload rejection. The first integration run failed due to an inherited function-static PDO socket; commit `67fa47d` fixes fixture setup by closing a direct, non-static PDO before forking. The corrected integration passed in fulfillment run `38240603363`, with both MySQL integration and PHP tests successful. PHP quality (`38240603254`) and syntax (`38240603237`) also passed for commit `67fa47d`. A further audit found that SQLSTATE `23000` includes foreign-key violations, not just duplicate idempotency keys. Commit `bdf606b` narrows recovery to SQLSTATE `40001` or MySQL driver codes `1062`, `1205`, and `1213`; commit `24c0a59` adds tests for duplicate-key, deadlock, lock timeout, and foreign-key exclusion. The classifier unit-test commit `24c0a59` passed PHP quality (`38242799852`) and syntax (`38242799858`); fulfillment was still running at the last check. Added `tests/checkout-deadlock-classification-integration.php` and wired it into the MySQL workflow (commits `796d205` and `d0ef765`). It creates a real MySQL row-lock cycle and asserts one deadlock victim (driver code 1213) is classified for winner recovery while the other transaction commits. Fulfillment run `38242948715` passed both `mysql-callback-integration` and `php-tests`, including this real deadlock test; PHP quality, syntax, and fulfillment workflows also passed on follow-up documentation commit `e33a8140be` (runs `38242987438`, `38242987397`, `38242987424`). This verifies actual engine error classification, but does not yet simulate full `checkout_create_payment()` recovery with a deadlock victim. The fulfillment MySQL workflow applies migrations 009–010 after 008 (commit `ec6a6bc`); all three workflows passed for follow-up documentation (runs `38237885006`, `38237884979`, `38237885082`). The stale supplier freshness integration change (`5a168b8`) passed PHP quality `38236928788`, syntax `38236928777`, and fulfillment `38236928790`; documentation follow-ups also passed all workflows (latest runs `38236974932`, `38236974892`, `38236974895`). | Run concurrent same-key requests and same-key/different-payload requests against MySQL; verify one order/payment is created and a mismatched payload never reuses a payment URL. |
| Duitku callback | HMAC-SHA256 verification, order/amount matching, row locking, and first-transition queue insertion are present. Result code `01` maps to failed, matching the server-to-server callback documentation; amount parsing rejects invalid/overflowing integers. | Execute valid/invalid signature, wrong amount, duplicate callback, pending-then-paid, and conflicting-state cases against a test database. |
| Queue and worker | MySQL-backed bounded worker, lock tokens, retry cap, read-only health diagnostic, and conservative stale-lock manual review are present. `fulfillment_process()` now treats a fresh supplier `PROCESSING` row (updated within 10 minutes) as an active request and returns `IGNORED` for a duplicate queue job rather than prematurely forcing the order into manual review; older ambiguous processing still fails closed. Commit `43ba2b7`. | Added `tests/duplicate-queue-processing-integration.php` and wired it into `.github/workflows/fulfillment-tests.yml` to verify a fresh `PROCESSING` row is preserved when a duplicate job runs. The PHP unit-test job passed in run `38201807243`; the MySQL integration job ran the new duplicate-queue test successfully, but the existing ambiguous-supplier test failed because its fixture created a fresh `PROCESSING` record, which the new active-request guard correctly treats as in progress. The fixture was corrected in commit `92a0ddb` to age that record by 11 minutes. The integration rerun passed after fixing the bootstrap path: workflow run `38202791455` completed successfully, including duplicate queue processing, concurrent duplicate callbacks, stale queue recovery, ambiguous supplier outcome, explicit supplier rejection/refund state, and read-only checkout review. A further audit found a crash window: if supplier status had already been persisted as `FAILED` but the process stopped before creating the refund row, a retry returned `FAILED` without creating the refund. `app/services/fulfillment.php` now calls the idempotent explicit-failure recorder when it encounters an existing supplier `FAILED` state (commit `e221f01`); the integration test now simulates that crash window (commit `7908e11`). The crash-window recovery test passed in fulfillment workflow run `38203732917` (both `php-tests` and `mysql-callback-integration` successful, including explicit supplier rejection/refund). A subsequent race review found that an older worker's delayed supplier HTTP response could overwrite `REVIEW` after stale-lock recovery. Commit `30c0735` conditionally persists supplier responses only while the supplier transaction remains `PROCESSING`/`RETRYING`, and only updates order states from allowed in-flight states; PHP quality, syntax, and fulfillment workflows passed (runs `38204088585`, `38204088548`, `38204088535`). A further change in commits `3cf9eaf` and `b039424` wraps normal and uncertain supplier-response persistence in a transaction and locks the order before the supplier row, matching stale-lock recovery's lock order; all three workflows passed for the resulting latest documentation commit (runs `38204439650`, `38204439603`, `38204439553`). A subsequent audit found worker finalization could update order/supplier state before checking whether its queue lease had been revoked. Commit `957d310` introduced transactional finalization after locking the queue row and verifying the same `PROCESSING` status and `lock_token`; it uses queue → order → supplier lock order and reports `LOCK_LOST` without applying the stale outcome. The logic was extracted into `app/services/queue_worker.php` (`5240068`), and `tests/worker-lease-revocation-integration.php` (`3c99f14`) plus a MySQL CI step (`5595378`) now simulate recovery revoking the lease and assert queue/order/supplier review states remain unchanged when the old worker returns. The MySQL integration and PHP test jobs passed in fulfillment run `38209568044`, including the revoked-lease regression step. Expanded preflight schema coverage (`33a32ee`) passed all three workflows: quality `38209632272`, syntax `38209632254`, fulfillment `38209632265`. The subsequent documentation commits `5985ec58`, `10e24cad`, `d143dd7f`, and `e3f24a21` also passed all three workflows (latest: `38209670216`, `38209670195`, `38209670175`). Added `tests/queue-worker-concurrency-integration.php`, wired into the MySQL integration workflow before callback tests. It uses two forked PHP processes with independent PDO connections, holds the first claim lock briefly, and asserts each process claims a distinct ready job with its own lease token. The initial failure was caused by an inherited parent PDO socket across `pcntl_fork()`; commit `94f806c` closes the connection before forking, and `5632ac5657` releases the parent statement before the fork. The latest fulfillment workflow `38213945487` passed, including `Test concurrent queue claims against MySQL`; PHP quality `38213945851` and syntax `38213945489` also passed. Commit `5a168b8` extends `tests/duplicate-queue-processing-integration.php` to verify both sides of the freshness rule: fresh `PROCESSING` returns `IGNORED`; an 11-minute-old ambiguous supplier request becomes order `MANUAL_REVIEW` and supplier `REVIEW`, without another provider call. All three workflows passed for this change (quality `38236928788`, syntax `38236928777`, fulfillment `38236928790`), and all three passed again after documentation updates (quality `38236974932`, syntax `38236974892`, fulfillment `38236974895`). This does not yet test the exact 10-minute boundary against real HTTP timeout duration and cron overlap. Do not auto-resubmit stale supplier requests. |
| Supplier fulfillment | Supplier adapters use HTTPS, timeouts, no redirects, and conservative manual review for ambiguous outcomes. Definitive rejection preserves payment=PAID and creates a single pending refund record under the order lock. Added isolated tests for account mapping and provider response normalization. | Run the new mapping test in CI, then verify provider API payload/signature/status semantics with sandbox credentials and known test accounts. Never treat an HTTP timeout as a definitive rejection. |
| Refunds | Refund records and idempotent creation for supplier failure exist; admin can filter latest refund status. | **No automatic customer refund is certified by this audit.** Confirm gateway refund capability and implement/verify an authorized refund action or complete refunds manually through the approved gateway process. Reconcile the gateway reference and refund status before closing an order. |
| Admin security | Session regeneration after login, CSRF checks, password hash verification, DB-backed throttling, output escaping, and audit logs are present in inspected paths. Regression tests now cover valid CSRF tokens and reject missing, malformed, mismatched, or absent-session tokens; the new test is included in the existing `tests/admin-authorization.php` workflow step. A shared `admin_can_manage_settings()` role policy now guards both Drive-folder changes and game-provider mapping changes; regression tests cover `SUPER_ADMIN`, `ADMIN`, `OPERATOR`, missing/empty roles, and case sensitivity. Related commits: `76fc5ad`, `a9b8c4c`, `da72aa0`, `b13b7ad`, `6134f52`, `a6a15d6`. | Verify every admin route and mutation in a configured runtime; test secure cookie behavior behind the actual reverse proxy, CSRF rejection, login throttling and permissions for each role. Expanded CSRF regression cases passed quality, syntax, and fulfillment workflows in runs `38210544538`, `38210544554`, and `38210544547`. |
| Google Drive settlement | Export and folder configuration scaffolding exist and are disabled by default. | Configure OAuth on the server and test create/upload, duplicate prevention, interrupted upload recovery, permissions, and independent database restore. |
| CI and tests | GitHub Actions lints PHP files and runs callback-state, checkout-fingerprint, supplier-mapping, and admin-authorization tests. Run `38201655600` showed lint and all four test steps successful; overall workflow was still `in_progress` at the last check due to the final post-job step. | Recheck the final workflow conclusion. The separate `fulfillment-tests.yml` workflow includes MySQL 8.4 integration cases for duplicate callbacks, queue recovery, ambiguous supplier outcomes, explicit rejection/refund state, and duplicate queue processing. Run `38202791455` completed successfully with both the PHP tests and all MySQL integration steps passing after the bootstrap path fix. The follow-up code/test change (`e221f01`, `7908e11`) adds recovery for a crash after supplier failure is persisted but before the refund record is created; both jobs passed in run `38203732917`. Commit `30c0735` adds conditional writes to stop a late supplier response from overriding stale-lock recovery; all three workflows passed. Later atomic supplier-response persistence, worker lease revocation, preflight schema coverage, and CSRF regression changes also passed their documented workflows. Latest docs commits `7a227ed9` and `f632ce18` passed quality, syntax, and fulfillment runs (`38210555764`, `38210555798`, `38210555794`; `38210566728`, `38210566658`, `38210566561`). Live provider calls are not run by these workflows. |
| Deployment and production | Preflight and queue-health CLI diagnostics exist. | **Blocked for production** until all staging tests, restore rehearsal, API reconciliation, cron monitoring, and incident/manual-review procedures pass. |

## Preflight schema coverage

`bin/preflight.php` now checks the recorded foundation migration `001_shared_hosting_foundation` and additional core tables (`audit_logs`, `provider_products`, `product_variants`, `settings`, `banners`, `webhook_events`, `account_validations`) in addition to the previously checked tables. If `SETTLEMENT_BACKUP_ENABLED=true`, it also checks `monthly_settlement_exports` and `app_settings` alongside migrations 006–007. This improves missing-schema detection; all three CI workflows passed for the code change (quality `38209632272`, syntax `38209632254`, fulfillment `38209632265`). It does not replace running preflight against the target database.

## Callback result-state rule

- `00`: payment reported successful; the callback may transition a pending payment and enqueue fulfillment once.
- `01`: payment failed; mark the payment/order failed only if the stored transaction is still pending.
- Any other code: reject the callback as unsupported. The separate customer redirect result codes are not used to update database payment state.

Duplicate callbacks for a transaction already in a non-pending state are acknowledged without repeating the payment/order transition.

## Staging exit checklist

- [ ] Apply all migrations 001–010 in order to a clean test database.
- [x] PHP quality checks and PHP/JavaScript syntax checks succeeded for commit `455499a` (runs `38202791491`, `38202791510`).
- [x] Fulfillment safety tests passed all PHP and MySQL integration steps for commit `455499a` (run `38202791455`).
- [x] Verify crash-window refund recovery (`e221f01`) and its integration fixture (`7908e11`): run `38203732917` passed both PHP and MySQL integration jobs.
- [x] Verify CI for the late supplier-response/stale-lock guard (`30c0735`): PHP quality, syntax, and fulfillment workflows all passed.
- [x] Verify CI for atomic supplier response persistence and consistent lock ordering (`3cf9eaf`, `b039424`): quality, syntax, and fulfillment workflows passed (runs `38204439650`, `38204439603`, `38204439553`).
- [x] Verify worker lease finalization guard and revoked-lease regression test (`957d310`, `5240068`, `3c99f14`, `5595378`): PHP tests and MySQL integration including the revoked-lease step passed in run `38209568044`.
- [x] Verify expanded preflight schema coverage (`33a32ee`): quality, syntax, and fulfillment workflows all passed (`38209632272`, `38209632254`, `38209632265`).
- [x] Verify expanded admin CSRF regression test in CI (`b371a0cb`; runs `38210544538`, `38210544554`, `38210544547`).
- [x] Add a MySQL-backed concurrent queue-claim regression test to CI; latest fulfillment run `38213945487` passed the concurrency test, alongside the PHP quality and syntax workflows (`38213945851`, `38213945489`). Commit `5a168b8` extends the duplicate queue test to assert that an 11-minute-old ambiguous supplier request enters manual review without resubmission; all three workflows passed (`38236928788`, `38236928777`, `38236928790`). Exact-boundary behavior against real HTTP timeout duration and cron overlap still needs staging verification.
- [ ] Test callback signature failures, wrong order/amount, duplicate callback, and pending-to-paid transition.
- [x] Verify `tests/checkout-idempotency-concurrency-integration.php` in MySQL CI (run `38240603363`): concurrent same-key requests create one order/payment, invoke the mocked gateway once, reuse the persisted URL, and reject a changed account payload.
- [x] Verify `tests/checkout-deadlock-classification-integration.php` against MySQL 8.4 (fulfillment run `38242948715`): it induces a real deadlock and asserts exactly one 1213 victim is classified for winner recovery while the other transaction commits. Unit coverage excludes SQLSTATE `23000` foreign-key violations (`24c0a59`). This does not yet prove full `checkout_create_payment()` recovery after that exact deadlock; add a controlled full-path test before production.
- [ ] Reuse one idempotency key with changed product/account data and verify the existing payment URL is not returned.
- [x] Verify the two-worker queue-claim integration test in CI (`38213945487`); test full worker execution and the exact 10-minute freshness boundary under controlled concurrent runs remains pending.
- [ ] Test provider success, explicit rejection, pending status, timeout, malformed response, and stale worker lock.
- [ ] Verify uncertain supplier outcomes go to manual review and are never blindly resubmitted.
- [ ] Verify a supplier rejection creates only one refund record and that the actual customer refund is independently reconciled.
- [ ] Verify product availability at reserve-balance boundaries and lowest-price selection across both providers.
- [ ] Test backup upload, duplicate-month handling, interrupted recovery, and database restore.
- [ ] Verify admin login throttling, roles, CSRF, secure cookies, and audit logs.
- [ ] Confirm cron schedule, non-overlap, queue health alerts, log rotation, and a manual recovery runbook.
- [ ] Keep live Duitku payments, supplier orders, and Drive backup disabled until the applicable checks pass.

## Safety defaults

Keep `CHECKOUT_PAYMENTS_ENABLED=false`, `SUPPLIER_ORDERS_ENABLED=false`, and `SETTLEMENT_BACKUP_ENABLED=false` until staging evidence is recorded. A successful syntax check is not proof that external APIs, database transactions, or production hosting work correctly.


## Admin session cookie proxy trust review (2026-10-12)

The admin session helper no longer trusts `X-Forwarded-Proto: https` unless the deployment explicitly sets `ADMIN_TRUST_PROXY_HTTPS=true`. This reduces reliance on a client-spoofable forwarded header. Direct HTTPS detection through the server's `HTTPS` variable remains supported. The environment example defaults the option to false; enable it only when the reverse proxy is trusted and configured to overwrite/strip incoming forwarded-proto headers. Regression coverage was added to `tests/admin-authorization.php`. PHP quality (`38244155564`) and syntax (`38244155580`) passed for commit `022fe0052b`; fulfillment (`38244155610`) was pending at the last check. Verify the final CI conclusion and confirm cookie `Secure` behavior on the actual hosting/proxy before production.

The real MySQL deadlock classification integration passed in fulfillment run `38242948715`; it validates MySQL error classification, not the full `checkout_create_payment()` recovery path. A deterministic full-path regression test remains outstanding.


## Duitku failed-callback state consistency (2026-10-12)

- Audit found the definitive `resultCode=01` callback marked `payment_transactions.status=FAILED` and `orders.payment_status=FAILED` but left a still-pending `orders.order_status=PENDING` behind. Commit `e9ffef5` now also transitions order status from `PENDING` to `FAILED`, without overwriting later/non-pending order states.
- Added a second disposable pending order fixture to `.github/workflows/fulfillment-tests.yml` and extended `tests/duitku_callback_integration.sh` (commit `dabd808`) to send a valid signed `01` callback and assert payment/order both become `FAILED` and no fulfillment job is added. This is test-only fixture data; no live API calls.
- Verification complete: after correcting shell variable expansion in commit `649d3e2cd1`, fulfillment run `38246227164` passed both `mysql-callback-integration` and `php-tests`. The follow-up documentation commit `7e7723095e` passed PHP quality (`38246259533`), syntax (`38246259565`), and fulfillment (`38246259539`). The MySQL test confirms the signed `resultCode=01` callback sets payment and still-pending order to `FAILED`, adds no fulfillment job, and duplicate successful callbacks enqueue exactly once. No live gateway/supplier calls were made. Keep live payment/supplier flags disabled.


Integration-test fixture correction: commit `649d3e2` fixes shell variable expansion in the failed-callback test (`tests/duitku_callback_integration.sh`). The latest CI triggered by this correction is pending; verify the MySQL integration job before considering the regression covered.


## Verification update — 2026-10-12

The failed Duitku callback state-consistency regression is now verified. Commit `649d3e2cd1` corrected variable expansion in the shell integration fixture. Fulfillment run `38246227164` passed both MySQL callback integration and PHP tests. Follow-up documentation commit `7e7723095e` passed all three workflows: quality `38246259533`, syntax `38246259565`, and fulfillment `38246259539`. Full `checkout_create_payment()` recovery after an induced deadlock remains a separate test gap; do not mistake the existing deadlock-classification test for full-path proof. Production remains blocked pending staging checks, and all live transaction flags stay disabled.


## Additional Duitku callback rejection coverage (2026-10-12)

Commit `fc7b8788890d6de2` extends the MySQL callback integration script with two negative cases: invalid HMAC signature and validly signed callback with an amount that does not match the payment row. Each must return HTTP 400 and preserve pending payment/order state without enqueuing fulfillment. The cases are verified in CI for commit `fc7b8788890d6de2c62f231b9016d67a39c30765`: PHP quality run `38251844581`, syntax run `38251844521`, and fulfillment run `38251844517` all passed. The fulfillment run's MySQL integration job completed successfully. No live API calls were made.


Verification update: callback rejection coverage from commit `fc7b8788890d6de2c62f231b9016d67a39c30765` passed all three CI workflows (quality `38251844581`, syntax `38251844521`, fulfillment `38251844517`). The MySQL integration step confirms invalid HMAC and mismatched amount return HTTP 400 while leaving the payment/order pending and adding no fulfillment job; the duplicate-success and failed-payment callback assertions also pass. Full-path deadlock recovery in `checkout_create_payment()` remains an open production-readiness test gap.


## Checkout concurrency regression strengthened (2026-10-13)

Commits `93a13db9ac` and `f7508904ed` modify `tests/checkout-idempotency-concurrency-integration.php` to start both same-key checkout workers without the previous 300 ms stagger, using a shared filesystem gate to release them together. Assertions remain: exactly one order, one payment, one mocked Duitku request, both callers receive the same order/payment URL, and mismatched payload reuse is rejected. CI is pending; verify the MySQL integration job before considering this test change green. This stress test increases race coverage but does not alone prove that error 1213 was triggered within `checkout_create_payment()`; full-path deadlock recovery remains unproven until the deadlock is observed deterministically. No live gateway/supplier calls; live transaction flags remain disabled.


## MySQL checkout race stress run (2026-10-13)

- CI now executes `tests/checkout-idempotency-concurrency-integration.php` three times with independent random fixtures on each run, using the synchronized start gate and mocked Duitku only (workflow commit `bb8549257b9b35bf5214152c50905192e6ab05a4`). This raises the chance of catching intermittent concurrency regressions while asserting one order/payment, one gateway mock call, the same persisted payment URL for both callers, and rejection of changed payload reuse.
- This is stress coverage, not deterministic proof that `checkout_create_payment()` itself receives MySQL error 1213 and completes winner recovery. The separate deadlock test proves real MySQL classification only. Keep the full-path deadlock-recovery gap open until a deterministic integration test observes that exact sequence. Await CI for the new workflow change; do not claim it passed yet. No live gateway or supplier API calls were made.


CI finding for the three-iteration stress test: the first run passed twice and then exposed an order-dependent assertion: the test assumed child process 1 always wins, which is not guaranteed by OS scheduling. Commit `d518075b5d8b3b8bfa5c4b8f284eb4d072a24813` changes assertions to require exactly one creator and one reuser regardless of which child wins. This is a test-harness correction, not evidence of a checkout application failure. Re-run CI before marking the stress test green. Full-path deadlock recovery remains unproven.


## MySQL checkout race verification update (2026-10-13)

- The scheduling-independent checkout race test passed in all three workflows for commit `4e33bf5dcd08`: PHP quality `38283808180`, syntax `38283808125`, and fulfillment safety `38283808131`. The fulfillment run's MySQL integration job passed, including all three checkout iterations and the existing real-deadlock classification integration.
- Follow-up commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` strengthens the checkout stress step: it snapshots MySQL's global `Innodb_deadlocks` counter, runs the three concurrent checkout iterations (mock Duitku only), then requires the counter to increase. Because the checkout integration assertions also require both calls to succeed, exactly one gateway call, one order/payment, and the same persisted URL, a passing run will provide direct evidence that at least one real InnoDB deadlock occurred during the checkout stress window and the checkout flow recovered. The separate classifier test still verifies the 1213 classification independently.
- This new assertion is not yet verified; wait for CI. If no deadlock occurs in the three runs, the step intentionally fails rather than falsely claiming full-path recovery coverage. No live API calls or deployment; production flags remain disabled.


## MySQL checkout race verification update (2026-10-13)

- The scheduling-independent checkout race test passed in all three workflows for commit `4e33bf5dcd08`: PHP quality `38283808180`, syntax `38283808125`, and fulfillment safety `38283808131`. The fulfillment run's MySQL integration job passed, including all three checkout iterations and the existing real-deadlock classification integration.
- Follow-up commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` strengthens the checkout stress step: it snapshots MySQL's global `Innodb_deadlocks` counter, runs the three concurrent checkout iterations (mock Duitku only), then requires the counter to increase. Because the checkout integration assertions also require both calls to succeed, exactly one gateway call, one order/payment, and the same persisted URL, a passing run will provide direct evidence that at least one real InnoDB deadlock occurred during the checkout stress window and the checkout flow recovered. The separate classifier test still verifies the 1213 classification independently.
- This new assertion is not yet verified; wait for CI. If no deadlock occurs in the three runs, the step intentionally fails rather than falsely claiming full-path recovery coverage. No live API calls or deployment; production flags remain disabled.


### Checkout deadlock stress result (2026-10-13)

The checkout stress CI for commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` passed all three concurrent idempotency iterations and the PHP test job, but MySQL's global `Innodb_deadlocks` counter stayed at 0 (before=0, after=0). This means the stress did not observe a real deadlock during `checkout_create_payment()`, so full-path deadlock recovery is still **not verified**. Commit `7113cdae9c22007fcf5b90db523b03354aef2960` changes the workflow to report the absence of a deadlock rather than failing otherwise-passing stress coverage. The independent deadlock integration test only verifies error classification and must not be counted as proof of the complete checkout recovery path. Next: attempt deterministic deadlock choreography in the checkout integration or leave this gap clearly documented. No live gateway/supplier calls; production flags remain disabled.


### CI after making deadlock observation non-fatal (2026-10-13)

Commit `7113cdae9c22007fcf5b90db523b03354aef2960` passed PHP quality (`38315071804`), PHP/JS syntax (`38315071913`), and fulfillment safety (`38315071794`). Both the PHP tests and MySQL integration job passed, including the three concurrent checkout idempotency runs. The stress window did not observe a real deadlock, so the workflow now reports that limitation without falsely failing the unrelated stress checks. Follow-up documentation commit `3fe14f4ca3a04f5a686b61fd5efaab6708c9e34a` also passed syntax (`38315106885`), quality (`38315106999`), and fulfillment (`38315106585`). Full-path recovery from a real deadlock inside `checkout_create_payment()` remains an open verification gap; the independent deadlock-classification test does not close it. No live API calls or deployment; production flags remain disabled.


### Deterministic checkout deadlock test added — awaiting CI (2026-10-13)

Commit `58ee5b048800c490538ad04cb754b58befc680a0` strengthens `tests/checkout-idempotency-concurrency-integration.php` with a parent transaction that locks the test product row before releasing both checkout workers. It waits until `performance_schema.data_lock_waits` shows both workers blocked by that lock, then releases it to encourage the absent-key gap-lock/insert-intention deadlock inside `checkout_create_payment()`. The test requires both calls to complete successfully, one order/payment, one mock Duitku call, shared winner URL, and an increased `Innodb_deadlocks` counter. This is a test strategy, not yet evidence of success; wait for the MySQL integration job. If it fails to provoke a deadlock, inspect logs and adjust. No live gateway/supplier calls or deployment; production flags remain disabled.


## Checkout deadlock choreography result (2026-10-13)

The deterministic test attempt in commit `58ee5b048800c490538ad04cb754b58befc680a0` failed its intended deadlock assertion: MySQL's global `Innodb_deadlocks` counter remained `0 -> 0`. The test did observe both checkout workers waiting on the parent-held product row, but releasing it did not trigger a deadlock. This is a failed test strategy, not proof that the checkout implementation is defective; equally, it is not proof of full-path recovery. Commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac` reverts that test file to the previously passing synchronized checkout idempotency stress test. CI for the revert is pending. The independent deadlock integration still verifies only error classification and remains insufficient to certify complete `checkout_create_payment()` recovery after error 1213. Keep production checkout/supplier flags disabled until this gap is either verified with a deterministic test or explicitly accepted as an outstanding risk. No live APIs or deployment.


### Verification after reverting deadlock choreography (2026-10-13)

The restored checkout idempotency stress test in commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac` passed PHP quality (`38319787900`), syntax (`38319788215`), and fulfillment safety (`38319788002`). Both PHP tests and the MySQL integration job passed. This confirms the prior same-key concurrency/idempotency assertions, not full-path recovery after a real deadlock inside `checkout_create_payment()`; keep that item open. The docs-only follow-up commit `ef5e72894d4ffa7bcc51ca2c577a356102f4511e` passed quality (`38319827522`) and syntax (`38319827634`); fulfillment run `38319827341` was still in progress at last check. No live payment/supplier API calls or deployment.


## Deterministic checkout deadlock integration attempt (2026-10-13)

Commits `69b4cd0e3105d5fa061c34e16e42d08f36a0b01c` and `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` introduce a test-only callback seam guarded by `APP_ENV=test` and use it to make two same-key checkout transactions lock the seeded DIGI and VIPAY rows in opposite order. Both workers synchronize after taking their first row lock, then request the second row inside `checkout_create_payment()`; the MySQL test asserts a real increase in `Innodb_deadlocks`, successful recovery for both requests, one order/payment, one mock Duitku call, URL reuse, and changed-payload rejection. CI is pending. This is only a test strategy until the MySQL job passes and logs confirm the intended deadlock counter increase. No live API calls/deployment; production payment and supplier flags remain disabled.


### Deadlock test harness fix (2026-10-13)

The first MySQL CI attempt for `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` failed at the test's post-run counter query because the parent PDO was intentionally unset before forking. The deadlock counter was never checked, so this run provides no evidence for or against full-path recovery. Commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reconnects before querying the counter; CI is pending. The deterministic deadlock and recovery assertions remain unverified until the corrected MySQL job passes.


### Direct 1213 evidence for checkout deadlock recovery (2026-10-13)

The MySQL attempt for commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reached the deadlock-counter assertion but showed `before=0 after=0`; this counter query did not provide usable proof of a real deadlock. Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` changes the test to capture the actual exception at each worker's second opposing provider-row lock and require exactly one driver code 1213 classified for idempotency recovery. It also continues to require both callers to succeed, one order/payment, one mock Duitku call, shared URL and mismatched-payload rejection. Workflow commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` removes the global-counter inference and runs the integration three times. This is pending CI; full-path recovery remains unverified until the MySQL job passes.


## Full-path checkout deadlock recovery verified (2026-10-13)

Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` passed PHP quality (`38321635015`), syntax (`38321635052`), and fulfillment safety (`38321635067`). The MySQL integration job passed all three concurrent checkout iterations. The test records the actual exception from the opposing provider-row lock attempts inside `checkout_create_payment()` and asserts exactly one driver code 1213 victim is classified by `checkout_is_idempotency_race()`; both checkout calls then have to succeed with exactly one order/payment, one mock gateway call, the same persisted URL, and changed-payload rejection. This verifies the full-path recovery branch in the MySQL integration environment.

The older global `Innodb_deadlocks` status read stayed `0 -> 0` and was not a reliable indicator here; workflow commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` removes that inference and relies on direct error evidence. Its combined MySQL job was still in progress at the last poll. Commit `c58719a03166cedf4537df373b18c2501282e1d9` makes the successful assertion explicit in test output and is awaiting CI. This remains mock-only integration evidence, not live gateway/provider certification. Production payment/supplier flags stay disabled pending staging exit checks.


## Checkout gateway timeout retry safety — verified in mock MySQL integration (2026-10-13)

Commit `808f17e33d0baa2d3c19a60b9deea8586b9ba2d4` makes same-key checkout retries fail promptly for admin review when the existing order has `last_error` set but no saved gateway URL. This avoids the previous 22-second polling delay and, more importantly, prevents a second external payment-create request after an ambiguous timeout. It deliberately does not assume the gateway failed to create a payment.

Commit `a94f8449b333466a33aea585f558a145fd49eb4b` adds an integration scenario using a mock gateway that throws after the database transaction commits. Assertions cover a single mock call, immediate retry rejection, one order/payment, no fabricated URL, and a persisted error marker. All workflows passed: PHP quality `38326198802`, PHP/JavaScript syntax `38326198808`, and fulfillment safety `38326198783`; the fulfillment workflow's MySQL integration and PHP test jobs both completed successfully.

**Remaining operational gap:** the error marker intentionally routes an ambiguous payment attempt to manual review. Before production, admin procedures/tooling must reconcile the merchant order ID with Duitku and determine whether a payment exists before any replacement attempt. Do not blindly retry, mark the payment failed, refund, or create a second payment based only on a client timeout. No live API calls or deployment were performed; production checkout and supplier flags remain disabled.


## Admin checkout reconciliation hints — verified (2026-10-13)

Commit `2e6efab01a524af252bc27c78bed61ab59fe6cd5` adds a read-only “Catatan checkout” column to the admin transaction list. It displays whether a Duitku payment URL is saved, the recorded expiry timestamp, and the escaped `orders.last_error` text marked as needing review. The actual payment URL is not exposed and no state-changing reconciliation controls were added.

CI for the code commit passed: PHP quality `38329325146`, PHP/JS syntax `38329325150`, and fulfillment safety `38329325168`; both jobs in the fulfillment workflow (`php-tests` and `mysql-callback-integration`) completed successfully. The documentation follow-ups also passed both fulfillment jobs: handoff run `38329275031` and audit run `38329284829`.

**Remaining gap:** this UI is evidence display, not reconciliation. A missing URL and a timeout marker do not establish whether Duitku created a payment. Before production, document a controlled procedure to query/confirm gateway status using the merchant order ID, record the operator and evidence, and only then decide whether a new payment attempt is safe. Do not automatically retry, mark failed, or refund an ambiguous payment. Verify current official Duitku API documentation before implementing status queries; no live API calls or deployment were performed. Production checkout and supplier flags remain disabled.


## Duitku manual reconciliation research (2026-10-13)

The official [Duitku API reference](https://docs.duitku.com/api/id/) documents the merchant transaction status endpoint at `/webapi/api/merchant/transactionStatus`. Development uses `https://sandbox.duitku.com/webapi/api/merchant/transactionStatus`; production uses `https://passport.duitku.com/webapi/api/merchant/transactionStatus`. The current “Cek Transaksi” section specifies POST fields `merchantCode`, `merchantOrderId`, and an HMAC-SHA256 signature over `merchantCode + merchantOrderId` using the API key. Some older Duitku pages show MD5; implement only against the current API reference and verify with sandbox before production.

**Critical status-code distinction:** current status-query response codes mean `00=Success`, `01=Process`, `02=Failed/Expired`. The existing callback mapping where `01=FAILED` is for callback payloads, not status-query responses. Do not share the callback mapper with a status-query parser. Duitku also warns that repeated automatic status lookups can trigger rate limiting and block requests for roughly an hour; do not build a cron poller.

Proposed safe operator flow for an ambiguous checkout timeout:
1. Use the exact local `merchant_order_id`; never use the customer redirect query as authoritative status.
2. Query status only through a deliberate operator action, using server-side credentials and no credential exposure in browser output or logs.
3. Require exact response `merchantOrderId` match and numeric amount equality with local `payment_transactions.amount`; validate response shape and allowed status code.
4. Record the operator, timestamp, query outcome, reference, status and mismatch/error evidence in an audit log with secrets redacted.
5. `01=Process`, network errors, invalid/malformed responses, unknown status, or any ID/amount mismatch stay in manual review; no replacement payment and no order-state mutation.
6. `00=Success` should be reconciled through the same verified, idempotent payment transition/fulfillment-enqueue logic as a trusted callback—not by directly editing the order row.
7. `02=Failed/Expired` can only inform a controlled resolution after matching response evidence and rechecking current DB state inside a transaction; never override a later PAID/QUEUED/PROCESSING/SUCCESS state. Replacement attempts must be separately audited and only allowed after proving no payable transaction remains active.

Implementation has not yet started. Next code change should add a small status-query service with strict response validation and unit tests, then a CSRF-protected admin action with role enforcement, rate-conscious manual invocation, audit logging, and no direct status mutation. Add mock-only integration tests for duplicate callbacks/races. No live Duitku calls or deployment occurred; checkout and supplier flags remain disabled.


### Duitku transaction-status validation helpers — verified (2026-10-13)

The code now contains three pure helpers in `app/services/duitku.php`: `duitku_transaction_status_signature()` (current HMAC-SHA256 formula), `duitku_transaction_status_state()` (separate status-query mapping), and `duitku_validate_transaction_status_response()` (exact order ID/amount matching, allowed-code validation, and response-shape checks). They do not perform network requests or mutate payment/order state. Unit tests in `tests/duitku-transaction-status.php` cover valid signature/mappings and reject unknown codes, order/amount mismatches, non-integer amounts, invalid references, and missing API keys.

CI passed on commit `8e6e46497107`: PHP quality `38331891216`, PHP/JS syntax `38331891186`, and fulfillment safety `38331891209`; both fulfillment jobs passed, including MySQL integration. No live gateway calls were made.

**Next:** before adding a transport method, reconcile the current Duitku docs' content-type inconsistency: the “Cek Transaksi” section labels the request x-www-form-urlencoded while its examples show a JSON body. Then implement a single-request, server-side status lookup, sandbox-only verification, CSRF-protected privileged admin action, and durable audit record. Do not implement automatic polling. A validated response alone does not authorize direct state mutation; paid status must use the existing idempotent callback transition path. Production flags remain disabled.


## Admin-triggered Duitku status reconciliation — verified (2026-10-13)

Added `public/admin/reconcile.php` and a privileged-admin link from `public/admin/index.php`. The action requires a valid admin session, a CSRF token, and the existing `ADMIN`/`SUPER_ADMIN` role gate. It performs one operator-triggered lookup for a locally stored Duitku merchant order ID, refuses non-integer rupiah amounts, and throttles repeated lookup attempts for the same payment transaction to at least 60 seconds using audit-log history.

The status lookup is sandbox-only. `duitku_status_config()` requires merchant code/API key and a sandbox `DUITKU_ENV`; `duitku_query_transaction_status()` is pinned to the exact sandbox transaction-status URL. A test-only transport seam is permitted only when `APP_ENV=test`. The route validates exact response order ID and amount, records a `DUITKU_STATUS_LOOKUP` audit event with normalized status/reference or a sanitized error, and **never changes payment/order status, enqueues fulfillment, or creates a replacement payment**.

CI passed on test commit `05d9f1ee497b`: PHP quality `38334462332`, syntax `38334462340`, and fulfillment `38334462306`; the fulfillment run passed both PHP and MySQL jobs. The preceding endpoint refactor's fulfillment run `38334449650` also passed.

**Duitku documentation caveat:** the current [API reference](https://docs.duitku.com/api/id/) labels “Cek Transaksi” as x-www-form-urlencoded but its included PHP and cURL examples serialize JSON and set `Content-Type: application/json`. This implementation follows the concrete examples, not the conflicting label. It has not been tested against Duitku sandbox yet; perform that verification only after sandbox credentials are configured and before considering production. The current documented signature is HMAC-SHA256 over `merchantCode + merchantOrderId`; MD5 is obsolete. Status-query codes remain separate from callback codes: `00=SUCCESS`, `01=PROCESS`, `02=FAILED_OR_EXPIRED`.

**Open production gap:** a validated `SUCCESS` result is displayed and audited but does not automatically reconcile the payment through the existing callback's idempotent state-transition path. This page is read-only evidence gathering. Do not manually edit statuses, trigger supplier fulfillment, or refund based only on its result. No live API calls or deployment; production checkout and supplier-order flags remain disabled.


### Open issue: status lookup cooldown is not atomic

The current 60-second throttle in `public/admin/reconcile.php` checks recent audit history before the HTTP request, but does not atomically reserve the lookup. Two simultaneous admin POST requests could both observe no recent row and each call the sandbox endpoint. This is a known limitation, not a concurrency-safe rate limiter. Before production or live Duitku use, serialize admission with a short DB transaction that locks the `payment_transactions` row and commits a `STARTED` audit/lease record before network I/O, or create a dedicated unique lease row. Do not hold the transaction during the network request. Add a concurrent-request integration test asserting one outbound call. Current implementation is sandbox-only and no live calls have been made.


### Atomic status-lookup admission — implementation added (2026-10-13)

The reconciliation route now serializes admission attempts by locking the matching `payment_transactions` row with `SELECT ... FOR UPDATE`, checking the last 60 seconds of lookup audit records while holding that lock, and committing a `STARTED` audit event before network I/O. The transaction is released before calling Duitku. A concurrent request for the same payment should therefore observe the committed marker and be rejected without making a second status-query request. No payment/order state is changed.

This closes the race in the prior check-then-call implementation at the code level. However, no dedicated concurrent HTTP/MySQL integration test for this route exists yet; current passing workflows do not independently prove simultaneous status lookup admission. Add a focused two-process test before treating the throttle as production-certified. Sandbox only, no live requests/deployment.


### Cooldown rejection audit fix (2026-10-13)

Commit `b8d7fa77e1d2363a561775ce7bbe0b7675e2db9c` fixes a throttle edge case in `public/admin/reconcile.php`: a request rejected because a lookup already occurred within 60 seconds no longer writes another `DUITKU_STATUS_LOOKUP` error event. Previously, repeated rejected clicks could refresh the audit timestamp and extend the cooldown indefinitely. Genuine lookup attempts that fail after admission still record an error. The atomic row-lock/STARTED-marker approach remains in place, and no payment/order state is changed. This change has been committed to GitHub; wait for CI and add a dedicated concurrent MySQL/web-route regression test before calling the throttle production-verified. No live Duitku request or deployment was performed.


### Cooldown regression guard added to CI (2026-10-13)

Added `tests/reconcile-cooldown-regression.php` and wired it into `.github/workflows/php-quality.yml`. The structural regression test checks that the route retains the payment-row lock, cooldown audit query, committed `STARTED` marker before network I/O, and the guard that suppresses duplicate audit rows for cooldown-denied clicks. This protects the discovered bug against accidental reintroduction. It is explicitly a structural check, not proof of simultaneous-request behavior; the dedicated two-process MySQL/web-route integration test remains required. Commits: test `cce70d749ba6f809a3a2e582f7deeb99dde184e1`; CI workflow `61ad26239ba960b59e5c379c2de254a226c56b83`. No live Duitku call or deployment.


### CI result — cooldown regression guard (2026-10-13)

PHP quality workflow run [38340379209](https://github.com/sulistio95/PluQ-I/actions/runs/38340379209) completed successfully on commit `78e58e8f67ea133d39f36acfc6d245f8956adc2a`. It passed PHP lint, Duitku callback-state mapping, transaction-status validation, the new admin cooldown structural regression guard, checkout request fingerprint, supplier response mapping, and admin authorization tests. This confirms the new structural test is wired correctly and passes in CI; it does not replace the outstanding concurrent MySQL/web-route integration test.


### Shared atomic reconciliation admission and concurrent MySQL test added (2026-10-13)

- Extracted the row-lock + 60-second cooldown + committed `STARTED` audit-marker transaction into `app/services/duitku_reconcile.php`. `public/admin/reconcile.php` calls this helper before any gateway request; a dedicated `DuitkuReconcileCooldownException` marks rejected clicks so they do not create a second lookup audit row.
- Added `tests/reconcile-cooldown-concurrency-integration.php`: two independent PHP processes race for the same disposable MySQL payment fixture. The intended assertion is exactly one admitted attempt, one cooldown rejection, and one recent audit marker. It does not call Duitku or any external API.
- Wired the integration test to run three times in the MySQL 8.4 fulfillment workflow and adjusted the structural regression test to assert the route uses the shared helper.
- Relevant commits: helper `ad24834b92ff8fb6887e7e8450b43b026c709b64`; route integration `1efda2505449740b070f7054ab9c472cd83184f8`, explicit exception `746fb1496499abb7b0d5acfd1ef564351187fc2b`, route exception handling `bcc94ea9d08e8bd98c74ca6322f35b562c54c030`; concurrency test `fa8ab44e4390033513db60fc2747e09a8bcfe143`; structural test `804aa234151e808611c59501b0baca63b97183ec` and ordering correction `8c7d050ca2e68e33f0068b952e7affe1ee6872a9`; workflow `bb740f156bcabda93ac315d60939351eb4ae9f99`.
- **Verification pending:** this test has been committed to CI but its runtime result has not yet been inspected. Do not mark concurrency behavior verified until the MySQL workflow passes all three attempts. It tests the shared admission helper, not two authenticated HTTP requests end-to-end. No live Duitku request, supplier order, production payment, or deployment was performed.


### Reconciliation concurrency CI failure investigated (2026-10-13)

- First run of the new MySQL concurrency test failed after the two workers completed: the parent process reused its PDO/MySQL socket after `pcntl_fork()`, and MySQL reported `server has gone away` when the test queried the final audit count. This is a test harness connection-lifecycle problem, not yet evidence of a cooldown logic failure.
- Fixed `tests/reconcile-cooldown-concurrency-integration.php` to close the parent PDO before forking and establish a fresh parent connection after both children exit. Commit: `8844027fd6cf3290163a3a6cffa21672e4389d62`.
- Syntax workflow passed on the previous commit; the fulfillment workflow failed specifically at the new concurrency integration step. The corrected run is pending; concurrency behavior remains unverified until it passes.
### Concurrent Duitku reconciliation admission — MySQL CI verified (2026-10-13)

The corrected concurrent admission integration test now passes. Fulfillment run [38347691175](https://github.com/sulistio95/PluQ-I/actions/runs/38347691175) completed successfully on commit `2ca838957423caa407fb49524ff23e16eff41723`; its `mysql-callback-integration` and `php-tests` jobs both passed, including all three concurrent reconciliation admission attempts. PHP quality [38347691176](https://github.com/sulistio95/PluQ-I/actions/runs/38347691176) and PHP/JavaScript syntax [38347691179](https://github.com/sulistio95/PluQ-I/actions/runs/38347691179) also passed. The follow-up runs on documentation commit `ddea8e3a5e3ea6080f8716c1a7b389bc0f4e0cf8) also passed: fulfillment `38347687156`, quality `38347687161`, syntax `38347687166`.

This is evidence that two independent PHP processes competing against the same MySQL payment row yield one admitted lookup, one cooldown rejection, and one audit marker in the integration harness. It does not yet test two authenticated HTTP requests through the admin page. No external Duitku request, production transaction, supplier order, or deployment occurred; production checkout/supplier flags remain disabled.

**Next audit focus:** determine whether a test-only HTTP-level admin reconciliation test can safely exercise authentication, CSRF, role checks, and duplicate POST admission without network access. Keep external transport mocked and ensure the route itself cannot be tricked into contacting a live endpoint.
### Reconciliation route security regression guard expanded (2026-10-13)

Commit `5877fa6e1fbd75bea0fe812c8e8a56ad4fecd738` extends `tests/reconcile-cooldown-regression.php` to guard the admin route's CSRF validation, privileged-role check, and read-only boundary (no payment/order UPDATE, checkout creation, or fulfillment call). This is a structural regression guard only; it does not simulate a browser session or replace an authenticated HTTP integration test. CI was triggered by the code change and must be checked before treating the guard as verified. No live gateway calls or deployment.
### Admin reconciliation HTTP boundary integration test added (2026-10-13)

Commits `77a1e0ed67460c84cc14eeea6252937fe5d9f4a8`, `b9e59514989c200e7f73b22c3e2d9fc365518655`, and `136f11118aa181a44a533fae93017dcb746e6ddf` add `tests/reconcile-admin-http-integration.php` and wire it into the MySQL CI workflow after the local PHP server starts. The test creates disposable admin/operator accounts and tests unauthenticated redirect, OPERATOR 403, invalid-CSRF POST 403, unknown POST action 400, and authorized ADMIN form render 200. It never submits a valid status-lookup action and therefore makes no Duitku request. Test-created admin rows are removed in a `finally` block. This expands coverage from source-structure checks to actual HTTP/session/role/CSRF boundaries, but still does not test duplicate valid HTTP status lookup requests or the external transport. CI is pending; do not call the new test verified until the workflow passes.
### CI runner anomaly after adding HTTP boundary test (2026-10-13)

The first workflow attempts after adding `tests/reconcile-admin-http-integration.php` reported failures across syntax, PHP quality, and fulfillment workflows within seconds, but the GitHub Actions job API returned no step summaries (`steps: null`, no log URL) and the job-log endpoint returned 404. This does not identify a test assertion or code failure and is being treated as an infrastructure/runner anomaly until logs or a rerun provide evidence. Failed jobs were requested for rerun on runs [38394970823](https://github.com/sulistio95/PluQ-I/actions/runs/38394970823), [38394970881](https://github.com/sulistio95/PluQ-I/actions/runs/38394970881), and [38394970906](https://github.com/sulistio95/PluQ-I/actions/runs/38394970906). Do not mark the new HTTP test verified until rerun results are available. The test script's first 50 lines were also syntax-checked locally with PHP 8.4; the full CI PHP 8.2 run remains authoritative.
### Follow-up CI attempts still fail before exposing job steps (2026-10-13)

The reruns of the three failed workflow runs also failed within seconds, and new runs triggered by commit `24d6bce9215add73efbfa6a13703c2f661011bd8` likewise failed immediately: fulfillment [38395199538](https://github.com/sulistio95/PluQ-I/actions/runs/38395199538), PHP quality [38395199540](https://github.com/sulistio95/PluQ-I/actions/runs/38395199540), syntax [38395199525](https://github.com/sulistio95/PluQ-I/actions/runs/38395199525). Their job payloads have no step summaries and no log URLs, so the failure happens before any identifiable test step and cannot currently be attributed to the new test code. Prior known-green CI remains the last usable verification of the existing code; the newly added HTTP integration test remains **unverified**. Investigate GitHub Actions runner/repository execution availability or retry when logs are available. Do not claim a green build.


### CI runner failure remains outside visible test steps (2026-10-13, latest check)

Checked the newest workflow runs on commit `fd167feb7018cecd25365275654acdb973f6066b`: fulfillment run `38395277059` and PHP quality run `38395277049` both completed as failures within seconds. The job API reports completed/failure but `steps: null` and `logs_url: null`; earlier syntax run `38395199525` has the same pre-step pattern. Since unrelated workflows fail before any step is exposed, this is not evidence that the new HTTP test itself failed. The root cause remains unconfirmed; inspect repository Actions availability, account billing/minute limits, and runner provisioning before changing application logic. Do not mark CI green or production-ready.

The HTTP boundary test source was reviewed against `admin_auth.php` and the schema: it creates disposable ADMIN/OPERATOR users, exercises anonymous/role/CSRF/unknown-action/read-only-form HTTP paths, and cleans up fixtures in `finally`. It intentionally never submits the valid Duitku lookup action, so it must not call the gateway. Runtime execution remains unverified until the CI runner issue is resolved.


Follow-up after recording this note: the note-only commits also triggered all three workflows, and all failed immediately without visible steps: commit `309c6b8b060ad4c10a20af77b0609162cbc06f6a` (runs `38395658550`, `38395658689`, `38395658589`) and commit `26aa6b64ee5f71acd1c34de9bbe9adc007e311e6` (runs `38395668065`, `38395668156`, `38395668061`). This further supports investigating Actions execution/billing/runner provisioning rather than attributing the failure to a particular test step; root cause is still not established.


### External comparison: failures match the known pre-runner symptom pattern (2026-10-13)

A current GitHub Community discussion dated 2026-09-28 describes the same broad symptom pattern in another repository: Actions jobs fail before the first step, no runner is assigned, and no job logs exist. This supports treating the PluQ-I failures as likely occurring in runner provisioning/orchestration rather than assuming a PHP/YAML test failure; it does **not** prove the same root cause here. Reference: https://github.com/orgs/community/discussions/209001. GitHub's billing documentation also confirms Actions usage/budget can be relevant for private repositories: https://docs.github.com/en/billing/concepts/product-billing/github-actions.

Next checks that require repository-owner UI access: (1) repository Settings → Actions → General, ensure Actions and required actions are allowed; (2) account Settings → Billing & licensing / Budgets and alerts, check payment status, included minutes and spending limit; (3) check https://www.githubstatus.com/ for an active incident; (4) if settings and billing are normal, contact GitHub Support with the failed run URLs and job IDs. No workflow source changes were made in this investigation because the jobs have not reached a visible step. Do not mark any new test as passed.


After this investigation note was committed, the three workflows again failed immediately on commit `851fddb169713d605360c2e49b71cdb87571e16b`: fulfillment `38397679614`, PHP quality `38397679661`, and syntax `38397679602` (2026-10-13 12:29 UTC). This repeats the same pre-step symptom; no root cause is confirmed and no test is marked green.


### Latest continuation check — workflow source and run metadata (2026-10-13)

The current head commit `2d559672eb132c4211eff93d529b749097fc54c8` triggered another set of immediate failures: fulfillment [38397710451](https://github.com/sulistio95/PluQ-I/actions/runs/38397710451), PHP quality [38397710416](https://github.com/sulistio95/PluQ-I/actions/runs/38397710416), and PHP/JavaScript syntax [38397710358](https://github.com/sulistio95/PluQ-I/actions/runs/38397710358). The newest fulfillment job records still have `steps: null` and `logs_url: null`; retrieving logs is unavailable (404). Reviewed the three checked-in workflow YAML files: each uses the ordinary `runs-on: ubuntu-latest` job pattern and normal setup/test steps; no obvious malformed runner declaration was found in the inspected content. The HTTP test source also remains a conventional PHP/cURL/MySQL integration test, but cannot be certified without runtime execution. This reinforces that diagnosis is blocked before visible steps, without proving a GitHub platform cause. No application changes were made; next action still requires owner-side Actions/billing/status checks or GitHub Support. Keep the new HTTP test unverified and do not enable live payment/supplier processing.


### Controlled retry of newest syntax workflow (2026-10-13)

Requested one rerun of syntax workflow [38399998833](https://github.com/sulistio95/PluQ-I/actions/runs/38399998833) to determine whether the failure was transient. Attempt 2 also ended in failure (updated 2026-10-13 12:52:43Z); the same commit's PHP quality run [38399998813](https://github.com/sulistio95/PluQ-I/actions/runs/38399998813) and fulfillment run [38399998667](https://github.com/sulistio95/PluQ-I/actions/runs/38399998667) failed immediately on attempt 1. The retry did not restore CI. No code or workflow changes were made. Root cause remains unknown; test execution is not certified and production remains blocked pending owner-side Actions/billing/platform investigation.


### Latest continuation — Actions metadata rechecked (2026-10-13)

- Rechecked the newest workflow runs triggered by handoff commit `a202647f6295ed4292637dc56a5961f7a0246bda`: PHP quality [38400685887](https://github.com/sulistio95/PluQ-I/actions/runs/38400685887) and fulfillment [38400685767](https://github.com/sulistio95/PluQ-I/actions/runs/38400685767) both failed within about four seconds.
- Job metadata remains opaque: quality job `115307218283`, fulfillment jobs `115307218758` and `115307219224` report `steps: null` and `logs_url: null`. This still does not show any application test executing and does not identify a root cause.
- This result repeats on a note-only commit, so do not attribute it to the new HTTP integration test without evidence. No further rerun or speculative workflow edit was made.
- Required owner-side checks remain: repository Settings → Actions → General; account Billing & licensing / Budgets and alerts; GitHub Status; then GitHub Support if all are normal. Runtime verification of the HTTP integration test remains pending. No live gateway/provider calls or deployment.


### Continuation review — latest Actions runs and admin route (2026-10-14)

- Rechecked the newest workflows after the prior handoff update. PHP quality [38401404982](https://github.com/sulistio95/PluQ-I/actions/runs/38401404982), fulfillment [38401405037](https://github.com/sulistio95/PluQ-I/actions/runs/38401405037), and syntax [38401405068](https://github.com/sulistio95/PluQ-I/actions/runs/38401405068) all failed immediately. Job steps/logs remain unavailable, so the test code is not shown to have executed.
- Re-read `tests/reconcile-admin-http-integration.php`, `public/admin/reconcile.php`, `app/services/duitku_reconcile.php`, `app/services/admin_auth.php`, and the fulfillment workflow. The HTTP test's session cookie name/path matches the admin session setup; it tests role/CSRF/request-boundary behavior and deliberately does not submit a real lookup. The admission helper commits its STARTED audit marker before any outbound request and releases the transaction before network I/O.
- No clear source-level defect was established in this review; no speculative code change was made. The HTTP test still requires runtime execution in CI/staging before it can be marked passed.
- Next blocker remains owner-side GitHub Actions settings/billing/status investigation or GitHub Support. No live gateway/provider calls, supplier fulfillment, or deployment.


### CI check after latest documentation commits (2026-10-14)

- Audit-only commit `5faf902970f9aaff42b75d22ea7448ea6c638e15` triggered PHP quality run [38468671002](https://github.com/sulistio95/PluQ-I/actions/runs/38468671002), which failed with job `steps: null` and `logs_url: null`.
- Handoff commit `f33f062705e451820aa5828266d62ff32f13d8dc` triggered syntax [38468686551](https://github.com/sulistio95/PluQ-I/actions/runs/38468686551), fulfillment [38468686528](https://github.com/sulistio95/PluQ-I/actions/runs/38468686528), and PHP quality [38468686565](https://github.com/sulistio95/PluQ-I/actions/runs/38468686565); all three ended in failure, with no visible steps/logs. This is consistent with the existing pre-step failure pattern, not evidence of a failing test assertion.
- No code change was made. The admin reconciliation HTTP integration test remains runtime-unverified; owner-side Actions settings, billing/budget and GitHub Status checks remain necessary. Live payment/provider calls and deployment remain disabled.


### Checkout idempotency source review — 2026-10-14

- Re-read `app/services/order_checkout.php`, `app/services/checkout_quote.php`, `public/create-payment.php`, migration 003, migration 010, and the checkout fingerprint/concurrency integration tests.
- Confirmed in source: `orders.checkout_key` is uniquely indexed; the key is stored as a SHA-256 digest; request fingerprints bind normalized email, game slug, product ID, and normalized account fields; a key reused with changed payload is rejected; recovery after duplicate-key/deadlock/lock-timeout rolls back and re-reads the winner without calling Duitku again; ambiguous gateway errors are marked for manual review and retries do not dispatch a second gateway request.
- The quote revalidates game/product eligibility, provider reserve balance, excluded services, cheapest matching offer, and configured account fields before inserting the order.
- No definite source-level defect was established in this review. The existing MySQL integration test covers same-key concurrent requests, one mocked gateway call, URL reuse, changed-payload rejection, real deadlock classification, and ambiguous gateway timeout. These checks are **not newly runtime-verified in this session** because GitHub Actions still fails before exposing steps/logs.
- Latest Actions runs for commit `213cccdd7e5db035ee0d3985884d0a64d12330f8`: PHP quality [38468745359](https://github.com/sulistio95/PluQ-I/actions/runs/38468745359) and fulfillment [38468745412](https://github.com/sulistio95/PluQ-I/actions/runs/38468745412) failed within seconds; job steps/logs remain unavailable. Root cause is still unknown.
- No source edits, live Duitku/provider calls, or deployment were performed. Keep production payment/provider flags disabled until CI is restored and the integration tests run successfully.


### Callback and queue lease source review — 2026-10-14

- Re-read `public/duitku-callback.php`, `app/services/duitku.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and callback signature tests.
- Callback source verifies the Duitku signature, validates the 26-character order identifier and integer amount, locks the matching Duitku payment row, and treats non-`PENDING` callbacks as idempotent acknowledgements. Fulfillment is queued only when the callback successfully transitions the order from pending to paid.
- Queue finalization verifies `PROCESSING` plus the same lock token while holding the queue row lock; a stale worker returns `LOCK_LOST` without finalizing. Supplier ambiguity routes to manual review instead of blind resubmission.
- No definite source-level defect was established in this targeted review. Callback/queue integration behavior is not newly runtime-verified because current GitHub Actions runs expose no steps/logs.
- The checkout review was recorded in audit commit `f620f2f49f44f9ce6e3726dc2f87d0dfb59fef56`; current CI blocker remains unresolved. Keep live payment/supplier processing and deployment disabled until workflows can execute and staging verification passes.


## Provider HTTP transport and admin reconciliation review — 2026-10-14

- Reviewed `app/services/provider_http.php`: outbound JSON requests require HTTPS, verify TLS peer/host, disable redirects, bound connect/overall timeouts, reject non-2xx responses and malformed/non-object JSON, and avoid logging request bodies or credential-bearing headers. No live network request was made.
- Reviewed `app/services/duitku.php` and `app/services/duitku_reconcile.php`: status lookup is sandbox-only, uses a separate status-query signature, validates the returned merchant order ID and amount, and does not mutate payment/order state. The admission helper locks the local payment row and commits a STARTED audit marker before network I/O; repeated lookups are limited by a 60-second audit-backed cooldown.
- Reviewed `public/admin/reconcile.php`, `public/admin/game-mappings.php`, `public/admin/index.php`, and `app/services/admin_auth.php`: reconciliation and mapping mutations require an authenticated privileged role, POST requests require CSRF validation, mapping writes and audit records share a transaction, and user-controlled display values are escaped. No definite source-level vulnerability was established in this pass.
- These are source-review findings only; no PHP/MySQL test was executed during this pass. Current CI execution status must be checked separately; existing failures without visible steps/logs cannot be treated as application-test failures or as passing verification.
- No code was changed, no live Duitku/provider call was made, and no deployment occurred. Keep checkout, supplier-order execution, and settlement backup disabled until CI and controlled staging checks are available.


### GitHub Actions follow-up — 2026-10-14

- The documentation-only handoff update triggered PHP/JavaScript syntax workflow run [38472583538](https://github.com/sulistio95/PluQ-I/actions/runs/38472583538), which failed in seconds. GitHub's job metadata reports `steps: null` and `logs_url: null`; no test/lint step output is available, so this does not establish that application checks failed.
- Root cause remains undetermined. Do not change application code or workflow definitions speculatively based on this result. Repository owner should check Actions availability/settings, billing/budgets, GitHub Status, and contact GitHub Support if those are normal.


## Admin login throttle review — 2026-10-14

Reviewed admin login and database-backed rate limiting. A successful login currently clears failed-attempt rows more broadly than the single IP-and-username pair involved, which can erase rate-limit history for unrelated attempts. Proposed a narrow correction and attempted to update the source, but the repository write was blocked; no code change has been committed. This finding remains open. No runtime tests were run, and CI still needs visible job steps/logs before test results can be trusted.


## Admin login throttle cleanup correction — 2026-10-14

- Corrected `admin_login_clear_failures()` in commit `6d0e2f24b4397d0f48148ee4e7bf3e1e0e9a8a77`: successful login now deletes failed attempts only when both the IP address and normalized username hash match. It no longer clears unrelated rows merely because either field matches.
- Added `tests/admin-login-throttle-clear.php` in commit `8e624e68e1c97572aec71268a15ac7e548d9d3d9`, checking the conjunction and bound values; wired it into `.github/workflows/php-quality.yml` in commit `664e9883ce7241c519dc0affb91949503f0054f2`.
- The quality workflow triggered for the test-wiring commit failed before exposing any steps/logs (run [38474440334](https://github.com/sulistio95/PluQ-I/actions/runs/38474440334)); this does not establish whether the new regression test passes. A local PHP 8.4 harness using the committed predicate and regression assertions passed; because this environment lacks mbstring and does not contain a full checkout, a compatibility shim was used for the ASCII test username. This is not a run of the repository test file itself, and CI/runtime verification remains pending.
- No live gateway/provider calls or deployment were performed. Keep live processing disabled until CI is observable and staging verification passes.


## Queue worker and fulfillment follow-up — 2026-10-14

- Re-reviewed `bin/worker-queue.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and queue/fulfillment integration tests. The worker claims jobs transactionally with a per-claim random token; expired leases are not blindly requeued; recovery locks queue → order → supplier; finalization checks the current token; uncertain supplier outcomes enter manual review; definitive failures are persisted separately from payment status and refund creation is idempotent by order lookup. No additional definite source defect was established in this pass.
- No database-backed runtime tests were run here; this is source review only.
- GitHub Actions continues to fail immediately without visible steps/logs across all three workflows for the current documentation commits: syntax [38474672764](https://github.com/sulistio95/PluQ-I/actions/runs/38474672764), PHP quality [38474672798](https://github.com/sulistio95/PluQ-I/actions/runs/38474672798), and fulfillment [38474672805](https://github.com/sulistio95/PluQ-I/actions/runs/38474672805). This still does not show application test failures; root cause remains external/undetermined.
- Do not enable live supplier orders, live payments, or deployment until CI produces inspectable logs and staging checks pass.


## Supplier retry duplicate-order guard — 2026-10-14

- Found a fail-closed gap in `fulfillment_process()`: a VIPayment supplier transaction marked `RETRYING` without a saved provider `trxid` could fall through to the order-creation API path. If the earlier request had reached VIPayment but its reference was not persisted, this could create a duplicate top-up.
- Added `fulfillment_supplier_state_requires_review()` and routed ambiguous supplier states through manual review. VIPayment `RETRYING` is allowed to continue only when a non-empty provider reference exists for status lookup; Digiflazz `RETRYING` remains blocked from automatic resubmission. Source commit: [9930da7](https://github.com/sulistio95/PluQ-I/commit/9930da74ac4d1d8f49e86add191811f4745064dd).
- Added regression cases in `tests/fulfillment-retry-safety-test.php` (commit [99cfd7e](https://github.com/sulistio95/PluQ-I/commit/99cfd7ebaa150051ef6676cd2ed2b2a42ee0542e)) and wired the test into the fulfillment workflow (commit [4af58c0](https://github.com/sulistio95/PluQ-I/commit/4af58c07616cf8b64989ce5d6f9b01872a46e72f)).
- Runtime/CI verification is pending: GitHub Actions has recently failed before exposing steps/logs, so the new test is committed but not yet verified by CI. No live supplier request or deployment was performed. Keep live supplier/payment flags disabled until workflow output and staging checks are available.


### CI status after supplier retry guard — 2026-10-14

- Latest runs on handoff commit `942ca0ef7e83662cc57f72bcf4cee90e72f2cdc6`: syntax [38480851988](https://github.com/sulistio95/PluQ-I/actions/runs/38480851988) and PHP quality [38480851789](https://github.com/sulistio95/PluQ-I/actions/runs/38480851789) failed without exposed step/log metadata; fulfillment [38480851797](https://github.com/sulistio95/PluQ-I/actions/runs/38480851797) also ended with both jobs failed and `steps: null`, `logs_url: null`. These results still do not identify an application test failure or establish why Actions exits early.
- The newly added retry-safety test is therefore committed but not CI-verified. Investigate repository/account Actions availability before making speculative workflow changes. No live requests or deployment.


## Digiflazz balance response validation — 2026-10-14

- Found that `digiflazz_balance()` only checked whether `data.deposit` existed, then cast it to float. Malformed strings such as `not-a-number` can cast to zero and be recorded as a real low balance, obscuring a provider response problem.
- Added `digiflazz_map_balance_response()` to require a numeric, finite, non-negative balance before returning it. Source commit: [9040192](https://github.com/sulistio95/PluQ-I/commit/9040192a403aa3afffb43eb6c448fc1774806c04).
- Added regression coverage for numeric strings, missing/malformed values, negative values, and non-finite strings in [5dcad4e](https://github.com/sulistio95/PluQ-I/commit/5dcad4ed2e83dbb631b6f0facd0841c54d6cc1b7); wired it into the fulfillment workflow in [c7b34f7](https://github.com/sulistio95/PluQ-I/commit/c7b34f7832be06c44be38fa425c9b8b809e1d1ce).
- Verification remains pending until GitHub Actions exposes runnable steps/logs. No live supplier API call or deployment performed.


### CI check after Digiflazz balance guard — 2026-10-14

- Runs on the latest note commit `421e31d0fbfcf79f193b12d24d2bc80f3af0ef94`: fulfillment [38482250710](https://github.com/sulistio95/PluQ-I/actions/runs/38482250710), PHP quality [38482250685](https://github.com/sulistio95/PluQ-I/actions/runs/38482250685), and syntax [38482250681](https://github.com/sulistio95/PluQ-I/actions/runs/38482250681) all reported job failures with `steps: null` and `logs_url: null`. No actionable test failure or root cause is exposed.
- Treat both newly added regression tests as unverified until Actions provides step-level results or the suite is run in a controlled local/staging environment.


## VIPayment balance response validation — 2026-10-14

- Follow-up review found `vipayment_profile()` accepted any `is_numeric()` value and returned its float conversion without checking finiteness or non-negativity. The balance-sync caller also rejects invalid floats, so this was defense-in-depth at the adapter boundary rather than evidence of an incorrect persisted balance.
- Added `vipayment_map_profile_response()` to reject missing, malformed, negative, and non-finite balances before returning profile data. Source commit: [2893932](https://github.com/sulistio95/PluQ-I/commit/2893932819fa8cafa3a1bf5905103df2bddd9675).
- Added `tests/vipayment-balance-validation-test.php` for numeric-string acceptance and missing/malformed/negative/overflow/non-finite rejection (commit [955fd57](https://github.com/sulistio95/PluQ-I/commit/955fd57aeb228e4f0d1b6213049e61cebdd0cdfa)); wired it into `.github/workflows/fulfillment-tests.yml` (commit [40e8bf3](https://github.com/sulistio95/PluQ-I/commit/40e8bf341e12d19a4307ec340826328ec7416c23)).
- Tests are committed but not yet verified by execution. Recent GitHub Actions runs still return failed jobs without step summaries/log URLs; root cause remains unknown. No live supplier/payment request or deployment was performed. Keep production payment and supplier-order flags disabled until tests are observable and staging checks pass.


### GitHub Actions runner allocation evidence — 2026-10-14

- Inspected the GitHub Actions REST job records for the latest syntax, PHP quality, and fulfillment runs on commit `d690c7828117183ca43d1b6cff9c08052c4a0340`. The jobs were created and marked failed within roughly 1–7 seconds, with `steps: []`, `runner_id: 0`, and no `started_at`/`completed_at` step records. This narrows the observable failure to before normal runner-step execution; it does not identify the cause.
- The repository Actions permissions endpoint was not accessible through the current GitHub connector, so account/repository Actions settings, billing/usage limits, policy restrictions, and GitHub service status remain unverified. Do not rewrite workflow definitions without evidence. Owner should inspect repository **Settings → Actions → General**, account billing/Actions usage, and GitHub Status; then rerun workflows and verify step logs.
- No source change or runtime test is claimed from this diagnostic pass. Keep payment/supplier processing disabled and deployment paused until actual test steps execute and pass.


## Duitku status amount integer-overflow hardening — 2026-10-14

- Source review found that transaction-status amount validation cast an arbitrary digit string to PHP `int` before comparing. Oversized decimal strings can saturate at `PHP_INT_MAX`; if the expected amount were also `PHP_INT_MAX`, a larger amount could be mistaken for an exact match.
- Updated `duitku_validate_transaction_status_response()` to compare normalized decimal strings instead of casting untrusted amount text: [source commit b6bba9d](https://github.com/sulistio95/PluQ-I/commit/b6bba9d5e3c5e2f9c708ef5904b52d06cb0b9662).
- Extended `tests/duitku-transaction-status.php` with oversized amounts, leading-zero mismatch, and a direct regression where an amount larger than `PHP_INT_MAX` must not match `PHP_INT_MAX`: [test commit 12042e3](https://github.com/sulistio95/PluQ-I/commit/12042e37603638481ce23cddeed87c4b8ac3601a).
- This is a source/test change only; the regression test has **not** been executed in this session. GitHub Actions still needs inspectable step logs before CI can be considered verified. No live Duitku/provider call or deployment; keep live processing disabled.


### CI result after Duitku overflow regression — 2026-10-14

- The note commit triggered syntax run [38485787043](https://github.com/sulistio95/PluQ-I/actions/runs/38485787043) and fulfillment run [38485787071](https://github.com/sulistio95/PluQ-I/actions/runs/38485787071); both failed immediately. Job records show `steps: null` and `logs_url: null` (jobs `115598975273`, `115598975592`, and `115598975726`). This still does not establish whether lint/tests ran or failed; root cause remains unknown.
- The integer-overflow fix and regression case are committed, but **not runtime-verified**. Restore inspectable Actions execution or run the suite in a controlled PHP 8.2+/MySQL environment before treating checks as green. Keep production payment/supplier execution disabled and do not deploy.


### Further CI log retrieval attempt — 2026-10-14

- Queried step summaries for failed jobs `115598975273`, `115598975592`, and `115598975726`; GitHub returned empty step arrays for all three. Attempted to retrieve their logs, but the log artifacts returned `BlobNotFound`. Thus there is still no evidence that lint or tests executed, and no actionable assertion/runner error is exposed by the available job records.
- Continued read-only review of Duitku callback, checkout idempotency, order status, and supplier recovery paths; no additional source change was made because the observed terminal-callback/order-state edge cases require a database-backed integration test and explicit reconciliation policy before safely changing payment states. Do not silently change a terminal payment to PAID based only on a conflicting callback.
- Next safe step is to restore actionable CI logs or execute the existing PHP/MySQL integration tests in a controlled environment. No live API calls or deployment.


## Contradictory terminal Duitku callback handling — 2026-10-14

- Source review confirmed the callback previously acknowledged every non-\`PENDING\` payment row without recording a contradictory signed result. That made a late \`00\` after stored \`FAILED\` (or \`01\` after stored \`PAID\`) invisible to operators.
- Updated \`public/duitku-callback.php\` in commit [9008996](https://github.com/sulistio95/PluQ-I/commit/9008996d5000da74b1fdd0dc0ed0006586582b26): preserve the existing terminal payment status; mark the order \`MANUAL_REVIEW\`; record an \`audit_logs\` event with stored/incoming state, amount, order ID and gateway reference. The callback still returns an acknowledgement to avoid endless gateway retries, but it never silently flips a terminal payment or creates a second fulfillment job.
- Added a MySQL-backed regression case to \`tests/duitku_callback_integration.sh\` in commit [8454a51](https://github.com/sulistio95/PluQ-I/commit/8454a51937c35ca4616403ae51b8b187d174f5cc): a success callback after a stored failure must keep payment \`FAILED\`, set order \`MANUAL_REVIEW\`, create one audit event, and not enqueue fulfillment again.
- This is committed source and test coverage, **not a test pass**. GitHub Actions currently fails before exposing steps/logs; the integration script has not been executed in this pass. Verify it on the MySQL 8.4 CI job once runner execution is restored. No live Duitku/provider request or deployment was performed; keep live processing disabled.


### Idempotent audit logging for repeated callback conflicts — 2026-10-14

- Follow-up review found that the terminal-conflict guard preserved payment state correctly but would append a new audit row on every retry of the same contradictory signed callback. Since gateways may retry callbacks, that could create noisy duplicate audit events.
- Updated `public/duitku-callback.php` in [commit 363d985](https://github.com/sulistio95/PluQ-I/commit/363d98545e872207cf68f95259e4915bfdff47ec): under the existing payment-row lock, check for an existing conflict audit event with the same order and stored/incoming payment states before inserting. The original terminal payment remains unchanged and the order remains flagged for manual review.
- Extended `tests/duitku_callback_integration.sh` in [commit 44dd349](https://github.com/sulistio95/PluQ-I/commit/44dd34915ab3679c8a73acce6ccd43a10205e7dc) to submit the same conflicting callback twice before asserting there is still exactly one audit event and no duplicate fulfillment job.
- Verification: **not runtime-tested yet**. Latest workflow runs for the previous note commit fail almost immediately with no steps or runner assignment visible. New commits may trigger fresh runs; inspect their job records before drawing conclusions. No live gateway/provider requests or deployment were made.


### CI status after repeat-callback audit deduplication — 2026-10-14

- Fresh runs triggered by the code and note commits again failed before exposing step-level execution: [fulfillment 38493159152](https://github.com/sulistio95/PluQ-I/actions/runs/38493159152), [PHP quality 38493159289](https://github.com/sulistio95/PluQ-I/actions/runs/38493159289), and [syntax 38493159116](https://github.com/sulistio95/PluQ-I/actions/runs/38493159116). Job records have no usable log URL; this does not reveal a PHP assertion or syntax failure.
- The latest callback audit-deduplication change and repeated-callback regression are committed but remain **unverified at runtime**. The runner/Actions configuration issue remains the next blocking item; do not treat these runs as test results.


## Hosting and real-payment readiness review — 2026-10-14

- Added [staging/payment test runbook](staging-payment-test-runbook.md) in commit [4b418ad](https://github.com/sulistio95/PluQ-I/commit/4b418ada3fc5b7c9a0d9423e2942c25d1dda5ac0).
- Source review confirms `app/services/duitku.php` deliberately rejects any `DUITKU_ENV` other than `sandbox`; therefore a real production Duitku mode is **not implemented** and must not be activated by changing an environment variable alone.
- Source review also confirms `checkout_payments_enabled()` requires both `CHECKOUT_PAYMENTS_ENABLED=true` and `SUPPLIER_ORDERS_ENABLED=true`. This blocks isolated sandbox checkout testing without enabling the supplier-order gate. The runbook records the required safe separation and acceptance criteria before testing.
- Latest GitHub Actions job metadata shows runner ID 0 and empty step arrays, with no usable logs. Cause remains unknown; this is not evidence that a particular application test failed. No CI pass is claimed.
- No live payment/provider request, supplier purchase, or deployment occurred. Keep both feature flags disabled until sandbox-only payment isolation, observable CI, and staging verification are complete.


## Isolated Duitku sandbox checkout — 2026-10-14

- Added `checkout_sandbox_only_enabled()` and updated the checkout gate in [6dbfa21](https://github.com/sulistio95/PluQ-I/commit/6dbfa21b79eb6b08c77652b72f0276df01461b32). This mode requires `APP_ENV=staging` or `test`, `DUITKU_ENV=sandbox`, `CHECKOUT_PAYMENTS_ENABLED=true`, and `SUPPLIER_ORDERS_ENABLED=false`. Production cannot use the isolated mode.
- Updated the Duitku callback in [7e6dbd7](https://github.com/sulistio95/PluQ-I/commit/7e6dbd7480e305d9e48f6385c2b0333aa065e8b9): a successful payment callback in sandbox-only mode records payment as `PAID`, sets the order to `MANUAL_REVIEW`, records that fulfillment is intentionally disabled, and does not enqueue `FULFILL_ORDER`.
- Expanded checkout gate tests in [e7bef5c](https://github.com/sulistio95/PluQ-I/commit/e7bef5cb8e8555b2579e6c0efb98d199027c69df) and added a MySQL-backed callback integration script in [b878eb3](https://github.com/sulistio95/PluQ-I/commit/b878eb318bf754cd39dccf2c499447334074e8ec). Wired a separate staging-mode callback server and fixture into the fulfillment workflow in [eb3063d](https://github.com/sulistio95/PluQ-I/commit/eb3063d377f3d5d7259f85b50828af79d8466a3a).
- **Not yet runtime-verified.** Runs [38497557866](https://github.com/sulistio95/PluQ-I/actions/runs/38497557866), [38497557852](https://github.com/sulistio95/PluQ-I/actions/runs/38497557852), and [38497557727](https://github.com/sulistio95/PluQ-I/actions/runs/38497557727) are queued at the time of this note. The preceding runs failed before step metadata/logs were exposed; root cause remains unknown.
- No live Duitku payment, supplier purchase, or deployment was performed. Keep production checkout and supplier ordering disabled until workflow execution is visible, all tests pass, and staging is verified. This change does not implement production Duitku mode.


### CI follow-up after sandbox-only change — 2026-10-14

- The new workflow runs [38497627559](https://github.com/sulistio95/PluQ-I/actions/runs/38497627559), [38497627399](https://github.com/sulistio95/PluQ-I/actions/runs/38497627399), and [38497627390](https://github.com/sulistio95/PluQ-I/actions/runs/38497627390) have failed before step summaries were exposed (`steps: null`). This remains the existing GitHub Actions execution blocker; it does not prove the new PHP assertions or MySQL callback integration failed.
- The sandbox-only integration is committed and wired into CI, but no test pass is claimed. Next action remains restoring observable runner execution, then reviewing the actual test output and fixing any confirmed source/test defects.


### GitHub Actions rerun diagnosis — 2026-10-14

- Manually re-ran failed jobs for workflow run [38497627559](https://github.com/sulistio95/PluQ-I/actions/runs/38497627559) (attempt 2). It failed again within about three seconds before any steps began.
- GitHub job metadata again reports `runner_id=0`, blank `runner_name`, and an empty `steps` list; log download returns `BlobNotFound`. This is strong evidence the failure occurs before a runner executes the repository workflow, but the exact account/repository-level cause is not exposed by the available API.
- No code change can responsibly fix an unobserved account-level runner allocation problem. Repository owner should inspect **Settings → Actions → General** and the account's Actions usage/billing/spending limit, and check whether hosted Actions runners are allowed for this private repository. Do not change workflow permissions or enable supplier processing as a workaround.
- The sandbox gate and callback safety changes remain committed; test pass is still unconfirmed until a runner executes the steps.


### Local gate-logic smoke test — 2026-10-14

- Ran a standalone PHP CLI smoke test against the exact sandbox gate conditions for five scenarios: default-off, staging sandbox allowed, production sandbox-only rejected, non-sandbox Duitku rejected in isolated mode, and normal supplier-enabled path preserved. All five assertions passed.
- Scope limitation: this executed a standalone copy of the gate logic because the private repository cannot be cloned into this runtime and GitHub Actions has not started a runner. It is **not** a substitute for `php tests/checkout_gate_test.php`, PHP lint of the committed tree, or the MySQL callback integration workflow.


### Sandbox callback integration assertion correction — 2026-10-14

- Source review found a defect in the new sandbox-only callback integration test: it asserted the entire shared test database contained exactly one `FULFILL_ORDER` job. Earlier integration cases can legitimately create unrelated jobs, making this assertion order-dependent and potentially flaky.
- Corrected the assertion in [fb3623c](https://github.com/sulistio95/PluQ-I/commit/fb3623cfa920afec7e5dc76978b2455064f569b9) to count only `FULFILL_ORDER` jobs whose JSON payload `order_id` matches the sandbox fixture order. Expected count is now zero for that order; unrelated test jobs no longer affect the result.
- This is a test-only correction. It has **not** been executed against MySQL because GitHub Actions has not exposed runner steps/logs. PHP/MySQL runtime result remains unverified; keep live payments, supplier ordering, and deployment disabled.


### CI result after sandbox assertion correction — 2026-10-14

- New workflow runs for the correction, fulfillment [38505439662](https://github.com/sulistio95/PluQ-I/actions/runs/38505439662) and PHP quality [38505439765](https://github.com/sulistio95/PluQ-I/actions/runs/38505439765), completed with failure. Job records expose `steps: null`; no test output was provided. The PHP quality run for the source-test commit [38505408114](https://github.com/sulistio95/PluQ-I/actions/runs/38505408114) shows the same symptom.
- This confirms the runner visibility/allocation blocker persists; it does **not** establish that the assertion change failed. Keep test status unverified and do not enable production payment/supplier flow or deploy.



### Sandbox integration script quoting fix — 2026-10-14

- Follow-up source review found the SQL assertion in `tests/duitku_sandbox_no_fulfillment_integration.sh` was embedded in a shell single-quoted `php -r` program while the SQL also contained single-quoted literals and the JSON path `'$.order_id'`. The shell could strip those quotes before PHP received the code, causing malformed SQL and invalidating the intended integration assertion.
- Replaced the inline `php -r '…'` block with a quoted PHP heredoc (`php <<'PHP'`) so SQL string literals and the JSON path reach PDO unchanged. Change: [acac0af](https://github.com/sulistio95/PluQ-I/commit/acac0afad5b2c735272e0150b9261817e82db4c6).
- This is a source-level fix; the MySQL integration has not yet executed. Latest Actions failures have missing step summaries, so runtime result remains unknown. Keep production payment, supplier fulfillment and deployment disabled.



### CI verification after heredoc correction — 2026-10-14

- Workflows for the heredoc fix are now recorded: [PHP quality 38508784612](https://github.com/sulistio95/PluQ-I/actions/runs/38508784612), [fulfillment safety 38508784598](https://github.com/sulistio95/PluQ-I/actions/runs/38508784598), and [syntax checks 38508784571](https://github.com/sulistio95/PluQ-I/actions/runs/38508784571). Failed jobs still report `steps: null`; the fulfillment PHP test job was queued when checked. No application test output is available yet, so do not interpret these as code-test failures or passes.


### Sandbox test output cleanup and free WordPress deployment assessment — 2026-10-14

- Follow-up review found the sandbox callback integration test's PHP diagnostic strings contained double-escaped newline sequences, which would print literal \\n instead of line breaks. Corrected the test output strings in [9309e7e](https://github.com/sulistio95/PluQ-I/commit/9309e7e2a6d8edc11d836a1b7075ecd7787eac89). This is cosmetic and does not establish a test pass; the MySQL integration remains unexecuted.
- Investigated the suggestion to use the connected WordPress.com account for free deployment testing. The connected WordPress site listing currently returns **zero accessible sites**, so there is no identified site to deploy to. WordPress.com Free also does not provide a general-purpose runtime for this repository's standalone PHP application or plugin installation; custom PHP/plugins and hosting developer features require a plugin-enabled paid plan. Official references: [WordPress.com code restrictions](https://wordpress.com/support/code/) and [plan features](https://wordpress.com/support/plan-features/).
- Therefore, WordPress.com Free cannot currently serve as the PHP/MySQL staging host for PluQ-I. A free WordPress.com site could only be considered for a static/demo presentation, not as proof that the PHP app, PDO database, callback, or queue works. No WordPress site was created or modified. Continue with safe source/CI verification; production payment, supplier ordering and deployment remain disabled.
- GitHub Actions still fails before runner steps are exposed (runner_id=0, empty steps); test results remain unverified.

### Actions check after test-output cleanup and WordPress assessment — 2026-10-14

- Rechecked the three workflows triggered by the note/test changes. Runs [38509769564](https://github.com/sulistio95/PluQ-I/actions/runs/38509769564) (fulfillment), [38509769499](https://github.com/sulistio95/PluQ-I/actions/runs/38509769499) (PHP quality), and [38509769497](https://github.com/sulistio95/PluQ-I/actions/runs/38509769497) (syntax) all ended in failure with job step metadata `null`; no PHP/MySQL test output is available. This matches the runner-before-steps failure pattern, not a confirmed application assertion failure.
- New runs from the latest handoff update are queued: [38509776705](https://github.com/sulistio95/PluQ-I/actions/runs/38509776705), [38509776692](https://github.com/sulistio95/PluQ-I/actions/runs/38509776692), and [38509776675](https://github.com/sulistio95/PluQ-I/actions/runs/38509776675). They are not yet evidence of passing tests.
- Result: source changes and notes are committed, but the sandbox callback test, complete PHP suite, and MySQL integration remain runtime-unverified. Do not enable payment/supplier operations or deploy.


### Additional test-source review while Actions is unavailable — 2026-10-14

- Continued reviewing test code independently of GitHub Actions. Found a second cosmetic diagnostic issue in `tests/checkout_gate_test.php`: its success message had a double-escaped newline and printed a literal `\\n`. Corrected the output string in commit [654bd26](https://github.com/sulistio95/PluQ-I/commit/654bd269d5ba396a555a47abba23ebb06351865a).
- Re-read the sandbox integration script and its workflow wiring. The fixture uses the same public/merchant order ID, payment amount 2500, and callback signature inputs across setup and verification; the isolated server is configured with staging + Duitku sandbox + checkout enabled + supplier orders disabled. The test's assertions explicitly require payment `PAID`, order `MANUAL_REVIEW`, and zero `FULFILL_ORDER` jobs for that order. This is a source-level consistency review only; no PHP/MySQL execution was possible from this check.
- GitHub Actions runner/step issue remains separate; do not treat source review as a passing test. Live payment, supplier fulfillment, and deployment remain disabled.

### Independent test execution attempt and latest Actions status — 2026-10-14

- Attempted to obtain a clean local checkout for independent PHP testing. The available local runtime has PHP 8.4.24, but `git clone https://github.com/sulistio95/PluQ-I.git` failed because this execution environment cannot resolve `github.com` (DNS/network unavailable). Thus the actual committed project tests could not be run locally either.
- After commit [654bd26](https://github.com/sulistio95/PluQ-I/commit/654bd269d5ba396a555a47abba23ebb06351865a), new runs [38511159260](https://github.com/sulistio95/PluQ-I/actions/runs/38511159260) (syntax), [38511159043](https://github.com/sulistio95/PluQ-I/actions/runs/38511159043) (fulfillment), and [38511159024](https://github.com/sulistio95/PluQ-I/actions/runs/38511159024) (PHP quality) also completed as failures. Prior inspection of these runner failures showed `steps: null`, so the workflow status still does not expose application test output.
- Work completed while waiting: source-level consistency review of the sandbox callback integration; corrected only test diagnostic newline formatting in commits [9309e7e](https://github.com/sulistio95/PluQ-I/commit/9309e7e2a6d8edc11d836a1b7075ecd7787eac89) and [654bd26](https://github.com/sulistio95/PluQ-I/commit/654bd269d5ba396a555a47abba23ebb06351865a); updated notes. No passing test is claimed. Payment, supplier fulfillment, and deployment stay disabled until tests run in a real PHP/MySQL environment.

### Queue and provider-balance concurrency audit — 2026-10-14

- Reviewed `app/services/queue_worker.php`, `bin/worker-queue.php`, `app/services/fulfillment.php`, `public/duitku-callback.php`, `app/services/provider_sync.php`, and `app/services/checkout_quote.php` directly from the current `main` branch.
- Queue safety findings: job claiming is performed inside a short InnoDB transaction; each claim gets a random lock token; finalization checks that token before changing the job; expired jobs are moved to manual review instead of blindly requeued because the supplier may already have accepted the request. The worker currently processes jobs serially per invocation, which is conservative for shared hosting but does not provide parallel throughput within a single run.
- **New production blocker — provider balance race:** checkout validates each product against the latest stored balance and configured reserve percentage, but there is no local reservation/commitment ledger for paid orders waiting in the queue. Two or more concurrent orders can each pass the 50% balance rule using the same balance snapshot, so their combined supplier cost can exceed the intended usable balance. This source review does not establish that an actual overspend occurred; it establishes that concurrent commitments are not reserved atomically.
- Next implementation should add an InnoDB-backed provider budget reservation/commitment mechanism, lock the provider row while deciding capacity, and release/settle each reservation idempotently on definitive supplier success/failure or manual reconciliation. Unknown supplier outcomes must retain the reservation until an operator resolves them. Add concurrent MySQL integration tests before enabling supplier ordering.
- This turn made no application-code change because a partial reservation implementation could incorrectly release capacity or double-count orders. This finding has been recorded before starting the schema/service/test change. Runtime tests remain unverified: the latest GitHub Actions runs failed before recording steps, and the repository could not be cloned into this runtime due DNS failure. Keep live checkout, supplier ordering, and deployment disabled.

### Provider budget reservation implementation — 2026-10-14

- Added migration `database/migrations/011_provider_budget_reservations.sql` and service `app/services/provider_budget.php`. The reservation row is unique per order. Capacity decisions lock the provider row and count both `ACTIVE` and `REVIEW` reservations against `balance × reserve_percentage / 100`.
- Duitku's normal successful-payment callback now marks the order paid, attempts the capacity reservation inside the callback DB transaction, and only queues fulfillment after reservation succeeds. If provider capacity is unavailable, the payment remains recorded as paid and the order moves to `MANUAL_REVIEW`; no supplier job is enqueued. Sandbox-only checkout does not reserve or enqueue supplier fulfillment.
- Supplier success resolves an active reservation to `SETTLED`; a definitive supplier failure releases it to `RELEASED` in the refund-review transaction; ambiguous supplier results move it to `REVIEW` and keep consuming capacity until reconciliation. Existing reservation updates are idempotent for terminal rows.
- Added `tests/provider-budget-capacity.php` for exact-boundary, aggregate-commitment, and invalid-number cases, and added it to `.github/workflows/php-quality.yml`.
- **Verification limitation:** these are committed code changes, not yet a verified MySQL concurrency result. The unit test and PHP lint still need a successful runner; an integration test with simultaneous MySQL transactions is still required to prove provider-row locking and reservation uniqueness under contention. Apply migration 011 before deploying this code. Keep live checkout, supplier orders, and deployment disabled.

- Follow-up hardening: `app/services/fulfillment.php` now refuses to call a supplier for paid jobs unless their budget reservation is `ACTIVE`. Missing, released, settled-but-inconsistent, or review reservations move the order to `MANUAL_REVIEW` and block supplier execution. This is a fail-closed guard for old queue rows and partial/misordered deployments.
- Added migration 011 to the MySQL integration workflow and documented it as required in README setup. Current CI run records still show failure before any steps/logs, so the new tests remain unverified.

- Balance-cache race follow-up: successful supplier purchases now remain counted as `SETTLED` commitments until a provider balance sync started after the settlement updates the cached balance. `provider_sync.php` now records the balance-check start time rather than response-write time, so a slow/stale in-flight balance request cannot automatically make a later supplier spend look reflected in that snapshot. This reduces a timing window but still requires staging tests against provider balance consistency.


### Reservation recovery consistency fix — 2026-10-14

- Source audit found a crash-recovery path in `app/services/fulfillment.php`: if the supplier transaction was already persisted as `SUCCESS` but the process crashed before resolving the budget reservation, the next worker marked the order successful without changing the reservation from `ACTIVE` to `SETTLED`.
- Fixed in commit [defd9d5](https://github.com/sulistio95/PluQ-I/commit/defd9d599f8c71b795ac7525addfa44f46a70178). The recovery branch now resolves the reservation to `SETTLED` inside the same DB transaction. This is an idempotent state-recovery fix and keeps the balance ledger consistent.
- Updated staging instructions to include migration 011 in the ordered migration list. Runtime PHP/MySQL tests remain unverified because GitHub Actions has not exposed executed steps/logs. Next priority: diagnose runner availability and add/execute a MySQL contention test proving two concurrent reservations cannot exceed the provider's usable balance. Keep payment/supplier production operations disabled.


### MySQL provider reservation integration coverage added — 2026-10-14

- Added `tests/provider-budget-reservation-mysql-integration.php` in commit [ed2ae8a](https://github.com/sulistio95/PluQ-I/commit/ed2ae8ab9c290408eda293ed64dde1db3fca15db). Against the configured MySQL schema, it creates disposable fixtures and checks that a Rp300,000 commitment blocks a further Rp200,001 against Rp500,000 usable capacity; releasing the first reservation frees capacity; and duplicate reservation calls reuse the single ledger row.
- Wired the test into `.github/workflows/fulfillment-tests.yml` after migration 011 in commit [81aea76](https://github.com/sulistio95/PluQ-I/commit/81aea76c0ff25d0132721fae1680750a9cab0573).
- This is a real-MySQL integration test for aggregate capacity/idempotency, but **not yet a simultaneous multi-connection contention test**. It has not run successfully because current GitHub Actions runs fail before step logs are available. Do not represent this test as passed. Continue investigating CI runner failure and add a genuine concurrent reservation test before enabling supplier orders.


### Concurrent provider budget reservation test added — 2026-10-14

- Extended `tests/provider-budget-reservation-mysql-integration.php` to launch two independent PHP worker processes, each opening its own PDO connection, and release them from a shared filesystem barrier to reserve Rp300,000 against the same provider with Rp500,000 usable capacity.
- Acceptance condition: exactly one concurrent reservation is admitted and the sum of ACTIVE/REVIEW commitments stays at or below Rp500,000. Existing aggregate-capacity, release, and duplicate/idempotency checks remain in the same integration script.
- Follow-up implementation commits: [bf0537f](https://github.com/sulistio95/PluQ-I/commit/bf0537fb17e7eaaed556936718da0e7b5ea707ed) and [47b2924](https://github.com/sulistio95/PluQ-I/commit/47b2924ddd75b915b0814d2627f00b437cd2b39e) (reliable worker output capture).
- **Not verified as passing:** the new GitHub Actions run [38529583687](https://github.com/sulistio95/PluQ-I/actions/runs/38529583687) failed with `steps: null`; no runner/test logs were recorded. Local PHP syntax check of a small process-I/O pattern passed, but that does not execute or validate the repository integration test. Next: restore runner diagnostics, then run the full MySQL 8.4 workflow. Keep checkout payments, supplier ordering, and deployment disabled.

- CI follow-up on 2026-10-14: reran the failed syntax-check job (attempt 2) for workflow run [38529853413](https://github.com/sulistio95/PluQ-I/actions/runs/38529853413); it again completed as failure without recording steps (`steps: null`). The adjacent PHP quality and fulfillment runs also fail immediately in the same way. This points to a runner/job-start issue rather than a reported PHP assertion failure, but the exact cause is still unconfirmed. No application test result is available.


### Stale balance timestamp precision hardening — 2026-10-14

- Changed the settled-reservation cutoff in `app/services/provider_budget.php` from strict `updated_at > last_balance_check_at` to conservative `>=`. MySQL timestamps can have second precision; equal timestamps cannot prove that the balance request began after the purchase. Counting equality may temporarily over-reserve capacity, but avoids incorrectly treating stale cached funds as available.
- Expanded `tests/provider-budget-reservation-mysql-integration.php` with a regression case: a SETTLED Rp300,000 purchase whose reservation timestamp equals the provider balance-check timestamp must still block a Rp200,001 reservation against Rp500,000 capacity.
- Commits: [d61a7c8](https://github.com/sulistio95/PluQ-I/commit/d61a7c89be9ca8c21a78495339746af62efd07de) and [8fb1105](https://github.com/sulistio95/PluQ-I/commit/8fb110597c22ecf6bbd5e4919bb2cc3dfa676961).
- Verification remains blocked: GitHub Actions job [38529853413](https://github.com/sulistio95/PluQ-I/actions/runs/38529853413) has an empty runner name and zero steps; downloading its logs returns `BlobNotFound`. Public status currently reports GitHub systems operational, so the exact per-repository/job failure cause is unconfirmed. Local container cannot resolve `raw.githubusercontent.com`, so repository PHP lint could not be run here. Do not claim the MySQL test passed; keep production checkout/supplier fulfillment disabled.


### Concurrent reservation test synchronization fix — 2026-10-14

- Tightened the race test so each child process writes its own `.ready` marker and waits at the shared barrier. The parent now waits until both independent workers have reached the barrier before releasing them; it fails the test if both do not become ready within 15 seconds. This avoids mistaking two sequential starts for a genuine contention test.
- Renamed the MySQL workflow step to make its full scope explicit: capacity, idempotency, concurrency, and stale-balance timestamp boundary. Commits: [6025cd6](https://github.com/sulistio95/PluQ-I/commit/6025cd6e7be77d3f6d0dbe2e601297a4b5b1009a) and [870daf7](https://github.com/sulistio95/PluQ-I/commit/870daf71c105589a627dd63d8da299c9cd62b899).
- This improves test synchronization but does not constitute a passing result. GitHub Actions still needs to start a runner and record logs before these MySQL assertions can be verified.

- Latest CI check after the synchronization/timestamp fixes: syntax workflow runs [38533061161](https://github.com/sulistio95/PluQ-I/actions/runs/38533061161) and [38533044011](https://github.com/sulistio95/PluQ-I/actions/runs/38533044011) again fail with `steps: null`; no PHP lint or test step ran. Fulfillment/PHP-quality runs for the latest commits were still queued at the time of inspection. This continues to indicate a runner-start/logging blocker, not a reported application assertion failure. Test status remains unverified.

### Stale fulfillment job guard — 2026-10-14

- Source audit found that fulfillment_process() checked payment status and reservation status but did not reject a paid order already in MANUAL_REVIEW before considering supplier execution. A duplicate/stale job paired with an ACTIVE reservation could therefore reach later supplier logic. It also allowed a PROCESSING/RETRYING order with no durable supplier transaction to create a new supplier request record.
- Fixed in [dd980d6](https://github.com/sulistio95/PluQ-I/commit/dd980d6b6d80f2ff82e598f6794e2ec2892178e1): only QUEUED, PROCESSING, and RETRYING paid orders can proceed; MANUAL_REVIEW stays blocked. If an in-flight order lacks its supplier transaction, it is moved to manual review and its capacity reservation is retained as REVIEW, rather than creating a potentially duplicate purchase.
- Expanded tests/duplicate-queue-processing-integration.php in [43b1bf5](https://github.com/sulistio95/PluQ-I/commit/43b1bf5e14434e39094af6926d632dbce46ea145) to seed an ACTIVE reservation and assert that a reviewed order remains reviewed despite a duplicate job.
- Verification pending: GitHub Actions was reported as potentially rate/usage-limited by the user; no new test pass is claimed. When Actions becomes available, run PHP lint and the full MySQL fulfillment integration workflow. Keep payment and supplier production flags disabled.


### Terminal order stale-job regression coverage — 2026-10-15

- Extended `tests/duplicate-queue-processing-integration.php` in commit [3c401d4](https://github.com/sulistio95/PluQ-I/commit/3c401d404f7237ff469800252b32abb703b4e12e).
- After the fixture is moved to `MANUAL_REVIEW`, the test now also changes the same paid order through `FAILED`, `REFUND_PENDING`, and `REFUNDED` while retaining an ACTIVE budget reservation and supplier state `REVIEW`. Each stale-job invocation must return `IGNORED` and preserve both order status and supplier review state.
- This adds source-level regression coverage for the terminal-status allowlist; it does not prove the integration test passed. PHP/MySQL execution remains blocked/unverified until GitHub Actions provides actual runner steps and logs. Do not enable live payments, supplier execution, or deployment.


### Queue error finalization preserves terminal order states — 2026-10-15

- Source audit found that `queue_finalize_job()` could replace an order already marked `FAILED` or `REFUND_PENDING` with `MANUAL_REVIEW` when a validly leased queue job later finalized as `ERROR`/retry exhaustion. This was an unnecessary state overwrite during a race with another order-state transition.
- Fixed in [47f06b9](https://github.com/sulistio95/PluQ-I/commit/47f06b9580b8435e99efcf228780f5db71c4e28f): queue error handling now moves an order to `MANUAL_REVIEW` only if its current status is `QUEUED`, `PROCESSING`, or `RETRYING`. Terminal and already-reviewed states are preserved; the queue job itself still records its failure.
- Added integration regression coverage in [7b3bc13](https://github.com/sulistio95/PluQ-I/commit/7b3bc13f7aeddee9ef21d779a58696f98b07da76) for `FAILED`, `REFUND_PENDING`, `REFUNDED`, and `MANUAL_REVIEW` states.
- Runtime verification is pending: GitHub Actions has previously failed before exposing runner steps. This is a source change plus test coverage, not a passing test result. Keep production payment and supplier flags disabled until the workflow and MySQL integration suite actually pass.

### Supplier-failure transition guard — 2026-10-15

- Source audit found `fulfillment_record_explicit_failure()` previously set any paid order to `FAILED` without checking its current order state. A stale or future direct invocation could therefore overwrite `SUCCESS`, `REFUND_PENDING`, `REFUNDED`, or `MANUAL_REVIEW` while creating/retaining refund work.
- Fixed in commit [4fbef73](https://github.com/sulistio95/PluQ-I/commit/4fbef73bb60eb6111c3701f9c7e666f5d51d6b47): the helper locks and reads `order_status`, permits only `QUEUED`, `PROCESSING`, `RETRYING`, or idempotent `FAILED`, and refuses terminal/reconciliation states. Refund insertion is now additionally constrained to an order that is still `PAID` and `FAILED`.
- Added a regression assertion in [eb30cf4](https://github.com/sulistio95/PluQ-I/commit/eb30cf4d677ee30a5a293b73fda811fb0a4381a1) to ensure a stale supplier-failure signal cannot overwrite `MANUAL_REVIEW`. This is committed source-level test coverage only; it has not been run successfully against PHP/MySQL.
- Runtime verification remains blocked/unverified while GitHub Actions has not provided usable runner steps/logs. Do not report tests as passing or enable live payments/supplier execution. Next audit: inspect admin reconciliation actions for state transitions and durable audit records, then run staging PHP lint and MySQL integration tests when a working runner/runtime is available.

### Rekonsiliasi Duitku — audit konsistensi snapshot status (2026-10-15)

- Audit sumber terbaru menemukan kemungkinan catatan status yang usang di `public/admin/reconcile.php` dan `app/services/duitku_reconcile.php`: halaman membaca status pembayaran/pesanan sebelum pemanggilan layanan, sementara layanan kemudian mengunci baris pembayaran tetapi menulis status dari argumen yang diberikan halaman. Jika callback mengubah status di antara dua langkah itu, entri audit `STARTED` bisa merekam status lama walaupun permintaan gateway tetap bersifat read-only.
- Perbaikan yang dituju: di dalam transaksi penguncian, baca ulang transaksi Duitku beserta status pesanan terkait; cocokkan merchant order ID dan nominal dengan target permintaan; gunakan snapshot terkunci itu untuk audit. Tambahkan regresi tes dengan status argumen yang sengaja usang dan pastikan audit menggunakan status database terkini.
- Perubahan kode belum diterapkan pada catatan ini; upaya pembaruan file kode ditolak oleh pemeriksaan keselamatan alat. Jangan anggap celah ini telah diperbaiki atau tes telah berjalan. Lanjutkan dengan perubahan terfokus melalui jalur GitHub yang diizinkan, kemudian jalankan PHP lint dan tes MySQL ketika runner tersedia.


### Status CI terbaru — kegagalan perlu didiagnosis (2026-10-15)

- Pemeriksaan Actions pada commit audit `7e23197e0ade112e1da7d7e7a7929038b6c005aa` menunjukkan tiga workflow gagal: [PHP lint](https://github.com/sulistio95/PluQ-I/actions/runs/38631882760), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38631882750), dan [Fulfillment safety tests](https://github.com/sulistio95/PluQ-I/actions/runs/38631457568) (termasuk job PHP test dan integrasi callback MySQL).
- Metadata job mengonfirmasi kesimpulan `failure`, tetapi pengambilan log rinci menghasilkan `NOT_FOUND` dan ringkasan langkah tidak tersedia. Penyebab belum terverifikasi; jangan menebak bahwa kegagalan hanya disebabkan runner atau limit.
- Langkah berikut: pulihkan akses log/runner atau jalankan tes di lingkungan PHP 8.2 + MySQL/MariaDB, identifikasi kegagalan pertama, perbaiki sumber atau konfigurasi yang benar-benar terkait, lalu ulangi seluruh workflow.
- Temuan snapshot status rekonsiliasi Duitku tetap terbuka; tidak ada perubahan kode rekonsiliasi pada pembaruan ini. Pembayaran langsung dan pemesanan supplier tetap nonaktif sampai tes relevan terbukti lulus.


### Pembaruan rekonsiliasi Duitku — 2026-10-15 (commit 9c138c9)

- Koreksi status sebelumnya: perubahan kode telah diterapkan pada `app/services/duitku_reconcile.php` dalam [commit `9c138c9`](https://github.com/sulistio95/PluQ-I/commit/9c138c98f1d492d90980bcfe268092ab4ce67f99). Transaksi kini mengunci baris pembayaran dan pesanan terkait, memvalidasi gateway, merchant order ID, serta nominal, lalu mencatat status `before` dari snapshot database terkunci. Pernyataan lama di atas bahwa perubahan kode belum diterapkan sudah kedaluwarsa.
- Regresi tes untuk argumen status usang belum masuk repo karena pembaruan `tests/reconcile-cooldown-concurrency-integration.php` ditolak alat; jangan menganggap pengujian ini tersedia atau lulus.
- CI setelah commit ini gagal: [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38632601867), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38632599511), dan [fulfillment safety tests](https://github.com/sulistio95/PluQ-I/actions/runs/38632599912). Job gagal terkonfirmasi, tetapi detail langkah/log tidak tersedia; penyebab belum diketahui.
- Status verifikasi: belum ada bukti lint atau tes integrasi berhasil dijalankan. Pembayaran produksi, pemesanan supplier, backup Google Drive, dan deployment tetap nonaktif sampai regresi tes dan verifikasi staging PHP 8.2 + MySQL/MariaDB berhasil.


### Regresi snapshot status rekonsiliasi Duitku — 2026-10-15 (commit 88aec00)

- Menambahkan pemeriksaan pada `tests/reconcile-cooldown-concurrency-integration.php`: setelah tes konkurensi, tes menghapus marker audit fixture, memanggil admission dengan status pemanggil yang sengaja usang, lalu membandingkan status `before` pada audit STARTED dengan status terkini yang dibaca dari database.
- Commit tes: [88aec00](https://github.com/sulistio95/PluQ-I/commit/88aec00533beba80d959f6cb08fd45a5167c5f3f). Perubahan tes sudah tersimpan; belum dijalankan secara runtime di sesi ini.
- CI sebelumnya masih gagal dan endpoint langkah/log job tidak menyediakan rincian yang dapat dibaca (steps kosong, unduhan log mengembalikan NOT_FOUND). Karena tes baru memicu workflow baru, periksa hasil run terbaru sebelum menyimpulkan lulus/gagal.
- Pembayaran produksi, pemesanan supplier, backup Google Drive, dan deployment tetap nonaktif hingga tes CI serta verifikasi staging PHP 8.2 + MySQL/MariaDB berhasil.


### Hasil Actions setelah regresi ditambahkan — 2026-10-15

- Workflow yang dipicu oleh commit tes `88aec00` berakhir gagal: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38633330500), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38633330555), [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/38633330567). Workflow pada commit dokumentasi `433f55d` juga gagal: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38633351092), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38633351125), [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/38633350950).
- Job syntax pada run `38633330500` berstatus failure, tetapi API tidak mengembalikan langkah (`steps` kosong) dan unduhan log tidak tersedia. Penyebabnya belum dapat dipastikan; jangan menyimpulkan kegagalan berasal dari kode atau limit Actions tanpa bukti.
- Pada saat pencatatan, run terbaru setelah commit handoff masih queued: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38633370983) dan [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/38633370846). Status akhir belum diketahui.
- Regresi tes sudah committed tetapi belum terverifikasi hijau. Jangan mengaktifkan pembayaran produksi, supplier ordering, backup Google Drive, atau deployment sebelum log tersedia dan pengujian staging lulus.


## Duitku reconciliation stale-status regression and CI status (2026-10-15)

- Regression test commit `88aec00533beba80d959f6cb08fd45a5167c5f3f` adds an assertion that the STARTED audit row records payment/order states read under the transaction's row locks, even when the admin caller supplies deliberately stale status strings. It also retains the concurrent admission assertion (one ADMITTED, one COOLDOWN). The test makes no gateway request.
- Latest observed workflow runs for the documentation/handoff head `55af1b0c1c15a0428e63fcb78810281063fa237c` all completed with failure: syntax run [38633432251](https://github.com/sulistio95/PluQ-I/actions/runs/38633432251), fulfillment safety [38633432240](https://github.com/sulistio95/PluQ-I/actions/runs/38633432240), and PHP quality [38633432207](https://github.com/sulistio95/PluQ-I/actions/runs/38633432207). Job-step API responses are empty and log download returns `BlobNotFound`; the cause is therefore unknown and must not be attributed to code, quota, or environment without evidence.
- Status: regression test is committed but runtime verification is **not established**. Re-run the workflows when log/step visibility is restored, or run the PHP and MySQL integration suites in a controlled environment. Keep production checkout, supplier orders, and settlement backup disabled until verification.


## Reconciliation structural-test correction (2026-10-15)

- Source review identified a concrete stale assertion in `tests/reconcile-cooldown-regression.php`: it searched for the former single-table lock SQL (`SELECT id FROM payment_transactions WHERE id=:id FOR UPDATE`) even though `app/services/duitku_reconcile.php` now locks the payment and joined order rows and selects their current statuses.
- Commit `8a81ae54b4bb927252ace3d4e715a071b78b515c` updates the structural guard to assert the current joined-payment SELECT, order-status fields, and the lock target before the cooldown/audit/commit sequence. This is a test-maintenance fix based on source comparison; it has **not yet been runtime-verified**.
- CI runs on the previous head failed very quickly and job logs remain unavailable. This stale assertion plausibly explains a failure in the PHP quality test that executes it, but it does not by itself explain every failed workflow. Recheck CI on this commit and do not claim all workflows passed until actual results are visible.


## CI follow-up — 2026-10-15

- Rechecked the latest three GitHub Actions runs for commit `2c357655b107f42b164ccab9850bf0be48c840ba`: syntax `38646127276`, PHP quality `38646127259`, and fulfillment `38646127248` all ended in failure within about 3 seconds.
- The Jobs API reports `runner_id=0`, empty `steps`, and no runner name for these jobs. Log downloads return `BlobNotFound`; the GitHub integration cannot access the check-run annotations endpoint. Therefore, these results do **not** identify a PHP/test failure, and no cause such as Actions quota has been proven.
- The structural regression guard was updated in commit `8a81ae54b4bb927252ace3d4e715a071b78b515c` to match the joined payment/order row-lock query; this change is still not runtime-verified because no CI step results are available.
- Next verification: restore usable Actions runner/log access, then run the PHP syntax/quality suites and MySQL fulfillment integration. Keep live payment, supplier ordering, Drive backup, and deployment disabled until actual tests complete.


## Status lanjutan — diagnosis GitHub Actions (2026-10-15)

- Metadata GitHub menunjukkan repository `sulistio95/PluQ-I` bersifat **public**. Jatah menit Actions bulanan untuk private repository karena itu bukan penjelasan yang paling mungkin untuk kegagalan ini; penyebab sebenarnya tetap belum diketahui.
- Tiga workflow pada commit `34d9ebb9` gagal hampir seketika: syntax `38646530354`, fulfillment `38646530361`, PHP quality `38646530385`. Run pada `8edca90f` juga gagal cepat. Jobs tidak menampilkan langkah maupun runner yang ditugaskan, dan log tidak dapat diunduh. Ini **bukan bukti** bahwa skrip tes berjalan lalu gagal.
- Percobaan checkout Git lokal untuk menjalankan PHP terhalang kegagalan resolusi DNS `github.com`; belum ada hasil tes lokal yang dapat diklaim.
- Review sumber terbaru: helper rekonsiliasi memvalidasi gateway, merchant order ID, nominal, dan status terkini di bawah row lock; menulis audit `STARTED` dan commit sebelum melakukan permintaan jaringan Duitku. Tes struktural dan integrasi konkurensi sudah ada tetapi belum terverifikasi berjalan.
- Tindakan berikutnya: pulihkan akses runner/log melalui pengaturan atau notifikasi Actions, lalu jalankan lint/quality dan integrasi MySQL. Jangan aktifkan pembayaran live, pemesanan supplier, backup Drive, atau deployment sebelum hasil tes nyata tersedia.


## Perbaikan konkurensi reservasi saldo supplier — 2026-10-15

- Commit `e98b4f4eea9af9d14a6863d4d1fd314c18ae6e96` mengubah urutan lock pada `app/services/provider_budget.php`: setelah mengunci order, transaksi kini mengunci baris provider sebelum memeriksa reservation yang belum ada. Pemeriksaan reservasi yang sudah ada tetap dilakukan sebelum menolak provider nonaktif, agar perilaku idempotensi tidak berubah.
- Log integrasi MySQL yang sebelumnya tidak terbaca kini menunjukkan kegagalan konkret pada uji dua worker: `SQLSTATE[40001]`, kode MySQL `1213`, deadlock saat reservasi serentak. Pemeriksaan key yang belum ada sebelum lock provider memungkinkan gap-lock InnoDB bersaing ketika kedua worker hendak memasukkan reservasi.
- PHP quality dan syntax lulus pada head dokumentasi sebelumnya (`e546adac`), tetapi uji MySQL memang menemukan cacat konkurensi. Perbaikan sudah committed; hasil integrasi pada commit perbaikan masih menunggu. Jangan menganggap bug selesai sebelum rerun berhasil.
- Pembayaran live, pemesanan supplier, backup Google Drive, dan deployment tetap nonaktif.


## Lanjutan uji konkurensi supplier dan koreksi fixture — 2026-10-15

- Uji MySQL reservasi saldo supplier lulus pada commit kode `e98b4f4`; PHP quality dan syntax juga lulus. Kegagalan MySQL 1213 sebelumnya sudah ditangani dengan mengunci provider sebelum pencarian reservation yang belum ada.
- Workflow fulfillment kemudian menemukan kegagalan pada `tests/uncertain_supplier_integration.sh`: fixture untuk transaksi supplier ambigu tidak membuat baris `provider_budget_reservations` yang diwajibkan migrasi 011. Safety gate produksi benar menghentikan job sebelum cabang ambiguous-supplier, sehingga transaksi fixture tetap PROCESSING dan assertion gagal. Commit `9c5ae47d9784dbb5e00c36112fe0ba64083f3a40` menambahkan reservation ACTIVE ke fixture sementara agar pengujian mencapai cabang yang dimaksud. Ini memperbaiki fixture tes, bukan melonggarkan pengamanan produksi.
- CI untuk koreksi fixture masih menunggu. Jangan aktifkan pembayaran live, supplier ordering, backup Drive, atau deployment sampai seluruh uji MySQL lulus.


## Koreksi fixture pemulihan kegagalan supplier — 2026-10-15

- Setelah commit `9c5ae47`, tes integrasi supplier ambigu lulus. Langkah MySQL berikutnya menemukan dua masalah pada `tests/explicit_supplier_failure_integration.sh`: eksekusi PHP dari stdin tidak mendefinisikan konstanta `STDERR`, sehingga assertion sebenarnya tertutupi; dan fixture crash-recovery tidak memiliki reservasi saldo migrasi 011, sehingga safety gate menghentikan alur sebelum cabang pemulihan supplier FAILED.
- Commit `5a4181d548d7d40a8c825b75a2ec3f2917d51279` menyediakan stream stderr untuk mode stdin dan menambahkan reservation ACTIVE pada fixture pemulihan. Perubahan ini hanya memperbaiki harness/fixture tes; guard produksi tidak diubah. Uji MySQL perlu dijalankan ulang.
- Uji konkurensi reservasi saldo dan supplier ambigu sudah lulus pada run terbaru, tetapi keseluruhan integrasi belum hijau. Integrasi live tetap nonaktif.


## Hasil verifikasi — seluruh workflow fulfillment MySQL lulus (2026-10-15)

- Commit `5a4181d548d7d40a8c825b75a2ec3f2917d51279` lulus ketiga workflow: syntax `38656968511`, PHP quality `38656968476`, dan fulfillment safety `38656968414`.
- Job MySQL fulfillment selesai sukses. Cakupannya meliputi batas kapasitas/idempotensi/konkurensi/stale balance reservasi provider, tiga percobaan konkurensi checkout, klasifikasi deadlock MySQL, admission rekonsiliasi Duitku serentak, klaim antrean dan callback bersamaan, job duplikat ketika supplier aktif, pemulihan lock usang, lease revocation, review hasil supplier ambigu, kegagalan supplier/refund dan pemulihannya, isolasi pembayaran sandbox, serta pemeriksaan rekonsiliasi read-only.
- Deadlock reservasi diperbaiki dengan serialisasi melalui lock provider sebelum pencarian reservation yang belum ada. Fixture tes supplier ambigu dan kegagalan eksplisit juga dilengkapi reservation ACTIVE yang diwajibkan migrasi 011; tes stdin PHP kini menyiapkan stream STDERR.
- Ini merupakan verifikasi CI pada PHP 8.2 dan layanan tes MySQL 8.4, bukan sertifikasi hosting produksi atau gateway live. Pembayaran live, supplier ordering, backup Drive, dan deployment tetap nonaktif sampai staging di shared hosting diverifikasi.


## Konsistensi kapasitas pada katalog dan checkout — 2026-10-15

- Audit sumber menemukan ketidaksesuaian yang terlihat pengguna: katalog dan quote checkout hanya membandingkan `base_price` dengan `balance × reserve_percentage`, sementara reservasi saat pembayaran juga memperhitungkan komitmen ACTIVE/REVIEW dan SETTLED yang mungkin belum tercermin pada sinkronisasi saldo terakhir. Akibatnya, produk bisa tampil dan memperoleh quote meskipun reservasi setelah pembayaran pasti ditolak karena kapasitas agregat.
- Commit `4500da6e250f30b8dde7c52a16060af4a9990661` memperbarui `app/services/catalog.php` agar `is_available` menghitung reservasi yang masih mengikat kapasitas.
- Commit `4c9e4c26e7e316427bb1c6428bb4ac1f27dc915d` menerapkan aturan kapasitas tersisa yang sama pada produk yang diminta dan pemilihan penawaran termurah di `app/services/checkout_quote.php`. Pemeriksaan reservasi transaksional saat pembayaran tetap menjadi otoritas terakhir untuk menangani kondisi race setelah quote.
- Commit `ac2f9a829d0cda2f6ea6a003b41b44b66d04496a` memperluas tes integrasi MySQL: katalog harus menyembunyikan penawaran saat reservasi sebelumnya membuat kapasitas tidak cukup, lalu memulihkan ketersediaan setelah reservasi dilepas. CI perubahan ini masih menunggu; jangan menyatakan tes baru lulus sebelum workflow selesai.
- Integrasi pembayaran/supplier/Drive produksi dan deployment tetap nonaktif.


## Tindak lanjut tes kapasitas katalog — 2026-10-15

- Run MySQL pertama setelah penambahan assertion katalog berhasil menolak penawaran yang melebihi kapasitas, tetapi ekspektasi tes berikutnya keliru. Setelah reservasi Rp300.000 dilepas, tes langsung membuat reservasi Rp200.001; sisa kapasitas dari Rp500.000 hanya Rp299.999 sehingga produk Rp300.000 memang masih harus tidak tersedia.
- Commit `7114ede8965d47fe6f8e600167527102fd0f8002` memperbaiki urutan assertion: katalog tetap tidak tersedia selama reservasi Rp200.001 aktif, lalu tersedia kembali setelah seluruh reservasi terkait dilepas. Ini koreksi ekspektasi tes, bukan pelonggaran kode katalog atau checkout.
- Tes MySQL yang baru harus dijalankan ulang pada commit ini sebelum dinyatakan lulus. Workflow syntax dan PHP quality lulus pada head implementasi sebelumnya; kegagalan MySQL sebelumnya berasal dari ekspektasi tes yang salah, bukan error SQL runtime.


## Verifikasi CI kapasitas katalog dan checkout — 2026-10-15

- Ketiga workflow pada head dokumentasi `a59d11e242f3c8c9de291b5a53b3c50f1ac5eceb` telah selesai sukses: syntax [38658731086](https://github.com/sulistio95/PluQ-I/actions/runs/38658731086), PHP quality [38658731054](https://github.com/sulistio95/PluQ-I/actions/runs/38658731054), dan fulfillment safety/MySQL [38658731101](https://github.com/sulistio95/PluQ-I/actions/runs/38658731101).
- Tes MySQL pada rangkaian ini memverifikasi katalog tetap menandai penawaran tidak tersedia selama total reservasi melebihi kapasitas tersisa, dan ketersediaan pulih setelah seluruh reservasi fixture dilepas. Perubahan query katalog dan checkout quote serta koreksi urutan assertion sekarang lolos workflow tersebut.
- Lingkup bukti: CI GitHub Actions, PHP 8.2, MySQL 8.4. Belum membuktikan kompatibilitas penuh pada versi MariaDB milik shared host, callback Duitku live, pesanan supplier live, OAuth/penulisan Google Drive, backup/restore hosting, atau cron di hosting target. Jangan mengaktifkan integrasi/deployment produksi sebelum staging checklist selesai.


## Regresi MySQL untuk quote checkout dan kapasitas tersisa — 2026-10-15

- `tests/provider-budget-reservation-mysql-integration.php` kini menguji perilaku `checkout_quote()` langsung: saat komitmen reservasi menyebabkan kapasitas tersisa kurang dari harga pokok produk, quote harus melempar `DomainException`; setelah semua reservasi fixture dilepas, quote harus berhasil kembali. Ini melengkapi assertion katalog dari perubahan sebelumnya.
- Fixture menambahkan field game `user_id` yang valid sehingga tes quote juga melewati validasi akun yang digunakan jalur checkout. Perubahan hanya pada tes; kode produksi tidak diubah.
- Status runtime untuk regresi baru ini masih menunggu workflow CI pada commit terbaru. Sampai workflow fulfillment/MySQL selesai sukses, jangan menyatakan pengujian baru lolos. Integrasi produksi dan deployment tetap nonaktif.


## Hasil CI regresi quote checkout — 2026-10-15 (diperbarui)

- Ketiga workflow pada commit `59297d1a892a491c025f6614aba3fee52095370c` selesai `success`: [syntax PHP/JavaScript](https://github.com/sulistio95/PluQ-I/actions/runs/38660082026), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38660081870), dan [fulfillment safety/MySQL integration](https://github.com/sulistio95/PluQ-I/actions/runs/38660081985).
- Tes MySQL baru untuk `checkout_quote()` ikut dijalankan: quote harus ditolak jika biaya produk plus komitmen melampaui kapasitas, dan harus kembali berhasil setelah reservasi fixture dilepas. Bukti ini berlaku untuk lingkungan CI PHP 8.2/MySQL 8.4, bukan untuk MariaDB atau hosting target yang belum dipilih.
- Pembayaran live, supplier ordering, Google Drive backup, dan deployment tetap nonaktif. Tahap audit berikutnya difokuskan pada settlement serta preflight shared hosting.


## Konsistensi ledger kapasitas saat queue masuk manual review — 2026-10-15

- Ditemukan inkonsistensi status pada pemulihan job: order dan supplier dapat masuk `MANUAL_REVIEW`/`REVIEW`, sedangkan reservasi kapasitas terkait tetap `ACTIVE`. Ini tidak melepaskan kapasitas dan karena itu konservatif, tetapi status ledger tidak menjelaskan bahwa reservasi menunggu rekonsiliasi.
- Perbaikan: `bin/worker-queue.php` dan `app/services/queue_worker.php` sekarang menandai reservasi yang ada sebagai `REVIEW` dalam transaksi yang sama ketika order paid dipindahkan ke manual review. Status `REVIEW` tetap dihitung sebagai komitmen kapasitas; tidak ada saldo yang dilepas otomatis.
- `tests/queue_recovery_integration.sh` menyiapkan reservasi fixture dan memverifikasi ledger berakhir `REVIEW`. Hasil CI perubahan ini masih menunggu. Pembayaran live, pemesanan supplier, Google Drive backup, dan deployment tetap nonaktif.


### Hasil CI perbaikan reservasi REVIEW — 2026-10-15

- Semua workflow pada commit `813f6dea8668162f1a817e00c631d9aeb1249a16` selesai sukses: [fulfillment safety dan MySQL](https://github.com/sulistio95/PluQ-I/actions/runs/38660874079), [syntax PHP/JavaScript](https://github.com/sulistio95/PluQ-I/actions/runs/38660874054), serta [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38660874040).
- Pengujian queue recovery kini memverifikasi order masuk `MANUAL_REVIEW`, supplier masuk `REVIEW`, job queue menjadi `FAILED`, dan reservasi budget ikut `REVIEW` sehingga kapasitas tidak dilepas saat hasil supplier masih belum pasti.


## 2026-10-15 — Settlement destination isolation

Source review found `settlement_find_drive_file()` searched Drive globally by settlement metadata and filename, while the admin-configured destination folder was only applied when creating/moving a new spreadsheet. A pre-existing tagged spreadsheet outside that folder could therefore be treated as the current backup. Commit `c57c494b398aa6724f99bec028c1c6db476879ff` now includes the configured folder as a parent constraint in the Drive lookup and passes the resolved folder ID to that function.

- **Code changed:** `bin/settle-monthly.php` only.
- **Purpose:** prevent false-positive “already backed up” matches outside the configured destination folder.
- **Verification:** CI for this commit pending at the time of this note. No live Google Drive/OAuth request was made. This does not certify Drive permissions, shared-drive compatibility, or production hosting.
- **Production gate remains:** settlement backup stays disabled until configured and tested safely; payment/provider execution and deployment remain disabled.


## 2026-10-15 — Preflight requires provider budget schema

The shared-hosting preflight previously did not require migration `011_provider_budget_reservations` or the `provider_budget_reservations` table, despite checkout/catalog/fulfillment safety relying on this reservation ledger. Commit `bb025e0539f63916ad87d29dd3fcd570e30a9156c` adds both to the mandatory checks. This prevents a false “preflight passed” result for a database missing the provider budget reservation schema.

- **Verification:** CI pending for this change. The preflight has not been executed against a real shared-hosting database.
- **Production blockers remain:** validate all migrations on a clean target MySQL/MariaDB version, test scheduled queue runs and recovery on the actual host, and keep payments, supplier ordering, and Drive settlement disabled until staging checks pass.


## 2026-10-15 — Paginated Google Drive settlement lookup

Commit `29c4ba614bf3950fbd9bb518033029cf53fbcceb` updates `settlement_find_drive_file()` to follow `nextPageToken` and search all matching Drive result pages, rather than inspecting only the first 20 entries. The query remains constrained to the configured folder and matching settlement-period metadata. This reduces the risk of creating a duplicate spreadsheet when the correct existing export is not in the first result page.

- **Verification:** CI pending for this commit; no real Google Drive/OAuth calls performed.
- **Previously verified:** all three CI workflows passed for preflight schema guard commit `bb025e0539f63916ad87d29dd3fcd570e30a9156c`.
- **Production gates unchanged:** test on the target hosting environment and validate Google Drive permissions before enabling settlement backup. Payment callbacks and supplier execution remain disabled.


## 2026-10-15 — Pemulihan settlement setelah file Drive hilang

Commit `5de0c6cd837832ceaeb033c46ba7aaadea83b12f` mengubah jalur retry `bin/settle-monthly.php`: catatan yang sudah berstatus `UPLOADED` tidak lagi langsung dianggap selesai tanpa memeriksa Drive. Proses masuk kembali ke jalur rekonsiliasi idempotensi berdasarkan periode dan folder tujuan. Jika spreadsheet yang cocok masih berada di folder yang dikonfigurasi, ekspor yang sama dikenali; jika tidak ditemukan (misalnya dihapus atau dipindahkan keluar folder), proses dapat membuat ulang file di folder tujuan.

- **Alasan:** mencegah status database `UPLOADED` menjadi bukti sukses yang kedaluwarsa setelah file Drive dihapus/dipindahkan.
- **Verifikasi:** ketiga workflow CI pada commit kode `5de0c6cd837832ceaeb033c46ba7aaadea83b12f` sukses: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38665307769), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38665307770), dan [fulfillment safety/MySQL](https://github.com/sulistio95/PluQ-I/actions/runs/38665307764). Pembaruan catatan pada SHA `1d357403` juga melewati ketiga workflow. Belum dilakukan akses OAuth/Drive live maupun uji pada hosting target.
- **Gate produksi:** backup settlement, pembayaran, supplier ordering, dan deployment tetap nonaktif sampai pengujian staging serta izin Drive diverifikasi.


## 2026-10-15 — Audit queue worker, cron, dan preflight

Source review pada `bin/worker-queue.php` mengonfirmasi pemrosesan dibatasi oleh ukuran batch dan batas runtime konfigurasi; job diklaim dalam transaksi dengan `FOR UPDATE` dan finalisasi memeriksa token lock. Lock yang kedaluwarsa tidak diretry otomatis: hasil supplier bisa ambigu, sehingga order paid perlu rekonsiliasi manual dan reservasi kapasitas tetap ditahan sebagai `REVIEW`. Endpoint HTTP memerlukan `X-Scheduler-Token`; worker juga tetap nonaktif saat supplier ordering dimatikan. `bin/queue-health.php` menyediakan pemeriksaan read-only untuk backlog, kegagalan, dan lock basi.

- **Kebutuhan validasi hosting:** pastikan cron provider mendukung custom header untuk endpoint HTTP atau jalankan worker melalui CLI bila paket hosting mengizinkan proses PHP CLI. Uji runtime/timeout dan frekuensi cron pada paket aktual; source review bukan bukti cron hosting berjalan.
- **Preflight:** memeriksa PHP 8.2+, ekstensi, HTTPS, secret strength tanpa menampilkan nilai, APP_DEBUG, toggle integrasi, DB, tabel dan migrasi inti. Migrasi/tabel settlement hanya diwajibkan saat settlement backup diaktifkan. Belum diuji terhadap database/versi MariaDB target.
- **CI pemulihan settlement:** seluruh tiga workflow untuk kode commit `5de0c6cd837832ceaeb033c46ba7aaadea83b12f` sukses (runs 38665307769, 38665307770, 38665307764). CI atas pembaruan catatan terbaru masih berjalan saat audit ini ditulis.
- **Gate produksi:** jangan aktifkan pembayaran, supplier ordering, Drive backup atau deployment sebelum staging, cron, MariaDB, dan akses eksternal diuji.


## 2026-10-15 — Conflicting payment callback keeps provider budget in review

A source audit found that a signed Duitku callback contradicting an already terminal payment would correctly move the order to `MANUAL_REVIEW`, but could leave its provider budget reservation `ACTIVE`. Commit `d348ce67d0842010697f01687842c63ee8891f08` now resolves any active/review reservation to `REVIEW` in the same callback transaction, preserving the reserved capacity while preventing an order from remaining in the automatic fulfillment path. The stored terminal payment state is not overwritten.

- Regression coverage in commit `74f61479f83aa5cf9075f675a521dbfde0c38080` checks contradictory callback after `PAID`, order manual-review status, reservation `REVIEW`, and no duplicate fulfillment job.
- **Verification pending:** inspect CI on the latest commit; the MySQL/HTTP integration script has not been run against live gateway credentials.
- **Production gates unchanged:** keep Duitku payments, supplier ordering, Google Drive settlement, and deployment disabled until CI and staging checks are complete.


## 2026-10-15 — Keep budget reservation in review for stale supplier requests

Review of `app/services/fulfillment.php` found a gap in the ambiguous prior-request branch: it moved the order to `MANUAL_REVIEW` and supplier transaction to `REVIEW`, but did not resolve an `ACTIVE` provider budget reservation. Commit `05c4a126086de77d9760682f4c9fe324e7d36122` now marks that reservation `REVIEW` in the same database transaction. This intentionally does not free the capacity: the supplier may have accepted the original request, so funds remain committed until reconciliation.

- Regression test commit `cbd833b1595f77b297b6434e646ffc3d070a9784` asserts that the reservation transitions to `REVIEW` together with the order and supplier states.
- **Verification pending:** check all CI workflows for the code/test commits and the latest notes commit. No live supplier API was called.
- **Production gates unchanged:** payment, supplier ordering, Drive backup, and deployment remain disabled pending staging and hosting validation.


## 2026-10-15 — Stale queue recovery cannot clear manual review

Audit of `bin/worker-queue.php` identified a recovery-state conflict: if a stale job's supplier transaction was `SUCCESS`, the recovery branch could overwrite order `MANUAL_REVIEW` with `SUCCESS`. Manual review can originate from a contradictory signed Duitku callback, so a supplier status alone must not erase that review gate. The same branch also marked recovered success without resolving the provider-budget reservation to `SETTLED`.

- Code commit `479788514539cd77b214a5bed1d602e71f7f54e9` only recovers supplier success automatically when the paid order is still `QUEUED`, `PROCESSING`, or `RETRYING`; an already-successful order is handled idempotently. Both success-recovery paths resolve the reservation to `SETTLED` in the same transaction as stale queue recovery. An order already in `MANUAL_REVIEW` stays under reconciliation and its reservation is retained as `REVIEW`.
- Regression coverage in `tests/queue_recovery_integration.sh` was added in commit `442527632c2a4bfa999d47ea5303eace52203ab0`: successful in-flight recovery must end with order `SUCCESS`, reservation `SETTLED`, and queue job `DONE`; payment-conflict `MANUAL_REVIEW` must remain unchanged with reservation `REVIEW` and queue job `FAILED`. The prior ambiguous stale supplier test remains.
- **Verification pending:** inspect CI for the code, test, and note commits. No live API was called. Shared-hosting staging, MariaDB compatibility, real cron behavior, and Drive permissions are still unverified. Keep payments, supplier ordering, settlement backup, and deployment disabled.


## 2026-10-15 — Queue recovery test harness correction

The first CI run for the new stale queue recovery regression assertions failed because the SQL JSON path `'$.order_id'` was placed inside a shell single-quoted `php -r` program. The shell removed the quotes before PHP/MySQL execution, producing `Unknown column '$.order_id'`. The PHP quality and syntax workflows passed; the MySQL job stopped at the affected assertion, so the recovery regression itself was not yet verified.

- Test-harness-only fix: commit `eb7e25fa4dd8540fdba02bc94403980c7d65909f` constructs the JSON path using `CHAR(...)`, avoiding embedded single quotes while preserving the same payload lookup.
- **Verification pending:** rerun CI on this test fix and the latest notes commit. No production behavior changed in this follow-up. Keep all live integrations and deployment disabled.


## 2026-10-15 — MySQL JSON path charset correction for queue recovery test

After the shell quoting fix, CI reached the queue-recovery assertions but MySQL rejected the generated JSON path because `CHAR(...)` returns a binary string. Test-only commit `53b2af99241193fbaacd3785352f5d457963770a` now casts the path to `CHAR CHARACTER SET utf8mb4`, which should provide the string charset expected by JSON functions. The MySQL integration must be rerun before the new assertions are considered passing; production logic remains unchanged by this harness correction.


## 2026-10-15 — Preserve existing manual-review reason in queue recovery

The MySQL queue-recovery regression reached the intended payment-conflict fixture and showed that the order status remained `MANUAL_REVIEW`, but its specific `last_error` was overwritten with the generic stale-lock message. This did not auto-clear review, but it degraded the audit trail needed for reconciliation.

- Code commit `fbc82eb00f14dfa9e2d539a15d25784d552f8f70` adds a guard so stale recovery does not overwrite `last_error` for an order already in `MANUAL_REVIEW`. Supplier success cannot clear the review gate; provider capacity stays `REVIEW` and the stale job remains `FAILED`.
- **Verification pending:** rerun the MySQL workflow after this fix. Prior failures in the newly added integration assertions are being used to tighten test coverage; no live supplier/payment calls were made.


## 2026-10-15 — CI passed for stale queue recovery reconciliation guard

All three CI workflows passed for implementation/test commit `fbc82eb00f14dfa9e2d539a15d25784d552f8f70`: PHP quality `38671179140`, syntax `38671179042`, and fulfillment/MySQL integration `38671179031`. The updated notes head `f9c908653cd6c2c177fa528a847e043311ef2e26` also passed syntax `38671219531`, PHP quality `38671219421`, and fulfillment/MySQL integration `38671219437`.

The regression confirms that stale recovery only restores supplier success for orders still in automatic fulfillment states, resolves recovered success capacity to `SETTLED`, and preserves existing payment-conflict `MANUAL_REVIEW` plus its reason while retaining the reservation as `REVIEW`. This evidence is from CI PHP 8.2/MySQL 8.4, not live integration or target-host certification. Keep Duitku payments, supplier ordering, Google Drive settlement, and deployment disabled until staging gates pass.


## 2026-10-15 — Shared-hosting readiness and isolated sandbox payment test status

**Readiness decision: not yet approved for a live transaction on shared hosting.** Latest CI evidence confirms PHP quality, syntax, and MySQL 8.4 integration tests pass for queue-recovery implementation commit `fbc82eb00f14dfa9e2d539a15d25784d552f8f70` (runs `38671179140`, `38671179042`, `38671179031`) and audit head `f9e611835d28772610dc89c7b4750fccbd1ebc34` (runs `38671358735`, `38671358840`, `38671358999`).

The previous runbook statement that sandbox checkout could not be isolated was stale. Source and tests confirm `checkout_sandbox_only_enabled()` only opens the sandbox gate in `staging`/`test`, with `DUITKU_ENV=sandbox`, `CHECKOUT_PAYMENTS_ENABLED=true`, and `SUPPLIER_ORDERS_ENABLED=false`. The CI MySQL workflow simulates a valid signed callback and asserts payment `PAID`, order `MANUAL_REVIEW`, and no `FULFILL_ORDER` job. It does **not** prove a real external Duitku sandbox transaction works from a shared host.

- Runbook refreshed in commit `833bebe13bdec243f32122599632f464c0b8c690`.
- Still required before calling the host transaction-ready: verify the selected plan's PHP extensions and MariaDB version, private environment/document-root protections, HTTPS and externally reachable callback, CLI cron and runtime limits, database backup/restore, a real Duitku sandbox create-payment → callback → status-page lifecycle, and no supplier calls during that lifecycle.
- Supplier execution remains blocked until provider identities/balances, exact game mappings, controlled order/status/timeout behavior, budget reservation under concurrent callbacks, and refund/manual-review ownership are verified. Google Drive export must remain disabled until real OAuth/folder permissions and recovery are tested.
- No live payment or supplier API was called; no deployment was performed. Keep production payment, supplier ordering, Drive export, and deployment disabled.


## 2026-10-15 — Cron schedule for Nimbus shared hosting

Source review confirms that PluQ-I has no committed or active hosting cron configuration: the schedule is set in the selected provider's control panel after deployment. `config/app.php` defines bounded worker settings, not a cron interval: batch size defaults to 5 (range 1–20) and runtime defaults to 20 seconds (range 5–50). `bin/worker-queue.php` is the queue processor; `bin/worker.php` is read-only diagnostic; `bin/queue-health.php` is read-only and CLI-only. Prefer PHP CLI cron; an HTTP invocation requires `X-Scheduler-Token`.

The README was updated in commit `9c25a7732630897f0a0346b48b40a0749b656ee2` to document a Nimbus-compatible queue example of every 6 minutes (`*/6 * * * *`) and a 15-minute alternative for stricter plans. Catalog sync, balance sync, and monthly Drive settlement are described as separate optional jobs and must not be enabled until provider limits, credentials, migrations, OAuth, and staging checks are confirmed. No PHP code or production defaults changed. No hosting has been purchased, so real cron execution, CLI paths, runtime limits, and non-overlap remain unverified. Payment, supplier orders, Drive backup, and deployment remain disabled.


## 2026-10-15 — Customer catalog hides unavailable supplier offers

Source review found that the customer catalog computed provider balance eligibility, but still returned unavailable products as visible cards marked “Tidak tersedia saat ini” when no equivalent available offer existed. That could expose VIPayment-only offers while VIPayment had zero usable balance.

- `app/services/catalog.php` now groups equivalent supplier offers, prefers eligible offers, and removes groups with no eligible offer before returning data to the storefront. Thus a zero-balance/ineligible VIPayment offer is not shown; an eligible Digiflazz offer remains visible. If both are eligible, the existing cheapest-offer selection remains automatic.
- Added `tests/catalog-availability.php` with regression checks for unavailable-only offers, Digiflazz available + VIPayment unavailable, cheapest offer when both are eligible, and other available products. Added the test to `.github/workflows/php-quality.yml`.
- `public/assets/catalog.js` now uses a clearer empty-state message when a game has no currently available products.
- **CI for this change set is pending verification.** This is source-level and unit-test coverage, not a live supplier API or hosting test. Payments, supplier orders, Drive backup, and deployment remain disabled.


## 2026-10-15 — Catalog availability regression test aligned with budget safety

The first MySQL CI run after filtering unavailable offers failed because `tests/provider-budget-reservation-mysql-integration.php` still expected one unavailable product card. That assertion represented the previous storefront behavior, not a production-code regression. It now expects zero customer-facing products when provider commitments make the offer ineligible; the checkout quote must still reject the same product. Unit-test fixtures were also completed with base prices.

- Test corrections: `db02544a740f46e5491bd11c7f3f499c3f554f92` and `aaa3b34011a304275af94236b968d5e0ab723850`.
- **Verification pending:** rerun/inspect all workflows on the latest commit. No live supplier API or hosting was used. Keep payments, supplier ordering, Drive backup, and deployment disabled.


## 2026-10-15 — Corrected MySQL integration expectation for hidden unavailable products

The latest fulfillment workflow (38755995858) exposed one remaining stale assertion in tests/provider-budget-reservation-mysql-integration.php: after a reservation consumes the remaining usable provider capacity, the test still expected one product marked unavailable. The storefront contract is now to omit unavailable products entirely, so this expectation contradicted the catalog filter and caused the MySQL integration job to fail; PHP quality and PHP/JavaScript syntax jobs passed.

- Commit f423392aad634c4d3dbde82dd1ffd6e957003cfd changes that assertion to expect an empty customer-facing catalog while capacity is exhausted.
- The test still checks that the product returns once all reservations are released.
- Verification: all three GitHub Actions workflows passed for code/test commit f423392aad634c4d3dbde82dd1ffd6e957003cfd — PHP quality 38758104195, PHP/JavaScript syntax 38758104376, and fulfillment/MySQL integration 38758105058. This validates the test suite in CI's MySQL 8.4 environment only; no live supplier API or hosting test was performed. Payment, supplier ordering, Drive backup, and deployment remain disabled.


## 2026-10-16 — Local MySQL deadlock classification test passed

- Local verification reported by the user: PHP lint passed for `tests/checkout-deadlock-classification-integration.php`.
- The MySQL integration test passed with output: `Real MySQL deadlock classification test passed (one victim classified for idempotency winner recovery).`
- Scope: verifies one real local MySQL deadlock victim is classified for idempotency-winner recovery. No Duitku/payment gateway or supplier API was called, and no hosting environment was tested.
- Safety note: do not execute `tests/queue_recovery_integration.sh` as currently written because it can update unrelated PENDING/RETRY jobs and starts a worker with supplier ordering enabled. Review and isolate `tests/duplicate-queue-processing-integration.php` before running it because it sets `SUPPLIER_ORDERS_ENABLED=true` and depends on a catalog fixture.
- Payment checkout, supplier ordering, settlement backup, and deployment remain disabled pending controlled staging verification.


## 2026-10-16 — Duplicate-queue test made self-contained and network-contained

- `tests/duplicate-queue-processing-integration.php` no longer requires a pre-seeded `integration-game/integration-sku` catalog row. It creates unique temporary game/product rows using the seeded DIGI provider and cleans them up, including partial-order setup failures.
- Test-only supplier API endpoints are set to loopback port 1 with fake credentials. The test enables supplier processing only inside its own PHP process to exercise the duplicate guard; if a regression tries to call a supplier client, it cannot reach the real Digiflazz or VIPayment endpoint.
- Changes: `91d68b83eedd996bf02d7321330e35206ca8ffe2`, `c688130ea340afa2e9b3f9dfe04e8197d05b0302`.
- **Verification pending:** the user must pull the latest commit and run lint plus the test through `app/bootstrap.php` against the disposable local MySQL database. No supplier API, payment gateway, or hosting test is claimed. Production payment, supplier ordering, Drive backup, and deployment remain disabled.


## 2026-10-16 — Duplicate-queue integration test verified locally

- User-reported local lint passed for `tests/duplicate-queue-processing-integration.php`.
- User-reported test result: `Duplicate queue job preserves fresh PROCESSING state; stale ambiguous requests enter manual review; reviewed and terminal orders cannot be revived.`
- This verifies the duplicate-job state guard against local MySQL fixtures only. Test-only supplier endpoints are loopback port 1; no real supplier request or gateway request was made.
- Next planned check is the isolated Duitku transaction-status helper test using its injected test transport; it does not require credentials or contact Duitku.


## 2026-10-16 — Uji helper status transaksi Duitku lulus secara lokal

- Pengguna menjalankan `php -l tests/duitku-transaction-status.php`; hasil: tidak ada kesalahan sintaks.
- Pengguna menjalankan `php tests/duitku-transaction-status.php`; hasil: `Duitku transaction-status signature, status mapping, and response validation tests passed.`
- Cakupan meliputi validasi HMAC, pemetaan status dan penolakan status tak dikenal, pemeriksaan referensi pesanan/nominal, serta penolakan transport tiruan pada mode produksi.
- Pengujian menggunakan transport tiruan yang diinjeksi dan **tidak menghubungi Duitku**. Ini bukan bukti transaksi sandbox nyata atau callback pada shared hosting telah berhasil.
- Langkah aman berikutnya: tinjau lalu jalankan `tests/reconcile-cooldown-regression.php`, yang merupakan uji regresi struktural. Jangan memulai server web atau menggunakan kredensial gateway untuk tes ini.
- Pembayaran, pemesanan supplier, backup Google Drive, dan deployment tetap nonaktif sampai pengujian staging dan hosting target selesai.


## 2026-10-16 — Uji struktural rekonsiliasi Duitku lulus secara lokal

- Pengguna menjalankan `php -l tests/reconcile-cooldown-regression.php`; hasil: tidak ada kesalahan sintaks.
- Pengguna menjalankan `php tests/reconcile-cooldown-regression.php`; hasil: `Admin Duitku cooldown structural regression checks passed.`
- Pemeriksaan struktural memastikan penguncian baris pembayaran/pesanan, cooldown 60 detik, pencatatan audit `STARTED` dan commit sebelum I/O jaringan, validasi CSRF, pemeriksaan hak akses admin, serta larangan mutasi status pembayaran/pesanan atau memulai checkout/fulfillment pada rute rekonsiliasi.
- Cakupan ini hanya pemeriksaan struktur kode. Ini belum menguji request HTTP dengan sesi admin, dua request cooldown bersamaan, lookup status Duitku nyata, maupun shared hosting.
- Langkah berikutnya: periksa setup `tests/reconcile-admin-http-integration.php` sebelum menjalankannya di localhost, pastikan fixture dan pembersihan aman.
- Pembayaran, supplier ordering, backup Google Drive, dan deployment tetap nonaktif.


## 2026-10-16 — Uji struktural cooldown rekonsiliasi lulus

Pengguna melaporkan `php -l tests/reconcile-cooldown-regression.php` tanpa kesalahan sintaks dan `php tests/reconcile-cooldown-regression.php` menghasilkan `Admin Duitku cooldown structural regression checks passed.` Tes memeriksa urutan penguncian dan cooldown, commit audit `STARTED` sebelum I/O jaringan, CSRF, hak akses, serta sifat read-only rute. Ini belum membuktikan request HTTP terautentikasi atau lookup Duitku nyata.

**Uji berikutnya yang direncanakan:** `tests/reconcile-admin-http-integration.php` menggunakan server pengembangan PHP lokal pada `127.0.0.1:8080` dan database tes lokal. Tes membuat akun ADMIN/OPERATOR sementara dengan nama unik dan menghapusnya pada blok `finally`; jalankan hanya jika `.env` menunjuk ke database lokal khusus pengujian. Tes mengharapkan redirect anonim, larangan untuk OPERATOR, penolakan CSRF invalid, penolakan POST tanpa aksi eksplisit, dan halaman admin untuk ADMIN. Order ID yang dikirim tidak valid sehingga tidak seharusnya memicu request gateway. Jangan masukkan kredensial gateway dan jangan mengaktifkan supplier ordering.


## 2026-10-16 — Admin reconciliation HTTP boundary test passed locally

The user ran the local PHP development server at `127.0.0.1:8080` and executed `php tests/reconcile-admin-http-integration.php`. Reported result: `Admin reconciliation HTTP boundary tests passed: anonymous redirect, operator forbidden, invalid CSRF forbidden, unknown POST rejected, authorized admin form rendered; no gateway request was made.`

- Server log confirmed anonymous GET redirect (`302`), operator GET and invalid-CSRF POST forbidden (`403`), unknown POST rejected (`400`), and authorized admin GET rendered (`200`).
- Scope is the tested HTTP authorization/CSRF boundary against local server and test database. No Duitku, supplier, or Google Drive network request was made. This is not a real gateway sandbox test or shared-hosting validation.
- Keep the local development server local-only and stop it with `Ctrl+C` when finished. Ensure `.env` points to the disposable local test database.
- Next: continue read-only preflight/deployment-security review and select the next test only after inspecting its fixture and side effects. Do not run `tests/queue_recovery_integration.sh` as-is: it can modify unrelated PENDING/RETRY jobs and starts a worker with supplier ordering enabled.
- Production checkout, supplier ordering, settlement/Google Drive backup, and deployment remain disabled.


## 2026-10-16 — Read-only document-root and preflight review

Reviewed `bin/preflight.php`, `config/app.php`, `public/.htaccess`, and the hosting checklist in `README.md`.

- Preflight includes checks for PHP version/extensions, HTTPS `APP_URL`, minimum `APP_KEY` and scheduler-token lengths, disabled production debug, payment/supplier/settlement flags, database connectivity, required tables, and migration records. Secret values are not printed.
- **Deployment condition:** there is no root-level `.htaccess` in the repository. The existing `public/.htaccess` applies only when the web document root is set to `public/`; it provides `Options -Indexes`, front-controller rewrites, and several response headers, not protection for private project directories if the repository root is web-accessible. Before deployment, point the document root at `public/` or verify explicit server rules block web access to `.env`, `app/`, `config/`, `database/`, `bin/`, and other private files.
- This is source/documentation review only; no hosting configuration or public request was tested. Preflight success alone is not a production certification. Keep payment, supplier ordering, Drive backup, and deployment disabled.


## 2026-10-16 — Local deployment preflight: 45/47 checks passed

The user ran `php -l bin/preflight.php` and `php bin/preflight.php` locally on PHP 8.2.34. Syntax passed. Preflight reported 45/47 checks passed.

Passed checks include PHP version and required extensions, minimum lengths for `APP_KEY` and `SCHEDULER_TOKEN`, disabled checkout/supplier/settlement flags, database connectivity, all 24 required base tables, and the migration records required while settlement backup is disabled. The two failures are environment-specific: `APP_URL uses HTTPS` and `APP_DEBUG disabled` (local development debug is enabled). Preserve local developer settings if needed; staging/production must use an HTTPS URL and `APP_DEBUG=false`. Do not interpret this local preflight as deployment approval or live integration verification. Checkout payments, supplier ordering, Drive backup, and deployment remain disabled.


## 2026-10-16 — Environment-file tracking check and queue-test guard

- User verified git check-ignore -v .env output .gitignore:1:.env, empty git status --short, and git ls-files --error-unmatch .env reported that .env is not tracked in the current Git index. This is a current-index check, not a full historical secret scan.
- The fulfillment workflow calls tests/queue_recovery_integration.sh. The script broadly changes PENDING/RETRY queue jobs and temporarily enables supplier fulfillment. Added a fail-closed invocation guard requiring both DB_DATABASE=pluq_test and PLUQI_ALLOW_DESTRUCTIVE_QUEUE_RECOVERY_TEST=1; only the matching disposable GitHub Actions step sets the opt-in.
- This limits accidental execution but does not make the legacy test safe for a user's local or production database; do not run it manually. CI for the guard is pending. No gateway, supplier, or Google Drive calls were made. Live payments, supplier ordering, settlement backup, and deployment remain disabled.


## 2026-10-16 — Queue-test guard CI verification

- GitHub Actions on commit `75603cd14db79d27fbf5dca4998285eb5f82905a` succeeded: PHP quality `38851169829`, fulfillment safety/MySQL integration `38851169810`, and PHP/JavaScript syntax `38851169695`.
- This verifies the current CI run after adding an explicit guard to the legacy queue-recovery test. The test is still unsuitable for manual execution against local or production databases; its intended run is the isolated CI `pluq_test` service.
- CI success is not hosting or real Duitku/provider certification. Live payments, supplier ordering, settlement backup, and deployment remain blocked pending staging checks.


## 2026-10-16 — Shared-hosting worker, supplier-sync, and settlement entry-point review

Read-only source review of `bin/worker-queue.php`, `bin/sync-balances.php`, `bin/sync-catalog.php`, `bin/settle-monthly.php`, `app/services/provider_sync.php`, `app/services/catalog_sync.php`, `app/services/catalog.php`, `app/services/provider_http.php`, and `config/app.php`.

- **Queue worker access:** the worker accepts CLI execution or an HTTP request carrying a matching `X-Scheduler-Token`; an empty/mismatched token is rejected. CLI cron is preferred on shared hosting. Keep `bin/` outside the public document root. Supplier processing returns disabled unless `SUPPLIER_ORDERS_ENABLED=true`.
- **Bounded work, not a hard wall-clock kill:** defaults are 5 jobs per invocation and 20 seconds, configurable but clamped to batch size 1–20 and runtime 5–50 seconds. The runtime is checked between jobs; an in-progress supplier HTTP call is not forcibly interrupted at the worker's runtime target. Provider HTTP requests have connection/overall timeouts (up to 30 seconds), so a single invocation can exceed the configured worker target. Avoid overlapping cron runs and measure real host execution before tuning.
- **Stale queue recovery:** locks older than 10 minutes are inspected in batches of at most 50 and ambiguous paid orders are moved to manual review; the worker does not blindly re-submit a potentially accepted supplier purchase. If more than 50 stale locks exist, recovery continues on later invocations. This is a source review, not a load test on shared hosting.
- **Supplier balance sync:** `bin/sync-balances.php` is CLI-only, skips a provider when its required credentials are absent, and skips providers explicitly marked `DISABLED`. Successful checks set the provider status to `ACTIVE` or `LOW_BALANCE` according to the configured threshold; errors mark it `ERROR`. Do not run it on a hosting account until credentials, rate limits, and provider mappings are reviewed because it makes supplier API requests.
- **Catalog sync and zero-balance suppliers:** `bin/sync-catalog.php` is CLI-only and similarly skips missing credentials and providers marked `DISABLED`. It may refresh cached catalog data for a `LOW_BALANCE` provider, but the customer catalog query only marks an offer available when the provider is `ACTIVE`/`LOW_BALANCE`, has a known balance, passes the configured reserve-capacity calculation, and has an applicable pricing rule. With balance zero and a positive product cost, the offer is not purchasable; equivalent offers are collapsed to an available supplier if one exists, and unavailable-only offers are hidden. The existing `tests/catalog-availability.php` covers the customer-facing selection behavior; no external API was called during this review.
- **Monthly settlement/Google Sheets:** `bin/settle-monthly.php` is CLI-only and exits before database/Google API work unless `SETTLEMENT_BACKUP_ENABLED=true`. It accepts the first day of the month for the normal run and a completed `--period=YYYY-MM` for controlled recovery; a recent `PROCESSING` record blocks duplicate work for 30 minutes, and Drive lookup is restricted to the configured destination folder and settlement period. Once enabled, it makes OAuth, Google Drive, and Sheets network requests and creates/updates files; do not enable or schedule it until OAuth permissions, destination folder, migration 006–007, duplicate/recovery behavior, and a restore/reconciliation procedure have been tested.
- **Current boundary:** this was source review only. No supplier, Duitku, Google OAuth, Drive, or Sheets network calls were made; no cron job or shared-hosting environment was tested. Keep checkout payments, supplier ordering, settlement backup, and deployment disabled pending controlled staging tests.


## 2026-10-16 — CI execution evidence and shared-hosting gate

- Inspected the actual job and step records for the latest workflows on `ca719f2442b1e5ade88358c7970473303d28e6ac`: [Fulfillment safety tests](https://github.com/sulistio95/PluQ-I/actions/runs/38851721983), [PHP quality checks](https://github.com/sulistio95/PluQ-I/actions/runs/38851722224), and [PHP/JavaScript syntax checks](https://github.com/sulistio95/PluQ-I/actions/runs/38851721990). All jobs and listed steps completed successfully, including disposable MySQL 8.4 concurrency/deadlock/callback/queue tests.
- Refreshed `docs/staging-payment-test-runbook.md` in commit `eb3773166795a9608270619baf202b56470a76e3` to supersede older historical notes about Actions being blocked, and added shared-hosting execution checks.
- Confirmed repository root contains no root-level `.htaccess`; only `public/.htaccess` exists. Hosting must serve `public/` as document root or separately deny access to private source/configuration/data directories. Do not deploy with repository root exposed.
- CI success proves the automated workflow ran; it does not prove real Duitku sandbox, supplier APIs, shared-hosting cron/permissions, or Google Drive OAuth/upload. Production Duitku mode remains unsupported by current code. Payment, supplier order, and settlement backup flags remain disabled.


## Admin supplier-driven game selection — 2026-10-19

- Replaced manual game/provider mapping fields in `public/admin/game-mappings.php` with a supplier-first workflow: select Digiflazz or VIPayment, explicitly fetch the current game list from that supplier API, check the games to expose, and save.
- Selection is stored per supplier. Saving one supplier deactivates only that supplier's previous mappings; it does not clear the other supplier's selection. API fetch/validation failures leave saved mappings untouched.
- The UI displays game names and checkboxes only. Supplier game codes/names remain internal mapping data for catalog synchronization and fulfillment.
- Selected same-named games from different suppliers resolve to a shared canonical `games` row, so the storefront can show one game entry while its products/offers remain tied to provider mappings. Storefront game status is recalculated from whether any supplier has an active mapping.
- The UI filters out names containing `joki` or `vilog`, preserves admin role/CSRF checks, and records an audit event containing supplier/counts but not credentials.
- GitHub Actions passed for code commit `970dea8b2e829c1d5cd3d63c82b38551b9b73ea5`: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39204606529), [MySQL callback integration](https://github.com/sulistio95/PluQ-I/actions/runs/39204606531), and [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39204606555). This verifies CI suites only. No live supplier API was called and local browser/database behavior, real API response shapes, and duplicate-name selection remain to be verified. Keep checkout, supplier fulfillment, and Drive backup disabled during UI testing.


## Default keuntungan awal dan panel yang dapat diedit — 2026-10-19

- Aturan keuntungan default yang telah disepakati tetap dipasang pada migration awal `database/migrations/002_catalog_and_operations.sql`: modal Rp0–Rp10.000 mendapat laba Rp500; di atas Rp10.000 sampai Rp100.000 mendapat Rp1.000; di atas Rp100.000 sampai Rp500.000 mendapat Rp2.000; dan di atas Rp500.000 mendapat Rp3.500.
- Migration `012_pricing_rules_hybrid.sql` mempertahankan aturan lama dengan menyalin `profit_amount` menjadi `profit_value` bertipe `FIXED`; jadi perubahan ke model nominal/persentase tidak mengganti laba nominal default yang sudah disepakati.
- Panel `/admin/pricing-settings.php` memungkinkan ADMIN/SUPER_ADMIN mengubah rentang modal, nominal atau persentase, dan status aturan. Harga katalog dan kutipan checkout membaca aturan aktif dari database yang sama. Biaya admin invoice tetap terpisah dari laba.
- Verifikasi GitHub Actions pada commit `a3740fe717da2da54ac11b619172dd07c6f253ee` berhasil: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39209913651), [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39209913521), dan [fulfillment/MySQL integration](https://github.com/sulistio95/PluQ-I/actions/runs/39209913435).
- Ini bukti CI pada database MySQL pengujian, bukan uji browser/shared hosting atau transaksi Duitku/provider nyata. Untuk menggunakan panel, tarik perubahan dan jalankan migration 012 setelah migration 011. Jangan menimpa aturan yang sudah disesuaikan admin saat deployment; migration mempertahankan data lama.


## 2026-10-19 — Panel pratinjau VIPayment dan hapus konfigurasi API

- Commit `a981ef81d24df0efe031e75c474b6f807d55bea7` menambahkan pratinjau baca-saja katalog VIPayment pada `public/admin/api-settings.php`, melalui helper API yang sudah ada. Pratinjau membatasi tampilan hingga 100 layanan; hasil tidak disinkronkan ke tabel `games/products` dan tidak membuat pesanan.
- Panel juga menyediakan penghapusan per nilai konfigurasi API tersimpan di database. Aksi memakai POST + CSRF, allowlist nama konfigurasi, transaksi, dan audit log. Penghapusan database tidak menghapus variabel di `.env` atau environment hosting; hal itu harus dilakukan terpisah.
- Kode telah diverifikasi ada di commit GitHub. Lint lokal, pengujian browser, respons format katalog VIPayment nyata, dan uji penghapusan terhadap database lokal belum dijalankan. Jangan menyebut fitur teruji sampai bukti tersebut tersedia.
- Risiko/kelanjutan: verifikasi format respons VIPayment aktual agar pemetaan kolom game/nama/kode/harga sesuai API. Pertahankan pembayaran checkout, pesanan supplier, backup Drive, dan deployment tetap nonaktif.

- GitHub Actions subsequently passed on commit `6a1527b2496f20207a626cdaa3f8071e089441de`: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39234587186), [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39234586979), and [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/39234586947). CI success is not a live VIPayment/browser/database deletion test.

## 2026-10-19 — Panel konfigurasi form akun per game

- Commit `4a17016b8d2cf7b7b97cbbff34f102743208184a` menambahkan `public/admin/game-fields.php`; commit `be413cf13c8f9c621f8fee98b8acab5b0dcbb7cf` menambahkan tautan navigasi admin.
- Fitur mendukung CRUD praktis kolom teks/angka/dropdown, label/kunci/placeholder, wajib diisi, regex opsional, urutan, status aktif/nonaktif, dan opsi dropdown. Pengubahan melalui POST + CSRF + role check ADMIN/SUPER_ADMIN + prepared statements + transaksi + audit log.
- Migration 002 sudah mendefinisikan `game_fields` dan `game_field_options`; tidak ada migration baru yang diperlukan untuk panel ini.
- Verifikasi yang masih harus dilakukan: `php -l public/admin/game-fields.php`, `php -l public/admin/index.php`, pengujian UI di browser lokal, validasi CSRF/role, serta memastikan field tersimpan dan tampil pada `/api/games/{slug}/fields`. Implementasi belum dianggap lulus pengujian hanya karena commit ada di GitHub.
- Batasan penting: regex dan required pada panel belum menggantikan validasi server yang ketat untuk request checkout, pengecekan username game, maupun pemetaan field khusus Digiflazz/VIPayment. Pertahankan pembayaran, pesanan supplier, dan backup Drive nonaktif sampai alur tersebut diuji secara terkontrol.


## Konfigurasi form akun game — 2026-10-19

- `public/admin/game-fields.php` menyediakan pengelolaan field per game dan opsi dropdown. Form memakai schema yang sudah ada pada migration `002_catalog_and_operations.sql` (`game_fields` dan `game_field_options`). Panel admin memiliki tautan **Konfigurasi Form Akun**.
- Fitur: tambah/edit kolom teks, angka, dropdown; key/label/placeholder; regex format; wajib/tidak wajib; urutan; status aktif/nonaktif; opsi dropdown `nilai|label`. Perubahan dilindungi oleh role ADMIN/SUPER_ADMIN, CSRF, transaksi database, dan audit log.
- Hardening commit `ad20f9627f7b7b6a50bcc74a75cee373939bd1df`: edit tidak boleh memindahkan/mengubah field milik game lain hanya dengan memodifikasi POST; opsi lama dinonaktifkan ketika field bukan lagi tipe dropdown.
- Frontend memakai `GET /api/games/{slug}/fields`. Regex/required di halaman publik memeriksa format input, bukan memvalidasi nickname ke server game. Setiap field harus dicocokkan dengan aturan game/provider; jangan mengasumsikan satu format untuk semua game.
- Verifikasi CI setelah commit dan pengujian runtime/browser/database belum dikonfirmasi. Setelah pull, uji tambah/edit, dropdown, nonaktif/aktif, duplikasi key, dan tampilan form pada halaman depan. Checkout, supplier ordering, dan backup Drive harus tetap nonaktif.


## Update — 2026-10-19: Form akun otomatis (branch belum merge)

- Branch: `feature/automatic-game-account-form`. Belum masuk ke `main` dan belum diuji pada hosting.
- Menghapus tautan admin **Konfigurasi Form Akun** dan menghapus `public/admin/game-fields.php`; tabel lama `game_fields`/`game_field_options` tidak dihapus agar data lama tetap tersedia.
- Form dibuat otomatis: User ID untuk semua game; Zone ID untuk judul yang dikenali memakai zone (termasuk Mobile Legends, Genshin Impact, Honkai: Star Rail/Impact, dan beberapa judul lain); Server untuk nama game yang secara eksplisit menyebut server/region jika tidak ada aturan Zone ID.
- Endpoint `GET /api/games/{slug}/fields` kini mengirim field hasil generator serta metadata apakah tersedia kode layanan VIPAY untuk pengecekan username.
- Ditambahkan `POST /api/games/{slug}/check-username`: memanggil VIPayment `type=get-nickname` dengan `code`, `target`, dan `additional_target`; memakai pembatasan per sesi dan pesan aman untuk kegagalan. Pengecekan berjalan otomatis setelah kolom wajib terisi. Tidak ada pesanan supplier yang dibuat oleh endpoint ini.
- Validasi kutipan checkout menggunakan schema generator, bukan konfigurasi manual `game_fields`. Email pembayaran dipindah keluar dari grid kolom akun.
- Batas penting: API `services` VIPayment tidak mengembalikan skema kolom tiap game. Generator saat ini menggunakan aturan nama game yang konservatif, bukan schema formal dari supplier. Unknown game hanya mendapat User ID. Username check hanya tersedia jika ada kode produk VIPAY aktif; hasil runtime perlu diuji sebelum mengaktifkan checkout.
- Tes unit schema dan workflow PHP quality ditambahkan. Belum ada klaim bahwa CI, browser, database, username lookup live, checkout, pembayaran, supplier ordering, atau backup Drive sudah lolos.

- Safety refinement: only a reviewed set of common games is currently allowed to reach checkout quotes; unknown games still show a User ID fallback, but product selection is disabled. A separate Server field is shown when indicated by the game name, but checkout remains blocked until supplier-specific mapping is verified. This avoids silently sending an incomplete or incorrectly combined account target.


## Admin API management — 2026-10-20

- Added provider-level deletion selection for Duitku, Digiflazz, VIPayment, and each individual custom profile. Deletion removes related database configuration rows transactionally and writes an audit log; it intentionally preserves transactions, orders, catalog rows, and mappings.
- Added encrypted custom API profiles categorized as Payment Gateway or Supplier, with HTTPS base URL, auth method, key/secret/token fields. These are configuration profiles only; they are not connected to live payment or supplier workflows until a dedicated adapter and security/test suite are implemented.
- Environment variables are outside the database and must be removed separately when rotating/removing a provider.
- Code commit: `5b551871ba7c151e0a7905818e8cafb20eff212ac`. Three GitHub Actions workflows were running when checked; current result must be rechecked after the latest docs commit. Local PHP lint and browser/database verification remain pending.
- No payment, supplier order, or Google Drive backup was triggered. Keep these disabled during testing.


## Admin template: unggah dan hapus gambar banner — 2026-10-20

- Pengaturan Template kini mendukung unggah lokal JPG/PNG/WebP (maks. 5 MB) serta URL HTTPS yang sudah didukung. Validasi memeriksa MIME, dimensi gambar, dan nama file acak; file disimpan pada `public/uploads/banners/`.
- Saat edit, URL kosong mempertahankan gambar lama; upload baru mengganti gambar setelah penyimpanan database dan audit log berhasil. Penghapusan banner menghapus baris database secara transaksional dan menghapus file lokal yang sesuai setelah commit; URL eksternal tidak disentuh. Menonaktifkan banner tetap non-destruktif.
- `public/uploads/banners/.htaccess` menonaktifkan directory listing dan menolak akses ke file berekstensi skrip. Hosting harus mengizinkan PHP menulis folder upload dan batas `upload_max_filesize`/`post_max_size` harus sesuai.
- Commits: `f1824eb05e28a9eebc3c41fbe0bf838a75418c4e`, `1fc141bf71ac435888bf9a9d7bf4703b1eac84d2`, `c78151b34daa8ef3157dce7c039c593f55b54b3a`.
- Belum ada klaim lint, CI, browser, atau uji hosting. Verifikasi workflow CI dan lakukan uji lokal tambah/edit/ganti/nonaktif/hapus. Jangan aktifkan checkout, pesanan supplier, atau backup Drive.


## Follow-up keamanan upload banner — 2026-10-20

- Jalur penghapusan file dibatasi ke URL relatif upload yang tepat; URL eksternal tidak dapat dipetakan menjadi path file lokal hanya karena memiliki path serupa. Commit: `9ef66075a549a93c361de259a85a0ef05b89271b`.
- File `public/uploads/banners/.htaccess` menggunakan `Options -Indexes` dan `RemoveHandler` untuk ekstensi skrip. Ini defense-in-depth; file upload tetap hanya boleh berasal dari validator JPG/PNG/WebP, dan konfigurasi server hosting perlu diverifikasi.
- PHP quality dan syntax lulus pada commit `9ef66075`; syntax lulus pada head `5a680240`. PHP quality dan fulfillment safety untuk head terbaru masih berjalan saat dicatat. Belum ada pengujian upload melalui browser, database lokal, atau hosting.


## Pengaturan Template — icon game aktif — 2026-10-20

- Menambahkan daftar **Icon Game List** pada `public/admin/template-settings.php`, hanya menampilkan game dengan status ACTIVE dari pilihan yang tersimpan pada Game List. Ikon menggunakan kolom `games.icon_url` yang sudah dipakai katalog; tidak perlu migration.
- Unggah/ganti menerima JPG/PNG/WebP maks. 5 MB, memvalidasi MIME dan dimensi, menggunakan nama acak, serta menyimpan di `public/uploads/game-icons/`. Update database dan audit log berada dalam transaksi. Icon lokal sebelumnya dibersihkan setelah update berhasil.
- Hapus icon mengosongkan `icon_url` dan menghapus file lokal terkelola jika ada, tanpa menghapus game/mapping. URL eksternal tidak dihapus dari server asal. Direktori memiliki `.htaccess` untuk menonaktifkan listing dan handler ekstensi skrip.
- Commits: `41543b6e3cbce9bc97cb196d825f5e09faeef567`, `7164700e961485fe800a8feb97efd8372aeb9c1d`.
- Runtime upload, homepage browser check, PHP lint, dan CI untuk perubahan ini belum diverifikasi pada saat pencatatan. Jalankan uji terkontrol sebelum deployment; pembayaran, supplier ordering, dan Drive backup tetap nonaktif.


## Pengaturan Template — matriks icon dan saran ukuran gambar — 2026-10-20

- `public/admin/template-settings.php` mengubah daftar Icon Game List dari tabel memanjang menjadi grid/matriks kartu responsif, sehingga banyak game lebih mudah dipindai dan dikelola. Setiap kartu tetap menyediakan pratinjau, unggah/ganti, dan hapus icon.
- Menu banner kini menampilkan saran ukuran **1200 × 400 px (3:1, landscape)**. Bagian Icon Game List menampilkan saran **512 × 512 px (1:1, persegi)**. Keduanya hanya rekomendasi, bukan batas validasi; batas upload tetap 5 MB dan format JPG/PNG/WebP.
- Commit kode: `7ee62fc3741ea01a0efcdb210246cd08a85f6ed5`.
- Belum ada klaim bahwa PHP lint, GitHub Actions, browser, atau unggah/hapus file lokal sudah lolos untuk perubahan ini. Setelah pull, jalankan `php -l public/admin/template-settings.php`, lalu cek matriks pada desktop dan ponsel serta uji unggah/ganti/hapus icon dan banner. Checkout, supplier ordering, backup Google Drive, dan deployment tetap nonaktif selama pengujian.


## Form data akun — hilangkan label Username dan helper text — 2026-10-20

- Pada `public/index.php`, label **Username** dan teks awal `Isi data akun; username akan diperiksa otomatis jika API mendukungnya.` dihapus dari form pelanggan. Area hasil pengecekan dibiarkan kosong pada keadaan awal.
- Pada `public/assets/catalog.js`, status kolom tidak wajib/tidak didukung tidak lagi menampilkan keterangan di area hasil username. Jika API benar-benar mendukung pemeriksaan, status pemeriksaan dan hasil nama akun tetap dapat ditampilkan secara dinamis; logika validasi akun dan perlindungan checkout tidak diubah.
- Perubahan dimaksudkan agar pelanggan tidak menganggap pengecekan username wajib tersedia untuk setiap game. Tidak ada perubahan skema database.
- Perubahan kode telah dikirim ke GitHub; browser dan alur cek username belum diuji langsung setelah perubahan. Jalankan `php -l public/index.php` dan uji game dengan dukungan cek username serta game tanpa dukungan.
