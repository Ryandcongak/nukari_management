<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';

function parse_amount_id($value)
{
    $digitsOnly = preg_replace('/[^0-9]/', '', trim((string) $value));
    return $digitsOnly === '' ? 0 : (float) $digitsOnly;
}


$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT t.*, p.project_name, p.contract_value
    FROM project_payment_terms t
    JOIN projects p ON t.project_id = p.id
    WHERE t.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$term = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$term) {
    redirect('pages/projects/index.php');
}

$pageTitle = 'Edit Termin Project - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $term_name = clean_input($_POST['term_name'] ?? '');
    $percentage = $_POST['percentage'] !== '' ? $_POST['percentage'] : null;
    $amount = parse_amount_id($_POST['amount'] ?? 0);
    $due_date = $_POST['due_date'] ?? '';
    $notes = clean_input($_POST['notes'] ?? '');
    $posting_mode = finance_normalize_posting_mode($_POST['posting_mode'] ?? ($term['posting_mode'] ?? 'posted'), true);
    $historical_reason = $posting_mode === 'posted' ? null : trim((string)($_POST['historical_reason'] ?? ($term['historical_reason'] ?? '')));
    $historical_paid_date = $_POST['historical_paid_date'] ?? ($term['paid_date'] ?? '');
    $historical_received_amount = parse_amount_id($_POST['historical_received_amount'] ?? 0);

    if ($term_name === '') {
        $error = 'Nama termin wajib diisi.';
    } elseif ($amount <= 0) {
        $error = 'Jumlah termin harus lebih dari 0.';
    } elseif ($due_date === '') {
        $error = 'Tanggal jatuh tempo wajib diisi.';
    } elseif ($posting_mode === 'historical' && $historical_paid_date === '') {
        $error = 'Tanggal pembayaran historis wajib diisi.';
    } elseif ($posting_mode === 'historical' && $historical_received_amount <= 0) {
        $error = 'Nominal bersih historis wajib lebih dari nol.';
    } elseif ($posting_mode === 'historical' && $historical_received_amount > $amount) {
        $error = 'Nominal bersih historis tidak boleh melebihi nilai termin.';
    } else {
        try {
            $posting_mode = finance_validate_posting_mode($posting_mode, $historical_reason, true);
            $oldPostingMode = $term['posting_mode'] ?? 'posted';
            if ($term['status'] === 'paid' && $posting_mode !== $oldPostingMode) {
                throw new Exception('Mode termin yang sudah dibayar tidak dapat diubah. Buat koreksi melalui transaksi terkait agar jejak audit tetap utuh.');
            }
            $newStatus = $posting_mode === 'historical' ? 'paid' : ($posting_mode === 'opening_outstanding' ? 'unpaid' : $term['status']);
            $existingReceivedAmount = (float)($term['received_amount'] ?? 0);
            $newReceivedAmount = $posting_mode === 'historical'
                ? $historical_received_amount
                : ($newStatus === 'paid' ? ($existingReceivedAmount > 0 ? min($amount, $existingReceivedAmount) : $amount) : 0);
            $newTaxAmount = $posting_mode === 'historical' ? max(0, $amount - $newReceivedAmount) : ($newStatus === 'paid' ? max(0, $amount - $newReceivedAmount) : 0);
            $newTaxPercentage = $amount > 0 ? round($newTaxAmount / $amount * 100, 2) : 0;
            $newPaidDate = $posting_mode === 'historical' ? $historical_paid_date : ($newStatus === 'paid' ? $term['paid_date'] : null);
            $pdo->beginTransaction();

            $update = $pdo->prepare("
                UPDATE project_payment_terms SET
                    term_name = :term_name,
                    percentage = :percentage,
                    amount = :amount,
                    due_date = :due_date,
                    received_amount = :received_amount,
                    tax_amount = :tax_amount,
                    tax_percentage = :tax_percentage,
                    paid_date = :paid_date,
                    status = :status,
                    posting_mode = :posting_mode,
                    historical_reason = :historical_reason,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $update->execute([
                ':term_name' => $term_name,
                ':percentage' => $percentage,
                ':amount' => $amount,
                ':due_date' => $due_date,
                ':received_amount' => $newReceivedAmount,
                ':tax_amount' => $newTaxAmount,
                ':tax_percentage' => $newTaxPercentage,
                ':paid_date' => $newPaidDate,
                ':status' => $newStatus,
                ':posting_mode' => $posting_mode,
                ':historical_reason' => $historical_reason,
                ':notes' => $notes ?: null,
                ':id' => $id
            ]);

            if ($newStatus === 'paid') {
                $projectTransactionId = !empty($term['transaction_id']) ? (int)$term['transaction_id'] : 0;
                if ($projectTransactionId > 0) {
                    $updateTrx = $pdo->prepare("UPDATE project_transactions SET transaction_date=:transaction_date,description=:description,amount=:amount,posting_mode=:posting_mode,historical_reason=:historical_reason WHERE id=:id AND project_id=:project_id LIMIT 1");
                    $updateTrx->execute([':transaction_date'=>$newPaidDate ?: date('Y-m-d'),':description'=>'Pembayaran '.$term_name,':amount'=>$newReceivedAmount,':posting_mode'=>$posting_mode === 'historical' ? 'historical' : 'posted',':historical_reason'=>$posting_mode === 'historical' ? $historical_reason : null,':id'=>$projectTransactionId,':project_id'=>$term['project_id']]);
                } else {
                    $insertTrx = $pdo->prepare("INSERT INTO project_transactions(project_id,transaction_date,transaction_type,funding_source,account_id,payment_method,category,description,amount,posting_mode,historical_reason,source_module,source_id) VALUES(:project_id,:transaction_date,'income','company',NULL,'other','Termin Pembayaran',:description,:amount,:posting_mode,:historical_reason,'term',:source_id)");
                    $insertTrx->execute([':project_id'=>$term['project_id'],':transaction_date'=>$newPaidDate ?: date('Y-m-d'),':description'=>'Pembayaran '.$term_name,':amount'=>$newReceivedAmount,':posting_mode'=>$posting_mode === 'historical' ? 'historical' : 'posted',':historical_reason'=>$posting_mode === 'historical' ? $historical_reason : null,':source_id'=>(int)$id]);
                    $projectTransactionId = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE project_payment_terms SET transaction_id=:transaction_id WHERE id=:id")->execute([':transaction_id'=>$projectTransactionId,':id'=>(int)$id]);
                }
            }

            // Integrasi finance: perubahan termin otomatis memperbarui Piutang.
            finance_sync_project_term_receivable($pdo, (int) $id, $_SESSION['user_id'] ?? null);

            if ($newStatus === 'paid') {
                // Jika termin sudah paid, nominal pembayaran piutang ikut disesuaikan.
                $financeStmt = $pdo->prepare("SELECT account_id, payment_method, proof_file FROM transactions WHERE source_module='project' AND source_table='project_payment_terms' AND source_id=:id LIMIT 1");
                $financeStmt->execute([':id' => (int)$id]);
                $financeRow = $financeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                finance_mark_project_term_paid($pdo, (int)$id, $newPaidDate ?: date('Y-m-d'), !empty($financeRow['account_id']) ? (int)$financeRow['account_id'] : null, $financeRow['payment_method'] ?? 'cash', $financeRow['proof_file'] ?? null, $_SESSION['user_id'] ?? null);
            }

            log_activity($pdo, 'Project Termin', 'UPDATE', finance_posting_mode_label($posting_mode) . ($historical_reason ? ' — ' . $historical_reason : ''), (int)$id, 'project_payment_terms');

            $pdo->commit();

            redirect('pages/projects/terms.php?project_id=' . $term['project_id']);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Gagal mengupdate termin: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Edit Termin</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($term['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/terms.php?project_id=' . $term['project_id']); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-8">
            <div class="panel">
                <form method="POST" class="row g-3">

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Nama Termin</label>
                        <input
                            type="text"
                            name="term_name"
                            class="form-control"
                            value="<?= htmlspecialchars($term['term_name']); ?>"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Persentase</label>
                        <div class="input-group">
                            <input
                                type="number"
                                name="percentage"
                                id="percentage"
                                min="0"
                                max="100"
                                step="0.01"
                                class="form-control"
                                value="<?= htmlspecialchars($term['percentage'] ?? ''); ?>"
                                oninput="calculateAmountFromPercentage()">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">Jika isi persen, jumlah rupiah otomatis berubah.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Jumlah Tagihan</label>
                        <input
                            type="text"
                            id="amount_display"
                            class="form-control currency-input"
                            data-target="amount"
                            value="Rp <?= number_format((float) $term['amount'], 0, ',', '.'); ?>"
                            oninput="calculatePercentageFromAmount()"
                            required>

                        <input
                            type="hidden"
                            name="amount"
                            id="amount"
                            value="<?= (float) $term['amount']; ?>">
                        <small class="text-muted">Jika isi rupiah, persentase otomatis berubah.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Jatuh Tempo</label>
                        <input
                            type="date"
                            name="due_date"
                            value="<?= htmlspecialchars($term['due_date']); ?>"
                            class="form-control"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Status</label><br>

                        <?php if ($term['status'] === 'paid'): ?>
                            <span class="badge rounded-pill badge-soft-green">Paid</span>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-orange">Unpaid</span>
                        <?php endif; ?>

                        <small class="text-muted d-block mt-1">
                            Status hanya bisa diubah melalui tombol Bayar.
                        </small>
                    </div>

                    <?php if (finance_user_can_use_non_posting()): ?>
                    <div class="col-md-12"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" id="posting_mode" class="form-select" onchange="togglePostingMode()"><option value="posted" <?= ($term['posting_mode']??'posted')==='posted'?'selected':''; ?>>Termin berjalan — pembayaran memengaruhi saldo</option><option value="historical" <?= ($term['posting_mode']??'')==='historical'?'selected':''; ?>>Historis sudah dibayar — tidak memengaruhi saldo</option><option value="opening_outstanding" <?= ($term['posting_mode']??'')==='opening_outstanding'?'selected':''; ?>>Piutang awal masih terbuka — pembayaran berikutnya memengaruhi saldo</option></select></div>
                    <div class="col-md-6 <?= ($term['posting_mode']??'posted')==='historical'?'':'d-none'; ?>" id="historical_paid_date_box"><label class="form-label fw-semibold">Tanggal Dibayar (Historis)</label><input type="date" name="historical_paid_date" class="form-control" value="<?= htmlspecialchars($term['paid_date'] ?? ''); ?>"></div>
                    <div class="col-md-6 <?= ($term['posting_mode']??'posted')==='historical'?'':'d-none'; ?>" id="historical_received_amount_box"><label class="form-label fw-semibold">Nominal Bersih Diterima (Historis)</label><input type="text" name="historical_received_amount" id="historical_received_amount" class="form-control currency-input" value="<?= number_format((float)($term['received_amount'] ?? 0) > 0 ? (float)$term['received_amount'] : (float)$term['amount'], 0, ',', '.'); ?>" <?= ($term['posting_mode']??'posted')==='historical'?'':'disabled'; ?>><small class="text-muted">Isi nominal setelah potongan pajak.</small></div>
                    <div class="col-md-6 <?= ($term['posting_mode']??'posted')==='historical'?'':'d-none'; ?>" id="historical_tax_preview_box"><div class="alert alert-warning rounded-4 mb-0 h-100"><span class="small">Potongan pajak historis</span><div class="fw-bold fs-5" id="historical_tax_preview">Rp <?= number_format((float)($term['tax_amount'] ?? 0), 0, ',', '.'); ?></div><small id="historical_tax_percent_preview"><?= number_format((float)($term['tax_percentage'] ?? 0), 2, ',', '.'); ?>%</small></div></div>
                    <div class="col-md-12 <?= ($term['posting_mode']??'posted')==='posted'?'d-none':''; ?>" id="historical_reason_box"><label class="form-label fw-semibold">Alasan / Referensi</label><textarea name="historical_reason" class="form-control" rows="2"><?= htmlspecialchars($term['historical_reason'] ?? ''); ?></textarea></div>
                    <?php else: ?><input type="hidden" name="posting_mode" value="<?= htmlspecialchars($term['posting_mode'] ?? 'posted'); ?>"><input type="hidden" name="historical_reason" value="<?= htmlspecialchars($term['historical_reason'] ?? ''); ?>"><?php endif; ?>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea name="notes" rows="4" class="form-control"><?= htmlspecialchars($term['notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-nukari">
                            <i class="bi bi-save me-1"></i> Update Termin
                        </button>

                        <a href="<?= base_url('pages/projects/terms.php?project_id=' . $term['project_id']); ?>" class="btn btn-light">
                            Batal
                        </a>
                    </div>

                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-info-circle me-1"></i> Panduan Edit Termin
                </h5>

                <p class="small text-muted mb-2">
                    Persentase dan jumlah rupiah saling menghitung otomatis.
                </p>

                <p class="small text-muted mb-2">
                    Termin yang sudah <strong>Paid</strong> akan otomatis menyesuaikan transaksi income jika nominalnya diubah.
                </p>

                <p class="small text-muted mb-0">
                    Status tidak bisa diubah manual agar data keuangan tetap valid.
                </p>
            </div>
        </div>

    </div>

</main>

<script>
    const contractValue = <?= (float) $term['contract_value']; ?>;

    function togglePostingMode() {
        const field = document.getElementById('posting_mode');
        if (!field) return;
        const historical = field.value === 'historical';
        const nonPosted = field.value !== 'posted';
        const paidDateBox = document.getElementById('historical_paid_date_box');
        const receivedBox = document.getElementById('historical_received_amount_box');
        const taxBox = document.getElementById('historical_tax_preview_box');
        const reasonBox = document.getElementById('historical_reason_box');
        paidDateBox?.classList.toggle('d-none', !historical);
        receivedBox?.classList.toggle('d-none', !historical);
        taxBox?.classList.toggle('d-none', !historical);
        reasonBox?.classList.toggle('d-none', !nonPosted);
        const paidDate = document.querySelector('[name="historical_paid_date"]');
        const receivedAmount = document.getElementById('historical_received_amount');
        const reason = document.querySelector('[name="historical_reason"]');
        if (paidDate) paidDate.required = historical;
        if (receivedAmount) {
            receivedAmount.disabled = !historical;
            receivedAmount.required = historical;
        }
        if (reason) reason.required = nonPosted;
        refreshHistoricalTax(historical);
    }

    function cleanNumberLocal(value) {
        return String(value).replace(/[^0-9]/g, '');
    }

    function refreshHistoricalTax(useGrossAsDefault = false) {
        const amountField = document.getElementById('amount');
        const receivedField = document.getElementById('historical_received_amount');
        if (!amountField || !receivedField) return;
        const gross = Number(cleanNumberLocal(amountField.value)) || 0;
        let received = Number(cleanNumberLocal(receivedField.value)) || 0;
        if (useGrossAsDefault && received <= 0 && gross > 0) {
            received = gross;
            receivedField.value = new Intl.NumberFormat('id-ID').format(gross);
        }
        const tax = Math.max(0, gross - Math.min(gross, received));
        const percentage = gross > 0 ? tax / gross * 100 : 0;
        document.getElementById('historical_tax_preview').textContent = 'Rp ' + new Intl.NumberFormat('id-ID').format(tax);
        document.getElementById('historical_tax_percent_preview').textContent = percentage.toFixed(2).replace('.', ',') + '%';
    }

    document.getElementById('historical_received_amount')?.addEventListener('input', () => refreshHistoricalTax());
    togglePostingMode();

    function calculateAmountFromPercentage() {
        const percentageInput = document.getElementById('percentage');
        const amountDisplay = document.getElementById('amount_display');
        const amountHidden = document.getElementById('amount');

        const percentage = parseFloat(percentageInput.value);

        if (!isNaN(percentage) && percentage > 0 && contractValue > 0) {
            const amount = Math.round(contractValue * percentage / 100);

            amountHidden.value = amount;
            amountDisplay.value = 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
            refreshHistoricalTax();
        }
    }

    function calculatePercentageFromAmount() {
        const percentageInput = document.getElementById('percentage');
        const amountDisplay = document.getElementById('amount_display');
        const amountHidden = document.getElementById('amount');

        const rawAmount = cleanNumberLocal(amountDisplay.value);

        if (rawAmount === '') {
            amountHidden.value = '';
            percentageInput.value = '';
            refreshHistoricalTax();
            return;
        }

        amountHidden.value = rawAmount;

        if (contractValue > 0) {
            const percentage = (parseFloat(rawAmount) / contractValue) * 100;
            percentageInput.value = percentage.toFixed(2);
        }
        refreshHistoricalTax();
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
