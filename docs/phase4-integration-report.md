# Phase 4 — Combined Integration Report

Tanggal: 2026-10-27

## Status

Integrasi fitur secara selektif dibuat pada branch `integration/phase4-combined-2026-10`, yang berawal tepat dari official `main` commit `52341bf38f1a3434dbe0f6a4ec817cd7a5c7f70f`. Branch `main` tetap tidak berubah. PR #24 adalah draft validasi saja: https://github.com/sulistio95/PluQ-I/pull/24.

## Fitur yang digabungkan

- Pagination harga supplier dengan pilihan 25/50/100/200 baris, filter dan urutan yang tetap tersimpan ketika berpindah halaman, serta layout sidebar.
- Filter harga supplier tetap mewajibkan mapping supplier aktif dan game aktif; katalog mempertahankan perilaku menampilkan nominal tidak tersedia sebagai Habis, bukan menyembunyikannya.
- Aset banner/logo/icon dan pengaturan layout katalog, termasuk pemuatan game dan banner secara independen.
- Sidebar admin, halaman pencarian/detail transaksi, pengaturan API/Game List/template/keuntungan, serta schema field akun otomatis.
- Pengecekan nickname VIPayment yang didukung dan proteksi cache/rate limit melalui migration 013.
- Rating pesanan pelanggan dan dashboard kualitas supplier melalui migration 014.
- Callback Duitku terlambat: jika transaksi berstatus FAILED menerima callback sukses yang valid, transaksi dan order dicatat sebagai PAID, order tetap MANUAL_REVIEW, konflik dicatat dalam audit log, dan fulfillment tidak dijalankan otomatis.

## Migration dan provider budget

Urutan migration: 011 provider-budget reservations, 012 hybrid pricing, 013 nickname-check protection, 014 order ratings/supplier quality. Urutan ini dijalankan dalam job MySQL integration CI.

Implementasi provider budget dari `main` dipertahankan (`provider_budget_reservations`, migration 011, `provider_budget.php`). Branch alternatif `fix/provider-balance-reservations` memakai migration 009, tabel `provider_balance_reservations`, dan status HOLD/COMMITTED/CONSUMED; skema itu tidak diimpor secara utuh karena bertentangan dengan implementasi yang sekarang. Implementasi utama melakukan penguncian baris provider dan pemeriksaan kapasitas sebelum antrean supplier. Jika kapasitas berubah setelah pembayaran, order diarahkan ke MANUAL_REVIEW alih-alih dikirim tanpa cadangan. Ini aman terhadap overspend tetapi dapat membutuhkan rekonsiliasi manual.

## Validasi

- PHP quality: lint PHP, pemeriksaan sintaks JavaScript, tes mapping field akun, parameter nickname, kapasitas provider, filter harga supplier, rating rules dan CSRF.
- Fulfillment safety: tes integrasi MySQL untuk migrasi, idempotensi checkout, deadlock/concurrency, callback ganda, antrean supplier, dan sandbox yang tidak boleh memicu fulfillment.
- Tes late-success ditambahkan pada callback dan script integrasi MySQL. Status CI akhir harus dilihat pada PR #24 karena perubahan tes memicu workflow ulang.

## Batasan

Tidak ada pembayaran, pemesanan supplier, atau backup Google Drive yang diaktifkan. Belum ada pemanggilan live Duitku/VIPayment/Digiflazz, migrasi pada database hosting, uji browser visual, atau uji ponsel. PR #24 tetap draft dan tidak boleh di-merge ke `main` sebelum status CI final lulus dan review dilakukan.

## Referensi

- PR #23 — pagination dan aset katalog, digabung hanya ke branch integrasi.
- PR #22 — ditutup tanpa merge karena konflik dengan official main; integrasi dilakukan selektif.
- PR #24 — draft validasi kandidat gabungan.
