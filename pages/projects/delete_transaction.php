<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT pt.*, p.project_name
    FROM project_transactions pt
    JOIN projects p ON pt.project_id = p.id
    WHERE pt.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    redirect('pages/projects/index.php');
}

$project_id = $transaction['project_id'];

if (in_array($transaction['category'], ['Modal Project', 'Termin Pembayaran'])) {
    redirect('pages/projects/detail.php?id=' . $project_id . '&tab=transactions');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM project_transaction_receipts
    WHERE transaction_id = :transaction_id
    ORDER BY id DESC
");
$stmt->execute([':transaction_id' => $id]);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Hapus Transaksi Project - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        if (function_exists('finance_clear_project_expense_links')) {
            finance_clear_project_expense_links($pdo, 'project_transactions', (int)$id);
        }

        $stmt = $pdo->prepare("
            DELETE FROM project_transactions
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $pdo->commit();
        foreach ($receipts as $receipt) upload_delete_file($receipt['image_path'] ?? null);

        redirect('pages/projects/detail.php?id=' . $project_id . '&tab=transactions');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Gagal menghapus transaksi project.';
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1 text-danger">Hapus Transaksi</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($transaction['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=transactions'); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-8">
            <div class="panel">

                <h5 class="fw-bold mb-3">Konfirmasi Hapus Transaksi</h5>

                <div class="alert alert-danger rounded-4">
                    <ul class="mb-0">
                        <li>Transaksi ini akan dihapus permanen.</li>
                        <li>Semua foto bukti transaksi juga akan ikut terhapus.</li>
                        <li>Saldo dan cash flow project akan berubah.</li>
                        <li>Data yang sudah dihapus tidak dapat dikembalikan.</li>
                    </ul>
                </div>

                <div class="border rounded-4 p-3 mb-4 bg-light">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <small class="text-muted d-block">Tanggal</small>
                            <div class="fw-semibold">
                                <?= date('d M Y H:i', strtotime($transaction['created_at'])); ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted d-block">Jenis</small>
                            <div class="fw-semibold">
                                <?= $transaction['transaction_type'] === 'income' ? 'Pemasukan' : 'Pengeluaran'; ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted d-block">Kategori</small>
                            <div class="fw-semibold">
                                <?= htmlspecialchars($transaction['category'] ?? '-'); ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted d-block">Jumlah</small>
                            <div class="fw-bold <?= $transaction['transaction_type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                                <?= rupiah($transaction['amount']); ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted d-block">Sumber Dana</small>
                            <div class="fw-semibold">
                                <?= htmlspecialchars($transaction['funding_source'] ?? '-'); ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted d-block">Nama Personal</small>
                            <div class="fw-semibold">
                                <?= htmlspecialchars($transaction['person_name'] ?? '-'); ?>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <small class="text-muted d-block">Deskripsi</small>
                            <div>
                                <?= htmlspecialchars($transaction['description'] ?? '-'); ?>
                            </div>
                        </div>

                    </div>

                </div>

                <?php if (!empty($receipts)): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3">Bukti Transaksi yang Akan Terhapus</h6>

                        <div class="row g-3">
                            <?php foreach ($receipts as $receipt): ?>
                                <div class="col-md-3 col-6">
                                    <a href="<?= base_url($receipt['image_path']); ?>" target="_blank">
                                        <img
                                            src="<?= base_url($receipt['image_path']); ?>"
                                            alt="Bukti Transaksi"
                                            class="img-fluid rounded-4 border"
                                            style="height: 120px; width: 100%; object-fit: cover;">
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i> Hapus Permanen
                    </button>

                    <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=transactions'); ?>" class="btn btn-light">
                        Batal
                    </a>
                </form>

            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-info-circle me-1"></i> Catatan
                </h5>

                <p class="small text-muted mb-0">
                    File gambar akan dihapus dari folder upload, dan data lampiran akan otomatis hilang karena terhubung dengan transaksi.
                </p>
            </div>
        </div>

    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
