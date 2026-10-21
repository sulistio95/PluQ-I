# Pemetaan Field Akun Game

Status: audit awal, 2026-10-21. Ini adalah pemetaan kebutuhan field UI, bukan persetujuan untuk mengaktifkan transaksi.

## Matriks pemetaan

| Game | Field UI yang diharapkan | Nilai yang dikirim ke supplier | Status |
|---|---|---|---|
| Mobile Legends | User ID + Zone ID | `data_no` = User ID; `data_zone` = Zone ID | Skema field sudah ada; mapping order tetap perlu diverifikasi |
| Genshin Impact | User ID + dropdown Server / Region (Asia, America, Europe, TW/HK/MO) | Nilai dropdown (`asia`, `america`, `europe`, `tw_hk_mo`) hanya placeholder UI; belum dipastikan diterima supplier atau harus dipetakan ke `data_zone`/parameter lain | Dropdown UI dibuat; checkout tetap diblokir sampai kode target supplier diverifikasi |
| Honkai: Star Rail | User ID + dropdown Server / Region (Asia, America, Europe, TW/HK/MO) | Nilai dropdown (`asia`, `america`, `europe`, `tw_hk_mo`) hanya placeholder UI; belum dipastikan diterima supplier atau harus dipetakan ke `data_zone`/parameter lain | Dropdown UI dibuat; checkout tetap diblokir sampai kode target supplier diverifikasi |
| Magic Chess GoGo | User ID + Zone ID | `data_no` = User ID; `data_zone` = Zone ID | Skema field sudah ada; pemetaan format supplier belum dikonfirmasi |
| Zenless Zone Zero | User ID + dropdown Server / Region (Asia, America, Europe, TW/HK/MO) | Nilai dropdown (`asia`, `america`, `europe`, `tw_hk_mo`) hanya placeholder UI; belum dipastikan diterima supplier atau harus dipetakan ke `data_zone`/parameter lain | Dropdown UI dibuat; checkout tetap diblokir sampai kode target supplier diverifikasi |

## Temuan API VIPayment

Dokumentasi publik VIPayment untuk `POST /api/game-feature` mendokumentasikan:
- `type=services`: daftar layanan berisi kode, game, nama, harga, deskripsi, `server`, dan status. Properti `server` berada pada record layanan; dokumentasi tidak menjelaskan bahwa properti tersebut adalah daftar pilihan server akun pelanggan.
- `type=order`: `data_no` untuk ID tujuan dan `data_zone` untuk data zone bila diperlukan.
- `type=get-nickname`: parameter `code`, `target`, dan `additional_target`; dokumentasi yang tersedia tidak mendefinisikan endpoint khusus untuk mengambil daftar pilihan server.

Karena itu, jangan menggunakan nilai `server` pada SKU sebagai opsi server akun secara otomatis tanpa bukti bahwa artinya memang sama. Opsi dropdown harus berasal dari sumber server yang terverifikasi, dengan label yang dapat dibaca dan nilai kode yang benar-benar dikirim saat order.

## Gerbang implementasi

1. Simpan contoh respons katalog VIPayment yang sudah disanitasi untuk Genshin, Honkai: Star Rail, Magic Chess GoGo, dan Zenless Zone Zero. Hapus API ID, API key, signature, serta data akun pelanggan.
2. Cocokkan dokumentasi resmi dan respons nyata untuk menentukan apakah server list tersedia dari API VIPayment atau perlu konfigurasi mapping yang ditinjau admin.
3. Untuk setiap pilihan, pisahkan `option_label` (misalnya nama server) dan `option_value` (kode persis yang diterima supplier).
4. Pastikan checkout memvalidasi nilai server/zone terhadap daftar yang diizinkan di server; jangan hanya mengandalkan validasi browser.
5. Tambahkan tes untuk field, opsi, kode target order, serta penolakan nilai yang tidak dikenal.
6. Biarkan checkout untuk skema yang belum terverifikasi tetap diblokir. Jangan mengaktifkan pembayaran, pemesanan supplier, atau backup Google Drive dalam pekerjaan pemetaan ini.

## Batasan saat ini

- Zenless Zone Zero kini memakai dropdown UI dengan pilihan umum Asia, America, Europe, dan TW / HK / MO. Nilai tersebut hanya placeholder UI dan belum terverifikasi sebagai kode supplier.
- Tes `tests/game-account-schema.php` menguji nama field dasar, tetapi belum menguji opsi server dinamis atau transformasi nilai order.
- Pemetaan ini tidak menganggap bahwa keberhasilan menampilkan field berarti akun atau transaksi supplier sudah tervalidasi.


## Update 2026-10-21 — Dropdown Server / Region sementara

- Genshin Impact dan Honkai: Star Rail kini menggunakan dropdown berisi Asia, America, Europe, dan TW / HK / MO.
- Nilai option (`asia`, `america`, `europe`, `tw_hk_mo`) adalah nilai UI sementara, bukan kode API VIPayment yang sudah dikonfirmasi. Jangan mengirimkannya ke supplier secara langsung.
- `game_account_schema_is_supported()` tetap menolak checkout untuk kedua game karena field `server` belum memiliki mapping supplier yang diverifikasi. Magic Chess GoGo tetap menggunakan User ID + Zone ID.
- Langkah berikutnya: jalankan tes schema, lint, dan pemeriksaan CI; implementasikan mapping target order hanya setelah kode supplier yang benar ditemukan dari dokumentasi/respons API yang sah.


## Update 2026-10-21 — Dropdown Zenless Zone Zero

- Field Server / Region untuk Zenless Zone Zero kini menggunakan dropdown dengan pilihan UI umum Asia, America, Europe, dan TW / HK / MO, sama seperti dropdown Genshin Impact dan Honkai: Star Rail.
- Nilai opsi belum diverifikasi terhadap kode order VIPayment. Checkout tetap diblokir untuk skema server sampai pemetaan supplier diverifikasi.
- Tes regresi mencakup slug katalog aktif `zenless-zone-zero-zzz` dan nama `Zenless Zone Zero (ZZZ)` agar perubahan tetap berlaku untuk entri yang memiliki produk.
