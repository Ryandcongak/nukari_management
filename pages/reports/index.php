<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Pusat Laporan - Nukari Management';

function report_count(PDO $pdo, string $table): int {
    if (!preg_match('/^[a-z_]+$/', $table)) return 0;
    try { return (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

$reports = [
    ['Keuangan', 'Ringkasan akun, arus kas, pemasukan dan pengeluaran.', 'bi-cash-stack', 'pages/finance/index.php', report_count($pdo, 'transactions') . ' transaksi'],
    ['Proyek', 'Nilai kontrak, termin, biaya pekerja dan transaksi proyek.', 'bi-kanban', 'pages/projects/index.php', report_count($pdo, 'projects') . ' proyek'],
    ['Payroll', 'Ringkasan gaji, komponen, pinjaman dan status pembayaran.', 'bi-wallet2', 'pages/payroll/index.php', report_count($pdo, 'payrolls') . ' payroll'],
    ['HR Analytics', 'Kehadiran, cuti, keterlambatan dan evaluasi karyawan.', 'bi-people', 'pages/hr/analytics.php', report_count($pdo, 'employees') . ' karyawan'],
    ['Aset', 'Nilai aset, penyusutan, maintenance dan pemasukan sewa.', 'bi-box-seam', 'pages/assets/report.php', report_count($pdo, 'assets') . ' aset'],
    ['Produk & Stok', 'Stok, produksi, HPP, penjualan dan kontribusi program.', 'bi-recycle', 'pages/products/report.php', report_count($pdo, 'products') . ' produk'],
    ['Hutang & Piutang', 'Posisi kewajiban, tagihan, jatuh tempo dan pembayaran.', 'bi-journal-text', 'pages/debts/index.php', report_count($pdo, 'debts') . ' catatan'],
    ['Dana Talangan', 'Pengajuan, persetujuan dan pembayaran reimbursement.', 'bi-receipt', 'pages/reimbursements/index.php', report_count($pdo, 'reimbursements') . ' pengajuan'],
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header"><h1 class="fw-bold mb-1">Pusat Laporan</h1><p class="text-muted mb-0">Akses ringkasan dan laporan operasional dari satu tempat.</p></div>
    <div class="row g-4">
        <?php foreach ($reports as [$title,$description,$icon,$url,$meta]): ?>
        <div class="col-md-6 col-xl-4"><div class="panel h-100 d-flex flex-column">
            <div class="d-flex align-items-center gap-3 mb-3"><div class="fs-2 text-success"><i class="bi <?= htmlspecialchars($icon); ?>"></i></div><div><h5 class="fw-bold mb-0"><?= htmlspecialchars($title); ?></h5><small class="text-muted"><?= htmlspecialchars($meta); ?></small></div></div>
            <p class="text-muted flex-grow-1"><?= htmlspecialchars($description); ?></p>
            <a class="btn btn-outline-success" href="<?= base_url($url); ?>">Buka laporan <i class="bi bi-arrow-right ms-1"></i></a>
        </div></div>
        <?php endforeach; ?>
    </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
