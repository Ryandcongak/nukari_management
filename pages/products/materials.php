<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Location: simple.php?info=' . urlencode('Modul Bahan Baku sudah digantikan oleh Produk Sederhana.'));
exit;
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Gudang Bahan Baku - Nukari';

$errors = [];
$success = '';

function material_redirect($params = [])
{
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: materials.php' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);

    $materialCode = trim($_POST['material_code'] ?? '') ?: product_code('BB');
    $materialName = trim($_POST['material_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $unit = $_POST['unit'] ?? 'pcs';
    $minimumStock = product_parse_number($_POST['minimum_stock'] ?? 0);
    $averagePrice = product_parse_number($_POST['average_price'] ?? 0);
    $location = trim($_POST['location'] ?? '');
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if ($materialName === '') {
        $errors[] = 'Nama bahan wajib diisi.';
    }

    $allowedUnits = ['kg','gram','liter','ml','meter','cm','pcs','botol','karung','pack','roll','jam','hari','other'];
    if (!in_array($unit, $allowedUnits, true)) {
        $unit = 'pcs';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    if (!$errors) {
        $data = [
            $materialCode,
            $materialName,
            $category,
            $unit,
            $minimumStock,
            $averagePrice,
            $location,
            $supplierName,
            $notes,
            $status
        ];

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE raw_materials
                SET material_code = ?,
                    material_name = ?,
                    category = ?,
                    unit = ?,
                    minimum_stock = ?,
                    average_price = ?,
                    location = ?,
                    supplier_name = ?,
                    notes = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $data[] = $id;
            $stmt->execute($data);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO raw_materials
                (material_code, material_name, category, unit, minimum_stock, average_price, location, supplier_name, notes, status, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
            ");
            $stmt->execute($data);
        }

        material_redirect(['saved' => 1]);
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    try {
        $pdo->prepare("DELETE FROM raw_materials WHERE id = ?")->execute([$id]);
        material_redirect(['deleted' => 1]);
    } catch (Throwable $e) {
        material_redirect(['delete_error' => 1]);
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM raw_materials WHERE id = ?");
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}

$q = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(material_code LIKE :q_code OR material_name LIKE :q_name OR category LIKE :q_category OR location LIKE :q_location OR supplier_name LIKE :q_supplier)";
    $queryValue = '%' . $q . '%';
    $params[':q_code'] = $queryValue;
    $params[':q_name'] = $queryValue;
    $params[':q_category'] = $queryValue;
    $params[':q_location'] = $queryValue;
    $params[':q_supplier'] = $queryValue;
}
if ($categoryFilter !== '') {
    $where[] = "category = :category";
    $params[':category'] = $categoryFilter;
}
if ($statusFilter !== '') {
    $where[] = "status = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT * FROM raw_materials $whereSql ORDER BY id DESC");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryRows = $pdo->query("
    SELECT category
    FROM raw_materials
    WHERE category IS NOT NULL AND category <> ''
    GROUP BY category
    ORDER BY category ASC
")->fetchAll(PDO::FETCH_COLUMN);

$summary = $pdo->query("
    SELECT
        COUNT(*) AS total_materials,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_materials,
        SUM(CASE WHEN stock <= minimum_stock THEN 1 ELSE 0 END) AS low_stock_materials,
        COALESCE(SUM(stock * average_price), 0) AS inventory_value
    FROM raw_materials
")->fetch(PDO::FETCH_ASSOC);

$totalMaterials = (int)($summary['total_materials'] ?? 0);
$activeMaterials = (int)($summary['active_materials'] ?? 0);
$lowStockMaterials = (int)($summary['low_stock_materials'] ?? 0);
$inventoryValue = (float)($summary['inventory_value'] ?? 0);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>

<style>
.materials-page .product-card {
    overflow: hidden;
}

.materials-page .soft-section {
    padding: 18px 20px;
}

.materials-page .section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 20px;
    border-bottom: 1px solid #e6edf5;
}

.materials-page .section-title h5,
.materials-page .section-title strong {
    margin: 0;
}

.materials-page .table-box {
    padding: 0 18px 18px;
}

.materials-page .table-wrap {
    border: 1px solid #e6edf5;
    border-radius: 18px;
    overflow: hidden;
    background: #fff;
}

.materials-page .table {
    margin-bottom: 0;
}

.materials-page .table thead th {
    background: #f6f8fb;
    color: #64748b;
    font-size: 12px;
    letter-spacing: .04em;
    text-transform: uppercase;
    border-bottom: 1px solid #e6edf5;
    white-space: nowrap;
    padding: 14px 16px;
}

.materials-page .table tbody td {
    padding: 14px 16px;
    vertical-align: middle;
}

.materials-page .material-avatar {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #eafaf1;
    color: #0f8f55;
    font-weight: 800;
    font-size: 13px;
    flex: 0 0 42px;
}

.materials-page .material-name-cell {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 220px;
}

.materials-page .stock-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 4px 10px;
    background: #eef2f7;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
}

.materials-page .low-stock .stock-pill {
    background: #fff7ed;
    color: #c2410c;
}

.materials-page .low-stock {
    background: #fffaf4;
}

.materials-page .metric-card {
    background: #fff;
    border: 1px solid #e3eaf3;
    border-radius: 18px;
    padding: 18px;
    min-height: 108px;
    box-shadow: 0 12px 30px rgba(15,23,42,.04);
    position: relative;
    overflow: hidden;
}

.materials-page .metric-card:after {
    content: "";
    position: absolute;
    right: -28px;
    top: -28px;
    width: 86px;
    height: 86px;
    border-radius: 999px;
    background: #eafff3;
}

.materials-page .metric-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #64748b;
    font-weight: 800;
}

.materials-page .metric-value {
    margin-top: 6px;
    font-size: 25px;
    line-height: 1.1;
    font-weight: 900;
    color: #0f172a;
}

.materials-page .metric-sub {
    margin-top: 8px;
    color: #64748b;
    font-size: 13px;
}

.materials-page .guide-list {
    margin: 0;
    padding-left: 18px;
    color: #475569;
    font-size: 13px;
    line-height: 1.7;
}

.materials-page .filter-card {
    padding: 16px 20px;
}

.materials-page .empty-state {
    padding: 36px 18px;
    text-align: center;
    color: #64748b;
}

.materials-page .action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

@media (max-width: 991px) {
    .materials-page .table-box {
        padding: 0 12px 14px;
    }

    .materials-page .section-title,
    .materials-page .soft-section,
    .materials-page .filter-card {
        padding-left: 14px;
        padding-right: 14px;
    }
}
</style>

<main class="main-content">
    <div class="container-fluid product-page materials-page py-3">
        <?php require_once __DIR__ . '/_nav.php'; ?>

        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success border-0 rounded-4 shadow-sm">Data bahan baku berhasil disimpan.</div>
        <?php endif; ?>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success border-0 rounded-4 shadow-sm">Data bahan baku berhasil dihapus.</div>
        <?php endif; ?>

        <?php if (isset($_GET['delete_error'])): ?>
            <div class="alert alert-warning border-0 rounded-4 shadow-sm">
                Bahan tidak bisa dihapus karena sudah memiliki riwayat mutasi/produksi. Gunakan status <strong>Inactive</strong> jika bahan tidak digunakan lagi.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger border-0 rounded-4 shadow-sm">
                <?php foreach ($errors as $error): ?>
                    <div><?= product_safe($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-label">Total Bahan</div>
                    <div class="metric-value"><?= product_num($totalMaterials); ?></div>
                    <div class="metric-sub"><?= product_num($activeMaterials); ?> aktif</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-label">Nilai Gudang</div>
                    <div class="metric-value"><?= product_money($inventoryValue); ?></div>
                    <div class="metric-sub">Stock × harga rata-rata</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-label">Perlu Perhatian</div>
                    <div class="metric-value"><?= product_num($lowStockMaterials); ?></div>
                    <div class="metric-sub">stok ≤ minimum</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-label">Kategori</div>
                    <div class="metric-value"><?= product_num(count($categoryRows)); ?></div>
                    <div class="metric-sub">jenis bahan terdaftar</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="product-card mb-3">
                    <div class="section-title">
                        <strong><?= $edit ? 'Edit' : 'Tambah'; ?> Bahan Baku</strong>
                        <?php if ($edit): ?>
                            <span class="badge bg-warning text-dark">Mode Edit</span>
                        <?php else: ?>
                            <span class="badge bg-success">Gudang</span>
                        <?php endif; ?>
                    </div>

                    <div class="soft-section">
                        <form method="post" class="row g-3" autocomplete="off">
                            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0); ?>">

                            <div class="col-6">
                                <label class="form-label fw-semibold">Kode</label>
                                <input name="material_code" class="form-control" value="<?= product_safe($edit['material_code'] ?? ''); ?>" placeholder="Otomatis">
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Satuan</label>
                                <select name="unit" class="form-select">
                                    <?php foreach (['kg','gram','liter','ml','meter','cm','pcs','botol','karung','pack','roll','jam','hari','other'] as $u): ?>
                                        <option value="<?= $u; ?>" <?= (($edit['unit'] ?? 'pcs') === $u) ? 'selected' : ''; ?>><?= $u; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Nama Bahan *</label>
                                <input type="text" name="material_name" required class="form-control" data-no-currency="1" value="<?= product_safe($edit['material_name'] ?? ''); ?>" placeholder="Contoh: Tutup Botol HDPE">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Kategori</label>
                                <input name="category" class="form-control" value="<?= product_safe($edit['category'] ?? ''); ?>" placeholder="Contoh: Plastik Daur Ulang / Kemasan / Eco Enzyme">
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Min Stok</label>
                                <input name="minimum_stock" class="form-control qty-input" value="<?= product_num($edit['minimum_stock'] ?? 0); ?>" inputmode="decimal">
                            </div>

                            <div class="col-6">
                                <label class="form-label fw-semibold">Harga Rata-rata</label>
                                <input name="average_price" class="form-control money-input" value="<?= product_num($edit['average_price'] ?? 0, 2); ?>" inputmode="numeric">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Lokasi</label>
                                <input name="location" class="form-control" value="<?= product_safe($edit['location'] ?? ''); ?>" placeholder="Contoh: Gudang Plastik Serangan">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Supplier/Asal</label>
                                <input name="supplier_name" class="form-control" value="<?= product_safe($edit['supplier_name'] ?? ''); ?>" placeholder="Contoh: Donasi / Bank Sampah / Supplier">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Status</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?= (($edit['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= (($edit['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Catatan</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Catatan kualitas, sumber bahan, atau instruksi penyimpanan."><?= product_safe($edit['notes'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-12 d-flex gap-2 flex-wrap">
                                <button class="btn btn-success btn-rounded px-4">Simpan</button>
                                <?php if ($edit): ?>
                                    <a href="materials.php" class="btn btn-outline-secondary btn-rounded">Batal</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="product-card">
                    <div class="section-title">
                        <strong>Panduan Gudang Bahan</strong>
                        <span class="badge rounded-pill bg-light text-dark">Guide</span>
                    </div>
                    <div class="soft-section">
                        <ol class="guide-list">
                            <li>Gunakan <strong>Mutasi</strong> untuk menambah stok awal, pembelian, donasi, atau pengurangan bahan.</li>
                            <li><strong>Harga rata-rata</strong> dipakai untuk menghitung nilai gudang dan estimasi HPP produksi.</li>
                            <li><strong>Minimum stok</strong> membantu sistem menandai bahan yang perlu restock.</li>
                            <li>Jangan hapus bahan yang sudah dipakai produksi. Ubah status menjadi <strong>Inactive</strong>.</li>
                            <li>Gunakan lokasi yang konsisten, misalnya Gudang Plastik, Gudang Cairan, atau Gudang Packaging.</li>
                        </ol>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="product-card">
                    <div class="section-title">
                        <div>
                            <strong>Stok Bahan Baku</strong>
                            <div class="small-muted mt-1"><?= product_num(count($materials)); ?> data ditampilkan</div>
                        </div>
                        <a href="movements.php" class="btn btn-sm btn-success btn-rounded">Mutasi Stok</a>
                    </div>

                    <div class="filter-card">
                        <form class="row g-2 align-items-end">
                            <div class="col-lg-5">
                                <label class="form-label small fw-semibold">Cari Bahan</label>
                                <input name="q" class="form-control" placeholder="Nama, kode, kategori, lokasi, supplier..." value="<?= product_safe($q); ?>">
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label small fw-semibold">Kategori</label>
                                <select name="category" class="form-select">
                                    <option value="">Semua Kategori</option>
                                    <?php foreach ($categoryRows as $cat): ?>
                                        <option value="<?= product_safe($cat); ?>" <?= $categoryFilter === $cat ? 'selected' : ''; ?>><?= product_safe($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2">
                                <label class="form-label small fw-semibold">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">Semua</option>
                                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-lg-2 d-flex gap-2">
                                <button class="btn btn-success btn-rounded w-100">Filter</button>
                                <a href="materials.php" class="btn btn-outline-secondary btn-rounded">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="table-box">
                        <div class="table-wrap table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Bahan</th>
                                        <th>Stok</th>
                                        <th>Harga Rata-rata</th>
                                        <th>Nilai</th>
                                        <th>Lokasi</th>
                                        <th>Status</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$materials): ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="empty-state">
                                                    Belum ada bahan baku yang sesuai filter.
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach ($materials as $m): ?>
                                        <?php
                                            $stock = (float)$m['stock'];
                                            $minimum = (float)$m['minimum_stock'];
                                            $average = (float)$m['average_price'];
                                            $value = $stock * $average;
                                            $isLowStock = $stock <= $minimum;
                                            $initials = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $m['material_name'] ?? 'B'), 0, 2));
                                            if ($initials === '') {
                                                $initials = 'BB';
                                            }
                                        ?>
                                        <tr class="<?= $isLowStock ? 'low-stock' : ''; ?>">
                                            <td class="fw-semibold"><?= product_safe($m['material_code']); ?></td>
                                            <td>
                                                <div class="material-name-cell">
                                                    <div class="material-avatar"><?= product_safe($initials); ?></div>
                                                    <div>
                                                        <strong><?= product_safe($m['material_name']); ?></strong>
                                                        <div class="small-muted"><?= product_safe($m['category']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="stock-pill"><?= product_num($stock) . ' ' . product_safe($m['unit']); ?></span>
                                                <div class="small-muted mt-1">Min <?= product_num($minimum); ?></div>
                                            </td>
                                            <td><?= product_money($average); ?></td>
                                            <td class="money"><?= product_money($value); ?></td>
                                            <td><?= product_safe($m['location']); ?></td>
                                            <td>
                                                <?php if (($m['status'] ?? 'active') === 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="action-buttons justify-content-end">
                                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int)$m['id']; ?>">Edit</a>
                                                    <a class="btn btn-sm btn-outline-success" href="movements.php?material_id=<?= (int)$m['id']; ?>">Mutasi</a>
                                                    <a onclick="return confirm('Hapus bahan ini? Jika sudah memiliki riwayat, sebaiknya ubah status menjadi Inactive.')" class="btn btn-sm btn-outline-danger" href="?delete=<?= (int)$m['id']; ?>">Hapus</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="soft-section pt-0">
                        <div class="alert alert-light border rounded-4 mb-0 small">
                            <strong>Catatan:</strong> Nilai bahan dihitung dari stok saat ini × harga rata-rata. Stok awal dan perubahan stok sebaiknya selalu dicatat melalui menu Mutasi agar riwayat gudang tetap rapi.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    function onlyDigits(value) {
        return String(value || '').replace(/[^\d]/g, '');
    }

    function formatRupiahInput(input) {
        const raw = onlyDigits(input.value);
        input.value = raw ? raw.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
    }

    document.querySelectorAll('.money-input').forEach(function (input) {
        input.addEventListener('input', function () {
            formatRupiahInput(input);
        });
        input.addEventListener('focus', function () {
            if (input.value === '0') input.value = '';
        });
        input.addEventListener('blur', function () {
            if (input.value.trim() === '') input.value = '0';
            formatRupiahInput(input);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
