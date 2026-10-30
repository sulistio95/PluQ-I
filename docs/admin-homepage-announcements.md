# Pengumuman Berjalan di Halaman Awal

## Tujuan
Admin dapat mengelola teks pengumuman yang berjalan pada bagian atas halaman awal toko, misalnya jadwal maintenance, gangguan layanan, informasi, atau promo.

## Perilaku
- Menu admin: **Pengumuman Halaman Awal**.
- Admin dengan hak pengelolaan pengaturan dapat menambah, mengubah, menghapus, dan mengaktifkan/menonaktifkan pengumuman.
- Jenis pesan: informasi, maintenance, peringatan, dan promo.
- Maksimal 300 karakter per pesan.
- Semua pengumuman aktif ditampilkan dalam satu ticker; pesan nonaktif tidak ditampilkan.
- Teks di-escape saat dirender agar konten pengumuman tidak dapat menyisipkan HTML/JavaScript.
- Perubahan menggunakan token CSRF dan dicatat ke audit log.
- Data disimpan sebagai JSON pada tabel `settings`, kunci `homepage_announcements`; tidak memerlukan migration baru karena tabel `settings` disediakan oleh migration 002.

## Verifikasi manual
1. Terapkan schema dan migration 002 atau lebih baru.
2. Masuk sebagai SUPER_ADMIN atau ADMIN.
3. Buka **Pengumuman Halaman Awal**, tambahkan pesan bertipe Maintenance dan simpan dalam status aktif.
4. Buka halaman awal; ticker harus menampilkan pesan.
5. Nonaktifkan pesan; ticker harus hilang jika tidak ada pesan aktif lain.
6. Ubah pesan, lalu hapus; pastikan perubahan tercermin di halaman awal.
7. Coba akses halaman admin sebagai OPERATOR; akses harus ditolak.
8. Pastikan request POST tanpa token CSRF valid ditolak.

## Catatan
Ticker menggunakan animasi CSS dan menghormati preferensi `prefers-reduced-motion`. Tidak ada JavaScript tambahan atau layanan worker.
