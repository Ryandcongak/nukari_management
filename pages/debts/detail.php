<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Detail Hutang & Piutang - Nukari';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT 
        d.*,
        p.project_name,
        p.project_code,
        c.client_name
    FROM debts d
    LEFT JOIN projects p ON p.id = d.project_id
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE d.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$debt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$debt) {
    redirect('pages/debts/index.php?error=Data tidak ditemukan');
}

finance_update_debt_status($pdo, $id);

$stmt = $pdo->prepare("
    SELECT 
        dp.*,
        t.transaction_code,
        t.type AS transaction_type,
        t.description AS transaction_description,
        fa.account_name,
        fa.account_type,
        u.full_name
    FROM debt_payments dp
    LEFT JOIN transactions t ON t.id = dp.transaction_id
    LEFT JOIN finance_accounts fa ON fa.id = dp.account_id
    LEFT JOIN users u ON u.id = dp.created_by
    WHERE dp.debt_id = :id
    ORDER BY dp.payment_date DESC, dp.id DESC
");
$stmt->execute([':id' => $id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM debts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$debt = array_merge($debt, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

$remaining = max((float)$debt['amount'] - (float)$debt['paid_amount'], 0);
$sourceLink = debt_get_source_link($debt);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Detail Hutang & Piutang</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($debt['debt_code']); ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/debts/index.php'); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
            <?php if ($sourceLink): ?>
                <a href="<?= htmlspecialchars($sourceLink['url']); ?>" class="btn btn-outline-info">
                    <i class="bi bi-box-arrow-up-right me-1"></i> <?= htmlspecialchars($sourceLink['label']); ?>
                </a>
            <?php endif; ?>
            <?php if (debt_can_be_paid($debt)): ?>
                <a href="<?= base_url('pages/debts/pay.php?id=' . $debt['id']); ?>" class="btn btn-nukari">
                    <i class="bi bi-cash me-1"></i> Bayar
                </a>
            <?php elseif (debt_is_project_term_receivable($debt) && $debt['status'] !== 'paid'): ?>
                <span class="btn btn-outline-secondary disabled" title="Piutang termin dibayar dari Detail Project">
                    <i class="bi bi-info-circle me-1"></i> Monitoring Piutang
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_GET['message'])): ?>
        <div class="alert alert-success rounded-4"><?= htmlspecialchars($_GET['message']); ?></div>
    <?php endif; ?>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Nominal</div>
                <div class="fs-4 fw-bold"><?= rupiah($debt['amount']); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Terbayar</div>
                <div class="fs-4 fw-bold text-success"><?= rupiah($debt['paid_amount']); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Sisa</div>
                <div class="fs-4 fw-bold text-danger"><?= rupiah($remaining); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Status</div>
                <div class="mt-2"><?= debt_status_badge_html($debt['status'], $debt['due_date']); ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="panel h-100">
                <h5 class="fw-bold mb-3">Informasi Utama</h5>
                <table class="table table-borderless align-middle">
                    <tr><td class="text-muted">Jenis</td><td><?= debt_type_badge_html($debt['type']); ?></td></tr>
                    <tr><td class="text-muted">Sumber</td><td><?= debt_source_badge($debt['source_module']); ?></td></tr>
                    <tr><td class="text-muted">Source Detail</td><td><?= htmlspecialchars($debt['source_table'] ?: '-'); ?> <?= $debt['source_id'] ? '#' . (int)$debt['source_id'] : ''; ?></td></tr>
                    <tr><td class="text-muted">Kode Referensi</td><td><?= htmlspecialchars($debt['reference_code'] ?: '-'); ?></td></tr>
                    <tr><td class="text-muted">Pihak</td><td class="fw-semibold"><?= htmlspecialchars($debt['related_name']); ?><br><small class="text-muted"><?= htmlspecialchars($debt['related_type']); ?> <?= $debt['related_id'] ? '#' . (int)$debt['related_id'] : ''; ?></small></td></tr>
                    <tr><td class="text-muted">Project</td><td><?= htmlspecialchars($debt['project_name'] ?: '-'); ?></td></tr>
                    <tr><td class="text-muted">Client Project</td><td><?= htmlspecialchars($debt['client_name'] ?: '-'); ?></td></tr>
                    <tr><td class="text-muted">Kategori</td><td><?= htmlspecialchars($debt['category'] ?: '-'); ?></td></tr>
                    <tr><td class="text-muted">Tanggal Dibuat</td><td><?= $debt['issue_date'] ? date('d M Y', strtotime($debt['issue_date'])) : '-'; ?></td></tr>
                    <tr><td class="text-muted">Jatuh Tempo</td><td><?= $debt['due_date'] ? date('d M Y', strtotime($debt['due_date'])) : '-'; ?></td></tr>
                    <tr><td class="text-muted">Metode</td><td><?= debt_payment_method_label($debt['payment_method'] ?? ''); ?></td></tr>
                    <tr><td class="text-muted">Bukti</td><td><?= !empty($debt['proof_file']) ? '<a target="_blank" href="' . base_url($debt['proof_file']) . '">Lihat File</a>' : '-'; ?></td></tr>
                </table>

                <?php if (!empty($debt['description'])): ?>
                    <div class="alert alert-light rounded-4 mb-0">
                        <?= nl2br(htmlspecialchars($debt['description'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="panel h-100">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Riwayat Pembayaran & Transaksi</h5>
                        <div class="text-muted small">Di sini Anda bisa cek apakah pembayaran sudah masuk ke transaksi keuangan.</div>
                    </div>
                    <?php if (debt_can_be_paid($debt)): ?>
                        <a href="<?= base_url('pages/debts/pay.php?id=' . $debt['id']); ?>" class="btn btn-sm btn-outline-success">Tambah Pembayaran</a>
                    <?php elseif (debt_is_project_term_receivable($debt) && $debt['status'] !== 'paid' && $sourceLink): ?>
                        <a href="<?= htmlspecialchars($sourceLink['url']); ?>" class="btn btn-sm btn-outline-info">Bayar dari Project</a>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Nominal</th>
                                <th>Metode</th>
                                <th>Akun</th>
                                <th>Transaksi</th>
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
                                    <td><?= debt_payment_method_label($p['payment_method']); ?></td>
                                    <td>
                                        <?php if (!empty($p['account_name'])): ?>
                                            <span class="fw-semibold"><?= htmlspecialchars($p['account_name']); ?></span><br>
                                            <small class="text-muted"><?= htmlspecialchars(ucfirst($p['account_type'])); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['transaction_id'])): ?>
                                            <span class="badge rounded-pill bg-success">Tercatat</span>
                                            <div class="small text-muted"><?= htmlspecialchars($p['transaction_code'] ?: ('#' . $p['transaction_id'])); ?></div>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-warning text-dark">Belum Ada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= !empty($p['proof_file']) ? '<a target="_blank" href="' . base_url($p['proof_file']) . '">Lihat</a>' : '-'; ?></td>
                                    <td><?= htmlspecialchars($p['notes'] ?: '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Belum ada pembayaran.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($debt['source_module'] === 'project_term'): ?>
                    <div class="alert alert-info rounded-4 mb-0 mt-3">
                        Data ini berasal dari termin project. Untuk mencegah input ganda, pembayaran termin hanya dilakukan dari Detail Project. Halaman ini berfungsi sebagai monitoring piutang.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
