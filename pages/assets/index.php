<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Aset / Inventaris - Nukari';

$condition = $_GET['condition'] ?? '';
$source = $_GET['source'] ?? '';
$category = trim($_GET['category'] ?? '');
$keyword = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($condition !== '') {
    $where[] = 'a.condition_status = :condition';
    $params[':condition'] = $condition;
}
if ($source !== '') {
    $where[] = 'a.source_type = :source';
    $params[':source'] = $source;
}
if ($category !== '') {
    $where[] = 'a.category = :category';
    $params[':category'] = $category;
}
if ($keyword !== '') {
    $where[] = '(a.asset_code LIKE :keyword OR a.asset_name LIKE :keyword OR a.category LIKE :keyword OR a.location LIKE :keyword OR e.full_name LIKE :keyword OR p.project_name LIKE :keyword)';
    $params[':keyword'] = '%' . $keyword . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT 
        a.*, 
        e.full_name AS responsible_name, 
        p.project_name,
        p.project_code,
        COUNT(ap.id) AS total_photos
    FROM assets a
    LEFT JOIN employees e ON a.responsible_employee_id = e.id
    LEFT JOIN projects p ON a.source_project_id = p.id
    LEFT JOIN project_asset_photos ap ON ap.asset_id = a.id
    $whereSql
    GROUP BY a.id
    ORDER BY a.id DESC
");
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query("
    SELECT
        COUNT(*) AS total_assets,
        COALESCE(SUM(purchase_price),0) AS total_value,
        SUM(CASE WHEN condition_status='good' THEN 1 ELSE 0 END) AS good_assets,
        SUM(CASE WHEN condition_status='maintenance' THEN 1 ELSE 0 END) AS maintenance_assets,
        SUM(CASE WHEN condition_status='damaged' THEN 1 ELSE 0 END) AS damaged_assets,
        SUM(CASE WHEN condition_status='lost' THEN 1 ELSE 0 END) AS lost_assets,
        SUM(CASE WHEN source_type='project_transaction' THEN 1 ELSE 0 END) AS project_assets,
        SUM(CASE WHEN source_type='manual' THEN 1 ELSE 0 END) AS manual_assets
    FROM assets
")->fetch(PDO::FETCH_ASSOC);

$categories = $pdo->query("
    SELECT category, COUNT(*) total, COALESCE(SUM(purchase_price),0) total_value
    FROM assets
    GROUP BY category
    ORDER BY total DESC, category ASC
")->fetchAll(PDO::FETCH_ASSOC);

$categoryOptions = $pdo->query("
    SELECT DISTINCT category 
    FROM assets 
    WHERE category IS NOT NULL AND category <> '' 
    ORDER BY category ASC
")->fetchAll(PDO::FETCH_COLUMN);

function asset_condition_label($status) {
    $map = [
        'good' => ['Baik', 'success', 'bi-check-circle'],
        'maintenance' => ['Maintenance', 'warning', 'bi-tools'],
        'damaged' => ['Rusak', 'danger', 'bi-exclamation-triangle'],
        'lost' => ['Hilang', 'secondary', 'bi-question-circle'],
    ];
    return $map[$status] ?? [$status, 'secondary', 'bi-circle'];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
.asset-hero {
    background: linear-gradient(135deg, #07553f 0%, #198754 54%, #e0bd42 100%);
    border-radius: 28px;
    padding: 30px;
    color: #fff;
    position: relative;
    overflow: hidden;
    margin-bottom: 22px;
}
.asset-hero::after {
    content: "";
    position: absolute;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    right: -70px;
    top: -90px;
    background: rgba(255,255,255,.18);
}
.asset-mini-card {
    background: #fff;
    border-radius: 18px;
    padding: 18px 18px;
    box-shadow: 0 10px 30px rgba(15,23,42,.06);
    border: 1px solid rgba(226,232,240,.85);
    min-height: 112px;
}
.asset-mini-card .icon {
    width: 42px;
    height: 42px;
    border-radius: 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
}
.asset-mini-card .label {
    color: #64748b;
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.asset-mini-card .value {
    font-size: 1.35rem;
    font-weight: 800;
    color: #0f172a;
}
.asset-filter-panel,
.asset-card-panel {
    background: #fff;
    border-radius: 22px;
    padding: 18px;
    border: 1px solid rgba(226,232,240,.9);
    box-shadow: 0 10px 30px rgba(15,23,42,.05);
}
.asset-table thead th {
    font-size: .76rem;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .05em;
    background: #f8fafc;
    border-bottom: 0;
}
.asset-thumb {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    object-fit: cover;
    background: #f1f5f9;
}
.asset-placeholder {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    background: #ecfdf5;
    color: #17835d;
    display: flex;
    align-items: center;
    justify-content: center;
}
.asset-action {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.category-pill {
    border-radius: 16px;
    background: #f8fafc;
    padding: 14px;
    border: 1px solid #e2e8f0;
}
</style>

<main class="main-content">
    <div class="asset-hero d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <span class="badge bg-white text-success rounded-pill mb-3 px-3 py-2">
                <i class="bi bi-box-seam me-1"></i> Operations / Asset
            </span>
            <h1 class="fw-bold mb-2">Aset / Inventaris</h1>
            <p class="mb-0 opacity-90">
                Pantau aset manual, aset dari project, kondisi, nilai inventaris, lokasi, dan penanggung jawab.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 position-relative">
            <a href="<?= base_url('pages/assets/scan_qr.php'); ?>" class="btn btn-outline-light rounded-pill fw-semibold px-4">
                <i class="bi bi-qr-code-scan me-1"></i> Scan QR
            </a>
            <a href="<?= base_url('pages/assets/report.php'); ?>" class="btn btn-outline-light rounded-pill fw-semibold px-4">
                <i class="bi bi-bar-chart-line me-1"></i> Laporan
            </a>
            <a href="<?= base_url('pages/assets/generate_notifications.php'); ?>" class="btn btn-outline-light rounded-pill fw-semibold px-4">
                <i class="bi bi-bell me-1"></i> Sync Reminder
            </a>
            <a href="<?= base_url('pages/assets/print_qr_all.php'); ?>" target="_blank" class="btn btn-outline-light rounded-pill fw-semibold px-4">
                <i class="bi bi-printer me-1"></i> Cetak Semua QR
            </a>
            <a href="<?= base_url('pages/assets/add.php'); ?>" class="btn btn-light rounded-pill fw-semibold px-4">
                <i class="bi bi-plus-circle me-1"></i> Tambah Aset
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="asset-mini-card d-flex justify-content-between">
                <div>
                    <div class="label">Total Aset</div>
                    <div class="value"><?= number_format((int)$summary['total_assets']); ?></div>
                    <div class="small text-muted">Manual & dari project.</div>
                </div>
                <div class="icon bg-success-subtle text-success"><i class="bi bi-boxes"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="asset-mini-card d-flex justify-content-between">
                <div>
                    <div class="label">Nilai Inventaris</div>
                    <div class="value"><?= rupiah($summary['total_value']); ?></div>
                    <div class="small text-muted">Total harga pembelian.</div>
                </div>
                <div class="icon bg-primary-subtle text-primary"><i class="bi bi-cash-stack"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="asset-mini-card d-flex justify-content-between">
                <div>
                    <div class="label">Maintenance</div>
                    <div class="value"><?= (int)$summary['maintenance_assets']; ?></div>
                    <div class="small text-muted">Perlu perhatian teknis.</div>
                </div>
                <div class="icon bg-warning-subtle text-warning"><i class="bi bi-tools"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="asset-mini-card d-flex justify-content-between">
                <div>
                    <div class="label">Rusak / Hilang</div>
                    <div class="value"><?= (int)$summary['damaged_assets'] + (int)$summary['lost_assets']; ?></div>
                    <div class="small text-muted">Perlu tindak lanjut.</div>
                </div>
                <div class="icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-octagon"></i></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="asset-filter-panel">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold">Cari Aset</label>
                        <input type="text" name="q" class="form-control" placeholder="Kode, nama, lokasi, PJ..." value="<?= htmlspecialchars($keyword); ?>">
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label fw-semibold">Kondisi</label>
                        <select name="condition" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach (['good'=>'Baik','maintenance'=>'Maintenance','damaged'=>'Rusak','lost'=>'Hilang'] as $k=>$v): ?>
                                <option value="<?= $k; ?>" <?= $condition === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label fw-semibold">Sumber</label>
                        <select name="source" class="form-select">
                            <option value="">Semua</option>
                            <option value="manual" <?= $source === 'manual' ? 'selected' : ''; ?>>Manual</option>
                            <option value="project_transaction" <?= $source === 'project_transaction' ? 'selected' : ''; ?>>Project</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label fw-semibold">Kategori</label>
                        <select name="category" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach ($categoryOptions as $cat): ?>
                                <option value="<?= htmlspecialchars($cat); ?>" <?= $category === $cat ? 'selected' : ''; ?>><?= htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="asset-filter-panel h-100">
                <div class="fw-bold mb-2">Komposisi Sumber</div>
                <div class="d-flex justify-content-between small mb-1">
                    <span>Manual</span><strong><?= (int)$summary['manual_assets']; ?></strong>
                </div>
                <div class="progress mb-2" style="height:8px;">
                    <div class="progress-bar bg-success" style="width: <?= ((int)$summary['total_assets']>0 ? ((int)$summary['manual_assets']/(int)$summary['total_assets']*100) : 0); ?>%"></div>
                </div>
                <div class="d-flex justify-content-between small mb-1">
                    <span>Dari Project</span><strong><?= (int)$summary['project_assets']; ?></strong>
                </div>
                <div class="progress" style="height:8px;">
                    <div class="progress-bar bg-primary" style="width: <?= ((int)$summary['total_assets']>0 ? ((int)$summary['project_assets']/(int)$summary['total_assets']*100) : 0); ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="asset-card-panel">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold mb-1">Daftar Aset</h5>
                        <p class="text-muted mb-0 small">Gunakan tabel ini untuk melihat detail, edit, atau menghapus data aset.</p>
                    </div>
                    <div class="small text-muted"><?= count($assets); ?> data ditemukan</div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle asset-table">
                        <thead>
                            <tr>
                                <th>Aset</th>
                                <th>Kategori</th>
                                <th>Nilai & Kondisi</th>
                                <th>Lokasi / PJ</th>
                                <th>Sumber</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($assets): foreach ($assets as $a): ?>
                            <?php [$condText, $condColor, $condIcon] = asset_condition_label($a['condition_status']); ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($a['photo'])): ?>
                                            <img src="<?= base_url($a['photo']); ?>" class="asset-thumb" alt="Foto Aset">
                                        <?php else: ?>
                                            <div class="asset-placeholder"><i class="bi bi-box-seam"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($a['asset_name']); ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars($a['asset_code'] ?: '-'); ?></div>
                                            <div class="small text-muted"><i class="bi bi-images me-1"></i><?= (int)$a['total_photos']; ?> foto</div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($a['category'] ?: '-'); ?></td>
                                <td>
                                    <div class="fw-bold"><?= rupiah($a['purchase_price']); ?></div>
                                    <span class="badge rounded-pill text-bg-<?= $condColor; ?>">
                                        <i class="bi <?= $condIcon; ?> me-1"></i><?= htmlspecialchars($condText); ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($a['location'] ?: '-'); ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($a['responsible_name'] ?: 'Tanpa PJ'); ?></div>
                                </td>
                                <td>
                                    <?php if (($a['source_type'] ?? 'manual') === 'project_transaction'): ?>
                                        <span class="badge rounded-pill text-bg-info">Project</span>
                                        <div class="small text-muted"><?= htmlspecialchars($a['project_name'] ?: '-'); ?></div>
                                    <?php else: ?>
                                        <span class="badge rounded-pill text-bg-light">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-dark asset-action" title="Cetak QR" target="_blank" href="<?= base_url('pages/assets/print_qr.php?id='.(int)$a['id']); ?>"><i class="bi bi-qr-code"></i></a>
                                    <a class="btn btn-sm btn-outline-success asset-action" title="Detail" href="<?= base_url('pages/assets/detail.php?id='.(int)$a['id']); ?>"><i class="bi bi-eye"></i></a>
                                    <a class="btn btn-sm btn-outline-primary asset-action" title="Edit" href="<?= base_url('pages/assets/edit.php?id='.(int)$a['id']); ?>"><i class="bi bi-pencil"></i></a>
                                    <a class="btn btn-sm btn-outline-danger asset-action" title="Hapus" onclick="return confirm('Hapus aset ini?')" href="<?= base_url('pages/assets/delete.php?id='.(int)$a['id']); ?>"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-5">Belum ada aset/inventaris.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="asset-card-panel mb-3">
                <h5 class="fw-bold mb-3">Panduan Halaman</h5>
                <div class="d-flex gap-3 mb-3">
                    <span class="badge rounded-circle bg-success-subtle text-success p-2">1</span>
                    <div>
                        <div class="fw-semibold">Catat Aset</div>
                        <div class="small text-muted">Input aset manual atau biarkan aset otomatis masuk dari transaksi project.</div>
                    </div>
                </div>
                <div class="d-flex gap-3 mb-3">
                    <span class="badge rounded-circle bg-success-subtle text-success p-2">2</span>
                    <div>
                        <div class="fw-semibold">Lengkapi Foto</div>
                        <div class="small text-muted">Simpan foto utama dan beberapa foto tambahan untuk dokumentasi.</div>
                    </div>
                </div>
                <div class="d-flex gap-3">
                    <span class="badge rounded-circle bg-success-subtle text-success p-2">3</span>
                    <div>
                        <div class="fw-semibold">Pantau Kondisi</div>
                        <div class="small text-muted">Update kondisi saat aset baik, maintenance, rusak, atau hilang.</div>
                    </div>
                </div>
            </div>

            <div class="asset-card-panel">
                <h5 class="fw-bold mb-3">Kategori Aset</h5>
                <?php if ($categories): foreach (array_slice($categories, 0, 7) as $cat): ?>
                    <div class="category-pill mb-2">
                        <div class="d-flex justify-content-between">
                            <strong><?= htmlspecialchars($cat['category'] ?: 'Tanpa Kategori'); ?></strong>
                            <span class="badge text-bg-light"><?= (int)$cat['total']; ?></span>
                        </div>
                        <div class="small text-muted"><?= rupiah($cat['total_value']); ?></div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="text-muted small">Belum ada kategori.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
