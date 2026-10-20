# Evaluasi sumber validasi nickname: triyatna/php-valid-game

Tanggal: 2026-10-20  
Status: audit statis + adaptor GoPay terbatas pada branch `audit/php-valid-game-provider`; **belum ada request live ke GoPay/Codashop dan flag fallback default mati**.

## Ringkasan

Repo upstream: https://github.com/triyatna/php-valid-game  
Lisensi kode: MIT. Paket menyatakan PHP `^8.1`, dan membutuhkan `guzzlehttp/guzzle ^7.0` serta `psr/log ^2.0|^3.0`. PluQ-I saat ini tidak memiliki `composer.json` atau `composer.lock` di root, sehingga integrasi bukan sekadar menyalin satu file: dependensi harus dipasang dan dipelihara, atau adaptor minimal yang diaudit perlu dibuat.

Paket menyediakan 22 entri game dan dua sumber: endpoint inisiasi pembayaran Codashop serta endpoint user-account GoPay Games. Daftar game tidak berarti semua judul punya dua sumber. Berdasarkan tabel README upstream:
- **Keduanya tercantum:** Arena of Valor, Call of Duty Mobile, Free Fire, Genshin Impact, Mobile Legends, VALORANT.
- **Codashop saja:** 8 Ball Pool, Aether Gazer, Auto Chess, Azur Lane, Badlanders, BarbarQ, Basketrio, Dragon City, Hago, Honkai: Star Rail, Point Blank, Zenless Zone Zero.
- **GoPay Games saja:** FC Mobile, Honor of Kings, Magic Chess: Go Go, PUBG Mobile.

Maka daftar 22 game meningkatkan cakupan, tetapi fallback tidak tersedia untuk judul yang hanya memiliki satu provider. Dukungan upstream perlu diuji per game dan per wilayah sebelum ditampilkan sebagai “terverifikasi”.

## Temuan teknis

1. **IPv4 belum dipaksa.** Transport upstream memakai Guzzle dengan konfigurasi timeout dan redirect, tetapi tidak menetapkan `CURLOPT_IPRESOLVE = CURL_IPRESOLVE_V4`. Karena PluQ-I pernah mengalami masalah koneksi IPv6, transport perlu adaptor/configuration khusus dan pengujian pada hosting target.
2. **Timeout bawaan 15 detik.** Ini berpotensi membuat form customer terasa menggantung; tentukan batas lebih rendah yang realistis dan tangani timeout sebagai “layanan validasi sementara tidak tersedia”, bukan sebagai ID salah.
3. **Redirect diizinkan.** Untuk request provider eksternal, audit redirect/host tujuan sebelum produksi; jangan meneruskan kredensial ke host yang tidak diharapkan.
4. **Fallback mengonsumsi beberapa request.** Satu pengecekan bisa mencoba dua provider. Limit global 50 request/menit yang ada di integrasi Evo tidak boleh langsung dipakai sebagai limit request ke paket ini; hitung jumlah request provider yang sesungguhnya dan bedakan error transport dari ID invalid.
5. **Ketergantungan pada layanan pihak ketiga.** Codashop provider menggunakan endpoint `order-sg.codashop.com/initPayment.action`; GoPay provider menggunakan `gopay.co.id/games/v1/order/user-account`. Endpoint/format respons bisa berubah atau menolak traffic. Lisensi MIT hanya melisensikan kode paket, bukan memberi jaminan SLA atau izin layanan sumber.
6. **Privasi/logging.** Produksi harus menonaktifkan debug/raw payload, tidak menyimpan ID akun mentah dalam log, dan hanya menyimpan hasil sukses singkat dalam cache yang sudah ada.
7. **Pemisahan dari supplier.** Pemeriksaan nickname tetap harus berdiri sendiri dan tidak boleh membuat order, memanggil endpoint top-up, atau mengaktifkan pembayaran.

## Keputusan sementara

Jangan mengganti Evo atau mengaktifkan paket upstream langsung pada PR #7. Lanjutkan sebagai kandidat terisolasi: siapkan adaptor dengan IPv4, timeout terbatas, host/redirect terkendali, pemetaan eksplisit dari slug dan field akun PluQ-I ke kode upstream, serta hasil status yang membedakan valid, invalid, dan provider unavailable. Pertahankan cache dan rate limit DB, lalu buat unit tests menggunakan respons tiruan. Uji provider live hanya setelah konfigurasi dan batas penggunaan dipastikan; tidak ada request live yang dilakukan pada audit ini.

## Referensi upstream yang diperiksa

- README dan matriks game: https://github.com/triyatna/php-valid-game
- `composer.json`: https://github.com/triyatna/php-valid-game/blob/main/composer.json
- Transport Guzzle: https://github.com/triyatna/php-valid-game/blob/main/src/Transport/HttpClient.php
- Registry game: https://github.com/triyatna/php-valid-game/blob/main/src/Registry/GameRegistry.php
- Codashop provider: https://github.com/triyatna/php-valid-game/blob/main/src/Providers/CodashopProvider.php
- GoPay Games provider: https://github.com/triyatna/php-valid-game/blob/main/src/Providers/GopayGamesProvider.php
- Lisensi: https://github.com/triyatna/php-valid-game/blob/main/LICENSE


## Implementasi adaptor lanjutan (2026-10-20)

- Dibuat `app/services/game_username_fallback.php` sebagai adaptor cURL terisolasi untuk GoPay Games saja; tidak memasang Composer/Guzzle dan tidak menyalin seluruh package. Endpoint tetap pada host/path GoPay yang diaudit, TLS verification aktif, IPv4 dipaksa, redirect dimatikan, connect timeout 3 detik dan total timeout 5 detik.
- Primary Evo tidak diganti. Fallback hanya dijalankan saat kegagalan teknis/provider, bukan ketika primary menyatakan akun tidak ditemukan. Respons GoPay yang tidak jelas tidak dianggap sebagai ID invalid. Pemetaan Genshin menerima region yang dikenal saja; region tak dikenal tidak ditebak.
- Flag `GAME_CHECK_GOPAY_FALLBACK_ENABLED=false` menjaga fallback tetap mati sampai pengujian staging dan peninjauan ketentuan sumber selesai. Batas GoPay terpisah: maksimum 25 request/menit dan cooldown per akun 1 request/5 detik untuk judul GoPay-only. Cache sukses lima menit dari route yang sudah ada tetap dipakai.
- Tes mock baru: `tests/game-username-fallback.php`, dimasukkan ke workflow `.github/workflows/php-quality.yml`. GitHub Actions run #765 sukses untuk PHP lint dan seluruh tes workflow. Belum ada panggilan provider live. [Run #765](https://github.com/sulistio95/PluQ-I/actions/runs/39355548027).
- **Batasan:** adaptor ini belum mengimplementasikan provider Codashop dan bukan integrasi lengkap 22 game dari package upstream. Tidak ada composer dependency baru. Jangan mengaktifkan flag di produksi sebelum sumber/ketentuan, hasil staging, dan respons provider diverifikasi.
