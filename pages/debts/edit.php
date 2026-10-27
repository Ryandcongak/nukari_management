<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Edit Hutang & Piutang - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM debts WHERE id = :id");
$stmt->execute([':id' => $id]);
$debt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$debt) {
    redirect('pages/debts/index.php?error=Data tidak ditemukan');
}
if (!in_array((string)($debt['source_module'] ?? 'manual'), ['', 'manual'], true)) {
    redirect('pages/debts/detail.php?id=' . $id . '&error=' . urlencode('Data hasil integrasi harus dikoreksi dari modul sumbernya.'));
}

$projects = $pdo->query("SELECT id, project_name FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$accounts = $pdo->query("SELECT id,account_name FROM finance_accounts WHERE status='active' ORDER BY is_default DESC,account_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newProof = null;
    try {
        $type = in_array($_POST['type'] ?? '', ['debt','receivable'], true) ? $_POST['type'] : 'debt';
        $related_type = in_array($_POST['related_type'] ?? '', ['supplier','client','employee','personal','project','other'], true) ? $_POST['related_type'] : 'other';
        $related_id = !empty($_POST['related_id']) ? (int)$_POST['related_id'] : null;
        $related_name = clean_input($_POST['related_name'] ?? '');
        $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $category = clean_input($_POST['category'] ?? '');
        $issue_date = $_POST['issue_date'] ?: null;
        $due_date = $_POST['due_date'] ?: null;
        $amount = money_to_number($_POST['amount'] ?? 0);
        $principalAmount = $amount - (float)($debt['interest_amount'] ?? 0);
        $receivingAccountId = !empty($_POST['receiving_account_id']) ? (int)$_POST['receiving_account_id'] : null;
        if ($type === 'debt') finance_validate_posting_date($pdo, $issue_date, $debt['posting_mode'] ?? 'posted');
        $payment_method = $_POST['payment_method'] ?: null;
        $description = clean_input($_POST['description'] ?? '');
        $reminder_days_before = max((int)($_POST['reminder_days_before'] ?? 7), 0);
        $proof_file = debt_upload_file('proof_file', $debt['proof_file'] ?? null);
        if ($proof_file !== ($debt['proof_file'] ?? null)) $newProof = $proof_file;

        if ($related_name === '') {
            $error = 'Nama pihak terkait wajib diisi.';
        } elseif ($amount <= 0) {
            $error = 'Nominal harus lebih dari 0.';
        } elseif ($principalAmount <= 0) {
            $error = 'Nominal total harus lebih besar dari nilai bunga yang sudah tercatat.';
        } elseif ((float)$debt['paid_amount'] > $amount) {
            $error = 'Nominal tidak boleh lebih kecil dari jumlah yang sudah dibayar.';
        } elseif ($type === 'debt' && ($debt['posting_mode'] ?? 'posted') === 'posted' && !$receivingAccountId) {
            $error = 'Pilih rekening tujuan penerimaan pinjaman.';
        } else {
            $pdo->beginTransaction();
            $oldFundingTransactionId = (int)($debt['funding_transaction_id'] ?? 0);
            $oldFundingAccountId = 0;
            if ($oldFundingTransactionId) {
                $fundingStmt = $pdo->prepare("SELECT account_id FROM transactions WHERE id=:id FOR UPDATE");
                $fundingStmt->execute([':id'=>$oldFundingTransactionId]);
                $oldFundingAccountId = (int)$fundingStmt->fetchColumn();
            }
            $stmt = $pdo->prepare("
                UPDATE debts SET
                    type=:type, related_type=:related_type, related_id=:related_id, related_name=:related_name,
                    project_id=:project_id, category=:category, issue_date=:issue_date, amount=:amount, principal_amount=:principal_amount,
                    receiving_account_id=:receiving_account_id,
                    due_date=:due_date, payment_method=:payment_method, proof_file=:proof_file,
                    reminder_days_before=:reminder_days_before, description=:description, updated_at=NOW()
                WHERE id=:id
            ");
            $stmt->execute([
                ':type'=>$type, ':related_type'=>$related_type, ':related_id'=>$related_id,
                ':related_name'=>$related_name, ':project_id'=>$project_id, ':category'=>$category ?: null,
                ':issue_date'=>$issue_date, ':amount'=>$amount, ':principal_amount'=>$principalAmount, ':receiving_account_id'=>$type === 'debt' ? $receivingAccountId : null, ':due_date'=>$due_date,
                ':payment_method'=>$payment_method, ':proof_file'=>$proof_file,
                ':reminder_days_before'=>$reminder_days_before, ':description'=>$description ?: null, ':id'=>$id
            ]);

            $fundingTransactionId = $oldFundingTransactionId;
            if ($type === 'debt' && ($debt['posting_mode'] ?? 'posted') === 'posted') {
                if ($fundingTransactionId) {
                    $fundingStmt = $pdo->prepare("UPDATE transactions SET transaction_date=:date,type='income',account_id=:account,project_id=:project,amount=:amount,description=:description,payment_method=:method,proof_file=:proof,reference_code=:reference,updated_at=NOW() WHERE id=:id");
                    $fundingStmt->execute([':date'=>$issue_date,':account'=>$receivingAccountId,':project'=>$project_id,':amount'=>$principalAmount,':description'=>'Penerimaan pinjaman dari '.$related_name,':method'=>$payment_method ?: 'bank_transfer',':proof'=>$proof_file,':reference'=>$debt['debt_code'],':id'=>$fundingTransactionId]);
                } else {
                    $fundingStmt = $pdo->prepare("INSERT INTO transactions(transaction_code,transaction_date,type,account_id,project_id,amount,description,payment_method,proof_file,source_module,source_table,source_id,reference_code,created_by,created_at,updated_at) VALUES(:code,:date,'income',:account,:project,:amount,:description,:method,:proof,'other','debts',:source,:reference,:user,NOW(),NOW())");
                    $fundingStmt->execute([':code'=>finance_generate_transaction_code($pdo,'income'),':date'=>$issue_date,':account'=>$receivingAccountId,':project'=>$project_id,':amount'=>$principalAmount,':description'=>'Penerimaan pinjaman dari '.$related_name,':method'=>$payment_method ?: 'bank_transfer',':proof'=>$proof_file,':source'=>$id,':reference'=>$debt['debt_code'],':user'=>$_SESSION['user_id']??null]);
                    $fundingTransactionId = (int)$pdo->lastInsertId();
                }
            } elseif ($fundingTransactionId) {
                $pdo->prepare("DELETE FROM transactions WHERE id=:id")->execute([':id'=>$fundingTransactionId]);
                $fundingTransactionId = 0;
            }
            $pdo->prepare("UPDATE debts SET funding_transaction_id=:transaction_id WHERE id=:id")->execute([':transaction_id'=>$fundingTransactionId ?: null,':id'=>$id]);
            update_debt_status($pdo, $id);
            if ($oldFundingAccountId) finance_recalculate_account_balance($pdo, $oldFundingAccountId);
            if ($type === 'debt' && $receivingAccountId) finance_recalculate_account_balance($pdo, $receivingAccountId);
            $pdo->commit();
            if ($newProof) upload_delete_file($debt['proof_file'] ?? null);
            redirect('pages/debts/index.php?message=Data berhasil diperbarui');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        upload_delete_file($newProof);
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h1 class="fw-bold mb-1">Edit Hutang & Piutang</h1><p class="text-muted mb-0"><?= htmlspecialchars($debt['debt_code']); ?></p></div>
        <a href="<?= base_url('pages/debts/index.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-4"><label class="form-label fw-semibold">Jenis</label><select name="type" class="form-select"><option value="debt" <?= $debt['type']==='debt'?'selected':''; ?>>Hutang</option><option value="receivable" <?= $debt['type']==='receivable'?'selected':''; ?>>Piutang</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Tipe Pihak</label><select name="related_type" class="form-select"><?php foreach(['supplier','client','employee','personal','project','other'] as $rt): ?><option value="<?= $rt; ?>" <?= ($debt['related_type'] ?? '')===$rt?'selected':''; ?>><?= ucfirst($rt); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">ID Pihak Terkait</label><input type="number" name="related_id" class="form-control" value="<?= htmlspecialchars($debt['related_id'] ?? ''); ?>"></div>
            <div class="col-md-8"><label class="form-label fw-semibold">Nama Pihak Terkait</label><input type="text" name="related_name" class="form-control" required value="<?= htmlspecialchars($debt['related_name']); ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Kategori</label><input type="text" name="category" class="form-control" value="<?= htmlspecialchars($debt['category'] ?? ''); ?>"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Project</label><select name="project_id" class="form-select"><option value="">Tidak terkait project</option><?php foreach($projects as $p): ?><option value="<?= $p['id']; ?>" <?= (int)$debt['project_id']===(int)$p['id']?'selected':''; ?>><?= htmlspecialchars($p['project_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Nominal</label><input type="text" name="amount" class="form-control money-input" required value="<?= number_format((float)$debt['amount'],0,',','.'); ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Dibuat</label><input type="date" name="issue_date" class="form-control" value="<?= htmlspecialchars($debt['issue_date'] ?? ''); ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Jatuh Tempo</label><input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars($debt['due_date'] ?? ''); ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Reminder H-</label><input type="number" name="reminder_days_before" class="form-control" value="<?= htmlspecialchars($debt['reminder_days_before'] ?? 7); ?>" min="0"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Metode Pembayaran Rencana</label><select name="payment_method" class="form-select"><option value="">Belum ditentukan</option><?php foreach(['cash'=>'Cash','bank_transfer'=>'Bank Transfer','qris'=>'QRIS','other'=>'Lainnya'] as $k=>$v): ?><option value="<?= $k; ?>" <?= ($debt['payment_method'] ?? '')===$k?'selected':''; ?>><?= $v; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Rekening Penerimaan Pinjaman</label><select name="receiving_account_id" class="form-select"><option value="">Tidak ada / bukan hutang berjalan</option><?php foreach($accounts as $account): ?><option value="<?= (int)$account['id']; ?>" <?= (int)($debt['receiving_account_id']??0)===(int)$account['id']?'selected':''; ?>><?= htmlspecialchars($account['account_name']); ?></option><?php endforeach; ?></select><div class="form-text">Wajib untuk jenis Hutang yang memengaruhi saldo.</div></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Ganti Bukti / Dokumen</label><input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf"><?php if(!empty($debt['proof_file'])): ?><small><a target="_blank" href="<?= base_url($debt['proof_file']); ?>">Lihat file saat ini</a></small><?php endif; ?></div>
            <div class="col-md-12"><label class="form-label fw-semibold">Keterangan</label><textarea name="description" rows="4" class="form-control"><?= htmlspecialchars($debt['description'] ?? ''); ?></textarea></div>
            <div class="col-12 text-end"><button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Perubahan</button></div>
        </form>
    </div>
</main>
<script>
document.querySelectorAll('.money-input').forEach(function(input){input.addEventListener('input',function(){let value=this.value.replace(/\D/g,'');this.value=value.replace(/\B(?=(\d{3})+(?!\d))/g,'.');});});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
