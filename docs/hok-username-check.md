# HOK nickname check — 2026-10-20

- Pengguna mengonfirmasi bahwa pencarian/pengecekan username Honor of Kings (HOK) juga tidak tersedia langsung di aplikasi VIPayment.
- Commit kode: `163ea73b39f0c54564faf898d2df3bc14b07586e`.
- `app/services/game_fields.php` kini menandai pemeriksaan nickname sebagai tidak tersedia untuk HOK, meskipun terdapat produk VIPAY aktif. Ini menghindari asumsi keliru bahwa setiap kode produk top-up mendukung `get-nickname`.
- Dengan metadata tersebut, frontend tidak perlu memanggil endpoint cek nickname untuk HOK; kolom User ID dan validasi akun biasa tetap digunakan. Hasil cek username dibiarkan kosong dan tidak seharusnya menghalangi pemilihan nominal.
- Ini tidak membuktikan bahwa top-up HOK berhasil atau bahwa ID yang dimasukkan benar. Pemrosesan transaksi tetap memerlukan validasi server dan respons supplier saat fulfillment diaktifkan.
- Status pengujian: perubahan telah dikirim ke GitHub, tetapi PHP lint, JavaScript syntax, browser, database lokal, dan API VIPayment langsung belum diuji setelah commit ini.
- Verifikasi lokal setelah pull: `php -l app/services/game_fields.php`, `php -l public/index.php`, `node --check public/assets/catalog.js`; lalu buka HOK dan pastikan tidak ada request cek nickname otomatis serta kolom User ID tetap tampil.
- Jangan aktifkan checkout/pembayaran, pesanan supplier, atau backup Google Drive untuk pengujian ini.
