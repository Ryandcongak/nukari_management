# Nukari Management System

Aplikasi manajemen internal PT Nukari Kriya Raya untuk dijalankan melalui MAMP pada komputer lokal.

## Kebutuhan

- MAMP dengan PHP 8.2 dan MySQL.
- Ekstensi PHP: PDO MySQL, mbstring, fileinfo, zip, gd.
- Composer dependencies sudah berada di folder `vendor`.

Konfigurasi bawaan mengikuti MAMP lokal: Apache `8888`, MySQL `8889`, database `nukari_management`, dan akun MySQL `root/root`.

## Menjalankan aplikasi

1. Nyalakan Apache dan MySQL dari MAMP.
2. Pastikan database `nukari_management` tersedia.
3. Untuk database lama yang struktur 001–015 sudah lengkap, catat baseline satu kali: `/Applications/MAMP/bin/php/php8.2.0/bin/php scripts/migrate.php --baseline`. Perintah ini membaseline 001–015 lalu langsung menerapkan migrasi sesudahnya.
4. Untuk pembaruan berikutnya, jalankan `/Applications/MAMP/bin/php/php8.2.0/bin/php scripts/migrate.php` agar hanya migrasi baru yang diterapkan.
5. Buka `http://localhost:8888/nukari_management/`.

Untuk instalasi baru, file dump lokal `nukari_management.sql` dapat diimpor terlebih dahulu melalui phpMyAdmin. Dump database sengaja diabaikan oleh Git karena berisi data operasional dan hash akun; file tersebut tetap tersimpan di komputer lokal.

Jangan menjalankan file migrasi lama satu per satu pada database aktif. Tabel `schema_migrations` menyimpan versi dan checksum sehingga migrasi yang sudah tercatat tidak dijalankan ulang. Opsi `--baseline` hanya untuk database lama yang struktur 001–015 sudah tersedia.

Konfigurasi bawaan tetap kompatibel dengan MAMP. Nilainya dapat ditimpa melalui environment variables yang tercantum di `.env.example`; aplikasi tidak membaca file `.env` secara otomatis.

## Cloudflare Tunnel dan hosting

Aplikasi mendeteksi HTTPS, hostname, serta subpath dari request dan header reverse proxy secara otomatis. Untuk deployment production, konfigurasi yang paling deterministik adalah menetapkan `NUKARI_BASE_URL` pada environment Apache/PHP ke URL publik lengkap, misalnya `https://management.example.com` atau `https://example.com/nukari`. Jangan tambahkan garis miring di akhir.

Untuk Cloudflare Tunnel, arahkan service ke origin MAMP, misalnya `http://localhost:8888`. Bila aplikasi masih berada di document root MAMP sebagai folder `nukari_management`, URL publiknya menggunakan path `/nukari_management/`. Alternatif yang lebih bersih adalah mengatur virtual host Apache dengan document root langsung ke folder project lalu menggunakan domain tanpa subpath.

## Role dan izin akses

| Role | Akses |
|---|---|
| super_admin | Akses penuh, User Management, seluruh operasional, laporan, pembayaran, dan tindakan pemulihan teknis |
| admin | Seluruh operasional, laporan, serta pembayaran payroll; tidak dapat mengelola akun dan role |
| director | Laporan eksekutif dan notifikasi read-only; dapat mencetak atau mengekspor laporan |
| commissioner | Laporan eksekutif dan notifikasi read-only; dapat mencetak atau mengekspor laporan |

Pembatasan dilakukan pada server untuk akses baca maupun perubahan data. Menyembunyikan menu hanya menjadi lapisan antarmuka tambahan. Payroll tetap memakai alur `Draft -> Paid & Locked`; tanda tangan slip dilakukan secara fisik dan tidak menjadi approval digital di aplikasi.

## Backup database

Saat MySQL aktif, jalankan:

```sh
chmod +x scripts/backup_database.sh scripts/restore_database.sh
./scripts/backup_database.sh
```

Backup dikompresi ke `backups/database` dan backup lebih dari 30 hari dibersihkan. Folder ini sengaja diabaikan Git. Untuk mengembalikan backup:

```sh
./scripts/restore_database.sh backups/database/nukari_management_YYYYMMDD_HHMMSS.sql.gz
```

Restore meminta konfirmasi eksplisit. Selalu buat backup baru sebelum restore.

Setiap backup baru memiliki file checksum `.sha256`. Uji restore tanpa menyentuh database aktif dengan:

```sh
./scripts/verify_database_backup.sh backups/database/nukari_management_YYYYMMDD_HHMMSS.sql.gz
```

Perintah tersebut membuat database sementara, memulihkan backup, menjalankan audit attendance dan operasional, lalu menghapus database sementara. Backup harian macOS dijadwalkan pukul 18.00 melalui LaunchAgent `com.nukari.management.backup`. Pasang ulang atau nonaktifkan jadwal dengan `./scripts/install_backup_schedule.sh` dan `./scripts/uninstall_backup_schedule.sh`. Log tersimpan di `backups/logs`.

## Keamanan lokal

- Semua POST diverifikasi dengan CSRF token.
- Aksi hapus lama dikirim ulang sebagai POST oleh UI dan endpoint kritis menolak GET.
- Sesi berakhir setelah dua jam tidak aktif dan session ID diganti saat login.
- Folder upload menolak eksekusi script melalui konfigurasi Apache.
- Error koneksi database detail hanya masuk ke error log.

## Catatan operasional

- Jangan mematikan komputer ketika transaksi sedang disimpan.
- Jalankan backup setiap hari kerja dan salin berkala ke drive eksternal.
- Jangan menghapus isi folder `uploads` tanpa memeriksa referensinya dalam database.
- Tombol WhatsApp hanya tampil bila `NUKARI_WHATSAPP` diisi. Chat AI disembunyikan secara bawaan.
- Alat pengujian password lama sudah dihapus dari aplikasi operasional.

## Laporan dan perangkat

Menu **Laporan** menyediakan laporan keuangan, proyek, payroll, aset, penjualan produk, dan karyawan. Direktur dan Komisaris memakai tampilan **Ringkasan Manajemen** yang sama dan sepenuhnya read-only; peran tetap dipisah agar izin dapat dikembangkan pada masa depan. Setiap laporan dapat difilter, diunduh sebagai CSV berformat UTF-8 yang kompatibel dengan Excel, serta dicetak atau disimpan sebagai PDF melalui dialog Print browser.

Pada laporan keuangan, **Total Uang Perusahaan** adalah saldo kas/bank aktif ditambah sisa uang perusahaan yang masih dipegang PIC. Piutang dan hutang disajikan terpisah, sedangkan **Posisi Bersih** dihitung sebagai total uang + piutang - hutang. Pemberian dana kepada PIC dan pengembalian sisa dana adalah transfer internal; hanya pemakaian Dana PIC yang menjadi pengeluaran pada arus kas. Dashboard Keuangan dan laporan memakai sumber arus kas konsolidasi yang sama. Ringkasan Manajemen menampilkan kesimpulan utama, periode cepat, waktu pembaruan data, dan rincian yang dapat dibuka bila diperlukan.

Tampilan global mendukung desktop, laptop, tablet, dan smartphone. Pada layar kecil, tabel lebar dapat digeser secara horizontal; gunakan mode landscape ketika mencetak tabel dengan banyak kolom. Tooltip kontekstual tersedia pada menu, tombol aksi, field formulir, status, dan card ringkasan; pengguna dapat membukanya dengan pointer, fokus keyboard, atau sentuhan pada perangkat mobile.

## Panduan penggunaan dan POS

Menu **Panduan Penggunaan** berbentuk buku dokumentasi dengan daftar isi, bab langkah demi langkah, pencarian seluruh isi, tautan antarbagian, dan tautan teks langsung ke fitur terkait. Dokumentasi juga dapat dicetak sebagai buku panduan.

POS mendukung beberapa antrean keranjang sekaligus. Antrean disimpan di browser komputer kasir sehingga pelanggan dapat ditahan sementara, antrean baru dapat dilayani, lalu keranjang sebelumnya dibuka kembali. Setelah pembayaran berhasil diproses, antrean terkait dikeluarkan dari daftar.

Absensi hasil impor dapat ditambah, dikoreksi, atau dihapus per karyawan dari halaman Absensi. Perubahan manual menghitung ulang keterlambatan, pulang awal, dan total kerja serta dicatat pada activity log. Scan tidak lengkap atau durasi tidak baku di bawah enam jam masuk ke antrean review. Superadmin/Admin dapat mengonfirmasi data tersebut dengan catatan tanpa mengubah nilai kehadiran; perubahan jam atau import ulang dengan data berbeda akan membuka review kembali.

## Pemeriksaan setelah perubahan

```sh
find . -path './vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l
php scripts/check_code_regressions.php
php scripts/test_authorization_rules.php
php scripts/test_attendance_rules.php
/Applications/MAMP/bin/php/php8.2.0/bin/php scripts/test_attendance_review_tracking.php
/Applications/MAMP/bin/php/php8.2.0/bin/php scripts/test_write_transactions.php
/Applications/MAMP/bin/php/php8.2.0/bin/php scripts/audit_operational_integrity.php
/Applications/MAMP/bin/php/php8.2.0/bin/php scripts/audit_report_reconciliation.php
/Applications/MAMP/bin/php/php8.2.0/bin/php scripts/smoke_read_pages.php
```

Untuk menjalankan seluruh pemeriksaan di atas sekaligus:

```sh
./scripts/run_health_checks.sh
```
