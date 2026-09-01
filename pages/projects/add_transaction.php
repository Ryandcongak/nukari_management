<?php
require_once __DIR__ . '/../../includes/auth.php';

function parse_amount_id($value)
{
    $raw = trim((string) $value);
    $raw = str_replace(["\xc2\xa0", ' ', 'Rp', 'rp', 'IDR', 'idr'], '', $raw);
    $digitsOnly = preg_replace('/[^0-9]/', '', $raw);
    return $digitsOnly === '' ? 0 : (float) $digitsOnly;
}

function uploadTransactionReceipts($files, $transaction_id, $pdo)
{
    if (!isset($files) || empty($files['name'][0])) {
        return;
    }
    $uploadDir = __DIR__ . '/../../uploads/project_transactions/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSize = 5 * 1024 * 1024;
    foreach ($files['name'] as $key => $name) {
        if (($files['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($files['error'][$key] !== UPLOAD_ERR_OK) throw new Exception('Ada gambar bukti transaksi yang gagal diupload.');
        if ($files['size'][$key] > $maxSize) throw new Exception('Ukuran setiap gambar maksimal 5MB.');
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new Exception('Format gambar harus JPG, JPEG, PNG, atau WEBP.');
        $fileName = 'trx_' . $transaction_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . $fileName;
        if (!move_uploaded_file($files['tmp_name'][$key], $targetPath)) throw new Exception('Gagal menyimpan gambar bukti transaksi.');
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

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) redirect('pages/projects/index.php');

$stmt = $pdo->prepare("SELECT project_name FROM projects WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) redirect('pages/projects/index.php');

$pageTitle = 'Tambah Transaksi Project - Nukari';
$error = '';
$accounts = project_get_finance_accounts($pdo);
$operationalFunds = project_get_open_operational_funds($pdo);
$employees = project_get_active_employees($pdo);

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

    try {
        $person_name = null;
        if ($type === 'expense' && $funding_source === 'person') {
            [$person_name, $personal_employee_id] = project_resolve_person($pdo, $personal_mode, $personal_employee_id, $person_name_manual);
        }
        if ($type === 'income') {
            $funding_source = 'company';
        }

        if ($amount <= 0) throw new Exception('Jumlah harus lebih dari 0.');
        if ($category === '') throw new Exception('Kategori wajib diisi.');
        if ($type === 'expense' && $funding_source === 'company' && $account_id <= 0) throw new Exception('Pilih akun kas/bank perusahaan.');
        if ($type === 'expense' && $funding_source === 'operational_fund' && $operational_fund_id <= 0) throw new Exception('Pilih Dana Operasional personal.');

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO project_transactions
            (project_id, transaction_date, transaction_type, category, description, funding_source, person_name, personal_employee_id, operational_fund_id, account_id, payment_method, amount)
            VALUES (:project_id, :transaction_date, :type, :category, :description, :funding_source, :person_name, :personal_employee_id, :operational_fund_id, :account_id, :payment_method, :amount)");
        $stmt->execute([
            ':project_id' => $project_id,
            ':transaction_date' => $transaction_date ?: date('Y-m-d'),
            ':type' => $type,
            ':category' => $category,
            ':description' => $description ?: null,
            ':funding_source' => $funding_source,
            ':person_name' => $person_name,
            ':personal_employee_id' => $personal_employee_id ?: null,
            ':operational_fund_id' => $funding_source === 'operational_fund' ? $operational_fund_id : null,
            ':account_id' => $funding_source === 'company' ? $account_id : null,
            ':payment_method' => $payment_method ?: 'cash',
            ':amount' => $amount,
        ]);
        $transaction_id = (int)$pdo->lastInsertId();

        if ($type === 'expense') {
            finance_apply_project_expense_source($pdo, 'project_transactions', $transaction_id, (int)$project_id, $funding_source, $amount, $category, $description ?: $category, $transaction_date, $account_id ?: null, $operational_fund_id ?: null, $person_name, $personal_employee_id ?: null, $payment_method, $_SESSION['user_id'] ?? null);
        } elseif ($type === 'income' && $account_id > 0) {
            finance_upsert_transaction($pdo, 'project', 'project_transactions', $transaction_id, 'income', (int)$project_id, $amount, $description ?: $category, $category, $transaction_date, $payment_method, 'PROJECT-TRX-' . $transaction_id, $_SESSION['user_id'] ?? null, $account_id);
        }

        uploadTransactionReceipts($_FILES['receipt_images'] ?? null, $transaction_id, $pdo);
        $pdo->commit();
        redirect('pages/projects/detail.php?id=' . $project_id . '&tab=transactions#tab-transactions');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h1 class="fw-bold mb-1">Tambah Transaksi</h1><p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p></div>
        <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=transactions#tab-transactions'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <div class="row g-4"><div class="col-lg-8"><div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Tanggal</label><input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d'); ?>" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Jenis Transaksi</label><select name="transaction_type" id="transaction_type" class="form-select"><option value="expense">Pengeluaran</option><option value="income">Pemasukan</option></select></div>
            <div class="col-md-12"><label class="form-label fw-semibold">Kategori</label><input type="text" name="category" class="form-control" placeholder="Contoh: Bahan, Transport, Konsumsi" required></div>
            <div class="col-md-12"><label class="form-label fw-semibold">Deskripsi</label><textarea name="description" rows="3" class="form-control" placeholder="Optional"></textarea></div>
            <div class="col-md-12" id="funding_section"><label class="form-label fw-semibold">Sumber Dana</label><select name="funding_source" id="funding_source" class="form-select"><option value="company">Akun Perusahaan</option><option value="operational_fund">Dana Operasional Personal</option><option value="person">Talangan Personal</option></select></div>
            <div class="col-md-12" id="company_box"><label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label><select name="account_id" class="form-select"><option value="">- Pilih Akun -</option><?php foreach ($accounts as $acc): ?><option value="<?= (int)$acc['id']; ?>"><?= htmlspecialchars($acc['account_name']); ?> - <?= rupiah($acc['current_balance']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-12" id="operational_box" style="display:none;"><label class="form-label fw-semibold">Dana Operasional Personal</label><select name="operational_fund_id" class="form-select"><option value="">- Pilih Dana Operasional -</option><?php foreach ($operationalFunds as $fund): ?><option value="<?= (int)$fund['id']; ?>"><?= htmlspecialchars($fund['person_name']); ?> (<?= htmlspecialchars($fund['fund_code']); ?>) - Saldo <?= rupiah($fund['current_balance']); ?></option><?php endforeach; ?></select><small class="text-muted">Jika belanja melebihi saldo, selisih otomatis menjadi hutang personal.</small></div>
            <div class="col-md-12" id="person_box" style="display:none;"><label class="form-label fw-semibold">Talangan Personal</label><select name="personal_mode" id="personal_mode" class="form-select mb-2"><option value="manual">Input Nama Manual</option><option value="employee">Pilih Karyawan</option></select><select name="personal_employee_id" id="personal_employee_id" class="form-select mb-2" style="display:none;"><option value="">- Pilih Karyawan -</option><?php foreach ($employees as $emp): ?><option value="<?= (int)$emp['id']; ?>"><?= htmlspecialchars($emp['full_name']); ?><?= $emp['position'] ? ' - ' . htmlspecialchars($emp['position']) : ''; ?></option><?php endforeach; ?></select><input type="text" name="person_name" id="person_name" class="form-control" placeholder="Nama lengkap personal"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Metode Pembayaran</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bank_transfer">Transfer Bank</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Jumlah</label><input type="text" id="amount_display" class="form-control currency-input" placeholder="Rp 1.000.000" autocomplete="off" required><input type="hidden" name="amount" id="amount"></div>
            <div class="col-md-12"><label class="form-label fw-semibold">Upload Bukti Transaksi <span class="text-muted">(Optional)</span></label><input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple><small class="text-muted">Format JPG, JPEG, PNG, WEBP. Maksimal 5MB per gambar.</small></div>
            <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Transaksi</button><a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=transactions#tab-transactions'); ?>" class="btn btn-light">Batal</a></div>
        </form>
    </div></div><div class="col-lg-4"><div class="panel"><h5 class="fw-bold mb-3">Panduan</h5><ul class="text-muted small mb-0"><li><strong>Akun Perusahaan:</strong> langsung mengurangi saldo kas/bank.</li><li><strong>Dana Operasional:</strong> mengurangi saldo dana yang dipegang personal.</li><li><strong>Talangan Personal:</strong> langsung menjadi hutang perusahaan kepada personal.</li><li>Jika Dana Operasional kurang, selisih otomatis menjadi hutang personal.</li></ul></div></div></div>
</main>
<script>
function toggleFunding(){const type=document.getElementById('transaction_type').value;const funding=document.getElementById('funding_source').value;const show=type==='expense';document.getElementById('funding_section').style.display=show?'block':'none';document.getElementById('company_box').style.display=(!show||funding==='company')?'block':'none';document.getElementById('operational_box').style.display=(show&&funding==='operational_fund')?'block':'none';document.getElementById('person_box').style.display=(show&&funding==='person')?'block':'none';}
function togglePersonalMode(){const mode=document.getElementById('personal_mode').value;document.getElementById('personal_employee_id').style.display=mode==='employee'?'block':'none';document.getElementById('person_name').style.display=mode==='manual'?'block':'none';}
document.getElementById('transaction_type').addEventListener('change',toggleFunding);document.getElementById('funding_source').addEventListener('change',toggleFunding);document.getElementById('personal_mode').addEventListener('change',togglePersonalMode);toggleFunding();togglePersonalMode();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
