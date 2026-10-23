# Rating pelanggan dan kualitas supplier PluQ-I

## Status implementasi

Pekerjaan awal tersedia pada branch `feature/customer-supplier-rating`. Ini belum berarti siap produksi: migration, PHP lint, tes lokal/CI, database MySQL, dan browser masih harus diverifikasi.

## Rating pelanggan

- Halaman status pesanan menampilkan form rating hanya bila pembayaran berstatus `PAID` dan status pesanan sudah final: `SUCCESS`, `FAILED`, atau `REFUNDED`.
- Pelanggan memilih 1–5 bintang, alasan, dan komentar opsional maksimal 1000 byte.
- Satu rating per pesanan ditegakkan oleh unique key `order_ratings.order_id`; percobaan ganda ditolak.
- Validasi input dipusatkan di `app/services/order_rating.php` dan diuji oleh `tests/order-rating-rules.php`.
- Pesanan yang masih menunggu, berjalan, atau pemeriksaan manual tidak dapat dinilai.
- Akses formulir bergantung pada ID pesanan acak 26 karakter yang juga digunakan halaman status. Jangan menambahkan ID berurutan atau data pribadi ke URL.

## Kualitas supplier

Panel admin `/admin/supplier-quality.php` menampilkan:
- jumlah pesanan berbayar sukses;
- jumlah pesanan berbayar gagal/refund;
- tingkat keberhasilan untuk pesanan berbayar yang sudah final;
- rata-rata waktu dari pembuatan pesanan sampai pemenuhan untuk pesanan sukses;
- rata-rata rating pelanggan dan jumlah sampelnya;
- rekap alasan rating.

**Pemisahan wajib:** rating pelanggan adalah umpan balik pengalaman pelanggan, bukan bukti langsung supplier yang bersalah. Kesalahan ID, pemetaan region/server, masalah gateway, dan masalah pelanggan harus diklasifikasikan sebelum statistik digunakan untuk menilai supplier. Data rating saat ini hanya dikaitkan dengan supplier yang tercatat pada pesanan; itu tidak sama dengan rating Digiflazz.

## Database dan pengujian

- Jalankan migration `database/migrations/013_order_ratings_and_supplier_quality.sql` setelah migration `012_pricing_rules_hybrid.sql`.
- CI quality workflow menjalankan `php tests/order-rating-rules.php`.
- Workflow MySQL mengimpor migration 013 setelah migration 012.
- Setelah pull, jalankan:
  ```sh
  php -l app/services/order_rating.php
  php -l public/order-status.php
  php -l public/admin/supplier-quality.php
  php tests/order-rating-rules.php
  git diff --check
  ```
- Uji integrasi MySQL harus memastikan insert rating, penolakan rating duplikat, dan query panel admin setelah seluruh migration diterapkan.
- Uji browser untuk pesanan sukses/gagal dan status yang belum final masih diperlukan.
- Jangan mengubah algoritme pemilihan supplier berdasarkan statistik ini sampai data nyata memadai, alasan kegagalan terklasifikasi, dan perubahan diuji.
- Pembayaran live, supplier fulfillment, dan backup Google Drive tetap nonaktif; fitur rating tidak mengubah toggle tersebut.
