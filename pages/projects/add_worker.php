<?php
require_once __DIR__ . '/../../includes/auth.php';

function parse_amount_id($value)
{
    $raw = trim((string) $value);
    $raw = str_replace(["\xc2\xa0", ' ', 'Rp', 'rp', 'IDR', 'idr'], '', $raw);
    $digitsOnly = preg_replace('/[^0-9]/', '', $raw);
    return $digitsOnly === '' ? 0 : (float) $digitsOnly;
}

function uploadProjectEvidenceReceipts($files, $transaction_id, $pdo)
{
    $transaction_id = (int) $transaction_id;
    if ($transaction_id <= 0 || !isset($files) || empty($files['name'][0])) return;
    $uploadDir = __DIR__ . '/../../uploads/project_transactions/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSize = 5 * 1024 * 1024;
    foreach ($files['name'] as $key => $name) {
        if (($files['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($files['error'][$key] !== UPLOAD_ERR_OK) throw new Exception('Ada foto bukti yang gagal diupload.');
        if ($files['size'][$key] > $maxSize) throw new Exception('Ukuran setiap foto bukti maksimal 5MB.');
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new Exception('Format bukti harus JPG, JPEG, PNG, atau WEBP.');
        $fileName = 'trx_' . $transaction_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$key], $uploadDir . $fileName)) throw new Exception('Gagal menyimpan foto bukti.');
        $stmt = $pdo->prepare("INSERT INTO project_transaction_receipts (transaction_id, image_path) VALUES (:transaction_id, :image_path)");
        $stmt->execute([':transaction_id' => $transaction_id, ':image_path' => 'uploads/project_transactions/' . $fileName]);
    }
}

function project_get_finance_accounts(PDO $pdo): array
{
    return $pdo->query("SELECT id, account_name, account_type, current_balance FROM finance_accounts WHERE status = 'active' ORDER BY is_default DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}
function project_get_open_operational_funds(PDO $pdo): array
{
    try { return $pdo->query("SELECT id, fund_code, person_name, current_balance FROM operational_funds WHERE status = 'open' ORDER BY person_name ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return []; }
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

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) redirect('pages/projects/index.php');
$stmt = $pdo->prepare("SELECT project_name, contract_value FROM projects WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) redirect('pages/projects/index.php');

$pageTitle = 'Tambah Pekerja Project - Nukari';
$error = '';
$accounts = project_get_finance_accounts($pdo);
$operationalFunds = project_get_open_operational_funds($pdo);
$employees = project_get_active_employees($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $worker_name = clean_input($_POST['worker_name'] ?? '');
    $worker_type = clean_input($_POST['worker_type'] ?? 'freelance');
    $payment_type = clean_input($_POST['payment_type'] ?? 'daily');
    $rate = parse_amount_id($_POST['rate'] ?? 0);
    $funding_source = clean_input($_POST['funding_source'] ?? 'company');
    $account_id = (int)($_POST['account_id'] ?? 0);
    $operational_fund_id = (int)($_POST['operational_fund_id'] ?? 0);
    $personal_mode = clean_input($_POST['personal_mode'] ?? 'manual');
    $personal_employee_id = (int)($_POST['personal_employee_id'] ?? 0);
    $person_name_manual = clean_input($_POST['person_name'] ?? '');
    $payment_method = clean_input($_POST['payment_method'] ?? 'cash');
    $notes = clean_input($_POST['notes'] ?? '');

    try {
        $person_name = null;
        if ($funding_source === 'person') [$person_name, $personal_employee_id] = project_resolve_person($pdo, $personal_mode, $personal_employee_id, $person_name_manual);
        if ($worker_name === '') throw new Exception('Nama pekerja wajib diisi.');
        if ($rate <= 0) throw new Exception('Rate atau nilai bayaran harus lebih dari 0.');
        if ($funding_source === 'company' && $account_id <= 0) throw new Exception('Pilih akun kas/bank perusahaan.');
        if ($funding_source === 'operational_fund' && $operational_fund_id <= 0) throw new Exception('Pilih Dana Operasional personal.');

        $pdo->beginTransaction();
        $description = 'Biaya pekerja: ' . $worker_name . ' (' . $worker_type . ' - ' . $payment_type . ')';
        $trx = $pdo->prepare("INSERT INTO project_transactions
            (project_id, transaction_date, transaction_type, category, description, funding_source, person_name, personal_employee_id, operational_fund_id, account_id, payment_method, amount, source_module)
            VALUES (:project_id, CURDATE(), 'expense', 'Biaya Pekerja', :description, :funding_source, :person_name, :personal_employee_id, :operational_fund_id, :account_id, :payment_method, :amount, 'worker')");
        $trx->execute([
            ':project_id' => $project_id,
            ':description' => $description,
            ':funding_source' => $funding_source,
            ':person_name' => $person_name,
            ':personal_employee_id' => $personal_employee_id ?: null,
            ':operational_fund_id' => $funding_source === 'operational_fund' ? $operational_fund_id : null,
            ':account_id' => $funding_source === 'company' ? $account_id : null,
            ':payment_method' => $payment_method ?: 'cash',
            ':amount' => $rate,
        ]);
        $transaction_id = (int)$pdo->lastInsertId();
        finance_apply_project_expense_source($pdo, 'project_transactions', $transaction_id, (int)$project_id, $funding_source, $rate, 'Biaya Pekerja', $description, date('Y-m-d'), $account_id ?: null, $operational_fund_id ?: null, $person_name, $personal_employee_id ?: null, $payment_method, $_SESSION['user_id'] ?? null);

        $stmt = $pdo->prepare("INSERT INTO project_workers
            (project_id, worker_name, worker_type, payment_type, rate, funding_source, person_name, personal_employee_id, operational_fund_id, account_id, payment_method, transaction_id, notes)
            VALUES (:project_id, :worker_name, :worker_type, :payment_type, :rate, :funding_source, :person_name, :personal_employee_id, :operational_fund_id, :account_id, :payment_method, :transaction_id, :notes)");
        $stmt->execute([
            ':project_id' => $project_id,
            ':worker_name' => $worker_name,
            ':worker_type' => $worker_type,
            ':payment_type' => $payment_type,
            ':rate' => $rate,
            ':funding_source' => $funding_source,
            ':person_name' => $person_name,
            ':personal_employee_id' => $personal_employee_id ?: null,
            ':operational_fund_id' => $funding_source === 'operational_fund' ? $operational_fund_id : null,
            ':account_id' => $funding_source === 'company' ? $account_id : null,
            ':payment_method' => $payment_method ?: 'cash',
            ':transaction_id' => $transaction_id,
            ':notes' => $notes ?: null,
        ]);
        uploadProjectEvidenceReceipts($_FILES['receipt_images'] ?? null, $transaction_id, $pdo);
        $pdo->commit();
        redirect('pages/projects/detail.php?id=' . $project_id . '&tab=workers');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3"><div><h1 class="fw-bold mb-1">Tambah Pekerja Project</h1><p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p></div><a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=workers'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a></div>
<?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
<div class="row g-4"><div class="col-lg-8"><div class="panel"><form method="POST" enctype="multipart/form-data" class="row g-3">
<div class="col-md-12"><label class="form-label fw-semibold">Nama Pekerja / Tim</label><input type="text" name="worker_name" class="form-control" required></div>
<div class="col-md-6"><label class="form-label fw-semibold">Tipe Pekerja</label><select name="worker_type" class="form-select"><option value="employee">Employee / Karyawan Tetap</option><option value="freelance" selected>Freelance / Pekerja Lepas</option><option value="borongan">Borongan / Tim Borongan</option></select></div>
<div class="col-md-6"><label class="form-label fw-semibold">Sistem Pembayaran</label><select name="payment_type" class="form-select"><option value="daily">Harian</option><option value="per_attendance">Per Kedatangan</option><option value="monthly">Bulanan</option><option value="borongan">Borongan</option></select></div>
<div class="col-md-12"><label class="form-label fw-semibold">Rate / Nilai Bayaran</label><input type="text" id="rate_display" class="form-control currency-input" placeholder="Rp 150.000" autocomplete="off" required><input type="hidden" name="rate" id="rate_hidden"></div>
<div class="col-md-12"><label class="form-label fw-semibold">Sumber Dana Pembayaran</label><select name="funding_source" id="funding_source" class="form-select"><option value="company">Akun Perusahaan</option><option value="operational_fund">Dana Operasional Personal</option><option value="person">Talangan Personal</option></select></div>
<div class="col-md-12" id="company_box"><label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label><select name="account_id" class="form-select"><option value="">- Pilih Akun -</option><?php foreach ($accounts as $acc): ?><option value="<?= (int)$acc['id']; ?>"><?= htmlspecialchars($acc['account_name']); ?> - <?= rupiah($acc['current_balance']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-12" id="operational_box" style="display:none;"><label class="form-label fw-semibold">Dana Operasional Personal</label><select name="operational_fund_id" class="form-select"><option value="">- Pilih Dana Operasional -</option><?php foreach ($operationalFunds as $fund): ?><option value="<?= (int)$fund['id']; ?>"><?= htmlspecialchars($fund['person_name']); ?> - <?= rupiah($fund['current_balance']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-12" id="person_box" style="display:none;"><label class="form-label fw-semibold">Talangan Personal</label><select name="personal_mode" id="personal_mode" class="form-select mb-2"><option value="manual">Input Nama Manual</option><option value="employee">Pilih Karyawan</option></select><select name="personal_employee_id" id="personal_employee_id" class="form-select mb-2" style="display:none;"><option value="">- Pilih Karyawan -</option><?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id']; ?>"><?= htmlspecialchars($emp['full_name']); ?></option><?php endforeach; ?></select><input type="text" name="person_name" id="person_name" class="form-control" placeholder="Nama lengkap personal"></div>
<div class="col-md-12"><label class="form-label fw-semibold">Metode Pembayaran</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bank_transfer">Transfer Bank</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
<div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" rows="3" class="form-control"></textarea></div>
<div class="col-md-12"><label class="form-label fw-semibold">Upload Bukti Pembayaran <span class="text-muted">(Optional)</span></label><input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple></div>
<div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Pekerja</button><a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=workers'); ?>" class="btn btn-light">Batal</a></div>
</form></div></div><div class="col-lg-4"><div class="panel"><h5 class="fw-bold mb-3">Panduan</h5><ul class="small text-muted mb-0"><li>Pembayaran pekerja kini memakai engine dana yang sama dengan transaksi project.</li><li>Dana Operasional yang kurang otomatis menjadi hutang personal.</li></ul></div></div></div>
</main>
<script>function syncRate(){const el=document.getElementById('rate_display'),h=document.getElementById('rate_hidden');h.value=(el.value||'').replace(/\D/g,'');}function toggleFunding(){const f=document.getElementById('funding_source').value;document.getElementById('company_box').style.display=f==='company'?'block':'none';document.getElementById('operational_box').style.display=f==='operational_fund'?'block':'none';document.getElementById('person_box').style.display=f==='person'?'block':'none';}function togglePersonalMode(){const m=document.getElementById('personal_mode').value;document.getElementById('personal_employee_id').style.display=m==='employee'?'block':'none';document.getElementById('person_name').style.display=m==='manual'?'block':'none';}document.getElementById('rate_display').addEventListener('input',syncRate);document.getElementById('funding_source').addEventListener('change',toggleFunding);document.getElementById('personal_mode').addEventListener('change',togglePersonalMode);toggleFunding();togglePersonalMode();syncRate();</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
