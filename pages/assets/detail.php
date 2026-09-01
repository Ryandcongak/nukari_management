<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT 
        a.*, 
        e.full_name responsible_name, 
        e.position responsible_position,
        p.project_name,
        p.project_code,
        pt.category transaction_category,
        pt.amount transaction_amount
    FROM assets a
    LEFT JOIN employees e ON a.responsible_employee_id=e.id
    LEFT JOIN projects p ON a.source_project_id=p.id
    LEFT JOIN project_transactions pt ON a.source_transaction_id=pt.id
    WHERE a.id=:id 
    LIMIT 1
");
$stmt->execute([':id'=>$id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$asset) redirect('pages/assets/index.php');

$photosStmt = $pdo->prepare("SELECT * FROM project_asset_photos WHERE asset_id=:asset_id ORDER BY id ASC");
$photosStmt->execute([':asset_id'=>$id]);
$photos = $photosStmt->fetchAll(PDO::FETCH_ASSOC);

$maintenanceStmt = $pdo->prepare("SELECT * FROM asset_maintenance WHERE asset_id=:asset_id ORDER BY maintenance_date DESC, id DESC");
$maintenanceStmt->execute([':asset_id'=>$id]);
$maintenances = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC);

$docsStmt = $pdo->prepare("SELECT * FROM asset_documents WHERE asset_id=:asset_id ORDER BY id DESC");
$docsStmt->execute([':asset_id'=>$id]);
$documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

$salesStmt = $pdo->prepare("SELECT * FROM asset_sales WHERE asset_id=:asset_id ORDER BY sale_date DESC, id DESC");
$salesStmt->execute([':asset_id'=>$id]);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

$rentalsStmt = $pdo->prepare("SELECT * FROM asset_rentals WHERE asset_id=:asset_id ORDER BY id DESC");
$rentalsStmt->execute([':asset_id'=>$id]);
$rentals = $rentalsStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentsStmt = $pdo->prepare("SELECT * FROM asset_rental_payments WHERE asset_id=:asset_id ORDER BY payment_date DESC, id DESC");
$paymentsStmt->execute([':asset_id'=>$id]);
$rentalPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

$movementsStmt = $pdo->prepare("\n    SELECT m.*, fe.full_name AS from_employee_name, te.full_name AS to_employee_name\n    FROM asset_movements m\n    LEFT JOIN employees fe ON m.from_employee_id=fe.id\n    LEFT JOIN employees te ON m.to_employee_id=te.id\n    WHERE m.asset_id=:asset_id\n    ORDER BY m.movement_date DESC, m.id DESC\n");
$movementsStmt->execute([':asset_id'=>$id]);
$movements = $movementsStmt->fetchAll(PDO::FETCH_ASSOC);

$employees = $pdo->query("SELECT id, full_name FROM employees WHERE employment_status='active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$eventsStmt = $pdo->prepare("SELECT ev.*, u.full_name AS user_name FROM asset_events ev LEFT JOIN users u ON ev.created_by=u.id WHERE ev.asset_id=:asset_id ORDER BY ev.event_date DESC, ev.id DESC LIMIT 100");
$eventsStmt->execute([':asset_id'=>$id]);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$remindersStmt = $pdo->prepare("SELECT * FROM asset_reminders WHERE asset_id=:asset_id ORDER BY FIELD(status,'overdue','active','done','cancelled'), due_date ASC, id DESC");
$remindersStmt->execute([':asset_id'=>$id]);
$reminders = $remindersStmt->fetchAll(PDO::FETCH_ASSOC);

$auditsStmt = $pdo->prepare("SELECT au.*, se.full_name AS system_employee_name, ae.full_name AS actual_employee_name FROM asset_audits au LEFT JOIN employees se ON au.system_employee_id=se.id LEFT JOIN employees ae ON au.actual_employee_id=ae.id WHERE au.asset_id=:asset_id ORDER BY au.audit_date DESC, au.id DESC");
$auditsStmt->execute([':asset_id'=>$id]);
$audits = $auditsStmt->fetchAll(PDO::FETCH_ASSOC);

$borrowingsStmt = $pdo->prepare("SELECT b.*, e.full_name AS employee_name FROM asset_borrowings b LEFT JOIN employees e ON b.employee_id=e.id WHERE b.asset_id=:asset_id ORDER BY b.id DESC");
$borrowingsStmt->execute([':asset_id'=>$id]);
$borrowings = $borrowingsStmt->fetchAll(PDO::FETCH_ASSOC);

$bookValue = asset_depreciation_value($asset['purchase_price'] ?? 0, $asset['residual_value'] ?? 0, $asset['useful_life_months'] ?? 0, $asset['purchase_date'] ?? null);


$pageTitle = 'Detail Aset - Nukari';

function asset_condition_view($status) {
    $map = [
        'good' => ['Baik', 'success', 'bi-check-circle'],
        'maintenance' => ['Maintenance', 'warning', 'bi-tools'],
        'damaged' => ['Rusak', 'danger', 'bi-exclamation-triangle'],
        'lost' => ['Hilang', 'secondary', 'bi-question-circle'],
    ];
    return $map[$status] ?? [$status, 'secondary', 'bi-circle'];
}
[$conditionText, $conditionColor, $conditionIcon] = asset_condition_view($asset['condition_status']);
[$businessStatusText, $businessStatusColor, $businessStatusIcon] = asset_status_view($asset['asset_status'] ?? 'active');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
.asset-detail-hero {
    background: linear-gradient(135deg,#07553f,#198754);
    border-radius: 28px;
    padding: 30px;
    color: white;
    margin-bottom: 22px;
    position: relative;
    overflow: hidden;
}
.asset-detail-hero::after{content:"";position:absolute;right:-70px;top:-100px;width:280px;height:280px;border-radius:50%;background:rgba(255,255,255,.15)}
.asset-panel{background:#fff;border-radius:22px;padding:22px;border:1px solid #e2e8f0;box-shadow:0 10px 30px rgba(15,23,42,.06)}
.asset-main-photo{width:100%;max-height:390px;object-fit:cover;border-radius:22px;background:#f1f5f9}
.asset-info-item{padding:14px;border:1px solid #e2e8f0;border-radius:16px;background:#f8fafc;height:100%}
.asset-info-item .label{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:700}
.asset-info-item .value{font-weight:800;color:#0f172a}
.asset-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.asset-gallery a{display:block;border-radius:18px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc}
.asset-gallery img{width:100%;height:130px;object-fit:cover}
</style>

<main class="main-content">
    <div class="asset-detail-hero d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="position-relative">
            <span class="badge bg-white text-success rounded-pill mb-3 px-3 py-2">
                <i class="bi bi-box-seam me-1"></i> Detail Aset
            </span>
            <h1 class="fw-bold mb-2"><?= htmlspecialchars($asset['asset_name']); ?></h1>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-white text-dark rounded-pill px-3 py-2"><?= htmlspecialchars($asset['asset_code'] ?: '-'); ?></span>
                <span class="badge text-bg-<?= $conditionColor; ?> rounded-pill px-3 py-2">
                    <i class="bi <?= $conditionIcon; ?> me-1"></i><?= htmlspecialchars($conditionText); ?>
                </span>
                <span class="badge bg-white text-dark rounded-pill px-3 py-2"><?= htmlspecialchars($asset['category'] ?: 'Tanpa Kategori'); ?></span>
            </div>
        </div>
        <div class="d-flex gap-2 position-relative">
            <a class="btn btn-light rounded-pill fw-semibold" href="<?= base_url('pages/assets/edit.php?id=' . $id); ?>">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <a class="btn btn-outline-light rounded-pill fw-semibold" href="<?= base_url('pages/assets/index.php'); ?>">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="asset-panel h-100">
                <?php if($asset['photo']): ?>
                    <img src="<?= base_url($asset['photo']); ?>" class="asset-main-photo mb-3" alt="Foto Aset">
                <?php else: ?>
                    <div class="asset-info-item text-center py-5">Tidak ada foto utama.</div>
                <?php endif; ?>
                <div class="d-grid">
                    <a class="btn btn-outline-primary rounded-pill" href="<?= base_url('pages/assets/edit.php?id=' . $id); ?>">
                        <i class="bi bi-images me-1"></i> Kelola Foto Aset
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="asset-panel h-100">
                <h5 class="fw-bold mb-3">Informasi Aset</h5>
                <div class="row g-3">
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Tanggal Beli</div><div class="value"><?= htmlspecialchars($asset['purchase_date'] ?: '-'); ?></div></div></div>
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Harga Beli</div><div class="value"><?= rupiah($asset['purchase_price']); ?></div></div></div>
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Nilai Saat Ini</div><div class="value"><?= rupiah($asset['current_value'] ?? $asset['purchase_price']); ?></div></div></div>
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Garansi Sampai</div><div class="value"><?= htmlspecialchars($asset['warranty_until'] ?: '-'); ?></div></div></div>
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Status Bisnis</div><div class="value"><span class="badge text-bg-<?= $businessStatusColor; ?> rounded-pill"><i class="bi <?= $businessStatusIcon; ?> me-1"></i><?= htmlspecialchars($businessStatusText); ?></span></div></div></div>
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Lokasi</div><div class="value"><?= htmlspecialchars($asset['location'] ?: '-'); ?></div></div></div>
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Penanggung Jawab</div><div class="value"><?= htmlspecialchars($asset['responsible_name'] ?: '-'); ?></div><div class="small text-muted"><?= htmlspecialchars($asset['responsible_position'] ?: ''); ?></div></div></div>
                    <div class="col-md-6">
                        <div class="asset-info-item">
                            <div class="label">Sumber Aset</div>
                            <div class="value">
                                <?= ($asset['source_type'] ?? 'manual') === 'project_transaction' ? 'Transaksi Project' : 'Manual'; ?>
                            </div>
                            <?php if (($asset['source_type'] ?? 'manual') === 'project_transaction'): ?>
                                <div class="small text-muted"><?= htmlspecialchars($asset['project_name'] ?: '-'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6"><div class="asset-info-item"><div class="label">Jumlah Foto</div><div class="value"><?= count($photos); ?> Foto</div></div></div>
                    <div class="col-12"><div class="asset-info-item"><div class="label">Catatan</div><div><?= nl2br(htmlspecialchars($asset['notes'] ?: '-')); ?></div></div></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (($asset['source_type'] ?? 'manual') === 'project_transaction'): ?>
    <div class="asset-panel mb-3">
        <h5 class="fw-bold mb-3">Asal Project</h5>
        <div class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="asset-info-item">
                    <div class="label">Project</div>
                    <div class="value"><?= htmlspecialchars($asset['project_name'] ?: '-'); ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($asset['project_code'] ?: '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="asset-info-item">
                    <div class="label">Transaksi</div>
                    <div class="value"><?= htmlspecialchars($asset['transaction_category'] ?: '-'); ?></div>
                    <div class="small text-muted"><?= $asset['transaction_amount'] !== null ? rupiah($asset['transaction_amount']) : '-'; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <?php if (!empty($asset['source_project_id'])): ?>
                    <a class="btn btn-outline-success rounded-pill" href="<?= base_url('pages/projects/detail.php?id=' . (int)$asset['source_project_id']); ?>">
                        <i class="bi bi-kanban me-1"></i> Buka Project Asal
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="asset-panel">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold mb-1">Gallery Foto Aset</h5>
                <p class="text-muted small mb-0">Klik foto untuk membuka ukuran penuh.</p>
            </div>
            <a class="btn btn-sm btn-outline-primary rounded-pill" href="<?= base_url('pages/assets/edit.php?id=' . $id); ?>">
                <i class="bi bi-plus-circle me-1"></i> Tambah Foto
            </a>
        </div>

        <?php if ($photos): ?>
            <div class="asset-gallery">
                <?php foreach ($photos as $ph): ?>
                    <a href="<?= base_url($ph['image_path']); ?>" target="_blank">
                        <img src="<?= base_url($ph['image_path']); ?>" alt="Foto Aset">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-muted">Belum ada foto tambahan.</div>
        <?php endif; ?>
    </div>


    <div class="asset-panel mt-3" id="maintenance">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h5 class="fw-bold mb-0">Maintenance / Service</h5></div>
        <form method="POST" action="<?= base_url('pages/assets/add_maintenance.php'); ?>" enctype="multipart/form-data" class="row g-2 mb-3">
            <input type="hidden" name="asset_id" value="<?= $id; ?>">
            <div class="col-md-3"><input type="date" name="maintenance_date" class="form-control" value="<?= date('Y-m-d'); ?>"></div>
            <div class="col-md-3"><input name="maintenance_type" class="form-control" placeholder="Jenis service"></div>
            <div class="col-md-3"><input name="vendor_name" class="form-control" placeholder="Vendor / bengkel"></div>
            <div class="col-md-3"><input name="cost" class="form-control money-input" placeholder="Biaya"></div>
            <div class="col-md-4"><input type="date" name="next_maintenance_date" class="form-control" title="Reminder berikutnya"></div>
            <div class="col-md-4"><input type="file" name="proof_file" class="form-control" accept="image/*,.pdf"></div>
            <div class="col-md-4"><input name="description" class="form-control" placeholder="Keterangan"></div>
            <div class="col-12"><button class="btn btn-sm btn-nukari rounded-pill px-3"><i class="bi bi-plus-circle me-1"></i>Tambah Maintenance</button></div>
        </form>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Tanggal</th><th>Jenis</th><th>Vendor</th><th>Biaya</th><th>Reminder</th><th>File</th><th></th></tr></thead><tbody>
        <?php foreach($maintenances as $m): ?><tr><td><?= htmlspecialchars($m['maintenance_date']); ?></td><td><?= htmlspecialchars($m['maintenance_type'] ?: '-'); ?><div class="small text-muted"><?= htmlspecialchars($m['description'] ?: ''); ?></div></td><td><?= htmlspecialchars($m['vendor_name'] ?: '-'); ?></td><td><?= rupiah($m['cost']); ?></td><td><?= htmlspecialchars($m['next_maintenance_date'] ?: '-'); ?></td><td><?php if($m['proof_file']): ?><a target="_blank" href="<?= base_url($m['proof_file']); ?>">Buka</a><?php endif; ?></td><td><a class="text-danger" onclick="return confirm('Hapus data ini?')" href="<?= base_url('pages/assets/delete_asset_record.php?type=maintenance&id='.(int)$m['id'].'&asset_id='.$id); ?>"><i class="bi bi-trash"></i></a></td></tr><?php endforeach; ?>
        <?php if(!$maintenances): ?><tr><td colspan="7" class="text-muted">Belum ada data maintenance.</td></tr><?php endif; ?></tbody></table></div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="asset-panel h-100" id="documents">
                <h5 class="fw-bold mb-3">Dokumen Aset</h5>
                <form method="POST" action="<?= base_url('pages/assets/add_document.php'); ?>" enctype="multipart/form-data" class="row g-2 mb-3">
                    <input type="hidden" name="asset_id" value="<?= $id; ?>">
                    <div class="col-md-6"><input name="document_name" class="form-control" placeholder="Nama dokumen" required></div>
                    <div class="col-md-6"><select name="document_type" class="form-select"><option value="invoice">Invoice</option><option value="warranty">Garansi</option><option value="stnk">STNK</option><option value="tax">Pajak</option><option value="contract">Kontrak</option><option value="certificate">Sertifikat</option><option value="manual">Manual</option><option value="other">Lainnya</option></select></div>
                    <div class="col-md-6"><input type="date" name="expiry_date" class="form-control" title="Tanggal berakhir"></div>
                    <div class="col-md-6"><input type="file" name="file_path" class="form-control" accept="image/*,.pdf" required></div>
                    <div class="col-12"><button class="btn btn-sm btn-outline-primary rounded-pill">Tambah Dokumen</button></div>
                </form>
                <div class="list-group list-group-flush"><?php foreach($documents as $d): ?><div class="list-group-item d-flex justify-content-between"><div><div class="fw-semibold"><?= htmlspecialchars($d['document_name']); ?></div><div class="small text-muted"><?= htmlspecialchars($d['document_type']); ?> • Exp: <?= htmlspecialchars($d['expiry_date'] ?: '-'); ?></div></div><div><a target="_blank" href="<?= base_url($d['file_path']); ?>">Buka</a></div></div><?php endforeach; ?><?php if(!$documents): ?><div class="text-muted">Belum ada dokumen.</div><?php endif; ?></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="asset-panel h-100" id="movement">
                <h5 class="fw-bold mb-3">Mutasi Lokasi / Penanggung Jawab</h5>
                <form method="POST" action="<?= base_url('pages/assets/add_movement.php'); ?>" class="row g-2 mb-3">
                    <input type="hidden" name="asset_id" value="<?= $id; ?>">
                    <div class="col-md-4"><input type="date" name="movement_date" class="form-control" value="<?= date('Y-m-d'); ?>"></div>
                    <div class="col-md-4"><input name="to_location" class="form-control" placeholder="Lokasi baru"></div>
                    <div class="col-md-4"><select name="to_employee_id" class="form-select"><option value="">PJ baru</option><?php foreach($employees as $e): ?><option value="<?= (int)$e['id']; ?>"><?= htmlspecialchars($e['full_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><input name="description" class="form-control" placeholder="Catatan mutasi"></div>
                    <div class="col-12"><button class="btn btn-sm btn-outline-success rounded-pill">Catat Mutasi</button></div>
                </form>
                <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tanggal</th><th>Dari</th><th>Ke</th></tr></thead><tbody><?php foreach($movements as $mv): ?><tr><td><?= htmlspecialchars($mv['movement_date']); ?></td><td><?= htmlspecialchars($mv['from_location'] ?: '-'); ?><div class="small text-muted"><?= htmlspecialchars($mv['from_employee_name'] ?: '-'); ?></div></td><td><?= htmlspecialchars($mv['to_location'] ?: '-'); ?><div class="small text-muted"><?= htmlspecialchars($mv['to_employee_name'] ?: '-'); ?></div></td></tr><?php endforeach; ?><?php if(!$movements): ?><tr><td colspan="3" class="text-muted">Belum ada mutasi.</td></tr><?php endif; ?></tbody></table></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="asset-panel h-100" id="sale">
                <h5 class="fw-bold mb-3">Penjualan Aset</h5>
                <form method="POST" action="<?= base_url('pages/assets/add_sale.php'); ?>" enctype="multipart/form-data" class="row g-2 mb-3">
                    <input type="hidden" name="asset_id" value="<?= $id; ?>">
                    <div class="col-md-6"><input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d'); ?>"></div>
                    <div class="col-md-6"><input name="buyer_name" class="form-control" placeholder="Nama pembeli" required></div>
                    <div class="col-md-6"><input name="sale_price" class="form-control money-input" placeholder="Harga jual" required></div>
                    <div class="col-md-6"><input name="book_value" class="form-control money-input" value="<?= number_format((float)($asset['current_value'] ?? $asset['purchase_price']),0,',','.'); ?>" placeholder="Nilai buku"></div>
                    <div class="col-md-6"><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
                    <div class="col-md-6"><input type="file" name="proof_file" class="form-control" accept="image/*,.pdf"></div>
                    <div class="col-12"><button onclick="return confirm('Catat aset ini sebagai TERJUAL? Transaksi income akan dibuat otomatis.')" class="btn btn-sm btn-danger rounded-pill">Catat Penjualan</button></div>
                </form>
                <?php foreach($sales as $sale): ?><div class="border rounded-3 p-2 mb-2"><div class="fw-semibold"><?= htmlspecialchars($sale['buyer_name']); ?> • <?= htmlspecialchars($sale['sale_date']); ?></div><div>Harga jual: <?= rupiah($sale['sale_price']); ?> | Laba/Rugi: <?= rupiah($sale['profit_loss']); ?></div></div><?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="asset-panel h-100" id="rental">
                <h5 class="fw-bold mb-3">Penyewaan Aset</h5>
                <form method="POST" action="<?= base_url('pages/assets/add_rental.php'); ?>" class="row g-2 mb-3">
                    <input type="hidden" name="asset_id" value="<?= $id; ?>">
                    <div class="col-md-6"><input name="renter_name" class="form-control" placeholder="Nama penyewa" required></div>
                    <div class="col-md-6"><input name="renter_phone" class="form-control" placeholder="Telepon"></div>
                    <div class="col-md-6"><input type="date" name="rental_start" class="form-control" value="<?= date('Y-m-d'); ?>"></div>
                    <div class="col-md-6"><input type="date" name="rental_end" class="form-control"></div>
                    <div class="col-md-6"><select name="billing_cycle" class="form-select"><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="monthly" selected>Bulanan</option><option value="annual">Tahunan</option><option value="custom">Custom</option></select></div>
                    <div class="col-md-6"><input name="rental_amount" class="form-control money-input" placeholder="Nilai sewa"></div>
                    <div class="col-md-6"><input name="deposit_amount" class="form-control money-input" placeholder="Deposit"></div>
                    <div class="col-md-6"><input type="date" name="next_due_date" class="form-control" title="Jatuh tempo berikutnya"></div>
                    <div class="col-12"><button class="btn btn-sm btn-info rounded-pill text-white">Catat Penyewaan</button></div>
                </form>
                <?php foreach($rentals as $r): ?><div class="border rounded-3 p-2 mb-2"><div class="fw-semibold"><?= htmlspecialchars($r['renter_name']); ?> • <?= rupiah($r['rental_amount']); ?></div><div class="small text-muted">Mulai <?= htmlspecialchars($r['rental_start']); ?> | Due <?= htmlspecialchars($r['next_due_date'] ?: '-'); ?> | <?= htmlspecialchars($r['status']); ?></div>
                    <form method="POST" action="<?= base_url('pages/assets/add_rental_payment.php'); ?>" enctype="multipart/form-data" class="row g-2 mt-2"><input type="hidden" name="asset_id" value="<?= $id; ?>"><input type="hidden" name="rental_id" value="<?= (int)$r['id']; ?>"><div class="col-md-4"><input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d'); ?>"></div><div class="col-md-4"><input name="amount" class="form-control form-control-sm money-input" placeholder="Pembayaran"></div><div class="col-md-4"><input type="date" name="next_due_date" class="form-control form-control-sm" title="Due berikutnya"></div><div class="col-12"><button class="btn btn-sm btn-outline-primary rounded-pill">Catat Pembayaran Sewa</button></div></form></div><?php endforeach; ?>
                <?php if($rentalPayments): ?><hr><div class="small fw-bold mb-2">Riwayat Pembayaran</div><?php foreach($rentalPayments as $pay): ?><div class="small d-flex justify-content-between"><span><?= htmlspecialchars($pay['payment_date']); ?></span><span><?= rupiah($pay['amount']); ?></span></div><?php endforeach; ?><?php endif; ?>
            </div>
        </div>
    </div>


    <div class="row g-3 mt-1">
        <div class="col-lg-7">
            <div class="asset-panel h-100" id="timeline">
                <h5 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Timeline Lengkap Aset</h5>
                <?php if($events): foreach($events as $ev): ?>
                    <div class="border-start border-3 ps-3 pb-3 mb-2">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <div class="fw-bold"><?= htmlspecialchars($ev['title']); ?></div>
                            <span class="small text-muted"><?= htmlspecialchars($ev['event_date']); ?></span>
                        </div>
                        <div class="small text-muted"><?= htmlspecialchars($ev['event_type']); ?><?= $ev['user_name'] ? ' • '.htmlspecialchars($ev['user_name']) : ''; ?></div>
                        <?php if($ev['description']): ?><div class="small mt-1"><?= nl2br(htmlspecialchars($ev['description'])); ?></div><?php endif; ?>
                    </div>
                <?php endforeach; else: ?><div class="text-muted">Belum ada timeline.</div><?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="asset-panel h-100">
                <h5 class="fw-bold mb-3"><i class="bi bi-calculator me-2"></i>Penyusutan / Valuasi</h5>
                <div class="asset-info-item mb-2"><div class="label">Harga Beli</div><div class="value"><?= rupiah($asset['purchase_price'] ?? 0); ?></div></div>
                <div class="asset-info-item mb-2"><div class="label">Nilai Buku Estimasi</div><div class="value"><?= rupiah($bookValue); ?></div></div>
                <div class="asset-info-item mb-2"><div class="label">Umur Ekonomis</div><div class="value"><?= (int)($asset['useful_life_months'] ?? 0); ?> bulan</div></div>
                <div class="asset-info-item"><div class="label">Nilai Residu</div><div class="value"><?= rupiah($asset['residual_value'] ?? 0); ?></div></div>
                <div class="small text-muted mt-2">Metode: <?= htmlspecialchars($asset['depreciation_method'] ?? 'none'); ?>. Nilai ini estimasi sistem, bukan laporan akuntansi final.</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="asset-panel h-100" id="reminders">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-bell me-2"></i>Reminder Aset</h5>
                    <a class="btn btn-sm btn-outline-warning rounded-pill" href="<?= base_url('pages/assets/generate_notifications.php'); ?>">Sync Notifikasi</a>
                </div>
                <form method="POST" action="<?= base_url('pages/assets/add_reminder.php'); ?>" class="row g-2 mb-3">
                    <input type="hidden" name="asset_id" value="<?= $id; ?>">
                    <div class="col-md-6"><select name="reminder_type" class="form-select"><option value="service">Service Berkala</option><option value="oil_change">Ganti Oli</option><option value="vehicle_tax">Pajak Kendaraan</option><option value="stnk">STNK</option><option value="insurance">Asuransi</option><option value="kir">KIR</option><option value="calibration">Kalibrasi</option><option value="inspection">Inspeksi</option><option value="warranty">Garansi</option><option value="pln">PLN</option><option value="pdam">PDAM</option><option value="internet">Internet</option><option value="hosting">Hosting</option><option value="domain">Domain</option><option value="land_rent_contract">Kontrak Sewa Lahan</option><option value="warehouse_rent_contract">Kontrak Sewa Gudang</option><option value="building_rent_contract">Kontrak Sewa Bangunan</option><option value="vehicle_rent_contract">Kontrak Sewa Kendaraan</option><option value="other">Lainnya</option></select></div>
                    <div class="col-md-6"><input name="title" class="form-control" placeholder="Judul reminder" required></div>
                    <div class="col-md-4"><input type="date" name="due_date" class="form-control" required></div>
                    <div class="col-md-4"><input type="number" name="remind_before_days" class="form-control" value="7" placeholder="H- berapa hari"></div>
                    <div class="col-md-4"><select name="priority" class="form-select"><option value="low">Low</option><option value="warning" selected>Warning</option><option value="urgent">Urgent</option><option value="danger">Danger</option></select></div>
                    <div class="col-12"><input name="description" class="form-control" placeholder="Catatan reminder"></div>
                    <div class="col-12"><button class="btn btn-sm btn-warning rounded-pill">Tambah Reminder</button></div>
                </form>
                <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Jenis</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($reminders as $r): ?><tr><td><?= htmlspecialchars(asset_reminder_type_label($r['reminder_type'])); ?><div class="small text-muted"><?= htmlspecialchars($r['title']); ?></div></td><td><?= htmlspecialchars($r['due_date']); ?></td><td><span class="badge text-bg-<?= $r['status']=='overdue'?'danger':($r['status']=='active'?'warning':'secondary'); ?>"><?= htmlspecialchars($r['status']); ?></span></td><td><?php if($r['status']!='done'): ?><a class="btn btn-sm btn-outline-success" href="<?= base_url('pages/assets/update_reminder_status.php?id='.(int)$r['id'].'&asset_id='.$id.'&status=done'); ?>">Done</a><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$reminders): ?><tr><td colspan="4" class="text-muted">Belum ada reminder.</td></tr><?php endif; ?></tbody></table></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="asset-panel h-100" id="audit">
                <h5 class="fw-bold mb-3"><i class="bi bi-clipboard-check me-2"></i>Audit / Stock Opname</h5>
                <form method="POST" action="<?= base_url('pages/assets/add_audit.php'); ?>" enctype="multipart/form-data" class="row g-2 mb-3">
                    <input type="hidden" name="asset_id" value="<?= $id; ?>">
                    <div class="col-md-4"><input type="date" name="audit_date" value="<?= date('Y-m-d'); ?>" class="form-control"></div>
                    <div class="col-md-4"><select name="audit_type" class="form-select"><option value="audit">Audit</option><option value="stock_opname">Stock Opname</option></select></div>
                    <div class="col-md-4"><select name="physical_status" class="form-select"><option value="found">Ditemukan</option><option value="not_found">Tidak ditemukan</option><option value="damaged">Rusak</option><option value="moved">Berpindah</option><option value="borrowed">Dipinjam</option><option value="other">Lainnya</option></select></div>
                    <div class="col-md-6"><input name="actual_location" class="form-control" placeholder="Lokasi aktual"></div>
                    <div class="col-md-6"><select name="actual_employee_id" class="form-select"><option value="">PIC aktual</option><?php foreach($employees as $e): ?><option value="<?= (int)$e['id']; ?>"><?= htmlspecialchars($e['full_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><select name="condition_status" class="form-select"><option value="good">Baik</option><option value="maintenance">Maintenance</option><option value="damaged">Rusak</option><option value="lost">Hilang</option></select></div>
                    <div class="col-md-4"><input type="number" min="0" max="100" name="condition_score" class="form-control" value="100"></div>
                    <div class="col-md-4"><input type="file" name="proof_file" class="form-control" accept="image/*,.pdf"></div>
                    <div class="col-12"><input name="notes" class="form-control" placeholder="Catatan audit"></div>
                    <div class="col-12"><button class="btn btn-sm btn-success rounded-pill">Simpan Audit</button></div>
                </form>
                <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tanggal</th><th>Status</th><th>Lokasi Aktual</th><th>Score</th></tr></thead><tbody><?php foreach($audits as $au): ?><tr><td><?= htmlspecialchars($au['audit_date']); ?></td><td><?= htmlspecialchars($au['physical_status']); ?><div class="small text-muted"><?= htmlspecialchars($au['audit_type']); ?></div></td><td><?= htmlspecialchars($au['actual_location'] ?: '-'); ?><div class="small text-muted"><?= htmlspecialchars($au['actual_employee_name'] ?: '-'); ?></div></td><td><?= (int)$au['condition_score']; ?></td></tr><?php endforeach; ?><?php if(!$audits): ?><tr><td colspan="4" class="text-muted">Belum ada audit.</td></tr><?php endif; ?></tbody></table></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="asset-panel" id="borrowing">
                <h5 class="fw-bold mb-3"><i class="bi bi-arrow-left-right me-2"></i>Pinjam Pakai / Check-out & Check-in</h5>
                <form method="POST" action="<?= base_url('pages/assets/add_borrowing.php'); ?>" class="row g-2 mb-3">
                    <input type="hidden" name="asset_id" value="<?= $id; ?>">
                    <div class="col-md-3"><select name="borrower_type" class="form-select"><option value="employee">Karyawan</option><option value="external">External</option></select></div>
                    <div class="col-md-3"><select name="employee_id" class="form-select"><option value="">Pilih karyawan</option><?php foreach($employees as $e): ?><option value="<?= (int)$e['id']; ?>"><?= htmlspecialchars($e['full_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><input name="borrower_name" class="form-control" placeholder="Nama peminjam external"></div>
                    <div class="col-md-3"><input name="borrower_phone" class="form-control" placeholder="Telepon"></div>
                    <div class="col-md-3"><input type="datetime-local" name="check_out_date" class="form-control" value="<?= date('Y-m-d\\TH:i'); ?>"></div>
                    <div class="col-md-3"><input type="date" name="expected_return_date" class="form-control"></div>
                    <div class="col-md-3"><select name="out_condition" class="form-select"><option value="good">Baik</option><option value="maintenance">Maintenance</option><option value="damaged">Rusak</option></select></div>
                    <div class="col-md-3"><input name="purpose" class="form-control" placeholder="Keperluan"></div>
                    <div class="col-12"><button class="btn btn-sm btn-primary rounded-pill">Check-out / Pinjamkan</button></div>
                </form>
                <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Peminjam</th><th>Keluar</th><th>Target Kembali</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($borrowings as $b): ?><tr><td><?= htmlspecialchars($b['borrower_name']); ?><div class="small text-muted"><?= htmlspecialchars($b['purpose'] ?: '-'); ?></div></td><td><?= htmlspecialchars($b['check_out_date']); ?></td><td><?= htmlspecialchars($b['expected_return_date'] ?: '-'); ?></td><td><span class="badge text-bg-<?= $b['status']=='overdue'?'danger':($b['status']=='borrowed'?'warning':'success'); ?>"><?= htmlspecialchars($b['status']); ?></span></td><td><?php if(in_array($b['status'], ['borrowed','overdue'])): ?><form method="POST" action="<?= base_url('pages/assets/return_borrowing.php'); ?>" class="d-flex gap-1"><input type="hidden" name="borrowing_id" value="<?= (int)$b['id']; ?>"><input type="hidden" name="asset_id" value="<?= $id; ?>"><select name="return_condition" class="form-select form-select-sm"><option value="good">Baik</option><option value="maintenance">Maintenance</option><option value="damaged">Rusak</option><option value="lost">Hilang</option></select><button class="btn btn-sm btn-success">Return</button></form><?php else: ?><?= htmlspecialchars($b['check_in_date'] ?: '-'); ?><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$borrowings): ?><tr><td colspan="5" class="text-muted">Belum ada pinjam pakai.</td></tr><?php endif; ?></tbody></table></div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.money-input').forEach(function(input){
            input.addEventListener('input', function(){
                let v = String(this.value || '').replace(/[^0-9]/g,'');
                this.value = v ? new Intl.NumberFormat('id-ID').format(v) : '';
            });
        });
    });
    </script>

</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
