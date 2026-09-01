<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Laporan Produk - Nukari';

function product_report_date($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt ? $dt->format('Y-m-d') : '';
}
function product_report_period_sql($column, $from, $to, &$params) {
    $where = [];
    if ($from !== '') {
        $where[] = "$column >= ?";
        $params[] = $from;
    }
    if ($to !== '') {
        $where[] = "$column <= ?";
        $params[] = $to;
    }
    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}
function product_report_fetch($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
function product_report_fetch_all($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function product_report_empty_row($cols, $text = 'Belum ada data.') {
    return '<tr><td colspan="'.$cols.'" class="text-center text-muted py-3">'.$text.'</td></tr>';
}

$dateFrom = product_report_date($_GET['date_from'] ?? '');
$dateTo   = product_report_date($_GET['date_to'] ?? '');

$periodLabel = ($dateFrom || $dateTo)
    ? (($dateFrom ?: 'Awal') . ' s/d ' . ($dateTo ?: 'Sekarang'))
    : 'Semua periode';

$productSummary = product_report_fetch($pdo, "
    SELECT 
        COUNT(*) total_products,
        COALESCE(SUM(stock),0) total_stock,
        COALESCE(SUM(stock * hpp_price),0) hpp_value,
        COALESCE(SUM(stock * selling_price),0) selling_value,
        SUM(CASE WHEN stock <= minimum_stock THEN 1 ELSE 0 END) low_stock
    FROM products
");

$materialSummary = product_report_fetch($pdo, "
    SELECT 
        COUNT(*) total_materials,
        COALESCE(SUM(stock * average_price),0) material_value,
        SUM(CASE WHEN stock <= minimum_stock THEN 1 ELSE 0 END) low_materials
    FROM raw_materials
");

$salesParams = [];
$salesWhere = product_report_period_sql('s.sale_date', $dateFrom, $dateTo, $salesParams);
$salesSummary = product_report_fetch($pdo, "
    SELECT 
        COUNT(*) total_sales,
        COALESCE(SUM(s.qty),0) qty_sold,
        COALESCE(SUM(s.gross_amount),0) gross,
        COALESCE(SUM(s.net_amount),0) net,
        COALESCE(SUM(s.hpp_total),0) hpp,
        COALESCE(SUM(s.profit_amount),0) profit
    FROM product_sales s
    $salesWhere
", $salesParams);

$prodParams = [];
$prodWhere = product_report_period_sql('pp.production_date', $dateFrom, $dateTo, $prodParams);
$productionSummary = product_report_fetch($pdo, "
    SELECT
        COUNT(*) total_productions,
        COALESCE(SUM(pp.output_qty),0) output_qty,
        COALESCE(SUM(pp.material_cost),0) material_cost,
        COALESCE(SUM(pp.labor_cost),0) labor_cost,
        COALESCE(SUM(pp.overhead_cost),0) overhead_cost,
        COALESCE(SUM(pp.total_cost),0) total_cost
    FROM product_productions pp
    $prodWhere
", $prodParams);

$customParams = [];
$customWhere = product_report_period_sql('co.order_date', $dateFrom, $dateTo, $customParams);
$customSummary = product_report_fetch($pdo, "
    SELECT
        COUNT(*) total_orders,
        SUM(CASE WHEN co.status IN ('inquiry','approved','production') THEN 1 ELSE 0 END) active_orders,
        COALESCE(SUM(co.offered_price),0) offered,
        COALESCE(SUM(co.estimated_cost),0) cost,
        COALESCE(SUM(co.profit_estimate),0) profit,
        COALESCE(SUM(co.down_payment + co.final_payment),0) paid
    FROM product_custom_orders co
    $customWhere
", $customParams);

$salesMargin = ($salesSummary['net'] ?? 0) > 0 ? (($salesSummary['profit'] ?? 0) / $salesSummary['net'] * 100) : 0;
$potentialProfit = ($productSummary['selling_value'] ?? 0) - ($productSummary['hpp_value'] ?? 0);

$topProducts = product_report_fetch_all($pdo, "
    SELECT product_code, product_name, product_type, stock, unit, hpp_price, selling_price,
           (stock*hpp_price) stock_value,
           (stock*selling_price) selling_value,
           ((selling_price-hpp_price)*stock) potential_profit
    FROM products
    ORDER BY stock_value DESC
    LIMIT 10
");

$lowMaterials = product_report_fetch_all($pdo, "
    SELECT material_code, material_name, category, stock, unit, minimum_stock, average_price, location
    FROM raw_materials
    WHERE stock <= minimum_stock
    ORDER BY stock ASC, material_name ASC
    LIMIT 20
");

$profitByProduct = product_report_fetch_all($pdo, "
    SELECT p.product_name, p.product_code, 
           COALESCE(SUM(s.qty),0) qty,
           COALESCE(SUM(s.net_amount),0) net,
           COALESCE(SUM(s.hpp_total),0) hpp,
           COALESCE(SUM(s.profit_amount),0) profit
    FROM product_sales s
    JOIN products p ON p.id = s.product_id
    $salesWhere
    GROUP BY p.id, p.product_name, p.product_code
    ORDER BY profit DESC
    LIMIT 10
", $salesParams);

$salesByChannel = product_report_fetch_all($pdo, "
    SELECT s.channel,
           COUNT(*) trx,
           COALESCE(SUM(s.qty),0) qty,
           COALESCE(SUM(s.net_amount),0) net,
           COALESCE(SUM(s.profit_amount),0) profit
    FROM product_sales s
    $salesWhere
    GROUP BY s.channel
    ORDER BY net DESC
", $salesParams);

$recentSales = product_report_fetch_all($pdo, "
    SELECT s.*, p.product_name
    FROM product_sales s
    JOIN products p ON p.id = s.product_id
    $salesWhere
    ORDER BY s.id DESC
    LIMIT 12
", $salesParams);

$recentProductions = product_report_fetch_all($pdo, "
    SELECT pp.*, p.product_name
    FROM product_productions pp
    JOIN products p ON p.id = pp.product_id
    $prodWhere
    ORDER BY pp.id DESC
    LIMIT 12
", $prodParams);

$movementParams = [];
$movementWhere = product_report_period_sql('m.movement_date', $dateFrom, $dateTo, $movementParams);
$recentMovements = product_report_fetch_all($pdo, "
    SELECT m.*, rm.material_name, rm.unit
    FROM raw_material_movements m
    JOIN raw_materials rm ON rm.id = m.material_id
    $movementWhere
    ORDER BY m.id DESC
    LIMIT 12
", $movementParams);

$activeCustomOrders = product_report_fetch_all($pdo, "
    SELECT order_code, customer_name, product_name, due_date, qty, unit, offered_price, profit_estimate, status
    FROM product_custom_orders
    WHERE status IN ('inquiry','approved','production')
    ORDER BY 
        CASE status WHEN 'production' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END,
        due_date ASC
    LIMIT 12
");

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>
<main class="main-content">
<div class="container-fluid product-page py-3">
    <?php require_once __DIR__ . '/_nav.php'; ?>

    <div class="product-hero d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <div class="small text-uppercase opacity-75">Laporan Produk</div>
            <h2 class="fw-bold mb-1">Produk, Produksi, Gudang & Profit</h2>
            <p class="mb-0">Ringkasan operasional Nukari untuk evaluasi, print, dan export laporan.</p>
        </div>
        <div class="page-actions d-flex flex-wrap gap-2">
            <button onclick="window.print()" class="btn btn-light btn-rounded"><i class="bi bi-printer"></i> Print</button>
            <a href="export_excel.php?date_from=<?= urlencode($dateFrom); ?>&date_to=<?= urlencode($dateTo); ?>" class="btn btn-warning btn-rounded"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        </div>
    </div>

    <div class="product-card mb-3 no-print">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Dari Tanggal</label>
                <input type="date" name="date_from" value="<?= product_safe($dateFrom); ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" name="date_to" value="<?= product_safe($dateTo); ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Periode Aktif</label>
                <div class="form-control bg-light"><?= product_safe($periodLabel); ?></div>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-success flex-fill"><i class="bi bi-funnel"></i> Terapkan</button>
                <a href="report.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Nilai Stok Produk HPP</div><h4 class="money"><?= product_money($productSummary['hpp_value'] ?? 0); ?></h4><small><?= (int)($productSummary['total_products'] ?? 0); ?> produk, <?= product_num($productSummary['total_stock'] ?? 0); ?> stok</small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Potensi Nilai Jual</div><h4 class="money"><?= product_money($productSummary['selling_value'] ?? 0); ?></h4><small>Potensi profit <?= product_money($potentialProfit); ?></small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Nilai Bahan Baku</div><h4 class="money"><?= product_money($materialSummary['material_value'] ?? 0); ?></h4><small><?= (int)($materialSummary['low_materials'] ?? 0); ?> bahan perlu restock</small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Profit Penjualan</div><h4 class="money"><?= product_money($salesSummary['profit'] ?? 0); ?></h4><small>Net <?= product_money($salesSummary['net'] ?? 0); ?>, margin <?= product_num($salesMargin); ?>%</small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Produksi Periode</div><h4 class="money"><?= product_money($productionSummary['total_cost'] ?? 0); ?></h4><small><?= (int)($productionSummary['total_productions'] ?? 0); ?> batch produksi</small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Material Produksi</div><h4 class="money"><?= product_money($productionSummary['material_cost'] ?? 0); ?></h4><small>TK <?= product_money($productionSummary['labor_cost'] ?? 0); ?>, OH <?= product_money($productionSummary['overhead_cost'] ?? 0); ?></small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Custom Order Aktif</div><h4><?= (int)($customSummary['active_orders'] ?? 0); ?></h4><small>Penawaran <?= product_money($customSummary['offered'] ?? 0); ?></small></div></div>
        <div class="col-md-3"><div class="kpi-card"><div class="small-muted">Profit Custom Estimasi</div><h4 class="money"><?= product_money($customSummary['profit'] ?? 0); ?></h4><small>Terbayar <?= product_money($customSummary['paid'] ?? 0); ?></small></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header d-flex justify-content-between"><strong>Profitabilitas Produk</strong><span class="small-muted">Top 10</span></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Produk</th><th class="text-end">Qty</th><th class="text-end">Net</th><th class="text-end">HPP</th><th class="text-end">Profit</th></tr></thead>
                    <tbody>
                    <?php if (!$profitByProduct) echo product_report_empty_row(5); ?>
                    <?php foreach($profitByProduct as $r): ?>
                    <tr>
                        <td><?= product_safe($r['product_name']); ?><div class="small-muted"><?= product_safe($r['product_code']); ?></div></td>
                        <td class="text-end"><?= product_num($r['qty']); ?></td>
                        <td class="text-end"><?= product_money($r['net']); ?></td>
                        <td class="text-end"><?= product_money($r['hpp']); ?></td>
                        <td class="text-end money"><?= product_money($r['profit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header d-flex justify-content-between"><strong>Penjualan per Channel</strong><span class="small-muted">Offline/Marketplace</span></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Channel</th><th class="text-end">Transaksi</th><th class="text-end">Qty</th><th class="text-end">Net</th><th class="text-end">Profit</th></tr></thead>
                    <tbody>
                    <?php if (!$salesByChannel) echo product_report_empty_row(5); ?>
                    <?php foreach($salesByChannel as $r): ?>
                    <tr>
                        <td><?= product_badge($r['channel']); ?></td>
                        <td class="text-end"><?= (int)$r['trx']; ?></td>
                        <td class="text-end"><?= product_num($r['qty']); ?></td>
                        <td class="text-end"><?= product_money($r['net']); ?></td>
                        <td class="text-end money"><?= product_money($r['profit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header"><strong>Top Nilai Stok Produk</strong></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Produk</th><th class="text-end">Stok</th><th class="text-end">HPP</th><th class="text-end">Jual</th><th class="text-end">Nilai HPP</th></tr></thead>
                    <tbody>
                    <?php if (!$topProducts) echo product_report_empty_row(5); ?>
                    <?php foreach($topProducts as $r): ?>
                    <tr>
                        <td><?= product_safe($r['product_name']); ?><div class="small-muted"><?= product_safe($r['product_code']); ?></div></td>
                        <td class="text-end"><?= product_num($r['stock']).' '.product_safe($r['unit']); ?></td>
                        <td class="text-end"><?= product_money($r['hpp_price']); ?></td>
                        <td class="text-end"><?= product_money($r['selling_price']); ?></td>
                        <td class="text-end money"><?= product_money($r['stock_value']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header"><strong>Bahan Baku Minimum / Restock</strong></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Bahan</th><th class="text-end">Stok</th><th class="text-end">Minimum</th><th>Lokasi</th></tr></thead>
                    <tbody>
                    <?php if (!$lowMaterials) echo product_report_empty_row(4, 'Tidak ada bahan yang berada di bawah minimum.'); ?>
                    <?php foreach($lowMaterials as $r): ?>
                    <tr>
                        <td><?= product_safe($r['material_name']); ?><div class="small-muted"><?= product_safe($r['material_code']); ?></div></td>
                        <td class="text-end"><?= product_num($r['stock']).' '.product_safe($r['unit']); ?></td>
                        <td class="text-end"><?= product_num($r['minimum_stock']); ?></td>
                        <td><?= product_safe($r['location']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header"><strong>Produksi Terbaru</strong></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Kode</th><th>Produk</th><th>Tanggal</th><th class="text-end">Output</th><th class="text-end">Total</th><th class="text-end">HPP</th></tr></thead>
                    <tbody>
                    <?php if (!$recentProductions) echo product_report_empty_row(6); ?>
                    <?php foreach($recentProductions as $r): ?>
                    <tr>
                        <td><?= product_safe($r['production_code']); ?></td>
                        <td><?= product_safe($r['product_name']); ?></td>
                        <td><?= product_safe($r['production_date']); ?></td>
                        <td class="text-end"><?= product_num($r['output_qty']).' '.product_safe($r['output_unit']); ?></td>
                        <td class="text-end"><?= product_money($r['total_cost']); ?></td>
                        <td class="text-end"><?= product_money($r['hpp_per_unit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header"><strong>Penjualan Terbaru</strong></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Tanggal</th><th>Produk</th><th>Channel</th><th class="text-end">Qty</th><th class="text-end">Net</th><th class="text-end">Profit</th></tr></thead>
                    <tbody>
                    <?php if (!$recentSales) echo product_report_empty_row(6); ?>
                    <?php foreach($recentSales as $r): ?>
                    <tr>
                        <td><?= product_safe($r['sale_date']); ?></td>
                        <td><?= product_safe($r['product_name']); ?></td>
                        <td><?= product_badge($r['channel']); ?></td>
                        <td class="text-end"><?= product_num($r['qty']); ?></td>
                        <td class="text-end"><?= product_money($r['net_amount']); ?></td>
                        <td class="text-end money"><?= product_money($r['profit_amount']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header"><strong>Mutasi Bahan Terbaru</strong></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Tanggal</th><th>Bahan</th><th>Jenis</th><th class="text-end">Qty</th><th class="text-end">Nilai</th></tr></thead>
                    <tbody>
                    <?php if (!$recentMovements) echo product_report_empty_row(5); ?>
                    <?php foreach($recentMovements as $r): ?>
                    <tr>
                        <td><?= product_safe($r['movement_date']); ?></td>
                        <td><?= product_safe($r['material_name']); ?></td>
                        <td><?= product_badge($r['movement_type']); ?></td>
                        <td class="text-end"><?= product_num($r['quantity']).' '.product_safe($r['unit']); ?></td>
                        <td class="text-end"><?= product_money($r['total_amount']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="product-card">
                <div class="card-header"><strong>Custom Order Aktif</strong></div>
                <div class="product-table-wrap table-responsive"><table class="table product-report-table table-sm mb-0 align-middle">
                    <thead><tr><th>Customer</th><th>Produk</th><th>Deadline</th><th class="text-end">Penawaran</th><th class="text-end">Profit</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$activeCustomOrders) echo product_report_empty_row(6); ?>
                    <?php foreach($activeCustomOrders as $r): ?>
                    <tr>
                        <td><?= product_safe($r['customer_name']); ?><div class="small-muted"><?= product_safe($r['order_code']); ?></div></td>
                        <td><?= product_safe($r['product_name']); ?><div class="small-muted"><?= product_num($r['qty']).' '.product_safe($r['unit']); ?></div></td>
                        <td><?= product_safe($r['due_date']); ?></td>
                        <td class="text-end"><?= product_money($r['offered_price']); ?></td>
                        <td class="text-end money"><?= product_money($r['profit_estimate']); ?></td>
                        <td><?= product_badge($r['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>

    <div class="product-card mt-3">
        <div class="card-header"><strong>Panduan Membaca Laporan</strong></div>
        <div class="p-3 small-muted">
            <ol class="mb-0">
                <li><strong>Nilai Stok Produk HPP</strong> menunjukkan nilai modal produk jadi yang masih tersedia.</li>
                <li><strong>Potensi Nilai Jual</strong> menunjukkan estimasi omzet jika seluruh stok produk terjual sesuai harga jual saat ini.</li>
                <li><strong>Profit Penjualan</strong> dihitung dari net sales dikurangi HPP produk terjual.</li>
                <li><strong>Bahan Baku Minimum</strong> membantu menentukan material yang perlu dibeli, dikumpulkan, atau diminta dari mitra.</li>
                <li>Gunakan filter periode untuk evaluasi per minggu, bulan, atau tahun.</li>
            </ol>
        </div>
    </div>
</div>
</main>


<style>
/* Report UI/UX spacing fix */
.product-page .product-card {
    overflow: hidden;
}
.product-page .product-card .card-header {
    padding: 14px 18px;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    background: #fff;
    margin: 0;
}
.product-page .product-table-wrap {
    padding: 0 16px 16px 16px;
    overflow-x: auto;
}
.product-page .product-card .card-header + .product-table-wrap {
    padding-top: 12px;
}
.product-page .product-report-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 12px;
    background: #fff;
}
.product-page .product-report-table thead th {
    background: #f8fafc;
    color: #64748b;
    font-size: 11px;
    letter-spacing: .04em;
    text-transform: uppercase;
    font-weight: 800;
    padding: 11px 12px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.25);
    white-space: nowrap;
}
.product-page .product-report-table tbody td {
    padding: 11px 12px;
    border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    vertical-align: middle;
}
.product-page .product-report-table tbody tr:last-child td {
    border-bottom: 0;
}
.product-page .product-report-table tbody tr:hover {
    background: #f8fafc;
}
.product-page .product-card .p-3 {
    padding: 18px !important;
}
.product-page .kpi-card {
    padding: 18px 20px;
}
@media print {
    .product-page .product-table-wrap { padding: 8px 10px 10px 10px !important; overflow: visible !important; }
    .product-page .product-card .card-header { padding: 9px 10px !important; }
    .product-page .product-report-table thead th,
    .product-page .product-report-table tbody td { padding: 6px 7px !important; font-size: 10px !important; }
    .product-page .product-report-table { border-radius: 6px !important; }
}
</style>

<style>
@media print {
    .sidebar, .topbar, .no-print, .page-actions, .btn, .floating-actions, nav, .product-nav { display:none !important; }
    .main-content { margin-left:0 !important; padding:0 !important; }
    body { background:#fff !important; font-size:11px; }
    .product-page { padding:0 !important; }
    .product-hero { color:#000 !important; background:#fff !important; border:1px solid #ddd; padding:14px !important; }
    .kpi-card, .product-card { box-shadow:none !important; border:1px solid #ddd !important; break-inside:avoid; }
    @page { size:A4 landscape; margin:10mm; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
