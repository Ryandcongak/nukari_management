<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Location: simple.php?info=' . urlencode('Modul Produksi sudah digantikan oleh Produk Sederhana.'));
exit;
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Produksi Produk - Nukari';

function production_safe($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function production_status_badge($status)
{
    $status = strtolower((string)$status);
    $map = [
        'completed' => ['Selesai', 'success'],
        'draft' => ['Draft', 'secondary'],
        'cancelled' => ['Dibatalkan', 'danger'],
    ];
    [$label, $class] = $map[$status] ?? [$status ?: '-', 'secondary'];
    return '<span class="badge rounded-pill bg-' . $class . '">' . production_safe($label) . '</span>';
}

function production_type_label($type)
{
    $map = [
        'recycle' => 'Recycle',
        'eco_enzyme' => 'Eco Enzyme',
        'custom' => 'Custom',
        'other' => 'Lainnya',
    ];
    return $map[$type] ?? ucfirst((string)$type);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $pdo->beginTransaction();
        try {
            $productId = (int)($_POST['product_id'] ?? 0);
            $qtyOut = product_parse_number($_POST['output_qty'] ?? 0);
            $labor = product_parse_number($_POST['labor_cost'] ?? 0);
            $over = product_parse_number($_POST['overhead_cost'] ?? 0);
            $matCost = 0;

            if ($productId <= 0) {
                throw new Exception('Produk jadi wajib dipilih.');
            }
            if ($qtyOut <= 0) {
                throw new Exception('Hasil produksi harus lebih dari 0.');
            }

            $materialIds = $_POST['material_id'] ?? [];
            $materialQtys = $_POST['material_qty'] ?? [];
            $materialPrices = $_POST['material_price'] ?? [];

            foreach ($materialIds as $i => $midRaw) {
                $mid = (int)$midRaw;
                $q = product_parse_number($materialQtys[$i] ?? 0);
                if ($mid <= 0 || $q <= 0) {
                    continue;
                }

                $check = $pdo->prepare("SELECT material_name, stock, unit FROM raw_materials WHERE id=? AND status='active' LIMIT 1");
                $check->execute([$mid]);
                $material = $check->fetch(PDO::FETCH_ASSOC);
                if (!$material) {
                    throw new Exception('Bahan produksi tidak ditemukan.');
                }
                if ((float)$material['stock'] < $q) {
                    throw new Exception('Stok bahan ' . $material['material_name'] . ' tidak cukup. Stok tersedia: ' . product_num($material['stock']) . ' ' . $material['unit']);
                }
            }

            $stmt = $pdo->prepare("INSERT INTO product_productions 
                (production_code, product_id, production_date, production_type, output_qty, output_unit, labor_cost, overhead_cost, status, notes, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                product_code('PROD'),
                $productId,
                $_POST['production_date'] ?? date('Y-m-d'),
                $_POST['production_type'] ?? 'recycle',
                $qtyOut,
                $_POST['output_unit'] ?? 'pcs',
                $labor,
                $over,
                'completed',
                trim($_POST['notes'] ?? ''),
                product_get_user_id()
            ]);
            $prodId = (int)$pdo->lastInsertId();

            foreach ($materialIds as $i => $midRaw) {
                $mid = (int)$midRaw;
                $q = product_parse_number($materialQtys[$i] ?? 0);
                $price = product_parse_number($materialPrices[$i] ?? 0);
                if ($mid <= 0 || $q <= 0) {
                    continue;
                }

                $tot = $q * $price;
                $matCost += $tot;

                $pdo->prepare("INSERT INTO product_production_materials (production_id, material_id, quantity, unit_price, total_amount) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$prodId, $mid, $q, $price, $tot]);

                $pdo->prepare("INSERT INTO raw_material_movements 
                    (material_id, movement_date, movement_type, quantity, unit_price, total_amount, reference_type, reference_id, description, created_by, created_at) 
                    VALUES (?, ?, 'out', ?, ?, ?, 'production', ?, ?, ?, NOW())")
                    ->execute([
                        $mid,
                        $_POST['production_date'] ?? date('Y-m-d'),
                        $q,
                        $price,
                        $tot,
                        $prodId,
                        'Pemakaian bahan untuk produksi #' . $prodId,
                        product_get_user_id()
                    ]);

                product_recalc_material_stock($pdo, $mid);
            }

            $total = $matCost + $labor + $over;
            $hpp = $qtyOut > 0 ? $total / $qtyOut : 0;

            $pdo->prepare("UPDATE product_productions SET material_cost=?, total_cost=?, hpp_per_unit=? WHERE id=?")
                ->execute([$matCost, $total, $hpp, $prodId]);

            $pdo->prepare("UPDATE products SET stock=stock+?, hpp_price=?, production_cost=?, profit_estimate=selling_price-?, updated_at=NOW() WHERE id=?")
                ->execute([$qtyOut, $hpp, $hpp, $hpp, $productId]);

            $pdo->commit();
            header('Location: production.php?success=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$q = trim($_GET['q'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(pp.production_code LIKE ? OR p.product_name LIKE ? OR pp.notes LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($typeFilter !== '') {
    $where[] = "pp.production_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter !== '') {
    $where[] = "pp.status = ?";
    $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$products = $pdo->query("SELECT * FROM products WHERE status='active' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$materials = $pdo->query("SELECT * FROM raw_materials WHERE status='active' ORDER BY material_name")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT pp.*, p.product_name, p.product_code, p.photo 
    FROM product_productions pp 
    JOIN products p ON p.id = pp.product_id 
    $whereSql
    ORDER BY pp.id DESC 
    LIMIT 100");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpi = $pdo->query("SELECT 
    COUNT(*) AS total_batch,
    COALESCE(SUM(output_qty),0) AS total_output,
    COALESCE(SUM(total_cost),0) AS total_cost,
    COALESCE(AVG(NULLIF(hpp_per_unit,0)),0) AS avg_hpp
    FROM product_productions
    WHERE status='completed'")->fetch(PDO::FETCH_ASSOC);

$recentMaterialCost = $pdo->query("SELECT COALESCE(SUM(material_cost),0) FROM product_productions WHERE status='completed' AND production_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$lowMaterials = $pdo->query("SELECT COUNT(*) FROM raw_materials WHERE status='active' AND stock <= minimum_stock AND minimum_stock > 0")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>
<style>
.production-hero{
    background:linear-gradient(135deg,#065f46,#16a34a 62%,#facc15);
    border-radius:24px;
    color:#fff;
    padding:28px 32px;
    position:relative;
    overflow:hidden;
    box-shadow:0 18px 40px rgba(15,23,42,.10);
}
.production-hero::after{
    content:'';
    position:absolute;
    right:-70px;
    top:-80px;
    width:240px;
    height:240px;
    background:rgba(255,255,255,.16);
    border-radius:50%;
}
.product-card.pro-card{
    border:1px solid #e2e8f0;
    border-radius:22px;
    overflow:hidden;
    background:#fff;
    box-shadow:0 16px 36px rgba(15,23,42,.07);
}
.pro-card .card-header{
    padding:18px 22px;
    border-bottom:1px solid #e2e8f0;
    background:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}
.pro-card .card-body{padding:22px;}
.table-wrap{padding:0 18px 18px; overflow-x:auto;}
.table-wrap .table{margin:0; border-collapse:separate; border-spacing:0;}
.table-wrap .table thead th{background:#f8fafc; color:#64748b; font-size:12px; letter-spacing:.05em; text-transform:uppercase; border-bottom:1px solid #e2e8f0; white-space:nowrap;}
.table-wrap .table tbody td{vertical-align:middle; border-bottom:1px solid #edf2f7; padding:14px 12px;}
.kpi-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;padding:18px;box-shadow:0 12px 28px rgba(15,23,42,.06);min-height:100%;}
.kpi-card small{display:block;color:#64748b;font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:.04em;margin-bottom:6px;}
.kpi-card strong{font-size:24px;color:#0f172a;}
.kpi-card span{display:block;color:#64748b;font-size:13px;margin-top:4px;}
.guide-card{border:1px solid #dbeafe;background:#f8fbff;border-radius:20px;padding:20px;color:#334155;}
.guide-card h6{font-weight:800;margin-bottom:10px;color:#0f172a;}
.guide-card ol{margin-bottom:0;padding-left:18px;}
.summary-box{border:1px solid #dbeafe;background:#f8fafc;border-radius:18px;padding:16px;}
.summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
.summary-item{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px;}
.summary-item small{display:block;color:#64748b;font-size:11px;text-transform:uppercase;font-weight:800;}
.summary-item strong{display:block;font-size:17px;color:#0f172a;margin-top:3px;}
.material-row{padding:10px;border:1px solid #e2e8f0;border-radius:16px;background:#f8fafc;}
.empty-state{padding:34px 16px;text-align:center;color:#64748b;}
@media(max-width:768px){.production-hero{padding:24px}.summary-grid{grid-template-columns:1fr}.table-wrap{padding:0 12px 12px}.pro-card .card-body{padding:18px}}
</style>

<main class="main-content">
    <div class="container-fluid product-page py-3">
        <?php require_once __DIR__ . '/_nav.php'; ?>

        <section class="production-hero mb-3">
            <div class="position-relative" style="z-index:1;">
                <div class="text-uppercase fw-bold small opacity-75 mb-1">Produksi Produk Nukari</div>
                <h1 class="h2 fw-bold mb-2">Input Produksi & Perhitungan HPP</h1>
                <p class="mb-0">Catat bahan yang dipakai, biaya tenaga kerja, overhead, output produksi, dan otomatis update stok produk jadi.</p>
            </div>
        </section>

        <?php if (!empty($_GET['success'])): ?>
            <div class="alert alert-success rounded-4">Produksi berhasil disimpan dan stok produk sudah diperbarui.</div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-4"><?= production_safe($error); ?></div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="kpi-card"><small>Total Batch</small><strong><?= product_num($kpi['total_batch'] ?? 0); ?></strong><span>Produksi selesai</span></div></div>
            <div class="col-md-3"><div class="kpi-card"><small>Total Output</small><strong><?= product_num($kpi['total_output'] ?? 0); ?></strong><span>Akumulasi produk jadi</span></div></div>
            <div class="col-md-3"><div class="kpi-card"><small>Total Biaya Produksi</small><strong><?= product_money($kpi['total_cost'] ?? 0); ?></strong><span>Semua batch selesai</span></div></div>
            <div class="col-md-3"><div class="kpi-card"><small>Bahan Perlu Cek</small><strong><?= product_num($lowMaterials); ?></strong><span>Stok minimum/restock</span></div></div>
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="product-card pro-card mb-3">
                    <div class="card-header">
                        <strong>Input Produksi</strong>
                        <span class="badge rounded-pill text-bg-success">Batch Baru</span>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3" id="productionForm">
                            <input type="hidden" name="action" value="create">
                            <div class="col-12">
                                <label class="form-label">Produk Jadi</label>
                                <select name="product_id" class="form-select" required>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= (int)$p['id']; ?>"><?= production_safe($p['product_name']); ?> - stok <?= product_num($p['stock']); ?> <?= production_safe($p['unit']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Tanggal</label>
                                <input type="date" name="production_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Tipe</label>
                                <select name="production_type" class="form-select">
                                    <option value="recycle">Recycle</option>
                                    <option value="eco_enzyme">Eco Enzyme</option>
                                    <option value="fertilizer">Pupuk</option>
                                    <option value="custom">Custom</option>
                                    <option value="other">Lainnya</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Hasil Produksi</label>
                                <input name="output_qty" class="form-control qty-input js-calc" required placeholder="Contoh: 20">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Satuan Output</label>
                                <select name="output_unit" class="form-select">
                                    <option value="pcs">pcs</option>
                                    <option value="botol">botol</option>
                                    <option value="liter">liter</option>
                                    <option value="kg">kg</option>
                                    <option value="pack">pack</option>
                                    <option value="unit">unit</option>
                                    <option value="other">other</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Biaya Tenaga Kerja</label>
                                <input name="labor_cost" class="form-control money-input js-calc" value="0">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Biaya Overhead</label>
                                <input name="overhead_cost" class="form-control money-input js-calc" value="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Bahan Dipakai</label>
                                <div id="materialsBox" class="d-grid gap-2"></div>
                                <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addMaterial()">+ Tambah Bahan</button>
                            </div>

                            <div class="col-12">
                                <div class="summary-box">
                                    <div class="fw-bold mb-2">Ringkasan Otomatis</div>
                                    <div class="summary-grid">
                                        <div class="summary-item"><small>Biaya Bahan</small><strong id="sumMaterial">Rp 0</strong></div>
                                        <div class="summary-item"><small>Tenaga Kerja</small><strong id="sumLabor">Rp 0</strong></div>
                                        <div class="summary-item"><small>Overhead</small><strong id="sumOverhead">Rp 0</strong></div>
                                        <div class="summary-item"><small>Total Produksi</small><strong id="sumTotal">Rp 0</strong></div>
                                        <div class="summary-item"><small>Output</small><strong id="sumOutput">0</strong></div>
                                        <div class="summary-item"><small>HPP / Unit</small><strong id="sumHpp">Rp 0</strong></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Catatan Produksi</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Contoh: Batch produksi pot recycle kecil untuk stok bazar."></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-success btn-rounded">Simpan Produksi</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="guide-card mb-3">
                    <h6>Panduan Produksi</h6>
                    <ol>
                        <li>Pilih produk jadi yang akan ditambahkan stoknya.</li>
                        <li>Isi jumlah hasil produksi dan bahan yang dipakai.</li>
                        <li>Sistem otomatis mengurangi stok bahan baku.</li>
                        <li>Sistem otomatis menambah stok produk jadi.</li>
                        <li>HPP dihitung dari bahan + tenaga kerja + overhead dibagi output.</li>
                    </ol>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="product-card pro-card mb-3">
                    <div class="card-header">
                        <div>
                            <strong>Filter Riwayat Produksi</strong>
                            <div class="small text-muted">Cari batch produksi berdasarkan kode, produk, tipe, atau status.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form class="row g-2" method="get">
                            <div class="col-lg-5"><input type="text" name="q" class="form-control" value="<?= production_safe($q); ?>" placeholder="Cari kode / produk / catatan..."></div>
                            <div class="col-lg-3">
                                <select name="type" class="form-select">
                                    <option value="">Semua Tipe</option>
                                    <?php foreach (['recycle'=>'Recycle','eco_enzyme'=>'Eco Enzyme','fertilizer'=>'Pupuk','custom'=>'Custom','other'=>'Lainnya'] as $val=>$label): ?>
                                        <option value="<?= $val; ?>" <?= $typeFilter===$val?'selected':''; ?>><?= $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-3">
                                <select name="status" class="form-select">
                                    <option value="">Semua Status</option>
                                    <?php foreach (['completed'=>'Selesai','draft'=>'Draft','cancelled'=>'Dibatalkan'] as $val=>$label): ?>
                                        <option value="<?= $val; ?>" <?= $statusFilter===$val?'selected':''; ?>><?= $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-1 d-grid"><button class="btn btn-success">Filter</button></div>
                        </form>
                    </div>
                </div>

                <div class="product-card pro-card">
                    <div class="card-header">
                        <strong>Riwayat Produksi</strong>
                        <span class="badge rounded-pill text-bg-light"><?= count($rows); ?> data</span>
                    </div>
                    <div class="table-wrap">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Produk</th>
                                    <th>Tanggal</th>
                                    <th>Tipe</th>
                                    <th class="text-end">Output</th>
                                    <th class="text-end">Material</th>
                                    <th class="text-end">TK + OH</th>
                                    <th class="text-end">Total Cost</th>
                                    <th class="text-end">HPP</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                    <tr><td colspan="10"><div class="empty-state">Belum ada data produksi sesuai filter.</div></td></tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><strong><?= production_safe($r['production_code']); ?></strong></td>
                                        <td>
                                            <div class="fw-bold"><?= production_safe($r['product_name']); ?></div>
                                            <div class="small text-muted"><?= production_safe($r['product_code']); ?></div>
                                        </td>
                                        <td><?= production_safe($r['production_date']); ?></td>
                                        <td><span class="badge rounded-pill text-bg-light"><?= production_safe(production_type_label($r['production_type'])); ?></span></td>
                                        <td class="text-end"><?= product_num($r['output_qty']) . ' ' . production_safe($r['output_unit']); ?></td>
                                        <td class="text-end"><?= product_money($r['material_cost']); ?></td>
                                        <td class="text-end"><?= product_money(((float)$r['labor_cost']) + ((float)$r['overhead_cost'])); ?></td>
                                        <td class="text-end fw-bold"><?= product_money($r['total_cost']); ?></td>
                                        <td class="text-end fw-bold"><?= product_money($r['hpp_per_unit']); ?></td>
                                        <td><?= production_status_badge($r['status']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 pb-4 small text-muted">
                        Catatan: produksi yang sudah tersimpan akan mempengaruhi stok bahan baku, stok produk jadi, HPP, dan laporan produk.
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
const mats = <?= json_encode($materials); ?>;

function productionParseNumber(v){
    v = String(v ?? '').replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',', '.');
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
}
function productionFormatMoney(n){
    n = Math.round(Number(n) || 0);
    return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
function productionFormatId(n){
    n = String(n ?? '0').replace(/[^0-9,.-]/g,'');
    let num = parseFloat(n);
    if (isNaN(num)) num = 0;
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
function productionFormatQty(n){
    n = Number(n) || 0;
    return n.toLocaleString('id-ID', {minimumFractionDigits:0, maximumFractionDigits:3});
}
function productionBindInputs(scope){
    (scope || document).querySelectorAll('.money-input').forEach(input => {
        input.addEventListener('input', function(){
            const pos = this.selectionStart;
            this.value = productionFormatId(this.value);
            try { this.setSelectionRange(this.value.length, this.value.length); } catch(e) {}
            calculateProduction();
        });
    });
    (scope || document).querySelectorAll('.js-calc, .qty-input').forEach(input => {
        input.addEventListener('input', calculateProduction);
        input.addEventListener('change', calculateProduction);
    });
}
function addMaterial(){
    let o = '<div class="material-row"><div class="row g-2 align-items-center">';
    o += '<div class="col-lg-5"><select name="material_id[]" class="form-select material-select">';
    mats.forEach(m => {
        o += `<option value="${m.id}" data-price="${m.average_price}" data-stock="${m.stock}" data-unit="${m.unit}">${m.material_name} - stok ${productionFormatQty(m.stock)} ${m.unit}</option>`;
    });
    o += '</select></div>';
    o += '<div class="col-lg-3"><input name="material_qty[]" class="form-control qty-input js-calc material-qty" placeholder="Qty"></div>';
    o += '<div class="col-lg-3"><input name="material_price[]" class="form-control price money-input js-calc material-price" placeholder="Harga"></div>';
    o += '<div class="col-lg-1 d-grid"><button type="button" class="btn btn-outline-danger" onclick="this.closest(\'.material-row\').remove(); calculateProduction();">x</button></div>';
    o += '</div><div class="small text-muted mt-1 material-hint"></div></div>';

    document.getElementById('materialsBox').insertAdjacentHTML('beforeend', o);
    let r = document.querySelector('#materialsBox .material-row:last-child');
    let sel = r.querySelector('select');
    r.querySelector('.price').value = productionFormatId(sel.selectedOptions[0].dataset.price || 0);
    sel.addEventListener('change', function(){
        const row = this.closest('.material-row');
        row.querySelector('.price').value = productionFormatId(this.selectedOptions[0].dataset.price || 0);
        calculateProduction();
    });
    productionBindInputs(r);
    calculateProduction();
}
function calculateProduction(){
    let material = 0;
    document.querySelectorAll('.material-row').forEach(row => {
        const qty = productionParseNumber(row.querySelector('.material-qty')?.value || 0);
        const price = productionParseNumber(row.querySelector('.material-price')?.value || 0);
        const select = row.querySelector('.material-select');
        const stock = Number(select?.selectedOptions[0]?.dataset.stock || 0);
        const unit = select?.selectedOptions[0]?.dataset.unit || '';
        material += qty * price;
        const hint = row.querySelector('.material-hint');
        if (hint) {
            hint.textContent = qty > stock ? `Peringatan: qty melebihi stok tersedia ${productionFormatQty(stock)} ${unit}.` : `Stok tersedia ${productionFormatQty(stock)} ${unit}.`;
            hint.className = qty > stock ? 'small text-danger mt-1 material-hint' : 'small text-muted mt-1 material-hint';
        }
    });
    const labor = productionParseNumber(document.querySelector('[name="labor_cost"]')?.value || 0);
    const over = productionParseNumber(document.querySelector('[name="overhead_cost"]')?.value || 0);
    const output = productionParseNumber(document.querySelector('[name="output_qty"]')?.value || 0);
    const total = material + labor + over;
    const hpp = output > 0 ? total / output : 0;

    document.getElementById('sumMaterial').textContent = productionFormatMoney(material);
    document.getElementById('sumLabor').textContent = productionFormatMoney(labor);
    document.getElementById('sumOverhead').textContent = productionFormatMoney(over);
    document.getElementById('sumTotal').textContent = productionFormatMoney(total);
    document.getElementById('sumOutput').textContent = productionFormatQty(output);
    document.getElementById('sumHpp').textContent = productionFormatMoney(hpp);
}

addMaterial();
productionBindInputs(document);
calculateProduction();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
