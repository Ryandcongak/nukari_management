<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../includes/master_data_helper.php';

$pageTitle = 'Edit Transaksi Manual - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$t) {
    redirect('pages/finance/transactions.php?error=Transaksi tidak ditemukan');
}

if (($t['source_module'] ?? 'manual') !== 'manual') {
    redirect('pages/finance/transactions.php?error=Hanya transaksi manual yang bisa diedit dari halaman ini');
}

$oldAccountId = (int)($t['account_id'] ?? 0);
$initialLinks = finance_manual_transaction_links($pdo, $id);
$hasOperationalFundLink = !empty($initialLinks['movements']);
$hasPartyAdvanceLink = false;
$hasCompanyShortfallLink = false;
foreach ($initialLinks['debts'] as $linkedDebt) {
    $hasPartyAdvanceLink = $hasPartyAdvanceLink || (($linkedDebt['category'] ?? '') === 'Talangan Operasional');
    $hasCompanyShortfallLink = $hasCompanyShortfallLink || (($linkedDebt['category'] ?? '') === 'Kekurangan Kas Kantor');
}
$hasExternalFundingLink = $hasOperationalFundLink || $hasPartyAdvanceLink;

$accounts = $pdo->query("SELECT id, account_name, account_type FROM finance_accounts WHERE status='active' ORDER BY is_default DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$categoryStmt = $pdo->prepare("SELECT id, category_name FROM transaction_categories WHERE status='active' OR id=:current ORDER BY category_name ASC");
$categoryStmt->execute([':current'=>(int)($t['category_id']??0)]);
$categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, project_name FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newProofFile = null;
    try {
        $type = in_array($_POST['type'] ?? '', ['income','expense'], true) ? $_POST['type'] : 'expense';
        $transactionName = clean_input($_POST['transaction_name'] ?? '');
        $transactionDate = $_POST['transaction_date'] ?: date('Y-m-d');
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $amount = f_money_to_number($_POST['amount'] ?? 0);
        $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash','bank_transfer','qris','other'], true) ? $_POST['payment_method'] : 'cash';
        $description = clean_input($_POST['description'] ?? '');
        $referenceCode = clean_input($_POST['reference_code'] ?? '');
        $proofFile = f_upload_file('proof_file', $t['proof_file']);
        if ($proofFile !== ($t['proof_file'] ?? null)) $newProofFile = $proofFile;
        $postingMode = finance_validate_posting_mode($_POST['posting_mode'] ?? ($t['posting_mode'] ?? 'posted'), $_POST['historical_reason'] ?? null);
        $historicalReason = $postingMode === 'historical' ? trim((string)($_POST['historical_reason'] ?? '')) : null;
        finance_validate_posting_date($pdo, $transactionDate, $postingMode);

        if ($transactionName === '') throw new Exception('Nama transaksi wajib diisi.');
        if ($amount <= 0) throw new Exception('Nominal transaksi harus lebih dari 0.');
        if ($hasExternalFundingLink) {
            if ($type !== 'expense' || $postingMode !== 'posted') throw new Exception('Transaksi Dana Operasional/talangan PIC harus tetap berupa pengeluaran berjalan. Untuk mengganti sumber atau dampak saldo, hapus lalu buat ulang transaksi.');
            $accountId = null;
        } elseif ($hasCompanyShortfallLink && ($type !== 'expense' || $postingMode !== 'posted' || !$accountId)) {
            throw new Exception('Transaksi dengan talangan kekurangan kas harus tetap berupa pengeluaran berjalan dari akun kas/bank.');
        } elseif ($postingMode === 'posted' && !$accountId) {
            throw new Exception('Pilih akun kas/bank untuk transaksi yang memengaruhi saldo.');
        }

        $pdo->beginTransaction();
        $links = finance_manual_transaction_links($pdo, $id);

        $stmt = $pdo->prepare("
            UPDATE transactions
            SET transaction_date = :date,
                transaction_name = :transaction_name,
                type = :type,
                category_id = :category_id,
                account_id = :account_id,
                project_id = :project_id,
                amount = :amount,
                posting_mode = :posting_mode,
                historical_reason = :historical_reason,
                posted_at = :posted_at,
                posted_by = :posted_by,
                description = :description,
                payment_method = :payment_method,
                proof_file = :proof_file,
                reference_code = :reference_code,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':date' => $transactionDate,
            ':transaction_name' => $transactionName,
            ':type' => $type,
            ':category_id' => $categoryId,
            ':account_id' => $accountId,
            ':project_id' => $projectId,
            ':amount' => $amount,
            ':posting_mode' => $postingMode,
            ':historical_reason' => $historicalReason,
            ':posted_at' => $postingMode === 'posted' ? date('Y-m-d H:i:s') : null,
            ':posted_by' => $postingMode === 'posted' ? ($_SESSION['user_id'] ?? null) : null,
            ':description' => $description ?: null,
            ':payment_method' => $paymentMethod,
            ':proof_file' => $proofFile,
            ':reference_code' => $referenceCode ?: null,
            ':id' => $id
        ]);

        $affectedFundIds = [];
        $affectedMovementAccountIds = [];
        foreach ($links['movements'] as $movement) {
            $affectedFundIds[] = (int)$movement['fund_id'];
            if (!empty($movement['account_id'])) $affectedMovementAccountIds[] = (int)$movement['account_id'];
            $updateMovement = $pdo->prepare("UPDATE operational_fund_movements SET movement_date=:date,amount=:amount,project_id=:project_id,description=:description,payment_method=:payment_method WHERE id=:id");
            $updateMovement->execute([
                ':date' => $transactionDate,
                ':amount' => $amount,
                ':project_id' => $projectId,
                ':description' => $transactionName . ($description ? ' - ' . $description : ''),
                ':payment_method' => $paymentMethod,
                ':id' => (int)$movement['id'],
            ]);
        }
        foreach (array_unique($affectedFundIds) as $fundId) opfund_recalculate($pdo, $fundId);
        foreach (array_unique($affectedMovementAccountIds) as $movementAccountId) f_recalculate_account($pdo, $movementAccountId);

        if ($oldAccountId) f_recalculate_account($pdo, $oldAccountId);
        if ($accountId) f_recalculate_account($pdo, $accountId);

        foreach ($links['debts'] as $linkedDebt) {
            $category = (string)($linkedDebt['category'] ?? '');
            if ($category === 'Talangan Operasional') {
                $principal = max(0, $amount - (float)($linkedDebt['interest_amount'] ?? 0));
                if ($amount + 0.009 < (float)($linkedDebt['paid_amount'] ?? 0)) throw new Exception('Nominal baru lebih kecil dari pembayaran hutang talangan yang sudah tercatat.');
                $status = (float)($linkedDebt['paid_amount'] ?? 0) <= 0 ? 'unpaid' : (((float)$linkedDebt['paid_amount'] + 0.009 >= $amount) ? 'paid' : 'partial');
                $updateDebt = $pdo->prepare("UPDATE debts SET issue_date=:date,project_id=:project_id,amount=:amount,principal_amount=:principal,payment_method=:method,reference_code=:reference,status=:status,description=:description,updated_at=NOW() WHERE id=:id");
                $updateDebt->execute([':date'=>$transactionDate,':project_id'=>$projectId,':amount'=>$amount,':principal'=>$principal,':method'=>$paymentMethod,':reference'=>$referenceCode ?: 'TRX-'.$id,':status'=>$status,':description'=>$transactionName.($description?' - '.$description:''),':id'=>(int)$linkedDebt['id']]);
            } elseif ($category === 'Kekurangan Kas Kantor') {
                $balanceStmt = $pdo->prepare("SELECT current_balance FROM finance_accounts WHERE id=:id");
                $balanceStmt->execute([':id' => $accountId]);
                $shortfall = min($amount, max(0, -(float)$balanceStmt->fetchColumn()));
                if ($shortfall <= 0.009) {
                    finance_assert_linked_debt_reversible($pdo, $linkedDebt);
                    $pdo->prepare("DELETE FROM debts WHERE id=:id")->execute([':id'=>(int)$linkedDebt['id']]);
                } else {
                    if ($shortfall + 0.009 < (float)($linkedDebt['paid_amount'] ?? 0)) throw new Exception('Kekurangan kas baru lebih kecil dari pembayaran hutang talangan yang sudah tercatat.');
                    $status = (float)($linkedDebt['paid_amount'] ?? 0) <= 0 ? 'unpaid' : 'partial';
                    $updateDebt = $pdo->prepare("UPDATE debts SET issue_date=:date,project_id=:project_id,amount=:amount,principal_amount=:principal_amount,payment_method=:method,reference_code=:reference,status=:status,description=:description,updated_at=NOW() WHERE id=:id");
                    $updateDebt->execute([':date'=>$transactionDate,':project_id'=>$projectId,':amount'=>$shortfall,':principal_amount'=>$shortfall,':method'=>$paymentMethod,':reference'=>$referenceCode ?: 'TRX-'.$id,':status'=>$status,':description'=>'Kekurangan saldo untuk '.$transactionName,':id'=>(int)$linkedDebt['id']]);
                }
            }
        }
        if (($t['posting_mode'] ?? 'posted') !== $postingMode) log_activity($pdo, 'Finance', 'UPDATE', 'Dampak saldo diubah dari ' . finance_posting_mode_label($t['posting_mode'] ?? 'posted') . ' menjadi ' . finance_posting_mode_label($postingMode) . ($historicalReason ? ' — ' . $historicalReason : ''), $id, 'transactions');

        $pdo->commit();
        if ($newProofFile) upload_delete_file($t['proof_file'] ?? null);

        redirect('pages/finance/transactions.php?message=Transaksi berhasil diperbarui');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        upload_delete_file($newProofFile);
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Edit Transaksi Manual</h1>
            <p class="text-muted mb-0"><?= f_e($t['transaction_code']); ?></p>
        </div>
        <a href="<?= base_url('pages/finance/transactions.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>
    <?php if ($hasExternalFundingLink): ?><div class="alert alert-info rounded-4">Transaksi ini terhubung ke <?= $hasOperationalFundLink ? 'Dana Operasional PIC' : 'hutang talangan PIC'; ?>. Nominal, tanggal, project, metode, dan deskripsi akan ikut disinkronkan. Sumber dana dan dampak saldo tidak dapat dialihkan lewat halaman Edit.</div><?php elseif ($hasCompanyShortfallLink): ?><div class="alert alert-info rounded-4">Transaksi ini memiliki hutang talangan kekurangan kas. Perubahan nominal akan menghitung ulang nilai hutang terkait.</div><?php endif; ?>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="type" class="form-select" required>
                    <option value="income" <?= $t['type'] === 'income' ? 'selected' : ''; ?>>Income / Pemasukan</option>
                    <option value="expense" <?= $t['type'] === 'expense' ? 'selected' : ''; ?>>Expense / Pengeluaran</option>
                </select>
            </div>
            <div class="col-md-8"><label class="form-label fw-semibold">Nama Transaksi</label><input type="text" name="transaction_name" class="form-control" value="<?=f_e($t['transaction_name'] ?: $t['description']);?>" required></div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal</label>
                <input type="date" name="transaction_date" class="form-control" value="<?= f_e($t['transaction_date']); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nominal</label>
                <input type="text" name="amount" class="form-control money-input" value="<?= number_format((float)$t['amount'], 0, ',', '.'); ?>" required>
            </div>
            <?php if (finance_user_can_use_non_posting()): ?><div class="col-md-12"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" id="postingMode" class="form-select"><option value="posted" <?= ($t['posting_mode']??'posted')==='posted'?'selected':''; ?>>Transaksi berjalan — memengaruhi saldo</option><option value="historical" <?= ($t['posting_mode']??'')==='historical'?'selected':''; ?>>Catatan historis — tidak memengaruhi saldo</option></select></div><div class="col-md-12 <?= ($t['posting_mode']??'posted')==='historical'?'':'d-none'; ?>" id="historicalReasonField"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"><?= f_e($t['historical_reason'] ?? ''); ?></textarea></div><?php else: ?><input type="hidden" name="posting_mode" value="<?= f_e($t['posting_mode'] ?? 'posted'); ?>"><?php endif; ?>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Akun Kas/Bank</label>
                <select name="account_id" class="form-select">
                    <option value="">Tidak ditentukan</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id']; ?>" <?= (int)$t['account_id'] === (int)$a['id'] ? 'selected' : ''; ?>><?= f_e($a['account_name']); ?> - <?= f_e(ucfirst($a['account_type'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <select name="category_id" class="form-select">
                    <option value="">Tanpa kategori</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id']; ?>" <?= (int)$t['category_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?= f_e($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Metode Pembayaran</label>
                <select name="payment_method" class="form-select">
                    <?= master_options($pdo,'payment_method',(string)$t['payment_method']); ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Project</label>
                <select name="project_id" class="form-select">
                    <option value="">Tidak terkait project</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id']; ?>" <?= (int)$t['project_id'] === (int)$p['id'] ? 'selected' : ''; ?>><?= f_e($p['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Kode Referensi</label>
                <input type="text" name="reference_code" class="form-control" value="<?= f_e($t['reference_code']); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Ganti Bukti</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
                <?php if (!empty($t['proof_file'])): ?><small><a target="_blank" href="<?= base_url($t['proof_file']); ?>">Lihat bukti saat ini</a></small><?php endif; ?>
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="description" rows="4" class="form-control"><?= f_e($t['description']); ?></textarea>
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</main>

<script>
document.querySelectorAll('.money-input').forEach(function(input) {
    input.addEventListener('input', function() {
        let value = this.value.replace(/\D/g, '');
        this.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    });
});
function syncPostingMode(){const field=document.getElementById('postingMode');if(!field)return;const historical=field.value==='historical';document.getElementById('historicalReasonField').classList.toggle('d-none',!historical);document.querySelector('[name="historical_reason"]').required=historical;}
if(document.getElementById('postingMode')){document.getElementById('postingMode').addEventListener('change',syncPostingMode);syncPostingMode();}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
