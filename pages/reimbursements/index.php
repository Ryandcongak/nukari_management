<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Dana Talangan - Nukari';

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$projectId = (int)($_GET['project_id'] ?? 0);

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(r.reimbursement_code LIKE :q OR r.requester_name LIKE :q OR r.category LIKE :q OR r.description LIKE :q OR p.project_name LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($status !== '') {
    $where[] = "r.status = :status";
    $params[':status'] = $status;
}

if ($projectId > 0) {
    $where[] = "r.project_id = :project_id";
    $params[':project_id'] = $projectId;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT 
        r.*,
        e.full_name AS employee_name,
        p.project_name,
        d.debt_code,
        d.status AS debt_status
    FROM reimbursements r
    LEFT JOIN employees e ON e.id = r.employee_id
    LEFT JOIN projects p ON p.id = r.project_id
    LEFT JOIN debts d ON d.id = r.debt_id
    $whereSql
    ORDER BY r.request_date DESC, r.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_amount,
        COALESCE(SUM(CASE WHEN status IN ('approved','partial') THEN amount - paid_amount ELSE 0 END),0) AS unpaid_amount,
        COALESCE(SUM(CASE WHEN status IN ('partial','paid') THEN paid_amount ELSE 0 END),0) AS paid_amount,
        COALESCE(SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END),0) AS pending_count
    FROM reimbursements
")->fetch(PDO::FETCH_ASSOC);

$projects = $pdo->query("SELECT id, project_name FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Dana Talangan</h1>
            <p class="text-muted mb-0">Kelola uang pribadi yang digunakan untuk kebutuhan operasional atau project.</p>
        </div>
        <a href="<?= base_url('pages/reimbursements/add.php'); ?>" class="btn btn-nukari">
            <i class="bi bi-plus-circle me-1"></i> Tambah Dana Talangan
        </a>
    </div>

    <?php if (!empty($_GET['message'])): ?>
        <div class="alert alert-success rounded-4"><?= rmb_e($_GET['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-4"><?= rmb_e($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Pending Approval</div><div class="fs-4 fw-bold text-warning"><?= rupiah($summary['pending_amount'] ?? 0); ?></div><small class="text-muted"><?= number_format($summary['pending_count'] ?? 0); ?> pengajuan</small></div></div>
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Belum Dibayar</div><div class="fs-4 fw-bold text-danger"><?= rupiah($summary['unpaid_amount'] ?? 0); ?></div></div></div>
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Sudah Dibayar</div><div class="fs-4 fw-bold text-success"><?= rupiah($summary['paid_amount'] ?? 0); ?></div></div></div>
        <div class="col-md-3"><div class="panel h-100"><div class="text-muted small">Integrasi</div><div class="fs-5 fw-bold text-primary">Hutang Otomatis</div><small class="text-muted">Saat approved</small></div></div>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-lg-4">
                <input type="text" name="q" class="form-control" value="<?= rmb_e($q); ?>" placeholder="Cari kode, pemohon, kategori, project...">
            </div>
            <div class="col-lg-3">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <?php foreach (['pending'=>'Pending','approved'=>'Approved','partial'=>'Dibayar Sebagian','paid'=>'Lunas','rejected'=>'Ditolak','cancelled'=>'Dibatalkan'] as $k=>$v): ?>
                        <option value="<?= $k; ?>" <?= $status === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <select name="project_id" class="form-select">
                    <option value="0">Semua Project</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id']; ?>" <?= $projectId === (int)$p['id'] ? 'selected' : ''; ?>><?= rmb_e($p['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-outline-success"><i class="bi bi-search me-1"></i> Filter</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Tanggal</th>
                        <th>Pemohon</th>
                        <th>Project</th>
                        <th>Kategori</th>
                        <th>Nominal</th>
                        <th>Terbayar</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th>Hutang</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php $remaining = max((float)$r['amount'] - (float)$r['paid_amount'], 0); ?>
                        <tr>
                            <td><div class="fw-semibold"><?= rmb_e($r['reimbursement_code']); ?></div><small class="text-muted">#<?= (int)$r['id']; ?></small></td>
                            <td><?= date('d M Y', strtotime($r['request_date'])); ?></td>
                            <td><div class="fw-semibold"><?= rmb_e($r['employee_name'] ?: $r['requester_name']); ?></div><small class="text-muted"><?= $r['employee_id'] ? 'Employee' : 'Personal/Other'; ?></small></td>
                            <td><?= rmb_e($r['project_name'] ?: '-'); ?></td>
                            <td><?= rmb_e($r['category'] ?: '-'); ?></td>
                            <td class="fw-semibold"><?= rupiah($r['amount']); ?></td>
                            <td><?= rupiah($r['paid_amount']); ?></td>
                            <td class="<?= $remaining > 0 ? 'text-danger fw-bold' : 'text-success fw-bold'; ?>"><?= rupiah($remaining); ?></td>
                            <td><?= rmb_status_badge($r['status']); ?></td>
                            <td>
                                <?php if ($r['debt_id']): ?>
                                    <span class="badge rounded-pill bg-success">Terhubung</span>
                                    <div class="small text-muted"><?= rmb_e($r['debt_code']); ?></div>
                                <?php else: ?>
                                    <span class="badge rounded-pill bg-secondary">Belum</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="<?= base_url('pages/reimbursements/detail.php?id=' . $r['id']); ?>" class="btn btn-sm btn-outline-primary">Detail</a>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <a href="<?= base_url('pages/reimbursements/approve.php?id=' . $r['id']); ?>" class="btn btn-sm btn-outline-success">Approve</a>
                                    <?php endif; ?>
                                    <?php if (in_array($r['status'], ['approved','partial'], true)): ?>
                                        <a href="<?= base_url('pages/reimbursements/pay.php?id=' . $r['id']); ?>" class="btn btn-sm btn-outline-success">Bayar</a>
                                    <?php endif; ?>
                                    <?php if (!in_array($r['status'], ['partial','paid'], true)): ?>
                                        <a href="<?= base_url('pages/reimbursements/edit.php?id=' . $r['id']); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="text-center text-muted py-5">Belum ada data dana talangan.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
