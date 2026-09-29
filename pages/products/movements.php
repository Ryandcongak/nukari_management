<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Location: simple.php?info=' . urlencode('Mutasi Bahan sudah dinonaktifkan pada Produk Sederhana.'));
exit;
require_once __DIR__ . '/product_helpers.php';
require_once __DIR__ . '/../../includes/finance_helper.php';
require_once __DIR__ . '/../../includes/master_data_helper.php';
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
    $acquisitionType = $_POST['acquisition_type'] ?? 'other';
    $fundingSource = $_POST['funding_source'] ?? 'none';
    $accountId = (int)($_POST['account_id'] ?? 0);
    $operationalFundId = (int)($_POST['operational_fund_id'] ?? 0);
    $personalEmployeeId = (int)($_POST['personal_employee_id'] ?? 0);
    $personalName = trim($_POST['personal_name'] ?? '');
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $paymentMethod = finance_normalize_payment_method($_POST['payment_method'] ?? 'cash');
    $referenceCode = trim($_POST['reference_code'] ?? '');

    $matStmt = $pdo->prepare("SELECT id, material_name, stock FROM raw_materials WHERE id=? AND status='active' LIMIT 1");
    $matStmt->execute([$mid]);
    $material = $matStmt->fetch(PDO::FETCH_ASSOC);

    if (!$material) {
        $error = 'Bahan baku tidak ditemukan atau tidak aktif.';
    } elseif ($qty == 0) {
        $error = 'Jumlah mutasi tidak boleh 0.';
    } elseif (in_array($movementType, ['out', 'damaged', 'lost'], true) && abs($qty) > (float)$material['stock']) {
        $error = 'Jumlah keluar/rusak/hilang melebihi stok tersedia.';
    } elseif ($movementType === 'in' && $acquisitionType === 'purchase' && $price <= 0) {
        $error = 'Harga satuan wajib diisi untuk pembelian bahan.';
    } elseif ($movementType === 'in' && $acquisitionType === 'purchase' && !in_array($fundingSource, ['company','operational_fund','personal','supplier_credit'], true)) {
        $error = 'Pilih sumber dana pembelian bahan.';
    } else {
        if (in_array($movementType, ['out', 'damaged', 'lost'], true)) {
            $qty = abs($qty);
        }
        if (in_array($movementType, ['in', 'return'], true)) {
            $qty = abs($qty);
        }

        $total = abs($qty) * $price;
        $isAcquisition = $movementType === 'in';
        if (!$isAcquisition) {$acquisitionType='other';$fundingSource='none';}
        if ($isAcquisition && $acquisitionType !== 'purchase') {$fundingSource='none';$total=0;$price=0;}
        try {
            if ($fundingSource === 'company' && $accountId <= 0) throw new Exception('Pilih akun kas/bank perusahaan.');
            if ($fundingSource === 'company') {
                $accountCheck=$pdo->prepare("SELECT current_balance FROM finance_accounts WHERE id=:id AND status='active' LIMIT 1");$accountCheck->execute([':id'=>$accountId]);
                $accountBalance=$accountCheck->fetchColumn();
                if($accountBalance===false)throw new Exception('Akun kas/bank tidak aktif atau tidak ditemukan.');
                if((float)$accountBalance<$total)throw new Exception('Saldo akun perusahaan tidak mencukupi untuk pembelian ini.');
            }
            if ($fundingSource === 'operational_fund' && $operationalFundId <= 0) throw new Exception('Pilih Dana Operasional.');
            if ($fundingSource === 'personal' && $personalEmployeeId <= 0 && $personalName === '') throw new Exception('Pilih karyawan atau isi nama personal.');
            if ($fundingSource === 'supplier_credit' && $supplierId <= 0) throw new Exception('Pilih supplier untuk pembelian tempo/hutang.');
            if ($fundingSource === 'operational_fund') {
                $fundCheck=$pdo->prepare("SELECT current_balance FROM operational_funds WHERE id=:id AND status='open' LIMIT 1");$fundCheck->execute([':id'=>$operationalFundId]);
                if ((float)$fundCheck->fetchColumn() < $total) throw new Exception('Saldo Dana Operasional tidak mencukupi. Pilih Personal jika selisih merupakan talangan.');
            }
            $proofFile = product_upload_acquisition_proof('proof_file');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO raw_material_movements (material_id,movement_date,movement_type,quantity,unit_price,total_amount,reference_type,acquisition_type,funding_source,account_id,operational_fund_id,personal_employee_id,personal_name,supplier_id,payment_method,reference_code,proof_file,description,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->execute([$mid,$movementDate,$movementType,$qty,$price,$total,$referenceType,$acquisitionType,$fundingSource,$accountId?:null,$operationalFundId?:null,$personalEmployeeId?:null,$personalName?:null,$supplierId?:null,$paymentMethod,$referenceCode?:null,$proofFile,$description,product_get_user_id()]);
            $movementId=(int)$pdo->lastInsertId();

            $fundDescription='Pembelian bahan '.$material['material_name'].($description!==''?' - '.$description:'');
            if ($fundingSource === 'company') {
                $transactionId=finance_upsert_transaction($pdo,'other','raw_material_movements',$movementId,'expense',null,$total,$fundDescription,'Material',$movementDate,$paymentMethod,$referenceCode?:('MAT-'.$movementId),product_get_user_id(),$accountId,$proofFile);
                $pdo->prepare("UPDATE raw_material_movements SET transaction_id=:tx WHERE id=:id")->execute([':tx'=>$transactionId,':id'=>$movementId]);
            } elseif ($fundingSource === 'operational_fund') {
                opfund_add_movement($pdo,$operationalFundId,'expense',$total,$movementDate,null,$fundDescription,'product','raw_material_movements',$movementId,null,$paymentMethod,product_get_user_id());
            } elseif (in_array($fundingSource,['personal','supplier_credit'],true)) {
                $partyType=$fundingSource==='supplier_credit'?'supplier':($personalEmployeeId?'employee':'personal');
                $partyId=$fundingSource==='supplier_credit'?$supplierId:($personalEmployeeId?:null);
                if ($fundingSource==='supplier_credit') {$ps=$pdo->prepare("SELECT supplier_name FROM suppliers WHERE id=:id");$ps->execute([':id'=>$supplierId]);$partyName=(string)$ps->fetchColumn();}
                elseif($personalEmployeeId){$ps=$pdo->prepare("SELECT full_name FROM employees WHERE id=:id");$ps->execute([':id'=>$personalEmployeeId]);$partyName=(string)$ps->fetchColumn();} else $partyName=$personalName;
                $debtCode='MAT-'.date('YmdHis').'-'.random_int(10,99);
                $debt=$pdo->prepare("INSERT INTO debts(debt_code,type,related_type,related_id,related_name,category,issue_date,amount,principal_amount,paid_amount,due_date,payment_method,proof_file,source_module,source_table,source_id,reference_code,status,description,created_by,created_at,updated_at) VALUES(:code,'debt',:party_type,:party_id,:party_name,'Pembelian Bahan',:issue_date,:amount,:principal_amount,0,:due_date,:method,:proof,'other','raw_material_movements',:source_id,:reference,'unpaid',:description,:user,NOW(),NOW())");
                $debt->execute([':code'=>$debtCode,':party_type'=>$partyType,':party_id'=>$partyId,':party_name'=>$partyName,':issue_date'=>$movementDate,':amount'=>$total,':principal_amount'=>$total,':due_date'=>($_POST['due_date']??'')?:null,':method'=>$paymentMethod,':proof'=>$proofFile,':source_id'=>$movementId,':reference'=>$referenceCode?:('MAT-'.$movementId),':description'=>$fundDescription,':user'=>product_get_user_id()]);
                $pdo->prepare("UPDATE raw_material_movements SET debt_id=:debt WHERE id=:id")->execute([':debt'=>(int)$pdo->lastInsertId(),':id'=>$movementId]);
            }

            if (in_array($movementType, ['in', 'return'], true) && $price>0) $pdo->prepare("UPDATE raw_materials SET average_price=? WHERE id=?")->execute([$price,$mid]);
            product_recalc_material_stock($pdo,$mid);
            $pdo->commit();
            header('Location: movements.php?material_id='.$mid.'&success=1');exit;
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            $error=$e->getMessage();
        }
    }
}

$accounts=$pdo->query("SELECT id,account_name,current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC,account_name")->fetchAll(PDO::FETCH_ASSOC);
$operationalFunds=$pdo->query("SELECT id,fund_code,person_name,current_balance FROM operational_funds WHERE status='open' ORDER BY person_name")->fetchAll(PDO::FETCH_ASSOC);
$employees=$pdo->query("SELECT id,full_name FROM employees WHERE employment_status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers=$pdo->query("SELECT id,supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

$materials = $pdo->query("SELECT * FROM raw_materials WHERE status='active' ORDER BY material_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$q = trim($_GET['q'] ?? '');
$fType = trim($_GET['type'] ?? '');
$fRef = trim($_GET['ref'] ?? '');
$fMaterial = (int)($_GET['material_filter'] ?? 0);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(r.material_name LIKE :q_name OR r.material_code LIKE :q_code OR m.description LIKE :q_description)";
    $queryValue = '%' . $q . '%';
    $params[':q_name'] = $queryValue;
    $params[':q_code'] = $queryValue;
    $params[':q_description'] = $queryValue;
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
                        <form method="post" enctype="multipart/form-data" class="row g-3">
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
                            <div class="col-12 acquisition-fields">
                                <label class="form-label">Cara Bahan Diperoleh</label>
                                <select name="acquisition_type" class="form-select" id="acquisitionType">
                                    <option value="purchase">Dibeli</option>
                                    <option value="free">Gratis / Diambil Tanpa Biaya</option>
                                    <option value="donation">Donasi</option>
                                    <option value="initial_stock">Stok Awal</option>
                                    <option value="return">Retur Masuk</option>
                                    <option value="other">Lainnya</option>
                                </select>
                            </div>
                            <div class="col-12 purchase-fields">
                                <label class="form-label">Sumber Dana Pembelian</label>
                                <select name="funding_source" class="form-select" id="fundingSource">
                                    <option value="company">Uang Perusahaan</option>
                                    <option value="operational_fund">Dana Operasional</option>
                                    <option value="personal">Talangan Personal / Karyawan</option>
                                    <option value="supplier_credit">Hutang / Tempo Supplier</option>
                                </select>
                            </div>
                            <div class="col-12 funding-box" data-funding="company">
                                <label class="form-label">Akun Kas/Bank Perusahaan</label>
                                <select name="account_id" class="form-select"><option value="">Pilih akun</option><?php foreach($accounts as $a):?><option value="<?=$a['id'];?>"><?=product_safe($a['account_name']);?> · <?=product_money($a['current_balance']);?></option><?php endforeach;?></select>
                            </div>
                            <div class="col-12 funding-box d-none" data-funding="operational_fund">
                                <label class="form-label">Dana Operasional</label>
                                <select name="operational_fund_id" class="form-select"><option value="">Pilih pemegang dana</option><?php foreach($operationalFunds as $f):?><option value="<?=$f['id'];?>"><?=product_safe($f['fund_code'].' · '.$f['person_name']);?> · <?=product_money($f['current_balance']);?></option><?php endforeach;?></select>
                            </div>
                            <div class="col-12 funding-box d-none" data-funding="personal">
                                <label class="form-label">Karyawan yang Menalangi</label>
                                <select name="personal_employee_id" class="form-select mb-2"><option value="">Bukan karyawan / isi nama</option><?php foreach($employees as $e):?><option value="<?=$e['id'];?>"><?=product_safe($e['full_name']);?></option><?php endforeach;?></select>
                                <input name="personal_name" class="form-control" placeholder="Nama personal jika bukan karyawan">
                            </div>
                            <div class="col-12 funding-box d-none" data-funding="supplier_credit">
                                <label class="form-label">Supplier</label>
                                <select name="supplier_id" class="form-select"><option value="">Pilih supplier</option><?php foreach($suppliers as $s):?><option value="<?=$s['id'];?>"><?=product_safe($s['supplier_name']);?></option><?php endforeach;?></select>
                                <label class="form-label mt-2">Jatuh Tempo</label><input type="date" name="due_date" class="form-control">
                            </div>
                            <div class="col-6 purchase-fields"><label class="form-label">Metode Pembayaran</label><select name="payment_method" class="form-select"><?=master_options($pdo,'payment_method','cash');?></select></div>
                            <div class="col-6 purchase-fields"><label class="form-label">Kode Invoice/Referensi</label><input name="reference_code" class="form-control" placeholder="Opsional"></div>
                            <div class="col-12 acquisition-fields"><label class="form-label">Bukti / Nota</label><input type="file" name="proof_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
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
                                <tr><th>Tanggal</th><th>Bahan</th><th>Jenis</th><th>Perolehan & Dana</th><th>Referensi</th><th>Qty</th><th>Harga</th><th>Nilai</th><th>Keterangan</th></tr>
                            </thead>
                            <tbody>
                            <?php if (!$movements): ?>
                                <tr><td colspan="9"><div class="empty-state">Belum ada mutasi sesuai filter.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($movements as $r): ?>
                                <tr>
                                    <td><strong><?= product_safe($r['movement_date']); ?></strong></td>
                                    <td><strong><?= product_safe($r['material_name']); ?></strong><div class="small-muted"><?= product_safe($r['material_code']); ?> · <?= product_safe($r['category']); ?></div></td>
                                    <td><span class="mv-badge <?= movement_class($r['movement_type']); ?>"><?= movement_label($r['movement_type']); ?></span></td>
                                    <td><strong><?=product_safe(ucwords(str_replace('_',' ',$r['acquisition_type']??'-')));?></strong><div class="small-muted"><?=product_safe(ucwords(str_replace('_',' ',$r['funding_source']??'none')));?></div></td>
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
<script>
(()=>{const movement=document.getElementById('movementType'),acquisition=document.getElementById('acquisitionType'),funding=document.getElementById('fundingSource');function render(){const incoming=movement?.value==='in',purchase=incoming&&acquisition?.value==='purchase';document.querySelectorAll('.acquisition-fields').forEach(el=>el.classList.toggle('d-none',!incoming));document.querySelectorAll('.purchase-fields').forEach(el=>el.classList.toggle('d-none',!purchase));document.querySelectorAll('.funding-box').forEach(el=>el.classList.toggle('d-none',!purchase||el.dataset.funding!==funding?.value));}movement?.addEventListener('change',render);acquisition?.addEventListener('change',render);funding?.addEventListener('change',render);render();})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
