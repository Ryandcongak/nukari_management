<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Buku Kas - Nukari';

[$month, $startDate, $endDate] = f_month_range($_GET['month'] ?? date('Y-m'));
$type = trim($_GET['type'] ?? '');
$accountId = (int)($_GET['account_id'] ?? 0);
$source = trim($_GET['source'] ?? '');
$q = trim($_GET['q'] ?? '');

$where = ["t.transaction_date BETWEEN :start AND :end"];
$params = [':start' => $startDate, ':end' => $endDate];

if ($type !== '') {
    $where[] = "t.type = :type";
    $params[':type'] = $type;
}

if ($accountId > 0) {
    $where[] = "t.account_id = :account_id";
    $params[':account_id'] = $accountId;
}

if ($source !== '') {
    $where[] = "t.source_module = :source";
    $params[':source'] = $source;
}

if ($q !== '') {
    $where[] = "(t.transaction_code LIKE :q_code OR t.reference_code LIKE :q_reference OR t.transaction_name LIKE :q_name OR t.description LIKE :q_description OR c.category_name LIKE :q_category OR p.project_name LIKE :q_project)";
    $searchValue = '%' . $q . '%';
    $params[':q_code'] = $searchValue;
    $params[':q_reference'] = $searchValue;
    $params[':q_name'] = $searchValue;
    $params[':q_description'] = $searchValue;
    $params[':q_category'] = $searchValue;
    $params[':q_project'] = $searchValue;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        t.*,
        c.category_name,
        a.account_name,
        p.project_name
    FROM transactions t
    LEFT JOIN transaction_categories c ON c.id = t.category_id
    LEFT JOIN finance_accounts a ON a.id = t.account_id
    LEFT JOIN projects p ON p.id = t.project_id
    $whereSql
    ORDER BY t.transaction_date DESC, t.id DESC
");
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN t.type='income' THEN t.amount ELSE 0 END),0) AS income_total,
        COALESCE(SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END),0) AS expense_total
    FROM transactions t
    LEFT JOIN transaction_categories c ON c.id = t.category_id
    LEFT JOIN finance_accounts a ON a.id = t.account_id
    LEFT JOIN projects p ON p.id = t.project_id
    $whereSql
");
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

$accounts = $pdo->query("SELECT id, account_name FROM finance_accounts WHERE status='active' ORDER BY account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$sources = [
    '' => 'Semua Sumber',
    'manual' => 'Manual',
    'debt_payment' => 'Hutang/Piutang',
    'reimbursement' => 'Dana Talangan',
    'project' => 'Project',
    'payroll' => 'Payroll',
    'pos' => 'Kasir/POS',
    'product_sale' => 'Penjualan Produk',
    'asset' => 'Aset',
    'other' => 'Lainnya',
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Buku Kas</h1>
            <p class="text-muted mb-0">Daftar semua transaksi masuk dan keluar dari seluruh modul.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/finance/index.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
            <a href="<?= base_url('pages/finance/transaction_add.php'); ?>" class="btn btn-nukari"><i class="bi bi-plus-circle me-1"></i> Transaksi Manual</a>
        </div>
    </div>

    <?php if (!empty($_GET['message'])): ?><div class="alert alert-success rounded-4"><?= f_e($_GET['message']); ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="alert alert-danger rounded-4"><?= f_e($_GET['error']); ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="panel h-100"><div class="text-muted small">Total Income</div><div class="fs-4 fw-bold text-success"><?= rupiah($summary['income_total']); ?></div></div></div>
        <div class="col-md-4"><div class="panel h-100"><div class="text-muted small">Total Expense</div><div class="fs-4 fw-bold text-danger"><?= rupiah($summary['expense_total']); ?></div></div></div>
        <div class="col-md-4"><div class="panel h-100"><div class="text-muted small">Selisih</div><div class="fs-4 fw-bold <?= ((float)$summary['income_total'] - (float)$summary['expense_total']) >= 0 ? 'text-success' : 'text-danger'; ?>"><?= rupiah((float)$summary['income_total'] - (float)$summary['expense_total']); ?></div></div></div>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-lg-2 col-md-4"><input type="month" name="month" class="form-control" value="<?= f_e($month); ?>"></div>
            <div class="col-lg-2 col-md-4">
                <select name="type" class="form-select">
                    <option value="">Semua Jenis</option>
                    <option value="income" <?= $type === 'income' ? 'selected' : ''; ?>>Income</option>
                    <option value="expense" <?= $type === 'expense' ? 'selected' : ''; ?>>Expense</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <select name="account_id" class="form-select">
                    <option value="0">Semua Akun</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id']; ?>" <?= $accountId === (int)$a['id'] ? 'selected' : ''; ?>><?= f_e($a['account_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <select name="source" class="form-select">
                    <?php foreach ($sources as $k => $v): ?>
                        <option value="<?= f_e($k); ?>" <?= $source === $k ? 'selected' : ''; ?>><?= f_e($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6"><input type="text" name="q" class="form-control" value="<?= f_e($q); ?>" placeholder="Cari referensi, kode sistem, deskripsi..."></div>
            <div class="col-lg-1 col-md-2 d-grid"><button class="btn btn-outline-success"><i class="bi bi-search"></i></button></div>
        </form>
    </div>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kode</th>
                        <th>Jenis</th>
                        <th>Nama Transaksi</th>
                        <th>Kategori</th>
                        <th>Akun</th>
                        <th>Project</th>
                        <th>Sumber</th>
                        <th>Deskripsi</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($transactions): ?>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?= date('d M Y', strtotime($t['transaction_date'])); ?></td>
                            <td>
                                <div class="fw-semibold"><?= f_e($t['reference_code'] ?: '-'); ?></div>
                                <small class="text-muted">Sistem: <?= f_e($t['transaction_code']); ?></small>
                            </td>
                            <td><?= f_type_badge($t['type']); ?></td>
                            <td><div class="fw-semibold"><?=f_e($t['transaction_name'] ?: $t['description'] ?: '-');?></div><small class="text-muted"><?=f_e(mb_strimwidth($t['description'] ?: '-',0,55,'...'));?></small></td>
                            <td><?= f_e($t['category_name'] ?: '-'); ?></td>
                            <td><?= f_e($t['account_name'] ?: '-'); ?></td>
                            <td><?= f_e($t['project_name'] ?: '-'); ?></td>
                            <td><?= f_e(f_source_label($t['source_module'])); ?></td>
                            <td style="min-width:200px"><?= f_e(mb_strimwidth($t['description'] ?: '-', 0, 80, '...')); ?></td>
                            <td class="text-end fw-bold <?= $t['type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                                <?= $t['type'] === 'income' ? '+' : '-'; ?> <?= rupiah($t['amount']); ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= base_url('pages/finance/transaction_detail.php?id=' . $t['id']); ?>" class="btn btn-sm btn-outline-success">Detail</a>
                                <?php if ($t['source_module'] === 'manual'): ?>
                                    <div class="btn-group">
                                        <a href="<?= base_url('pages/finance/transaction_edit.php?id=' . $t['id']); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        <a href="<?= base_url('pages/finance/transaction_delete.php?id=' . $t['id']); ?>" onclick="return confirm('Yakin hapus transaksi manual ini?')" class="btn btn-sm btn-outline-danger">Hapus</a>
                                    </div>
                                <?php else: ?>
                                    <span class="badge rounded-pill bg-light text-muted">Auto</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="text-center text-muted py-5">Belum ada transaksi.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
