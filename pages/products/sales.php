<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Penjualan Produk - Nukari';

function sale_num($value): float {
    return (float) product_parse_number($value ?? 0);
}

function sale_status_badge($profit): string {
    $profit = (float)$profit;
    if ($profit > 0) return '<span class="badge bg-success">Untung</span>';
    if ($profit < 0) return '<span class="badge bg-danger">Rugi</span>';
    return '<span class="badge bg-secondary">Impas</span>';
}

function sale_channel_label($channel): string {
    $labels = [
        'offline' => 'Offline',
        'tokopedia' => 'Tokopedia',
        'shopee' => 'Shopee',
        'bazar' => 'Bazar',
        'custom' => 'Custom',
        'other' => 'Lainnya'
    ];
    return $labels[$channel] ?? ucfirst((string)$channel);
}

$flash = $_SESSION['flash_sales'] ?? null;
unset($_SESSION['flash_sales']);

$action = $_POST['action'] ?? '';
$userId = product_get_user_id();

/* =========================
   DELETE SALE
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $saleId = (int)($_POST['sale_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM product_sales WHERE id = ? FOR UPDATE");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception('Data penjualan tidak ditemukan.');
        }

        // Kembalikan stok produk.
        $pdo->prepare("UPDATE products SET stock = stock + ?, updated_at = NOW() WHERE id = ?")
            ->execute([(float)$sale['qty'], (int)$sale['product_id']]);

        // Hapus transaksi keuangan yang dibuat otomatis oleh penjualan ini.
        if (!empty($sale['transaction_id'])) {
            $pdo->prepare("DELETE FROM transactions WHERE id = ?")
                ->execute([(int)$sale['transaction_id']]);
        }

        $pdo->prepare("DELETE FROM product_sales WHERE id = ?")->execute([$saleId]);

        $pdo->commit();
        $_SESSION['flash_sales'] = ['type' => 'success', 'message' => 'Penjualan berhasil dihapus dan stok produk dikembalikan.'];
        header('Location: sales.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* =========================
   CREATE SALE
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = sale_num($_POST['qty'] ?? 0);
    $unit = sale_num($_POST['unit_price'] ?? 0);
    $fee = sale_num($_POST['marketplace_fee'] ?? 0);
    $ship = sale_num($_POST['shipping_cost'] ?? 0);
    $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
    $channel = $_POST['channel'] ?? 'offline';
    $payment = $_POST['payment_method'] ?? 'cash';

    try {
        if ($pid <= 0) throw new Exception('Produk wajib dipilih.');
        if ($qty <= 0) throw new Exception('Qty penjualan harus lebih dari 0.');
        if ($unit < 0) throw new Exception('Harga/unit tidak valid.');
        if ($fee < 0 || $ship < 0) throw new Exception('Fee marketplace dan ongkir tidak boleh minus.');

        $pdo->beginTransaction();

        $p = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
        $p->execute([$pid]);
        $prod = $p->fetch(PDO::FETCH_ASSOC);

        if (!$prod) throw new Exception('Produk tidak ditemukan.');
        if (($prod['status'] ?? 'active') !== 'active') throw new Exception('Produk tidak aktif.');

        $currentStock = (float)($prod['stock'] ?? 0);
        if ($qty > $currentStock) {
            throw new Exception('Stok tidak cukup. Stok tersedia: ' . product_num($currentStock) . ' ' . ($prod['unit'] ?? ''));
        }

        $gross = $qty * $unit;
        $net = $gross - $fee - $ship;
        $hppUnit = (float)($prod['hpp_price'] ?? 0);
        if ($hppUnit <= 0) {
            $hppUnit = (float)($prod['production_cost'] ?? 0);
        }
        $hpp = $qty * $hppUnit;
        $profit = $net - $hpp;

        // Kurangi stok.
        $pdo->prepare("UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ?")
            ->execute([$qty, $pid]);

        // Payment method di transactions belum memiliki enum "marketplace".
        $trxPayment = in_array($payment, ['cash', 'bank_transfer', 'qris', 'other'], true) ? $payment : 'other';

        $description = 'Penjualan produk: ' . $prod['product_name'] . ' (' . sale_channel_label($channel) . ')';
        $stmt = $pdo->prepare("
            INSERT INTO transactions
                (transaction_code, transaction_date, type, amount, description, payment_method, created_by, created_at)
            VALUES
                (?, ?, 'income', ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            product_code('TRX'),
            $saleDate,
            $net,
            $description,
            $trxPayment,
            $userId
        ]);
        $trxId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO product_sales
                (sale_code, product_id, sale_date, channel, customer_name, qty, unit_price,
                 gross_amount, marketplace_fee, shipping_cost, net_amount, hpp_total,
                 profit_amount, payment_method, transaction_id, notes, created_by, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            product_code('SALE'),
            $pid,
            $saleDate,
            $channel,
            trim($_POST['customer_name'] ?? ''),
            $qty,
            $unit,
            $gross,
            $fee,
            $ship,
            $net,
            $hpp,
            $profit,
            $payment,
            $trxId,
            trim($_POST['notes'] ?? ''),
            $userId
        ]);

        $pdo->commit();
        $_SESSION['flash_sales'] = ['type' => 'success', 'message' => 'Penjualan berhasil disimpan. Stok dan transaksi keuangan sudah diperbarui.'];
        header('Location: sales.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* =========================
   EXPORT CSV
   ========================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where = [];
    $params = [];

    if (!empty($_GET['q'])) {
        $where[] = "(p.product_name LIKE ? OR s.sale_code LIKE ? OR s.customer_name LIKE ?)";
        $q = '%' . $_GET['q'] . '%';
        array_push($params, $q, $q, $q);
    }
    if (!empty($_GET['channel'])) {
        $where[] = "s.channel = ?";
        $params[] = $_GET['channel'];
    }
    if (!empty($_GET['date_from'])) {
        $where[] = "s.sale_date >= ?";
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where[] = "s.sale_date <= ?";
        $params[] = $_GET['date_to'];
    }

    $sql = "SELECT s.*, p.product_name FROM product_sales s JOIN products p ON p.id = s.product_id";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY s.sale_date DESC, s.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=penjualan-produk-' . date('Ymd-His') . '.csv');

    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Kode', 'Tanggal', 'Produk', 'Channel', 'Customer', 'Qty', 'Harga Unit', 'Gross', 'Fee', 'Ongkir', 'Net', 'HPP', 'Profit', 'Metode Bayar', 'Catatan']);
    foreach ($rows as $r) {
        fputcsv($fp, [
            $r['sale_code'],
            $r['sale_date'],
            $r['product_name'],
            sale_channel_label($r['channel']),
            $r['customer_name'],
            $r['qty'],
            $r['unit_price'],
            $r['gross_amount'],
            $r['marketplace_fee'],
            $r['shipping_cost'],
            $r['net_amount'],
            $r['hpp_total'],
            $r['profit_amount'],
            $r['payment_method'],
            $r['notes']
        ]);
    }
    fclose($fp);
    exit;
}

/* =========================
   DATA
   ========================= */
$products = $pdo->query("
    SELECT *
    FROM products
    WHERE status = 'active'
    ORDER BY product_name
")->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];

if (!empty($_GET['q'])) {
    $where[] = "(p.product_name LIKE ? OR s.sale_code LIKE ? OR s.customer_name LIKE ?)";
    $q = '%' . $_GET['q'] . '%';
    array_push($params, $q, $q, $q);
}
if (!empty($_GET['channel'])) {
    $where[] = "s.channel = ?";
    $params[] = $_GET['channel'];
}
if (!empty($_GET['date_from'])) {
    $where[] = "s.sale_date >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = "s.sale_date <= ?";
    $params[] = $_GET['date_to'];
}

$sql = "
    SELECT s.*, p.product_name, p.unit
    FROM product_sales s
    JOIN products p ON p.id = s.product_id
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY s.sale_date DESC, s.id DESC LIMIT 150";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'count' => count($sales),
    'net' => 0,
    'hpp' => 0,
    'profit' => 0,
];
foreach ($sales as $s) {
    $summary['net'] += (float)$s['net_amount'];
    $summary['hpp'] += (float)$s['hpp_total'];
    $summary['profit'] += (float)$s['profit_amount'];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>
<style>
.sales-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.sales-mini-card{border:1px solid #e7edf5;background:#f8fafc;border-radius:14px;padding:12px}
.sales-mini-card .label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:800}
.sales-mini-card .value{font-size:18px;font-weight:900;color:#0f172a;margin-top:3px}
.sales-result-box{border:1px solid #dbe7f3;background:#f8fbff;border-radius:16px;padding:12px;margin-top:8px}
.sales-result-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.sales-result-item{border:1px solid #e5edf6;border-radius:12px;padding:10px;background:#fff}
.sales-result-item strong{display:block;font-size:16px;color:#0f172a}
.sales-result-item span{font-size:11px;font-weight:800;text-transform:uppercase;color:#64748b}
.sales-profit-good{background:#ecfdf5;border-color:#bbf7d0}
.sales-profit-bad{background:#fef2f2;border-color:#fecaca}
.product-help-card{border:1px solid #e3ebf5;background:white;border-radius:18px;padding:14px;box-shadow:0 14px 40px rgba(15,23,42,.04)}
.product-help-card ol{padding-left:18px;margin-bottom:0;color:#475569;font-size:13px}
.product-help-card li{margin-bottom:6px}
.table-sales th,.table-sales td{vertical-align:middle;white-space:nowrap}
.table-sales .product-col{white-space:normal;min-width:180px}
.filter-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto auto;gap:8px}
@media(max-width:1200px){.sales-summary-grid{grid-template-columns:repeat(2,1fr)}.filter-row{grid-template-columns:1fr 1fr}}
@media(max-width:768px){.sales-summary-grid,.sales-result-grid,.filter-row{grid-template-columns:1fr}}
</style>

<main class="main-content">
<div class="container-fluid product-page py-3">
    <?php require_once __DIR__ . '/_nav.php'; ?>

    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= product_safe($flash['type']); ?>"><?= product_safe($flash['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= product_safe($error); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-4 col-lg-5">
            <div class="product-card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <strong>Input Penjualan</strong>
                    <span class="badge bg-success">Sales</span>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-2" id="salesForm">
                        <input type="hidden" name="action" value="create">

                        <div class="col-12">
                            <label class="form-label">Produk</label>
                            <select name="product_id" id="product_id" class="form-select" required>
                                <?php foreach ($products as $p): 
                                    $hppUnit = (float)($p['hpp_price'] ?? 0);
                                    if ($hppUnit <= 0) $hppUnit = (float)($p['production_cost'] ?? 0);
                                ?>
                                    <option
                                        value="<?= (int)$p['id']; ?>"
                                        data-stock="<?= (float)$p['stock']; ?>"
                                        data-unit="<?= product_safe($p['unit'] ?? 'pcs'); ?>"
                                        data-price="<?= (float)($p['selling_price'] ?? 0); ?>"
                                        data-hpp="<?= $hppUnit; ?>"
                                        data-name="<?= product_safe($p['product_name']); ?>"
                                    >
                                        <?= product_safe($p['product_name']); ?> - stok <?= product_num($p['stock']); ?> <?= product_safe($p['unit']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6">
                            <label class="form-label">Tanggal</label>
                            <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                        </div>

                        <div class="col-6">
                            <label class="form-label">Channel</label>
                            <select name="channel" id="channel" class="form-select">
                                <option value="offline">Offline</option>
                                <option value="tokopedia">Tokopedia</option>
                                <option value="shopee">Shopee</option>
                                <option value="bazar">Bazar</option>
                                <option value="custom">Custom</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Customer</label>
                            <input name="customer_name" class="form-control" placeholder="Contoh: Pembeli Workshop Nukari">
                        </div>

                        <div class="col-6">
                            <label class="form-label">Qty</label>
                            <input name="qty" id="qty" class="form-control qty-input" required placeholder="0">
                            <small class="text-muted" id="stockHint">Stok tersedia: -</small>
                        </div>

                        <div class="col-6">
                            <label class="form-label">Harga / Unit</label>
                            <input name="unit_price" id="unit_price" class="form-control money-input" required placeholder="0">
                            <small class="text-muted" id="hppHint">HPP/unit: -</small>
                        </div>

                        <div class="col-6">
                            <label class="form-label">Fee Marketplace</label>
                            <input name="marketplace_fee" id="marketplace_fee" class="form-control money-input" value="0">
                        </div>

                        <div class="col-6">
                            <label class="form-label">Ongkir</label>
                            <input name="shipping_cost" id="shipping_cost" class="form-control money-input" value="0">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Metode Bayar</label>
                            <select name="payment_method" id="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="qris">QRIS</option>
                                <option value="marketplace">Marketplace</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="sales-result-box">
                                <div class="sales-result-grid">
                                    <div class="sales-result-item">
                                        <span>Subtotal</span>
                                        <strong id="grossPreview">Rp 0</strong>
                                    </div>
                                    <div class="sales-result-item">
                                        <span>Net Sales</span>
                                        <strong id="netPreview">Rp 0</strong>
                                    </div>
                                    <div class="sales-result-item">
                                        <span>HPP</span>
                                        <strong id="hppPreview">Rp 0</strong>
                                    </div>
                                    <div class="sales-result-item" id="profitBox">
                                        <span>Profit</span>
                                        <strong id="profitPreview">Rp 0</strong>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2" id="warningText">Ringkasan akan dihitung otomatis saat qty dan harga diisi.</small>
                            </div>
                        </div>

                        <div class="col-12">
                            <textarea name="notes" class="form-control" placeholder="Catatan penjualan"></textarea>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-success btn-rounded" id="saveBtn">Simpan Penjualan</button>
                            <button type="reset" class="btn btn-outline-secondary btn-rounded">Reset</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="product-help-card mt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong>Panduan Penjualan</strong>
                    <span class="badge bg-light text-dark">UX</span>
                </div>
                <ol>
                    <li>Pilih produk, sistem akan membaca stok, harga jual, dan HPP/unit.</li>
                    <li>Isi qty dan harga jual. Ringkasan profit akan tampil otomatis.</li>
                    <li>Untuk Tokopedia/Shopee, isi fee marketplace agar profit lebih akurat.</li>
                    <li>Qty tidak boleh melebihi stok tersedia.</li>
                    <li>Saat disimpan, stok produk berkurang dan transaksi income masuk ke Keuangan.</li>
                </ol>
            </div>
        </div>

        <div class="col-xl-8 col-lg-7">
            <div class="sales-summary-grid">
                <div class="sales-mini-card">
                    <div class="label">Data Ditampilkan</div>
                    <div class="value"><?= number_format($summary['count'], 0, ',', '.'); ?></div>
                </div>
                <div class="sales-mini-card">
                    <div class="label">Net Sales</div>
                    <div class="value"><?= product_money($summary['net']); ?></div>
                </div>
                <div class="sales-mini-card">
                    <div class="label">Total HPP</div>
                    <div class="value"><?= product_money($summary['hpp']); ?></div>
                </div>
                <div class="sales-mini-card">
                    <div class="label">Profit</div>
                    <div class="value"><?= product_money($summary['profit']); ?></div>
                </div>
            </div>

            <div class="product-card">
                <div class="card-header">
                    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                        <strong>Riwayat Penjualan</strong>
                        <a class="btn btn-sm btn-outline-success btn-rounded" href="sales.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>">Export CSV</a>
                    </div>
                    <form method="get" class="filter-row mt-3">
                        <input type="text" name="q" class="form-control" value="<?= product_safe($_GET['q'] ?? ''); ?>" placeholder="Cari produk, kode, customer...">
                        <select name="channel" class="form-select">
                            <option value="">Semua Channel</option>
                            <?php foreach (['offline'=>'Offline','tokopedia'=>'Tokopedia','shopee'=>'Shopee','bazar'=>'Bazar','custom'=>'Custom','other'=>'Lainnya'] as $k=>$v): ?>
                                <option value="<?= $k; ?>" <?= (($_GET['channel'] ?? '') === $k) ? 'selected' : ''; ?>><?= $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="date_from" class="form-control" value="<?= product_safe($_GET['date_from'] ?? ''); ?>">
                        <input type="date" name="date_to" class="form-control" value="<?= product_safe($_GET['date_to'] ?? ''); ?>">
                        <button class="btn btn-success btn-rounded">Filter</button>
                        <a href="sales.php" class="btn btn-outline-secondary btn-rounded">Reset</a>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-sales">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tanggal</th>
                                <th class="product-col">Produk</th>
                                <th>Channel</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end">Fee/Ongkir</th>
                                <th class="text-end">Net</th>
                                <th class="text-end">HPP</th>
                                <th class="text-end">Profit</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$sales): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted py-4">Belum ada data penjualan.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($sales as $s): ?>
                                <tr>
                                    <td><?= product_safe($s['sale_code']); ?></td>
                                    <td><?= product_safe($s['sale_date']); ?></td>
                                    <td class="product-col">
                                        <strong><?= product_safe($s['product_name']); ?></strong>
                                        <?php if (!empty($s['customer_name'])): ?>
                                            <div class="small text-muted"><?= product_safe($s['customer_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= product_badge(sale_channel_label($s['channel'])); ?></td>
                                    <td class="text-end"><?= product_num($s['qty']); ?> <?= product_safe($s['unit'] ?? ''); ?></td>
                                    <td class="text-end"><?= product_money($s['gross_amount']); ?></td>
                                    <td class="text-end">
                                        <?= product_money((float)$s['marketplace_fee'] + (float)$s['shipping_cost']); ?>
                                        <div class="small text-muted">Fee <?= product_money($s['marketplace_fee']); ?></div>
                                    </td>
                                    <td class="text-end"><?= product_money($s['net_amount']); ?></td>
                                    <td class="text-end"><?= product_money($s['hpp_total']); ?></td>
                                    <td class="text-end money"><?= product_money($s['profit_amount']); ?></td>
                                    <td><?= sale_status_badge($s['profit_amount']); ?></td>
                                    <td class="text-end">
                                        <button
                                            class="btn btn-sm btn-outline-primary"
                                            type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#detailSale<?= (int)$s['id']; ?>"
                                        >Detail</button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus penjualan ini? Stok produk akan dikembalikan dan transaksi income akan dihapus.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="sale_id" value="<?= (int)$s['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>

                                <div class="modal fade" id="detailSale<?= (int)$s['id']; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Detail Penjualan - <?= product_safe($s['sale_code']); ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-3">
                                                    <div class="col-md-6"><strong>Produk</strong><br><?= product_safe($s['product_name']); ?></div>
                                                    <div class="col-md-6"><strong>Customer</strong><br><?= product_safe($s['customer_name'] ?: '-'); ?></div>
                                                    <div class="col-md-3"><strong>Qty</strong><br><?= product_num($s['qty']); ?> <?= product_safe($s['unit'] ?? ''); ?></div>
                                                    <div class="col-md-3"><strong>Harga/Unit</strong><br><?= product_money($s['unit_price']); ?></div>
                                                    <div class="col-md-3"><strong>Gross</strong><br><?= product_money($s['gross_amount']); ?></div>
                                                    <div class="col-md-3"><strong>Net</strong><br><?= product_money($s['net_amount']); ?></div>
                                                    <div class="col-md-3"><strong>Fee Marketplace</strong><br><?= product_money($s['marketplace_fee']); ?></div>
                                                    <div class="col-md-3"><strong>Ongkir</strong><br><?= product_money($s['shipping_cost']); ?></div>
                                                    <div class="col-md-3"><strong>HPP</strong><br><?= product_money($s['hpp_total']); ?></div>
                                                    <div class="col-md-3"><strong>Profit</strong><br><?= product_money($s['profit_amount']); ?></div>
                                                    <div class="col-12"><strong>Catatan</strong><br><?= nl2br(product_safe($s['notes'] ?: '-')); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 small text-muted border-top">
                    Catatan: export CSV mengikuti filter yang sedang aktif. Hapus penjualan akan mengembalikan stok produk dan menghapus transaksi income terkait.
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<script>
(function(){
    const productSelect = document.getElementById('product_id');
    const qtyInput = document.getElementById('qty');
    const unitInput = document.getElementById('unit_price');
    const feeInput = document.getElementById('marketplace_fee');
    const shipInput = document.getElementById('shipping_cost');
    const channelSelect = document.getElementById('channel');
    const paymentSelect = document.getElementById('payment_method');
    const saveBtn = document.getElementById('saveBtn');

    const grossPreview = document.getElementById('grossPreview');
    const netPreview = document.getElementById('netPreview');
    const hppPreview = document.getElementById('hppPreview');
    const profitPreview = document.getElementById('profitPreview');
    const profitBox = document.getElementById('profitBox');
    const warningText = document.getElementById('warningText');
    const stockHint = document.getElementById('stockHint');
    const hppHint = document.getElementById('hppHint');

    function parseNumber(value){
        value = String(value || '0').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
        const n = parseFloat(value);
        return isNaN(n) ? 0 : n;
    }

    function formatNumber(value){
        value = String(value || '').replace(/[^\d]/g, '');
        if (!value) return '0';
        return value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function formatCurrency(value){
        value = Math.round(value || 0);
        return 'Rp ' + String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function formatQty(value){
        value = Number(value || 0);
        if (Number.isInteger(value)) return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return value.toLocaleString('id-ID', { maximumFractionDigits: 3 });
    }

    function selectedProduct(){
        const opt = productSelect.options[productSelect.selectedIndex];
        return {
            stock: parseFloat(opt.dataset.stock || 0),
            unit: opt.dataset.unit || '',
            price: parseFloat(opt.dataset.price || 0),
            hpp: parseFloat(opt.dataset.hpp || 0)
        };
    }

    function recalc(){
        const p = selectedProduct();
        const qty = parseNumber(qtyInput.value);
        const unitPrice = parseNumber(unitInput.value);
        const fee = parseNumber(feeInput.value);
        const ship = parseNumber(shipInput.value);

        const gross = qty * unitPrice;
        const net = gross - fee - ship;
        const hpp = qty * p.hpp;
        const profit = net - hpp;

        stockHint.textContent = 'Stok tersedia: ' + formatQty(p.stock) + ' ' + p.unit;
        hppHint.textContent = 'HPP/unit: ' + formatCurrency(p.hpp);

        grossPreview.textContent = formatCurrency(gross);
        netPreview.textContent = formatCurrency(net);
        hppPreview.textContent = formatCurrency(hpp);
        profitPreview.textContent = formatCurrency(profit);

        profitBox.classList.remove('sales-profit-good', 'sales-profit-bad');
        if (profit > 0) profitBox.classList.add('sales-profit-good');
        if (profit < 0) profitBox.classList.add('sales-profit-bad');

        if (qty > p.stock) {
            warningText.textContent = 'Qty melebihi stok tersedia. Penjualan tidak bisa disimpan.';
            warningText.className = 'text-danger d-block mt-2';
            saveBtn.disabled = true;
        } else {
            warningText.textContent = 'Ringkasan dihitung otomatis. Pastikan fee marketplace diisi untuk Tokopedia/Shopee.';
            warningText.className = 'text-muted d-block mt-2';
            saveBtn.disabled = false;
        }
    }

    function autoSetPrice(){
        const p = selectedProduct();
        if (p.price > 0) {
            unitInput.value = formatNumber(Math.round(p.price));
        }
        recalc();
    }

    function formatMoneyInput(e){
        const el = e.target;
        const raw = String(el.value || '').replace(/[^\d]/g, '');
        el.value = raw ? raw.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
        recalc();
    }

    document.querySelectorAll('.money-input').forEach(input => {
        input.addEventListener('input', formatMoneyInput);
        input.addEventListener('blur', formatMoneyInput);
    });

    [qtyInput, feeInput, shipInput, unitInput].forEach(el => {
        el.addEventListener('input', recalc);
        el.addEventListener('change', recalc);
    });

    productSelect.addEventListener('change', autoSetPrice);

    channelSelect.addEventListener('change', function(){
        if (['tokopedia','shopee'].includes(channelSelect.value)) {
            paymentSelect.value = 'marketplace';
        }
        recalc();
    });

    autoSetPrice();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
