<?php
require_once __DIR__ . '/../../includes/auth.php';

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT project_name, contract_value
    FROM projects
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM project_payment_terms
    WHERE project_id = :id
    ORDER BY due_date ASC
");
$stmt->execute([':id' => $project_id]);
$terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalTerms = 0;
$totalPaid = 0;
$totalUnpaid = 0;
$totalOverdue = 0;

foreach ($terms as $t) {
    $status = $t['status'];

    if ($status === 'unpaid' && strtotime($t['due_date']) < time()) {
        $status = 'overdue';
    }

    $totalTerms += (float) $t['amount'];

    if ($status === 'paid') {
        $totalPaid += (float) $t['amount'];
    } elseif ($status === 'overdue') {
        $totalOverdue += (float) $t['amount'];
    } else {
        $totalUnpaid += (float) $t['amount'];
    }
}

$progress = $totalTerms > 0 ? ($totalPaid / $totalTerms) * 100 : 0;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Termin Pembayaran</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p>
        </div>

        <div class="d-flex gap-2">
            <a href="<?= base_url('pages/projects/add_term.php?project_id=' . $project_id); ?>" class="btn btn-nukari">
                <i class="bi bi-plus-circle me-1"></i> Tambah Termin
            </a>

            <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Total Termin</div>
                <div class="stat-number"><?= rupiah($totalTerms); ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Sudah Dibayar</div>
                <div class="stat-number"><?= rupiah($totalPaid); ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Belum Dibayar</div>
                <div class="stat-number"><?= rupiah($totalUnpaid); ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Overdue</div>
                <div class="stat-number"><?= rupiah($totalOverdue); ?></div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">Progress Pembayaran</h5>

        <div class="progress" style="height: 20px;">
            <div class="progress-bar bg-success" style="width: <?= $progress; ?>%">
                <?= round($progress, 1); ?>%
            </div>
        </div>

        <small class="text-muted">
            Progress dihitung dari total termin yang sudah dibayar.
        </small>
    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">
            <i class="bi bi-info-circle me-1"></i> Panduan Termin
        </h5>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="border rounded-4 p-3 h-100">
                    <div class="fw-bold text-success mb-1">Paid</div>
                    <small class="text-muted">Termin sudah dibayar dan otomatis masuk ke pendapatan project.</small>
                </div>
            </div>

            <div class="col-md-4">
                <div class="border rounded-4 p-3 h-100">
                    <div class="fw-bold text-warning mb-1">Unpaid</div>
                    <small class="text-muted">Termin belum dibayar dan masih harus ditagih ke client.</small>
                </div>
            </div>

            <div class="col-md-4">
                <div class="border rounded-4 p-3 h-100">
                    <div class="fw-bold text-danger mb-1">Overdue</div>
                    <small class="text-muted">Termin sudah lewat jatuh tempo dan harus segera ditindaklanjuti.</small>
                </div>
            </div>
        </div>

        <hr>

        <p class="text-muted small mb-0">
            Jika termin sudah <strong>Paid</strong>, saat diedit atau dihapus, transaksi income terkait juga akan ikut disesuaikan.
        </p>
    </div>

    <div class="panel">
        <h5 class="fw-bold mb-3">Daftar Termin</h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Termin</th>
                        <th>Persentase</th>
                        <th>Jumlah</th>
                        <th>PIC</th>
                        <th>Jatuh Tempo</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($terms): ?>
                        <?php foreach ($terms as $t): ?>
                            <?php
                            $status = $t['status'];
                            if ($status === 'unpaid' && strtotime($t['due_date']) < time()) {
                                $status = 'overdue';
                            }
                            ?>

                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($t['term_name']); ?></div>
                                    <?php if (!empty($t['notes'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars($t['notes']); ?></small>
                                    <?php endif; ?>
                                </td>

                                <td><?= $t['percentage'] ? htmlspecialchars($t['percentage']) . '%' : '-'; ?></td>

                                <td><?= rupiah($t['amount']); ?></td>
                                <td><?= htmlspecialchars($t['pic_name'] ?: '-'); ?></td>

                                <td><?= date('d M Y', strtotime($t['due_date'])); ?></td>

                                <td>
                                    <?php if ($status === 'paid'): ?>
                                        <span class="badge rounded-pill badge-soft-green">Paid</span>
                                    <?php elseif ($status === 'overdue'): ?>
                                        <span class="badge rounded-pill badge-soft-red">Overdue</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-orange">Unpaid</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-end">
                                    <?php if ($t['status'] !== 'paid'): ?>
                                        <a href="<?= base_url('pages/projects/pay_term.php?id=' . $t['id']); ?>" class="btn btn-sm btn-success mb-1">
                                            Bayar
                                        </a>
                                    <?php endif; ?>

                                    <a href="<?= base_url('pages/projects/edit_term.php?id=' . $t['id']); ?>" class="btn btn-sm btn-outline-primary mb-1">
                                        Edit
                                    </a>

                                    <a
                                        href="<?= base_url('pages/projects/delete_term.php?id=' . $t['id']); ?>"
                                        class="btn btn-sm btn-outline-danger mb-1">
                                        Hapus
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="table-light fw-bold">
                            <td colspan="2">Total</td>
                            <td><?= rupiah($totalTerms); ?></td>
                            <td colspan="3">
                                Dibayar: <?= rupiah($totalPaid); ?> |
                                Belum: <?= rupiah($totalUnpaid); ?> |
                                Overdue: <?= rupiah($totalOverdue); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Belum ada termin pembayaran.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
