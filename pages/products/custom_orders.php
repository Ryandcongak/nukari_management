<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Custom Order Produk - Nukari';

function custom_order_status_label($status) {
    return product_badge($status ?: 'inquiry');
}

function custom_order_status_options($selected = 'inquiry') {
    $statuses = [
        'inquiry' => 'Inquiry',
        'approved' => 'Approved',
        'production' => 'Production',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
    $html = '';
    foreach ($statuses as $value => $label) {
        $sel = ((string)$selected === (string)$value) ? 'selected' : '';
        $html .= '<option value="' . product_safe($value) . '" ' . $sel . '>' . product_safe($label) . '</option>';
    }
    return $html;
}

function custom_order_payment_status($offered, $dp, $final) {
    $offered = (float)$offered;
    $paid = (float)$dp + (float)$final;
    if ($offered <= 0) return '<span class="badge bg-secondary">Belum Ada Nilai</span>';
    if ($paid <= 0) return '<span class="badge bg-warning text-dark">Belum Bayar</span>';
    if ($paid >= $offered) return '<span class="badge bg-success">Lunas</span>';
    return '<span class="badge bg-info">DP / Partial</span>';
}

function custom_order_due_label($dueDate, $status) {
    if (!$dueDate) return '<span class="small-muted">-</span>';
    $status = (string)$status;
    $due = strtotime($dueDate);
    $today = strtotime(date('Y-m-d'));
    $safeDate = product_safe($dueDate);
    if (!in_array($status, ['completed','cancelled'], true) && $due < $today) {
        return '<span class="text-danger fw-bold">' . $safeDate . '</span><div class="small text-danger">Overdue</div>';
    }
    return $safeDate;
}

function custom_order_redirect($params = []) {
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: custom_orders.php' . $query);
    exit;
}

// Export CSV sederhana agar bisa dibuka Excel tanpa library tambahan.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->query("SELECT * FROM product_custom_orders ORDER BY id DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=custom-orders-' . date('Ymd-His') . '.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kode','Customer','No HP','Email','Produk','Tanggal Order','Deadline','Qty','Satuan','Estimasi Cost','Penawaran','DP','Pelunasan','Sisa Tagihan','Profit Estimasi','Status','Deskripsi','Catatan'], ';');
    foreach ($rows as $r) {
        $balance = (float)$r['offered_price'] - ((float)$r['down_payment'] + (float)$r['final_payment']);
        fputcsv($out, [
            $r['order_code'],
            $r['customer_name'],
            $r['customer_phone'],
            $r['customer_email'] ?? '',
            $r['product_name'],
            $r['order_date'],
            $r['due_date'],
            $r['qty'],
            $r['unit'],
            $r['estimated_cost'],
            $r['offered_price'],
            $r['down_payment'],
            $r['final_payment'],
            $balance,
            $r['profit_estimate'],
            $r['status'],
            $r['description'],
            $r['notes'],
        ], ';');
    }
    fclose($out);
    exit;
}

$errors = [];
$success = isset($_GET['success']) ? trim((string)$_GET['success']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'quick_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'inquiry';
        if ($id > 0 && in_array($status, ['inquiry','approved','production','completed','cancelled'], true)) {
            $stmt = $pdo->prepare("UPDATE product_custom_orders SET status=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$status, $id]);
            custom_order_redirect(['success' => 'status']);
        }
        custom_order_redirect(['error' => 'status']);
    }

    if ($action === 'save' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $customerEmail = trim($_POST['customer_email'] ?? '');
        $clientId = (int)($_POST['client_id'] ?? 0);
        $productName = trim($_POST['product_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $dueDate = ($_POST['due_date'] ?? '') ?: null;
        $qty = product_parse_number($_POST['qty'] ?? 1);
        $unit = trim($_POST['unit'] ?? 'pcs');
        $estimatedCost = product_parse_number($_POST['estimated_cost'] ?? 0);
        $offeredPrice = product_parse_number($_POST['offered_price'] ?? 0);
        $downPayment = product_parse_number($_POST['down_payment'] ?? 0);
        $finalPayment = product_parse_number($_POST['final_payment'] ?? 0);
        $status = $_POST['status'] ?? 'inquiry';
        $notes = trim($_POST['notes'] ?? '');
        $profitEstimate = $offeredPrice - $estimatedCost;
        $paidAmount = $downPayment + $finalPayment;

        if ($customerName === '') $errors[] = 'Customer wajib diisi.';
        if ($customerPhone === '') $errors[] = 'Nomor WhatsApp customer wajib diisi.';
        if ($productName === '') $errors[] = 'Nama produk custom wajib diisi.';
        if ($qty <= 0) $errors[] = 'Qty harus lebih besar dari 0.';
        if ($estimatedCost < 0 || $offeredPrice < 0 || $downPayment < 0 || $finalPayment < 0) $errors[] = 'Nilai uang tidak boleh minus.';
        if ($offeredPrice > 0 && $paidAmount > $offeredPrice) $errors[] = 'Total DP + Pelunasan tidak boleh lebih besar dari harga penawaran.';
        if (!in_array($status, ['inquiry','approved','production','completed','cancelled'], true)) $status = 'inquiry';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                [$clientId, $customerName, $customerPhone, $customerEmail] = product_resolve_client($pdo, $clientId, $customerName, $customerPhone, $customerEmail, true);
                if ($action === 'update' && $id > 0) {
                    $stmt = $pdo->prepare("UPDATE product_custom_orders SET client_id=?, customer_name=?, customer_phone=?, customer_email=?, product_name=?, description=?, order_date=?, due_date=?, qty=?, unit=?, estimated_cost=?, offered_price=?, down_payment=?, final_payment=?, profit_estimate=?, status=?, notes=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$clientId, $customerName, $customerPhone, $customerEmail ?: null, $productName, $description, $orderDate, $dueDate, $qty, $unit, $estimatedCost, $offeredPrice, $downPayment, $finalPayment, $profitEstimate, $status, $notes, $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO product_custom_orders (order_code, client_id, customer_name, customer_phone, customer_email, product_name, description, order_date, due_date, qty, unit, estimated_cost, offered_price, down_payment, final_payment, profit_estimate, status, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([product_code('CUS'), $clientId, $customerName, $customerPhone, $customerEmail ?: null, $productName, $description, $orderDate, $dueDate, $qty, $unit, $estimatedCost, $offeredPrice, $downPayment, $finalPayment, $profitEstimate, $status, $notes, product_get_user_id()]);
                }
                $pdo->commit();
                custom_order_redirect(['success' => $action === 'update' ? 'updated' : 'created']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $pdo->prepare("DELETE FROM product_custom_orders WHERE id=?")->execute([$id]);
    }
    custom_order_redirect(['success' => 'deleted']);
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM product_custom_orders WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$detailRow = null;
if (isset($_GET['detail'])) {
    $stmt = $pdo->prepare("SELECT * FROM product_custom_orders WHERE id=?");
    $stmt->execute([(int)$_GET['detail']]);
    $detailRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$q = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(order_code LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR product_name LIKE ? OR description LIKE ? OR notes LIKE ?)";
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like,$like,$like,$like,$like,$like]);
}
if ($statusFilter !== '' && in_array($statusFilter, ['inquiry','approved','production','completed','cancelled'], true)) {
    $where[] = "status = ?";
    $params[] = $statusFilter;
}
$sql = "SELECT * FROM product_custom_orders" . ($where ? (' WHERE ' . implode(' AND ', $where)) : '') . " ORDER BY id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query("SELECT COUNT(*) total_orders, COALESCE(SUM(offered_price),0) total_offer, COALESCE(SUM(profit_estimate),0) total_profit, COALESCE(SUM(down_payment + final_payment),0) total_paid FROM product_custom_orders")->fetch(PDO::FETCH_ASSOC);
$activeSummary = $pdo->query("SELECT COUNT(*) FROM product_custom_orders WHERE status IN ('inquiry','approved','production')")->fetchColumn();
$overdueSummary = $pdo->query("SELECT COUNT(*) FROM product_custom_orders WHERE due_date IS NOT NULL AND due_date < CURDATE() AND status NOT IN ('completed','cancelled')")->fetchColumn();
$clients = $pdo->query("SELECT id,client_name,phone,email FROM clients WHERE status='active' ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);

$form = $editRow ?: [
    'id' => 0,
    'client_id' => 0,
    'customer_name' => '',
    'customer_phone' => '',
    'customer_email' => '',
    'product_name' => '',
    'description' => '',
    'order_date' => date('Y-m-d'),
    'due_date' => '',
    'qty' => 1,
    'unit' => 'pcs',
    'estimated_cost' => 0,
    'offered_price' => 0,
    'down_payment' => 0,
    'final_payment' => 0,
    'status' => 'inquiry',
    'notes' => '',
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>
<main class="main-content">
    <div class="container-fluid product-page py-3">
        <?php require_once __DIR__ . '/_nav.php'; ?>

        <?php if ($success): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm mb-3">
                <?php
                $messages = [
                    'created' => 'Custom order berhasil disimpan.',
                    'updated' => 'Custom order berhasil diperbarui.',
                    'deleted' => 'Custom order berhasil dihapus.',
                    'status' => 'Status custom order berhasil diperbarui.',
                ];
                echo product_safe($messages[$success] ?? 'Proses berhasil.');
                ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-3">
                <strong>Periksa kembali:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $e): ?><li><?= product_safe($e); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="product-card p-3"><div class="small-muted text-uppercase fw-bold">Total Order</div><h4 class="mb-0"><?= product_num($summary['total_orders'] ?? 0, 0); ?></h4></div></div>
            <div class="col-md-3"><div class="product-card p-3"><div class="small-muted text-uppercase fw-bold">Order Aktif</div><h4 class="mb-0"><?= product_num($activeSummary, 0); ?></h4></div></div>
            <div class="col-md-3"><div class="product-card p-3"><div class="small-muted text-uppercase fw-bold">Nilai Penawaran</div><h4 class="mb-0"><?= product_money($summary['total_offer'] ?? 0); ?></h4></div></div>
            <div class="col-md-3"><div class="product-card p-3"><div class="small-muted text-uppercase fw-bold">Overdue</div><h4 class="mb-0 <?= ((int)$overdueSummary > 0 ? 'text-danger' : ''); ?>"><?= product_num($overdueSummary, 0); ?></h4></div></div>
        </div>

        <div class="row g-3">
            <div class="col-xl-4 col-lg-5">
                <div class="product-card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong><?= $editRow ? 'Edit Custom Order' : 'Tambah Custom Order'; ?></strong>
                        <?php if ($editRow): ?><a href="custom_orders.php" class="btn btn-sm btn-outline-secondary">Batal Edit</a><?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-2" id="customOrderForm">
                            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'save'; ?>">
                            <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0); ?>">

                            <div class="col-12"><label class="form-label">Pilih Client</label><select name="client_id" id="customClient" class="form-select"><option value="">Client baru</option><?php foreach ($clients as $client): ?><option value="<?= (int)$client['id']; ?>" data-name="<?= product_safe($client['client_name']); ?>" data-phone="<?= product_safe($client['phone']); ?>" data-email="<?= product_safe($client['email']); ?>" <?= (int)($form['client_id'] ?? 0)===(int)$client['id']?'selected':''; ?>><?= product_safe($client['client_name']); ?><?= $client['phone'] ? ' · '.product_safe($client['phone']) : ''; ?></option><?php endforeach; ?></select><div class="small-muted mt-1">Pilih client lama atau isi data baru di bawah.</div></div>
                            <div class="col-12"><label class="form-label">Nama Client *</label><input id="customClientName" name="customer_name" class="form-control" value="<?= product_safe($form['customer_name']); ?>" required></div>
                            <div class="col-12"><label class="form-label">No. WhatsApp *</label><input id="customClientPhone" name="customer_phone" class="form-control" value="<?= product_safe($form['customer_phone']); ?>" required></div>
                            <div class="col-12"><label class="form-label">Email</label><input id="customClientEmail" type="email" name="customer_email" class="form-control" value="<?= product_safe($form['customer_email'] ?? ''); ?>"></div>
                            <div class="col-12"><label class="form-label">Nama Produk Custom *</label><input name="product_name" class="form-control" value="<?= product_safe($form['product_name']); ?>" required></div>
                            <div class="col-6"><label class="form-label">Tanggal Order</label><input type="date" name="order_date" class="form-control" value="<?= product_safe($form['order_date'] ?: date('Y-m-d')); ?>"></div>
                            <div class="col-6"><label class="form-label">Deadline</label><input type="date" name="due_date" class="form-control" value="<?= product_safe($form['due_date']); ?>"></div>
                            <div class="col-6"><label class="form-label">Qty</label><input name="qty" class="form-control qty-input" value="<?= product_num($form['qty'] ?? 1); ?>"></div>
                            <div class="col-6"><label class="form-label">Satuan</label><input name="unit" class="form-control" value="<?= product_safe($form['unit'] ?: 'pcs'); ?>"></div>
                            <div class="col-6"><label class="form-label">Estimasi Cost</label><input name="estimated_cost" class="form-control money-input" value="<?= product_num($form['estimated_cost'] ?? 0, 0); ?>"></div>
                            <div class="col-6"><label class="form-label">Harga Penawaran</label><input name="offered_price" class="form-control money-input" value="<?= product_num($form['offered_price'] ?? 0, 0); ?>"></div>
                            <div class="col-6"><label class="form-label">DP</label><input name="down_payment" class="form-control money-input" value="<?= product_num($form['down_payment'] ?? 0, 0); ?>"></div>
                            <div class="col-6"><label class="form-label">Pelunasan</label><input name="final_payment" class="form-control money-input" value="<?= product_num($form['final_payment'] ?? 0, 0); ?>"></div>
                            <div class="col-12"><label class="form-label">Status</label><select name="status" class="form-select"><?= custom_order_status_options($form['status'] ?? 'inquiry'); ?></select></div>

                            <div class="col-12">
                                <div class="p-3 rounded-4 border bg-light" id="orderSummary">
                                    <div class="row g-2">
                                        <div class="col-6"><div class="small-muted fw-bold">Profit Estimasi</div><div class="fw-bold" id="sumProfit">Rp 0</div></div>
                                        <div class="col-6"><div class="small-muted fw-bold">Sisa Tagihan</div><div class="fw-bold" id="sumBalance">Rp 0</div></div>
                                        <div class="col-6"><div class="small-muted fw-bold">Total Dibayar</div><div class="fw-bold" id="sumPaid">Rp 0</div></div>
                                        <div class="col-6"><div class="small-muted fw-bold">Status Pembayaran</div><div class="fw-bold" id="sumPayStatus">-</div></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12"><textarea name="description" class="form-control" placeholder="Deskripsi pesanan" rows="3"><?= product_safe($form['description']); ?></textarea></div>
                            <div class="col-12"><textarea name="notes" class="form-control" placeholder="Catatan internal" rows="2"><?= product_safe($form['notes']); ?></textarea></div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-success btn-rounded"><?= $editRow ? 'Update Order' : 'Simpan Order'; ?></button>
                                <button type="reset" class="btn btn-outline-secondary btn-rounded">Reset</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="product-card">
                    <div class="card-header"><strong>Panduan Custom Order</strong></div>
                    <div class="card-body small">
                        <ol class="mb-3 ps-3">
                            <li>Isi estimasi cost berdasarkan kalkulasi bahan, tenaga kerja, dan overhead.</li>
                            <li>Harga penawaran adalah nilai yang diberikan kepada customer.</li>
                            <li>Profit estimasi = harga penawaran - estimasi cost.</li>
                            <li>DP dan pelunasan digunakan untuk memantau sisa tagihan.</li>
                            <li>Gunakan status untuk workflow: Inquiry → Approved → Production → Completed.</li>
                        </ol>
                        <div class="alert alert-light border mb-0 small">
                            Contoh: estimasi cost Rp 2.500.000, penawaran Rp 3.750.000, maka estimasi profit Rp 1.250.000.
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-8 col-lg-7">
                <div class="product-card">
                    <div class="card-header">
                        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <strong>Daftar Custom Order</strong>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="custom_orders.php?export=csv" class="btn btn-sm btn-outline-success">Export CSV</a>
                            </div>
                        </div>
                        <form method="get" class="row g-2 mt-3">
                            <div class="col-md-7"><input name="q" class="form-control" placeholder="Cari customer, produk, kode, catatan..." value="<?= product_safe($q); ?>"></div>
                            <div class="col-md-3"><select name="status" class="form-select"><option value="">Semua Status</option><?= custom_order_status_options($statusFilter); ?></select></div>
                            <div class="col-md-2 d-grid"><button class="btn btn-success">Filter</button></div>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 custom-order-table">
                            <thead>
                                <tr>
                                    <th style="width:130px">Kode</th>
                                    <th style="width:180px">Customer</th>
                                    <th>Produk</th>
                                    <th style="width:110px">Deadline</th>
                                    <th class="text-end" style="width:130px">Penawaran</th>
                                    <th class="text-end" style="width:130px">Profit</th>
                                    <th class="text-end" style="width:120px">Sisa</th>
                                    <th style="width:110px">Status</th>
                                    <th style="width:170px">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada custom order.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $r):
                                    $paid = (float)$r['down_payment'] + (float)$r['final_payment'];
                                    $balance = (float)$r['offered_price'] - $paid;
                                ?>
                                <tr>
                                    <td><strong><?= product_safe($r['order_code']); ?></strong><div class="small-muted"><?= product_safe($r['order_date']); ?></div></td>
                                    <td><?= product_safe($r['customer_name']); ?><div class="small-muted"><?= product_safe($r['customer_phone']); ?></div></td>
                                    <td><strong><?= product_safe($r['product_name']); ?></strong><div class="small-muted"><?= product_num($r['qty']) . ' ' . product_safe($r['unit']); ?></div></td>
                                    <td><?= custom_order_due_label($r['due_date'], $r['status']); ?></td>
                                    <td class="text-end"><?= product_money($r['offered_price']); ?></td>
                                    <td class="text-end fw-bold <?= ((float)$r['profit_estimate'] < 0 ? 'text-danger' : ''); ?>"><?= product_money($r['profit_estimate']); ?></td>
                                    <td class="text-end"><?= product_money(max(0, $balance)); ?><div><?= custom_order_payment_status($r['offered_price'], $r['down_payment'], $r['final_payment']); ?></div></td>
                                    <td>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="action" value="quick_status">
                                            <input type="hidden" name="id" value="<?= (int)$r['id']; ?>">
                                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <?= custom_order_status_options($r['status']); ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="?detail=<?= (int)$r['id']; ?>" class="btn btn-sm btn-outline-secondary">Detail</a>
                                        <a href="?edit=<?= (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <a onclick="return confirm('Hapus custom order ini?')" href="?delete=<?= (int)$r['id']; ?>" class="btn btn-sm btn-outline-danger">Hapus</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-2 small-muted border-top">
                        Menampilkan maksimal 100 data terakhir. Gunakan filter untuk mencari data tertentu.
                    </div>
                </div>

                <?php if ($detailRow):
                    $paid = (float)$detailRow['down_payment'] + (float)$detailRow['final_payment'];
                    $balance = (float)$detailRow['offered_price'] - $paid;
                ?>
                <div class="product-card mt-3" id="detailOrderBox">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Detail Custom Order</strong>
                        <a href="custom_orders.php" class="btn btn-sm btn-outline-secondary">Tutup</a>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><div class="small-muted fw-bold">Kode</div><div class="fw-bold"><?= product_safe($detailRow['order_code']); ?></div></div>
                            <div class="col-md-6"><div class="small-muted fw-bold">Status</div><div><?= custom_order_status_label($detailRow['status']); ?></div></div>
                            <div class="col-md-6"><div class="small-muted fw-bold">Customer</div><div><?= product_safe($detailRow['customer_name']); ?> / <?= product_safe($detailRow['customer_phone']); ?></div><?php if (!empty($detailRow['customer_email'])): ?><div class="small-muted"><?= product_safe($detailRow['customer_email']); ?></div><?php endif; ?><?php if (!empty($detailRow['client_id'])): ?><a class="small" href="<?= base_url('pages/clients/detail.php?id='.(int)$detailRow['client_id']); ?>">Buka profil client</a><?php endif; ?></div>
                            <div class="col-md-6"><div class="small-muted fw-bold">Produk</div><div><?= product_safe($detailRow['product_name']); ?> (<?= product_num($detailRow['qty']) . ' ' . product_safe($detailRow['unit']); ?>)</div></div>
                            <div class="col-md-3"><div class="small-muted fw-bold">Estimasi Cost</div><div><?= product_money($detailRow['estimated_cost']); ?></div></div>
                            <div class="col-md-3"><div class="small-muted fw-bold">Penawaran</div><div><?= product_money($detailRow['offered_price']); ?></div></div>
                            <div class="col-md-3"><div class="small-muted fw-bold">Dibayar</div><div><?= product_money($paid); ?></div></div>
                            <div class="col-md-3"><div class="small-muted fw-bold">Sisa Tagihan</div><div><?= product_money(max(0, $balance)); ?></div></div>
                            <div class="col-md-12"><div class="small-muted fw-bold">Deskripsi</div><div><?= nl2br(product_safe($detailRow['description'])); ?></div></div>
                            <div class="col-md-12"><div class="small-muted fw-bold">Catatan</div><div><?= nl2br(product_safe($detailRow['notes'])); ?></div></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<style>
.custom-order-table th,
.custom-order-table td { vertical-align: middle; }
.custom-order-table th { white-space: nowrap; }
.custom-order-table td { font-size: 0.92rem; }
@media (max-width: 1200px) {
    .custom-order-table { min-width: 1050px; }
}
</style>

<script>
(function(){
    function parseIDR(value){
        value = (value || '').toString().replace(/[^0-9,-]/g, '');
        value = value.replace(',', '.');
        return parseFloat(value) || 0;
    }
    function formatIDR(num){
        num = Math.round(num || 0);
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function formatCurrency(num){
        return 'Rp ' + formatIDR(num);
    }
    document.querySelectorAll('.money-input').forEach(function(input){
        input.addEventListener('input', function(){
            var raw = this.value.replace(/[^0-9]/g, '');
            this.value = raw ? formatIDR(parseInt(raw, 10)) : '0';
            calculateSummary();
        });
        input.addEventListener('focus', function(){ if(this.value === '0') this.select(); });
    });
    document.querySelectorAll('.qty-input').forEach(function(input){
        input.addEventListener('input', calculateSummary);
    });
    var clientSelect = document.getElementById('customClient');
    if(clientSelect) clientSelect.addEventListener('change', function(){
        var option = this.options[this.selectedIndex];
        if(!option.value) return;
        document.getElementById('customClientName').value = option.dataset.name || '';
        document.getElementById('customClientPhone').value = option.dataset.phone || '';
        document.getElementById('customClientEmail').value = option.dataset.email || '';
    });
    function calculateSummary(){
        var form = document.getElementById('customOrderForm');
        if(!form) return;
        var cost = parseIDR(form.querySelector('[name="estimated_cost"]').value);
        var offer = parseIDR(form.querySelector('[name="offered_price"]').value);
        var dp = parseIDR(form.querySelector('[name="down_payment"]').value);
        var finalPay = parseIDR(form.querySelector('[name="final_payment"]').value);
        var profit = offer - cost;
        var paid = dp + finalPay;
        var balance = Math.max(0, offer - paid);
        document.getElementById('sumProfit').textContent = formatCurrency(profit);
        document.getElementById('sumProfit').className = 'fw-bold ' + (profit < 0 ? 'text-danger' : 'text-success');
        document.getElementById('sumPaid').textContent = formatCurrency(paid);
        document.getElementById('sumBalance').textContent = formatCurrency(balance);
        var payStatus = '-';
        if(offer <= 0) payStatus = 'Belum ada nilai';
        else if(paid <= 0) payStatus = 'Belum bayar';
        else if(paid >= offer) payStatus = 'Lunas';
        else payStatus = 'DP / Partial';
        document.getElementById('sumPayStatus').textContent = payStatus;
    }
    calculateSummary();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
