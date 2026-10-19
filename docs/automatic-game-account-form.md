# PluQ-I — Form akun otomatis

## Perilaku

- Formulir akun dibuat dari game aktif yang telah disinkronkan ke katalog lokal. Panel admin tidak lagi menyediakan editor kolom akun per game.
- Semua game menampilkan **ID Game / User ID**.
- Mobile Legends menampilkan **Zone ID**. Bila nama game secara eksplisit menandakan server/region dan tidak cocok dengan aturan Zone ID, formulir menampilkan **Server**.
- Setelah kolom wajib terisi, browser menjalankan pemeriksaan username otomatis bila ada produk VIPAY aktif yang menyediakan kode layanan. Hasil nama akun hanya ditampilkan dari respons VIPayment yang sukses.
- Bila API checker tidak tersedia, UI menyatakan hal itu secara eksplisit; username tidak dibuat-buat. Kesalahan pengecekan yang bukan kondisi unsupported menghentikan validasi akun untuk mencegah pemilihan produk dengan ID yang belum terverifikasi.

## Batasan data supplier

VIPayment `type=services` mengembalikan kode layanan, nama game, nama produk, harga, server layanan, dan status layanan, tetapi tidak mengembalikan skema formal kolom input akun per game. Karena itu, implementasi saat ini memakai aturan nama game konservatif dan fallback User ID untuk game yang tidak dikenali. Ini menghilangkan konfigurasi manual, tetapi belum menjamin semua game memiliki skema sempurna. Hanya daftar game yang dipetakan dan ditinjau dalam kode yang dapat melanjutkan pemilihan produk; game lain tetap menampilkan User ID tetapi tombol produk dinonaktifkan dan endpoint kutipan checkout menolak transaksi sampai pemetaan diperiksa. Perlu verifikasi katalog aktual dan dokumentasi game sebelum checkout produksi.

Pengecekan nickname VIPayment menggunakan `type=get-nickname`, `code`, `target` (User ID), dan `additional_target` (Zone/Server). Endpoint tidak mengirim permintaan order. Pembatasan saat ini bersifat per sesi; sebelum penggunaan publik, pertimbangkan rate limit lintas sesi/IP untuk mencegah penyalahgunaan API.

## File yang diubah

- `app/services/game_fields.php`: generator field, schema, dan deteksi dukungan VIPAY.
- `public/index.php`: metadata field dan endpoint username checker.
- `public/assets/catalog.js`: input otomatis dan pengecekan username setelah data wajib terisi.
- `app/services/checkout_quote.php`: validasi kutipan checkout terhadap schema otomatis.
- `public/admin/index.php`: menghapus tautan konfigurasi manual.
- `public/admin/game-fields.php`: dihapus; tabel lama tidak dihapus.
- `tests/game-account-schema.php`: tes generator field.

## Status pengujian

Branch `feature/automatic-game-account-form` belum digabung ke `main`. Periksa GitHub Actions setelah PR dibuat. Tetap nonaktifkan pembayaran, pemesanan supplier, dan backup Drive sampai tes PHP, browser, database, dan username lookup di staging berhasil.
