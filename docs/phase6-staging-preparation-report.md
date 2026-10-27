# Fase 6 — Review akhir dan persiapan staging

Tanggal: 2026-10-27  
Branch: `integration/phase4-combined-2026-10`  
Head yang direview sebelum dokumentasi Fase 6: `5ce9be502c0abd78d84b02dc61fef94a46d73448`  
PR validasi: [#24 — Combined integration candidate](https://github.com/sulistio95/PluQ-I/pull/24)

## Yang diselesaikan

1. Meninjau kembali PR #24: masih open, draft, belum merged, dan base `main` tetap pada baseline yang dicatat di PR.
2. Memastikan hasil CI pada head `5ce9be5`:
   - [Fulfillment safety tests](https://github.com/sulistio95/PluQ-I/actions/runs/40467838096) — SUCCESS
   - [PHP quality checks](https://github.com/sulistio95/PluQ-I/actions/runs/40467838058) — SUCCESS
   - [PHP and JavaScript syntax checks](https://github.com/sulistio95/PluQ-I/actions/runs/40467838014) — SUCCESS
3. Memperbaiki panduan staging yang sebelumnya masih menyebut migrasi hanya sampai 011, dan yang mengharapkan order menjadi `QUEUED` ketika pemesanan supplier dinonaktifkan.
4. Menegaskan prosedur callback sandbox yang aman: pembayaran dapat tercatat `PAID`, order diarahkan ke `MANUAL_REVIEW`, dan tidak boleh ada job `FULFILL_ORDER` saat `SUPPLIER_ORDERS_ENABLED=false`.
5. Mencatat perhatian konfigurasi: `.env.example` mengarahkan `GITHUB_UPLOAD_BRANCH` ke `feature/admin-left-sidebar`; di staging, token upload repo harus dinonaktifkan atau branch harus ditetapkan eksplisit agar unggahan staging tidak mengubah cabang pengembangan yang tidak dimaksud.
6. Memperjelas prasyarat hosting: document root ke `public/`, konfigurasi rahasia di luar web root, database staging terpisah, `APP_ENV=staging` untuk jalur sandbox terbatas, debug nonaktif, dan fitur backup Drive tetap mati.

## Hasil dan batasan

Fase 6 selesai sebagai **review repository dan persiapan prosedur staging**, bukan sebagai deployment atau persetujuan go-live.

Belum dilakukan karena memerlukan hosting/konfigurasi dan pengujian eksternal:
- deployment ke shared hosting atau pengujian document root/cron pada host sebenarnya;
- penerapan migrasi ke database staging jarak jauh;
- pengujian UI melalui browser pada host;
- transaksi Duitku sandbox eksternal dan callback yang benar-benar berasal dari gateway;
- pemeriksaan kredensial/read-only VIPayment dan Digiflazz;
- uji backup/restore di hosting.

Tidak ada transaksi pembayaran, pemesanan supplier, backup Google Drive, pemulihan queue manual, atau deployment yang dijalankan dalam fase ini.

## Gate yang tetap wajib sebelum staging menerima pengujian sandbox

- [ ] Pastikan hosting menyediakan PHP CLI 8.2+, ekstensi yang diperlukan, MariaDB/MySQL dengan InnoDB, HTTPS, dan cron yang sesuai.
- [ ] Buat database dan pengguna staging yang terpisah; jangan menyalin data pelanggan/rahasia produksi.
- [ ] Simpan `.env` di luar document root; gunakan `APP_ENV=staging`, `APP_DEBUG=false`, `DUITKU_ENV=sandbox`; buat `APP_KEY` dan `SCHEDULER_TOKEN` unik.
- [ ] Pertahankan `SUPPLIER_ORDERS_ENABLED=false` dan `SETTLEMENT_BACKUP_ENABLED=false`; jangan aktifkan pembayaran sampai sesi sandbox terkontrol disetujui secara terpisah.
- [ ] Terapkan schema dan migrasi wajib 002–005 serta 008–014 berurutan. Migrasi 006–007 hanya diperlukan sebelum backup settlement diaktifkan.
- [ ] Jalankan `php bin/preflight.php` melalui CLI dan selesaikan seluruh kegagalan tanpa membagikan output yang mengandung rahasia.
- [ ] Pastikan unggahan aset tidak menulis ke cabang GitHub yang salah.
- [ ] Uji halaman dan respons HTTP di staging, lalu jalankan siklus Duitku sandbox terkontrol dengan supplier tetap nonaktif. Verifikasi callback valid, signature salah, nominal salah, callback duplikat/terlambat, dan tidak adanya job fulfillment.
- [ ] Buktikan backup/restore ke database disposable sebelum menyimpan data order sungguhan.

## Keputusan

PR #24 tetap draft dan belum digabung ke `main`. Jangan mengaktifkan mode pembayaran produksi: kode Duitku saat ini memang menolak `DUITKU_ENV=production`. Supplier order dan backup Drive tetap nonaktif sampai gate terpisah selesai dan mendapat persetujuan eksplisit.
