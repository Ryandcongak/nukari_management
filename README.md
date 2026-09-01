# Nukari Management System

Aplikasi manajemen internal PT Nukari Kriya Raya untuk dijalankan melalui MAMP pada komputer lokal.

## Kebutuhan

- MAMP dengan PHP 8.2 dan MySQL.
- Ekstensi PHP: PDO MySQL, mbstring, fileinfo, zip, gd.
- Composer dependencies sudah berada di folder `vendor`.

## Menjalankan aplikasi

1. Nyalakan Apache dan MySQL dari MAMP.
2. Pastikan database `nukari_management` tersedia.
3. Jalankan `database/migrations/001_local_foundation.sql` melalui phpMyAdmin.
4. Buka `http://localhost:8888/nukari_management/`.

Konfigurasi bawaan tetap kompatibel dengan MAMP. Nilainya dapat ditimpa melalui environment variables yang tercantum di `.env.example`; aplikasi tidak membaca file `.env` secara otomatis.

## Role dan izin perubahan data

| Modul | Role yang dapat mengubah data |
|---|---|
| User | super_admin, admin |
| Payroll/Finance/Hutang/Talangan | super_admin, admin, manager, finance |
| Karyawan/Absensi/HR | super_admin, admin, manager |
| Proyek | super_admin, admin, manager, finance, staff |
| Aset/Produk/Client/Supplier | super_admin, admin, manager, staff |
| Notifikasi | super_admin, admin, manager |

Pengguna lain tetap dapat melihat halaman yang tersedia, tetapi request perubahan akan ditolak oleh server.

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
- `test_password.php` hanya alat lama dan tidak boleh digunakan pada operasional.

## Pemeriksaan setelah perubahan

```sh
find . -path './vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l
```
