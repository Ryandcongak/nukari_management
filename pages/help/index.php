<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Panduan Penggunaan - Nukari Management';
$isReportReader = is_report_only_role();
$currentRoleLabel = app_role_label((string)($_SESSION['role'] ?? ''));
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<style>
.docs-shell{display:grid;grid-template-columns:280px minmax(0,1fr);gap:22px;align-items:start}.docs-nav{position:sticky;top:92px;max-height:calc(100vh - 112px);overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:15px}.docs-nav-title{padding:7px 10px 12px;font-weight:800}.docs-nav a{display:block;padding:9px 11px;border-radius:11px;color:#475569;text-decoration:none;font-size:14px}.docs-nav a:hover,.docs-nav a.active{background:#eaf8f0;color:#12683f;font-weight:700}.docs-book{min-width:0}.docs-cover{position:relative;overflow:hidden;background:linear-gradient(135deg,#0b3327,#146c46 65%,#2ba66a);color:#fff;border-radius:25px;padding:32px;margin-bottom:20px}.docs-cover:after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;right:-70px;top:-90px;background:rgba(255,255,255,.08)}.docs-cover h1{font-size:clamp(1.8rem,4vw,2.8rem);font-weight:850}.docs-search{position:relative;max-width:780px;margin-top:20px;z-index:1}.docs-search i{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:#64748b}.docs-search input{border:0;min-height:56px;padding-left:50px;border-radius:15px;font-size:16px}.docs-search-meta{min-height:22px;margin-top:9px;font-size:13px;color:#d7f5e3}.docs-section{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:28px;margin-bottom:18px;scroll-margin-top:95px}.docs-section h2{font-size:1.55rem;font-weight:800;margin-bottom:8px}.docs-section h3{font-size:1.08rem;font-weight:800;margin:24px 0 9px}.docs-section p,.docs-section li{line-height:1.72;color:#475569}.docs-section li{margin-bottom:7px}.docs-section a:not(.btn){color:#167649;font-weight:650;text-underline-offset:3px}.docs-kicker{color:#17804d;text-transform:uppercase;font-size:12px;letter-spacing:.08em;font-weight:800}.docs-callout{border-left:4px solid #22a565;background:#effaf3;padding:14px 16px;border-radius:0 13px 13px 0;margin:16px 0;color:#315747}.docs-callout.warning{border-color:#f59e0b;background:#fff9e8;color:#725212}.docs-callout.danger{border-color:#dc3545;background:#fff2f3;color:#7f2630}.docs-steps{counter-reset:step;list-style:none;padding:0}.docs-steps>li{position:relative;padding:3px 0 14px 43px;min-height:38px}.docs-steps>li:before{counter-increment:step;content:counter(step);position:absolute;left:0;top:0;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:#1f7a4d;color:#fff;font-weight:800}.docs-link-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.docs-link-grid a{border:1px solid #dce8e1;padding:12px;border-radius:13px;text-decoration:none}.docs-no-results{display:none;text-align:center;padding:45px}.docs-mobile-toc{display:none;margin-bottom:14px}.docs-section.is-hidden{display:none}.docs-table{width:100%;border-collapse:collapse}.docs-table th,.docs-table td{border-bottom:1px solid #e2e8f0;padding:10px;text-align:left;vertical-align:top}.docs-table th{background:#f7faf8}.docs-flow{display:flex;align-items:stretch;gap:28px;margin:18px 0 24px;overflow-x:auto;padding:4px 2px 12px;scroll-snap-type:x proximity}.docs-flow-step{position:relative;flex:1 0 145px;min-width:145px;background:linear-gradient(180deg,#fff,#f4fbf7);border:1px solid #cfe5d8;border-radius:18px;padding:16px 12px;text-align:center;scroll-snap-align:start}.docs-flow-step:not(:last-child):after{content:"\F285";font-family:"bootstrap-icons";position:absolute;right:-22px;top:50%;transform:translateY(-50%);color:#24a365;font-size:20px}.docs-flow-icon{width:48px;height:48px;margin:0 auto 9px;border-radius:16px;display:grid;place-items:center;background:#daf5e5;color:#137447;font-size:23px}.docs-flow-step strong{display:block;color:#183b2c;font-size:14px}.docs-flow-step small{display:block;color:#64748b;margin-top:5px;line-height:1.4}.docs-flow-step.warn{background:#fff9e9;border-color:#f4d38c}.docs-flow-step.warn .docs-flow-icon{background:#fff0c5;color:#a96800}.docs-flow-step.blue{background:#f1f7ff;border-color:#cadcf4}.docs-flow-step.blue .docs-flow-icon{background:#dcecff;color:#2563a8}.docs-branch{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:17px 0}.docs-branch-card{border:1px solid #dce7e1;border-radius:17px;padding:16px;background:#fff}.docs-branch-card .branch-head{display:flex;align-items:center;gap:9px;font-weight:800;color:#183b2c;margin-bottom:7px}.docs-branch-card .branch-head i{width:34px;height:34px;border-radius:11px;display:grid;place-items:center;background:#e7f7ee;color:#17804d}.docs-branch-card p{font-size:13px;line-height:1.55;margin:0}.docs-mini-formula{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;background:#f8faf9;border:1px dashed #b9d5c5;border-radius:16px;padding:14px;margin:13px 0}.docs-mini-formula span{background:#fff;border:1px solid #dce8e1;border-radius:12px;padding:9px 12px;font-weight:750;color:#315747}.docs-mini-formula i{color:#17804d}.docs-quick-links{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 4px}.docs-quick-links .btn{border-radius:999px}.docs-role-banner{display:flex;align-items:center;gap:14px;background:#f4fbf7;border:1px solid #cfe5d8;border-radius:16px;padding:15px;margin:18px 0}.docs-role-banner i{width:44px;height:44px;flex:0 0 44px;border-radius:14px;display:grid;place-items:center;background:#d9f4e4;color:#137447;font-size:21px}.docs-role-banner strong{display:block;color:#183b2c}.docs-role-banner small{color:#64748b}.docs-checklist{list-style:none;padding-left:0}.docs-checklist li{position:relative;padding-left:31px}.docs-checklist li:before{content:"\F26B";font-family:"bootstrap-icons";position:absolute;left:0;color:#17804d;font-size:18px}.docs-tag{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;background:#eaf8f0;color:#12683f;font-size:12px;font-weight:800;margin-bottom:10px}
@media(max-width:991px){.docs-shell{grid-template-columns:1fr}.docs-nav{display:none;position:static;max-height:none}.docs-nav.mobile-open{display:block}.docs-mobile-toc{display:flex}.docs-section{padding:22px}}@media(max-width:767px){.docs-flow{gap:25px}.docs-flow-step{flex-basis:132px;min-width:132px}.docs-branch{grid-template-columns:1fr}.docs-flow-step:not(:last-child):after{right:-20px}}@media(max-width:576px){.docs-cover{padding:22px;border-radius:18px}.docs-section{padding:18px;border-radius:16px}.docs-link-grid{grid-template-columns:1fr}.docs-table{min-width:620px}}@media print{.docs-nav,.docs-mobile-toc,.docs-search,.docs-search-meta,.docs-section .btn{display:none!important}.docs-shell{display:block}.docs-cover{color:#111;background:#fff;border:1px solid #ddd}.docs-section{display:block!important;break-inside:avoid;border:0;border-bottom:1px solid #ddd;border-radius:0;padding:16px 0}.docs-flow{overflow:visible;gap:18px}.docs-flow-step{min-width:0}.docs-flow-step:not(:last-child):after{display:none}.docs-branch{grid-template-columns:repeat(3,1fr)}}
</style>

<main class="main-content">
<button class="btn btn-outline-success docs-mobile-toc" id="docsTocToggle"><i class="bi bi-list me-2"></i>Daftar Isi</button>
<div class="docs-shell">
<aside class="docs-nav" id="docsNav">
    <div class="docs-nav-title">Daftar Isi</div>
    <a href="#mulai">1. Mulai & Alur Kerja</a>
    <a href="#akses">2. Peran & Hak Akses</a>
    <?php if (!$isReportReader): ?>
        <a href="#persiapan">3. Persiapan Sistem</a>
        <a href="#hr">4. Karyawan & Absensi</a>
        <a href="#payroll">5. Payroll</a>
        <a href="#proyek">6. Client, Vendor & Proyek</a>
        <a href="#produk">7. Produk & Kasir</a>
        <a href="#keuangan">8. Keuangan & Dana PIC</a>
        <a href="#aset">9. Aset & Inventaris</a>
    <?php endif; ?>
    <a href="#laporan"><?= $isReportReader ? '3' : '10'; ?>. Membaca Laporan</a>
    <a href="#notifikasi"><?= $isReportReader ? '4' : '11'; ?>. Notifikasi</a>
    <?php if (!$isReportReader): ?><a href="#backup">12. Backup & Keamanan</a><?php endif; ?>
    <a href="#masalah"><?= $isReportReader ? '5' : '13'; ?>. Masalah & Checklist</a>
</aside>

<div class="docs-book">
<header class="docs-cover">
    <div class="docs-kicker text-white opacity-75">Panduan Sistem Aktif</div>
    <h1>Panduan Penggunaan Nukari Management</h1>
    <p class="mb-0 opacity-75">Langkah kerja sesuai menu, peran pengguna, dan proses yang berjalan di perusahaan.</p>
    <div class="docs-search"><i class="bi bi-search"></i><input id="docsSearch" class="form-control" placeholder="Cari: payroll, absensi, saldo, POS, aset..." value="<?= htmlspecialchars($_GET['q'] ?? ''); ?>"></div>
    <div class="docs-search-meta" id="docsSearchMeta">Ketik kata untuk mencari isi panduan.</div>
</header>

<div class="panel docs-no-results" id="docsNoResults"><i class="bi bi-journal-x fs-1 text-muted"></i><h5 class="mt-3">Topik tidak ditemukan</h5><p class="text-muted mb-0">Coba kata yang lebih umum atau pilih bab melalui Daftar Isi.</p></div>

<article class="docs-section" id="mulai" data-search="mulai login masuk dashboard alur kerja admin direktur komisaris smartphone logout">
    <div class="docs-kicker">Bab 1</div>
    <h2>Mulai dan Alur Kerja Utama</h2>
    <div class="docs-role-banner"><i class="bi bi-person-check"></i><div><strong>Anda masuk sebagai <?= htmlspecialchars($currentRoleLabel); ?></strong><small>Panduan yang tampil sudah disesuaikan dengan akses akun ini.</small></div></div>
    <?php if ($isReportReader): ?>
        <p>Untuk Direktur dan Komisaris, penggunaan sistem dibuat sesederhana mungkin. Tidak ada formulir input, edit, hapus, atau persetujuan digital.</p>
        <ol class="docs-steps">
            <li>Masuk menggunakan username/email dan password yang diberikan Superadmin.</li>
            <li>Buka <a href="<?= base_url('pages/reports/index.php'); ?>">Ringkasan Manajemen</a>. Pilih periode cepat seperti <strong>Bulan Ini</strong>, <strong>Bulan Lalu</strong>, atau <strong>Tahun Ini</strong>.</li>
            <li>Baca bagian <strong>Kesimpulan Utama</strong>, lalu periksa kartu uang, arus kas, proyek, payroll, karyawan, produk, dan aset bila diperlukan.</li>
            <li>Tekan ikon lonceng untuk melihat pengingat. Daftar ini hanya dapat dibaca dan tidak membuka halaman operasional.</li>
            <li>Setelah selesai, pilih <strong>Logout</strong>. Jangan memberikan password kepada orang lain.</li>
        </ol>
        <div class="docs-callout">Direktur dan Komisaris melihat tampilan laporan yang sama. Direktur tidak perlu melakukan approval di sistem; persetujuan payroll tetap dilakukan dengan tanda tangan pada slip cetak.</div>
    <?php else: ?>
        <p>Gunakan urutan berikut agar data antar-modul tetap terhubung dan laporan tidak berganda.</p>
        <div class="docs-flow" aria-label="Alur kerja sistem">
            <div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-grid"></i></div><strong>Siapkan Master</strong><small>Akun, kategori, pihak, client, supplier</small></div>
            <div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-pencil-square"></i></div><strong>Catat Operasional</strong><small>HR, proyek, produk, aset, transaksi</small></div>
            <div class="docs-flow-step blue"><div class="docs-flow-icon"><i class="bi bi-check2-square"></i></div><strong>Periksa</strong><small>Cek bukti, nominal, periode, dan sumber dana</small></div>
            <div class="docs-flow-step warn"><div class="docs-flow-icon"><i class="bi bi-lock"></i></div><strong>Kunci Transaksi</strong><small>Paid hanya setelah proses nyata selesai</small></div>
            <div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-bar-chart"></i></div><strong>Baca Laporan</strong><small>Pastikan saldo dan arus kas masuk akal</small></div>
        </div>
        <ol class="docs-steps"><li>Pastikan Apache dan MySQL MAMP aktif, lalu buka aplikasi.</li><li>Masuk menggunakan akun sendiri. Dashboard adalah ringkasan awal pekerjaan.</li><li>Gunakan sidebar untuk berpindah modul; pada smartphone tekan ikon tiga garis.</li><li>Periksa kembali data sebelum menekan Simpan, Hapus, Bayar, Paid, atau Locked.</li><li>Logout setelah selesai dan jangan memakai satu akun bersama-sama.</li></ol>
    <?php endif; ?>
</article>

<article class="docs-section" id="akses" data-search="peran role hak akses superadmin ceo admin direktur komisaris audit log tooltip baca saja">
    <div class="docs-kicker">Bab 2</div><h2>Peran, Hak Akses, dan Bantuan di Layar</h2>
    <div class="table-responsive"><table class="docs-table"><thead><tr><th>Peran</th><th>Hak yang tersedia</th><th>Batas penting</th></tr></thead><tbody>
        <tr><td><strong>Superadmin / CEO</strong></td><td>Akses penuh sistem, kelola pengguna, seluruh operasional, laporan, pembayaran, koreksi, dan pemulihan data.</td><td>Gunakan hak khusus seperti Unlock payroll dan data historis hanya dengan alasan yang jelas.</td></tr>
        <tr><td><strong>Admin</strong></td><td>Input, edit, lihat, hapus data operasional; kelola HR, payroll, proyek, produk, keuangan, aset, notifikasi, dan laporan.</td><td>Tidak dapat mengelola akun pengguna atau membuka payroll terkunci. Aktivitas perubahan dicatat.</td></tr>
        <tr><td><strong>Direktur</strong></td><td>Melihat dan mengekspor Ringkasan Manajemen serta membaca notifikasi pada ikon lonceng.</td><td>Tidak ada input, edit, hapus, atau approval digital.</td></tr>
        <tr><td><strong>Komisaris</strong></td><td>Melihat dan mengekspor Ringkasan Manajemen serta membaca notifikasi pada ikon lonceng.</td><td>Tampilan sama dengan Direktur dan seluruhnya baca-saja.</td></tr>
    </tbody></table></div>
    <h3>Tooltip dan ikon bantuan</h3><p>Arahkan pointer ke menu, tombol, status, kartu angka, atau field formulir untuk membaca penjelasan singkat. Pada smartphone, sentuh ikon informasi atau fokuskan kontrol. Tooltip hanya menjelaskan dan tidak mengubah data.</p>
    <div class="docs-callout warning">Pesan konfirmasi sebelum menghapus atau membayar harus dibaca sampai selesai. Jika ragu terhadap nominal, periode, atau sumber dana, batalkan dahulu dan periksa datanya.</div>
</article>

<?php if (!$isReportReader): ?>
<article class="docs-section" id="persiapan" data-search="persiapan user pengguna username password master data kategori akun pihak pic client supplier saldo awal">
    <div class="docs-kicker">Bab 3</div><h2>Persiapan Sistem dan Master Data</h2>
    <?php if (has_permission('users.manage')): ?>
        <span class="docs-tag"><i class="bi bi-shield-lock"></i>Khusus Superadmin</span>
        <h3>Mengelola akun pengguna</h3>
        <ol class="docs-steps"><li>Buka <a href="<?= base_url('pages/users/index.php'); ?>">User Management</a>, lalu pilih Tambah User.</li><li>Isi nama lengkap, username, email, nomor telepon, password, role, dan status akun.</li><li>Gunakan satu akun untuk satu orang agar catatan aktivitas dapat ditelusuri.</li><li>Username tidak diubah dari form edit. Bila identitas login harus diganti, lakukan sebagai pekerjaan teknis terkontrol.</li><li>Nonaktifkan akun yang tidak dipakai. Sistem melindungi Superadmin aktif terakhir agar tidak terhapus atau dinonaktifkan.</li></ol>
    <?php endif; ?>
    <h3>Urutan menyiapkan data dasar</h3>
    <ol class="docs-steps"><li>Buka <a href="<?= base_url('pages/master/index.php'); ?>">Master Data</a>.</li><li>Buat kategori transaksi pemasukan/pengeluaran yang akan dipakai di Buku Kas.</li><li>Buat akun Kas, Bank, atau akun lain dan tentukan akun default. Masukkan saldo awal melalui menu Keuangan hanya jika memang saldo pembukaan.</li><li>Tambahkan Pihak/PIC untuk orang yang memegang dana, menalangi perusahaan, atau memiliki hubungan bisnis.</li><li>Tambahkan <a href="<?= base_url('pages/clients/index.php'); ?>">Client</a> dan <a href="<?= base_url('pages/suppliers/index.php'); ?>">Vendor/Supplier</a> sebelum membuat transaksi terkait.</li><li>Lengkapi kelompok master lain seperti jabatan, departemen, satuan, kategori aset, dan metode pembayaran sesuai kebutuhan.</li></ol>
    <div class="docs-callout">Data master sebaiknya tidak dibuat ganda dengan ejaan berbeda. Cari nama yang sudah ada sebelum menambahkan data baru.</div>
</article>

<article class="docs-section" id="hr" data-search="karyawan internship absensi fingerprint import csv xls xlsx review cuti izin sakit lembur analytics payroll">
    <div class="docs-kicker">Bab 4</div><h2>Karyawan, Absensi, Cuti, dan HR Analytics</h2>
    <h3>Menambahkan karyawan atau internship</h3>
    <ol class="docs-steps"><li>Buka <a href="<?= base_url('pages/employees/index.php'); ?>">Karyawan & Internship</a>, lalu pilih Tambah.</li><li>Isi kode karyawan, ID fingerprint, nama, jenis kelamin, tanggal lahir, jabatan, departemen, telepon, tanggal masuk, dan alamat.</li><li>Pilih tipe gaji <strong>Bulanan</strong>, <strong>Harian</strong>, atau <strong>Per Kehadiran</strong>, lalu isi nominal yang benar.</li><li>Pilih status Active agar karyawan tersedia pada absensi dan payroll. Gunakan Inactive atau Resigned jika sudah tidak aktif.</li></ol>
    <h3>Impor absensi fingerprint</h3>
    <ol class="docs-steps"><li>Pastikan ID fingerprint pada file sama dengan data karyawan. Nama hanya menjadi pencocokan cadangan jika unik.</li><li>Buka <a href="<?= base_url('pages/attendances/import.php'); ?>">Import Absensi</a> dan unggah CSV, XLS, atau XLSX maksimal 10 MB.</li><li>Pastikan file memuat periode, nomor hari, serta jam format 24 jam. Sistem membaca seluruh worksheet.</li><li>Baca hasil impor: baru, diperbarui, dilewati, atau identitas tidak ditemukan.</li><li>Buka <a href="<?= base_url('pages/attendances/index.php'); ?>">Daftar Absensi</a>, filter periode, lalu periksa baris bertanda Perlu Diperiksa.</li></ol>
    <div class="table-responsive"><table class="docs-table"><thead><tr><th>Kondisi scan</th><th>Hasil sistem</th><th>Tindakan admin</th></tr></thead><tbody><tr><td>Datang dan pulang lengkap</td><td>Scan pertama menjadi datang, scan terakhir menjadi pulang.</td><td>Periksa bila jam tidak baku atau durasinya janggal.</td></tr><tr><td>Hanya satu scan</td><td>Data tetap disimpan, tetapi nilai kerja belum lengkap.</td><td>Edit dan lengkapi jam yang benar berdasarkan bukti.</td></tr><tr><td>Impor file yang sama lagi</td><td>Baris karyawan-tanggal yang sama diperbarui, bukan digandakan.</td><td>Periksa ringkasan hasil impor.</td></tr><tr><td>Sudah masuk payroll Paid/Locked</td><td>Perubahan absensi dibatasi.</td><td>Hubungi Superadmin jika koreksi memang wajib.</td></tr></tbody></table></div>
    <h3>Aturan nilai hadir dan lembur</h3><ul><li>Senin sampai Minggu memakai aturan waktu yang sama.</li><li>Hari penuh dibukukan 1 hari/8 jam; setengah hari pagi atau siang dibukukan 0,5 hari/4 jam.</li><li>Scan tidak lengkap bernilai 0 sampai dikoreksi.</li><li>Lembur dibayar dalam jam bulat: sisa 40 menit atau lebih dibulatkan naik; 0–39 menit dibulatkan turun.</li><li>Tarif lembur per jam dapat diterapkan massal untuk beberapa tanggal satu karyawan. Lembur borongan diedit per tanggal.</li><li>Menandai Review Selesai hanya mencatat bahwa baris sudah diperiksa; tindakan itu tidak mengubah jam atau upah.</li></ul>
    <h3>Cuti, izin, sakit, dan dinas luar</h3><p>Buka <a href="<?= base_url('pages/hr/leaves.php'); ?>">Cuti / Izin</a>, pilih karyawan, jenis, tanggal, dibayar/tidak, alasan, dan bukti. Durasi menghitung seluruh hari kalender dalam rentang, termasuk Sabtu dan Minggu. Status Pending, Approved, Rejected, dan Cancelled merupakan validasi operasional oleh Admin/Superadmin—bukan approval digital Direktur.</p>
    <h3>HR Analytics</h3><p>Gunakan <a href="<?= base_url('pages/hr/analytics.php'); ?>">HR Analytics</a> untuk melihat kehadiran, keterlambatan, pulang awal, alpha, cuti/izin, jam kerja, dan lembur. Analytics membantu pemeriksaan; angka payroll tetap mengikuti snapshot saat Generate/Recalculate.</p>
</article>

<article class="docs-section" id="payroll" data-search="payroll gaji slip cetak tanda tangan direktur paid locked unlock generate recalculate tambahan kasbon pinjaman bpjs pajak">
    <div class="docs-kicker">Bab 5</div><h2>Payroll: dari Data sampai Paid</h2>
    <div class="docs-flow" aria-label="Alur payroll sebenarnya">
        <div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-calendar-check"></i></div><strong>Lengkapi Data</strong><small>Absensi, cuti, tambahan, kasbon, cicilan</small></div>
        <div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-arrow-repeat"></i></div><strong>Generate</strong><small>Buat atau hitung ulang Draft</small></div>
        <div class="docs-flow-step blue"><div class="docs-flow-icon"><i class="bi bi-search"></i></div><strong>Periksa & Cetak</strong><small>Cek detail lalu cetak slip</small></div>
        <div class="docs-flow-step warn"><div class="docs-flow-icon"><i class="bi bi-pen"></i></div><strong>Tanda Tangan Fisik</strong><small>Direktur menandatangani slip cetak</small></div>
        <div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-check-circle"></i></div><strong>Admin Tekan Paid</strong><small>Saldo berkurang dan payroll terkunci</small></div>
    </div>
    <div class="docs-callout"><strong>Tidak ada approval Direktur di sistem.</strong> Persetujuan dilakukan melalui tanda tangan fisik pada slip. Setelah proses nyata selesai, Admin menekan Paid dan memilih sumber pembayaran.</div>
    <h3>Menyiapkan dan menghitung payroll</h3>
    <ol class="docs-steps"><li>Periksa periode pada <a href="<?= base_url('pages/payroll/settings.php'); ?>">Setting Payroll</a>. Contoh tanggal 16–15 berarti payroll Juli memakai data 16 Juni–15 Juli.</li><li>Buka <a href="<?= base_url('pages/payroll/management.php'); ?>">Kelola Payroll</a>. Lengkapi tab Komponen Payroll, Tambahan, Kasbon, Cuti/Izin, dan Pinjaman.</li><li>Buka <a href="<?= base_url('pages/payroll/index.php'); ?>">Ringkasan Gaji</a>, pilih bulan dan parameter BPJS/pajak yang digunakan.</li><li>Tekan Generate/Recalculate. Sistem memperbarui payroll Draft dan tidak mengubah payroll Paid/Locked.</li><li>Buka detail setiap karyawan. Periksa kehadiran, gaji pokok, lembur, tambahan, potongan, pajak, BPJS, dan gaji bersih.</li><li>Cetak slip, minta tanda tangan Direktur secara fisik, kemudian simpan slip sesuai prosedur perusahaan.</li><li>Admin menekan Paid, mencentang konfirmasi, lalu memilih Kas/Bank perusahaan atau Dana Operasional PIC. Dana PIC harus mencukupi.</li></ol>
    <h3>Komponen, tambahan, kasbon, dan pinjaman</h3>
    <div class="table-responsive"><table class="docs-table"><thead><tr><th>Fitur</th><th>Kapan digunakan</th><th>Dampak</th></tr></thead><tbody><tr><td>Komponen Payroll</td><td>Tunjangan, bonus, potongan, atau pajak satu nominal untuk satu periode.</td><td>Menambah atau mengurangi gaji sesuai jenis.</td></tr><tr><td>Tambahan</td><td>Uang bensin, tambahan proyek, atau pembayaran berdasarkan beberapa tanggal.</td><td>Nominal per tanggal × jumlah tanggal unik.</td></tr><tr><td>Kasbon/Pinjaman</td><td>Dana diberikan kepada karyawan dan dipotong sesuai periode/cicilan.</td><td>Sumber dana berkurang dan piutang karyawan tercatat.</td></tr><tr><td>Lembur</td><td>Pekerjaan melewati batas jam reguler.</td><td>Jam dibayar × tarif, atau nominal borongan.</td></tr></tbody></table></div>
    <p><strong>Gaji bersih = gaji pokok + tunjangan + bonus + lembur + tambahan − potongan − pajak − BPJS.</strong> Setelah data sumber diubah, jalankan Recalculate pada payroll Draft agar snapshot diperbarui.</p>
    <h3>Paid, Locked, dan Unlock</h3><ul><li>Paid mencatat pengeluaran keuangan, mengurangi saldo sumber pembayaran, dan mengunci snapshot.</li><li>Admin tidak dapat membuka kembali payroll yang sudah Paid/Locked.</li><li><strong>Hanya Superadmin</strong> dapat Unlock dengan alasan wajib. Sistem membalik transaksi pembayaran, mengembalikan saldo sumber, lalu mengubah payroll menjadi Draft.</li><li>Setelah koreksi, lakukan Recalculate, periksa ulang, cetak ulang bila perlu, lalu ikuti proses tanda tangan dan Paid kembali.</li></ul>
</article>

<article class="docs-section" id="proyek" data-search="project proyek client vendor supplier kontrak termin dana transaksi pekerja gallery tracking pembayaran">
    <div class="docs-kicker">Bab 6</div><h2>Client, Vendor/Supplier, dan Proyek</h2>
    <ol class="docs-steps"><li>Pastikan client sudah ada di <a href="<?= base_url('pages/clients/index.php'); ?>">Client</a> dan pemasok sudah ada di <a href="<?= base_url('pages/suppliers/index.php'); ?>">Vendor/Supplier</a>.</li><li>Buat proyek melalui <a href="<?= base_url('pages/projects/index.php'); ?>">Project</a>. Isi client, nama, nilai kontrak, lokasi, tanggal, penanggung jawab, dan status Planning/Ongoing/Completed/Cancelled.</li><li>Pada detail proyek, buat termin sesuai kontrak dan jatuh tempo. Catat pembayaran melalui tombol Bayar agar penerimaan dan status termin terhubung ke keuangan.</li><li>Tambahkan pendanaan proyek. Dana perusahaan adalah perpindahan sumber dana, bukan pendapatan proyek; dana personal menjadi hutang/talangan perusahaan.</li><li>Catat pengeluaran/pemasukan dan pembayaran pekerja dari detail proyek. Pilih sumber: akun perusahaan, Dana Operasional PIC, atau talangan personal.</li><li>Unggah bukti serta dokumen/gallery proyek, kemudian pantau nilai kontrak, biaya, pekerja, dan saldo proyek.</li><li>Gunakan <a href="<?= base_url('pages/projects/tracking.php'); ?>">Project Tracking</a> untuk pekerjaan harian, rentang tanggal, tim, dan status Belum Dimulai/Dikerjakan/Selesai.</li></ol>
    <div class="docs-callout warning">Jangan mencatat ulang transaksi proyek di Buku Kas jika transaksi sudah dibuat dari halaman proyek. Modul proyek otomatis menghubungkannya ke keuangan.</div>
</article>

<article class="docs-section" id="produk" data-search="produk penjualan kasir pos barcode kamera antrean keranjang stok custom order dp pelunasan laporan">
    <div class="docs-kicker">Bab 7</div><h2>Produk, Penjualan, Custom Order, dan Kasir/POS</h2>
    <h3>Menyiapkan produk</h3><p>Buka <a href="<?= base_url('pages/products/simple.php'); ?>">Produk</a>, lalu isi foto, kode/barcode, nama, kategori, deskripsi, harga dasar, harga jual, stok, satuan, kontrol stok, dan status. Hanya produk Active yang tampil di POS. Bila kontrol stok aktif, penjualan ditolak jika jumlah melebihi stok dan stok berkurang setelah pembayaran.</p>
    <h3>Transaksi Kasir/POS</h3>
    <ol class="docs-steps"><li>Buka <a href="<?= base_url('pages/products/cashier.php'); ?>">Kasir/POS</a>, gunakan antrean aktif atau buat antrean baru.</li><li>Scan barcode melalui kamera/foto, ketik barcode, atau cari produk secara manual.</li><li>Periksa barang, qty, harga, dan diskon. Antrean disimpan pada browser agar dapat berpindah pelanggan.</li><li>Pilih customer yang sudah ada atau masukkan customer baru, lalu isi channel, metode bayar, akun penerima, dan catatan.</li><li>Tekan <strong>Bayar & Cetak Slip</strong> satu kali. Setelah berhasil, penjualan, transaksi keuangan, snapshot harga, dan stok tersimpan.</li></ol>
    <h3>Penjualan manual dan custom order</h3><p>Menu Penjualan dapat mencatat tanggal, produk, customer, channel, qty, harga, biaya marketplace, ongkir, metode pembayaran, dan akun. Custom Order menyimpan produk pesanan, estimasi biaya, harga penawaran, deadline, DP, pelunasan, dan status Inquiry/Approved/Production/Completed/Cancelled. Istilah Approved pada custom order adalah status pekerjaan operasional, bukan approval Direktur.</p>
    <div class="docs-callout warning">Jangan menekan Bayar dua kali atau mencatat kembali hasil POS sebagai transaksi manual. Jika produk tidak dibatasi stok, nonaktifkan kontrol stok pada master produk.</div>
</article>

<article class="docs-section" id="keuangan" data-search="keuangan saldo total uang cash flow arus kas akun transaksi dana operasional pic hutang piutang talangan reimbursement historis">
    <div class="docs-kicker">Bab 8</div><h2>Keuangan, Hutang/Piutang, dan Dana PIC</h2>
    <h3>Arti angka utama</h3>
    <div class="table-responsive"><table class="docs-table"><thead><tr><th>Istilah</th><th>Arti</th></tr></thead><tbody><tr><td>Total Uang Perusahaan</td><td>Saldo akun Kas/Bank pusat ditambah sisa positif Dana Operasional PIC.</td></tr><tr><td>Pendapatan/Pengeluaran</td><td>Arus uang pada periode yang dipilih.</td></tr><tr><td>Arus Kas Bersih</td><td>Pendapatan dikurangi pengeluaran; bukan laba akuntansi.</td></tr><tr><td>Piutang</td><td>Hak perusahaan yang belum diterima dan belum menjadi uang tersedia.</td></tr><tr><td>Hutang</td><td>Kewajiban perusahaan yang belum dibayar.</td></tr><tr><td>Posisi Bersih</td><td>Total uang + piutang − hutang.</td></tr></tbody></table></div>
    <h3>Mencatat transaksi manual</h3>
    <ol class="docs-steps"><li>Buka <a href="<?= base_url('pages/finance/index.php'); ?>">Keuangan</a> dan pastikan akun sumber/tujuan benar.</li><li>Pilih Transaksi Manual hanya untuk transaksi yang belum dibuat oleh modul lain.</li><li>Pilih pemasukan atau pengeluaran, tanggal, akun, kategori, pihak, proyek bila ada, metode, nominal, keterangan, dan bukti.</li><li>Untuk pengeluaran, pilih sumber Kas/Bank, Dana Operasional PIC, atau Talangan Pihak/PIC.</li><li>Periksa hasilnya di Buku Kas dan saldo akun.</li></ol>
    <div class="docs-callout">Transaksi dari payroll, proyek, POS, aset, hutang/piutang, kasbon, dan pinjaman dibuat dari modul asal agar relasi dan pembalikannya aman.</div>
    <h3>Dana Operasional PIC</h3>
    <div class="docs-flow" aria-label="Alur dana operasional"><div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-bank"></i></div><strong>Berikan Dana</strong><small>Kas/Bank pusat berkurang</small></div><div class="docs-flow-step blue"><div class="docs-flow-icon"><i class="bi bi-wallet2"></i></div><strong>Dipegang PIC</strong><small>Belum menjadi biaya</small></div><div class="docs-flow-step"><div class="docs-flow-icon"><i class="bi bi-receipt"></i></div><strong>Dipakai</strong><small>Saldo PIC turun, biaya tercatat</small></div><div class="docs-flow-step warn"><div class="docs-flow-icon"><i class="bi bi-arrow-return-left"></i></div><strong>Kembalikan Sisa</strong><small>Masuk kembali ke akun pusat</small></div></div>
    <p>Buat Pihak/PIC terlebih dahulu, lalu pilih <a href="<?= base_url('pages/finance/operational_funds.php'); ?>">Dana Operasional</a> → Berikan Dana. Jika pemakaian melebihi saldo, nilai minus menjadi hutang perusahaan kepada PIC. Pemberian dana berikutnya menutup nilai minus lebih dahulu; sisanya menjadi saldo baru.</p>
    <div class="docs-mini-formula"><span>Saldo PIC Rp50.000</span><i class="bi bi-dash-lg"></i><span>Belanja Rp80.000</span><i class="bi bi-arrow-right"></i><span>Saldo −Rp30.000</span><i class="bi bi-arrow-right"></i><span>Hutang ke PIC Rp30.000</span></div>
    <h3>Hutang, piutang, dan dana talangan</h3><p>Buka <a href="<?= base_url('pages/debts/index.php'); ?>">Dana, Hutang & Piutang</a> untuk mencatat kewajiban/tagihan dan pembayarannya. Dana Talangan dipakai ketika uang pribadi sudah digunakan untuk perusahaan. Status Approve pada Dana Talangan adalah validasi data operasional oleh Admin/Superadmin agar hutang otomatis terbentuk—bukan persetujuan Direktur. Pembayaran sebagian mengubah status Partial dan pembayaran penuh menjadi Paid.</p>
    <h3>Catatan historis</h3><p>Opsi Historical tidak memengaruhi saldo dan hanya tersedia bagi Superadmin untuk migrasi/catatan masa lalu. Transaksi berjalan harus memakai mode Posted agar saldo dan laporan bergerak.</p>
</article>

<article class="docs-section" id="aset" data-search="aset inventaris qr scan dokumen maintenance sewa penjualan reminder audit stock opname pinjam kembali perpindahan laporan">
    <div class="docs-kicker">Bab 9</div><h2>Aset dan Inventaris</h2>
    <ol class="docs-steps"><li>Buka <a href="<?= base_url('pages/assets/add.php'); ?>">Tambah Aset</a>. Isi kode, nama, kategori, sumber/perolehan, tanggal, nilai, lokasi, PIC, kondisi, dan status bisnis.</li><li>Dari daftar aset, cetak QR per aset atau semua QR, lalu tempelkan pada barang. Gunakan Scan QR melalui kamera, foto, atau input kode manual.</li><li>Buka detail aset untuk mencatat perpindahan lokasi/PIC, maintenance, dokumen dan tanggal kedaluwarsa, rental serta pembayaran, reminder, audit/stock opname, dan pinjam-pakai/pengembalian.</li><li>Jika aset dijual, catat melalui fitur penjualan aset agar status aset dan pemasukan keuangan terhubung.</li><li>Tekan Sync Reminder setelah menambah/mengubah jadwal agar pengingat aset masuk ke notifikasi.</li><li>Gunakan <a href="<?= base_url('pages/assets/report.php'); ?>">Laporan Aset</a> untuk memeriksa jumlah, nilai, penyusutan, kondisi, dan ekspor.</li></ol>
    <div class="docs-callout warning">Pengeluaran maintenance, penerimaan sewa, dan penjualan aset dapat membentuk transaksi keuangan. Jangan mencatat transaksi yang sama untuk kedua kalinya di Buku Kas.</div>
</article>
<?php endif; ?>

<article class="docs-section" id="laporan" data-search="laporan ringkasan manajemen direktur komisaris total uang saldo cash arus kas posisi bersih grafik periode csv print pdf">
    <div class="docs-kicker">Bab <?= $isReportReader ? '3' : '10'; ?></div><h2>Membaca Ringkasan Manajemen</h2>
    <ol class="docs-steps"><li>Buka <a href="<?= base_url('pages/reports/index.php'); ?>">Ringkasan Manajemen</a>.</li><li>Pilih periode cepat Bulan Ini, Bulan Lalu, atau Tahun Ini. Gunakan tanggal khusus hanya bila diperlukan.</li><li>Mulai dari Kesimpulan Utama. Perhatikan peringatan saldo, arus kas negatif, hutang, piutang, proyek, atau data kosong.</li><li>Baca kartu keuangan: Total Uang adalah uang tersedia; Piutang belum diterima; Hutang belum dibayar; Posisi Bersih adalah uang + piutang − hutang.</li><li>Baca grafik tren untuk arah perubahan, lalu buka rincian hanya jika ingin mengetahui sumber angka.</li><li>Lanjutkan ke ringkasan proyek, payroll, karyawan, produk, dan aset. Beberapa angka adalah posisi saat ini, sedangkan transaksi mengikuti periode.</li><li>Gunakan Download CSV untuk Excel atau Print/PDF untuk arsip. Ekspor tidak mengubah data.</li></ol>
    <div class="docs-branch"><div class="docs-branch-card"><div class="branch-head"><i class="bi bi-cash-stack"></i>Total Uang</div><p>Kas/Bank pusat + sisa dana yang masih dipegang PIC.</p></div><div class="docs-branch-card"><div class="branch-head"><i class="bi bi-arrow-left-right"></i>Arus Kas Bersih</div><p>Uang masuk dikurangi uang keluar pada periode terpilih.</p></div><div class="docs-branch-card"><div class="branch-head"><i class="bi bi-shield-check"></i>Posisi Bersih</div><p>Total uang ditambah piutang lalu dikurangi hutang.</p></div></div>
    <div class="docs-callout">Pemberian dana kepada PIC dan pengembalian sisa hanyalah perpindahan uang internal. Pemakaian Dana PIC-lah yang menjadi pengeluaran. Direktur dan Komisaris melihat laporan yang sama tanpa tombol perubahan data.</div>
</article>

<article class="docs-section" id="notifikasi" data-search="notifikasi reminder lonceng read only baca saja direktur komisaris admin jatuh tempo selesai aktif">
    <div class="docs-kicker">Bab <?= $isReportReader ? '4' : '11'; ?></div><h2>Reminder dan Notifikasi</h2>
    <?php if ($isReportReader): ?>
        <p>Tekan ikon lonceng di kanan atas untuk membaca termin, aset, operasional, HR, dan reminder yang mendekati jatuh tempo. Pada akun Direktur/Komisaris, item notifikasi bersifat <strong>read-only dan tidak dapat diklik</strong>. Tidak ada tombol Selesai, Aktifkan, tambah, edit, atau hapus.</p>
    <?php else: ?>
        <ol class="docs-steps"><li>Tekan ikon lonceng untuk melihat notifikasi aktif dan prioritasnya.</li><li>Buka <a href="<?= base_url('pages/notifications/index.php'); ?>">Reminder/Notifikasi</a> untuk menambah reminder manual.</li><li>Isi jenis, judul, tanggal jatuh tempo, prioritas, dan keterangan.</li><li>Setelah pekerjaan selesai, tekan Selesai. Gunakan Aktifkan jika reminder perlu dibuka kembali.</li><li>Untuk reminder aset, perbarui jadwal di detail aset lalu jalankan Sync Reminder.</li></ol>
        <p>Direktur dan Komisaris hanya melihat daftar pada ikon lonceng. Item tidak dapat diklik dan mereka tidak dapat mengubah status.</p>
    <?php endif; ?>
</article>

<?php if (!$isReportReader): ?>
<article class="docs-section" id="backup" data-search="backup database restore mysql mamp developer superadmin terminal sql keamanan password">
    <div class="docs-kicker">Bab 12</div><h2>Backup, Restore, dan Keamanan Data</h2>
    <span class="docs-tag"><i class="bi bi-shield-lock"></i>Superadmin / Developer</span>
    <p>Backup database harus dilakukan rutin, terutama sebelum migrasi, perubahan besar, atau koreksi massal. Jalankan dari Terminal pada folder proyek:</p>
    <pre class="bg-dark text-white rounded-3 p-3"><code>./scripts/backup_database.sh</code></pre>
    <p>File tersimpan di <code>backups/database</code>. Simpan salinan penting pada media lain yang aman dan lakukan verifikasi backup.</p>
    <div class="docs-callout danger"><strong>Restore dapat mengganti data aktif.</strong> Hanya Superadmin/Developer yang boleh melakukan restore setelah membuat backup terbaru dan memastikan file serta target database benar.</div>
    <ul class="docs-checklist"><li>Jangan berbagi password akun atau password database.</li><li>Gunakan akun masing-masing agar log aktivitas dapat ditelusuri.</li><li>Jangan menghapus file upload langsung dari folder; gunakan aksi pada sistem agar relasinya tetap konsisten.</li><li>Jangan mengubah database manual ketika aplikasi sedang dipakai tanpa backup dan rencana koreksi.</li></ul>
</article>
<?php endif; ?>

<article class="docs-section" id="masalah" data-search="masalah error gagal login laporan kosong payroll tidak berubah absensi import saldo stok pos checklist harian bantuan">
    <div class="docs-kicker">Bab <?= $isReportReader ? '5' : '13'; ?></div><h2>Pemecahan Masalah dan Checklist</h2>
    <div class="table-responsive"><table class="docs-table"><thead><tr><th>Masalah</th><th>Yang harus dilakukan</th></tr></thead><tbody>
        <tr><td>Tidak dapat login</td><td>Periksa username/email, password, dan status akun. Minta Superadmin memeriksa akun bila masih gagal.</td></tr>
        <tr><td>Menu tidak tersedia / akses ditolak</td><td>Akun hanya menampilkan fitur sesuai peran. Direktur/Komisaris hanya memiliki laporan, panduan, dan notifikasi read-only.</td></tr>
        <tr><td>Laporan kosong</td><td>Periksa periode. Coba Bulan Ini, Tahun Ini, atau rentang lebih luas. Data posisi seperti aset/karyawan dapat berbeda dari data transaksi periode.</td></tr>
        <?php if (!$isReportReader): ?>
        <tr><td>File absensi tidak terbaca</td><td>Pastikan CSV/XLS/XLSX maksimal 10 MB, periode tersedia, User ID/nama cocok, baris tanggal benar, dan jam memakai format 24 jam.</td></tr>
        <tr><td>Payroll belum berubah</td><td>Pastikan payroll masih Draft, data berada pada periode yang benar, lalu jalankan Generate/Recalculate.</td></tr>
        <tr><td>Pembayaran payroll gagal</td><td>Periksa konfirmasi dan akun sumber. Jika memakai Dana Operasional PIC, saldonya wajib mencukupi gaji bersih.</td></tr>
        <tr><td>Saldo PIC negatif</td><td>Ini berarti perusahaan berhutang kepada PIC. Berikan dana dari akun perusahaan; sistem menutup nilai minus terlebih dahulu.</td></tr>
        <tr><td>Produk tidak muncul di POS</td><td>Pastikan status Active, kode/barcode benar, dan produk tersimpan.</td></tr>
        <tr><td>Stok tidak cukup</td><td>Kurangi qty, koreksi stok produk, atau nonaktifkan kontrol stok hanya jika produk memang tidak dibatasi.</td></tr>
        <tr><td>Angka keuangan ganda</td><td>Periksa apakah transaksi otomatis dari modul asal dicatat ulang sebagai transaksi manual.</td></tr>
        <?php endif; ?>
    </tbody></table></div>
    <?php if (!$isReportReader): ?>
        <h3>Checklist Admin sebelum selesai bekerja</h3><ul class="docs-checklist"><li>Periksa notifikasi jatuh tempo dan data yang perlu ditinjau.</li><li>Pastikan bukti, tanggal, kategori, nominal, dan sumber dana setiap transaksi sudah benar.</li><li>Pastikan transaksi otomatis tidak dicatat ulang.</li><li>Periksa payroll Draft sebelum dicetak dan jangan tekan Paid sebelum slip ditandatangani.</li><li>Periksa Buku Kas, saldo akun, Dana PIC, hutang, dan piutang bila ada transaksi hari itu.</li><li>Logout setelah pekerjaan selesai.</li></ul>
    <?php else: ?>
        <h3>Checklist Direktur/Komisaris</h3><ul class="docs-checklist"><li>Pilih periode laporan yang ingin dibaca.</li><li>Baca Kesimpulan Utama terlebih dahulu.</li><li>Bandingkan Total Uang, arus kas, hutang, dan piutang.</li><li>Buka rincian hanya jika membutuhkan sumber angka.</li><li>Logout setelah selesai.</li></ul>
    <?php endif; ?>
    <p class="mt-3">Jika masalah tetap terjadi, catat halaman, periode, nama data terkait, waktu kejadian, dan pesan error lengkap. Sertakan tangkapan layar bila memungkinkan agar Superadmin dapat menelusurinya.</p>
</article>
</div>
</div>
</main>

<script>
(function(){
 const search=document.getElementById('docsSearch'),sections=[...document.querySelectorAll('.docs-section')],meta=document.getElementById('docsSearchMeta'),empty=document.getElementById('docsNoResults'),nav=document.getElementById('docsNav');
 function normalize(v){return String(v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'')}
 function runSearch(){const q=normalize(search.value.trim());let shown=0;sections.forEach(section=>{const hay=normalize(section.dataset.search+' '+section.innerText);const visible=!q||q.split(/\s+/).every(word=>hay.includes(word));section.classList.toggle('is-hidden',!visible);if(visible)shown++});empty.style.display=shown?'none':'block';meta.textContent=q?(shown+' bab ditemukan untuk “'+search.value.trim()+'”'):'Ketik kata untuk mencari isi panduan.';document.querySelectorAll('#docsNav a').forEach(a=>{const target=document.querySelector(a.getAttribute('href'));a.style.display=target&&!target.classList.contains('is-hidden')?'':'none'})}
 search.addEventListener('input',runSearch);document.getElementById('docsTocToggle').addEventListener('click',()=>nav.classList.toggle('mobile-open'));document.querySelectorAll('#docsNav a').forEach(a=>a.addEventListener('click',()=>{document.querySelectorAll('#docsNav a').forEach(x=>x.classList.remove('active'));a.classList.add('active');nav.classList.remove('mobile-open')}));runSearch();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
