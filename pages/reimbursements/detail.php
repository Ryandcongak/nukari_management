<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Detail Dana Talangan - Nukari';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT 
        r.*,
        e.full_name AS employee_name,
        p.project_name,
        d.debt_code,
        d.status AS debt_status,
        d.paid_amount AS debt_paid_amount
    FROM reimbursements r
    LEFT JOIN employees e ON e.id = r.employee_id
    LEFT JOIN projects p ON p.id = r.project_id
    LEFT JOIN debts d ON d.id = r.debt_id
    WHERE r.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    redirect('pages/reimbursements/index.php?error=Data tidak ditemukan');
}

if (!empty($r['debt_id'])) {
    finance_sync_reimbursement_from_debt($pdo, $id);
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
}

$payments = [];

$stmt = $pdo->prepare("
    SELECT 
        rp.id,
        rp.payment_date,
        rp.amount,
        rp.posting_mode,
        rp.historical_reason,
        rp.payment_method,
        rp.proof_file,
        rp.notes,
        rp.debt_payment_id,
        rp.transaction_id,
        t.transaction_code,
        'reimbursement' AS payment_source
    FROM reimbursement_payments rp
    LEFT JOIN transactions t ON t.id = rp.transaction_id
    WHERE rp.reimbursement_id = :id
    ORDER BY rp.payment_date DESC, rp.id DESC
");
$stmt->execute([':id' => $id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($r['debt_id'])) {
    $stmt = $pdo->prepare("
        SELECT
            dp.id,
            dp.payment_date,
            dp.amount,
            dp.posting_mode,
            dp.historical_reason,
            dp.payment_method,
            dp.proof_file,
            dp.notes,
            dp.id AS debt_payment_id,
            dp.transaction_id,
            t.transaction_code,
            'debt' AS payment_source
        FROM debt_payments dp
        LEFT JOIN transactions t ON t.id = dp.transaction_id
        WHERE dp.debt_id = :debt_id
          AND NOT EXISTS (
              SELECT 1
              FROM reimbursement_payments rp
              WHERE rp.debt_payment_id = dp.id
              LIMIT 1
          )
        ORDER BY dp.payment_date DESC, dp.id DESC
    ");
    $stmt->execute([':debt_id' => (int)$r['debt_id']]);
    $debtPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payments = array_merge($payments, $debtPayments);

    usort($payments, function ($a, $b) {
        $dateCompare = strcmp($b['payment_date'], $a['payment_date']);
        if ($dateCompare !== 0) return $dateCompare;
        return (int)$b['id'] <=> (int)$a['id'];
    });
}

$remaining = max((float)$r['amount'] - (float)$r['paid_amount'], 0);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Detail Dana Talangan</h1>
            <p class="text-muted mb-0"><?= rmb_e($r['reimbursement_code']); ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/reimbursements/index.php'); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
            <?php if ($r['status'] === 'pending'): ?>
                <a href="<?= base_url('pages/reimbursements/approve.php?id=' . $r['id']); ?>" class="btn btn-nukari">
                    <i class="bi bi-check-circle me-1"></i> Approve
                </a>
            <?php endif; ?>
            <?php if (in_array($r['status'], ['approved','partial'], true)): ?>
                <a href="<?= base_url('pages/reimbursements/pay.php?id=' . $r['id']); ?>" class="btn btn-nukari">
                    <i class="bi bi-cash me-1"></i> Bayar
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_GET['message'])): ?>
        <div class="alert alert-success rounded-4"><?= rmb_e($_GET['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-4"><?= rmb_e($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Nominal</div><div class="fs-4 fw-bold"><?= rupiah($r['amount']); ?></div></div></div>
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Terbayar</div><div class="fs-4 fw-bold text-success"><?= rupiah($r['paid_amount']); ?></div></div></div>
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Sisa</div><div class="fs-4 fw-bold text-danger"><?= rupiah($remaining); ?></div></div></div>
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Status</div><div class="mt-2"><?= rmb_status_badge($r['status']); ?></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="panel h-100">
                <h5 class="fw-bold mb-3">Informasi Utama</h5>
                <table class="table table-borderless align-middle">
                    <tr><td class="text-muted">Pemohon</td><td class="fw-semibold"><?= rmb_e($r['employee_name'] ?: $r['requester_name']); ?></td></tr>
                    <tr><td class="text-muted">Project</td><td><?= rmb_e($r['project_name'] ?: '-'); ?></td></tr>
                    <tr><td class="text-muted">Kategori</td><td><?= rmb_e($r['category'] ?: '-'); ?></td></tr>
                    <tr><td class="text-muted">Tanggal</td><td><?= date('d M Y', strtotime($r['request_date'])); ?></td></tr>
                    <tr><td class="text-muted">Jatuh Tempo</td><td><?= $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '-'; ?></td></tr>
                    <tr><td class="text-muted">Bukti</td><td><?= $r['proof_file'] ? '<a target="_blank" href="' . base_url($r['proof_file']) . '">Lihat File</a>' : '-'; ?></td></tr>
                    <tr><td class="text-muted">Hutang</td><td><?= $r['debt_id'] ? '<a href="' . base_url('pages/debts/detail.php?id=' . $r['debt_id']) . '">' . rmb_e($r['debt_code']) . '</a>' : '-'; ?></td></tr>
                    <tr><td class="text-muted">Approval</td><td><?= $r['approved_at'] ? date('d M Y H:i', strtotime($r['approved_at'])) : '-'; ?></td></tr>
                </table>

                <?php if ($r['description']): ?>
                    <div class="alert alert-light rounded-4"><?= nl2br(rmb_e($r['description'])); ?></div>
                <?php endif; ?>

                <?php if ($r['approval_notes']): ?>
                    <div class="alert alert-info rounded-4 mb-0">
                        <strong>Catatan Approval:</strong><br>
                        <?= nl2br(rmb_e($r['approval_notes'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="panel h-100">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Riwayat Pembayaran</h5>
                        <div class="text-muted small">Menampilkan pembayaran dari Dana Talangan dan Hutang & Piutang.</div>
                    </div>
                    <?php if (in_array($r['status'], ['approved','partial'], true)): ?>
                        <a href="<?= base_url('pages/reimbursements/pay.php?id=' . $r['id']); ?>" class="btn btn-sm btn-outline-success">Tambah Pembayaran</a>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Nominal</th>
                                <th>Metode</th>
                                <th>Transaksi</th>
                                <th>Sumber</th>
                                <th>Bukti</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($payments): ?>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($p['payment_date'])); ?></td>
                                    <td class="fw-semibold"><?= rupiah($p['amount']); ?></td>
                                    <td><?= rmb_payment_method_label($p['payment_method']); ?></td>
                                    <td>
                                        <?php if (($p['posting_mode'] ?? 'posted') === 'historical'): ?><span class="badge rounded-pill bg-secondary">Historis · tanpa saldo</span><?php else: ?><span class="badge rounded-pill bg-success">Terposting</span><?php endif; ?><br>
                                        <?php if (!empty($p['transaction_id'])): ?>
                                            <div class="small text-muted"><?= rmb_e($p['transaction_code'] ?: ('#' . $p['transaction_id'])); ?></div>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-warning text-dark">Belum</span>
                                        <?php endif; ?>
                                        <?php if (!empty($p['historical_reason'])): ?><div class="small text-muted"><?= rmb_e($p['historical_reason']); ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (($p['payment_source'] ?? '') === 'debt'): ?>
                                            <span class="badge rounded-pill bg-primary-subtle text-primary">Hutang & Piutang</span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-success-subtle text-success">Dana Talangan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= !empty($p['proof_file']) ? '<a target="_blank" href="' . base_url($p['proof_file']) . '">Lihat</a>' : '-'; ?></td>
                                    <td><?= rmb_e($p['notes'] ?: '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Belum ada pembayaran.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 d-flex gap-2 flex-wrap">
                    <?php if (!in_array($r['status'], ['partial','paid'], true)): ?>
                        <a href="<?= base_url('pages/reimbursements/edit.php?id=' . $r['id']); ?>" class="btn btn-outline-secondary">Edit</a>
                        <a href="<?= base_url('pages/reimbursements/delete.php?id=' . $r['id']); ?>" onclick="return confirm('Yakin ingin menghapus data ini?')" class="btn btn-outline-danger">Hapus</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
