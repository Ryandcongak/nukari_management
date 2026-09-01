<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Akun Keuangan - Nukari';
f_recalculate_all_accounts($pdo);

$accounts = $pdo->query("
    SELECT *
    FROM finance_accounts
    ORDER BY status ASC, is_default DESC, account_type ASC, account_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Akun Keuangan</h1>
            <p class="text-muted mb-0">Kelola kas, bank, QRIS, dan akun pembayaran perusahaan.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/finance/index.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
            <a href="<?= base_url('pages/finance/account_add.php'); ?>" class="btn btn-nukari"><i class="bi bi-plus-circle me-1"></i> Tambah Akun</a>
        </div>
    </div>

    <?php if (!empty($_GET['message'])): ?><div class="alert alert-success rounded-4"><?= f_e($_GET['message']); ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger rounded-4"><?= f_e($_GET['error']); ?></div><?php endif; ?>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama Akun</th>
                        <th>Jenis</th>
                        <th>Bank / Nomor</th>
                        <th>Saldo Awal</th>
                        <th>Saldo Saat Ini</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($accounts): ?>
                    <?php foreach ($accounts as $a): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= f_e($a['account_code']); ?></div><?= $a['is_default'] ? '<span class="badge rounded-pill bg-secondary">Default</span>' : ''; ?></td>
                            <td>
                                <div class="fw-semibold"><?= f_e($a['account_name']); ?></div>
                                <small class="text-muted"><?= f_e($a['notes'] ?: '-'); ?></small>
                            </td>
                            <td><?= f_account_type_badge($a['account_type']); ?></td>
                            <td><?= f_e($a['bank_name'] ?: '-'); ?><br><small class="text-muted"><?= f_e($a['account_number'] ?: '-'); ?></small></td>
                            <td><?= rupiah($a['opening_balance']); ?></td>
                            <td class="fw-bold <?= (float)$a['current_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?= rupiah($a['current_balance']); ?></td>
                            <td><?= $a['status'] === 'active' ? '<span class="badge rounded-pill bg-success">Active</span>' : '<span class="badge rounded-pill bg-secondary">Inactive</span>'; ?></td>
                            <td class="text-end">
                                <a href="<?= base_url('pages/finance/account_edit.php?id=' . $a['id']); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">Belum ada akun keuangan.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
