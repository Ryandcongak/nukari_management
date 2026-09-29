<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Location: simple.php');
exit;
require_once __DIR__ . '/product_helpers.php';
require_once __DIR__ . '/../../includes/master_data_helper.php';

$pageTitle = 'Produk & Produksi - Nukari';

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$view = ($_GET['view'] ?? 'table') === 'card' ? 'card' : 'table';

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(product_code LIKE :q_code OR COALESCE(barcode_code,'') LIKE :q_barcode OR product_name LIKE :q_name OR category LIKE :q_category OR product_type LIKE :q_type OR unit LIKE :q_unit OR description LIKE :q_description)";
    $queryValue = '%' . $q . '%';
    $params[':q_code'] = $queryValue;
    $params[':q_barcode'] = $queryValue;
    $params[':q_name'] = $queryValue;
    $params[':q_category'] = $queryValue;
    $params[':q_type'] = $queryValue;
    $params[':q_unit'] = $queryValue;
    $params[':q_description'] = $queryValue;
}
if ($type !== '') {
    $where[] = "product_type = :type";
    $params[':type'] = $type;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT * FROM products $whereSql ORDER BY id DESC LIMIT 50");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query("
    SELECT
        COUNT(*) AS total_products,
        COALESCE(SUM(stock),0) AS total_stock,
        COALESCE(SUM(stock * COALESCE(NULLIF(hpp_price,0), production_cost, 0)),0) AS stock_hpp_value,
        COALESCE(SUM(stock * selling_price),0) AS stock_selling_value,
        COALESCE(SUM(CASE WHEN stock <= minimum_stock THEN 1 ELSE 0 END),0) AS low_stock,
        COALESCE(SUM(CASE WHEN product_type='recycle' THEN 1 ELSE 0 END),0) AS recycle_products,
        COALESCE(SUM(CASE WHEN product_type='eco_enzyme' THEN 1 ELSE 0 END),0) AS eco_enzyme_products,
        COALESCE(SUM(CASE WHEN product_type='fertilizer' THEN 1 ELSE 0 END),0) AS fertilizer_products,
        COALESCE(SUM(CASE WHEN product_type='custom' THEN 1 ELSE 0 END),0) AS custom_products
    FROM products
")->fetch(PDO::FETCH_ASSOC);

$mat = $pdo->query("
    SELECT
        COUNT(*) AS total_materials,
        COALESCE(SUM(stock * average_price),0) AS material_value,
        COALESCE(SUM(CASE WHEN stock <= minimum_stock THEN 1 ELSE 0 END),0) AS low_materials
    FROM raw_materials
    WHERE status='active'
")->fetch(PDO::FETCH_ASSOC);

$salesMonth = $pdo->query("
    SELECT
        COALESCE(SUM(net_amount),0) AS net_sales,
        COALESCE(SUM(profit_amount),0) AS profit_sales,
        COALESCE(SUM(qty),0) AS qty_sold
    FROM product_sales
    WHERE DATE_FORMAT(sale_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
")->fetch(PDO::FETCH_ASSOC);

$productionMonth = $pdo->query("
    SELECT COUNT(*) AS total_batches, COALESCE(SUM(total_cost),0) AS total_cost
    FROM product_productions
    WHERE DATE_FORMAT(production_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
")->fetch(PDO::FETCH_ASSOC);

$recentProductions = $pdo->query("
    SELECT pp.*, p.product_name
    FROM product_productions pp
    JOIN products p ON p.id=pp.product_id
    ORDER BY pp.id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$lowMaterials = $pdo->query("
    SELECT *
    FROM raw_materials
    WHERE status='active' AND stock <= minimum_stock
    ORDER BY stock ASC, material_name ASC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$topMaterials = $pdo->query("
    SELECT *
    FROM raw_materials
    WHERE status='active'
    ORDER BY (stock * average_price) DESC, id DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$customOrders = $pdo->query("
    SELECT *
    FROM product_custom_orders
    WHERE status IN ('inquiry','approved','production')
    ORDER BY id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

function product_photo_url($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    return base_url($path);
}
function product_initials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'PR';
    $parts = preg_split('/\s+/', $name);
    $letters = '';
    foreach ($parts as $p) {
        if ($p !== '') $letters .= mb_substr($p, 0, 1);
        if (mb_strlen($letters) >= 2) break;
    }
    return mb_strtoupper($letters);
}
function product_type_label($type) {
    $map = [
        'recycle' => 'Recycle',
        'eco_enzyme' => 'Eco Enzyme',
        'fertilizer' => 'Pupuk',
        'custom' => 'Custom',
        'service' => 'Service',
        'other' => 'Other',
    ];
    return $map[$type] ?? ucfirst((string)$type);
}
function product_margin_percent($hpp, $sell) {
    $sell = (float)$sell; $hpp = (float)$hpp;
    if ($sell <= 0) return 0;
    return (($sell - $hpp) / $sell) * 100;
}
function product_stock_display($stock, $unit) {
    $stock = (float)$stock;
    $dec = abs($stock - round($stock)) < 0.0001 ? 0 : 3;
    return number_format($stock, $dec, ',', '.') . ' ' . product_safe($unit ?: 'pcs');
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>

<style>
.product-page-pro { --nkr-green:#11874f; --nkr-dark:#123026; --nkr-soft:#f4f7f6; --nkr-line:#e3ebf2; --nkr-muted:#64748b; }
.product-hero {
    position:relative; overflow:hidden; border-radius:24px; padding:30px 34px; color:#fff;
    background:linear-gradient(135deg,#08633f,#1aa35f 62%,#e0c43b);
    box-shadow:0 18px 45px rgba(15,23,42,.08); margin-bottom:20px;
}
.product-hero:after{content:"";position:absolute;right:-55px;top:-85px;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.18);}
.product-hero .hero-actions{position:relative;z-index:2;display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.product-hero h1{font-weight:800;letter-spacing:-.03em;margin:0 0 8px;font-size:34px;}
.product-hero p{margin:0;opacity:.92;font-size:15px;}
.product-kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;margin-bottom:18px;}
.product-kpi{background:#fff;border:1px solid var(--nkr-line);border-radius:18px;padding:18px;box-shadow:0 14px 35px rgba(15,23,42,.05);min-height:106px;position:relative;overflow:hidden;}
.product-kpi:after{content:"";position:absolute;right:-22px;top:-22px;width:72px;height:72px;background:#e8fff1;border-radius:24px;}
.product-kpi .label{font-size:12px;text-transform:uppercase;font-weight:800;color:#64748b;letter-spacing:.04em;}
.product-kpi .value{font-size:24px;font-weight:900;color:#0f172a;margin-top:6px;}
.product-kpi .sub{font-size:12px;color:#64748b;margin-top:6px;}
.product-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(320px,.8fr);gap:18px;align-items:start;}
.product-card-pro{background:#fff;border:1px solid var(--nkr-line);border-radius:20px;box-shadow:0 16px 40px rgba(15,23,42,.05);overflow:hidden;margin-bottom:18px;}
.product-card-head{padding:18px 20px;border-bottom:1px solid var(--nkr-line);display:flex;justify-content:space-between;align-items:center;gap:12px;}
.product-card-head h5{margin:0;font-weight:800;color:#111827;}
.product-card-body{padding:18px 20px;}
.table-wrap-pro{padding:0 18px 18px;overflow:auto;}
.table-pro{width:100%;border-collapse:separate;border-spacing:0;}
.table-pro th{background:#f5f7fa;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.06em;padding:12px 14px;border-bottom:1px solid var(--nkr-line);white-space:nowrap;}
.table-pro td{padding:14px;border-bottom:1px solid #edf2f7;vertical-align:middle;background:#fff;}
.table-pro tr:last-child td{border-bottom:0;}
.product-thumb{width:64px;height:64px;border-radius:16px;object-fit:cover;border:1px solid #e2e8f0;background:#f1f5f9;}
.product-thumb-empty{width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#e8fff1,#f8fafc);display:flex;align-items:center;justify-content:center;font-weight:900;color:#11874f;border:1px solid #dbeafe;}
.product-title{font-weight:850;color:#0f172a;line-height:1.2;}
.product-meta{font-size:12px;color:#64748b;margin-top:3px;}
.product-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:800;background:#eef6ff;color:#2563eb;}
.product-badge.green{background:#dcfce7;color:#15803d;}
.product-badge.yellow{background:#fef9c3;color:#a16207;}
.product-badge.gray{background:#f1f5f9;color:#475569;}
.barcode-text{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px;font-weight:800;color:#334155;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:3px 7px;display:inline-flex;align-items:center;gap:4px;}
.donation-note{font-size:11px;color:#15803d;background:#dcfce7;border-radius:999px;padding:3px 8px;font-weight:800;display:inline-flex;margin-top:5px;}
.action-icon{width:32px;height:32px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;}
.action-group{display:flex;gap:6px;flex-wrap:wrap;}
.btn-mini{border-radius:10px;padding:6px 9px;font-size:12px;font-weight:700;}
.filter-card{padding:16px 18px;background:#fff;border:1px solid var(--nkr-line);border-radius:18px;margin-bottom:18px;box-shadow:0 14px 35px rgba(15,23,42,.04);}
.quick-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;}
.quick-action{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--nkr-line);border-radius:16px;background:#fff;text-decoration:none;color:#1f2937;font-weight:800;}
.quick-action:hover{border-color:#11874f;color:#11874f;background:#f7fffb;}
.product-card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:0 18px 18px;}
.product-item-card{border:1px solid var(--nkr-line);border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 12px 28px rgba(15,23,42,.04);}
.product-item-photo{height:155px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;}
.product-item-photo img{width:100%;height:100%;object-fit:cover;}
.product-item-photo .initial{font-size:38px;font-weight:900;color:#11874f;}
.product-item-body{padding:14px;}
.product-metrics{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:12px;}
.metric-mini{border:1px solid #e8eef5;border-radius:12px;padding:9px;background:#f8fafc;}
.metric-mini span{display:block;font-size:10px;color:#64748b;text-transform:uppercase;font-weight:800;}
.metric-mini strong{font-size:13px;color:#0f172a;}
.side-list{display:flex;flex-direction:column;gap:10px;}
.side-item{border:1px solid #edf2f7;border-radius:16px;padding:12px;background:#fff;display:flex;gap:12px;align-items:center;}
.material-visual{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#e8fff1,#eef6ff);color:#11874f;font-weight:900;flex-shrink:0;}
.empty-state{padding:26px;text-align:center;color:#64748b;background:#fff;border-radius:16px;}
@media (max-width: 1400px){.product-kpi-grid{grid-template-columns:repeat(3,1fr)}.product-card-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width: 992px){.product-layout{grid-template-columns:1fr}.product-kpi-grid{grid-template-columns:repeat(2,1fr)}.quick-grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width: 576px){.product-kpi-grid,.quick-grid,.product-card-grid{grid-template-columns:1fr}.product-hero{padding:24px}.product-hero h1{font-size:28px}}
</style>

<main class="main-content">
<div class="container-fluid product-page product-page-pro py-3">
    <?php require_once __DIR__ . '/_nav.php'; ?>

    <section class="product-hero">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <div class="small text-uppercase fw-bold mb-2">Produk & Produksi Nukari</div>
                <h1>Dashboard Produk</h1>
                <p>Pantau produk recycle, eco enzyme, bahan baku, produksi, HPP, penjualan, dan profit dalam satu halaman.</p>
            </div>
            <div class="col-lg-5">
                <div class="hero-actions">
                    <a href="add.php" class="btn btn-light btn-rounded fw-bold"><i class="bi bi-plus-circle me-1"></i> Produk Baru</a>
                    <a href="print_barcode_all.php" target="_blank" class="btn btn-outline-light btn-rounded fw-bold"><i class="bi bi-upc-scan me-1"></i> Cetak Barcode</a>
                    <a href="production.php" class="btn btn-outline-light btn-rounded fw-bold"><i class="bi bi-gear me-1"></i> Produksi</a>
                    <a href="calculator.php" class="btn btn-warning btn-rounded fw-bold"><i class="bi bi-calculator me-1"></i> Kalkulator HPP</a>
                </div>
            </div>
        </div>
    </section>

    <section class="product-kpi-grid">
        <div class="product-kpi"><div class="label">Total Produk</div><div class="value"><?= (int)$summary['total_products']; ?></div><div class="sub"><?= product_num($summary['total_stock'] ?? 0); ?> total stok</div></div>
        <div class="product-kpi"><div class="label">Nilai Stok HPP</div><div class="value"><?= product_money($summary['stock_hpp_value']); ?></div><div class="sub">Modal produk tersedia</div></div>
        <div class="product-kpi"><div class="label">Potensi Jual</div><div class="value"><?= product_money($summary['stock_selling_value']); ?></div><div class="sub">Jika stok terjual</div></div>
        <div class="product-kpi"><div class="label">Bahan Baku</div><div class="value"><?= product_money($mat['material_value']); ?></div><div class="sub"><?= (int)$mat['total_materials']; ?> bahan aktif</div></div>
        <div class="product-kpi"><div class="label">Profit Bulan Ini</div><div class="value"><?= product_money($salesMonth['profit_sales']); ?></div><div class="sub">Net <?= product_money($salesMonth['net_sales']); ?></div></div>
        <div class="product-kpi"><div class="label">Produksi Bulan Ini</div><div class="value"><?= (int)$productionMonth['total_batches']; ?></div><div class="sub"><?= product_money($productionMonth['total_cost']); ?> biaya</div></div>
    </section>

    <section class="filter-card">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="view" value="<?= product_safe($view); ?>">
            <div class="col-lg-5">
                <label class="form-label small fw-bold">Cari Produk</label>
                <input type="text" name="q" class="form-control" placeholder="Nama, kode produk, barcode, kategori, tipe, satuan..." value="<?= product_safe($q); ?>">
            </div>
            <div class="col-lg-3">
                <label class="form-label small fw-bold">Tipe Produk</label>
                <select name="type" class="form-select">
                    <option value="">Semua Tipe</option>
                    <?=master_options($pdo,'product_type',$type);?>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-success btn-rounded"><i class="bi bi-funnel me-1"></i> Filter</button>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <a href="index.php?view=table" class="btn <?= $view==='table'?'btn-success':'btn-outline-secondary'; ?> btn-rounded flex-fill"><i class="bi bi-table"></i></a>
                <a href="index.php?view=card" class="btn <?= $view==='card'?'btn-success':'btn-outline-secondary'; ?> btn-rounded flex-fill"><i class="bi bi-grid-3x3-gap"></i></a>
            </div>
        </form>
    </section>

    <section class="product-card-pro">
        <div class="product-card-head">
            <h5>Quick Action</h5>
            <span class="product-badge green">Operasional Produk</span>
        </div>
        <div class="product-card-body">
            <div class="quick-grid">
                <a class="quick-action" href="add.php"><i class="bi bi-plus-circle"></i> Tambah Produk</a>
                <a class="quick-action" href="materials.php"><i class="bi bi-boxes"></i> Gudang Bahan</a>
                <a class="quick-action" href="production.php"><i class="bi bi-gear"></i> Input Produksi</a>
                <a class="quick-action" href="sales.php"><i class="bi bi-cart"></i> Input Penjualan</a>
            </div>
        </div>
    </section>

    <div class="product-layout">
        <div>
            <section class="product-card-pro">
                <div class="product-card-head">
                    <h5>Produk Jadi</h5>
                    <div class="d-flex gap-2">
                        <span class="product-badge gray"><?= count($products); ?> data</span>
                        <a href="add.php" class="btn btn-success btn-sm btn-rounded"><i class="bi bi-plus"></i> Tambah</a>
                    </div>
                </div>

                <?php if ($view === 'card'): ?>
                    <div class="product-card-grid">
                        <?php foreach ($products as $p): 
                            $hpp = (float)($p['hpp_price'] ?: $p['production_cost']);
                            $sell = (float)$p['selling_price'];
                            $profit = $sell - $hpp;
                            $margin = product_margin_percent($hpp, $sell);
                            $photo = product_photo_url($p['photo'] ?? '');
                        ?>
                        <div class="product-item-card">
                            <div class="product-item-photo">
                                <?php if ($photo): ?>
                                    <img src="<?= product_safe($photo); ?>" alt="<?= product_safe($p['product_name']); ?>" data-bs-toggle="modal" data-bs-target="#photoModal" data-photo="<?= product_safe($photo); ?>" data-title="<?= product_safe($p['product_name']); ?>" style="cursor:pointer;">
                                <?php else: ?>
                                    <div class="initial"><?= product_initials($p['product_name']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="product-item-body">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <div>
                                        <div class="product-title"><?= product_safe($p['product_name']); ?></div>
                                        <div class="product-meta"><?= product_safe($p['product_code']); ?> • <?= product_type_label($p['product_type']); ?></div>
                                        <div class="mt-1"><span class="barcode-text"><i class="bi bi-upc-scan"></i><?= product_safe($p['barcode_code'] ?? $p['product_code']); ?></span></div>
                                        <?php if (!empty($p['contribution_percent']) && (float)$p['contribution_percent'] > 0): ?>
                                            <div class="donation-note">Donasi <?= product_num($p['contribution_percent'],2); ?>%</div>
                                        <?php endif; ?>
                                    </div>
                                    <span class="product-badge <?= ($p['stock'] <= $p['minimum_stock']) ? 'yellow' : 'green'; ?>"><?= product_stock_display($p['stock'], $p['unit']); ?></span>
                                </div>
                                <div class="product-metrics">
                                    <div class="metric-mini"><span>HPP</span><strong><?= product_money($hpp); ?></strong></div>
                                    <div class="metric-mini"><span>Jual</span><strong><?= product_money($sell); ?></strong></div>
                                    <div class="metric-mini"><span>Profit/Unit</span><strong><?= product_money($profit); ?></strong></div>
                                    <div class="metric-mini"><span>Margin</span><strong><?= product_num($margin,2); ?>%</strong></div>
                                </div>
                                <div class="action-group mt-3">
                                    <a class="btn btn-outline-success btn-mini" target="_blank" href="print_barcode.php?id=<?= (int)$p['id']; ?>"><i class="bi bi-upc-scan"></i> Barcode</a>
                                    <a class="btn btn-outline-primary btn-mini" href="edit.php?id=<?= (int)$p['id']; ?>">Edit</a>
                                    <a class="btn btn-outline-danger btn-mini" onclick="return confirm('Hapus produk ini?')" href="delete.php?id=<?= (int)$p['id']; ?>">Hapus</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!$products): ?><div class="empty-state">Belum ada produk.</div><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap-pro">
                        <table class="table-pro">
                            <thead>
                                <tr>
                                    <th>Produk</th>
                                    <th>Tipe</th>
                                    <th>Stok</th>
                                    <th>HPP</th>
                                    <th>Harga Jual</th>
                                    <th>Profit/Unit</th>
                                    <th>Margin</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($products as $p):
                                $hpp = (float)($p['hpp_price'] ?: $p['production_cost']);
                                $sell = (float)$p['selling_price'];
                                $profit = $sell - $hpp;
                                $margin = product_margin_percent($hpp, $sell);
                                $photo = product_photo_url($p['photo'] ?? '');
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if ($photo): ?>
                                                <img src="<?= product_safe($photo); ?>" class="product-thumb" alt="<?= product_safe($p['product_name']); ?>" data-bs-toggle="modal" data-bs-target="#photoModal" data-photo="<?= product_safe($photo); ?>" data-title="<?= product_safe($p['product_name']); ?>" style="cursor:pointer;">
                                            <?php else: ?>
                                                <div class="product-thumb-empty"><?= product_initials($p['product_name']); ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="product-title"><?= product_safe($p['product_name']); ?></div>
                                                <div class="product-meta"><?= product_safe($p['product_code']); ?><?= $p['category'] ? ' • '.product_safe($p['category']) : ''; ?></div>
                                                <div class="mt-1"><span class="barcode-text"><i class="bi bi-upc-scan"></i><?= product_safe($p['barcode_code'] ?? $p['product_code']); ?></span></div>
                                                <?php if (!empty($p['contribution_percent']) && (float)$p['contribution_percent'] > 0): ?>
                                                    <div class="donation-note">Program sosial <?= product_num($p['contribution_percent'],2); ?>%</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="product-badge"><?= product_type_label($p['product_type']); ?></span></td>
                                    <td>
                                        <strong><?= product_stock_display($p['stock'], $p['unit']); ?></strong>
                                        <div class="small text-muted">Min <?= product_num($p['minimum_stock'] ?? 0); ?></div>
                                    </td>
                                    <td><?= product_money($hpp); ?></td>
                                    <td><?= product_money($sell); ?></td>
                                    <td><strong><?= product_money($profit); ?></strong></td>
                                    <td><?= product_num($margin,2); ?>%</td>
                                    <td>
                                        <div class="action-group">
                                            <a class="btn btn-outline-success btn-mini" target="_blank" href="print_barcode.php?id=<?= (int)$p['id']; ?>"><i class="bi bi-upc-scan"></i> Barcode</a>
                                            <a class="btn btn-outline-primary btn-mini" href="edit.php?id=<?= (int)$p['id']; ?>">Edit</a>
                                            <a class="btn btn-outline-danger btn-mini" onclick="return confirm('Hapus produk ini?')" href="delete.php?id=<?= (int)$p['id']; ?>">Hapus</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?>
                                <tr><td colspan="8"><div class="empty-state">Belum ada produk.</div></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="product-card-pro">
                <div class="product-card-head">
                    <h5>Produksi Terbaru</h5>
                    <a href="production.php" class="btn btn-outline-success btn-sm btn-rounded">Lihat Produksi</a>
                </div>
                <div class="table-wrap-pro">
                    <table class="table-pro">
                        <thead><tr><th>Kode</th><th>Produk</th><th>Output</th><th>Total Cost</th><th>HPP</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentProductions as $r): ?>
                            <tr>
                                <td><?= product_safe($r['production_code']); ?></td>
                                <td><?= product_safe($r['product_name']); ?></td>
                                <td><?= product_num($r['output_qty']); ?> <?= product_safe($r['output_unit']); ?></td>
                                <td><?= product_money($r['total_cost']); ?></td>
                                <td><?= product_money($r['hpp_per_unit']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$recentProductions): ?><tr><td colspan="5"><div class="empty-state">Belum ada produksi.</div></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <aside>
            <section class="product-card-pro">
                <div class="product-card-head">
                    <h5>Bahan Baku Visual</h5>
                    <a href="materials.php" class="btn btn-outline-success btn-sm btn-rounded">Gudang</a>
                </div>
                <div class="product-card-body">
                    <div class="side-list">
                        <?php foreach ($topMaterials as $m): ?>
                            <div class="side-item">
                                <div class="material-visual"><?= product_initials($m['material_name']); ?></div>
                                <div class="flex-grow-1">
                                    <div class="product-title"><?= product_safe($m['material_name']); ?></div>
                                    <div class="product-meta"><?= product_safe($m['category']); ?> • <?= product_stock_display($m['stock'], $m['unit']); ?></div>
                                </div>
                                <strong><?= product_money($m['stock'] * $m['average_price']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$topMaterials): ?><div class="empty-state">Belum ada bahan baku.</div><?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="product-card-pro">
                <div class="product-card-head">
                    <h5>Stok Perlu Perhatian</h5>
                    <span class="product-badge yellow"><?= (int)$summary['low_stock'] + (int)$mat['low_materials']; ?> item</span>
                </div>
                <div class="product-card-body">
                    <div class="side-list">
                        <?php foreach ($products as $p): if ((float)$p['stock'] > (float)$p['minimum_stock']) continue; ?>
                            <div class="side-item">
                                <div class="material-visual"><i class="bi bi-box-seam"></i></div>
                                <div>
                                    <div class="product-title"><?= product_safe($p['product_name']); ?></div>
                                    <div class="product-meta">Stok <?= product_stock_display($p['stock'], $p['unit']); ?> • Min <?= product_num($p['minimum_stock']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($lowMaterials as $m): ?>
                            <div class="side-item">
                                <div class="material-visual"><i class="bi bi-exclamation-triangle"></i></div>
                                <div>
                                    <div class="product-title"><?= product_safe($m['material_name']); ?></div>
                                    <div class="product-meta">Stok <?= product_stock_display($m['stock'], $m['unit']); ?> • Min <?= product_num($m['minimum_stock']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (((int)$summary['low_stock'] + (int)$mat['low_materials']) === 0): ?><div class="empty-state">Semua stok masih aman.</div><?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="product-card-pro">
                <div class="product-card-head">
                    <h5>Custom Order Aktif</h5>
                    <a href="custom_orders.php" class="btn btn-outline-success btn-sm btn-rounded">Lihat</a>
                </div>
                <div class="product-card-body">
                    <div class="side-list">
                        <?php foreach ($customOrders as $o): ?>
                            <div class="side-item">
                                <div class="material-visual"><i class="bi bi-brush"></i></div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div class="product-title"><?= product_safe($o['product_name']); ?></div>
                                        <?= product_badge($o['status']); ?>
                                    </div>
                                    <div class="product-meta"><?= product_safe($o['customer_name']); ?> • <?= product_money($o['offered_price']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$customOrders): ?><div class="empty-state">Belum ada custom order aktif.</div><?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="product-card-pro">
                <div class="product-card-head"><h5>Komposisi Produk</h5></div>
                <div class="product-card-body">
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Recycle Product</span><strong><?= (int)$summary['recycle_products']; ?></strong></div>
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Eco Enzyme</span><strong><?= (int)$summary['eco_enzyme_products']; ?></strong></div>
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>Produk Pupuk</span><strong><?= (int)$summary['fertilizer_products']; ?></strong></div>
                    <div class="d-flex justify-content-between py-2"><span>Custom Product</span><strong><?= (int)$summary['custom_products']; ?></strong></div>
                </div>
            </section>
        </aside>
    </div>
</div>
</main>

<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:22px; overflow:hidden;">
            <div class="modal-header">
                <h5 class="modal-title" id="photoModalTitle">Preview Foto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 bg-light text-center">
                <img id="photoModalImg" src="" alt="Preview" style="max-width:100%; max-height:75vh; object-fit:contain;">
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('show.bs.modal', function (event) {
    const trigger = event.relatedTarget;
    if (!trigger || !trigger.dataset.photo) return;
    document.getElementById('photoModalImg').src = trigger.dataset.photo;
    document.getElementById('photoModalTitle').textContent = trigger.dataset.title || 'Preview Foto';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
