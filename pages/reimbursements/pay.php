<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Bayar Dana Talangan - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM reimbursements WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    redirect('pages/reimbursements/index.php?error=Data tidak ditemukan');
}

if (!in_array($r['status'], ['approved','partial'], true)) {
    redirect('pages/reimbursements/detail.php?id=' . $id . '&error=Hanya dana talangan approved/partial yang bisa dibayar');
}

if (empty($r['debt_id'])) {
    try {
        $pdo->beginTransaction();
        finance_create_debt_from_reimbursement($pdo, $id, $_SESSION['user_id'] ?? null);
        $pdo->commit();
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$remaining = max((float)$r['amount'] - (float)$r['paid_amount'], 0);
$accounts = $pdo->query("SELECT id, account_name, account_type, current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $paymentDate = $_POST['payment_date'] ?: date('Y-m-d');
        $amount = rmb_money_to_number($_POST['amount'] ?? 0);
        $method = in_array($_POST['payment_method'] ?? '', ['cash','bank_transfer','qris','other'], true) ? $_POST['payment_method'] : 'cash';
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $notes = clean_input($_POST['notes'] ?? '');
        $proofFile = rmb_upload_file('proof_file');

        if ($amount <= 0) throw new Exception('Nominal pembayaran harus lebih dari 0.');
        if (!$accountId) throw new Exception('Pilih akun kas/bank perusahaan yang digunakan untuk pembayaran.');
        if ($amount > $remaining) throw new Exception('Nominal pembayaran tidak boleh melebihi sisa dana talangan.');

        $pdo->beginTransaction();

        $debtId = $r['debt_id'] ?: finance_create_debt_from_reimbursement($pdo, $id, $_SESSION['user_id'] ?? null);

        $stmt = $pdo->prepare("
            INSERT INTO debt_payments
            (debt_id, payment_date, amount, payment_method, account_id, transaction_id, proof_file, notes, created_by, created_at)
            VALUES
            (:debt_id, :payment_date, :amount, :method, :account_id, NULL, :proof_file, :notes, :created_by, NOW())
        ");
        $stmt->execute([
            ':debt_id' => $debtId,
            ':payment_date' => $paymentDate,
            ':amount' => $amount,
            ':method' => $method,
            ':account_id' => $accountId,
            ':proof_file' => $proofFile,
            ':notes' => $notes ?: null,
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
        $debtPaymentId = (int)$pdo->lastInsertId();

        $transactionId = finance_create_transaction_from_debt_payment($pdo, $debtPaymentId, $accountId);
        finance_update_debt_status($pdo, $debtId);

        $stmt = $pdo->prepare("
            INSERT INTO reimbursement_payments
            (reimbursement_id, debt_payment_id, transaction_id, payment_date, amount, payment_method, proof_file, notes, created_by, created_at)
            VALUES
            (:reimbursement_id, :debt_payment_id, :transaction_id, :payment_date, :amount, :payment_method, :proof_file, :notes, :created_by, NOW())
        ");
        $stmt->execute([
            ':reimbursement_id' => $id,
            ':debt_payment_id' => $debtPaymentId,
            ':transaction_id' => $transactionId,
            ':payment_date' => $paymentDate,
            ':amount' => $amount,
            ':payment_method' => $method,
            ':proof_file' => $proofFile,
            ':notes' => $notes ?: null,
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);

        finance_sync_reimbursement_from_debt($pdo, $id);

        $pdo->commit();

        redirect('pages/reimbursements/detail.php?id=' . $id . '&message=Pembayaran dana talangan berhasil disimpan');
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
        <div>
            <h1 class="fw-bold mb-1">Bayar Dana Talangan</h1>
            <p class="text-muted mb-0"><?= rmb_e($r['reimbursement_code'] . ' - ' . $r['requester_name']); ?></p>
        </div>
        <a href="<?= base_url('pages/reimbursements/detail.php?id=' . $id); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= rmb_e($error); ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="panel h-100"><div class="text-muted small">Nominal</div><div class="fs-4 fw-bold"><?= rupiah($r['amount']); ?></div></div></div>
        <div class="col-md-4"><div class="panel h-100"><div class="text-muted small">Terbayar</div><div class="fs-4 fw-bold text-success"><?= rupiah($r['paid_amount']); ?></div></div></div>
        <div class="col-md-4"><div class="panel h-100"><div class="text-muted small">Sisa</div><div class="fs-4 fw-bold text-danger"><?= rupiah($remaining); ?></div></div></div>
    </div>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Pembayaran</label>
                <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nominal Pembayaran</label>
                <input type="text" name="amount" class="form-control money-input" value="<?= number_format($remaining, 0, ',', '.'); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Metode</label>
                <select name="payment_method" class="form-select">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="qris">QRIS</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label>
                <select name="account_id" class="form-select" required>
                    <option value="">Pilih akun yang digunakan</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id']; ?>">
                            <?= rmb_e($a['account_name']); ?> - <?= rmb_e(ucfirst($a['account_type'])); ?>
                            (Saldo: <?= rupiah($a['current_balance']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Pembayaran talangan akan mengurangi saldo akun ini.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Bukti Pembayaran</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea name="notes" rows="4" class="form-control"></textarea>
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Pembayaran</button>
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
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
