<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Tambah Hutang & Piutang - Nukari';
$error = '';

$projects = $pdo->query("SELECT id, project_name FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, client_name FROM clients WHERE status='active' ORDER BY client_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$employees = $pdo->query("SELECT id, full_name FROM employees WHERE employment_status='active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$accounts = $pdo->query("SELECT id,account_name,current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC,account_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proof_file = null;
    try {
        $type = in_array($_POST['type'] ?? '', ['debt','receivable'], true) ? $_POST['type'] : 'debt';
        $related_type = in_array($_POST['related_type'] ?? '', ['supplier','client','employee','personal','project','other'], true) ? $_POST['related_type'] : 'other';
        $related_id = !empty($_POST['related_id']) ? (int)$_POST['related_id'] : null;
        $related_name = clean_input($_POST['related_name'] ?? '');
        $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $category = clean_input($_POST['category'] ?? '');
        $issue_date = $_POST['issue_date'] ?: date('Y-m-d');
        $due_date = $_POST['due_date'] ?: null;
        $amount = money_to_number($_POST['amount'] ?? 0);
        $principalAmount = $amount;
        $interestPercentage = max(0, (float)($_POST['interest_percentage'] ?? 0));
        $interestAmount = money_to_number($_POST['interest_amount'] ?? 0);
        if ($interestAmount <= 0 && $interestPercentage > 0) $interestAmount = round($principalAmount * $interestPercentage / 100, 2);
        $amount = $principalAmount + $interestAmount;
        $receivingAccountId = !empty($_POST['receiving_account_id']) ? (int)$_POST['receiving_account_id'] : null;
        $payment_method = $_POST['payment_method'] ?: null;
        $description = clean_input($_POST['description'] ?? '');
        $reminder_days_before = max((int)($_POST['reminder_days_before'] ?? 7), 0);
        $proof_file = debt_upload_file('proof_file');
        $postingMode = finance_validate_posting_mode($_POST['posting_mode'] ?? 'posted', $_POST['historical_reason'] ?? null, true);
        if ($postingMode === 'historical') throw new Exception('Hutang/piutang yang masih terbuka gunakan mode Saldo awal masih terbuka, bukan Historis selesai.');
        $historicalReason = $postingMode === 'opening_outstanding' ? trim((string)($_POST['historical_reason'] ?? '')) : null;
        finance_validate_posting_date($pdo, $issue_date, $postingMode);

        if ($related_name === '') {
            throw new Exception('Nama pihak terkait wajib diisi.');
        } elseif ($amount <= 0) {
            throw new Exception('Nominal harus lebih dari 0.');
        } else {
            $code = debt_code_generate($pdo, $type);
            if ($postingMode === 'posted' && $type === 'debt' && !$receivingAccountId) throw new Exception('Pilih rekening tujuan penerimaan dana pinjaman.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO debts
                (debt_code, type, related_type, related_id, related_name, project_id, category, issue_date, amount, principal_amount, interest_percentage, interest_amount, receiving_account_id, paid_amount, due_date, payment_method, proof_file, reminder_days_before, status, posting_mode, historical_reason, description, created_by, created_at, updated_at)
                VALUES
                (:debt_code, :type, :related_type, :related_id, :related_name, :project_id, :category, :issue_date, :amount, :principal, :interest_percentage, :interest_amount, :receiving_account, 0, :due_date, :payment_method, :proof_file, :reminder_days_before, 'unpaid', :posting_mode, :historical_reason, :description, :created_by, NOW(), NOW())
            ");
            $stmt->execute([
                ':debt_code' => $code,
                ':type' => $type,
                ':related_type' => $related_type,
                ':related_id' => $related_id,
                ':related_name' => $related_name,
                ':project_id' => $project_id,
                ':category' => $category ?: null,
                ':issue_date' => $issue_date,
                ':amount' => $amount,
                ':principal' => $principalAmount,
                ':interest_percentage' => $interestPercentage,
                ':interest_amount' => $interestAmount,
                ':receiving_account' => $receivingAccountId,
                ':due_date' => $due_date,
                ':payment_method' => $payment_method,
                ':proof_file' => $proof_file,
                ':reminder_days_before' => $reminder_days_before,
                ':posting_mode' => $postingMode,
                ':historical_reason' => $historicalReason,
                ':description' => $description ?: null,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            $debtId=(int)$pdo->lastInsertId();
            if($type==='debt' && $postingMode==='posted'){
                $transactionCode=finance_generate_transaction_code($pdo,'income');
                $pdo->prepare("INSERT INTO transactions(transaction_code,transaction_date,type,account_id,project_id,amount,description,payment_method,proof_file,source_module,source_table,source_id,reference_code,created_by,created_at,updated_at) VALUES(:code,:date,'income',:account,:project,:amount,:description,:method,:proof,'other','debts',:source,:reference,:user,NOW(),NOW())")
                    ->execute([':code'=>$transactionCode,':date'=>$issue_date,':account'=>$receivingAccountId,':project'=>$project_id,':amount'=>$principalAmount,':description'=>'Penerimaan pinjaman dari '.$related_name,':method'=>$payment_method?:'bank_transfer',':proof'=>$proof_file,':source'=>$debtId,':reference'=>$code,':user'=>$_SESSION['user_id']??null]);
                $fundingTx=(int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE debts SET funding_transaction_id=:tx WHERE id=:id")->execute([':tx'=>$fundingTx,':id'=>$debtId]);
                finance_recalculate_account_balance($pdo,$receivingAccountId);
            }
            log_activity($pdo, 'Debt', 'CREATE', finance_posting_mode_label($postingMode) . ($historicalReason ? ' — ' . $historicalReason : ''), $debtId, 'debts');
            $pdo->commit();
            redirect('pages/debts/index.php?message=Data hutang/piutang berhasil ditambahkan');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        upload_delete_file($proof_file);
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h1 class="fw-bold mb-1">Tambah Hutang & Piutang</h1><p class="text-muted mb-0">Catat kewajiban perusahaan atau tagihan yang harus diterima.</p></div>
        <a href="<?= base_url('pages/debts/index.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="type" class="form-select" required>
                    <option value="debt">Hutang</option>
                    <option value="receivable">Piutang</option>
                </select>
            </div>
            <?php if (finance_user_can_use_non_posting()): ?><div class="col-md-8"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" id="postingMode" class="form-select"><option value="posted">Hutang/piutang berjalan</option><option value="opening_outstanding">Saldo awal masih terbuka — penerimaan/pembayaran awal tidak diposting ulang</option></select></div><div class="col-md-12 d-none" id="historicalReasonField"><label class="form-label fw-semibold">Alasan / Referensi Saldo Awal</label><textarea name="historical_reason" class="form-control" rows="2"></textarea></div><?php endif; ?>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tipe Pihak</label>
                <select name="related_type" class="form-select">
                    <option value="supplier">Supplier / Vendor</option>
                    <option value="client">Client</option>
                    <option value="employee">Karyawan</option>
                    <option value="personal">Personal</option>
                    <option value="project">Project</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">ID Pihak Terkait</label>
                <input type="number" name="related_id" class="form-control" placeholder="Opsional">
                <small class="text-muted">Boleh kosong jika pihak tidak ada di master data.</small>
            </div>

            <div class="col-md-8">
                <label class="form-label fw-semibold">Nama Pihak Terkait</label>
                <input type="text" name="related_name" class="form-control" required placeholder="Contoh: PT ABC, Pak Made, Ryan, Client X">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <input type="text" name="category" class="form-control" placeholder="Material, Operasional, Project, dll">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Project</label>
                <select name="project_id" class="form-select">
                    <option value="">Tidak terkait project</option>
                    <?php foreach ($projects as $p): ?><option value="<?= $p['id']; ?>"><?= htmlspecialchars($p['project_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Pokok Pinjaman / Tagihan</label>
                <input type="text" name="amount" class="form-control money-input" required placeholder="Contoh: 1.500.000">
            </div>
            <div class="col-md-4"><label class="form-label fw-semibold">Bunga (%)</label><input type="number" name="interest_percentage" step="0.001" min="0" class="form-control" value="0"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Bunga Nominal (opsional)</label><input type="text" name="interest_amount" class="form-control money-input" placeholder="Otomatis dari persen"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Dana Pinjaman Masuk Ke</label><select name="receiving_account_id" class="form-select"><option value="">Pilih untuk jenis Hutang</option><?php foreach($accounts as $a): ?><option value="<?= (int)$a['id']; ?>"><?= htmlspecialchars($a['account_name']); ?> · <?= rupiah($a['current_balance']); ?></option><?php endforeach; ?></select><small class="text-muted">Wajib jika pihak luar memberi pinjaman ke perusahaan.</small></div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Dibuat</label>
                <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jatuh Tempo</label>
                <input type="date" name="due_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Reminder H-</label>
                <input type="number" name="reminder_days_before" class="form-control" value="7" min="0">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Metode Pembayaran Rencana</label>
                <select name="payment_method" class="form-select">
                    <option value="">Belum ditentukan</option>
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="qris">QRIS</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Bukti / Dokumen</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>

            <div class="col-md-12">
                <label class="form-label fw-semibold">Keterangan</label>
                <textarea name="description" rows="4" class="form-control" placeholder="Catatan tambahan..."></textarea>
            </div>

            <div class="col-12 text-end">
                <button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </form>
    </div>

    <div class="panel mt-4">
        <div class="fw-bold mb-2">Referensi cepat ID pihak terkait</div>
        <div class="row g-3 small">
            <div class="col-md-4"><strong>Client:</strong><br><?php foreach(array_slice($clients,0,8) as $c): ?><?= $c['id']; ?> - <?= htmlspecialchars($c['client_name']); ?><br><?php endforeach; ?></div>
            <div class="col-md-4"><strong>Supplier:</strong><br><?php foreach(array_slice($suppliers,0,8) as $s): ?><?= $s['id']; ?> - <?= htmlspecialchars($s['supplier_name']); ?><br><?php endforeach; ?></div>
            <div class="col-md-4"><strong>Karyawan:</strong><br><?php foreach(array_slice($employees,0,8) as $e): ?><?= $e['id']; ?> - <?= htmlspecialchars($e['full_name']); ?><br><?php endforeach; ?></div>
        </div>
    </div>
</main>
<script>
document.querySelectorAll('.money-input').forEach(function(input){
    input.addEventListener('input', function(){
        let value = this.value.replace(/\D/g, '');
        this.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    });
});
function syncPostingMode(){const field=document.getElementById('postingMode');if(!field)return;const opening=field.value==='opening_outstanding';document.getElementById('historicalReasonField').classList.toggle('d-none',!opening);document.querySelector('[name="historical_reason"]').required=opening;}
if(document.getElementById('postingMode')){document.getElementById('postingMode').addEventListener('change',syncPostingMode);syncPostingMode();}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
