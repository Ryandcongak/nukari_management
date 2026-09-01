<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';
$pageTitle = 'Mutasi Stok Bahan - Nukari';

$selected = (int)($_GET['material_id'] ?? 0);
$error = '';
$success = trim($_GET['success'] ?? '');

function movement_label($type)
{
    $labels = [
        'in' => 'Masuk',
        'out' => 'Keluar',
        'adjustment' => 'Penyesuaian',
        'damaged' => 'Rusak',
        'lost' => 'Hilang',
        'return' => 'Return',
    ];
    return $labels[$type] ?? ucfirst((string)$type);
}

function movement_class($type)
{
    $classes = [
        'in' => 'mv-in',
        'return' => 'mv-in',
        'out' => 'mv-out',
        'production' => 'mv-out',
        'damaged' => 'mv-danger',
        'lost' => 'mv-danger',
        'adjustment' => 'mv-warning',
    ];
    return $classes[$type] ?? 'mv-neutral';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mid = (int)($_POST['material_id'] ?? 0);
    $movementType = $_POST['movement_type'] ?? 'in';
    $qty = product_parse_number($_POST['quantity'] ?? 0);
    $price = product_parse_number($_POST['unit_price'] ?? 0);
    $referenceType = $_POST['reference_type'] ?? 'manual';
    $description = trim($_POST['description'] ?? '');
    $movementDate = $_POST['movement_date'] ?? date('Y-m-d');

    $matStmt = $pdo->prepare("SELECT id, material_name, stock FROM raw_materials WHERE id=? AND status='active' LIMIT 1");
    $matStmt->execute([$mid]);
    $material = $matStmt->fetch(PDO::FETCH_ASSOC);

    if (!$material) {
        $error = 'Bahan baku tidak ditemukan atau tidak aktif.';
    } elseif ($qty == 0) {
        $error = 'Jumlah mutasi tidak boleh 0.';
    } elseif (in_array($movementType, ['out', 'damaged', 'lost'], true) && abs($qty) > (float)$material['stock']) {
        $error = 'Jumlah keluar/rusak/hilang melebihi stok tersedia.';
    } else {
        if (in_array($movementType, ['out', 'damaged', 'lost'], true)) {
            $qty = abs($qty);
        }
        if (in_array($movementType, ['in', 'return'], true)) {
            $qty = abs($qty);
        }

        $total = abs($qty) * $price;
        $stmt = $pdo->prepare("INSERT INTO raw_material_movements (material_id,movement_date,movement_type,quantity,unit_price,total_amount,reference_type,description,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$mid, $movementDate, $movementType, $qty, $price, $total, $referenceType, $description, product_get_user_id()]);

        if (in_array($movementType, ['in', 'return'], true)) {
            $pdo->prepare("UPDATE raw_materials SET average_price = CASE WHEN (? > 0) THEN ? ELSE average_price END WHERE id=?")->execute([$price, $price, $mid]);
        }
        product_recalc_material_stock($pdo, $mid);
        header('Location: movements.php?material_id=' . $mid . '&success=1');
        exit;
    }
}

$materials = $pdo->query("SELECT * FROM raw_materials WHERE status='active' ORDER BY material_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$q = trim($_GET['q'] ?? '');
$fType = trim($_GET['type'] ?? '');
$fRef = trim($_GET['ref'] ?? '');
$fMaterial = (int)($_GET['material_filter'] ?? 0);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(r.material_name LIKE :q OR r.material_code LIKE :q OR m.description LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($fType !== '') {
    $where[] = "m.movement_type = :type";
    $params[':type'] = $fType;
}
if ($fRef !== '') {
    $where[] = "m.reference_type = :ref";
    $params[':ref'] = $fRef;
}
if ($fMaterial > 0) {
    $where[] = "m.material_id = :material_filter";
    $params[':material_filter'] = $fMaterial;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT m.*, r.material_code, r.material_name, r.unit, r.category, r.stock, r.minimum_stock
                       FROM raw_material_movements m
                       JOIN raw_materials r ON r.id=m.material_id
                       $whereSql
                       ORDER BY m.id DESC
                       LIMIT 150");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$month = date('Y-m');
$stats = $pdo->query("SELECT
    COUNT(*) total_mutasi,
    COALESCE(SUM(CASE WHEN movement_type IN ('in','return') THEN total_amount ELSE 0 END),0) nilai_masuk,
    COALESCE(SUM(CASE WHEN movement_type IN ('out','damaged','lost') THEN total_amount ELSE 0 END),0) nilai_keluar,
    COALESCE(SUM(CASE WHEN DATE_FORMAT(movement_date,'%Y-%m') = '$month' THEN total_amount ELSE 0 END),0) nilai_bulan_ini
    FROM raw_material_movements")->fetch(PDO::FETCH_ASSOC);

$lowMaterials = $pdo->query("SELECT material_name, material_code, stock, minimum_stock, unit FROM raw_materials WHERE status='active' AND stock <= minimum_stock ORDER BY stock ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>
<style>
.product-page .section-card{background:#fff;border:1px solid #dfe7f1;border-radius:22px;box-shadow:0 18px 44px rgba(15,23,42,.06);overflow:hidden}.section-head{padding:18px 22px;border-bottom:1px solid #e8eef6;display:flex;align-items:center;justify-content:space-between;gap:12px}.section-title{margin:0;font-size:18px;font-weight:800;color:#142033}.section-subtitle{font-size:12px;color:#64748b;margin-top:4px}.section-body{padding:22px}.table-box{padding:0 18px 18px}.table-box .table{border-collapse:separate;border-spacing:0}.table-box thead th{background:#f6f8fb;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-top:1px solid #eef2f7;border-bottom:1px solid #e2e8f0;padding:12px 14px;white-space:nowrap}.table-box tbody td{padding:14px;border-bottom:1px solid #eef2f7;vertical-align:middle}.table-box tbody tr:last-child td{border-bottom:0}.form-label{font-weight:700;color:#334155;font-size:13px}.form-control,.form-select{border-radius:13px;border-color:#d8e2ef;min-height:44px}.btn-rounded{border-radius:999px}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px}.kpi-card{background:#fff;border:1px solid #dfe7f1;border-radius:18px;padding:18px;box-shadow:0 14px 34px rgba(15,23,42,.05)}.kpi-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#64748b}.kpi-value{font-size:22px;font-weight:900;color:#0f172a;margin-top:5px}.kpi-note{font-size:12px;color:#64748b;margin-top:4px}.mv-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800}.mv-in{background:#dcfce7;color:#166534}.mv-out{background:#fee2e2;color:#991b1b}.mv-danger{background:#fecaca;color:#7f1d1d}.mv-warning{background:#fef3c7;color:#92400e}.mv-neutral{background:#e2e8f0;color:#334155}.guide-list{margin:0;padding-left:18px;color:#475569;font-size:13px;line-height:1.7}.empty-state{padding:34px;text-align:center;color:#64748b}.material-chip{display:flex;align-items:center;gap:10px;border:1px solid #e2e8f0;border-radius:14px;padding:10px 12px;background:#fff}.material-avatar{width:38px;height:38px;border-radius:12px;background:#ecfdf5;color:#07854d;display:grid;place-items:center;font-weight:900}.small-muted{color:#64748b;font-size:12px}.filter-card{background:#fff;border:1px solid #dfe7f1;border-radius:18px;padding:14px;margin-bottom:16px}@media(max-width:1200px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:768px){.kpi-grid{grid-template-columns:1fr}.section-head{align-items:flex-start;flex-direction:column}.table-box{padding:0 10px 12px}.section-body{padding:16px}}
</style>

<main class="main-content">
    <div class="container-fluid product-page py-3">
        <?php require_once __DIR__ . '/_nav.php'; ?>

        <?php if ($success): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm">Mutasi stok berhasil disimpan.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm"><?= product_safe($error); ?></div>
        <?php endif; ?>

        <div class="kpi-grid">
            <div class="kpi-card"><div class="kpi-label">Total Mutasi</div><div class="kpi-value"><?= product_num($stats['total_mutasi'] ?? 0); ?></div><div class="kpi-note">Semua riwayat bahan</div></div>
            <div class="kpi-card"><div class="kpi-label">Nilai Masuk</div><div class="kpi-value"><?= product_money($stats['nilai_masuk'] ?? 0); ?></div><div class="kpi-note">Pembelian, donasi, return</div></div>
            <div class="kpi-card"><div class="kpi-label">Nilai Keluar</div><div class="kpi-value"><?= product_money($stats['nilai_keluar'] ?? 0); ?></div><div class="kpi-note">Produksi, rusak, hilang</div></div>
            <div class="kpi-card"><div class="kpi-label">Mutasi Bulan Ini</div><div class="kpi-value"><?= product_money($stats['nilai_bulan_ini'] ?? 0); ?></div><div class="kpi-note">Periode <?= date('M Y'); ?></div></div>
        </div>

        <div class="row g-3">
            <div class="col-xl-4">
                <div class="section-card mb-3">
                    <div class="section-head">
                        <div><h3 class="section-title">Tambah Mutasi Stok</h3><div class="section-subtitle">Catat bahan masuk, keluar, rusak, hilang, return, atau stock opname.</div></div>
                    </div>
                    <div class="section-body">
                        <form method="post" class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Bahan Baku</label>
                                <select name="material_id" class="form-select" required>
                                    <?php foreach ($materials as $m): ?>
                                        <option value="<?= (int)$m['id']; ?>" <?= $selected === (int)$m['id'] ? 'selected' : ''; ?>>
                                            <?= product_safe($m['material_name']) . ' - ' . product_num($m['stock']) . ' ' . product_safe($m['unit']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Tanggal</label>
                                <input type="date" name="movement_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Jenis</label>
                                <select name="movement_type" class="form-select" id="movementType">
                                    <option value="in">Masuk</option>
                                    <option value="out">Keluar</option>
                                    <option value="adjustment">Penyesuaian (+/-)</option>
                                    <option value="damaged">Rusak</option>
                                    <option value="lost">Hilang</option>
                                    <option value="return">Return</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Jumlah</label>
                                <input name="quantity" class="form-control qty-input" placeholder="Contoh: 10" required>
                                <div class="small-muted mt-1">Untuk penyesuaian boleh minus, contoh: -3.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Harga / Satuan</label>
                                <input name="unit_price" class="form-control money-input" value="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Referensi</label>
                                <select name="reference_type" class="form-select">
                                    <option value="manual">Manual</option>
                                    <option value="purchase">Pembelian</option>
                                    <option value="donation">Donasi</option>
                                    <option value="production">Produksi</option>
                                    <option value="stock_opname">Stock Opname</option>
                                    <option value="custom_order">Custom Order</option>
                                    <option value="other">Lainnya</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Keterangan</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Contoh: Stok awal gudang / bahan dipakai untuk produksi / hasil donasi hotel"></textarea>
                            </div>
                            <div class="col-12 d-flex gap-2 flex-wrap">
                                <button class="btn btn-success btn-rounded px-4">Simpan Mutasi</button>
                                <a class="btn btn-outline-secondary btn-rounded" href="materials.php">Lihat Gudang</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="section-card mb-3">
                    <div class="section-head"><div><h3 class="section-title">Panduan Mutasi Stok</h3><div class="section-subtitle">Gunakan jenis mutasi sesuai kondisi bahan.</div></div><span class="mv-badge mv-neutral">Guide</span></div>
                    <div class="section-body">
                        <ol class="guide-list">
                            <li><strong>Masuk</strong>: bahan dari pembelian, donasi, atau stok awal.</li>
                            <li><strong>Keluar</strong>: bahan dipakai manual di luar proses produksi otomatis.</li>
                            <li><strong>Produksi</strong>: biasanya tercatat otomatis dari menu Produksi.</li>
                            <li><strong>Penyesuaian</strong>: koreksi stok setelah stock opname, bisa plus atau minus.</li>
                            <li><strong>Rusak/Hilang</strong>: stok berkurang dan tetap tercatat sebagai riwayat.</li>
                        </ol>
                    </div>
                </div>

                <?php if ($lowMaterials): ?>
                <div class="section-card">
                    <div class="section-head"><div><h3 class="section-title">Stok Perlu Perhatian</h3><div class="section-subtitle">Bahan yang sudah mencapai minimum.</div></div><span class="mv-badge mv-warning"><?= count($lowMaterials); ?> item</span></div>
                    <div class="section-body d-grid gap-2">
                        <?php foreach ($lowMaterials as $lm): ?>
                            <div class="material-chip">
                                <div class="material-avatar"><?= strtoupper(substr($lm['material_name'],0,1)); ?></div>
                                <div class="flex-grow-1"><strong><?= product_safe($lm['material_name']); ?></strong><div class="small-muted"><?= product_safe($lm['material_code']); ?> · Min <?= product_num($lm['minimum_stock']); ?> <?= product_safe($lm['unit']); ?></div></div>
                                <strong><?= product_num($lm['stock']); ?> <?= product_safe($lm['unit']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-xl-8">
                <div class="filter-card">
                    <form class="row g-2 align-items-end" method="get">
                        <div class="col-lg-4"><label class="form-label">Cari Mutasi</label><input name="q" class="form-control" value="<?= product_safe($q); ?>" placeholder="Bahan, kode, keterangan..."></div>
                        <div class="col-lg-2"><label class="form-label">Jenis</label><select name="type" class="form-select"><option value="">Semua</option><?php foreach(['in','out','adjustment','damaged','lost','return'] as $t): ?><option value="<?= $t; ?>" <?= $fType===$t?'selected':''; ?>><?= movement_label($t); ?></option><?php endforeach; ?></select></div>
                        <div class="col-lg-2"><label class="form-label">Referensi</label><select name="ref" class="form-select"><option value="">Semua</option><?php foreach(['manual','purchase','donation','production','stock_opname','custom_order','other'] as $r): ?><option value="<?= $r; ?>" <?= $fRef===$r?'selected':''; ?>><?= ucwords(str_replace('_',' ',$r)); ?></option><?php endforeach; ?></select></div>
                        <div class="col-lg-2"><label class="form-label">Bahan</label><select name="material_filter" class="form-select"><option value="0">Semua</option><?php foreach($materials as $m): ?><option value="<?= (int)$m['id']; ?>" <?= $fMaterial===(int)$m['id']?'selected':''; ?>><?= product_safe($m['material_name']); ?></option><?php endforeach; ?></select></div>
                        <div class="col-lg-2 d-grid"><button class="btn btn-success btn-rounded">Terapkan</button></div>
                    </form>
                </div>

                <div class="section-card">
                    <div class="section-head">
                        <div><h3 class="section-title">Riwayat Mutasi Terbaru</h3><div class="section-subtitle">150 data terbaru sesuai filter. Tabel diberi ruang agar nyaman dibaca.</div></div>
                        <span class="mv-badge mv-neutral"><?= count($movements); ?> data</span>
                    </div>
                    <div class="table-box table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Tanggal</th><th>Bahan</th><th>Jenis</th><th>Referensi</th><th>Qty</th><th>Harga</th><th>Nilai</th><th>Keterangan</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!$movements): ?>
                                <tr><td colspan="8"><div class="empty-state">Belum ada mutasi sesuai filter.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($movements as $r): ?>
                                <tr>
                                    <td><strong><?= product_safe($r['movement_date']); ?></strong></td>
                                    <td><strong><?= product_safe($r['material_name']); ?></strong><div class="small-muted"><?= product_safe($r['material_code']); ?> · <?= product_safe($r['category']); ?></div></td>
                                    <td><span class="mv-badge <?= movement_class($r['movement_type']); ?>"><?= movement_label($r['movement_type']); ?></span></td>
                                    <td><span class="small-muted"><?= product_safe(ucwords(str_replace('_',' ', $r['reference_type'] ?? '-'))); ?></span></td>
                                    <td><strong><?= product_num($r['quantity']); ?></strong> <?= product_safe($r['unit']); ?></td>
                                    <td><?= product_money($r['unit_price']); ?></td>
                                    <td><strong><?= product_money($r['total_amount']); ?></strong></td>
                                    <td><?= product_safe($r['description']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
