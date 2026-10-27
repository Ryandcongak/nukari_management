<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Pembayaran Hutang & Piutang - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT d.*, p.project_name
    FROM debts d
    LEFT JOIN projects p ON p.id = d.project_id
    WHERE d.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$debt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$debt) {
    redirect('pages/debts/index.php?error=Data tidak ditemukan');
}

finance_update_debt_status($pdo, $id);

$stmt = $pdo->prepare("SELECT * FROM debts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$debt = array_merge($debt, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

if ($debt['status'] === 'paid') {
    redirect('pages/debts/detail.php?id=' . $id . '&error=Data sudah lunas');
}

if (debt_is_project_term_receivable($debt)) {
    redirect('pages/debts/detail.php?id=' . $id . '&error=Pembayaran piutang termin harus dilakukan dari Detail Project agar tidak terjadi pembayaran ganda.');
}

$remaining = max((float)$debt['amount'] - (float)$debt['paid_amount'], 0);
$accounts = $pdo->query("SELECT id, account_name, account_type, current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $proof_file = null;
    try {
        $payment_date = $_POST['payment_date'] ?: date('Y-m-d');
        $amount = debt_money_to_number($_POST['amount'] ?? 0);
        $method = in_array($_POST['payment_method'] ?? '', ['cash', 'bank_transfer', 'qris', 'other'], true) ? $_POST['payment_method'] : 'cash';
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $notes = clean_input($_POST['notes'] ?? '');
        $proof_file = debt_upload_file('proof_file');
        $postingMode = finance_validate_posting_mode($_POST['posting_mode'] ?? 'posted', $_POST['historical_reason'] ?? null);
        $historicalReason = $postingMode === 'historical' ? trim((string)($_POST['historical_reason'] ?? '')) : null;

        if ($amount <= 0) {
            throw new Exception('Nominal pembayaran harus lebih dari 0.');
        } elseif (!$accountId) {
            throw new Exception('Pilih akun kas/bank perusahaan yang digunakan untuk pembayaran.');
        } elseif ($amount > $remaining) {
            throw new Exception('Nominal pembayaran tidak boleh melebihi sisa pembayaran.');
        } else {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO debt_payments
                (debt_id, payment_date, amount, posting_mode, historical_reason, payment_method, account_id, transaction_id, proof_file, notes, created_by, created_at)
                VALUES
                (:debt_id, :payment_date, :amount, :posting_mode, :historical_reason, :payment_method, :account_id, NULL, :proof_file, :notes, :created_by, NOW())
            ");
            $stmt->execute([
                ':debt_id' => $id,
                ':payment_date' => $payment_date,
                ':amount' => $amount,
                ':posting_mode' => $postingMode,
                ':historical_reason' => $historicalReason,
                ':payment_method' => $method,
                ':account_id' => $accountId,
                ':proof_file' => $proof_file,
                ':notes' => $notes ?: null,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);

            $paymentId = (int)$pdo->lastInsertId();

            finance_create_transaction_from_debt_payment($pdo, $paymentId, $accountId);
            finance_update_debt_status($pdo, $id);
            log_activity($pdo, 'Debt Payment', 'CREATE', finance_posting_mode_label($postingMode) . ($historicalReason ? ' — ' . $historicalReason : ''), $paymentId, 'debt_payments');

            /*
             * Jika data berasal dari termin project, update status termin juga.
             * Ini menjaga agar Project dan Hutang/Piutang tetap sinkron.
             */
            if (($debt['source_module'] ?? '') === 'project_term' && !empty($debt['source_id'])) {
                $stmt = $pdo->prepare("SELECT amount, paid_amount FROM debts WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $freshDebt = $stmt->fetch(PDO::FETCH_ASSOC);

                $termStatus = 'unpaid';
                $paidDate = null;

                if ((float)$freshDebt['paid_amount'] >= (float)$freshDebt['amount']) {
                    $termStatus = 'paid';
                    $paidDate = $payment_date;
                } elseif ((float)$freshDebt['paid_amount'] > 0) {
                    $termStatus = 'partial';
                }

                $stmt = $pdo->prepare("
                    UPDATE project_payment_terms
                    SET status = :status,
                        paid_date = :paid_date,
                        updated_at = NOW()
                    WHERE id = :term_id
                ");
                $stmt->execute([
                    ':status' => $termStatus,
                    ':paid_date' => $paidDate,
                    ':term_id' => (int)$debt['source_id']
                ]);
            }

            $pdo->commit();

            redirect('pages/debts/detail.php?id=' . $id . '&message=Pembayaran berhasil disimpan dan transaksi keuangan otomatis dibuat');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        upload_delete_file($proof_file);
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Pembayaran Hutang & Piutang</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($debt['debt_code'] . ' - ' . $debt['related_name']); ?></p>
        </div>
        <a href="<?= base_url('pages/debts/detail.php?id=' . $id); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Jenis</div>
                <div class="mt-2"><?= debt_type_badge_html($debt['type']); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Nominal</div>
                <div class="fs-4 fw-bold"><?= rupiah($debt['amount']); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Terbayar</div>
                <div class="fs-4 fw-bold text-success"><?= rupiah($debt['paid_amount']); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Sisa</div>
                <div class="fs-4 fw-bold text-danger"><?= rupiah($remaining); ?></div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="fw-bold">Sumber Data</div>
                <div class="text-muted small">
                    <?= debt_source_badge($debt['source_module']); ?>
                    <?= htmlspecialchars($debt['source_table'] ?: '-'); ?>
                    <?= $debt['source_id'] ? '#' . (int)$debt['source_id'] : ''; ?>
                </div>
            </div>
            <div class="text-end">
                <div class="fw-bold"><?= htmlspecialchars($debt['project_name'] ?: $debt['related_name']); ?></div>
                <div class="text-muted small"><?= htmlspecialchars($debt['reference_code'] ?: '-'); ?></div>
            </div>
        </div>
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
            <?php if (finance_user_can_use_non_posting()): ?><div class="col-md-12"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" id="postingMode" class="form-select"><option value="posted">Pembayaran berjalan — memengaruhi saldo</option><option value="historical">Pembayaran historis — hanya memperbarui catatan hutang/piutang</option></select></div><div class="col-md-12 d-none" id="historicalReasonField"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"></textarea></div><?php endif; ?>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label>
                <select name="account_id" class="form-select" required>
                    <option value="">Pilih akun yang digunakan</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id']; ?>">
                            <?= htmlspecialchars($a['account_name']); ?> - <?= htmlspecialchars(ucfirst($a['account_type'])); ?>
                            (Saldo: <?= rupiah($a['current_balance']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Untuk piutang, saldo akun bertambah. Untuk hutang/talangan, saldo akun berkurang.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Bukti Pembayaran</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea name="notes" rows="4" class="form-control" placeholder="Catatan pembayaran..."></textarea>
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-nukari">
                    <i class="bi bi-save me-1"></i> Simpan Pembayaran
                </button>
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
