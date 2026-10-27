<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';

function parse_amount_id($value)
{
    $raw = trim((string) $value);
    $raw = str_replace(["\xc2\xa0", ' ', 'Rp', 'rp', 'IDR', 'idr'], '', $raw);
    $digitsOnly = preg_replace('/[^0-9]/', '', $raw);
    return $digitsOnly === '' ? 0 : (float) $digitsOnly;
}

function uploadTransactionReceipts($files, $transaction_id, $pdo)
{
    $paths = upload_store_multiple($files, 'project_transactions', 'trx_' . (int)$transaction_id, upload_image_mimes(), 5242880, 10);
    try {
        $stmt = $pdo->prepare("INSERT INTO project_transaction_receipts (transaction_id, image_path) VALUES (:transaction_id, :image_path)");
        foreach ($paths as $path) $stmt->execute([':transaction_id' => $transaction_id, ':image_path' => $path]);
    } catch (Throwable $e) {
        foreach ($paths as $path) upload_delete_file($path);
        throw $e;
    }
    return $paths;
}

function project_get_finance_accounts(PDO $pdo): array
{
    return $pdo->query("SELECT id, account_name, account_type, current_balance FROM finance_accounts WHERE status = 'active' ORDER BY is_default DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function project_get_open_operational_funds(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, fund_code, person_name, current_balance FROM operational_funds WHERE status = 'open' ORDER BY person_name ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function project_get_active_employees(PDO $pdo): array
{
    return $pdo->query("SELECT id, full_name, position FROM employees WHERE employment_status = 'active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function project_resolve_person(PDO $pdo, string $personalMode, ?int $employeeId, string $manualName): array
{
    $employeeId = $employeeId ?: null;
    $manualName = trim($manualName);
    if ($personalMode === 'employee') {
        if (!$employeeId) throw new Exception('Pilih karyawan untuk talangan personal.');
        $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $employeeId]);
        $name = (string)$stmt->fetchColumn();
        if ($name === '') throw new Exception('Data karyawan tidak ditemukan.');
        return [$name, $employeeId];
    }
    if ($manualName === '') throw new Exception('Nama personal wajib diisi.');
    return [$manualName, null];
}


$id = $_GET['id'] ?? null;
if (!$id) redirect('pages/projects/index.php');
$stmt = $pdo->prepare("SELECT pt.*, p.project_name FROM project_transactions pt JOIN projects p ON pt.project_id = p.id WHERE pt.id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$transaction) redirect('pages/projects/index.php');
$project_id = (int)$transaction['project_id'];
if (in_array($transaction['category'], ['Modal Project', 'Termin Pembayaran'])) redirect('pages/projects/detail.php?id=' . $project_id . '&tab=transactions');
$stmt = $pdo->prepare("SELECT * FROM project_transaction_receipts WHERE transaction_id = :transaction_id ORDER BY id DESC");
$stmt->execute([':transaction_id' => $id]);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'Edit Transaksi Project - Nukari';
$error = '';
$accounts = project_get_finance_accounts($pdo);
$operationalFunds = project_get_open_operational_funds($pdo);
$employees = project_get_active_employees($pdo);
$currentPersonalMode = !empty($transaction['personal_employee_id']) ? 'employee' : 'manual';

function deleteSelectedReceipts($receiptIds, $transaction_id, $pdo)
{
    if (empty($receiptIds) || !is_array($receiptIds)) return [];
    $deletedPaths = [];
    foreach ($receiptIds as $receiptId) {
        $receiptId = (int)$receiptId;
        $stmt = $pdo->prepare("SELECT * FROM project_transaction_receipts WHERE id = :id AND transaction_id = :transaction_id LIMIT 1");
        $stmt->execute([':id' => $receiptId, ':transaction_id' => $transaction_id]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            $deleteStmt = $pdo->prepare("DELETE FROM project_transaction_receipts WHERE id = :id AND transaction_id = :transaction_id LIMIT 1");
            $deleteStmt->execute([':id' => $receiptId, ':transaction_id' => $transaction_id]);
            $deletedPaths[] = $receipt['image_path'];
        }
    }
    return $deletedPaths;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = clean_input($_POST['transaction_type'] ?? 'expense');
    $category = clean_input($_POST['category'] ?? '');
    $description = clean_input($_POST['description'] ?? '');
    $funding_source = clean_input($_POST['funding_source'] ?? 'company');
    $account_id = (int)($_POST['account_id'] ?? 0);
    $operational_fund_id = (int)($_POST['operational_fund_id'] ?? 0);
    $personal_mode = clean_input($_POST['personal_mode'] ?? 'manual');
    $personal_employee_id = (int)($_POST['personal_employee_id'] ?? 0);
    $person_name_manual = clean_input($_POST['person_name'] ?? '');
    $payment_method = clean_input($_POST['payment_method'] ?? 'cash');
    $transaction_date = clean_input($_POST['transaction_date'] ?? date('Y-m-d'));
    $amount = parse_amount_id($_POST['amount'] ?? 0);
    $delete_receipts = $_POST['delete_receipts'] ?? [];
    $posting_mode = finance_normalize_posting_mode($_POST['posting_mode'] ?? ($transaction['posting_mode'] ?? 'posted'));
    $historical_reason = $posting_mode === 'historical' ? trim((string)($_POST['historical_reason'] ?? '')) : null;

    $uploadedReceiptPaths = [];
    $deletedReceiptPaths = [];
    try {
        $posting_mode = finance_validate_posting_mode($posting_mode, $historical_reason);
        $person_name = null;
        if ($type === 'expense' && $funding_source === 'person') [$person_name, $personal_employee_id] = project_resolve_person($pdo, $personal_mode, $personal_employee_id, $person_name_manual);
        if ($type === 'income') $funding_source = 'company';
        if ($amount <= 0) throw new Exception('Jumlah transaksi harus lebih dari 0.');
        if ($category === '') throw new Exception('Kategori transaksi wajib diisi.');
        if ($posting_mode === 'posted' && ($type === 'income' || ($type === 'expense' && $funding_source === 'company')) && $account_id <= 0) throw new Exception('Pilih akun kas/bank perusahaan.');
        if ($posting_mode === 'posted' && $type === 'expense' && $funding_source === 'operational_fund' && $operational_fund_id <= 0) throw new Exception('Pilih Dana Operasional personal.');

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE project_transactions SET transaction_date=:transaction_date, transaction_type=:transaction_type, category=:category, description=:description, funding_source=:funding_source, person_name=:person_name, personal_employee_id=:personal_employee_id, operational_fund_id=:operational_fund_id, account_id=:account_id, payment_method=:payment_method, amount=:amount, posting_mode=:posting_mode, historical_reason=:historical_reason WHERE id=:id LIMIT 1");
        $stmt->execute([
            ':transaction_date' => $transaction_date ?: date('Y-m-d'),
            ':transaction_type' => $type,
            ':category' => $category,
            ':description' => $description ?: null,
            ':funding_source' => $funding_source,
            ':person_name' => $person_name,
            ':personal_employee_id' => $personal_employee_id ?: null,
            ':operational_fund_id' => $funding_source === 'operational_fund' ? $operational_fund_id : null,
            ':account_id' => $funding_source === 'company' ? $account_id : null,
            ':payment_method' => $payment_method ?: 'cash',
            ':amount' => $amount,
            ':posting_mode' => $posting_mode,
            ':historical_reason' => $historical_reason,
            ':id' => $id,
        ]);
        finance_sync_project_transaction($pdo, (int)$id, $_SESSION['user_id'] ?? null);
        if (($transaction['posting_mode'] ?? 'posted') !== $posting_mode) {
            log_activity($pdo, 'Project Transaction', 'UPDATE', 'Dampak saldo diubah dari ' . finance_posting_mode_label($transaction['posting_mode'] ?? 'posted') . ' menjadi ' . finance_posting_mode_label($posting_mode) . ($historical_reason ? ' — ' . $historical_reason : ''), (int)$id, 'project_transactions');
        }
        $deletedReceiptPaths = deleteSelectedReceipts($delete_receipts, $id, $pdo);
        $uploadedReceiptPaths = uploadTransactionReceipts($_FILES['receipt_images'] ?? null, $id, $pdo);
        $pdo->commit();
        foreach ($deletedReceiptPaths as $path) upload_delete_file($path);
        redirect('pages/projects/detail.php?id=' . $project_id . '&tab=transactions');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($uploadedReceiptPaths as $path) upload_delete_file($path);
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content"><div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3"><div><h1 class="fw-bold mb-1">Edit Transaksi</h1><p class="text-muted mb-0"><?= htmlspecialchars($transaction['project_name']); ?></p></div><a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=transactions'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a></div>
<?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
<div class="row g-4"><div class="col-lg-8"><div class="panel"><form method="POST" enctype="multipart/form-data" class="row g-3">
<div class="col-md-6"><label class="form-label fw-semibold">Tanggal</label><input type="date" name="transaction_date" class="form-control" value="<?= htmlspecialchars($transaction['transaction_date'] ?: date('Y-m-d')); ?>" required></div>
<div class="col-md-6"><label class="form-label fw-semibold">Jenis Transaksi</label><select name="transaction_type" id="transaction_type" class="form-select"><option value="expense" <?= $transaction['transaction_type']==='expense'?'selected':''; ?>>Pengeluaran</option><option value="income" <?= $transaction['transaction_type']==='income'?'selected':''; ?>>Pemasukan</option></select></div>
<div class="col-md-12"><label class="form-label fw-semibold">Kategori</label><input type="text" name="category" class="form-control" value="<?= htmlspecialchars($transaction['category'] ?? ''); ?>" required></div>
<div class="col-md-12"><label class="form-label fw-semibold">Deskripsi</label><textarea name="description" rows="3" class="form-control"><?= htmlspecialchars($transaction['description'] ?? ''); ?></textarea></div>
<?php if (finance_user_can_use_non_posting()): ?><div class="col-md-12"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" id="posting_mode" class="form-select"><option value="posted" <?= ($transaction['posting_mode']??'posted')==='posted'?'selected':''; ?>>Transaksi berjalan — memengaruhi saldo</option><option value="historical" <?= ($transaction['posting_mode']??'')==='historical'?'selected':''; ?>>Catatan historis — tidak memengaruhi saldo/dana/hutang</option></select></div><div class="col-md-12 <?= ($transaction['posting_mode']??'posted')==='historical'?'':'d-none'; ?>" id="historical_reason_box"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"><?= htmlspecialchars($transaction['historical_reason'] ?? ''); ?></textarea></div><?php else: ?><input type="hidden" name="posting_mode" value="<?= htmlspecialchars($transaction['posting_mode'] ?? 'posted'); ?>"><?php endif; ?>
<div class="col-md-12" id="funding_section"><label class="form-label fw-semibold">Sumber Dana</label><select name="funding_source" id="funding_source" class="form-select"><option value="company" <?= $transaction['funding_source']==='company'?'selected':''; ?>>Akun Perusahaan</option><option value="operational_fund" <?= ($transaction['funding_source'] ?? '')==='operational_fund'?'selected':''; ?>>Dana Operasional Personal</option><option value="person" <?= $transaction['funding_source']==='person'?'selected':''; ?>>Talangan Personal</option></select></div>
<div class="col-md-12" id="company_box"><label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label><select name="account_id" class="form-select"><option value="">- Pilih Akun -</option><?php foreach ($accounts as $acc): ?><option value="<?= (int)$acc['id']; ?>" <?= ((int)($transaction['account_id'] ?? 0)===(int)$acc['id'])?'selected':''; ?>><?= htmlspecialchars($acc['account_name']); ?> - <?= rupiah($acc['current_balance']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-12" id="operational_box" style="display:none;"><label class="form-label fw-semibold">Dana Operasional Personal</label><select name="operational_fund_id" class="form-select"><option value="">- Pilih Dana Operasional -</option><?php foreach ($operationalFunds as $fund): ?><option value="<?= (int)$fund['id']; ?>" <?= ((int)($transaction['operational_fund_id'] ?? 0)===(int)$fund['id'])?'selected':''; ?>><?= htmlspecialchars($fund['person_name']); ?> - <?= rupiah($fund['current_balance']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-12" id="person_box" style="display:none;"><label class="form-label fw-semibold">Talangan Personal</label><select name="personal_mode" id="personal_mode" class="form-select mb-2"><option value="manual" <?= $currentPersonalMode==='manual'?'selected':''; ?>>Input Nama Manual</option><option value="employee" <?= $currentPersonalMode==='employee'?'selected':''; ?>>Pilih Karyawan</option></select><select name="personal_employee_id" id="personal_employee_id" class="form-select mb-2" style="display:none;"><option value="">- Pilih Karyawan -</option><?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id']; ?>" <?= ((int)($transaction['personal_employee_id'] ?? 0)===(int)$emp['id'])?'selected':''; ?>><?= htmlspecialchars($emp['full_name']); ?></option><?php endforeach; ?></select><input type="text" name="person_name" id="person_name" class="form-control" value="<?= htmlspecialchars($transaction['person_name'] ?? ''); ?>" placeholder="Nama lengkap personal"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Metode Pembayaran</label><select name="payment_method" class="form-select"><option value="cash" <?= ($transaction['payment_method']??'cash')==='cash'?'selected':''; ?>>Cash</option><option value="bank_transfer" <?= ($transaction['payment_method']??'')==='bank_transfer'?'selected':''; ?>>Transfer Bank</option><option value="qris" <?= ($transaction['payment_method']??'')==='qris'?'selected':''; ?>>QRIS</option><option value="other" <?= ($transaction['payment_method']??'')==='other'?'selected':''; ?>>Lainnya</option></select></div>
<div class="col-md-6"><label class="form-label fw-semibold">Jumlah</label><input type="text" id="amount_display" class="form-control currency-input" value="<?= number_format($transaction['amount'], 0, ',', '.'); ?>" autocomplete="off" required><input type="hidden" name="amount" id="amount" value="<?= (float)$transaction['amount']; ?>"></div>
<div class="col-md-12"><label class="form-label fw-semibold">Tambah Bukti Transaksi Baru <span class="text-muted">(Optional)</span></label><input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple></div>
<?php if (!empty($receipts)): ?><div class="col-md-12"><label class="form-label fw-semibold">Bukti Transaksi Saat Ini</label><div class="row g-3"><?php foreach ($receipts as $receipt): ?><div class="col-md-4 col-6"><div class="border rounded-4 p-2 h-100"><a href="<?= base_url($receipt['image_path']); ?>" target="_blank"><img src="<?= base_url($receipt['image_path']); ?>" class="img-fluid rounded-3 mb-2" style="height:120px;width:100%;object-fit:cover;"></a><div class="form-check"><input class="form-check-input" type="checkbox" name="delete_receipts[]" value="<?= $receipt['id']; ?>" id="receipt_<?= $receipt['id']; ?>"><label class="form-check-label small text-danger" for="receipt_<?= $receipt['id']; ?>">Hapus gambar ini</label></div></div></div><?php endforeach; ?></div></div><?php endif; ?>
<div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Perubahan</button><a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=transactions'); ?>" class="btn btn-light">Batal</a></div>
</form></div></div><div class="col-lg-4"><div class="panel"><h5 class="fw-bold mb-3">Catatan</h5><ul class="small text-muted mb-0"><li>Akun Perusahaan mengubah saldo kas/bank.</li><li>Dana Operasional mengubah saldo personal.</li><li>Talangan Personal menjadi hutang perusahaan.</li></ul></div></div></div></main>
<script>function syncAmount(){const el=document.getElementById('amount_display'),h=document.getElementById('amount');h.value=(el.value||'').replace(/\D/g,'');}function toggleFunding(){const type=document.getElementById('transaction_type').value;const f=document.getElementById('funding_source').value;const show=type==='expense';document.getElementById('funding_section').style.display=show?'block':'none';document.getElementById('company_box').style.display=(!show||f==='company')?'block':'none';document.getElementById('operational_box').style.display=(show&&f==='operational_fund')?'block':'none';document.getElementById('person_box').style.display=(show&&f==='person')?'block':'none';}function togglePersonalMode(){const m=document.getElementById('personal_mode').value;document.getElementById('personal_employee_id').style.display=m==='employee'?'block':'none';document.getElementById('person_name').style.display=m==='manual'?'block':'none';}function togglePostingMode(){const field=document.getElementById('posting_mode');if(!field)return;const historical=field.value==='historical';document.getElementById('historical_reason_box').classList.toggle('d-none',!historical);document.querySelector('[name="historical_reason"]').required=historical;}document.getElementById('amount_display').addEventListener('input',syncAmount);document.getElementById('transaction_type').addEventListener('change',toggleFunding);document.getElementById('funding_source').addEventListener('change',toggleFunding);document.getElementById('personal_mode').addEventListener('change',togglePersonalMode);if(document.getElementById('posting_mode'))document.getElementById('posting_mode').addEventListener('change',togglePostingMode);toggleFunding();togglePersonalMode();togglePostingMode();syncAmount();</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
