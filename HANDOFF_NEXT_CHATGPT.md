## Update — 2026-10-20: Pencarian dan detail transaksi admin

- Menambahkan halaman **Cari Transaksi** di `public/admin/transaction-search.php`. Pencarian dapat memakai ID publik pesanan, nomor merchant Duitku, referensi gateway, atau referensi transaksi supplier; hasil dibatasi 200 baris.
- Menambahkan halaman **Detail Transaksi** di `public/admin/transaction-detail.php`, yang menampilkan ringkasan pesanan, status pembayaran/pesanan, error terakhir, catatan gateway, referensi supplier, refund, dan linimasa dari cap waktu/status yang memang tersedia di database.
- Menambahkan tautan **Cari Transaksi** ke navigasi admin melalui `app/views/admin_sidebar.php`. Halaman pencarian dan detail memerlukan sesi admin; keluaran dinamis di-escape dan query memakai prepared statements.
- Batasan yang disengaja: linimasa ini belum merupakan audit trail lengkap untuk setiap callback, permintaan HTTP, atau percobaan retry. Beberapa status gateway/refund tidak memiliki waktu kejadian yang ditampilkan oleh skema saat ini. Belum ada tindakan retry/refund dari halaman detail.
- Verifikasi PHP lint, integrasi database, tampilan browser, dan GitHub Actions untuk commit ini belum dilakukan pada saat catatan ditulis. Uji lokal dengan migration database yang sudah diterapkan sebelum digunakan. Jangan mengaktifkan pembayaran, pemesanan supplier, atau backup Drive selama pengujian awal.

## Update — 2026-10-19: Sinkronisasi katalog dari panel admin

- Menambahkan tombol **Sinkronkan katalog VIPayment** dan **Sinkronkan katalog Digiflazz** pada Pengaturan API. Berbeda dari tombol pratinjau VIPayment, tombol sinkronisasi memanggil service katalog dan menyimpan produk/harga yang cocok dengan pemetaan game aktif ke database.
- Jika tidak ada produk yang cocok dengan pemetaan aktif, sinkronisasi sekarang melempar error di dalam transaksi sehingga perubahan status stale di-rollback dan katalog yang ada tidak ikut dikosongkan.
- Kode diubah pada `public/admin/api-settings.php` (commit `3f4cb21f692a8409c21eac177bd249f7b228f71b`) dan `app/services/catalog_sync.php` (commit `21715ee41013345af21789641b9b9b9a8ffe9110`).
- Langkah admin: simpan kredensial supplier; buka **Game List**, pilih dan simpan game yang dipetakan untuk supplier; kembali ke **Pengaturan API**, jalankan sinkronisasi supplier yang digunakan; lalu buka game di halaman customer dan periksa endpoint `/api/games/{slug}/products`.
- Sinkronisasi hanya membaca katalog harga supplier dan menulis katalog lokal; tidak membuat order supplier maupun transaksi pembayaran. Pastikan gunakan hanya supplier yang memang dikonfigurasi dan ingin digunakan.
- Belum menjalankan lint, CI, atau pengujian browser/database lokal untuk perubahan ini. Hasil CI terbaru harus diperiksa, lalu pengguna akan melakukan tes lokal. Pembayaran, pemesanan supplier, dan backup Drive tetap nonaktif.

## Follow-up verifikasi katalog Habis — 2026-10-19

- Run MySQL berikutnya menemukan assertion katalog kedua yang masih mengharapkan hasil kosong setelah reservasi kedua memakai kapasitas supplier. Assertion itu juga sudah diperbarui agar mengharapkan satu nominal `is_available=false`; penolakan reservasi/checkout tetap diuji terpisah.
- Commit tes terbaru: `6285888f1abfc57c10782a50b9ac4bbea23e1c5a`. GitHub Actions untuk commit dan catatan terbaru harus diselesaikan sebelum menyatakan CI hijau.
- Pada run sebelumnya, PHP quality dan syntax lulus; fulfillment safety gagal hanya karena assertion katalog lama di skenario kapasitas kedua. Pengujian lokal pengguna dilakukan setelah status CI terbaru selesai.

## Verifikasi katalog Habis — 2026-10-19

- Pemeriksaan GitHub Actions setelah fitur nominal Habis menemukan kegagalan integrasi MySQL: `tests/provider-budget-reservation-mysql-integration.php` masih mengharapkan katalog menghilangkan penawaran yang melampaui sisa anggaran supplier. Harapan itu sudah tidak sesuai keputusan baru, karena nominal harus tetap terlihat dengan `is_available=false`.
- Memperbarui tes integrasi agar memastikan nominal tetap muncul sebagai tidak tersedia ketika sisa anggaran tidak cukup; tes tetap memastikan `checkout_quote()` menolak transaksi itu di sisi server.
- Commit koreksi tes: `0437cc493ca2ec3cd07203355938f3a4f76a082a`. GitHub Actions untuk commit ini berjalan saat catatan diperbarui; periksa hasil terbaru sebelum uji lokal.
- CI sebelumnya: PHP quality dan PHP/JavaScript syntax lulus; fulfillment safety gagal hanya pada assertion katalog yang usang, sebelum tes integrasi berikutnya dijalankan. Jangan menyebut seluruh CI lulus sampai run setelah koreksi selesai.
- Pengguna akan menguji di mesin lokal setelah hasil CI selesai. Tetap nonaktifkan pembayaran, pesanan supplier, dan backup Google Drive.

## Update — 2026-10-19: Keputusan katalog untuk nominal habis

- Keputusan pengguna: jika ada beberapa supplier untuk nominal yang sama, pilih penawaran termurah dari supplier yang memenuhi syarat. Jika tidak ada supplier yang tersedia, nominal tetap ditampilkan dengan label **Habis**.
- Implementasi: `catalog_select_customer_products()` tetap mengutamakan penawaran tersedia; bila semua penawaran setara tidak tersedia, pilih penawaran cache termurah dan kembalikan `is_available=false`.
- Frontend menampilkan label **Habis** dan tidak menyediakan tombol pilih untuk item yang tidak tersedia. Item yang terlihat habis bukan izin untuk checkout; validasi server tetap wajib menolak produk/supplier yang tidak tersedia.
- Tes regresi katalog diperbarui untuk mencakup nominal dengan satu supplier tidak tersedia, dua supplier tidak tersedia, pilihan supplier termurah yang tersedia, dan deduplikasi nominal.
- Belum mengklaim lint, CI, atau pengujian browser/database setelah perubahan. Pembayaran, pemesanan supplier, dan backup Drive tetap nonaktif.

## Update — 2026-10-19: Paksa koneksi API VIPayment ke IPv4

- Commit kode: `e034368f646d6689448b19f392903d52945bf7bc`.
- `app/services/vipayment.php` sekarang menyetel `CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4`, sehingga request VIPayment melalui helper ini menggunakan IPv4 dan tidak memilih IPv6.
- Tujuan: mengatasi penolakan whitelist karena provider tampaknya menerima IPv4 saja. Tambahkan IPv4 publik yang benar-benar dipakai koneksi keluar ke whitelist VIPayment.
- Verifikasi runtime, hasil pratinjau katalog setelah perubahan, dan CI untuk commit ini masih menunggu. Ini belum membuktikan IP keluar mana yang dipakai jaringan atau bahwa whitelist sudah benar.
- Setelah menarik commit di komputer lokal, jalankan `php -l app/services/vipayment.php`, lalu coba pratinjau katalog. Jika gagal, catat pesan baru; jangan tampilkan API Key/sign. Pembayaran, pesanan supplier, dan backup Drive tetap nonaktif.

## Update — 2026-10-19: Pratinjau katalog VIPayment lokal

- Rencana fitur admin: tombol baca-saja untuk mengambil layanan VIPayment melalui `type=services` dan menampilkan daftar game/kategori serta pratinjau hingga 100 layanan.
- Pratinjau ini tidak mengubah tabel katalog lokal dan tidak membuat pesanan. Sinkronisasi ke tabel `games`/`products` perlu tahap terpisah setelah format respons dan pemetaan kode produk dipastikan.
- Status implementasi: belum berhasil ditulis ke repository; pembaruan kode melalui GitHub ditolak oleh pemeriksaan keamanan alat. Jangan menganggap fitur tersedia sampai commit kode terverifikasi.


## Update — 2026-10-19: Masa berlaku invoice Duitku

- Masa berlaku invoice kini dapat diatur dari **Pengaturan API → Duitku → Masa berlaku invoice**, default 30 menit, dengan validasi 5–1440 menit. Kolom kosong mempertahankan nilai yang tersimpan; nilai lingkungan `DUITKU_EXPIRY_MINUTES` menjadi fallback.
- Permintaan Duitku menggunakan `expiryPeriod` dari konfigurasi yang sama dengan `payment_transactions.gateway_expiry_at`, sehingga batas waktu gateway dan batas waktu lokal tidak lagi menggunakan angka hard-coded yang terpisah.
- Menambahkan `bin/reconcile-expired-payments.php` sebagai CLI rekonsiliasi. Script hanya memeriksa invoice PENDING yang melewati tenggat dan menanyakan status Duitku sandbox. Status lokal menjadi EXPIRED hanya jika Duitku mengonfirmasi status gagal/kedaluwarsa; PROCESS tetap pending, sedangkan SUCCESS tidak memicu fulfillment dan perlu ditangani melalui callback/rekonsiliasi terpisah. Pembaruan memakai row lock dan memeriksa ulang status untuk mencegah callback yang datang bersamaan ditimpa.
- Script tidak menerima request web dan tidak memanggil endpoint pembelian supplier. Jadwalkan melalui cron PHP CLI yang tersedia di hosting (misalnya setiap 15 menit); jangan membuka script sebagai URL publik.
- Tes batas nilai ditambahkan ke `tests/duitku-transaction-status.php`. GitHub Actions **PHP quality** dan **PHP/JavaScript syntax** lulus pada commit `94fbe6e7fd1b73762f591f061fe791f9fa637b38`; workflow fulfillment safety masih berjalan saat catatan ini diperbarui. Ini belum menggantikan uji lokal/browser dan uji Duitku sandbox nyata. Tetap pertahankan `CHECKOUT_PAYMENTS_ENABLED=false`, `SUPPLIER_ORDERS_ENABLED=false`, dan backup Drive nonaktif sampai uji staging terkontrol selesai.
## Hybrid pricing CI follow-up — 2026-10-19

- First MySQL integration CI found a missing `SELECT` in the new percentage-profit subqueries in catalog and checkout quote. Corrected in commits `c27b930eeb75d330e9118e389fec54326b29ba3e` and `e3a8d3b87bceaa3b3411cbed16aa6defe37e6e48`.
- PHP quality and syntax workflows passed before that SQL correction; MySQL integration failed at `catalog_products()`. Recheck the post-fix CI runs before considering verification complete.
- No live payment, supplier order, or Google Drive backup was triggered.

## Update — 2026-10-19: Pengaturan keuntungan

- Added `public/admin/pricing-settings.php`, linked as **Pengaturan Keuntungan** in admin navigation.
- Supports per-modal range rules with either fixed rupiah profit or percentage of supplier base price, active/inactive status, and audit logging. Active ranges may not overlap; at least one active rule must remain.
- Migration `012_pricing_rules_hybrid.sql` adds `profit_type` and `profit_value` while preserving old fixed `profit_amount` values. Apply after migration 011.
- Updated pricing service, public catalog calculation, checkout quote's cheapest-offer validation, and preflight migration checks. Added `tests/pricing-hybrid.php` to PHP quality CI.
- Key commits: migration `9aad765bf710aa9716861c66df58dbe39c947a73`; page `81b5ee4a05eae463e8f836c33c401d50b9bf6fac`; pricing service `66ddc234ea0b01ef1d4ff0d99d57f6b674d3da0b`; catalog `86d67429412405f73751ab8aee5f2a86d6f19c82`; checkout quote `8ca22bfa5bff9f6edf65c740a0cc002ad6cdca8f`; nav `b113a9ef8aa96e77c02baab016d1222c236b00b3`; preflight `e5e8aea7204213d8e53e5f6f54bf2eb949f11b9f`; test `cd0e0145a8728cd61fd4a50fa2984093f77a45da`; workflow `53c004b0d9589b9439c1b229444c27c398a3a0bd`.
- CI/browser/database verification is pending. Pull latest main, apply migration 012 after 011, then verify admin save/validation, catalog prices, checkout quote, and the workflow result. Keep payment, supplier ordering, and Drive backup disabled.

## Update — 2026-10-19: Homepage layout

- Preserved the existing PluQ brand/title at the top-left exactly in place; do not change its positioning or appearance.
- Reordered the remaining homepage content to: banner → game search → game list.
- Updated `public/index.php` and `public/assets/catalog.css`; the existing catalog search event and 3-second banner rotation logic remain in use.
- Commits: homepage markup `3fac5a148b383e962cdf6f6296dcf32fb82ec75a`; CSS `7c7e73019485a28df667ca10989249521b115aeb`; audit notes `b140c620e211f88fd76d9759d3126fc3a8114f63`.
- Not yet verified in a browser or by CI. Next: run PHP lint and JS syntax/regression workflows; visually confirm desktop/mobile layout. Keep checkout, supplier ordering, and Drive backup disabled.


## Update — 2026-10-19: Admin template settings

- Added `public/admin/template-settings.php`; admin navigation now includes **Pengaturan Template**.
- Page supports add/edit, HTTPS image URL, optional target URL, sort order, active/inactive state, role and CSRF checks, and audit log. Deactivation is non-destructive.
- Existing public `GET /api/banners` endpoint reads active banners from the `banners` table. Direct image upload and banner scheduling are not included in this first iteration.
- Not yet tested in a live/local browser with the database, and no CI result claimed yet. Verify syntax and schema compatibility, then check banner create/edit/deactivate and the homepage slider. Expected slider design: 3-second autoplay, looping, pause-on-hover, arrows, indicators, responsive images, mobile swipe.
- Keep checkout, supplier ordering, and Google Drive backup disabled during testing.

# PluQ-I — Handoff for the next ChatGPT session

Last updated: 2026-10-13 (Asia/Jakarta) — admin checkout hints and timeout CI verified; manual reconciliation is next
Repository: https://github.com/sulistio95/PluQ-I
Default branch: `main`

## Admin API settings UI CI update — 2026-10-19

- Fixed PHP argument-unpacking compatibility in `public/admin/api-settings.php`.
- All three workflows passed on commit `bda15ed334c5dd318f362c079cfb6b652bcee286`: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39200932151), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39200932012), [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/39200931982).
- Interactive local testing of encrypted save/reload and supplier read-only requests is still pending. Do not say this UI has been end-to-end tested yet.

## Update — 2026-10-19: Admin API settings UI

- Added `public/admin/api-settings.php` and the panel navigation link for Duitku, Digiflazz, and VIPayment.
- Added `app/services/api_secret_crypto.php`; entered credentials are encrypted using Sodium secretbox and `APP_KEY`, stored in existing `app_settings`, and loaded by the provider service config helpers. The secret values are never re-rendered in the form.
- Duitku is still sandbox-only. Its button validates local configuration; Digiflazz/VIPayment balance buttons perform explicit read-only API requests only. No order is created by these buttons.
- The page requires the admin settings role and CSRF validation. Blank fields preserve current saved values. The user must keep `APP_KEY` stable and private.
- **Not yet runtime-verified:** lint/CI, save-and-reload encryption, and real supplier read-only checks. Next action: inspect the new page for PHP compatibility, run PHP lint and focused tests, verify navigation/login locally, and keep payment/order/Drive flags disabled. Do not claim the feature is tested until actual results are recorded.

## Latest verified state — 2026-10-16

- Local Puppy Bookworm64 verification: PHP syntax checks and the catalog, payment gate, provider-balance validation, callback signature/state, account mapping, supplier retry, pricing, response mapping, checkout idempotency/deadlock recovery, provider budget reservation, and concurrent queue-claim test groups passed as reported in the active conversation.
- Local MySQL integration checks passed for provider budget reservation capacity/idempotency/release/concurrency, checkout gateway-failure idempotency plus deadlock recovery with a mocked Duitku creator, and two workers claiming distinct queue jobs.
- The worker lease revocation test initially stopped before execution because the local catalog had no `integration-game/integration-sku` fixture. The query of existing game/product rows returned no rows. Commit `93de6ee` changes `tests/worker-lease-revocation-integration.php` to create a unique temporary game/product against the existing DIGI provider and clean those fixtures up afterward; it no longer depends on pre-seeded catalog rows. This change is committed but has not yet been run locally or verified by CI in this note.
- Do not run `tests/queue_recovery_integration.sh` as-is: source review found it updates all PENDING/RETRY queue rows and runs the worker with supplier ordering forced on. Supplier ordering, live payments, and Drive backup must remain disabled during local testing.
- These local tests are not proof of a real Duitku sandbox payment or shared-hosting deployment. Continue with isolated DB tests and update this handoff/audit after verification.

## Latest verified state — 2026-10-15

- CI for audit head `7edd7244c856968ecc4666978c3ee0787074adeb` passed all three workflows: PHP quality `38692917813`, PHP/JavaScript syntax `38692917809`, fulfillment safety/MySQL integration `38692917819`.
- The runbook head `833bebe1` also passed all three workflows (`38692868185`, `38692868196`, `38692868253`).
- Recent regression tests verify safe stale queue recovery and preserve an existing payment-conflict `MANUAL_REVIEW` reason; they also verify a sandbox callback cannot enqueue supplier fulfillment when supplier ordering is disabled.
- CI is not hosting validation and the signed callback test is locally simulated. No real Duitku sandbox transaction or shared-hosting deployment has been performed. Keep live payment, supplier ordering, and Drive backup disabled until staging gates pass.
- Next: finish the shared-hosting preflight/deployment safety audit, then run staging only after a hosting account/domain and sandbox credentials are available. Continue updating this handoff and `docs/production-readiness-audit.md` after each material change.

## Instructions for continuing

Read this file first, then read `README.md` and `docs/production-readiness-audit.md`. Continue work directly in **PluQ-I** using the GitHub integration when available. Do not make the user repeat the project background. Audit source before changing it, commit focused changes, update this handoff and the audit document as work progresses, and distinguish code review from tests actually executed.

## Scope and repository separation

- **Only work in `sulistio95/PluQ-I`** for the current shared-hosting edition.
- The separate `sulistio95/PluQ` repository is the primary edition planned for Cloudbaik. Do not edit it or copy changes there unless the user explicitly asks.
- PluQ-I targets PHP 8.2+ and MySQL/MariaDB on lower-cost shared hosting (possible targets include Rumahweb/Rumah Hosting).
- Architecture is intentionally PHP + PDO + MySQL queue processed in bounded cron runs. Do not introduce Redis, BullMQ, Node.js, or a permanently running worker into this edition.
- User asked to complete the stages and then audit them individually. Continue implementation and source review rather than only offering a plan.

## Product and business requirements

- Top-up storefront for games, dark/minimal responsive UI, search and rotating banners.
- Suppliers: Digiflazz and VIPayment. Gateway: Duitku only for now.
- Customer prices are calculated server-side from supplier costs, profit/pricing rules and the configured admin fee. Exclude catalog entries containing `joki` or `vilog`.
- Provider balance availability must respect reserve percentage (default design reserves 50%).
- Game account inputs vary by game; never guess field meanings or map IDs/zones by array order.
- Do not expose supplier choice to the customer if the selected offer should be automatically chosen by the backend.
- Keep payment status separate from fulfillment/order status.
- Supplier ambiguity/timeouts must go to manual review; do not blindly repeat a purchase that might already have been accepted by the supplier.
- Definitive supplier rejection currently creates a pending refund record, but **automatic customer refunds are not certified/implemented as a production-safe workflow**. Reconcile refunds through the approved gateway procedure.

## Current implementation at a glance

- PHP bootstrap/environment loading and PDO database configuration.
- MySQL schema plus migrations 002–010 for catalog/operations, checkout/payment, manual-review statuses, settlements/Drive settings, login throttling, refund lookup index, and checkout request fingerprint.
- Public catalog and quote validation; admin transaction view and game/provider mapping screen.
- Checkout idempotency binds the idempotency key to a normalized fingerprint of email, game, product and account fields; same key with a different payload fails closed.
- Duitku callback verifies HMAC, checks order/amount, locks the payment row and enqueues fulfillment only on the first valid transition to paid. Callback code `00` means success and `01` means failure according to Duitku server-to-server callback docs.
- MySQL-backed bounded queue with lock tokens and retry cap; stale worker locks are moved to manual review rather than automatically resubmitted.
- Worker finalization hardening (commit `957d310591c159af2357c2553db72d4f0187ef65`): after fulfillment returns, the worker locks its queue row and verifies `status='PROCESSING'` plus the same `lock_token` before changing queue/order/supplier state. Finalization is transactional and locks queue → order → supplier, matching stale-lock recovery. A worker whose lease was revoked records `LOCK_LOST` and does not finalize its stale result. The revoked-lease DB integration test passed in run `38209568044`.
- Follow-up commit `524006860eb1662f9bc415d830ab0a0dc1d775bf` extracted lease-guarded finalization to `app/services/queue_worker.php` so it can be exercised directly. Commit `3c99f1483dac492029640e3ac1c648b3df242b93` adds `tests/worker-lease-revocation-integration.php`, which simulates stale recovery revoking a queue token and setting order/supplier to review, then verifies the original worker's RETRY result returns `LOCK_LOST` without changing those states. Workflow step added in commit `5595378aea624f67bb4a42cb1e24bcd24169c89b`. MySQL integration and PHP test jobs passed in fulfillment run `38209568044`; the revoked-lease test passed. The expanded preflight schema-coverage change (`33a32ee`) passed all three workflows: quality `38209632272`, syntax `38209632254`, fulfillment `38209632265`. Follow-up documentation commits also passed all three workflows: `5985ec58` (`38209640961`, `38209640956`, `38209640963`), `10e24cad` (`38209648824`, `38209648887`, `38209648851`), `d143dd7f` (`38209663121`, `38209663128`, `38209663111`), and `e3f24a21` (`38209670216`, `38209670195`, `38209670175`). The expanded admin CSRF regression test in commit `b371a0cb` passed quality run `38210544538`, syntax run `38210544554`, and fulfillment run `38210544547`; the later documentation commits `7a227ed9` and `f632ce18` also passed all three workflows (runs `38210555764`, `38210555798`, `38210555794` and `38210566728`, `38210566658`, `38210566561`, respectively).
- Preflight schema coverage expanded in commit `33a32eefd5d2d14a031978fe58b46af083316247`: it checks the recorded foundation migration `001_shared_hosting_foundation`, additional core tables (`audit_logs`, `provider_products`, `product_variants`, `settings`, `banners`, `webhook_events`, `account_validations`), and—when settlement backup is enabled—both `monthly_settlement_exports` and `app_settings` as well as migrations 006–007. All three workflows passed for this change.
- Supplier response mapping tests cover account-field mapping and common status normalization.
- `bin/preflight.php` checks required runtime/schema conditions and ensures payment, supplier order and settlement-backup flags remain disabled.
- Google Drive settlement export scaffolding exists but is disabled by default and is not validated against a real Google account.
- Admin login uses password hashing, session ID regeneration, CSRF tokens, DB-backed login throttling and audit logs.
- Admin authorization fix: commit `76fc5ad5685dad8cb63b89e1d2b1bb41614a967e` denies OPERATOR-role POST attempts to change the Google Drive folder setting.
- Admin role checks were centralized in `admin_can_manage_settings()` in `app/services/admin_auth.php` (commit `a9b8c4c7321fab6924f4175d1419ce0acaa74c65`). Both `public/admin/index.php` (Drive setting) and `public/admin/game-mappings.php` (supplier mapping mutations) now call that helper (commits `da72aa030fe641a4a3ed7b11191e303b790fc78f` and `b13b7ade7f24af5cb742e67c001da28ffb916d89`).
- Added `tests/admin-authorization.php` for role allow/deny cases and wired it into `.github/workflows/php-quality.yml` (commits `6134f52527d8d0882b92e237847ba3ed3086b197` and `a6a15d6131b21d577611153075ff8b1106665ed6`).
- Audit row updated in `docs/production-readiness-audit.md` (commit `6f19570577ed02383dfb4a571e8d018b1f9b6c6c`).
- Queue race audit: found that a duplicate queue job could see a supplier transaction in fresh `PROCESSING` state while another worker was still making the supplier request, and incorrectly force manual review. `app/services/fulfillment.php` now treats `PROCESSING` rows updated within the last 10 minutes as active and returns `IGNORED` for the duplicate job; older ambiguous states still go to manual review (commit `43ba2b74f1dae5644cc0d0fdfe5574ad09a7d5c9`). This is source-level mitigation; DB-backed concurrency tests are still required.
- Updated audit notes with the queue finding and observed CI step results (commit `ef878ab554a96271401df9c468fed3c7c40e5eab`).
- Added `tests/duplicate-queue-processing-integration.php`, which creates a disposable paid order plus a fresh supplier `PROCESSING` row, invokes `fulfillment_process()`, and asserts the duplicate path returns `IGNORED` without changing order/supplier status or calling a supplier endpoint. Added the test step to `.github/workflows/fulfillment-tests.yml` (commits `a9d8a8a4fbf6f629038fb24fcd74efa7132ed0b6` and `25b03689aec41a4015ce8cc2cd74bcab085f7185`). Audit updated at `c7b682f695a68999217cc7bb5700ad422ceb2041`.
- MySQL integration run `38201807243` confirmed the new duplicate-queue test passed, as did duplicate callback and stale queue recovery tests. It then failed in `tests/uncertain_supplier_integration.sh` because that fixture created a fresh `PROCESSING` supplier transaction, while the new guard correctly treats records updated within 10 minutes as an active request. Corrected the fixture to set `updated_at` to 11 minutes ago so it models an abandoned request (commit `92a0ddb13644c353966dd520567ebf606e8e586b`). Audit records this finding in commit `205836d7482fe60a48991bfd22294ef6b3b5fbce`. Verify rerun before treating integration suite as passing.

## Latest work log (2026-10-12)

- Reviewed `public/admin/index.php`, `public/admin/game-mappings.php`, and `app/services/admin_auth.php`.
- Centralized the allowed mutation roles (`SUPER_ADMIN`, `ADMIN`) in `admin_can_manage_settings()` and applied it to both Drive folder settings and game-provider mapping changes.
- Added role-policy regression tests for allowed roles, `OPERATOR`, missing/empty roles, and case sensitivity; wired the test into GitHub Actions.
- Updated `docs/production-readiness-audit.md` and this handoff after the code changes.
- Verification update: GitHub Actions runs `38201807339` (`PHP quality checks`) and `38201807369` (`PHP and JavaScript syntax checks`) completed successfully for code commit `c7b682f695a68999217cc7bb5700ad422ceb2041`. In `38201807243` (`Fulfillment safety tests`), the PHP unit-test job passed. MySQL integration confirmed duplicate queue processing, duplicate callback, and stale queue recovery, then failed at the ambiguous supplier test because its fixture was not aged beyond the new 10-minute guard. The fixture was corrected in commit `92a0ddb`; recheck the rerun before treating the suite as passing. No local PHP run is claimed.

## Tests and what is actually verified

GitHub Actions workflow: `.github/workflows/php-quality.yml`
- PHP 8.2 lint over PHP files in `app`, `bin`, `config`, `public`, and `tests`.
- `tests/duitku-callback-result-state.php`
- `tests/checkout-request-fingerprint.php`
- `tests/fulfillment-mapping.php`
- `tests/admin-authorization.php`
- `tests/duplicate-queue-processing-integration.php` (MySQL integration workflow only; pending run result)

Runs `38202791491` (PHP quality), `38202791510` (PHP/JavaScript syntax), and `38202791455` (fulfillment safety tests) all completed successfully for commit `455499aa42c3f99fdd31587db2e21d36613da408`. The refund crash-window recovery (`e221f01`) and integration test (`7908e11`) were verified by fulfillment run `38203732917`: both `php-tests` and `mysql-callback-integration` passed, including the explicit supplier rejection/refund integration step. After that, `app/services/fulfillment.php` was hardened in commit `30c0735` so a late supplier HTTP response cannot overwrite a `REVIEW` state written by stale-lock recovery. PHP quality, syntax, and fulfillment safety workflows for `30c0735` all passed (runs `38204088585`, `38204088548`, `38204088535`). A deeper follow-up in commits `3cf9eaf` and `b039424` serializes both normal and uncertain supplier response persistence in a DB transaction that locks the order before the supplier transaction, matching stale-lock recovery's lock order. CI for these newest commits is pending. A local clone previously failed because the execution environment could not resolve `github.com`. CI does not certify live provider/payment behavior.

## Production safety — hard stop

Keep all of these disabled until controlled staging tests pass:
- `CHECKOUT_PAYMENTS_ENABLED=false`
- `SUPPLIER_ORDERS_ENABLED=false`
- settlement/Google Drive backup flag disabled.

Do not deploy or initiate live transactions, real supplier orders, or Google Drive writes while auditing. Never put API keys, Duitku secrets, supplier credentials, OAuth tokens, or production `.env` contents in Git or chat.

## Highest-priority next steps

1. **Continue admin security audit**: `public/admin/` currently contains `index.php` and `game-mappings.php`; both POST routes use CSRF validation, and privileged mutations use `admin_can_manage_settings()`. A regression test covers valid and invalid/missing CSRF tokens alongside role checks; it passed quality, syntax, and fulfillment workflows in runs `38210544538`, `38210544554`, and `38210544547`.
2. Inspect the rest of `public/admin/index.php` and each admin page for privilege-sensitive actions. Confirm the new Drive-folder role check works for OPERATOR and allowed roles.
3. Review the queue and fulfillment transaction boundaries: concurrent worker claims, lock-token conditional updates, pending supplier responses, retry limits, stale-lock recovery, duplicate queue jobs, and reconciliation path. A MySQL concurrency test was added in `tests/queue-worker-concurrency-integration.php` and wired into the fulfillment workflow before callback tests. First CI attempt failed because a live parent PDO connection was inherited across `pcntl_fork()`, causing `MySQL server has gone away` during parent verification/cleanup. Commit `94f806c` changes the fixture to use a direct fresh PDO connection, close the parent connection before forking, reconnect after child exit, and use a fresh cleanup connection. Its CI result is pending; do not count concurrency coverage as passing yet. Do not alter safe manual-review behavior without evidence.
4. Review all migrations 001–010 and `bin/preflight.php` against a clean MySQL/MariaDB install and an upgrade path. Execute only when a real test DB/runtime is available.
5. Expand tests for pricing/reserve boundaries, callback duplicate/conflicting transitions, checkout idempotency concurrency, refund idempotency, admin roles/CSRF, and queue concurrency.
6. Continue with DB-backed concurrent-worker tests and full staging checks. The new test starts two independent PHP processes/PDO connections and asserts they claim distinct ready jobs with separate lease tokens. The first run exposed an inherited parent PDO socket; commit `94f806c` closed it before forking, and commit `5632ac5657` also releases the parent statement before the fork. The latest fulfillment workflow `38213945487` passed, including `Test concurrent queue claims against MySQL`; PHP quality `38213945851` and syntax `38213945489` also passed. Checkout UUID validation now has valid/malformed input tests (`6fa1d80`). Review of concurrent checkout handling found that a MySQL deadlock/serialization failure during simultaneous use of the same absent idempotency key would fail closed but not attempt to reuse the winning request. Commit `103cf16` classifies duplicate-key, deadlock, and lock-wait conflicts for a post-rollback winner lookup; it does not re-call Duitku. Commit `59c893b` adds unit coverage for SQLSTATE classification. The initial fixture failed because PHP `PDOException` requires an integer code; commit `c2568d9` fixed it. CI for `c2568d98b0` passed PHP quality (`38239039733`), syntax (`38239039554`), and fulfillment (`38239039583`). A PHP-quality failure on older documentation commit `5920d6f0a5` was confirmed to be the pre-fix fixture and is superseded by the passing corrected commit. The fulfillment integration workflow now applies migrations 009 and 010 after 008 (commit `ec6a6bc`); its workflows passed after documentation updates in runs `38237885006`, `38237884979`, and `38237885082`. Atomic supplier response persistence/lock ordering and worker lease revocation are covered by passing CI runs listed above.
7. Keep `README.md`, `docs/production-readiness-audit.md`, and this handoff updated after substantive changes.

## Required staging exit checklist

- Clean install and upgrade migrations 001–010 on the target MySQL/MariaDB version.
- Verify private `.env`, file permissions, HTTPS, PHP extensions, outbound HTTPS and cron capability on the selected shared-host plan.
- Test valid/invalid Duitku HMAC, wrong amount/order, duplicate callback, pending-then-paid, and conflicting status transitions.
- Run same idempotency key concurrently with same and different payloads; ensure only one order/payment exists and payload mismatch never reuses a payment URL. Unit coverage validates request fingerprint normalization; UUID shape regression cases were added in commit `6fa1d80` and corrected classifier unit cases passed PHP quality and syntax in runs `38242799852` and `38242799858`. The MySQL integration workflow applies migrations 009 and 010 after 008. Added `tests/checkout-idempotency-concurrency-integration.php` and wired it into `.github/workflows/fulfillment-tests.yml` (commits `e64061e` and `d6f848f`). It forks two PHP processes with independent PDO connections, delays the first mocked gateway response so the second request observes a committed order without a URL, and asserts one order/payment, exactly one mocked Duitku call, reuse of the winner URL, and rejection of changed account data for the same key. The checkout service now waits up to 22 seconds for the winning URL on the same-key path and after classified DB race errors (commit `623d0c6`). The concurrent checkout integration passed in fulfillment run `38240603363`; the real MySQL deadlock-classification integration passed in run `38242948715`. Latest follow-up documentation commit `e33a8140be` passed quality (`38242987438`), syntax (`38242987397`), and fulfillment (`38242987424`). Full-path recovery after an induced deadlock remains an explicit test gap.
- Run multiple queue workers; test success, pending, definitive failure, timeout, process crash, stale lock and recovery. Reconcile provider status before any manual retry.
- Verify supplier API request signing, exact product codes, ID/zone mapping, status meanings and provider transaction references with sandbox credentials and known test accounts.
- Reconcile refund amount/status and the actual Duitku refund capability/process; never treat a local refund row as proof money was returned.
- Verify admin roles, CSRF, login throttling, cookie security behind the actual proxy, audit trail and unauthorized POST behavior.
- Test Google Drive export only in staging after migrations 006–007 and OAuth/folder setup; test interruption recovery, duplicate prevention, access permissions and independent DB restore.
- Test backups and restore, cron monitoring, queue backlog alerts, and incident/manual-review procedures.

## Working style requested by the user

- Continue directly with concrete repository work where tools permit; avoid repetitive setup questions.
- Keep explanations in Indonesian.
- Do not claim completion merely because files exist. State what was changed, which checks were actually performed, and what remains blocked.
- If a tool/network restriction prevents a test or edit, record that limitation here and in the audit notes.


## Latest work — checkout deadlock and admin HTTPS proxy trust (2026-10-12)

- The real MySQL deadlock classification test passed in fulfillment run `38242948715`; both `mysql-callback-integration` and `php-tests` completed successfully. Documentation follow-up workflows on commit `e33a8140be` passed quality (`38242987438`), syntax (`38242987397`), and fulfillment (`38242987424`). Full-path recovery from a deliberately induced deadlock inside `checkout_create_payment()` remains untested and is still a production-readiness gap.
- Admin session cookie setup previously trusted `HTTP_X_FORWARDED_PROTO=https` unconditionally. Commit `875866f` introduces `admin_request_is_https()`; forwarded proto is accepted only when `ADMIN_TRUST_PROXY_HTTPS=true`, while direct `HTTPS=on` remains accepted. `.env.example` documents the setting as false by default; only enable it when the trusted reverse proxy strips client-supplied forwarded headers. Regression tests in `tests/admin-authorization.php` cover untrusted forwarded headers, explicit proxy trust, multi-value headers, and direct HTTPS.
- For commit `022fe0052b`, PHP quality run `38244155564` and syntax run `38244155580` passed. Fulfillment run `38244155610` was still in progress at the last check; recheck it before claiming all workflows passed. Do not deploy or enable live payment/supplier flags.


## Duitku failed-callback state consistency (2026-10-12)

- Audit found the definitive `resultCode=01` callback marked `payment_transactions.status=FAILED` and `orders.payment_status=FAILED` but left a still-pending `orders.order_status=PENDING` behind. Commit `e9ffef5` now also transitions order status from `PENDING` to `FAILED`, without overwriting later/non-pending order states.
- Added a second disposable pending order fixture to `.github/workflows/fulfillment-tests.yml` and extended `tests/duitku_callback_integration.sh` (commit `dabd808`) to send a valid signed `01` callback and assert payment/order both become `FAILED` and no fulfillment job is added. This is test-only fixture data; no live API calls.
- CI for this callback-state change and integration assertion must be checked before marking it verified. Keep live payment/supplier flags disabled.


Integration-test fixture correction: commit `649d3e2` fixes shell variable expansion in the failed-callback test (`tests/duitku_callback_integration.sh`). The latest CI triggered by this correction is pending; verify the MySQL integration job before considering the regression covered.


## Latest verification — failed Duitku callback state consistency (2026-10-12)

- Code change: commit `e9ffef5` closes a still-pending order as `FAILED` when a valid Duitku server-to-server callback has `resultCode=01`; it does not overwrite non-pending order status.
- Integration fixture correction: commit `649d3e2cd1` fixes shell variable expansion in `tests/duitku_callback_integration.sh`. The MySQL integration job and PHP test job both passed in fulfillment run `38246227164`; the `mysql-callback-integration` job specifically completed successfully. Follow-up documentation workflows on commit `7e7723095e` also passed: PHP quality `38246259533`, PHP/JavaScript syntax `38246259565`, and fulfillment safety `38246259539`.
- The integration test verifies the signed failed callback marks payment and pending order failed, does not enqueue fulfillment, and that duplicate successful callbacks enqueue only one job. No live gateway/supplier calls were made.
- Next focused audit gap: build a controlled integration test for full `checkout_create_payment()` recovery after a real deadlock, not just classifier behavior. Keep checkout/payment, supplier order, and settlement backup flags disabled; do not deploy.


## Duitku callback rejection tests added (2026-10-12)

- Commit `fc7b8788890d6de2` extends `tests/duitku_callback_integration.sh` to verify that an invalid HMAC signature and a correctly signed callback with the wrong amount both receive HTTP 400 and leave payment/order pending with no fulfillment job. The existing valid duplicate-success and definitive-failure cases remain in the same MySQL integration script.
- Verified: PHP quality (`38251844581`), PHP/JavaScript syntax (`38251844521`), and fulfillment safety (`38251844517`) all passed for commit `fc7b8788890d6de2c62f231b9016d67a39c30765`; the fulfillment run's `mysql-callback-integration` job completed successfully, including both new negative cases. Follow-up documentation workflows were triggered separately. This is a local test fixture only; no live gateway or supplier requests were made. Production flags remain disabled.


Verification update: callback rejection coverage from commit `fc7b8788890d6de2c62f231b9016d67a39c30765` passed all three CI workflows (quality `38251844581`, syntax `38251844521`, fulfillment `38251844517`). The MySQL integration step confirms invalid HMAC and mismatched amount return HTTP 400 while leaving the payment/order pending and adding no fulfillment job; the duplicate-success and failed-payment callback assertions also pass. Full-path deadlock recovery in `checkout_create_payment()` remains an open production-readiness test gap.


## Checkout concurrency regression strengthened (2026-10-13)

- Commits `93a13db9ac` and `f7508904ed` remove the deliberate 300 ms stagger between the two forked checkout requests so they compete more directly for the same idempotency key. Commit `f7508904ed` adds a filesystem start barrier so both child processes begin the checkout path together. This increases coverage of the absent-key/gap-lock race while preserving the mock Duitku creator and assertions for one order, one payment, one gateway call, and winner URL reuse.
- CI triggered by these changes is pending; inspect the MySQL integration job before calling the new race setup verified. This still does not prove the DB actually emitted error 1213 from inside `checkout_create_payment()`; the existing direct deadlock integration only proves classification. If CI is flaky or no deterministic deadlock is observed, document that limitation rather than claiming full-path deadlock recovery proven. No live requests; production flags remain disabled.


## Latest MySQL integration work (2026-10-13)

Workflow commit `bb8549257b9b35bf5214152c50905192e6ab05a4` changes the concurrent checkout idempotency test to run three independent iterations against MySQL with mocked Duitku. Existing checks still assert one order/payment, exactly one gateway mock call, identical payment URL reuse, and rejection of a changed account payload. Await CI for this workflow commit. This increases stress coverage but does not prove full-path deadlock recovery inside `checkout_create_payment()`; keep that gap open. Do not enable live checkout/supplier flags or deploy. No live APIs were called.


CI note: initial 3-iteration MySQL stress run found the test incorrectly assumed child 1 always wins; two iterations passed and the third failed that assertion. Commit `d518075b5d8b3b8bfa5c4b8f284eb4d072a24813` makes the assertions scheduling-independent (exactly one creator and one reuser). Verify the new CI before considering it green. This was a test assertion flaw, not evidence of checkout code failure. Full-path deadlock recovery remains an open test gap.


## Checkout full-path deadlock recovery test (2026-10-13)

Commit `4e33bf5dcd08` passed quality (`38283808180`), syntax (`38283808125`), and fulfillment (`38283808131`); MySQL integration passed three checkout idempotency iterations and the separate deadlock classifier test. Commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` adds a stronger assertion around the three-iteration checkout stress test: the MySQL `Innodb_deadlocks` counter must increase during that window. The integration already asserts both callers succeed, one order/payment, exactly one mock Duitku call, and URL reuse. Await CI for `570d6daf` before claiming this proves full-path recovery. A failed counter assertion means the run did not observe a real deadlock; it must not be described as a code failure or a pass. No live APIs; do not deploy or enable checkout/supplier flags.


CI result for commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` (2026-10-13): PHP tests passed, PHP/JS syntax and PHP quality passed, and all three concurrent checkout iterations passed their idempotency assertions. However, MySQL reported `Innodb_deadlocks` before=0 and after=0. Therefore the stress run did **not** exercise a real deadlock inside `checkout_create_payment()`; it does not prove full-path deadlock recovery. The workflow was adjusted in commit `7113cdae9c22007fcf5b90db523b03354aef2960` to report this limitation without failing otherwise-valid stress tests. Next task: design a deterministic integration schedule that provokes a real 1213 during checkout, or retain the gap explicitly. The separate real-deadlock classification test remains useful but is not equivalent. No live APIs or deployment; checkout/supplier flags remain disabled.


Workflow verification for commit `7113cdae9c22007fcf5b90db523b03354aef2960`: PHP/JS syntax `38315071913`, PHP quality `38315071804`, and fulfillment safety `38315071794` all passed. The MySQL integration job passed, including three concurrent checkout idempotency runs; it reported no natural deadlock rather than failing. Documentation follow-up commit `3fe14f4ca3a04f5a686b61fd5efaab6708c9e34a` also has syntax `38315106885` and quality `38315106999` passed, and fulfillment `38315106585` passed both PHP and MySQL jobs. Next: build a deterministic deadlock choreography if feasible; otherwise keep full-path recovery explicitly unverified. No live APIs/deployment; production flags remain disabled.


## Deterministic checkout deadlock choreography added (2026-10-13; CI pending)

Commit `58ee5b048800c490538ad04cb754b58befc680a0` updates `tests/checkout-idempotency-concurrency-integration.php`: after both child processes are forked, the parent acquires a lock on the test product row, releases the child start gate, and polls `performance_schema.data_lock_waits` until both checkout workers are blocked by that parent lock. The parent then releases the product row. This is intended to make both children retain their missing-checkout-key gap locks while competing for the same product row, provoking an InnoDB deadlock in the real `checkout_create_payment()` transaction. The test asserts both callers succeed, exactly one order/payment and mock gateway call exist, both callers share the winning URL, and the global InnoDB deadlock counter increases during that test. This is not verified until CI passes; if the lock choreography does not trigger a deadlock or the recovery fails, inspect MySQL job logs and revise rather than claiming success. No live APIs or deployment; production flags remain disabled.


### Deterministic checkout deadlock attempt — negative result (2026-10-13)

- CI for test commit `58ee5b048800c490538ad04cb754b58befc680a0` failed at the new assertion: the MySQL global `Innodb_deadlocks` counter remained `before=0 after=0`. The test had observed both workers waiting on the parent-held product row, but releasing that lock did not produce a deadlock. Therefore the choreography did **not** prove full-path deadlock recovery; do not interpret the failed assertion as a confirmed product-code regression.
- Reverted only `tests/checkout-idempotency-concurrency-integration.php` to the previously passing synchronized stress test in commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac`. It continues to verify same-key concurrency, one order/payment, one mocked Duitku call, URL reuse, and payload-mismatch rejection. CI for the revert is pending.
- The separate MySQL deadlock integration still proves the SQLSTATE/driver error classifier handles a real 1213 victim, but it does not prove that the complete checkout function recovers after a real deadlock. Keep this gap explicit. Next work should design a deterministic full-path test without relying on the current product-row lock choreography, or leave the gap open rather than claim success.
- No live gateway/supplier APIs or deployment were used; production flags remain disabled.


### Verification after reverting the unproven deadlock choreography (2026-10-13)

Commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac` passed all three workflows: PHP quality `38319787900`, PHP/JavaScript syntax `38319788215`, and fulfillment safety `38319788002`. The fulfillment run's PHP tests and MySQL integration job both completed successfully, including the restored concurrent checkout idempotency stress test. This verifies the restored stress coverage only; full-path recovery after an actual deadlock inside `checkout_create_payment()` remains unproven. Documentation-only follow-up commit `ef5e72894d4ffa7bcc51ca2c577a356102f4511e` passed quality `38319827522` and syntax `38319827634`; its fulfillment run `38319827341` was still in progress at last check. No live APIs or deployment.


### New deterministic checkout deadlock integration attempt — awaiting CI (2026-10-13)

- Commit `69b4cd0e3105d5fa061c34e16e42d08f36a0b01c` adds an optional pre-product-lock callback to `checkout_create_payment()`, guarded so it is accepted only when `APP_ENV=test`. Production callers omit the callback.
- Commit `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` changes the MySQL checkout integration test so two independent same-key checkout transactions lock seeded provider rows in opposite order inside the checkout transaction. Both workers synchronize at a filesystem barrier before requesting the second row, so InnoDB should choose one real deadlock victim (1213). The test then requires both checkout calls to succeed, exactly one mock gateway call/order/payment, the same payment URL for both callers, rejection of a mismatched account payload, and an increased global `Innodb_deadlocks` counter.
- This is a new test strategy and is **not yet verified**. Await MySQL integration CI and inspect exact logs. If it fails, record the observed error and do not claim recovery. The hook is guarded by `APP_ENV=test`; no live APIs or deployment were used, and production transaction flags remain disabled.


### Deadlock integration test harness correction (2026-10-13)

The first CI attempt for commit `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` failed before checking the deadlock counter because the test had deliberately unset the parent PDO before forking and then tried to query the counter through that unset variable. This was a test-harness connection-lifecycle bug, not a deadlock-recovery result; no claim about the application path can be drawn from that run. Commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reconnects through `database()` before reading the post-run counter and is awaiting CI. Continue by inspecting the MySQL integration logs for all three attempts and the counter assertion. Production flags remain disabled; mock gateway only.


### Direct deadlock evidence replaces status-counter inference (2026-10-13)

The corrected test run for commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reached the checkout assertion but reported `before=0 after=0`. The integration test did not produce a recorded exception, and the global status counter path is not a reliable proof source in this run. Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` now captures the actual `PDOException` from each worker's second provider-row lock and asserts exactly one driver error `1213` classified by `checkout_is_idempotency_race()`; both checkout calls must still return the same order/URL with one mock gateway call. Workflow commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` removes the misleading global status counter and runs the deterministic test three times. CI is pending. Do not claim full-path recovery until the MySQL integration job passes all three iterations.


### Full-path checkout deadlock recovery verified in MySQL CI (2026-10-13)

- Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` passed PHP quality `38321635015`, PHP/JavaScript syntax `38321635052`, and fulfillment safety `38321635067`. The MySQL integration job completed successfully, including all three iterations of `tests/checkout-idempotency-concurrency-integration.php`.
- In each iteration, the integration test's opposing provider-row locks occur inside `checkout_create_payment()`; it captures the actual exception from the second lock attempt and asserts exactly one driver error 1213 classified by `checkout_is_idempotency_race()`. Both callers must still succeed with one order/payment, one mock Duitku call, identical payment URL, and changed-payload rejection. Passing all three iterations verifies the full-path deadlock recovery scenario using the mocked gateway.
- The previous `Innodb_deadlocks` global-status read remained `0 -> 0`; the workflow step was subsequently changed in commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` to rely on the direct 1213 exception evidence instead. That combined workflow's MySQL job was still in progress at last poll. Commit `c58719a03166cedf4537df373b18c2501282e1d9` updates the test success message to make the verified assertion explicit; its CI is pending.
- No live Duitku or supplier APIs were called and no deployment occurred. Keep production flags disabled until separate staging exit checks pass.


### Checkout gateway timeout retry guard verified (2026-10-13)

- Commit `808f17e33d0baa2d3c19a60b9deea8586b9ba2d4` updates `checkout_reuse_winning_request()`: when an existing checkout has no persisted gateway URL but has a recorded `orders.last_error`, same-key retry now fails promptly with a manual-review message instead of polling for up to 22 seconds. It does not repeat the Duitku request, because an HTTP timeout is ambiguous: the gateway may have created a payment even when the response was lost.
- Commit `a94f8449b333466a33aea585f558a145fd49eb4b` extends the MySQL checkout integration test with a mocked gateway timeout after the order/payment transaction commits. It asserts the first call records the issue, a retry returns promptly, the mock gateway is called only once, one order/payment remains, no URL is fabricated, and `last_error` is set for review.
- Verified CI for `808f17e3`: PHP quality `38326174963`, syntax `38326174976`, fulfillment `38326175007` all passed. Verified CI for `a94f8449`: PHP quality `38326198802`, syntax `38326198808`, fulfillment `38326198783` all passed; the MySQL integration job and PHP tests completed successfully.
- This is still mock-gateway integration evidence, not proof of Duitku's live timeout behavior or reconciliation APIs. Keep live payment/supplier flags disabled. Next: inspect whether admin tooling has a safe way to reconcile an order left pending after gateway timeout, and document an operational resolution path without retrying an ambiguous request automatically.


## Latest work — read-only checkout reconciliation hints (2026-10-13)

- Commit `2e6efab01a524af252bc27c78bed61ab59fe6cd5` updates `public/admin/index.php` to show a read-only “Catatan checkout” column: whether the Duitku payment URL was saved, gateway expiry timestamp, and escaped `orders.last_error` marked for review. It does not expose the actual payment URL or add payment/order mutation controls. Transaction table empty-state colspan was updated from 10 to 11.
- Verification for code commit `2e6efab0`: PHP quality run `38329325146` passed; PHP/JS syntax run `38329325150` passed; fulfillment safety run `38329325168` passed. The fulfillment run's `php-tests` and `mysql-callback-integration` jobs both completed successfully.
- Documentation follow-ups for the ambiguous gateway timeout protection passed all checks: handoff commit `813ec60ad4d8c93086b9e915b226a95f709599eb`, run `38329275031` (PHP and MySQL jobs success); audit commit `c2e38d90a58cb6748bec23b7ddd02463f22b3950`, run `38329284829` (PHP and MySQL jobs success).
- The admin hints improve operator visibility only. They do not query Duitku, prove whether a payment was created, or authorize a retry/refund. Next work should define a safe, auditable manual reconciliation procedure and review official Duitku status-query capabilities before implementing any live gateway integration. Do not make live API calls during this audit.
- Production checkout and supplier-order flags remain disabled. No deployment, live payment, or supplier order was performed.


## Duitku timeout reconciliation research (2026-10-13)

Official Duitku API reference: https://docs.duitku.com/api/id/ (section “Cek Transaksi”).
- Status lookup uses POST to `https://sandbox.duitku.com/webapi/api/merchant/transactionStatus` for development and `https://passport.duitku.com/webapi/api/merchant/transactionStatus` for production.
- Request fields: `merchantCode`, `merchantOrderId`, `signature`; current documentation specifies `HMAC_SHA256(merchantCode + merchantOrderId, apiKey)`. Older documentation pages show MD5, so do not copy legacy snippets.
- Status lookup response codes are **not the same semantics as callback result codes**: the current status-query documentation lists `00=Success`, `01=Process`, `02=Failed/Expired`. The existing callback mapper's `01=FAILED` applies to callback payloads only; do not reuse it for status-query responses.
- Duitku explicitly warns against repeated automatic status-query requests because rate limiting may block calls for about an hour. This supports an operator-initiated, audited lookup for ambiguous checkout cases—not a polling cron.
- Safe manual process: search by exact `merchantOrderId`; compare response order ID and amount to local `payment_transactions.amount`; store gateway reference and response evidence in an audit record; if status is `01` (Process), leave it pending and do not create a replacement; only consider replacement after an authoritative failed/expired response is matched and local payment/order state is checked under a transaction. A `00` success must follow the normal verified callback/state-transition path, not an ad-hoc admin status edit. Network errors, malformed response, unknown codes, amount/order mismatch, or uncertainty stay in manual review.
- No status-query API call was made and no live credentials were used. Before coding this integration, add unit tests for signature/response validation and status-code mapping, then mock-only integration tests for mismatch, process, success, failed/expired, rate/error handling, and concurrent callback interactions. Do not add a repeated cron poller.


### Duitku transaction-status validation helpers — CI verified (2026-10-13)

- Added `duitku_transaction_status_signature()`, using HMAC-SHA256 over merchant code + merchant order ID; it is separate from callback signature validation.
- Added `duitku_transaction_status_state()` with the status-query meanings `00=SUCCESS`, `01=PROCESS`, `02=FAILED_OR_EXPIRED`.
- Added `duitku_validate_transaction_status_response()` to require exact merchant-order ID and integer amount matches, reject unknown codes/malformed fields, and return normalized evidence without changing database state.
- Added `tests/duitku-transaction-status.php` and wired it into `.github/workflows/php-quality.yml`.
- Verified on workflow commit `8e6e46497107`: PHP quality run `38331891216` passed (including the new unit test); syntax run `38331891186` passed; fulfillment safety run `38331891209` passed both `php-tests` and `mysql-callback-integration`. The preceding service and test commits also passed their workflows.
- **Not implemented yet:** network transport to Duitku status endpoint, admin action, audit event persistence, or any payment/order mutation. These helpers are validation only. Before adding transport, resolve official docs' conflicting content-type wording (the page labels the request x-www-form-urlencoded while its examples show JSON) and verify only against sandbox. Keep lookup operator-triggered and rate-conscious; do not add cron polling. No live calls/deployment; production flags remain disabled.


### Admin-triggered Duitku status reconciliation — mock-tested (2026-10-13)

- Added `public/admin/reconcile.php` and a link from the admin transaction page for `ADMIN`/`SUPER_ADMIN` roles only. It requires a valid admin session and CSRF token.
- Lookup is operator-triggered for one exact local `merchant_order_id`; no cron/polling. Same payment transaction is throttled for at least 60 seconds based on audit log history.
- The route queries the local payment/order, requires a whole-rupiah amount, calls the sandbox-only `duitku_status_config()` / `duitku_query_transaction_status()`, validates exact merchant order ID and amount, and writes a `DUITKU_STATUS_LOOKUP` audit event with the normalized status/reference or a sanitized error. It never changes payment/order states, enqueues fulfillment, or creates replacement payments.
- Added test-only transport injection guarded by `APP_ENV=test`; tests verify fixed sandbox endpoint, HMAC-SHA256 signature, one transport call, and rejection of injected transport outside tests. No live request was made.
- The official [Duitku API reference](https://docs.duitku.com/api/id/) labels transaction-status requests x-www-form-urlencoded but its PHP/cURL examples send JSON with `Content-Type: application/json`. The implementation follows the executable examples (JSON) but remains sandbox-only; verify actual behavior with sandbox credentials before any production work. Signature uses current HMAC-SHA256, not obsolete MD5. Status meanings are `00=SUCCESS`, `01=PROCESS`, `02=FAILED_OR_EXPIRED`.
- Verified current test commit `05d9f1ee497b`: PHP quality `38334462332` passed; syntax `38334462340` passed; fulfillment `38334462306` passed both PHP tests and MySQL integration. The earlier endpoint refactor's fulfillment run `38334449650` also passed.
- Remaining gap: a verified `SUCCESS` is displayed only. It does not yet feed a controlled reconciliation event through the callback's idempotent transition logic. Do not manually edit statuses or refund based on this read-only page. No live API calls or deployment; production checkout and supplier-order flags remain disabled.


### Follow-up security note — concurrent admin clicks (2026-10-13)

The 60-second cooldown currently checks recent audit rows before calling Duitku, but the check-and-call sequence is not atomic. Two simultaneous POST requests can both pass the check before either records its result. This is a known limitation; do not describe the current cooldown as concurrency-safe. Before production or any real Duitku use, serialize lookup admission using a short DB transaction that locks the payment row and commits a `STARTED` audit/lease record before network I/O, or use a dedicated unique lease row. Do not hold a DB transaction open during the HTTP request. Verify that parallel requests result in at most one outbound call. Current page remains sandbox-only; no live API calls/deployment.


### Atomic admin status-lookup cooldown added (2026-10-13)

Commit `public/admin/reconcile.php` now acquires a `SELECT ... FOR UPDATE` lock on the local `payment_transactions` row, checks recent lookup audit entries while holding the lock, and commits a `STARTED` audit entry before any HTTP request. A concurrent POST for the same payment waits for the lock and then sees the committed `STARTED` record, so it should be rejected by the 60-second cooldown rather than sending a second request. The database transaction is not held open during network I/O. No payment/order state is mutated.

This closes the previously documented check-then-call race by design, but a dedicated two-process MySQL integration test for simultaneous admin status POSTs has not yet been added. Existing CI checks lint/unit/fulfillment flows, not the full authenticated web route's concurrent admission path. Verify the route against the MySQL test environment and add a focused concurrency test before relying on this for production. Sandbox only; no live API call or deployment.


## Latest work — admin status lookup cooldown edge case (2026-10-13)

- Reviewed the current `public/admin/reconcile.php` against the latest atomic cooldown notes.
- Found that a rejected repeat click entered the generic error-audit path and wrote another `DUITKU_STATUS_LOOKUP` row. Because cooldown admission checks this same action, repeated rejected clicks could keep refreshing the 60-second window indefinitely.
- Commit `b8d7fa77e1d2363a561775ce7bbe0b7675e2db9c` adds a `$cooldownRejected` guard: cooldown-denied requests do not create a new lookup audit event. Real admitted attempts still record success/error outcomes. The row lock and committed STARTED marker remain unchanged; the transaction is still released before HTTP I/O.
- CI has not yet been rechecked for this new code commit at the time of this note. A dedicated concurrent MySQL/web-route regression test remains open. No local PHP run, live Duitku call, production payment, supplier order, deployment, or Google Drive write is claimed.


## Follow-up — cooldown regression check wired into CI (2026-10-13)

- Added `tests/reconcile-cooldown-regression.php` to structurally assert the admin route's row-lock/cooldown/STARTED/commit/network ordering and the cooldown-rejection audit guard.
- Added the test to the PHP quality GitHub Actions workflow.
- Test commit: `cce70d749ba6f809a3a2e582f7deeb99dde184e1`.
- Workflow commit: `61ad26239ba960b59e5c379c2de254a226c56b83`.
- This is a structural regression guard only. It does not simulate concurrent authenticated HTTP requests or prove MySQL row-lock behavior; that integration test remains open. No live gateway call, deployment, supplier order, or production payment was performed.


## CI verification update — cooldown regression guard (2026-10-13)

- PHP quality workflow [38340379209](https://github.com/sulistio95/PluQ-I/actions/runs/38340379209) passed on commit `78e58e8f67ea133d39f36acfc6d245f8956adc2a`.
- Confirmed the new `Test admin Duitku cooldown regression guard` step passed along with PHP lint and the existing callback, status-validation, checkout fingerprint, supplier mapping, and admin authorization tests.
- Limitation remains: this is a source-structure regression guard, not a concurrent two-process MySQL/HTTP test. Keep that test as an explicit pre-production requirement.


## Latest progress — concurrent Duitku cooldown integration test (2026-10-13)

- Extracted the atomic MySQL admission transaction into `app/services/duitku_reconcile.php` and wired `public/admin/reconcile.php` to use it. The route sets its no-duplicate-audit guard only for the explicit cooldown exception.
- Added `tests/reconcile-cooldown-concurrency-integration.php` that races two independent PHP processes against the same seeded MySQL payment. Expected: one admission, one cooldown rejection, one audit marker. It makes no gateway request. Added it to the MySQL 8.4 fulfillment CI and updated the structural guard to follow the helper.
- Commits: `ad24834b92ff8fb6887e7e8450b43b026c709b64`, `1efda2505449740b070f7054ab9c472cd83184f8`, `746fb1496499abb7b0d5acfd1ef564351187fc2b`, `bcc94ea9d08e8bd98c74ca6322f35b562c54c030`, `fa8ab44e4390033513db60fc2747e09a8bcfe143`, `804aa234151e808611c59501b0baca63b97183ec`, `bb740f156bcabda93ac315d60939351eb4ae9f99`, `8c7d050ca2e68e33f0068b952e7affe1ee6872a9`.
- **Next:** inspect GitHub Actions for the latest head; if the MySQL integration fails, fix the test/code and update both notes again. Until passing, label the test pending. This is concurrent helper/database admission coverage, not authenticated concurrent HTTP end-to-end coverage. No live APIs or deployment.


### CI follow-up — concurrent reconciliation test harness (2026-10-13)

- Inspected failed run [38343145833](https://github.com/sulistio95/PluQ-I/actions/runs/38343145833): the PHP test job passed; MySQL integration failed in the new test's final count query with `MySQL server has gone away`. Root cause: parent PDO socket remained open across `pcntl_fork()` and was reused afterward.
- Fixed the test to close the parent connection before forking and reconnect after both workers finish. Commit: `8844027fd6cf3290163a3a6cffa21672e4389d62`.
- Await the new CI run before claiming success. If it fails again, inspect the exact failing step/log and update both notes. No live payment/supplier requests.
## Latest work log — 2026-10-13 reconciliation concurrency verification

- Fixed the MySQL test harness so the parent PDO connection is closed before `pcntl_fork()` and a fresh connection is created after both children exit (commit `8844027fd6cf3290163a3a6cffa21672e4389d62`).
- Corrected CI passed on code commit `2ca838957423caa407fb49524ff23e16eff41723`: fulfillment [38347691175](https://github.com/sulistio95/PluQ-I/actions/runs/38347691175) passed both PHP and MySQL jobs, including the three concurrent reconciliation admission attempts; PHP quality [38347691176](https://github.com/sulistio95/PluQ-I/actions/runs/38347691176) and syntax [38347691179](https://github.com/sulistio95/PluQ-I/actions/runs/38347691179) passed. Documentation-only follow-up commit `ddea8e3a5e3ea6080f8716c1a7b389bc0f4e0cf8` also passed fulfillment `38347687156`, quality `38347687161`, and syntax `38347687166`.
- Verified scope is limited to the shared admission helper under two concurrent PHP processes and MySQL: one request is admitted, one is rejected by cooldown, and one marker remains. It is **not** a full authenticated HTTP route test and does not exercise actual outbound calls. No live Duitku calls, supplier orders, or deployment.
- Next: inspect feasibility of a sandbox-only, test-transport-injected HTTP-level test for `public/admin/reconcile.php`, covering authentication, CSRF, privileged role, and duplicate requests. Do not make live API calls. Continue to update this handoff and `docs/production-readiness-audit.md` after each substantive change/test.
### Reconciliation route security regression guard expanded (2026-10-13)

Commit `5877fa6e1fbd75bea0fe812c8e8a56ad4fecd738` extends `tests/reconcile-cooldown-regression.php` to check that the admin reconciliation route retains CSRF validation, privileged-role enforcement, and no direct payment/order mutation or checkout/fulfillment calls. This is a source-structure test, not a full authenticated HTTP test. CI is pending and must be inspected before claiming success. No live Duitku requests or deployment.
### Admin reconciliation HTTP boundary integration test added (2026-10-13)

- Added `tests/reconcile-admin-http-integration.php` and workflow step after local PHP server startup. Commits: test `77a1e0ed67460c84cc14eeea6252937fe5d9f4a8`, cleanup fix `b9e59514989c200e7f73b22c3e2d9fc365518655`, workflow wiring `136f11118aa181a44a533fae93017dcb746e6ddf`.
- Exercises real HTTP/session boundaries with disposable MySQL users: anonymous redirect (302), OPERATOR forbidden (403), invalid CSRF (403), missing explicit action (400), ADMIN form render (200). No valid status-lookup submission, no Duitku network call. Fixtures are deleted in `finally`.
- CI is pending; inspect job logs and fix failures before marking verified. Coverage still does not include duplicate valid HTTP lookup requests or real external transport. Continue updating this handoff and `docs/production-readiness-audit.md` for every substantive change/result.
### CI runner anomaly after adding HTTP boundary test (2026-10-13)

- New HTTP integration test and workflow wiring are in commits `77a1e0ed67460c84cc14eeea6252937fe5d9f4a8`, `b9e59514989c200e7f73b22c3e2d9fc365518655`, and `136f11118aa181a44a533fae93017dcb746e6ddf`; notes committed at `4927d490bbaf89542026332c1a5239d4cb47bd0f` and `ab23e93e316be34e7dd6bc7db7b14f6b9094f356`.
- Initial workflows `38394970823` (fulfillment), `38394970881` (syntax), and `38394970906` (PHP quality) failed within seconds with no job step summaries/log URL. Job-log endpoint returned 404, so root cause is not known and must not be labeled a test-code failure without evidence.
- Requested reruns for all three failed workflows; check their latest attempt/results next. A PHP 8.4 local syntax check of the script's function/HTTP client portion passed, but full PHP 8.2 CI and runtime integration remain unverified.
- Do not claim new HTTP test green until reruns finish. Keep production and external Duitku calls untouched.
### Follow-up CI attempts still fail before exposing job steps (2026-10-13)

- Reruns of the previous failed workflow attempts failed immediately too. Fresh runs triggered by commit `24d6bce9215add73efbfa6a13703c2f661011bd8` failed immediately: fulfillment [38395199538](https://github.com/sulistio95/PluQ-I/actions/runs/38395199538), PHP quality [38395199540](https://github.com/sulistio95/PluQ-I/actions/runs/38395199540), syntax [38395199525](https://github.com/sulistio95/PluQ-I/actions/runs/38395199525).
- Job payloads have `steps: null`, no log URL, and log endpoint returns 404. This is currently an opaque pre-step failure, not evidence that a particular PHP test failed. The new HTTP integration test remains unverified; do not claim CI is green.
- Next: investigate Actions runner/repository execution availability or rerun when logs are accessible. Keep production/payment/supplier API interactions disabled. Both this file and `docs/production-readiness-audit.md` were updated to capture this status.


## Latest continuation note — CI runner diagnosis (2026-10-13)

- Rechecked current Actions runs: fulfillment `38395277059` and PHP quality `38395277049` on `fd167feb7018cecd25365275654acdb973f6066b` fail within seconds; the job payloads expose `steps: null` and `logs_url: null`. Syntax run `38395199525` shows the same pattern. This affects unrelated workflows before their first visible step, so it does not establish a failure in the HTTP integration test.
- Root cause is still unknown. Next: check repository Actions settings, account billing/minute limits, and runner provisioning; avoid speculative source changes and do not label CI successful.
- Reviewed `tests/reconcile-admin-http-integration.php` against `app/services/admin_auth.php` and `database/schema.mysql.sql`. The test covers anonymous redirect, OPERATOR denial, invalid CSRF denial, unknown POST action rejection, and authorized admin form rendering. It never submits the real status-lookup action, so it must not call Duitku. Fixture cleanup uses `finally`. Full runtime/CI verification remains pending.
- Keep payment/provider live execution and deployment disabled. Update this handoff and `docs/production-readiness-audit.md` after every material code or test change.


- Additional confirmation: documentation-only commits `309c6b8b060ad4c10a20af77b0609162cbc06f6a` and `26aa6b64ee5f71acd1c34de9bbe9adc007e311e6` also triggered all three workflows and all failed immediately (runs `38395658550`, `38395658689`, `38395658589`, `38395668065`, `38395668156`, `38395668061`). Continue treating the cause as unknown and investigate Actions settings/billing/runner provisioning first.


## Follow-up — Actions runner provisioning investigation (2026-10-13)

- Compared the repeated PluQ-I symptom (all three workflows fail within seconds; jobs have `steps: null` and `logs_url: null`) with a GitHub Community discussion dated 2026-09-28 describing similar failures before runner assignment: https://github.com/orgs/community/discussions/209001. This makes runner provisioning/orchestration a plausible area to investigate, but it does not establish the cause for this repository.
- Billing reference: https://docs.github.com/en/billing/concepts/product-billing/github-actions. Owner-side checks needed in GitHub UI: repository Settings → Actions → General; account Settings → Billing & licensing / Budgets and alerts; check current GitHub Status at https://www.githubstatus.com/.
- If settings and billing are clean, contact GitHub Support with the failed run URLs and job IDs. Repeated retries already showed the same pre-step symptom, so avoid adding more application/workflow changes until a job exposes logs or steps.
- No tests are certified green by this investigation. The HTTP test's runtime execution remains pending; payment/provider APIs and deployment remain disabled.


- Latest confirmation after the investigation note commit: all three workflows again failed immediately on `851fddb169713d605360c2e49b71cdb87571e16b`: fulfillment `38397679614`, PHP quality `38397679661`, syntax `38397679602`. This remains a pre-step failure; no test is green and no root cause is established.


## Latest continuation — workflow source and run metadata (2026-10-13)

- Checked the latest head `2d559672eb132c4211eff93d529b749097fc54c8`: fulfillment [38397710451](https://github.com/sulistio95/PluQ-I/actions/runs/38397710451), PHP quality [38397710416](https://github.com/sulistio95/PluQ-I/actions/runs/38397710416), and syntax [38397710358](https://github.com/sulistio95/PluQ-I/actions/runs/38397710358) all failed immediately.
- Latest job API results still return `steps: null` and `logs_url: null`; fetching job logs returns 404. Reviewed `.github/workflows/fulfillment-tests.yml`, `.github/workflows/php-quality.yml`, and `.github/workflows/php-lint.yml`; inspected runner declarations and steps look conventional, with no obvious malformed `runs-on` setup. This cannot explain or prove the external failure.
- Reviewed the HTTP boundary test source: it uses a local PHP server, cURL, and disposable MySQL admin fixtures; no valid Duitku status lookup is submitted. The test remains runtime-unverified.
- No speculative application/workflow edits were made. Next step is owner-side repository Settings → Actions → General, account Billing & licensing / Budgets and alerts, GitHub Status, then Support if those look normal. Keep production deployment and live gateway/provider calls disabled. Update both notes after any subsequent material change/result.


## Controlled Actions retry result (2026-10-13)

- Requested a targeted rerun of syntax workflow [38399998833](https://github.com/sulistio95/PluQ-I/actions/runs/38399998833). Attempt 2 also failed (updated at 12:52:43 UTC). The same commit's PHP quality [38399998813](https://github.com/sulistio95/PluQ-I/actions/runs/38399998813) and fulfillment [38399998667](https://github.com/sulistio95/PluQ-I/actions/runs/38399998667) failed on their first attempts within seconds.
- The retry did not resolve the CI issue. No source/workflow edits were made; root cause remains unconfirmed and no new tests are certified green. Continue with owner-side Actions settings/billing/status checks or GitHub Support; do not deploy or enable live payment/provider calls. Audit updated in commit `0efb7848c15a3a70f20268ddea7130ccde40a4c6`.


## Latest continuation — Actions metadata rechecked (2026-10-13)

- Rechecked workflows triggered by handoff commit `a202647f6295ed4292637dc56a5961f7a0246bda`: PHP quality [38400685887](https://github.com/sulistio95/PluQ-I/actions/runs/38400685887) and fulfillment [38400685767](https://github.com/sulistio95/PluQ-I/actions/runs/38400685767) failed within about four seconds.
- Job metadata is still opaque: quality job `115307218283`, fulfillment jobs `115307218758` and `115307219224` show `steps: null` and `logs_url: null`. No application test step is visible; root cause remains unknown.
- Because this happened again on a note-only commit, do not blame the HTTP test or change application/workflow files speculatively. No additional retry was requested.
- Next action requires owner-side checks in repository Settings → Actions → General, account Billing & licensing / Budgets and alerts, and GitHub Status. If all are normal, contact GitHub Support with these run URLs and job IDs.
- The HTTP boundary integration test remains runtime-unverified. Keep live Duitku/provider requests and deployment disabled. The production-readiness audit was updated in commit `cb9ec0f6dcc9c2baff50c47aca9a34d4324ad1ad`.


## Continuation review — 2026-10-14

- Latest Actions run set on commit `15d163d8e27202c7946318bf237a3824720c1fb1`: PHP quality [38401404982](https://github.com/sulistio95/PluQ-I/actions/runs/38401404982), fulfillment [38401405037](https://github.com/sulistio95/PluQ-I/actions/runs/38401405037), and syntax [38401405068](https://github.com/sulistio95/PluQ-I/actions/runs/38401405068) all failed within seconds with no visible steps or logs.
- Re-reviewed the admin reconciliation route, admission helper, admin session/auth functions, HTTP integration test, and MySQL workflow. The test's session cookie name matches the application; the test exercises anonymous access, OPERATOR denial, CSRF rejection, unknown action rejection, and authorized form rendering. It does not submit the actual lookup action or contact Duitku.
- The admission helper commits the cooldown marker before network I/O and does not hold the DB transaction open during the request. No definite code defect was identified in this review, so no speculative source edit was made.
- Runtime execution of the HTTP integration test remains unverified. The blocker is still the opaque Actions pre-step failure; owner-side Actions settings/billing/status checks or GitHub Support are required. Do not enable live payments, supplier execution, or deployment until tests can run and staging checks pass.
- The production-readiness audit was updated in commit `5faf902970f9aaff42b75d22ea7448ea6c638e15`.


## CI check after latest documentation commits (2026-10-14)

- Audit commit `5faf902970f9aaff42b75d22ea7448ea6c638e15` triggered PHP quality [38468671002](https://github.com/sulistio95/PluQ-I/actions/runs/38468671002), which failed with no visible steps/logs.
- Handoff commit `f33f062705e451820aa5828266d62ff32f13d8dc` triggered syntax [38468686551](https://github.com/sulistio95/PluQ-I/actions/runs/38468686551), fulfillment [38468686528](https://github.com/sulistio95/PluQ-I/actions/runs/38468686528), and PHP quality [38468686565](https://github.com/sulistio95/PluQ-I/actions/runs/38468686565). All failed before any step or log was exposed. No test assertion is shown to have run.
- No source change was made because no specific code defect was established. The admin reconciliation HTTP integration test remains runtime-unverified. Next required step is owner-side GitHub Actions settings/billing/status investigation or GitHub Support; live payment/provider calls and deployment remain disabled.
- Audit note updated in commit `8fff19d3d64eed7a3182a1c8f08632fe9b43f86a`.


## Checkout idempotency review — 2026-10-14

- Re-read `app/services/order_checkout.php`, `app/services/checkout_quote.php`, `public/create-payment.php`, migrations 003/010, and checkout fingerprint/concurrency tests.
- Source review confirms unique checkout-key constraint, SHA-256 storage, request fingerprint binding email/game/product/account, rejection of same-key changed payload, no repeat Duitku dispatch on duplicate/deadlock recovery, and manual-review handling for ambiguous gateway timeout. Quote logic rechecks reserve balance, cheapest eligible offer, exclusions, and account-field validation.
- No definite source defect was established; no speculative source edit was made. Existing DB integration coverage is present but **not newly runtime-verified** because current GitHub Actions runs fail before showing any steps/logs.
- Latest runs on commit `213cccdd7e5db035ee0d3985884d0a64d12330f8`: PHP quality [38468745359](https://github.com/sulistio95/PluQ-I/actions/runs/38468745359) and fulfillment [38468745412](https://github.com/sulistio95/PluQ-I/actions/runs/38468745412) failed within seconds with job logs/steps unavailable; root cause remains unknown.
- Audit update: commit `f620f2f49f44f9ce6e3726dc2f87d0dfb59fef56` in [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).
- Next: continue source review on a separate subsystem without enabling live gateway/supplier calls; owner-side GitHub Actions settings, billing/budget, GitHub Status, or GitHub Support remain necessary to restore test execution. Do not deploy or enable production payment flags before successful CI and staging verification.


## Duitku callback and queue lease review — 2026-10-14

- Reviewed `public/duitku-callback.php`, `app/services/duitku.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and callback signature tests.
- Callback signature, order ID shape, and amount are validated before payment-row locking. Repeated callbacks are acknowledged without repeating transitions. A fulfillment job is inserted only when the order transitions to PAID for the first time.
- Worker finalization requires the current PROCESSING status and matching lease token. Supplier ambiguity is routed to manual review instead of blind resubmission.
- No definite defect established; this is source review, not a newly executed runtime test. CI remains blocked by failures with no visible steps/logs.
- Audit updated in commit `ad1fd8f812a9f2416f7846b43c047b07d129450f`: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).
- Continue reviewing code/tests without speculative changes; do not enable live payment/provider processing or deploy while CI execution and staging checks are unresolved.


## Provider transport and admin reconciliation review — 2026-10-14

- Reviewed `app/services/provider_http.php`: HTTPS-only, TLS verification, redirects disabled, bounded timeouts, non-2xx and invalid JSON rejected, no request-body/credential logging. No live API call was made.
- Reviewed `app/services/duitku.php`, `app/services/duitku_reconcile.php`, and `public/admin/reconcile.php`: status query is sandbox-only, uses a dedicated signature, validates returned order ID and amount, does not mutate local payment/order state, and applies a row-lock plus 60-second audit-backed cooldown before network I/O.
- Reviewed admin mapping and transaction pages plus `app/services/admin_auth.php`: privileged-role checks, CSRF validation, transactionally coupled mapping/audit writes, and HTML escaping are present. No definite source-level defect was established in this pass.
- Review only; no PHP/MySQL tests were executed in this pass. No code change, live provider/payment call, or deployment. Keep production flags disabled until CI can run visibly and staging checks pass.
- Audit record: commit `967870a6926912cdcdc59cf8839453b5970ffb73` in [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


### GitHub Actions follow-up — 2026-10-14

- The documentation-only handoff update triggered PHP/JavaScript syntax workflow run [38472583538](https://github.com/sulistio95/PluQ-I/actions/runs/38472583538), which failed in seconds. GitHub's job metadata reports `steps: null` and `logs_url: null`; no test/lint step output is available, so this does not establish that application checks failed.
- Root cause remains undetermined. Do not change application code or workflow definitions speculatively based on this result. Repository owner should check Actions availability/settings, billing/budgets, GitHub Status, and contact GitHub Support if those are normal.


## Admin login review — 2026-10-14

Login page and rate-limit helper review found that a successful login clears failed-attempt history too broadly, rather than only the matching IP and username pair. A source correction was attempted but repository writes to the source were blocked; no code fix is committed. This finding remains open, with no new runtime tests. The audit entry is recorded in commit `6c67f8367480baca7932cc70ee9e5ae5a254bf28`.


## Admin login throttle cleanup correction — 2026-10-14

- Fixed `admin_login_clear_failures()` in commit `6d0e2f24b4397d0f48148ee4e7bf3e1e0e9a8a77`: cleanup now matches both the current IP and normalized username hash, so a successful login does not erase unrelated throttle history.
- Added `tests/admin-login-throttle-clear.php` (commit `8e624e68e1c97572aec71268a15ac7e548d9d3d9`) and wired it into the PHP quality workflow (commit `664e9883ce7241c519dc0affb91949503f0054f2`).
- CI run [38474440334](https://github.com/sulistio95/PluQ-I/actions/runs/38474440334) failed before job steps/logs were available, so the new test is **not verified by CI**. A local PHP 8.4 harness using the committed SQL predicate and assertions passed; a small `mb_strtolower` compatibility shim was needed because local PHP lacks mbstring, and the repository test file itself was not run from a full checkout. Next, restore observable Actions execution and run the committed regression test on PHP 8.2 with mbstring.
- No live provider/payment call or deployment. Keep production flags disabled.
- Audit detail: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md), commit `d2e7f0e954956c0f0ebc6bcffbf40500122bb901`.


## Queue worker and fulfillment follow-up — 2026-10-14

- Reviewed `bin/worker-queue.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and related integration tests. Source review found transactional job claiming with random lease tokens, fail-closed stale-lock recovery, queue → order → supplier lock ordering, lease-token verification at finalization, manual review for ambiguous supplier outcomes, and idempotent pending-refund creation for definitive supplier failure. No additional definite defect was established. No DB-backed runtime tests were run in this pass.
- All three workflows still fail immediately without exposed steps/logs on current notes commits: syntax [38474672764](https://github.com/sulistio95/PluQ-I/actions/runs/38474672764), PHP quality [38474672798](https://github.com/sulistio95/PluQ-I/actions/runs/38474672798), fulfillment [38474672805](https://github.com/sulistio95/PluQ-I/actions/runs/38474672805). Root cause remains unknown; do not infer that application tests themselves failed.
- Keep live supplier/payment processing and deployment paused until Actions execution is inspectable and staging verification passes.
- Audit detail: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md), commit `0a21f099fa60dab90d58a02502c33a4fd9683d80`.


## Supplier retry duplicate-order guard — 2026-10-14

- Source review found that a VIPayment `RETRYING` record with no saved provider `trxid` could reach the new-order request path. That is unsafe because the earlier request may already have been accepted by the supplier.
- Fixed in [9930da7](https://github.com/sulistio95/PluQ-I/commit/9930da74ac4d1d8f49e86add191811f4745064dd): ambiguous `PROCESSING` requests and VIPayment `RETRYING` records without a transaction reference now require manual review; VIPayment status polling is retained when a reference exists; Digiflazz retry remains fail-closed.
- Added `tests/fulfillment-retry-safety-test.php` in [99cfd7e](https://github.com/sulistio95/PluQ-I/commit/99cfd7ebaa150051ef6676cd2ed2b2a42ee0542e) and included it in `.github/workflows/fulfillment-tests.yml` in [4af58c0](https://github.com/sulistio95/PluQ-I/commit/4af58c07616cf8b64989ce5d6f9b01872a46e72f).
- CI/runtime result is not yet verified because Actions has been failing before steps/logs are exposed. No live API calls or deployment. Keep live processing disabled. Audit details: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


### CI follow-up after retry-safety fix — 2026-10-14

- Latest syntax [38480851988](https://github.com/sulistio95/PluQ-I/actions/runs/38480851988), PHP quality [38480851789](https://github.com/sulistio95/PluQ-I/actions/runs/38480851789), and fulfillment [38480851797](https://github.com/sulistio95/PluQ-I/actions/runs/38480851797) runs failed without visible step/log metadata (`steps: null`, `logs_url: null`). This does not tell us whether the newly added regression test or application checks themselves failed.
- Next: restore inspectable GitHub Actions execution, then run the new `tests/fulfillment-retry-safety-test.php` and the existing PHP/MySQL integration suite. Keep live supplier/payment processing and deployment disabled. Audit details: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


## Digiflazz balance validation — 2026-10-14

- Added strict validation for the Digiflazz `deposit` balance: it must be numeric, finite, and non-negative. Previously a malformed string could silently cast to zero. Commit: [9040192](https://github.com/sulistio95/PluQ-I/commit/9040192a403aa3afffb43eb6c448fc1774806c04).
- Added `tests/digiflazz-balance-validation-test.php` in [5dcad4e](https://github.com/sulistio95/PluQ-I/commit/5dcad4ed2e83dbb631b6f0facd0841c54d6cc1b7) and wired it into fulfillment CI in [c7b34f7](https://github.com/sulistio95/PluQ-I/commit/c7b34f7832be06c44be38fa425c9b8b809e1d1ce).
- CI execution is still not verified because recent Actions runs failed without visible step/log metadata. No live provider requests/deployment. Keep payment and supplier processing disabled until verification passes. Full audit: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


### CI status after latest balance-validation change — 2026-10-14

- Runs [38482250710](https://github.com/sulistio95/PluQ-I/actions/runs/38482250710), [38482250685](https://github.com/sulistio95/PluQ-I/actions/runs/38482250685), and [38482250681](https://github.com/sulistio95/PluQ-I/actions/runs/38482250681) failed with no step/log metadata (`steps: null`, `logs_url: null`). This still does not identify the root cause or prove a specific application test failed.
- Continue with source-level review and regression additions only where a concrete defect is found; do not keep modifying workflows blindly. Arrange inspectable CI or controlled PHP/MySQL test execution before enabling any live payment or supplier flow.


## VIPayment balance response validation — 2026-10-14

- Source commit [2893932](https://github.com/sulistio95/PluQ-I/commit/2893932819fa8cafa3a1bf5905103df2bddd9675) adds `vipayment_map_profile_response()`, validating the profile balance as numeric, finite, and non-negative before returning it. The sync caller already had a second validation check; this hardening makes the adapter's own contract safe for other callers too.
- Added regression test [tests/vipayment-balance-validation-test.php](https://github.com/sulistio95/PluQ-I/blob/main/tests/vipayment-balance-validation-test.php) (commit [955fd57](https://github.com/sulistio95/PluQ-I/commit/955fd57aeb228e4f0d1b6213049e61cebdd0cdfa)) and wired it into the fulfillment workflow (commit [40e8bf3](https://github.com/sulistio95/PluQ-I/commit/40e8bf341e12d19a4307ec340826328ec7416c23)).
- Regression test remains unverified until CI steps/logs are visible or the suite is run in controlled PHP 8.2+ environment. GitHub Actions failures still expose no steps/logs; root cause unknown. Do not enable live Duitku/supplier processing or deploy. No live API call made.
- Audit details: [docs/production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md), commit [004e5ce](https://github.com/sulistio95/PluQ-I/commit/004e5ceea2655bddfeaf2c318b4416aac60737a3).


### GitHub Actions runner allocation follow-up — 2026-10-14

- Checked job records for syntax, PHP quality, and fulfillment runs on `d690c7828117183ca43d1b6cff9c08052c4a0340`. Each job failed before normal steps were recorded (`steps: []`, `runner_id: 0`, no usable logs), which confirms the visible problem is pre-step execution but does not establish the cause.
- Actions permissions could not be inspected with the current connector. Next diagnostic is owner-side review of **Settings → Actions → General**, account billing/usage limits, repository policy, and GitHub Status; then rerun and inspect step logs. Do not blindly alter workflows or mark tests as passed.
- No source edits, test execution, supplier/payment calls, or deployment occurred in this diagnostic pass. Production processing stays disabled. Audit details: [docs/production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


## Latest work — Duitku status amount overflow guard (2026-10-14)

- Fixed a numeric validation edge case in `app/services/duitku.php`: arbitrary-length numeric response amounts are now compared as normalized decimal strings, avoiding PHP integer saturation.
- Source commit: [b6bba9d](https://github.com/sulistio95/PluQ-I/commit/b6bba9d5e3c5e2f9c708ef5904b52d06cb0b9662). Regression test commit: [12042e3](https://github.com/sulistio95/PluQ-I/commit/12042e37603638481ce23cddeed87c4b8ac3601a).
- Added coverage for oversized values and the exact `PHP_INT_MAX` saturation case. Test execution is still unverified because GitHub Actions failures have not exposed normal steps/logs; do not report this test as passing.
- No live gateway/provider calls or deployment. Keep live payment and supplier-order flags disabled. Next: verify CI execution and run the PHP/MySQL integration suite before considering staging.


### CI status for latest regression (2026-10-14)

- Syntax workflow [38485787043](https://github.com/sulistio95/PluQ-I/actions/runs/38485787043) and fulfillment workflow [38485787071](https://github.com/sulistio95/PluQ-I/actions/runs/38485787071) failed immediately on handoff commit `2a465e53a1ccf66bba3ce03dd558df50b55f0705`. Jobs `115598975273`, `115598975592`, and `115598975726` expose no step summaries or logs. Do not interpret this as a test assertion failure; cause remains undetermined.
- The Duitku status amount overflow regression remains unverified. Next priority: resolve Actions pre-step execution or run tests in a controlled PHP 8.2+/MySQL environment. Live payment/provider calls and deployment remain disabled.


### Additional CI/log diagnosis — 2026-10-14

- Retried step-summary retrieval for jobs `115598975273`, `115598975592`, and `115598975726`; all return empty step arrays. Log retrieval returns `BlobNotFound`, so the runner/test failure cause remains unknown and no tests can be claimed to have run.
- Read-only review covered Duitku callback terminal-state handling, checkout idempotency, order-status display, and supplier recovery. No extra code change was made: contradictory terminal callbacks need DB integration coverage and a defined manual-reconciliation policy; do not auto-flip a terminal failed payment to paid without reconciliation.
- Next: restore CI logs or run PHP/MySQL integration tests in controlled staging. Keep live payments and supplier orders disabled; do not deploy.


## Contradictory terminal Duitku callback — 2026-10-14

- Added fail-safe reconciliation behavior in commit [9008996](https://github.com/sulistio95/PluQ-I/commit/9008996d5000da74b1fdd0dc0ed0006586582b26). If a signed callback contradicts a payment transaction's already-terminal state, the transaction's payment status is preserved, the order is flagged \`MANUAL_REVIEW\`, and \`audit_logs\` captures both states plus callback metadata. No new fulfillment job is enqueued.
- Added regression assertions in \`tests/duitku_callback_integration.sh\` (commit [8454a51](https://github.com/sulistio95/PluQ-I/commit/8454a51937c35ca4616403ae51b8b187d174f5cc)).
- **Not runtime-verified yet**: current GitHub Actions jobs still fail before exposing step summaries/logs. After the runner issue is resolved, execute the full MySQL integration workflow and verify the new conflict case. No live gateway/provider calls or deployment; keep live payment/supplier execution disabled.


## Repeat-callback audit deduplication — 2026-10-14

- Follow-up hardening: repeated identical signed callbacks that conflict with a terminal payment state now create one audit event per order + stored/incoming state pair, rather than one row per gateway retry. Source: [363d985](https://github.com/sulistio95/PluQ-I/commit/363d98545e872207cf68f95259e4915bfdff47ec).
- Integration script now sends the conflicting callback twice and asserts one audit row and no extra fulfillment job: [44dd349](https://github.com/sulistio95/PluQ-I/commit/44dd34915ab3679c8a73acce6ccd43a10205e7dc).
- Not runtime-verified yet. The Actions runs on the prior note commit failed before exposing runner steps/logs. Check fresh runs after this change; keep live payments, supplier orders, and deployment disabled until actual tests pass.


### Latest CI check — 2026-10-14

- After repeat-callback audit deduplication, the new runs (fulfillment 38493159152, PHP quality 38493159289, syntax 38493159116) also failed with no usable step logs. This is still an execution visibility blocker, not a proven source-level failure.
- New callback conflict idempotency coverage is committed but not verified by execution. Inspect repository/account Actions settings or run in a controlled PHP 8.2 + MySQL 8.4 environment before claiming success.


## Hosting and real-payment readiness update — 2026-10-14

- Created [docs/staging-payment-test-runbook.md](docs/staging-payment-test-runbook.md) in commit [4b418ad](https://github.com/sulistio95/PluQ-I/commit/4b418ada3fc5b7c9a0d9423e2942c25d1dda5ac0). It lists the staging setup, CI restoration, isolated Duitku sandbox test, supplier API verification, and production exit gates.
- New blocking source findings: `app/services/duitku.php` intentionally accepts only `DUITKU_ENV=sandbox`, so production Duitku support does not yet exist. `checkout_payments_enabled()` requires both `CHECKOUT_PAYMENTS_ENABLED=true` and `SUPPLIER_ORDERS_ENABLED=true`, so public checkout cannot currently be sandbox-tested independently of supplier-order enablement. Do not work around this by enabling supplier calls blindly; next code task is a sandbox-only payment path with no supplier purchase calls and DB integration tests.
- Fresh Actions job metadata for runs [38493246965](https://github.com/sulistio95/PluQ-I/actions/runs/38493246965) and [38493246898](https://github.com/sulistio95/PluQ-I/actions/runs/38493246898) shows runner ID 0 and empty step arrays. Root cause is still unknown; do not claim test execution or success.
- No live payment/provider call, supplier purchase, or deployment. Keep `CHECKOUT_PAYMENTS_ENABLED=false` and `SUPPLIER_ORDERS_ENABLED=false` until CI is observable and the sandbox-only path is implemented and verified.


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


### Latest test review — sandbox callback assertion — 2026-10-14

- Reviewed the sandbox-only callback integration script and found its queue assertion counted all `FULFILL_ORDER` jobs in the shared MySQL test database. Since earlier integration tests can create unrelated queue jobs, this could fail even when the sandbox order correctly created no supplier job.
- Fixed in [fb3623c](https://github.com/sulistio95/PluQ-I/commit/fb3623cfa920afec7e5dc76978b2455064f569b9): assert zero fulfillment jobs for the specific sandbox fixture's `order_id` in the JSON payload.
- Test has not yet run on MySQL. Recheck GitHub Actions runner allocation/log visibility, then execute the full fulfillment workflow and confirm the sandbox callback case. No live payment/provider call or deployment; keep production flags disabled.


### CI result after sandbox assertion correction — 2026-10-14

- Latest fulfillment run [38505439662](https://github.com/sulistio95/PluQ-I/actions/runs/38505439662) and PHP quality run [38505439765](https://github.com/sulistio95/PluQ-I/actions/runs/38505439765) failed with job `steps: null`; no actual test logs are available. PHP quality run [38505408114](https://github.com/sulistio95/PluQ-I/actions/runs/38505408114) has the same symptom.
- Do not treat this as a source test failure or success. Continue by resolving owner-side Actions runner/account availability, then inspect and run the actual test suite. The test-only assertion correction and notes are committed; live payments, supplier calls, and deployment remain disabled.



### Sandbox integration script quoting fix — 2026-10-14

- Source audit found shell quoting risk in `tests/duitku_sandbox_no_fulfillment_integration.sh`: a `php -r '…'` argument contained SQL single-quoted literals and JSON path `'$.order_id'`, which the shell could remove before PHP executed it.
- Fixed by using a single-quoted PHP heredoc, commit [acac0af](https://github.com/sulistio95/PluQ-I/commit/acac0afad5b2c735272e0150b9261817e82db4c6). This preserves SQL quoting as authored. The test still needs a real MySQL run; do not claim it passed until Actions steps/logs are available.
- Continue by checking new CI run results and testing the actual script. Production payments, supplier calls and deployment remain disabled.



### CI status after heredoc test fix — 2026-10-14

- Workflow runs for the heredoc correction: [PHP quality 38508784612](https://github.com/sulistio95/PluQ-I/actions/runs/38508784612), [fulfillment safety 38508784598](https://github.com/sulistio95/PluQ-I/actions/runs/38508784598), and [syntax checks 38508784571](https://github.com/sulistio95/PluQ-I/actions/runs/38508784571). Completed-failure jobs expose `steps: null`; no actual step output is accessible. The fulfillment PHP unit job was still queued at check time. Treat tests as unverified, not passed.


### Free WordPress.com deployment idea and latest test note — 2026-10-14

- User suggested using the connected WordPress account for free deployment testing without buying a domain. Checked the WordPress.com connected-site listing: it returned zero accessible sites, so no existing test site was available to target.
- WordPress.com Free is not a general-purpose host for this standalone PHP/MySQL app and does not allow plugin installation/custom PHP runtime. See [code restrictions](https://wordpress.com/support/code/) and [plan features](https://wordpress.com/support/plan-features/). Do not claim PluQ-I has been deployed or tested by publishing a WordPress page; a static demo is not an app-runtime test. No site was created or modified.
- Fixed literal \\n output in the sandbox callback integration test diagnostics in commit [9309e7e](https://github.com/sulistio95/PluQ-I/commit/9309e7e2a6d8edc11d836a1b7075ecd7787eac89). This is cosmetic; MySQL integration is still unverified.
- Latest observed Actions jobs fail before any steps (runner_id=0, steps empty), with no usable test logs. Resume by resolving Actions runner availability or arranging an actual PHP/MySQL staging runtime. Keep payments, supplier fulfillment and deployment disabled until test evidence is available.


### Latest Actions result — 2026-10-14

- Runs triggered by the sandbox diagnostic cleanup and documentation updates were checked: fulfillment [38509769564](https://github.com/sulistio95/PluQ-I/actions/runs/38509769564), PHP quality [38509769499](https://github.com/sulistio95/PluQ-I/actions/runs/38509769499), and syntax [38509769497](https://github.com/sulistio95/PluQ-I/actions/runs/38509769497). Each failed with job step metadata `null`, so there is no actual test output and no claim of a code-level failure or pass.
- Runs for the latest handoff commit are queued: [38509776705](https://github.com/sulistio95/PluQ-I/actions/runs/38509776705), [38509776692](https://github.com/sulistio95/PluQ-I/actions/runs/38509776692), [38509776675](https://github.com/sulistio95/PluQ-I/actions/runs/38509776675). Check again later; they are not passing evidence yet.


### Additional source-level test review — 2026-10-14

- While Actions is unavailable, reviewed the sandbox integration fixture, callback signature construction, test environment flags, and assertions against the workflow. Their order ID and amount setup are consistent on source review; the test is intended to verify PAID payment, MANUAL_REVIEW order, and no fulfillment queue job for the fixture order.
- Fixed the double-escaped newline in the checkout-gate test's success output, commit [654bd26](https://github.com/sulistio95/PluQ-I/commit/654bd269d5ba396a555a47abba23ebb06351865a). The earlier sandbox script diagnostic cleanup is [9309e7e](https://github.com/sulistio95/PluQ-I/commit/9309e7e2a6d8edc11d836a1b7075ecd7787eac89).
- These are source reviews and cosmetic test-output changes, not evidence that tests passed. PHP/MySQL runtime and callback integration remain unverified; keep live payments, supplier ordering, and deployment disabled.

### Independent test attempt and Actions status — 2026-10-14

- Local PHP 8.4.24 is available, but an independent repository clone failed because this runtime cannot resolve `github.com`; the committed project tests therefore could not be run locally.
- Latest runs for commit `3e933fa847c1268825f88713c009855f46398b18`: syntax [38511159260](https://github.com/sulistio95/PluQ-I/actions/runs/38511159260), fulfillment [38511159043](https://github.com/sulistio95/PluQ-I/actions/runs/38511159043), and PHP quality [38511159024](https://github.com/sulistio95/PluQ-I/actions/runs/38511159024) are completed failures. Runner jobs have repeatedly exposed `steps: null`, so there is no application test output. Do not interpret this as a code pass or a confirmed test assertion failure.
- Continue source-level audit while waiting for GitHub runner limits to reset; do not enable payments, supplier orders, or deployment before actual PHP/MySQL test evidence is available.

### Queue and provider-balance concurrency audit — 2026-10-14

- Reviewed `app/services/queue_worker.php`, `bin/worker-queue.php`, `app/services/fulfillment.php`, `public/duitku-callback.php`, `app/services/provider_sync.php`, and `app/services/checkout_quote.php` directly from the current `main` branch.
- Queue safety findings: job claiming is performed inside a short InnoDB transaction; each claim gets a random lock token; finalization checks that token before changing the job; expired jobs are moved to manual review instead of blindly requeued because the supplier may already have accepted the request. The worker currently processes jobs serially per invocation, which is conservative for shared hosting but does not provide parallel throughput within a single run.
- **New production blocker — provider balance race:** checkout validates each product against the latest stored balance and configured reserve percentage, but there is no local reservation/commitment ledger for paid orders waiting in the queue. Two or more concurrent orders can each pass the 50% balance rule using the same balance snapshot, so their combined supplier cost can exceed the intended usable balance. This source review does not establish that an actual overspend occurred; it establishes that concurrent commitments are not reserved atomically.
- Next implementation should add an InnoDB-backed provider budget reservation/commitment mechanism, lock the provider row while deciding capacity, and release/settle each reservation idempotently on definitive supplier success/failure or manual reconciliation. Unknown supplier outcomes must retain the reservation until an operator resolves them. Add concurrent MySQL integration tests before enabling supplier ordering.
- This turn made no application-code change because a partial reservation implementation could incorrectly release capacity or double-count orders. This finding has been recorded before starting the schema/service/test change. Runtime tests remain unverified: the latest GitHub Actions runs failed before recording steps, and the repository could not be cloned into this runtime due DNS failure. Keep live checkout, supplier ordering, and deployment disabled.

### Provider budget reservation implementation — 2026-10-14

- Added `database/migrations/011_provider_budget_reservations.sql` and `app/services/provider_budget.php`. Provider-row `FOR UPDATE` locking serializes capacity checks; active and review reservations count against the configured usable balance; order ID is unique in the ledger.
- Successful Duitku callback now reserves provider budget before enqueueing supplier fulfillment. Capacity failure preserves payment status `PAID`, moves the order to `MANUAL_REVIEW`, and does not enqueue a supplier job. Sandbox-only callbacks bypass supplier reservation/fulfillment.
- Supplier success -> `SETTLED`; definitive supplier rejection -> `RELEASED` with refund pending; ambiguous result -> `REVIEW`, which still consumes budget until an operator reconciles it.
- Added `tests/provider-budget-capacity.php` and wired it into `.github/workflows/php-quality.yml`.
- Next: verify latest GitHub Actions runs, run PHP lint/unit tests, and add a real MySQL concurrency integration test with two or more simultaneous paid callbacks sharing one provider. Check migration runner/install instructions and require migration 011 before deploying the new callback/service. No live API calls or deployments. Do not claim tests passed until workflow output shows the steps and results.

- Follow-up guard: `fulfillment_process()` now checks the reservation ledger before any supplier API request and blocks any paid queue job without an `ACTIVE` reservation, moving it to manual review. Migration 011 is included in the MySQL integration workflow migration list and in README setup steps.
- CI still needs diagnosis: latest workflow jobs report failed with `steps:null` and no logs, so there is no PHP lint, unit-test, or MySQL test evidence. Next investigate runner/workflow service and add real MySQL contention tests for reservation locking. Keep all live operations disabled.

- Further concurrency hardening: settled reservation amounts remain included in capacity usage until `providers.last_balance_check_at` is newer than the reservation update. Balance sync records the query start time before the external HTTP request, not response completion time, to avoid an in-flight stale response incorrectly clearing a later settled spend. Verify this behavior with MySQL integration tests and controlled supplier balance checks.


### Reservation recovery consistency fix — 2026-10-14

- Source audit found a crash-recovery path in `app/services/fulfillment.php`: if the supplier transaction was already persisted as `SUCCESS` but the process crashed before resolving the budget reservation, the next worker marked the order successful without changing the reservation from `ACTIVE` to `SETTLED`.
- Fixed in commit [defd9d5](https://github.com/sulistio95/PluQ-I/commit/defd9d599f8c71b795ac7525addfa44f46a70178). The recovery branch now resolves the reservation to `SETTLED` inside the same DB transaction. This is an idempotent state-recovery fix and keeps the balance ledger consistent.
- Updated staging instructions to include migration 011 in the ordered migration list. Runtime PHP/MySQL tests remain unverified because GitHub Actions has not exposed executed steps/logs. Next priority: diagnose runner availability and add/execute a MySQL contention test proving two concurrent reservations cannot exceed the provider's usable balance. Keep payment/supplier production operations disabled.


### MySQL provider reservation integration coverage added — 2026-10-14

- Added `tests/provider-budget-reservation-mysql-integration.php` in commit [ed2ae8a](https://github.com/sulistio95/PluQ-I/commit/ed2ae8ab9c290408eda293ed64dde1db3fca15db). Against the configured MySQL schema, it creates disposable fixtures and checks that a Rp300,000 commitment blocks a further Rp200,001 against Rp500,000 usable capacity; releasing the first reservation frees capacity; and duplicate reservation calls reuse the single ledger row.
- Wired the test into `.github/workflows/fulfillment-tests.yml` after migration 011 in commit [81aea76](https://github.com/sulistio95/PluQ-I/commit/81aea76c0ff25d0132721fae1680750a9cab0573).
- This is a real-MySQL integration test for aggregate capacity/idempotency, but **not yet a simultaneous multi-connection contention test**. It has not run successfully because current GitHub Actions runs fail before step logs are available. Do not represent this test as passed. Continue investigating CI runner failure and add a genuine concurrent reservation test before enabling supplier orders.


### Provider reservation concurrency test — 2026-10-14

- Updated `tests/provider-budget-reservation-mysql-integration.php` to start two separate PHP processes/connections at a shared barrier. Each attempts a Rp300,000 reservation against a provider with Rp500,000 usable capacity; test expects one admitted reservation and aggregate active/review commitments not above capacity.
- Worker output is captured through temporary files to avoid partial reads while child processes are still running. Commits: [bf0537f](https://github.com/sulistio95/PluQ-I/commit/bf0537fb17e7eaaed556936718da0e7b5ea707ed), [47b2924](https://github.com/sulistio95/PluQ-I/commit/47b2924ddd75b915b0814d2627f00b437cd2b39e).
- CI remains blocked/unverified: workflow run [38529583687](https://github.com/sulistio95/PluQ-I/actions/runs/38529583687) failed before step recording (`steps: null`). Do not call the integration test passed. Next diagnose GitHub Actions runner availability, then execute PHP lint/unit/MySQL integration tests. Do not modify the separate `PluQ` repo and do not enable production payment/supplier fulfillment.

- CI diagnosis update (2026-10-14): reran syntax-check job run [38529853413](https://github.com/sulistio95/PluQ-I/actions/runs/38529853413), attempt 2; it still failed with no step records. New PHP quality/fulfillment workflow runs fail similarly. Cause remains unknown; don't infer a code failure or a passing test. Next inspect repository/account Actions policies, runner availability/usage limits, and GitHub Status, then obtain a run with actual step logs.


### Settled reservation timestamp boundary — 2026-10-14

- Hardened `app/services/provider_budget.php`: settled reservations remain committed when `updated_at >= last_balance_check_at`, not only strictly greater. Equal timestamps are ambiguous under second-precision storage; conservative capacity blocking is safer than reusing stale balance.
- Added an integration regression to `tests/provider-budget-reservation-mysql-integration.php`: equal balance/reservation timestamps must still block a second order that would exceed usable capacity. Commits [d61a7c8](https://github.com/sulistio95/PluQ-I/commit/d61a7c89be9ca8c21a78495339746af62efd07de) and [8fb1105](https://github.com/sulistio95/PluQ-I/commit/8fb110597c22ecf6bbd5e4919bb2cc3dfa676961).
- CI still unverified: run [38529853413](https://github.com/sulistio95/PluQ-I/actions/runs/38529853413) has no runner name/steps; logs return `BlobNotFound`. Public GitHub status was operational at the latest check; don't assume a current global incident or a code-level cause. Local runtime couldn't resolve `raw.githubusercontent.com`, so PHP lint was not executed locally. Next: get a real Actions runner/logs, run PHP lint and full MySQL 8.4 integration, and fix any actual failures. Keep live operations disabled.


### Reservation contention test synchronization — 2026-10-14

- The MySQL integration test now waits for each independent PHP worker's ready marker before releasing the shared barrier, ensuring both workers are poised to reserve at the same time. It fails if both workers do not reach the barrier within 15 seconds and always releases started workers to avoid leaving them waiting.
- Workflow step label updated to name all covered cases (capacity, idempotency, concurrency, stale-balance boundary). Commits [6025cd6](https://github.com/sulistio95/PluQ-I/commit/6025cd6e7be77d3f6d0dbe2e601297a4b5b1009a) and [870daf7](https://github.com/sulistio95/PluQ-I/commit/870daf71c105589a627dd63d8da299c9cd62b899).
- Not yet executed successfully. Do not claim test success until the MySQL workflow produces step logs and passes.

- After the latest test hardening, syntax workflow runs [38533061161](https://github.com/sulistio95/PluQ-I/actions/runs/38533061161) and [38533044011](https://github.com/sulistio95/PluQ-I/actions/runs/38533044011) still report failure with `steps: null`; no test commands ran. Latest fulfillment/PHP-quality runs were queued at inspection. Continue runner diagnostics; do not attribute this to a PHP assertion without logs and do not mark tests as passed.

### Stale fulfillment job guard — 2026-10-14

- Latest code commit: [dd980d6](https://github.com/sulistio95/PluQ-I/commit/dd980d6b6d80f2ff82e598f6794e2ec2892178e1). fulfillment_process() now allows supplier processing only for paid orders in QUEUED, PROCESSING, or RETRYING; a paid order already in MANUAL_REVIEW cannot be revived by a duplicate job even if its budget reservation is ACTIVE.
- An in-flight PROCESSING/RETRYING order without a durable supplier transaction is moved to MANUAL_REVIEW, with its budget reservation resolved to REVIEW, instead of creating a fresh supplier request that might double-purchase.
- Regression test expanded in [43b1bf5](https://github.com/sulistio95/PluQ-I/commit/43b1bf5e14434e39094af6926d632dbce46ea145); it now seeds the reservation and verifies a duplicate job does not revive a reviewed order. Cleanup deletes the reservation before the order to respect the foreign key.
- GitHub Actions may be usage-limited until the next reset per user's report. Do not claim tests passed. Resume by checking whether Actions can assign runners, then run PHP lint, PHP unit tests, and MySQL 8.4 integration tests. Do not change the separate PluQ repository or enable live operations.


### Latest work — terminal order stale-job regression (2026-10-15)

- Commit [3c401d4](https://github.com/sulistio95/PluQ-I/commit/3c401d404f7237ff469800252b32abb703b4e12e) extends `tests/duplicate-queue-processing-integration.php`: besides the existing fresh/stale PROCESSING and MANUAL_REVIEW cases, it now checks that stale queue jobs cannot revive `FAILED`, `REFUND_PENDING`, or `REFUNDED` orders when the budget reservation remains ACTIVE. Each call must return `IGNORED`, and the supplier row must remain `REVIEW`.
- This is test coverage added to the repository, not a passing runtime result. The latest known Actions problem is failure before runner steps/logs; check current run state before claiming any test passed.
- Next: continue source audit of payment/refund state transitions and admin security; when Actions is available, run PHP lint/quality and MySQL fulfillment integration tests including this regression. Keep checkout payments, supplier ordering, settlement backup, and deployment disabled pending staging evidence.


### Latest work — queue finalization state-preservation guard (2026-10-15)

- Commit [47f06b9](https://github.com/sulistio95/PluQ-I/commit/47f06b9580b8435e99efcf228780f5db71c4e28f) fixes `app/services/queue_worker.php`: an `ERROR` or exhausted retry can no longer overwrite existing `FAILED`, `REFUND_PENDING`, `REFUNDED`, or `MANUAL_REVIEW` order states. Only `QUEUED`, `PROCESSING`, and `RETRYING` orders are transitioned to `MANUAL_REVIEW` by this path.
- Commit [7b3bc13](https://github.com/sulistio95/PluQ-I/commit/7b3bc13f7aeddee9ef21d779a58696f98b07da76) adds MySQL integration assertions for the protected states to `tests/worker-lease-revocation-integration.php`.
- Tests have not yet been executed successfully; verify PHP lint and the full MySQL fulfillment workflow when GitHub Actions provides usable runner logs. Do not mark this change as runtime-verified and do not enable production payments/supplier execution.
- Next source-audit priority: refund/admin reconciliation state transitions and audit trail consistency, followed by actual staging verification.

### Latest work — supplier-failure transition guard (2026-10-15)

- Source audit found `fulfillment_record_explicit_failure()` could overwrite any paid order status to `FAILED`, even if a stale/future call reached a successful, refunded, or manually reviewed order.
- Fixed in commit [4fbef73](https://github.com/sulistio95/PluQ-I/commit/4fbef73bb60eb6111c3701f9c7e666f5d51d6b47): lock/read current order state; allow only `QUEUED`, `PROCESSING`, `RETRYING`, or idempotent `FAILED`; constrain refund insertion to paid orders still in `FAILED`. Other states throw `DomainException` without modifying order/refund/reservation.
- Added regression coverage in [eb30cf4](https://github.com/sulistio95/PluQ-I/commit/eb30cf4d677ee30a5a293b73fda811fb0a4381a1): after crash-window refund recovery, move fixture to `MANUAL_REVIEW` and assert another explicit-failure call is rejected without overwriting that state. Runtime PHP/MySQL test remains unverified.
- Continue with source audit of `public/admin/reconcile.php` and related reconciliation/audit-log code. Check current GitHub Actions runner status before claiming any test result; keep live payment, supplier ordering, backups, and deployment disabled until real staging evidence exists.


### Latest work — Duitku reconciliation snapshot consistency (2026-10-15)

- Applied in [commit `9c138c9`](https://github.com/sulistio95/PluQ-I/commit/9c138c98f1d492d90980bcfe268092ab4ce67f99), `app/services/duitku_reconcile.php` now locks the payment and associated order together, validates the Duitku gateway, merchant order ID and whole-unit amount, and records the `before` statuses from the locked database snapshot rather than caller-supplied stale arguments.
- The earlier audit note saying no reconciliation code fix had been applied is superseded by this commit; see `docs/production-readiness-audit.md` for the current record.
- A proposed regression test using stale status arguments was not committed because the test-file write was blocked. Do not assume this case is covered or that runtime verification passed.
- Latest Actions runs are failures: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38632601867), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38632599511), [fulfillment tests](https://github.com/sulistio95/PluQ-I/actions/runs/38632599912). Job metadata confirms failure but detailed logs/steps are unavailable, so root cause remains unknown.
- Next steps: add the stale-snapshot regression test when writes are available; recover readable CI logs or run PHP 8.2 + MySQL/MariaDB staging tests; fix only evidence-backed failures; update both notes after each meaningful change. Keep production payments, supplier orders, Google Drive backup and deployment disabled until verification passes.


### Regression test added — 2026-10-15

- Commit [88aec00](https://github.com/sulistio95/PluQ-I/commit/88aec00533beba80d959f6cb08fd45a5167c5f3f) extends `tests/reconcile-cooldown-concurrency-integration.php`: it supplies deliberately stale payment/order statuses to `duitku_reconcile_admit_lookup()` and asserts the STARTED audit details match the locked current database statuses. Existing concurrent admission assertions remain.
- Audit notes updated in commit [433f55d](https://github.com/sulistio95/PluQ-I/commit/433f55d683a9622ef4f3db3a684b27d11e54f471).
- This regression test is committed but has not yet been executed locally or confirmed green in Actions. Earlier CI log downloads were unavailable; fetch latest Actions runs and inspect job results next.
- Keep production payments, supplier ordering, Google Drive backup, and deployment disabled until CI and PHP 8.2 + MySQL/MariaDB staging verification pass.


### Latest CI follow-up — 2026-10-15

- After regression commit `88aec00`, all three workflows reported failure: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38633330500), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38633330555), [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/38633330567). Runs triggered by audit-doc commit `433f55d` also failed: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38633351092), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38633351125), [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/38633350950).
- Job steps are empty and log downloads return NOT_FOUND, so root cause cannot currently be identified. Do not assume the Actions quota is the cause or that the code itself is the cause.
- At the time of this note, the newest runs triggered by the handoff update were still queued: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38633370983) and [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/38633370846). Recheck their final state.
- Audit record updated in [commit `e196df9`](https://github.com/sulistio95/PluQ-I/commit/e196df911e86ffed5eaab413775d8b70bc078db7). The handoff should be considered updated by this commit after this write succeeds.
- Next: recheck latest Actions, obtain readable logs or local/staging PHP 8.2 + MySQL/MariaDB results, then address evidence-backed failures. Keep all production integrations disabled until verification passes.


## Latest continuation note — reconciliation regression CI remains opaque (2026-10-15)

- Regression test `88aec00533beba80d959f6cb08fd45a5167c5f3f` checks concurrent cooldown admission and confirms audit before-status fields are loaded from locked database state, not stale admin-page arguments. It does not call the gateway.
- Latest workflows observed on prior handoff commit `55af1b0c1c15a0428e63fcb78810281063fa237c` failed: syntax `38633432251`, fulfillment `38633432240`, PHP quality `38633432207`. Job-step responses are empty; downloading logs returns `BlobNotFound`. Do not infer the root cause. Test remains unverified until it runs successfully against MySQL and PHP.
- Audit note was updated in commit `645b3e6d6990204d80ee280d1395b6c5b65cc677`. Next: inspect Actions on the new handoff commit; if logs remain inaccessible, do static review only and document the limitation. Do not make repeated documentation-only commits just to trigger CI. Do not enable production payment, supplier ordering, or Drive settlement backup.


## Latest code change — stale reconciliation assertion corrected (2026-10-15)

- Commit `8a81ae54b4bb927252ace3d4e715a071b78b515c` updates `tests/reconcile-cooldown-regression.php`: its lock assertion expected the old single-table query, while the service now locks the payment joined to its order and reads both statuses under lock. The guard now checks the joined SELECT, the current order status fields, and the lock target in sequence.
- Audit documentation updated in commit `034d4802d36392f748734a5d85de511b19837892`.
- This test fix has not yet been runtime-verified. Previous CI runs failed quickly and logs are unavailable; the stale assertion can explain a PHP-quality failure but is not proven to explain every failed workflow. Check Actions for this commit and do not enable production payment/supplier/Drive backup flags.


## CI follow-up — 2026-10-15

- Latest Actions runs `38646127276` (syntax), `38646127259` (PHP quality), and `38646127248` (fulfillment) failed almost immediately after commit `2c357655b107f42b164ccab9850bf0be48c840ba`.
- Jobs report `runner_id=0`, empty steps, and no runner name; log retrieval returns `BlobNotFound`. The annotations endpoint is unavailable through the current GitHub connection. Root cause is therefore unknown; do not assume quota or claim the tests ran.
- The regression assertion was aligned with the joined lock query in commit `8a81ae54b4bb927252ace3d4e715a071b78b515c`, but still needs runtime verification.
- Continue only in PluQ-I. Keep payment/provider/Drive backup live operations disabled until CI and MySQL integration tests can actually execute and pass.


## Continuation update — Actions diagnosis and next safe step (2026-10-15)

- Rechecked repository metadata: `sulistio95/PluQ-I` is **public**. GitHub's standard hosted runner usage for public repositories is normally free, so the account's 2,000-minute private-repository allowance is unlikely to explain these failures. This does not identify the actual cause.
- Latest workflows for head `34d9ebb9` failed immediately: syntax `38646530354`, fulfillment `38646530361`, PHP quality `38646530385`. Earlier head `8edca90f` also failed immediately. Jobs still have no steps/runner assignment and log downloads are unavailable; treat CI as infrastructure/runner-diagnostic blocked, not as proof that tests executed and failed.
- Attempted a fresh public Git clone for local PHP verification, but this runtime could not resolve `github.com`; no local test result is claimed.
- Source review reconfirmed the reconciliation helper locks the payment/order row set, validates gateway/order ID/amount against locked data, records the STARTED audit marker before network I/O, and commits before calling Duitku. The structural and MySQL concurrency tests are present, but remain unverified at runtime.
- Next action: inspect repository Actions settings/notifications and obtain a usable runner/log; then run PHP lint/quality and MySQL integration tests. Avoid another code change without test evidence. Keep live payments, supplier orders, Drive backup, and deployment disabled.


## Provider-budget concurrency fix — 2026-10-15

- Commit `e98b4f4eea9af9d14a6863d4d1fd314c18ae6e96` changes `app/services/provider_budget.php` lock order to lock the provider row before checking the unique reservation key. Existing-reservation idempotency is still checked before rejecting an unavailable provider.
- This was driven by the now-readable MySQL CI log: the budget integration failed with `SQLSTATE[40001] / MySQL 1213 Deadlock found when trying to get lock` in the two-worker capacity race. The previous lock order checked for a missing reservation before serializing on the provider, allowing competing InnoDB gap locks and insert deadlock.
- Syntax and PHP quality workflows passed on the immediately preceding docs head (`e546adac`); the MySQL integration exposed the concurrency defect. The fix commit has newly triggered workflows; do not mark the integration test passed until its rerun is green.
- Live payment, supplier ordering, Google Drive backup, and deployment remain disabled.


## Follow-up: provider reservation deadlock and integration fixture — 2026-10-15

- The provider-budget MySQL concurrency case now passes on code commit `e98b4f4` (PHP quality and syntax workflows also passed). It had previously reproduced MySQL 1213 due to reservation lock ordering; provider row locking now precedes the absent-key reservation lookup.
- The same fulfillment workflow then reached `tests/uncertain_supplier_integration.sh` and failed because its ambiguous-supplier fixture omitted the mandatory migration-011 `provider_budget_reservations` row. The production safety gate correctly stopped the job before the test's intended ambiguous-supplier branch, leaving the fixture's supplier row PROCESSING. Commit `9c5ae47d9784dbb5e00c36112fe0ba64083f3a40` adds an ACTIVE budget reservation to this disposable test fixture so the test exercises the intended branch. This is a test-fixture correction, not a production bypass.
- CI for the fixture correction is pending. Keep live integrations and deployment disabled until the full MySQL suite passes.


## Follow-up: explicit supplier-failure recovery fixture — 2026-10-15

- On the rerun after commit `9c5ae47`, the ambiguous-supplier integration passed. The next MySQL step exposed two issues in `tests/explicit_supplier_failure_integration.sh`: PHP running from standard input did not define `STDERR`, masking the intended assertion, and the crash-recovery fixture lacked the migration-011 budget reservation, so the production safety gate correctly stopped before the persisted-failure recovery branch.
- Commit `5a4181d548d7d40a8c825b75a2ec3f2917d51279` defines a CLI stderr stream for stdin execution and seeds an ACTIVE reservation for the recovery fixture. These are test-harness/fixture corrections; production guards are unchanged. Re-run the MySQL workflow and address any next evidence-backed failure.
- The provider budget concurrency test and ambiguous-supplier test have passed on recent runs; the full integration suite is not yet green. Keep live integrations disabled.


## Verification result — MySQL fulfillment suite green (2026-10-15)

- Commit `5a4181d548d7d40a8c825b75a2ec3f2917d51279` passed all three GitHub Actions workflows: syntax `38656968511`, PHP quality `38656968476`, and fulfillment safety `38656968414`.
- The MySQL fulfillment job completed successfully, including provider budget capacity/idempotency/concurrency/stale-balance checks, three concurrent checkout-idempotency attempts, MySQL deadlock classification, concurrent Duitku reconciliation admission, queue-claim/callback concurrency, duplicate active supplier queue, stale queue recovery, lease revocation, ambiguous supplier review, explicit supplier failure/refund recovery, sandbox-payment isolation, and read-only reconciliation checks.
- The concrete reservation deadlock was fixed by provider-first serialization before the absent reservation lookup; both supplier integration fixtures were corrected to include the mandatory active budget reservation where needed. PHP-from-stdin stderr is now explicitly initialized in the explicit-failure test.
- This is CI verification against GitHub Actions PHP 8.2 and MySQL 8.4 test services, not a production-host or live gateway certification. Keep live payments, supplier ordering, Drive backup, and deployment disabled pending shared-host staging verification.


## Catalog and checkout capacity consistency — 2026-10-15

- Source review found a user-visible mismatch: catalog and checkout quote checked only `product base_price <= provider balance × reserve_percentage`, while payment-time reservation correctly subtracts existing ACTIVE/REVIEW commitments and conservative SETTLED commitments after the last balance sync. This could display an offer and allow a quote even when its budget could not be reserved after payment.
- Commit `4500da6e250f30b8dde7c52a16060af4a9990661` updates `app/services/catalog.php` to account for outstanding reservations when deriving `is_available`.
- Commit `4c9e4c26e7e316427bb1c6428bb4ac1f27dc915d` applies the same remaining-capacity rule to the selected offer and cheapest eligible offer in `app/services/checkout_quote.php`. The authoritative transactional reservation check remains in place to handle races after the quote.
- Commit `ac2f9a829d0cda2f6ea6a003b41b44b66d04496a` extends the MySQL provider-budget integration test: catalog must hide the offer when a prior reservation leaves insufficient capacity, then restore availability after that reservation is released. CI for these changes is pending; do not claim the new test passed until workflow completion.
- Production payment/provider/Drive integrations and deployment remain disabled.


## Catalog capacity test follow-up — 2026-10-15

- The first MySQL run after adding catalog assertions correctly rejected the first over-capacity offer, but the test's later expectation was wrong: after releasing the Rp300,000 reservation, it immediately created a Rp200,001 reservation, leaving only Rp299,999 of Rp500,000 usable capacity. The catalog correctly kept the Rp300,000 offer unavailable.
- Commit `7114ede8965d47fe6f8e600167527102fd0f8002` corrects the integration sequence: assert unavailable while the Rp200,001 reservation remains active, then assert availability returns only after all relevant reservations are released. This is a test expectation correction; catalog and checkout implementation were not relaxed.
- The new MySQL test must rerun on this commit before its status can be marked green. Syntax and PHP quality workflows had passed for the prior implementation head; the earlier MySQL failure was the test's incorrect expectation, not a runtime SQL error.


## Verifikasi lanjutan kapasitas katalog/checkout — 2026-10-15

- Setelah catatan sebelumnya, ketiga workflow pada head dokumentasi `a59d11e242f3c8c9de291b5a53b3c50f1ac5eceb` selesai sukses: syntax [38658731086](https://github.com/sulistio95/PluQ-I/actions/runs/38658731086), PHP quality [38658731054](https://github.com/sulistio95/PluQ-I/actions/runs/38658731054), dan fulfillment safety termasuk integrasi MySQL [38658731101](https://github.com/sulistio95/PluQ-I/actions/runs/38658731101).
- Ini memverifikasi rangkaian terbaru: reservasi aktif harus mengurangi kapasitas yang ditampilkan katalog dan dipakai checkout quote; penawaran kembali tersedia setelah komitmen terkait dilepas. Koreksi fixture/urutan assertion pada commit `7114ede` kini terlewati oleh workflow fulfillment yang hijau.
- CI menggunakan PHP 8.2 dan MySQL 8.4; bukan uji pada paket shared hosting yang dipilih, gateway Duitku live, API supplier live, atau Google Drive. Seluruh integrasi eksternal dan deployment tetap nonaktif.
- Lanjutkan audit sumber pada jalur checkout, pemetaan akun game, dan admin; setiap perubahan substantif wajib dicatat di file ini dan `docs/production-readiness-audit.md`.


## Regresi quote checkout untuk kapasitas supplier — 2026-10-15

- Memperluas `tests/provider-budget-reservation-mysql-integration.php` agar selain katalog, `checkout_quote()` juga diuji terhadap reservasi kapasitas: quote harus ditolak ketika reservasi aktif membuat kapasitas tidak cukup, lalu kembali berhasil setelah seluruh reservasi fixture dilepas.
- Fixture sekarang membuat field game `user_id` yang sah agar quote menempuh validasi akun normal. Cleanup menghapus field fixture sebelum game dihapus. Perubahan tes sudah committed, tetapi workflow CI yang dipicu commit ini belum diverifikasi; jangan klaim regresi baru lulus sebelum job fulfillment/MySQL selesai.
- Kode produksi tidak berubah pada langkah ini. Pembayaran, pemesanan supplier, backup Drive, dan deployment tetap nonaktif.


## Hasil CI regresi quote checkout — 2026-10-15 (diperbarui)

- Perubahan tes pada commit `59297d1a892a491c025f6614aba3fee52095370c` telah melewati ketiga workflow: [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38660082026), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38660081870), dan [fulfillment safety termasuk MySQL integration](https://github.com/sulistio95/PluQ-I/actions/runs/38660081985) — semuanya `success`.
- Dengan demikian assertion baru yang memastikan `checkout_quote()` menolak kapasitas yang tidak cukup dan pulih setelah reservasi dilepas telah dijalankan dalam CI MySQL. Lingkup tetap PHP 8.2/MySQL 8.4; belum sertifikasi MariaDB/hosting target atau integrasi eksternal live.
- Langkah audit berikutnya: memperluas pengujian settlement dan kesiapan operasional shared hosting tanpa mengaktifkan pembayaran, supplier, backup Drive, atau deployment.


## Pemulihan queue: reservasi kapasitas ikut ditandai REVIEW — 2026-10-15

- Audit jalur pemulihan menemukan bahwa ketika job supplier kedaluwarsa atau worker berakhir dengan error ambigu, pesanan dan transaksi supplier dipindahkan ke `MANUAL_REVIEW`/`REVIEW`, tetapi ledger `provider_budget_reservations` bisa tertinggal berstatus `ACTIVE`. Kapasitas tetap tertahan (aman secara saldo), namun status ledger tidak konsisten dengan kebutuhan rekonsiliasi.
- `bin/worker-queue.php` dan `app/services/queue_worker.php` sekarang memanggil `provider_budget_resolve(..., 'REVIEW', ...)` pada transisi manual review. Pengujian `tests/queue_recovery_integration.sh` kini membuat reservasi fixture dan menegaskan statusnya berubah ke `REVIEW` tanpa melepaskan kapasitas.
- Perubahan produksi dan tes sudah committed; workflow CI pada commit terbaru masih harus diverifikasi. Jangan menandai regresi ini lulus sebelum fulfillment/MySQL integration selesai.


### Hasil CI perbaikan reservasi REVIEW — 2026-10-15

- Ketiga workflow pada commit `813f6dea8668162f1a817e00c631d9aeb1249a16` sukses: [fulfillment safety/MySQL](https://github.com/sulistio95/PluQ-I/actions/runs/38660874079), [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38660874054), dan [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38660874040).
- Regresi pemulihan queue memverifikasi reservasi budget yang terkait dengan order stale juga berstatus `REVIEW`; kapasitas tetap dikunci sampai rekonsiliasi.


## Progress update — 2026-10-15: monthly settlement Drive scope

- Commit `c57c494b398aa6724f99bec028c1c6db476879ff` updates `bin/settle-monthly.php` so the idempotency lookup for an existing monthly spreadsheet is constrained to the configured destination folder as well as the settlement period/name and non-trashed status.
- Reason: the prior Drive query could find a matching tagged spreadsheet anywhere in the connected Drive and incorrectly mark it as this site's successful backup, even if it was outside the admin-configured folder.
- This is a source-level safety fix only. CI results for this commit are pending; Google OAuth/Drive calls have not been executed, and no backup integration has been enabled.
- Next: inspect CI result; then continue review of monthly settlement retry behavior and shared-hosting queue/preflight. Do not enable live payment, supplier orders, Drive backup, or deployment.


## Progress update — 2026-10-15: preflight checks reservation schema

- Commit `bb025e0539f63916ad87d29dd3fcd570e30a9156c` updates `bin/preflight.php` to require migration `011_provider_budget_reservations` and table `provider_budget_reservations` for all shared-hosting deployments. Queue/checkout budget safety relies on this table, so a preflight must not pass if it is absent.
- CI for the source change is pending. This is a code change and static preflight coverage; it has not been run against the user's hosting database.
- Also in this session, commit `c57c494b398aa6724f99bec028c1c6db476879ff` scoped monthly spreadsheet lookup to the configured Drive folder; syntax and PHP quality checks passed on that code commit, fulfillment safety test was still running at last poll.


## Progress update — 2026-10-15: paginated settlement Drive lookup

- Commit `29c4ba614bf3950fbd9bb518033029cf53fbcceb` changes `settlement_find_drive_file()` to walk Google Drive list pages (100 items per page) until it finds the matching spreadsheet or exhausts the results. The lookup remains scoped to the configured destination folder and settlement period.
- Reason: a one-page lookup could incorrectly report “not found” when matching period-tagged files were beyond the first page, causing an unnecessary duplicate spreadsheet to be created.
- Verification pending for this commit. No live Drive requests were made. The earlier preflight-schema commit `bb025e0539f63916ad87d29dd3fcd570e30a9156c` has all three CI workflows green.


## Pemulihan settlement jika file Drive hilang — 2026-10-15

- Commit `5de0c6cd837832ceaeb033c46ba7aaadea83b12f` menghapus penghentian dini untuk catatan settlement berstatus `UPLOADED`. Saat dijalankan ulang secara terkontrol, proses kini memeriksa lagi spreadsheet dengan metadata periode di folder tujuan; jika file tidak lagi ada di folder tersebut, ekspor dapat dibuat ulang alih-alih melaporkan sukses berdasarkan ID lama saja.
- Proses tetap memakai reservasi periode dan pemeriksaan status `PROCESSING`; file yang ditemukan melalui metadata idempotensi tidak dibuat ulang.
- Ketiga workflow CI pada commit kode `5de0c6cd837832ceaeb033c46ba7aaadea83b12f` sukses: [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38665307769), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/38665307770), dan [fulfillment safety/MySQL](https://github.com/sulistio95/PluQ-I/actions/runs/38665307764). Workflow pada pembaruan catatan berikutnya juga hijau pada SHA `1d357403` (runs 38665338318, 38665338288, 38665338132). Belum ada panggilan Google Drive/OAuth live. Backup settlement, pembayaran, pemesanan supplier, dan deployment tetap nonaktif.


## Audit antrean dan scheduler shared hosting — 2026-10-15

- Source review pada `bin/worker-queue.php`: worker memproses batch terbatas oleh `scheduler_batch_size` dan `scheduler_max_runtime_seconds`; klaim job memakai transaksi + `FOR UPDATE` dan token lock saat finalisasi. Lock `PROCESSING` yang lebih tua dari 10 menit tidak otomatis diulang karena supplier mungkin sudah menerima permintaan; order berbayar yang hasilnya belum pasti dipindah ke `MANUAL_REVIEW`, transaksi supplier dan reservasi kapasitas ke `REVIEW`.
- Endpoint non-CLI memerlukan header `X-Scheduler-Token`; worker berhenti jika pemesanan supplier dinonaktifkan. Untuk shared hosting, uji apakah layanan cron mendukung header tersebut atau jalankan worker via CLI jika tersedia. `bin/queue-health.php` bersifat read-only dan melaporkan backlog, job gagal, serta lock kedaluwarsa.
- `bin/preflight.php` memeriksa PHP 8.2+, ekstensi wajib, HTTPS, kekuatan APP_KEY/token scheduler, mode debug, status integrasi tetap nonaktif, koneksi DB, tabel, dan migrasi inti; migrasi/tabel settlement diperiksa jika backup diaktifkan. Belum dijalankan di hosting nyata.
- CI pada kode pemulihan settlement commit `5de0c6cd837832ceaeb033c46ba7aaadea83b12f` seluruhnya hijau (runs 38665307769, 38665307770, 38665307764). Workflow dari pembaruan catatan terbaru sedang diverifikasi; lihat Actions sebelum menyatakan semua perubahan catatan lolos.
- Belum ada perubahan konfigurasi produksi. Pembayaran, supplier ordering, settlement Drive, dan deployment tetap nonaktif.


## Progress update — 2026-10-15: contradictory Duitku callback reservation safety

- Code commit `d348ce67d0842010697f01687842c63ee8891f08` updates `public/duitku-callback.php`: when a signed callback conflicts with a terminal payment state, the order is moved to `MANUAL_REVIEW` while the payment state remains unchanged. Any `ACTIVE` provider budget reservation is now changed to `REVIEW` in the same DB transaction, so manual reconciliation retains capacity accounting and the reservation is not stranded as `ACTIVE`.
- Test commit `74f61479f83aa5cf9075f675a521dbfde0c38080` extends `tests/duitku_callback_integration.sh` to assert that a contradictory failure callback after `PAID` preserves the payment, moves the order to manual review, marks the reservation `REVIEW`, and does not create a second fulfillment job.
- CI for these commits must be checked before calling this change verified. The integration test requires its MySQL/HTTP test fixture; it has not been executed against live Duitku. Payment, supplier orders, Drive backup, and deployment remain disabled.


## Progress update — 2026-10-15: ambiguous supplier request releases no capacity

- Audit of `app/services/fulfillment.php` found that a stale supplier transaction already in `PROCESSING` was correctly moved to order `MANUAL_REVIEW` and supplier `REVIEW`, but its provider budget reservation could remain `ACTIVE`. This obscured its reconciliation state and depended on later queue finalization to resolve it, which is not guaranteed on every duplicate-job path.
- Code commit `05c4a126086de77d9760682f4c9fe324e7d36122` now resolves the reservation to `REVIEW` in the same transaction as the order/supplier transition. The capacity remains committed until a human reconciles the uncertain supplier outcome.
- Test commit `cbd833b1595f77b297b6434e646ffc3d070a9784` extends `tests/duplicate-queue-processing-integration.php` to assert the reservation also becomes `REVIEW` when stale ambiguous processing is detected.
- CI must be checked on these commits. This is source/CI coverage only; it is not a live supplier API test. Payment, supplier ordering, Drive backup, and deployment remain disabled.


## Progress update — 2026-10-15: stale queue recovery respects manual review

- Audit found that stale-lock recovery in `bin/worker-queue.php` could promote any paid order with a supplier transaction marked `SUCCESS` to order `SUCCESS`, even when the order was already `MANUAL_REVIEW` for another reason (including a contradictory signed payment callback). That could clear a human-reconciliation gate without resolving the payment conflict.
- Commit `479788514539cd77b214a5bed1d602e71f7f54e9` restricts supplier-success recovery to orders still in the automatic fulfillment states `QUEUED`, `PROCESSING`, or `RETRYING`. Already-successful orders remain idempotently successful; their budget reservation is resolved to `SETTLED`. A supplier-success record no longer overrides `MANUAL_REVIEW`.
- Test commit `442527632c2a4bfa999d47ea5303eace52203ab0` adds MySQL queue-recovery fixtures for both successful in-flight recovery (order `SUCCESS`, reservation `SETTLED`, job `DONE`) and preservation of payment-conflict `MANUAL_REVIEW` (reservation `REVIEW`, job `FAILED`). Existing ambiguous stale-request assertions remain.
- **Verification pending:** check CI on the code, test, and updated-notes commits. This is CI integration coverage only; no live supplier or payment API was called. Payment, supplier ordering, Drive backup, and deployment remain disabled.


## Follow-up — queue recovery test harness quoting correction (2026-10-15)

- CI on the first regression-test commit `442527632c2a4bfa999d47ea5303eace52203ab0` caught a test-harness issue, not a production PHP failure: the SQL JSON path `'$.order_id'` was embedded inside a shell single-quoted `php -r` script, so the shell removed the SQL quotes and MySQL reported `Unknown column '$.order_id'`.
- Commit `eb7e25fa4dd8540fdba02bc94403980c7d65909f` avoids shell quoting collisions by constructing the JSON path with SQL `CHAR(...)`. The assertions still target each job by its payload order ID; production logic is unchanged in this follow-up.
- **Verification pending:** rerun the MySQL integration workflow on this test fix and the current notes head. Do not mark the new recovery regression tests green until that run completes successfully.


## Follow-up — MySQL JSON path charset in queue recovery test (2026-10-15)

- The first shell-quoting correction (`eb7e25fa4dd8540fdba02bc94403980c7d65909f`) advanced the MySQL workflow past the previous shell error, but the test then failed because MySQL `CHAR(...)` produced a binary string for the JSON path (`Cannot create a JSON value from a string with CHARACTER SET 'binary'`).
- Test-only commit `53b2af99241193fbaacd3785352f5d457963770a` casts the constructed JSON path to `CHAR CHARACTER SET utf8mb4`. Production PHP logic is unchanged.
- **Verification pending:** rerun the MySQL queue recovery integration and inspect the latest full CI. The new recovery behavior is not considered verified until the integration workflow passes.


## Follow-up — preserve the reason for an existing manual review (2026-10-15)

- The latest MySQL regression run advanced through the queue recovery setup and found another real state-integrity issue: the order stayed `MANUAL_REVIEW`, but stale-lock recovery replaced its specific `last_error` (the payment callback conflict reason) with a generic queue timeout message. That would obscure the original reason for human reconciliation.
- Commit `fbc82eb00f14dfa9e2d539a15d25784d552f8f70` adds an `order_status<>'MANUAL_REVIEW'` guard to the stale recovery update, so an existing review state and its reason are preserved. Reservation capacity still moves/remains in `REVIEW`, and the stale queue job remains failed.
- **Verification pending:** rerun the MySQL integration workflow on this code change and the newest notes. No live APIs were called; production gates remain disabled.


## Verification result — stale queue recovery safety regression (2026-10-15)

- The final implementation/test commit `fbc82eb00f14dfa9e2d539a15d25784d552f8f70` passed all three workflows: PHP quality `38671179140`, PHP/JavaScript syntax `38671179042`, and fulfillment/MySQL integration `38671179031`.
- The latest handoff/audit documentation head `f9c908653cd6c2c177fa528a847e043311ef2e26` also passed syntax `38671219531`, PHP quality `38671219421`, and fulfillment/MySQL integration `38671219437`.
- The MySQL recovery regression now verifies: ambiguous stale requests become manual review with reservation `REVIEW`; supplier success recovers an in-flight order to `SUCCESS`, reservation `SETTLED`, and job `DONE`; supplier success cannot clear an existing payment-conflict `MANUAL_REVIEW` or its specific error reason, and its reservation remains `REVIEW` while the stale job ends `FAILED`.
- Evidence is limited to GitHub Actions PHP 8.2/MySQL 8.4 tests. Live supplier/payment APIs, shared-hosting MariaDB/cron, Drive OAuth/permissions, and deployment remain unverified and disabled.


## Shared-hosting readiness answer and sandbox gate status — 2026-10-15

- User asked whether PluQ-I is ready for shared hosting and transaction testing. Current answer: **not yet ready for a live shared-hosting transaction**. Code-level preparation and CI integration tests are in place, but the actual target hosting plan, public HTTPS callback, cron execution, real Duitku sandbox lifecycle, supplier APIs, and Drive permissions have not been verified.
- Latest known CI for queue recovery implementation `fbc82eb00f14dfa9e2d539a15d25784d552f8f70` passed all three workflows (PHP quality `38671179140`, syntax `38671179042`, fulfillment/MySQL `38671179031`). Audit documentation head `f9e611835d28772610dc89c7b4750fccbd1ebc34` also passed all three (PHP quality `38671358735`, syntax `38671358840`, fulfillment `38671358999`).
- Source review corrected a stale runbook claim: `checkout_sandbox_only_enabled()` now allows a non-production isolated sandbox path only with `APP_ENV=staging|test`, `DUITKU_ENV=sandbox`, payments enabled, and supplier orders disabled. CI runs `tests/duitku_sandbox_no_fulfillment_integration.sh` and verifies a valid simulated callback records PAID, puts the order into MANUAL_REVIEW, and enqueues no supplier fulfillment job. This is local CI simulation, not a real gateway transaction.
- Updated `docs/staging-payment-test-runbook.md` in commit `833bebe13bdec243f32122599632f464c0b8c690`. Next: verify CI on the latest documentation head, then continue auditing the deployment/preflight and hosting compatibility checklist. Keep real payments, supplier orders, settlement Drive export, and deployment disabled.


## 2026-10-15 — Shared-hosting cron evaluation for DomaiNesia Nimbus

- User has not purchased hosting or a domain yet. There is **no live cron configuration**; cron jobs are created later in the hosting control panel. Existing GitHub workflow schedules are CI tests, not application cron jobs.
- Source review: `config/app.php` reads `SCHEDULER_BATCH_SIZE` (default 5, bounded 1–20) and `SCHEDULER_MAX_RUNTIME_SECONDS` (default 20, bounded 5–50). These are per-run limits, not the schedule interval. `bin/worker-queue.php` is the real queue processor; `bin/worker.php` is diagnostic-only; `bin/queue-health.php` is a read-only CLI health report. The HTTP worker route requires `X-Scheduler-Token`, so CLI cron is preferred if the host supports it.
- README scheduled-work instructions were corrected in commit `9c25a7732630897f0a0346b48b40a0749b656ee2`: Nimbus example uses `*/6 * * * *` for queue worker; hosts limited to 15 minutes can use `*/15 * * * *` with longer expected queue delay. It now distinguishes optional catalog sync, balance sync, and monthly Drive settlement; those should only be enabled after credentials, rate limits, and staging checks.
- No production PHP behavior or environment defaults were changed. Keep payment, supplier ordering, and Drive backup disabled. Current defaults remain batch 5 and max runtime 20 seconds; no reason to increase them before measuring the target host.
- Before deploying, obtain the host's exact PHP CLI executable path and absolute project path; do not copy placeholder paths. Verify cron runtime limits, non-overlap, output logging, and queue health on the real account. No shared-hosting cron has been run yet.
- CI should be checked for the README and follow-up note commits; documentation changes are not evidence of live hosting validation.


## 2026-10-15 — Sembunyikan produk dari supplier yang tidak tersedia

- Audit katalog menemukan bahwa `catalog_products()` sudah menghitung kelayakan saldo dan memilih penawaran yang tersedia, tetapi jika semua penawaran untuk suatu nominal tidak tersedia, baris itu tetap dikirim ke frontend sebagai kartu “Tidak tersedia saat ini”. Ini bisa membuat produk VIPayment terlihat saat saldo VIPayment Rp0.
- Perubahan pada `app/services/catalog.php` (commit `3d305880ad76527387ab37dd76b5fa36665ee91e`): penawaran dengan saldo/status yang tidak memenuhi syarat sekarang dikeluarkan dari katalog pelanggan setelah pengelompokan penawaran setara. Jika Digiflazz tersedia dan VIPayment tidak, hanya penawaran Digiflazz yang memenuhi syarat yang dikirim. Jika dua supplier sama-sama tersedia, pemilihan harga termurah tetap otomatis.
- Tes regresi baru `tests/catalog-availability.php` memeriksa VIPayment saja tidak tersedia, Digiflazz tersedia + VIPayment Rp0, pemilihan termurah ketika keduanya tersedia, dan produk lain yang masih tersedia. Tes ditambahkan ke workflow PHP quality.
- Frontend `public/assets/catalog.js` sekarang menjelaskan bahwa tidak ada produk yang tersedia saat ini ketika daftar hasil kosong (commit `0bfa998be60aea4dfc270ea48c1470bd84cb08cc`).
- **Verifikasi CI untuk rangkaian perubahan ini masih harus diperiksa.** Tidak ada panggilan API supplier atau transaksi nyata. Perubahan ini tidak mengaktifkan pembayaran, pemesanan supplier, atau backup Google Drive.


## 2026-10-15 — Sinkronisasi regresi budget dengan katalog yang menyembunyikan produk tidak tersedia

- CI awal untuk perubahan katalog menemukan assertion lama di `tests/provider-budget-reservation-mysql-integration.php` masih mengharapkan satu kartu produk `is_available=false`. Itu bertentangan dengan perilaku baru yang sengaja menghilangkan produk yang tidak dapat dipesan.
- Fixture/assertion integrasi diperbarui: jika saldo/kapasitas provider tidak cukup, katalog harus mengembalikan nol produk yang memenuhi syarat; checkout quote juga tetap harus ditolak. Perubahan ada di commit `db02544a740f46e5491bd11c7f3f499c3f554f92`.
- Fixture unit `tests/catalog-availability.php` dilengkapi `base_price` untuk menghindari peringatan data uji yang tidak lengkap (commit `aaa3b34011a304275af94236b968d5e0ab723850`).
- **CI untuk commit perbaikan terbaru masih harus diperiksa.** Kegagalan integrasi sebelumnya adalah assertion lama yang belum menyesuaikan perilaku katalog; jangan menyatakan suite terbaru lulus sebelum workflow baru selesai.


## Latest work log — 2026-10-15 (catalog availability follow-up)

- Audited the customer catalog for the requested one-supplier startup scenario. app/services/catalog.php already removes unavailable supplier offers, selects an eligible Digiflazz offer when VIPayment has zero usable balance, and automatically chooses the cheapest equivalent offer when both are eligible. public/assets/catalog.js shows an empty-state message if no product is eligible; it does not ask the customer to choose a supplier.
- CI uncovered a stale MySQL integration assertion that still expected an unavailable product card. Commit f423392aad634c4d3dbde82dd1ffd6e957003cfd corrects the assertion to expect an empty catalog when budget is exhausted; availability restoration after reservations are released remains tested.
- All three GitHub Actions workflows passed for correction commit f423392aad634c4d3dbde82dd1ffd6e957003cfd: PHP quality 38758104195, PHP/JavaScript syntax 38758104376, and fulfillment/MySQL integration 38758105058. The integration ran in CI MySQL 8.4; this is not a live provider API or hosting test. No live provider API or hosting was used. Keep payment, supplier ordering, Drive backup, and deployment disabled.


## 2026-10-16 — Local MySQL deadlock classification verified

- User ran `php -l tests/checkout-deadlock-classification-integration.php`: no syntax errors.
- User ran the test through `php -r 'require "app/bootstrap.php"; require "tests/checkout-deadlock-classification-integration.php";'`; it passed with: `Real MySQL deadlock classification test passed (one victim classified for idempotency winner recovery).`
- This confirms the local MySQL deadlock classification scenario only. It is not evidence of a live Duitku request, real supplier order, or hosting deployment.
- Do not run `tests/queue_recovery_integration.sh` as-is. Also review/fix `tests/duplicate-queue-processing-integration.php` before local execution: it sets `SUPPLIER_ORDERS_ENABLED=true` and currently expects a pre-seeded catalog fixture, so keep supplier ordering disabled outside an isolated test database.
- Keep payment checkout, supplier orders, and settlement/Google Drive backup disabled. Update this handoff and the production-readiness audit after the next verified test/change.


## 2026-10-16 — Duplicate-queue integration fixture isolated

- Updated `tests/duplicate-queue-processing-integration.php` in commits `91d68b83eedd996bf02d7321330e35206ca8ffe2` and `c688130ea340afa2e9b3f9dfe04e8197d05b0302`.
- The test now creates its own unique game/product fixture against the seeded DIGI provider and removes the temporary catalog rows during cleanup, rather than requiring a pre-existing `integration-game/integration-sku` row.
- To make the enabled fulfillment path fail closed if a regression attempts an HTTP request, test-only Digiflazz and VIPayment URLs are pointed at loopback port 1 and use fake credentials. No real supplier endpoint should be reachable from this test.
- The test still temporarily sets `SUPPLIER_ORDERS_ENABLED=true` inside the test process to exercise `fulfillment_process()`; do not copy that setting into the local `.env` or production. All real payment/supplier/Drive flags remain disabled.
- **Not yet locally executed or CI-verified in this note.** Next, pull latest `main`, run PHP lint, then run through bootstrap against the disposable local MySQL database. Inspect exact output before marking passed.


## 2026-10-16 — Duplicate-queue integration test passed locally

- User pulled through commit `c25d005d5620a165c7d93b77222431814c90962b` and ran PHP lint plus the test through `app/bootstrap.php`.
- Reported output: `No syntax errors detected in tests/duplicate-queue-processing-integration.php` and `Duplicate queue job preserves fresh PROCESSING state; stale ambiguous requests enter manual review; reviewed and terminal orders cannot be revived.`
- The test is now verified against the user's local MySQL database. It uses a temporary catalog fixture and fake supplier credentials pointed at loopback port 1; this is not a live supplier test.
- Next safe check: `tests/duitku-transaction-status.php`, which uses a test transport callback and does not need real Duitku credentials or make a network request.


## 2026-10-16 — Duitku transaction-status helper test passed locally

- User ran `php -l tests/duitku-transaction-status.php`: no syntax errors.
- User ran `php tests/duitku-transaction-status.php`; output: `Duitku transaction-status signature, status mapping, and response validation tests passed.`
- Coverage includes HMAC signature validation, status mapping/unknown-status rejection, order/reference/amount validation, and rejection of the injected transport in production mode.
- This helper test uses an injected mock transport and did not contact Duitku. It is not proof of a real sandbox payment or shared-hosting callback.
- Next safe test: `tests/reconcile-cooldown-regression.php`, a structural regression test; inspect/run it without starting a web server or using gateway credentials. Keep payments, supplier ordering, Drive backup, and deployment disabled.


## 2026-10-16 — Admin Duitku reconciliation structural test passed locally

- User ran `php -l tests/reconcile-cooldown-regression.php`: no syntax errors.
- User ran `php tests/reconcile-cooldown-regression.php`; output: `Admin Duitku cooldown structural regression checks passed.`
- The structural guards check payment/order row locking, the 60-second cooldown, persistence of the STARTED audit marker before network I/O, CSRF validation, privileged-role enforcement, and that the route does not mutate payment/order state or initiate checkout/fulfillment.
- This is source-structure regression coverage only; it does not exercise an authenticated HTTP request, concurrent cooldown requests, a real Duitku status lookup, or shared hosting.
- Next: inspect and, if its setup is safe, run the isolated admin reconciliation HTTP integration test against localhost. Keep payment, supplier ordering, Drive backup, and deployment disabled.


## 2026-10-16 — Admin Duitku cooldown structural test passed locally

- User ran `php -l tests/reconcile-cooldown-regression.php`: no syntax errors.
- User ran `php tests/reconcile-cooldown-regression.php`; output: `Admin Duitku cooldown structural regression checks passed.`
- The test checks payment/order row-lock and cooldown source ordering, STARTED audit commit before network I/O, CSRF and role guards, and that the reconciliation route does not mutate payment/order state or invoke checkout/fulfillment.
- This is structural source coverage only, not an authenticated HTTP request or real Duitku status lookup.
- Next planned check: `tests/reconcile-admin-http-integration.php` against a local PHP development server at `127.0.0.1:8080` and the disposable local MySQL database. It creates uniquely named temporary ADMIN/OPERATOR rows and deletes them in a `finally` block. Start no live gateway; the test uses an invalid order ID and expects no gateway request. Run only with the local test database configured in `.env`.


## 2026-10-16 — Admin rekonsiliasi HTTP lulus secara lokal

- Pengguna menjalankan server PHP lokal pada `127.0.0.1:8080`; log menunjukkan permintaan anonim ke `/admin/reconcile.php` mendapat `302`, akses operator dan CSRF invalid mendapat `403`, POST tanpa aksi yang dikenali mendapat `400`, dan halaman admin berwenang mendapat `200`.
- Pengguna menjalankan `php tests/reconcile-admin-http-integration.php`; hasil: `Admin reconciliation HTTP boundary tests passed: anonymous redirect, operator forbidden, invalid CSRF forbidden, unknown POST rejected, authorized admin form rendered; no gateway request was made.`
- Ini memverifikasi batas akses HTTP untuk skenario yang diuji pada server/database lokal. Tidak ada request gateway Duitku, supplier, atau Google Drive. Ini bukan pengujian shared hosting atau transaksi sandbox nyata.
- Server pengembangan PHP hanya untuk lokal; hentikan dengan `Ctrl+C` setelah selesai. Pastikan `.env` tetap menunjuk ke database tes lokal.
- Langkah berikutnya: lanjutkan audit preflight dan keamanan deployment secara read-only, lalu pilih tes berikutnya setelah meninjau fixture dan efek sampingnya. Jangan jalankan `tests/queue_recovery_integration.sh` tanpa perbaikan isolasi karena skrip itu dapat mengubah job lain dan mengaktifkan supplier worker.
- Pembayaran, supplier ordering, backup Google Drive, dan deployment tetap nonaktif.


## 2026-10-16 — Read-only deployment document-root review

- Reviewed `bin/preflight.php`, `config/app.php`, `public/.htaccess`, and the hosting checklist in `README.md`.
- Preflight checks PHP 8.2+, required extensions, HTTPS `APP_URL`, minimum lengths for `APP_KEY` and `SCHEDULER_TOKEN`, `APP_DEBUG=false`, disabled payment/supplier/settlement flags, database connection, required tables, and recorded migrations. It deliberately does not echo secrets.
- Deployment finding: the repository has no root-level `.htaccess`; `public/.htaccess` only applies if the web server's document root is the `public/` directory. It adds directory listing protection and security headers, but it cannot protect private directories if the hosting document root is set to the repository root. Configure the document root to `public/` or verify explicit server rules deny access to `.env`, `app/`, `config/`, `database/`, `bin/`, and other private files before deployment.
- This was a source/documentation review only. No hosting account, web-server configuration, or production request was tested. Keep payment, supplier ordering, Drive backup, and deployment disabled.


## 2026-10-16 — Read-only deployment document-root review

- Reviewed `bin/preflight.php`, `config/app.php`, `public/.htaccess`, and the hosting checklist in `README.md`.
- Preflight checks PHP 8.2+, required extensions, HTTPS `APP_URL`, minimum lengths for `APP_KEY` and `SCHEDULER_TOKEN`, `APP_DEBUG=false`, disabled payment/supplier/settlement flags, database connection, required tables, and recorded migrations. It deliberately does not echo secrets.
- Deployment finding: the repository has no root-level `.htaccess`; `public/.htaccess` only applies if the web server's document root is the `public/` directory. It adds directory listing protection and security headers, but it cannot protect private directories if the hosting document root is set to the repository root. Configure the document root to `public/` or verify explicit server rules deny access to `.env`, `app/`, `config/`, `database/`, `bin/`, and other private files before deployment.
- This was a source/documentation review only. No hosting account, web-server configuration, or production request was tested. Keep payment, supplier ordering, Drive backup, and deployment disabled.


## 2026-10-16 — Local deployment preflight result

- User ran `git pull origin main`, `php -l bin/preflight.php`, and `php bin/preflight.php` on Puppy Bookworm64 with PHP 8.2.34.
- PHP syntax passed. Preflight reported **45/47 checks passed**. PHP version/extensions, `APP_KEY` and `SCHEDULER_TOKEN` length checks, disabled checkout/supplier/settlement flags, database connection, all 24 required base tables, and required migration records passed.
- Two expected environment-specific failures: `APP_URL uses HTTPS` (local URL is not HTTPS) and `APP_DEBUG disabled` (local debug is enabled). Do not change local development settings solely to make this production preflight pass; use an HTTPS staging URL and `APP_DEBUG=false` in staging/production.
- This result is local configuration verification, not proof of hosting readiness. Keep checkout payments, supplier ordering, settlement/Drive backup, and deployment disabled until staging gates are satisfied.


## 2026-10-16 — Environment-file tracking check and queue-test guard

- User pulled the latest main; git check-ignore -v .env returned .gitignore:1:.env .env, git status --short was empty, and git ls-files --error-unmatch .env reported that .env is not tracked. This verifies the current Git index; it does not scan old history for previously committed secrets. Never put secrets in Git or chat.
- Source review confirmed .github/workflows/fulfillment-tests.yml runs tests/queue_recovery_integration.sh. That legacy test marks all pending/retry jobs DONE and invokes the worker with SUPPLIER_ORDERS_ENABLED=true, so accidental execution against a non-disposable database could alter jobs or trigger fulfillment.
- Added a fail-closed guard to the script: it refuses to run unless DB_DATABASE=pluq_test and the explicit PLUQI_ALLOW_DESTRUCTIVE_QUEUE_RECOVERY_TEST=1 opt-in are both present. GitHub Actions sets that opt-in only on the stale-recovery test step, using its disposable pluq_test database. This is a guardrail, not a redesign of the legacy test; do not run it manually against local or hosting databases.
- CI verification for this guard and documentation update is pending. No local queue test was run, and no payment, supplier, or Drive request was made. Keep checkout payments, supplier ordering, settlement backup, and deployment disabled.


## 2026-10-16 — Queue-test guard CI verification

- After the queue-recovery invocation guard was added, GitHub Actions for commit `75603cd14db79d27fbf5dca4998285eb5f82905a` completed successfully: PHP quality `38851169829`, fulfillment safety tests `38851169810`, and PHP/JavaScript syntax `38851169695`.
- The guard is now present on main and the user pulled it locally; `git status --short` was empty. CI success verifies the workflow as run against its disposable MySQL service, not shared hosting or live payment/provider behavior.
- Continue reviewing operational entry points and cron safety. Keep live checkout, supplier fulfillment, settlement/Drive backup, and deployment disabled pending staging validation.


## 2026-10-16 — Shared-hosting worker, supplier-sync, and settlement audit

Read-only source review completed for queue worker, supplier balance/catalog sync, customer catalog eligibility, provider HTTP transport, and monthly settlement.

- `bin/worker-queue.php`: CLI or authenticated HTTP via `X-Scheduler-Token`; use CLI cron and keep `bin/` outside the public document root. It remains disabled unless `SUPPLIER_ORDERS_ENABLED=true`.
- Worker defaults to 5 jobs and 20 seconds, clamped to 1–20 jobs and 5–50 seconds. Runtime is checked between jobs, not as a hard process kill; a provider HTTP call can last up to 30 seconds, so a single invocation may exceed the worker target. Avoid overlapping cron runs; hosting runtime/cron behavior is untested.
- Stale locks older than 10 minutes are reviewed in batches of 50. Ambiguous supplier outcomes go to manual review instead of automatic re-submission. More than 50 stale locks require subsequent invocations.
- `bin/sync-balances.php` and `bin/sync-catalog.php` are CLI-only, skip missing credentials, and skip providers marked `DISABLED`. Balance sync updates provider status from supplier responses; catalog sync can refresh cached offers for `LOW_BALANCE`, but public catalog eligibility applies provider state, known balance, reserve capacity, and pricing-rule checks.
- A provider with balance 0 cannot sell positive-cost offers under the reserve-capacity calculation. The existing `tests/catalog-availability.php` covers hiding unavailable offers, preferring an available provider, and selecting the cheapest available offer. This was code review; no supplier API call was made.
- `bin/settle-monthly.php` is CLI-only and refuses to proceed unless `SETTLEMENT_BACKUP_ENABLED=true`. It supports a normal first-of-month run and controlled recovery for a completed month; a recent PROCESSING reservation blocks duplicate work for 30 minutes. When enabled it performs Google OAuth/Drive/Sheets network operations and creates or updates a spreadsheet, so keep it disabled until OAuth, folder access, migrations 006–007, and recovery/restore checks are verified.
- No live API, Google, or hosting calls/tests occurred. Checkout payments, supplier ordering, settlement backup, and deployment remain disabled.

Next: run safe local regression tests only after confirming the local `.env` targets the disposable test DB, then review hosting-specific PHP CLI path, cron availability, document-root isolation, and backup/restore requirements. Do not manually run destructive queue-recovery scripts against a developer or hosting database.


## 2026-10-16 — CI evidence and shared-hosting gate update

- Inspected the job/step records on commit `ca719f2442b1e5ade88358c7970473303d28e6ac`: all three latest workflows passed, including MySQL 8.4 concurrency, checkout deadlock/idempotency, callback boundary, queue claim/recovery, and sandbox no-fulfillment tests. Links: [fulfillment](https://github.com/sulistio95/PluQ-I/actions/runs/38851721983), [quality](https://github.com/sulistio95/PluQ-I/actions/runs/38851722224), [syntax](https://github.com/sulistio95/PluQ-I/actions/runs/38851721990).
- Updated `docs/staging-payment-test-runbook.md` (commit `eb3773166795a9608270619baf202b56470a76e3`) with current CI evidence, hosting checklist, and a no-go-live gate.
- Repository has only `public/.htaccess`, not a root-level one. Point the web document root to `public/`; never expose the repository root directly.
- No real external Duitku/supplier/Google API or hosting test was performed. Keep all transaction/fulfillment/settlement flags off. Production Duitku mode is not implemented.
- Next: review staging domain and hosting capabilities only when provided/available; then run preflight against a dedicated staging DB, verify cron and document-root isolation, and conduct controlled sandbox testing. Never run queue recovery tests against a non-disposable DB.


## Admin supplier-driven game selection — 2026-10-19

- Commit `970dea8b2e829c1d5cd3d63c82b38551b9b73ea5` replaces the manual mapping form with supplier selection, explicit API game-list fetch, checkboxes, and save.
- The saved checkbox state is provider-specific. Switching suppliers does not erase the previous supplier's saved choices. A failed supplier request must not deactivate mappings.
- The visible UI no longer asks for supplier game codes or supplier game names. Codes are retained internally for catalog matching.
- Same-named selected games across suppliers are attached to one canonical web game; storefront visibility follows active mappings from either supplier.
- CI passed for the code commit: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39204606529), [MySQL callback integration](https://github.com/sulistio95/PluQ-I/actions/runs/39204606531), and [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39204606555). CI does not prove live provider API responses or browser/database save-and-reload behavior. Duplicate-name merging and customer-facing server/region choices still need local integration verification. Keep checkout, supplier orders, and Drive backup disabled.


## Default keuntungan awal dan panel yang dapat diedit — 2026-10-19

- Aturan keuntungan default yang telah disepakati tetap dipasang pada migration awal `database/migrations/002_catalog_and_operations.sql`: modal Rp0–Rp10.000 mendapat laba Rp500; di atas Rp10.000 sampai Rp100.000 mendapat Rp1.000; di atas Rp100.000 sampai Rp500.000 mendapat Rp2.000; dan di atas Rp500.000 mendapat Rp3.500.
- Migration `012_pricing_rules_hybrid.sql` mempertahankan aturan lama dengan menyalin `profit_amount` menjadi `profit_value` bertipe `FIXED`; jadi perubahan ke model nominal/persentase tidak mengganti laba nominal default yang sudah disepakati.
- Panel `/admin/pricing-settings.php` memungkinkan ADMIN/SUPER_ADMIN mengubah rentang modal, nominal atau persentase, dan status aturan. Harga katalog dan kutipan checkout membaca aturan aktif dari database yang sama. Biaya admin invoice tetap terpisah dari laba.
- Verifikasi GitHub Actions pada commit `a3740fe717da2da54ac11b619172dd07c6f253ee` berhasil: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39209913651), [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39209913521), dan [fulfillment/MySQL integration](https://github.com/sulistio95/PluQ-I/actions/runs/39209913435).
- Ini bukti CI pada database MySQL pengujian, bukan uji browser/shared hosting atau transaksi Duitku/provider nyata. Untuk menggunakan panel, tarik perubahan dan jalankan migration 012 setelah migration 011. Jangan menimpa aturan yang sudah disesuaikan admin saat deployment; migration mempertahankan data lama.


## Update — 2026-10-19: Pratinjau katalog VIPayment dan penghapusan konfigurasi API

- Commit `a981ef81d24df0efe031e75c474b6f807d55bea7` memperbarui `public/admin/api-settings.php`.
- Panel sekarang memiliki tombol **Ambil daftar game dan layanan** yang memanggil helper `vipayment_game_services(null, null)` untuk mengambil daftar layanan VIPayment, lalu menampilkan pratinjau maksimum 100 baris dan jumlah layanan/game yang teridentifikasi. Ini hanya pratinjau; tidak menulis ke tabel katalog dan tidak membuat pesanan.
- Panel **Hapus API tersimpan** menyediakan tombol hapus per nilai konfigurasi yang tersimpan di database. Permintaan POST memerlukan CSRF valid, nama konfigurasi dibatasi pada allowlist, penghapusan dicatat dalam audit log dan memakai transaksi database.
- Penghapusan hanya menghapus nilai `api_secret_*` dari database. Nilai yang ditetapkan lewat `.env` atau environment hosting tidak dapat dihapus dari panel ini dan perlu dihapus terpisah.
- Perubahan sudah terkonfirmasi di GitHub melalui commit di atas. **Belum diuji lint lokal, browser, maupun dengan API VIPayment nyata**. Setelah pengguna menarik commit, jalankan `php -l public/admin/api-settings.php`; lalu login sebagai ADMIN/SUPER_ADMIN, periksa tombol pratinjau dan penghapusan. Jangan gunakan tombol pratinjau sampai kredensial VIPayment sudah benar karena itu mengirim permintaan baca ke API eksternal.
- Tetap nonaktifkan pembayaran checkout, pesanan supplier, dan backup Google Drive. Pratinjau katalog tidak mengaktifkan ketiganya.

- Follow-up CI verification on commit `6a1527b2496f20207a626cdaa3f8071e089441de` passed: [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39234587186), [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39234586979), and [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/39234586947). These CI checks do not exercise browser interaction or call the live VIPayment API.

## Update — 2026-10-19: Panel konfigurasi form akun per game

- Commit `4a17016b8d2cf7b7b97cbbff34f102743208184a` menambahkan `public/admin/game-fields.php`; commit `be413cf13c8f9c621f8fee98b8acab5b0dcbb7cf` menautkan halaman sebagai **Konfigurasi Form Akun** di navigasi admin.
- Panel memungkinkan ADMIN/SUPER_ADMIN memilih game, membuat/mengubah kolom teks/angka/dropdown, menentukan kunci data, label, placeholder, required, regex opsional, urutan dan status aktif. Opsi dropdown ditulis satu per baris dengan format `nilai|Label`. Kolom dapat dinonaktifkan tanpa menghapus catatan konfigurasi.
- Aksi perubahan memakai POST, token CSRF, pemeriksaan peran, prepared statements, transaksi, dan audit log. Dropdown aktif wajib memiliki setidaknya satu opsi. Data disimpan pada tabel `game_fields` dan `game_field_options` yang sudah disediakan migration 002.
- **Status pengujian:** kode tersimpan di GitHub, tetapi lint PHP, uji browser, uji CSRF/role untuk halaman baru, dan pengujian database lokal belum dijalankan. Sesudah `git pull`, jalankan `php -l public/admin/game-fields.php` dan `php -l public/admin/index.php`, lalu buka panel admin dan konfigurasi satu game uji.
- Contoh konfigurasi awal: Mobile Legends biasanya memerlukan `user_id` dan `zone_id`; HOK menggunakan `user_id`. Pastikan persyaratan masing-masing game benar sebelum mengaktifkan kolom. Halaman ini belum melakukan pengecekan nama akun ke game dan belum memastikan pemetaan kunci ke format request tiap supplier.
- Jangan mengaktifkan checkout, pemesanan supplier, atau backup Google Drive. Setelah UI diuji, pekerjaan berikutnya adalah validasi akun sisi server serta pengujian mapping field per supplier sebelum transaksi diaktifkan.


## Update — 2026-10-19: Konfigurasi form akun game

- Halaman admin `public/admin/game-fields.php` tersedia dan ditautkan dari panel admin sebagai **Konfigurasi Form Akun**. Admin dapat memilih game, menambah/mengubah kolom `text`, `number`, atau dropdown; menentukan label, key, placeholder, regex format, wajib/tidak wajib, urutan, dan status; serta mengatur opsi dropdown dengan format `nilai|label`.
- Memakai tabel `game_fields` dan `game_field_options` dari migration `002_catalog_and_operations.sql`; tidak perlu migration tambahan untuk fitur ini.
- Perubahan pada 2026-10-19 memperketat edit agar field hanya bisa diperbarui dalam game asalnya, dan menonaktifkan opsi lama ketika tipe kolom diubah dari dropdown menjadi teks/angka. Semua perubahan menggunakan transaksi DB, CSRF, pembatasan role ADMIN/SUPER_ADMIN, dan audit log.
- Frontend membaca konfigurasi melalui `GET /api/games/{slug}/fields`. Contoh umum: Mobile Legends `user_id` + `zone_id`; HOK `user_id` saja, tetapi cocokkan kebutuhan setiap game dengan format provider yang benar.
- Status verifikasi: perubahan hardening dikomit pada `ad20f9627f7b7b6a50bcc74a75cee373939bd1df`. CI dan uji interaktif browser/database setelah commit masih perlu diperiksa. Jangan klaim pengujian runtime berhasil sebelum ada hasil aktual.
- Langkah pengguna: pull `main`, buka **Panel Admin → Konfigurasi Form Akun**, pilih game, tambahkan kolom sesuai kebutuhan, simpan, lalu buka game dari halaman depan untuk memastikan field tampil. Jangan aktifkan pembayaran, pemesanan supplier, atau backup Google Drive selama pengujian.


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


## Update — 2026-10-19: Hasil pengujian GitHub untuk form akun otomatis

- Perbaikan fixture tes integrasi MySQL: nama game uji sebelumnya tidak termasuk daftar skema akun yang diizinkan, sehingga checkout sengaja ditolak oleh guard baru. Fixture kini menggunakan nama Honor of Kings agar pengujian concurrency menguji tujuan aslinya, bukan ditolak oleh aturan skema.
- Commit perbaikan fixture: `07ad701af829114d387edf993c1007c79121c658` dan `8f47fbb14d0a5c4c17a56ac2cca18cc803e78a54`.
- Pada commit `8f47fbb14d0a5c4c17a56ac2cca18cc803e78a54`, ketiga workflow GitHub Actions selesai dengan sukses: PHP quality checks, PHP/JavaScript syntax checks, dan Fulfillment safety tests (termasuk tes integrasi MySQL dan checkout concurrency).
- Hasil ini adalah pengujian otomatis di runner GitHub dengan MySQL test dan Duitku mock/sandbox fixture. Ini bukan pengujian pada hosting, browser pengguna, API VIPayment langsung, pembayaran nyata, supplier order nyata, atau backup Google Drive.
- Ketiga workflow diulang pada head PR terbaru `97078e1f07681a050b75f2fb74d529236c882db7` setelah pembaruan catatan, dan semuanya sukses. PR #3 kemudian berhasil di-squash-merge ke `main` pada 2026-10-19.
- Merge commit di `main`: `0b246f8d7aaaa3d3506530e9d88e7874b65447c8`. GitHub belum melaporkan workflow run terpisah untuk merge commit tersebut saat catatan ini diperbarui; hasil CI yang terverifikasi adalah seluruh workflow sukses pada head PR tepat sebelum merge.
- Pengguna dapat mengambil branch `main` untuk pengujian lokal. Jangan aktifkan transaksi produksi sebelum field akun dan pengecekan nickname diuji untuk game/provider yang digunakan.
- Batas keselamatan tetap berlaku: mapping nama game bukan schema resmi VIPayment; jangan aktifkan transaksi produksi sebelum uji lokal memverifikasi field dan nickname check per game.


## Update — 2026-10-19: Halaman lihat harga modal supplier

- Menambahkan halaman baca-saja `public/admin/supplier-prices.php` untuk melihat harga modal yang disimpan dari sinkronisasi katalog, harga jual yang dihitung dari aturan keuntungan aktif, dan selisih keuntungan.
- Halaman menyediakan filter supplier, game, dan pencarian nama/kode; menampilkan hingga 200 baris per tampilan. Produk yang belum dipetakan juga dapat diperiksa. Tidak ada fungsi mengubah harga atau membuat pesanan.
- Menambahkan tautan **Harga Modal Supplier** di navigasi panel admin.
- Commit halaman: `10f73ac1a015b9fe8263b146961f9ead61d88175`; commit navigasi: `4369ea04c34c175a1488bee85c6825feaa27b424`.
- Belum menjalankan PHP lint atau pengujian database/browser untuk halaman baru. Setelah `git pull origin main`, jalankan `php -l public/admin/supplier-prices.php` dan `php -l public/admin/index.php`, lalu buka **Harga Modal Supplier** dari panel admin. Jika tabel kosong, sinkronkan katalog supplier dan pastikan pemetaan game sudah disimpan. Pembayaran, pesanan supplier, dan backup Drive tetap nonaktif.


## Update — 2026-10-19: Pengurutan harga modal

- Menambahkan pilihan urutan di halaman **Harga Modal Supplier**: **Terendah ke tertinggi** dan **Tertinggi ke terendah**, berdasarkan harga modal supplier.
- Filter supplier, game, dan pencarian tetap dapat dipakai bersamaan dengan pengurutan.
- Commit: `4b42f2ab980210db77f7f1238a48695e46f0afca`.
- Belum menjalankan PHP lint atau uji browser lokal setelah perubahan ini. Untuk mengambil perubahan: `git pull origin main`; kemudian periksa dengan `php -l public/admin/supplier-prices.php`.


## Update — 2026-10-20: Pilih API untuk dihapus dan profil API custom

- Mengubah **Pengaturan API → Hapus API tersimpan** menjadi satu dropdown untuk memilih provider yang tepat: Duitku, Digiflazz, VIPayment, atau profil custom tertentu. Tombol hapus tidak lagi membuat satu tombol per kolom kredensial.
- Menghapus satu provider bawaan menghapus semua key/secret/URL terkait yang disimpan di `app_settings` dalam satu transaksi DB dan mencatat audit log. Profil custom dihapus berdasarkan ID profil yang dipilih. Data transaksi, pesanan, mapping game, dan katalog sengaja dipertahankan agar riwayat dan relasi tetap utuh.
- Menambahkan form **Tambah API custom** dengan nama, jenis Payment Gateway/Supplier, HTTPS base URL, metode autentikasi, API key, secret, dan token. Profil beserta kredensial disimpan terenkripsi menggunakan Sodium dan dapat dipilih secara spesifik pada dropdown penghapusan.
- Batas integrasi penting: profil custom saat ini adalah penyimpanan konfigurasi terenkripsi, belum otomatis menjadi adapter pembayaran/supplier. Sebelum digunakan untuk checkout, pembayaran, sinkronisasi katalog, atau fulfillment, perlu adapter khusus provider, validasi signature/callback, pemetaan respons, dan tes sandbox.
- Menghapus konfigurasi database tidak mengubah nilai yang ditetapkan di `.env` atau environment hosting; hapus nilai tersebut terpisah bila ingin benar-benar mengganti kredensial. Riwayat transaksi dan katalog tidak ikut dihapus.
- Commit kode: `5b551871ba7c151e0a790581e8cafb20eff212ac`.
- GitHub Actions pada commit ini sedang berjalan ketika catatan ditulis: [PHP/JavaScript syntax](https://github.com/sulistio95/PluQ-I/actions/runs/39319602896), [fulfillment safety](https://github.com/sulistio95/PluQ-I/actions/runs/39319602645), [PHP quality](https://github.com/sulistio95/PluQ-I/actions/runs/39319602539). Jangan menganggapnya lulus sampai status final diperiksa. PHP lint lokal serta pengujian halaman di browser/database masih pending.
- Tetap biarkan pembayaran, pesanan supplier, dan backup Google Drive nonaktif selama pengujian.


## Update — 2026-10-20: Upload dan hapus gambar banner lokal

- Memperbarui `public/admin/template-settings.php`: admin dapat memilih gambar dari laptop atau tetap memakai URL HTTPS; saat mengedit, kolom URL yang dikosongkan mempertahankan gambar lama, sedangkan unggahan baru menggantinya setelah penyimpanan berhasil.
- Format yang diterima: JPG/JPEG, PNG, WebP; maksimum 5 MB; pemeriksaan MIME, dimensi (maksimum 8000×8000 dan 25 megapiksel), nama acak, dan penyimpanan di `public/uploads/banners/`. SVG dan format lain ditolak.
- Menambahkan `public/uploads/banners/.htaccess` untuk menonaktifkan listing direktori dan menolak akses ke file berekstensi skrip. Folder unggahan harus dapat ditulis oleh PHP di hosting; unggahan tetap dibatasi oleh `upload_max_filesize` dan `post_max_size` PHP.
- Daftar banner kini memiliki aksi **Hapus** permanen dengan CSRF, role admin, audit log dan transaksi database. Jika gambar berasal dari folder lokal PluQ-I, file ikut dihapus; jika URL eksternal, hanya baris banner yang dihapus. **Nonaktifkan** tetap merupakan tindakan non-destruktif.
- Commit kode: `f1824eb05e28a9eebc3c41fbe0bf838a75418c4e`; rollback hardening: `1fc141bf71ac435888bf9a9d7bf4703b1eac84d2`; proteksi folder upload: `c78151b34daa8ef3157dce7c039c593f55b54b3a`.
- Belum mengklaim PHP lint, CI, upload runtime, atau uji browser berhasil. Periksa GitHub Actions setelah commit dokumentasi ini; lalu tarik `main`, jalankan `php -l public/admin/template-settings.php`, dan uji tambah gambar lokal, edit/ganti gambar, nonaktifkan, hapus, serta gambar URL eksternal di database lokal.
- Jangan aktifkan checkout, pemesanan supplier, atau backup Google Drive selama pengujian.


## Follow-up hardening upload banner — 2026-10-20

- Memperketat deteksi file lokal agar penghapusan hanya dapat menyasar URL relatif tepat berbentuk `/uploads/banners/{nama-acak}.{jpg|png|webp}`, bukan URL eksternal yang kebetulan memakai path serupa. Commit: `9ef66075a549a93c361de259a85a0ef05b89271b`.
- Menyederhanakan `public/uploads/banners/.htaccess` untuk menonaktifkan directory listing dan handler ekstensi skrip. Commit: `5a6802401a36d31c328da92a4e64813bd7648693`.
- Status CI yang teramati: PHP quality dan syntax lulus pada commit kode `9ef66075`; syntax juga lulus pada commit `5a680240`. Fulfillment safety dan PHP quality pada head terbaru masih berjalan saat catatan ini diperbarui. Upload dan uji browser/database lokal tetap belum diuji.


## Update — 2026-10-20: Upload/hapus icon game pada Pengaturan Template

- Memperluas `public/admin/template-settings.php` dengan bagian **Icon Game List**. Bagian ini mengambil hanya `games.status='ACTIVE'`, yaitu game yang memiliki mapping supplier aktif setelah pilihan dicentang/disimpan pada `/admin/game-mappings.php`. Tidak perlu migration baru: icon disimpan pada kolom `games.icon_url` yang sudah digunakan endpoint katalog publik.
- Admin yang berwenang dapat mengunggah atau mengganti icon lokal (JPG/PNG/WebP, maks. 5 MB, validasi MIME dan dimensi maks. 4000×4000/10 MP). File disimpan dengan nama acak di `public/uploads/game-icons/`; perubahan `icon_url` dan audit log dilakukan dalam transaksi. Icon lokal lama dihapus setelah perubahan berhasil.
- Tombol **Hapus icon** mengosongkan `games.icon_url`, mencatat audit log dan menghapus file lokal yang dikelola PluQ-I. Game, status game, dan mapping supplier tetap utuh; URL gambar eksternal juga tidak disentuh di server asalnya.
- Tambah `public/uploads/game-icons/.htaccess` untuk menonaktifkan directory listing dan menghapus handler ekstensi skrip. Folder harus dapat ditulis oleh PHP. Tetap validasi konfigurasi hosting sebelum produksi.
- Commits: halaman template `41543b6e3cbce9bc97cb196d825f5e09faeef567`; proteksi direktori `7164700e961485fe800a8feb97efd8372aeb9c1d`.
- Belum mengklaim PHP lint, CI terbaru, upload browser, atau uji homepage. Tarik `main`, jalankan `php -l public/admin/template-settings.php`, uji pilih/simpan game di Game List, unggah/ganti/hapus icon, dan pastikan `GET /api/games`/homepage menampilkan icon baru. Jangan aktifkan pembayaran, supplier ordering, atau backup Google Drive selama pengujian.


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


## Update — 2026-10-20: Pemeriksaan nickname VIPayment opsional
- Endpoint `POST /api/games/{slug}/check-username` pada `public/index.php` sudah memanggil VIPayment `POST /game-feature` dengan `type=get-nickname`, `code`, `target`, dan `additional_target`; kredensial/signature dan koneksi IPv4 memakai helper `vipayment_post()`.
- Perbaikan frontend di `public/assets/catalog.js` (commit `d32ae43c433cde7487efcd5ac70d4e7c034bf778`): jika server mengembalikan HTTP 409 karena pemeriksaan nickname tidak didukung atau pemetaan/kode layanan tidak tersedia, hasil tetap kosong dan akun tidak diblokir oleh pemeriksaan nickname. Validasi kolom akun biasa tetap berlaku.
- Respons nickname sukses tetap ditampilkan. Error validasi ID dari API (mis. HTTP 422), timeout/kegagalan jaringan, atau pembatasan laju tidak dianggap sebagai dukungan yang tidak tersedia; hasil error tetap terlihat dan pilihan nominal tetap tertahan sampai pemeriksaan berhasil.
- Belum menjalankan PHP lint, JavaScript syntax check, atau panggilan API nyata dengan kredensial VIPayment. Verifikasi lokal: `php -l public/index.php`, `node --check public/assets/catalog.js`, lalu uji satu game yang mendukung nickname dan satu game yang tidak mendukungnya. Jangan mengaktifkan pembayaran/pemesanan supplier untuk pengujian ini.


## Lanjutan — 2026-10-20: Cek username FF gagal di PluQ-I

- FF cek nickname terlihat pada aplikasi VIPayment, tetapi endpoint PluQ-I mengembalikan pesan umum. Kode saat ini menggunakan kode produk termurah untuk `get-nickname`, yang belum tentu merupakan kode lookup yang benar.
- Commit diagnosis `ff0bc98a510f2f269340576132bafef18d874060` menambahkan log server-side dengan awalan `[PluQ-I username-check]`; respons publik tetap generik dan tidak menampilkan kredensial.
- Reproduksi sekali, lalu cari awalan tersebut di error log PHP/web-server hosting. Laporkan hanya teks error yang sudah disensor; jangan bagikan API ID/key/sign atau payload sensitif.
- Setelah error diketahui, verifikasi kode lookup FF yang sesuai dari VIPayment dan hindari memakai kode termurah secara otomatis. Tes PHP lint, CI, browser, dan API nyata belum dilakukan. Pembayaran, supplier ordering, dan backup Drive tetap nonaktif.


## Update — 2026-10-20: Perbaikan aman pengecekan nickname Free Fire

- Diagnostik lokal: VIPayment menolak `ff5-s13` dengan pesan `Provider for game code 'ff5-s13' is not available.`
- Perubahan berada di branch `fix/free-fire-nickname-lookup` (belum di-merge ke `main`). File `app/services/game_fields.php` kini menganggap lookup nickname Free Fire belum didukung sampai kode lookup yang benar dikonfirmasi. HOK tetap opsional seperti sebelumnya.
- Karena endpoint fields menyatakan lookup tidak tersedia, frontend seharusnya melewati cek nickname untuk FF; input User ID dan pemilihan nominal tidak lagi bergantung pada API nickname. Kode produk top-up termurah tidak lagi dipakai untuk cek nickname FF.
- Dokumentasi VIPayment: https://vip-reseller.co.id/page/api/game-feature — mencantumkan parameter `get-nickname`, tetapi tidak mengidentifikasi kode lookup Free Fire yang valid.
- Tes schema ditambah untuk guard Free Fire. Belum menjalankan lint, tes lokal, CI, browser, atau panggilan API live pada branch ini.
- Setelah perubahan di-merge ke `main`, di Puppy Linux jalankan `git pull origin main`, `php -l app/services/game_fields.php`, `php tests/game-account-schema.php`, dan `php -l public/index.php`; lalu uji halaman Free Fire. Jangan aktifkan pembayaran, supplier ordering, atau backup Drive.

## 2026-10-20 — Koreksi label Username

- Pengguna mengklarifikasi bahwa yang harus dihapus hanya teks penjelas/helper tentang pengecekan username otomatis, bukan label/kolom **Username** itu sendiri.
- Perubahan UI pada branch `fix/restore-username-label` mengembalikan label `Username` di area hasil pemeriksaan pada `public/index.php`, tanpa memulihkan teks helper. Field User ID dan schema akun otomatis tetap dipertahankan; lookup Free Fire tetap opsional sampai kode VIPayment yang valid diketahui.
- Verifikasi browser dan lint lokal belum dilakukan. Setelah merge, tarik `main`, jalankan `php -l public/index.php`, lalu buka Free Fire untuk memastikan label Username dan kolom ID tampil sementara area hasil username tetap kosong tanpa teks penjelas.
- Pembayaran, pemesanan supplier, dan backup Google Drive tetap nonaktif.


## 2026-10-20 — Hapus teks Username, pertahankan area hasil

- Klarifikasi pengguna: hapus tulisan `Username` dan teks penjelas, tetapi jangan hapus area/kolom hasil; jika pengecekan gagal, tampilkan `Pengecekan username gagal`.
- Pada branch `fix/username-status-without-label`, `public/index.php` menghapus label `Username` tetapi mempertahankan `#username-check` dan `#username-result`. `public/assets/catalog.js` menampilkan pesan generik `Pengecekan username gagal` untuk kegagalan lookup yang dicoba.
- Untuk Free Fire/HOK yang lookup-nya dinonaktifkan karena kode/dukungan belum dikonfirmasi, area status tetap kosong; User ID dan form tetap tampil.
- Lint PHP/JS dan uji browser belum dilakukan. Jangan aktifkan pembayaran, pesanan supplier, atau backup Drive.


## 2026-10-20 — Koreksi kode khusus VIPayment Get Nickname

- Pengguna memberikan daftar kode resmi dari komentar fitur VIPayment Get Nickname. Kode lookup Free Fire adalah `free-fire`, bukan kode produk top-up `ff5-s13`; daftar juga mencantumkan `mobile-legends`, `mobile-legends-region`, `pubgm`, `valorant`, `genshin-impact`, `honkai-star-rail`, dan `pointblank`.
- Mengganti pemetaan cek nickname agar memakai kode khusus Game Feature berdasarkan game, bukan memilih kode produk VIPAY termurah dari tabel katalog. Kode lookup dipisahkan dari kode top-up. Honor of Kings tetap tidak didukung karena tidak tercantum pada daftar yang diberikan.
- Endpoint tetap memakai `type=get-nickname`, `target=user_id`, dan `additional_target=zone_id/server` (kosong untuk game yang hanya memerlukan User ID). Pengecekan nickname tidak membuat order supplier.
- Menambahkan tes unit pemetaan kode untuk delapan kode yang tersedia dalam daftar. Tes lokal dan panggilan API VIPayment live belum dijalankan pada saat commit; hasil nickname Free Fire perlu diuji dari browser dan log server setelah update.
- Tetap jangan aktifkan pembayaran, pemesanan supplier, atau backup Google Drive selama pengujian.


## 2026-10-20 — Pengecekan nickname dipisahkan dari supplier

- Arah arsitektur diperbarui sesuai keputusan pengguna: pengecekan nickname tidak lagi memanggil endpoint Game Feature VIPayment atau memakai kode produk Digiflazz/VIPayment. Dibuat adapter terpisah `app/services/game_username_check.php` untuk API Game Check eksternal melalui HTTPS, cURL, validasi TLS, timeout, dan IPv4.
- Kandidat API yang dipilih untuk integrasi awal adalah Game Check by Evo Game Store: `https://gamecheck.evogamestore.com/api/v1/check`. Dokumentasinya menyebut 8 game dan mewajibkan API key gratis melalui halaman request. API key disimpan di environment server `GAME_CHECK_API_KEY`, bukan JavaScript atau GitHub. Referensi dokumentasi: https://game-check.evogamestore.com/.
- Cakupan awal yang dipetakan: Mobile Legends, Mobile Legends Region, Free Fire/Free Fire MAX, PUBG Mobile, Valorant, Genshin Impact, Honkai: Star Rail, dan Point Blank. Game lain—termasuk Honor of Kings—belum didukung oleh layanan ini dan harus menampilkan kondisi tidak tersedia, bukan mengklaim semua game dapat diverifikasi.
- Metadata cek nickname kini tidak bergantung pada katalog produk, saldo, atau status supplier. Kode game checker dipisahkan dari kode produk top-up. Tambahan `GAME_CHECK_API_KEY=` dimasukkan ke `.env.example`.
- Tes pemetaan schema diperbarui. Belum ada panggilan API live karena API key pengguna belum dikonfigurasi; PHP lint, browser, serta workflow CI perlu diverifikasi setelah perubahan. Tidak ada order supplier, pembayaran, atau backup Drive yang dibuat/diaktifkan.


## Update — 2026-10-20: Proteksi trafik pemeriksaan nickname

- Pada branch `fix/vipayment-dedicated-nickname-codes`, menambahkan migration `013_game_username_check_protection.sql` untuk cache hasil sukses dan fixed-window rate limit berbasis MySQL/InnoDB. Tidak membutuhkan Redis atau worker permanen, sesuai target shared hosting.
- Endpoint pemeriksaan kini membatasi 20 request per IP per 60 detik, 1 panggilan upstream untuk fingerprint akun yang sama per 5 detik, dan maksimal 50 panggilan upstream global per 60 detik. IP dan fingerprint disimpan sebagai hash; ID akun mentah tidak dimasukkan ke tabel cache.
- Hasil nickname yang berhasil di-cache selama 5 menit dengan kunci yang mencakup game dan semua field akun (termasuk zone/server). Hasil ID tidak ditemukan dan error provider tidak di-cache. Request cache-hit tetap dikenai rate limit IP tetapi tidak menghabiskan kuota API eksternal.
- Timeout API diturunkan menjadi 8 detik (connect timeout 5 detik), koneksi tetap HTTPS/TLS-verified dan dipaksa IPv4. Respons rate-limit lokal/provider dipetakan ke HTTP 429.
- Dokumentasi Evo Game Store menyatakan layanan mereka memakai primary source + automatic fallback chain dan smart cache TTL 10 menit; integrasi PluQ-I menggunakan cache 5 menit sendiri sebagai lapisan tambahan. Ini bukan jaminan uptime 100%, dan cakupan API tetap 8 game. Sumber: https://game-check.evogamestore.com/.
- Sumber publik isan.eu.org tidak dipasang sebagai fallback produksi karena repositori yang ditemukan mencantumkan lisensi CC BY-NC-SA 4.0; penggunaan komersial PluQ-I perlu izin yang sesuai. Jangan menambahkannya tanpa pemeriksaan lisensi dan kontrak API yang jelas.
- Status: perubahan dikirim ke branch PR #7; cek GitHub Actions untuk head terbaru sebelum menyatakan tes lulus. Migration 013 harus diterapkan sebelum endpoint baru diuji. Belum ada panggilan API live karena API key belum disetel. Pembayaran, order supplier, dan backup Drive tetap nonaktif.


## Update — 2026-10-20: Audit kandidat validasi nickname tambahan

- Membuat branch terpisah `audit/php-valid-game-provider` dari `fix/vipayment-dedicated-nickname-codes`; PR #7 tidak diubah dan belum digabung.
- Audit statis awal paket `triyatna/php-valid-game` dicatat di `docs/game-username-check-provider-evaluation.md`.
- Paket upstream mencantumkan 22 game, tetapi tidak semua game memiliki fallback: tabel upstream menunjukkan 6 game dengan dua provider, 12 Codashop saja, dan 4 GoPay Games saja. Dukungan harus dipetakan per game, bukan dianggap semua 22 memiliki fallback.
- Ketergantungan paket adalah PHP ^8.1, Guzzle ^7, dan PSR Log. PluQ-I tidak memiliki `composer.json`/`composer.lock` root; integrasi perlu keputusan dependensi/adaptor.
- Transport upstream belum memaksa IPv4, timeout default 15 detik, dan redirect diizinkan. Ini harus ditangani sebelum uji hosting, mengingat kendala IPv6 yang pernah terjadi.
- Belum ada API live request, Composer install, uji lokal, atau perubahan kode customer untuk kandidat ini. Tidak ada pembayaran, pesanan supplier, atau backup Drive yang dijalankan.
- Tahap berikutnya: implementasi adaptor terisolasi dengan IPv4 dan timeout terbatas, unit tests dengan respons tiruan, lalu CI. Jangan mengganti Evo atau menggabungkan branch sebelum hasil tes dan keputusan dependensi jelas.


## Update — 2026-10-20: adaptor fallback nickname GoPay (branch audit)

- Implementasi awal adaptor terisolasi ditambahkan di `app/services/game_username_fallback.php`; tidak memakai Composer/Guzzle dan tidak mengubah pembayaran atau pemesanan supplier. Request hanya ke host tetap `https://gopay.co.id/games/v1/order/user-account`, POST JSON, IPv4 dipaksa, TLS diverifikasi, redirect dimatikan, connect timeout 3 detik dan total timeout 5 detik.
- Adapter memetakan kode lookup GoPay terpisah dari kode produk supplier. Cakupan pemetaan termasuk Mobile Legends, Free Fire, Genshin Impact (region harus dikenali), VALORANT, Honor of Kings, PUBG Mobile, FC Mobile, Magic Chess: Go Go, Arena of Valor, dan Call of Duty Mobile. Ketersediaan aktual tetap mengikuti skema checkout dan respons provider; ini bukan jaminan semua 22 game validasi.
- Primary Evo tetap dipakai lebih dahulu bila kode tersedia dan API key dikonfigurasi. Fallback hanya dicoba pada kegagalan teknis/provider; respons primary yang secara eksplisit menyatakan ID tidak ditemukan tidak diteruskan ke fallback. Respons GoPay yang ambigu diperlakukan sebagai layanan tidak tersedia, bukan ID salah.
- Proteksi database yang sudah ada tetap dipakai; GoPay memiliki budget terpisah maksimal 25 request/menit dan cooldown akun 1 request/5 detik untuk judul GoPay-only. Cache sukses 5 menit tetap di jalur route. Flag `GAME_CHECK_GOPAY_FALLBACK_ENABLED=false` ditambahkan dan **default mati**; metadata tidak mengiklankan cek GoPay-only sebelum flag diaktifkan.
- Tes `tests/game-username-fallback.php` memakai respons tiruan dan ditambahkan ke workflow PHP quality. GitHub Actions run #765 (`39355548027`) sukses: PHP lint dan seluruh tes pada workflow lulus, termasuk adapter fallback. Belum ada API live call atau uji hosting. Sebelum staging: verifikasi izin/ketentuan sumber GoPay, aktifkan flag hanya pada staging, dan periksa respons nyata tanpa membuat order. [Run #765](https://github.com/sulistio95/PluQ-I/actions/runs/39355548027).
- File yang diubah: `app/services/game_username_fallback.php`, `app/services/game_fields.php`, `public/index.php`, `.env.example`, `tests/game-username-fallback.php`, `.github/workflows/php-quality.yml`, serta catatan evaluasi/audit ini.
- Branch kerja tetap `audit/php-valid-game-provider`; PR #7 dan branch utama tidak diubah/di-merge. Pembayaran, supplier ordering, dan backup Google Drive tetap nonaktif.


## Update — 2026-10-20: Cek nickname tidak lagi mewajibkan pendaftaran Evo

- Pada branch `audit/php-valid-game-provider`, metadata `username_check_available` kini hanya aktif jika game dipetakan ke adapter GoPay dan `GAME_CHECK_GOPAY_FALLBACK_ENABLED=true`. Kunci `GAME_CHECK_API_KEY` Evo tidak lagi menjadi prasyarat atau disebut di `.env.example`.
- Fungsi validasi yang dipanggil route telah diubah menjadi GoPay-only; ia tidak mencoba Evo terlebih dahulu. Game tanpa dukungan lookup otomatis tetap dapat memakai input ID manual jika skema akun dan pemetaan checkout game sudah ditinjau. Game yang skema akunnya belum terverifikasi tetap diblokir dari pemilihan nominal demi menghindari salah target.
- Default GoPay tetap `false` karena izin penggunaan endpoint dan respons live belum diverifikasi. Dengan default ini, tes lokal tidak memerlukan API nickname, tidak mengirim request eksternal, dan tidak membuat pesanan. Jika GoPay dinyalakan kelak, lakukan hanya di staging setelah verifikasi ketentuan dan uji respons baca-saja.
- Perubahan mencakup `app/services/game_fields.php`, `app/services/game_username_fallback.php`, `public/index.php`, `tests/game-account-schema.php`, dan `.env.example`. Perbaikan lanjutan juga menghapus referensi variabel error lama pada adapter GoPay.
- Status: kode sudah dikirim ke branch GitHub, tetapi hasil CI setelah perubahan ini dan pengujian lokal pengguna masih menunggu. Pull branch tersebut untuk diuji; jangan merge ke `main` sebelum hasil CI dan uji lokal ditinjau. Pembayaran, supplier ordering, dan backup Google Drive tetap nonaktif.


## Update — 2026-10-20: hasil pemeriksaan lokal setelah handoff

- Pengguna mengonfirmasi branch lokal `audit/php-valid-game-provider`, HEAD `c494ef0` (`Record manual nickname fallback decision in readiness audit`), sinkron dengan `origin/audit/php-valid-game-provider`.
- `git status --short` hanya menunjukkan tiga banner JPG baru yang belum dilacak di `public/uploads/banners/`: `3415d24f42be590c29d176df0f32acf6.jpg`, `f1bdb42a7c23d2c0b084d2bffdee282c.jpg`, dan `fe03270365bd04633c47bc8794da2dd6.jpg`. File tersebut harus dipertahankan; jangan menjalankan `git clean`, reset, atau menghapusnya.
- Pemeriksaan `command -v node`, `nodejs`, `js`, `qjs`, dan `quickjs` tidak menemukan runtime yang tersedia (tidak ada path yang dicetak). Karena itu `node --check public/assets/catalog.js` belum dijalankan dan sintaks JS belum dapat dinyatakan lulus/gagal.
- Status check GitHub untuk `c494ef0` tidak mengembalikan status, dan pencarian workflow PR-triggered untuk short SHA juga tidak menemukan run. Ini bukan bukti tes gagal maupun lulus; hasil CI head terbaru perlu diperiksa dari GitHub Actions secara langsung.
- Langkah berikutnya: pertahankan branch dan banner, jalankan ulang pemeriksaan PHP yang tersedia (`php bin/preflight.php`, tes PHP terkait, dan `git diff --check`), lalu lakukan pemeriksaan JS menggunakan runtime/parser yang memang tersedia atau CI yang berjalan pada head branch. Jangan merge ke `main`; `GAME_CHECK_GOPAY_FALLBACK_ENABLED=false` tetap dipertahankan. Pembayaran, supplier ordering, dan backup Drive tetap nonaktif.


## Update — 2026-10-20: katalog lulus dengan peringatan PHP, perbaikan dibuat

- Hasil lokal pengguna: `php bin/preflight.php` lulus 48/48; tes `game-account-schema`, `game-username-fallback`, dan seluruh 10 kelompok tes regresi yang dijalankan lulus. `tests/catalog-availability.php` lulus 6/6 tetapi menghasilkan tiga warning `Undefined array key "base_price"` dari `app/services/catalog.php` baris 100 karena fixture penawaran tertentu tidak menyertakan field opsional.
- Perbaikan dibuat pada branch `audit/php-valid-game-provider`, commit `4d61d7c9d8eec675bea0e59876237b9716803e1d`: normalisasi `base_price`, `selling_price`, dan `is_available` memakai nilai default aman agar row yang tidak menyertakan key opsional tidak menimbulkan warning. Belum diverifikasi di mesin lokal pengguna; wajib pull branch dan ulangi tes katalog serta `git diff --check`.
- JavaScript `public/assets/catalog.js` masih belum diperiksa karena Node.js/alternatif runtime tidak ditemukan di mesin pengguna. Jangan mengklaim JS lint lulus sampai ada CI atau parser yang dapat menjalankannya.
- Tiga banner lokal tetap tidak terlacak dan harus dipertahankan. Jangan merge ke `main`; pembayaran, supplier ordering, dan backup Google Drive tetap nonaktif.


## Verifikasi lokal katalog tanpa peringatan — 2026-10-20

- Pengguna melakukan `git pull --ff-only origin audit/php-valid-game-provider`; perubahan katalog dan catatan tersinkron dengan fast-forward.
- `git diff --check` bersih; `php -l app/services/catalog.php` tidak menemukan kesalahan sintaks.
- `php -d error_reporting=E_ALL tests/catalog-availability.php` lulus **6/6** tanpa peringatan `Undefined array key "base_price"`. Normalisasi `base_price`, `selling_price`, dan `is_available` kini aman terhadap field opsional yang tidak tersedia.
- Berikutnya: lanjutkan pemeriksaan kualitas lintas tes dan validasi sintaks JavaScript. Mesin lokal pengguna belum memiliki Node.js; jangan menyatakan pemeriksaan JS lokal lulus tanpa alat validasi. Jangan gabungkan branch ini ke `main` dulu. File banner JPG lokal yang tidak dilacak harus dipertahankan; pembayaran, pemesanan supplier, dan backup Drive tetap nonaktif.


## Regresi menyeluruh lokal — 2026-10-20

- Pengguna menarik pembaruan catatan terbaru dari branch `audit/php-valid-game-provider` dengan fast-forward.
- `php bin/preflight.php`: **48/48 checks passed**; koneksi DB, tabel, dan migrasi terdeteksi. Pembayaran, order supplier, dan backup settlement tetap nonaktif.
- Seluruh 12 kelompok tes berikut lulus tanpa output warning yang terlihat: `duitku-callback-result-state.php`, `duitku-transaction-status.php`, `reconcile-cooldown-regression.php`, `checkout-request-fingerprint.php`, `fulfillment-mapping.php`, `provider-budget-capacity.php`, `catalog-availability.php` (6/6), `pricing-hybrid.php`, `admin-authorization.php`, `admin-login-throttle-clear.php`, `game-account-schema.php`, dan `game-username-fallback.php`.
- Batasan: ini tes lokal/preflight, bukan bukti kredensial eksternal atau transaksi sandbox/produksi berfungsi. Validasi sintaks JS dan hasil CI branch terbaru masih perlu dicek. Jangan merge ke `main` atau mengaktifkan pembayaran/order/backup sebelum verifikasi tambahan.


## GitHub Actions setelah regresi lokal — 2026-10-20

- Setelah pengguna melaporkan preflight 48/48 dan seluruh 12 kelompok tes regresi lulus, catatan ini diperbarui.
- GitHub Actions `PHP quality checks` pada commit `e9628ffff1ea12221f63c7629f34e7cefbb960ae` (head branch `audit/php-valid-game-provider`, PR #8) berstatus `completed / success`.
- CI hijau ini mencakup workflow PHP quality yang ada; jangan menyimpulkan bahwa JavaScript atau alur browser end-to-end sudah diverifikasi hanya dari nama/status workflow tersebut. Validasi JS eksplisit dan browser masih perlu dipastikan.
- Tidak ada merge atau perubahan konfigurasi keamanan: checkout pembayaran, order supplier, dan backup Drive tetap nonaktif.


## 2026-10-20 — Tambahkan validasi sintaks JavaScript ke CI

- Pengguna mengonfirmasi `node` dan `nodejs` tidak tersedia di Puppy Bookworm64, dan APT tidak menemukan paket `nodejs` pada indeks yang aktif. Karena itu pemeriksaan JavaScript lokal belum dapat dijalankan.
- Commit workflow `d50d8eddb4ec4c19b2127bdf53a1b1c819531497` pada branch `audit/php-valid-game-provider` menambahkan setup Node.js 22 khusus GitHub Actions dan `node --check public/assets/catalog.js` ke workflow PHP quality. Ini hanya runtime CI; arsitektur hosting PluQ-I tetap PHP + MySQL dan tidak membutuhkan Node.js di shared hosting.
- Hasil workflow setelah commit ini masih harus diperiksa. Jangan menyebut validasi JavaScript lulus sebelum run baru selesai sukses.
- Preflight lokal sebelumnya 48/48 dan 12 kelompok tes regresi PHP lulus. Ini tidak menggantikan pemeriksaan browser, uji API staging, atau uji Duitku sandbox.
- Pertahankan tiga banner JPG lokal yang belum terlacak. Jangan merge ke `main`; pembayaran, supplier ordering, `GAME_CHECK_GOPAY_FALLBACK_ENABLED=false`, dan backup Google Drive tetap nonaktif.


## 2026-10-20 — CI sintaks JavaScript terverifikasi

- GitHub Actions run #788 (`39374900076`) pada head branch `audit/php-valid-game-provider` selesai `success`: https://github.com/sulistio95/PluQ-I/actions/runs/39374900076.
- Workflow yang dijalankan mencakup `node --check public/assets/catalog.js`; dengan demikian pemeriksaan sintaks file JavaScript tersebut lulus di runner GitHub Actions. Node.js tetap hanya dipakai di CI, bukan dibutuhkan pada shared hosting.
- Ini bukan bukti perilaku JavaScript saat runtime di browser. Uji browser, API provider staging, Duitku sandbox, dan deployment hosting masih belum selesai.
- Pembaruan catatan ini dibuat setelah hasil CI sukses. Pembayaran, supplier ordering, fallback GoPay, dan backup Drive tetap nonaktif; jangan merge ke `main` tanpa tahap audit berikutnya.


## 2026-10-20 — Audit endpoint pemeriksaan username dan perbaikan pemilihan provider

- Penelusuran sumber publik tidak menemukan dokumentasi API resmi untuk `https://gopay.co.id/games/v1/order/user-account`; akses endpoint melalui web reader juga tidak memberikan dokumentasi/kontrak API. Jadi endpoint, skema respons, stabilitas, dan izin penggunaan komersial masih belum terverifikasi.
- Ketentuan GoPay resmi yang dipublikasikan menyatakan GoPay dapat membatasi akses dari platform pihak ketiga yang bukan Mitra Platform Resmi, termasuk karena risiko keamanan/pengumpulan data komersial. Ini bukan penetapan hukum bahwa endpoint game tersebut dilarang, tetapi tidak memberi otorisasi untuk memakai endpoint tak terdokumentasi. Rujukan: https://gopay.co.id/syarat-dan-ketentuan dan https://gopay.co.id/terms-and-condition/gopay.
- Audit kode menemukan route POST username sebelumnya selalu memanggil adapter GoPay, bahkan saat `GAME_CHECK_GOPAY_FALLBACK_ENABLED=false`; ini membuat flag disabled tidak benar-benar mencegah pemanggilan adapter dan mengabaikan dedicated checker walaupun `GAME_CHECK_API_KEY` tersedia.
- Perbaikan pada branch ini: `public/index.php` sekarang memilih dedicated checker jika kode game didukung dan `GAME_CHECK_API_KEY` terpasang; GoPay fallback hanya dipanggil saat flag fallback diaktifkan eksplisit; tanpa provider yang dikonfigurasi, request gagal aman. Fallback juga dipanggil dengan kode layanan kosong agar cooldown per-akun yang tersedia di adapter diterapkan. `app/services/game_fields.php` hanya menandai username check tersedia jika provider terkait benar-benar dikonfigurasi. `.env.example` menambahkan `GAME_CHECK_API_KEY` dan memperjelas bahwa fallback GoPay harus tetap `false` sampai ada konfirmasi izin.
- Perubahan kode/env: `public/index.php` commit `23508598100b75080f70d61a94a93c097671e525`; `app/services/game_fields.php` commit `e0ca6bf234cecda1fe3f4653baf67ef994c0278b`; `.env.example` commit `4bb1c72bce77959be62f7e01e0f853041dbe9e2d`. Tes regresi/CI setelah perubahan ini masih harus diverifikasi sebelum dianggap lulus.
- Pengguna dapat menguji lokal setelah pull, tetapi pengujian ID nyata membutuhkan `GAME_CHECK_API_KEY` yang sah untuk dedicated API, atau konfirmasi izin GoPay sebelum mengaktifkan fallback. Kredensial VIPayment saja tidak otomatis menjadi kredensial `GAME_CHECK_API_KEY`. Jangan masukkan API key ke GitHub/percakapan; isi hanya di `.env` lokal.
- Payment checkout, supplier orders, GoPay fallback, dan Google Drive backup tetap disabled. Jangan merge ke `main`; pertahankan tiga banner JPG lokal yang belum terlacak.


## 2026-10-20 — CI pascaperbaikan username checker

- GitHub Actions `PHP quality checks` lulus untuk commit routing `23508598100b75080f70d61a94a93c097671e525` (run #791), perubahan skema `e0ca6bf234cecda1fe3f4653baf67ef994c0278b` (run #792), dan `.env.example` `4bb1c72bce77959be62f7e01e0f853041dbe9e2d` (run #793).
- Ini membuktikan pemeriksaan otomatis dalam workflow lulus, bukan bahwa API username eksternal bekerja dengan ID nyata. Pengujian live masih menunggu konfigurasi provider resmi/berizin.
- Setelah pengguna pull, jalankan preflight dan tes `game-account-schema.php`, `game-username-fallback.php`, `git diff --check`, lalu uji endpoint lokal. Dengan `GAME_CHECK_API_KEY` kosong dan fallback false, hasil aman yang diharapkan adalah pengecekan username tidak tersedia (bukan request ke GoPay). Jangan set fallback true sebelum otorisasi endpoint dikonfirmasi.


## 2026-10-20 — Kembali ke VIPayment Get Nickname

- Sesuai keputusan pengguna, jalur GoPay dan checker pihak ketiga dihapus dari alur customer. Pemeriksaan username kembali memakai VIPayment Game Feature type=get-nickname dengan kredensial API ID/API key VIPayment yang sudah dikonfigurasi di panel admin.
- Pemetaan kode lookup mengikuti daftar VIPayment: mobile-legends, mobile-legends-region, free-fire, pubgm, valorant, genshin-impact, honkai-star-rail, pointblank. Kode lookup bukan kode produk top-up.
- Route hanya mengirim type, code, target, additional_target ke endpoint /game-feature. Tidak ada order supplier yang dibuat oleh lookup username.
- Flag dan kredensial GAME_CHECK_API_KEY serta GAME_CHECK_GOPAY_FALLBACK_ENABLED dihapus dari .env.example; adaptor GoPay dihapus dan tes diganti dengan tes pemetaan parameter VIPayment.
- Metadata form hanya mengiklankan pengecekan otomatis jika game masuk daftar kode yang didukung dan kredensial VIPayment tersedia. Game di luar daftar tetap dapat memakai alur manual sesuai aturan schema yang ada.
- CI dan tes lokal setelah perubahan ini harus dijalankan sebelum menyatakan selesai. Belum ada klaim bahwa panggilan nickname live berhasil. Pembayaran, supplier ordering, dan backup Drive tetap nonaktif.


### Koreksi lanjutan — 2026-10-20
- Uji lokal menemukan deklarasi ganda `game_username_check()` di `app/services/game_username_check.php` (implementasi VIPayment di baris awal dan implementasi Game Check lama di bagian bawah), yang menyebabkan HTTP 500 sebelum request keluar.
- Menghapus implementasi Game Check lama dan mempertahankan satu implementasi VIPayment. Rate limit dan cache MySQL tetap dipertahankan.
- Ulangi `php -l app/services/game_username_check.php`, `php tests/game-username-vipayment.php`, `php tests/game-account-schema.php`, serta uji endpoint lokal. Uji API live masih harus dikonfirmasi terpisah.


## 2026-10-20 — Database lokal belum menerapkan migration proteksi nickname
- Pengguna melaporkan HTTP 503 pada `POST /api/games/free-fire/check-username`: `SQLSTATE[42S02]` karena `pluqi_local_test.game_username_check_limits` tidak ditemukan.
- Penyebabnya adalah database lokal belum memiliki tabel yang didefinisikan oleh `database/migrations/013_game_username_check_protection.sql`; migration ini membuat `game_username_check_limits` dan `game_username_check_cache`.
- Memperbarui `bin/preflight.php` untuk mewajibkan dua tabel tersebut dan memeriksa migration `013_game_username_check_protection`. Commit perbaikan preflight: `934d2d3a78f8ae06a90e53667ee1509a27463fdf` pada branch `fix/vipayment-nickname-lookup`.
- Langkah berikutnya: tarik branch, terapkan migration 013 pada database `pluqi_local_test`, jalankan `php bin/preflight.php` dan `php tests/game-username-vipayment.php`, lalu coba lagi endpoint. Jangan aktifkan pembayaran atau supplier order. API nickname live belum dinyatakan berhasil.


## 2026-10-20 — Navigasi panel admin dipindahkan ke sidebar kiri

- Menambahkan komponen bersama `app/views/admin_sidebar.php` dan stylesheet `public/assets/admin-sidebar.css` agar menu admin konsisten pada Dashboard/Transaksi, Pengaturan API, Game List, Pengaturan Template, Pengaturan Keuntungan, Harga Modal Supplier, dan Rekonsiliasi Duitku.
- Sidebar berada di sisi kiri pada layar desktop. Pada layar sempit, menu berubah menjadi bar navigasi horizontal yang dapat digulir agar tetap dapat digunakan di ponsel tanpa JavaScript tambahan.
- Menu aktif diberi penanda `aria-current="page"`; tautan Rekonsiliasi Duitku hanya ditampilkan jika akun lolos `admin_can_manage_settings()`. Kredensial, alur otorisasi, dan endpoint transaksi tidak diubah oleh pekerjaan tata letak ini.
- Link menu atas yang duplikat di dashboard dan link “kembali ke panel” pada halaman pengaturan dihapus karena navigasi bersama sekarang tersedia melalui sidebar.
- Perubahan ada pada branch `feature/admin-left-sidebar`, diturunkan dari `fix/vipayment-nickname-lookup`. Belum digabung ke `main`. Lakukan PHP lint, `php bin/preflight.php`, tes regresi terkait admin, dan `git diff --check` setelah branch ditarik. Pemeriksaan visual browser masih diperlukan.
- Tidak ada perubahan atau aktivasi pembayaran, supplier ordering, maupun backup Google Drive. Pertahankan tiga banner JPG lokal yang belum terlacak; jangan menjalankan perintah reset/clean yang menghapus file lokal.


## Update — 2026-10-20: Penggeser horizontal tabel Harga Modal Supplier

- Menambahkan scrollbar horizontal kedua di bagian atas tabel Harga Modal Supplier (`public/admin/supplier-prices.php`), dengan posisi sticky agar tetap mudah dijangkau saat halaman digulir vertikal.
- Scrollbar atas disinkronkan dua arah dengan tabel utama; pengguna dapat menggeser kolom ke kiri/kanan tanpa harus turun sampai bagian bawah tabel. Tabel mempertahankan scrollbar bawaan sebagai alternatif.
- Lebar penggeser menyesuaikan lebar tabel saat ukuran layar berubah. Tidak mengubah query harga, filter, urutan harga, otorisasi, atau proses transaksi.
- Perubahan dikirim ke branch `feature/admin-left-sidebar`; belum di-merge ke `main`. Perlu lint PHP, pemeriksaan CI, dan uji visual desktop/mobile sebelum dinyatakan terverifikasi. Pembayaran, supplier ordering, dan backup Google Drive tetap nonaktif.


## Verifikasi CI — penggeser Harga Modal Supplier (2026-10-20)

- GitHub Actions `PHP quality checks` pada commit `45d9016a8a7216ff12467c03fafc740ec06a38ec` selesai dengan status **success**.
- Hasil CI tidak menggantikan uji visual browser. Perlu pengguna memeriksa penggeser horizontal atas pada halaman Harga Modal Supplier, termasuk saat halaman digulir vertikal dan pada layar sempit.


## Update — 2026-10-20: Perbaikan teks intro yang bertumpuk dengan banner homepage

- Memisahkan area banner promosi dari teks “Top up game lebih praktis” dan “Pilih game dan temukan nominal yang tersedia.”; teks kini berada tepat di bawah banner, bukan di atas gambar.
- Area banner menggunakan rasio 16:5, tinggi minimum untuk layar kecil dan batas tinggi maksimum agar gambar tidak menutupi konten. Banner tetap dapat diklik jika konfigurasi memiliki target URL; pergantian banner 3 detik tidak diubah.
- Perubahan pada `public/index.php` dan `public/assets/catalog.css`, branch `feature/admin-left-sidebar`. Catatan handoff dan audit diperbarui. Perlu lint/CI serta pemeriksaan visual desktop dan ponsel; belum merge ke `main`.


## Update — 2026-10-20: Banner saja pada form akun dan pemilihan nominal

- Pada tampilan setelah pelanggan memilih game, banner promosi tetap berada di bagian atas. Kolom pencarian game dan teks intro homepage disembunyikan selama form data akun dan nominal ditampilkan.
- Saat pelanggan menekan **Kembali ke game**, teks intro dan pencarian ditampilkan kembali. Tidak mengubah alur pemuatan form, produk, pergantian banner otomatis 3 detik, atau logika harga.
- Implementasi pada `public/index.php` dan `public/assets/catalog.js` di branch `feature/admin-left-sidebar`; commit kode: `45d4a5b02042acab0210de60e420e1fd7c1a0ee2` dan `adf7e95db84d3e9af154f23888444a113aa27ee8`.
- Belum menjalankan lint/CI atau pemeriksaan visual browser untuk perubahan ini. Setelah pull, uji memilih game, pastikan hanya banner yang tersisa di bagian atas form akun/nominal, lalu tekan kembali dan pastikan pencarian serta intro muncul lagi. Belum merge ke `main`; pembayaran, supplier ordering, dan backup Google Drive tetap nonaktif.


## Update — 2026-10-20: Branding footer toko

- Mengganti teks footer halaman storefront dari `PluQ · Katalog shared-hosting` menjadi `PluQ.I Official Store` di `public/index.php`.
- Commit kode: `8588cd6654ed510c3d58aa55132812224a360f28` pada branch `feature/admin-left-sidebar`.
- Perubahan hanya teks footer; alur katalog, akun, nominal, banner, dan pembayaran tidak diubah. Belum merge ke `main`; hasil CI dan tampilan browser perlu diverifikasi.


## Update — 2026-10-20: Pratinjau katalog dapat memilih supplier

- Panel **Pengaturan API → Pratinjau daftar game dan layanan** kini menyediakan pilihan VIPayment atau Digiflazz. VIPayment menggunakan `vipayment_game_services()`; Digiflazz menggunakan `digiflazz_price_list()` dan menampilkan field produk yang dikembalikan API, termasuk status buyer/seller bila tersedia.
- Pratinjau tetap baca-saja: tidak menulis katalog database, tidak mengubah pemetaan, dan tidak membuat pesanan. Untuk menyimpan harga ke katalog toko, gunakan tombol **Sinkronkan katalog VIPayment/Digiflazz** setelah mapping aktif di Game List disiapkan.
- API custom belum bisa dipratinjau secara generik. Profil custom saat ini hanya menyimpan base URL dan kredensial; endpoint katalog, autentikasi spesifik, dan bentuk respons memerlukan adapter terpisah dari dokumentasi resmi provider. UI menjelaskan batasan ini dan tidak mengirim request spekulatif ke endpoint custom.
- Commit kode: `6415ccc59e62060abffcab86863ad41e6b40d955` pada branch `feature/admin-left-sidebar`; belum merge ke `main`.
- CI, lint, dan tes browser belum diverifikasi untuk commit ini. Setelah pull, jalankan `php -l public/admin/api-settings.php`, `php bin/preflight.php`, tes regresi terkait, dan `git diff --check`; uji pratinjau kedua supplier dengan kredensial valid. Jangan mengaktifkan pembayaran, supplier ordering, atau backup Google Drive.


## Verifikasi CI — pratinjau katalog supplier (2026-10-20)

- GitHub Actions **PHP quality checks** untuk commit `6415ccc59e62060abffcab86863ad41e6b40d955` selesai dengan status **success**, run #819: https://github.com/sulistio95/PluQ-I/actions/runs/39391595762.
- Ini hanya hasil workflow yang dikonfigurasi; belum membuktikan request API live untuk kedua supplier atau perilaku browser. Uji lokal dengan kredensial valid dan pemeriksaan tampilan masih diperlukan.
- Dukungan API custom tetap memerlukan adapter katalog berdasarkan dokumentasi provider; tidak ada endpoint custom yang dipanggil. Pembayaran, supplier ordering, dan backup Drive tetap nonaktif.
