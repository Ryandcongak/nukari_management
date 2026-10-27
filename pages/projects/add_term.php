<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';


function parse_amount_id($value)
{
    $raw = trim((string) $value);
    $raw = str_replace(["Â ", ' '], '', $raw);
    $digitsOnly = preg_replace('/[^0-9]/', '', $raw);
    return $digitsOnly === '' ? 0 : (float) $digitsOnly;
}


$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT id, project_name, contract_value, project_type
    FROM projects 
    WHERE id = :id 
    LIMIT 1
");
$stmt->execute([':id' => $project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

$pageTitle = 'Tambah Termin Project - Nukari';
$error = '';
$employees = $pdo->query("SELECT id,full_name FROM employees WHERE employment_status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $term_name = clean_input($_POST['term_name'] ?? '');
    $percentage = $_POST['percentage'] !== '' ? $_POST['percentage'] : null;
    $amount = parse_amount_id($_POST['amount'] ?? 0);
    $pic_employee_id = !empty($_POST['pic_employee_id']) ? (int)$_POST['pic_employee_id'] : null;
    $pic_name = clean_input($_POST['pic_name'] ?? '');
    if ($pic_name === '' && $pic_employee_id) {
        $picStmt = $pdo->prepare("SELECT full_name FROM employees WHERE id=:id");
        $picStmt->execute([':id'=>$pic_employee_id]);
        $pic_name = (string)$picStmt->fetchColumn();
    }
    $due_date = $_POST['due_date'] ?? '';
    $notes = clean_input($_POST['notes'] ?? '');
    $posting_mode = finance_normalize_posting_mode($_POST['posting_mode'] ?? 'posted', true);
    $historical_reason = $posting_mode === 'posted' ? null : trim((string)($_POST['historical_reason'] ?? ''));
    $historical_paid_date = $_POST['historical_paid_date'] ?? '';
    $historical_received_amount = parse_amount_id($_POST['historical_received_amount'] ?? 0);
    $historical_tax_amount = 0;
    $historical_tax_percentage = 0;

    if ($term_name === '') {
        $error = 'Nama termin wajib diisi.';
    } elseif ($amount <= 0) {
        $error = 'Jumlah tagihan harus lebih dari 0.';
    } elseif ($due_date === '') {
        $error = 'Tanggal jatuh tempo wajib diisi.';
    } elseif ($posting_mode === 'historical' && $historical_paid_date === '') {
        $error = 'Tanggal pembayaran historis wajib diisi.';
    } elseif ($posting_mode === 'historical' && $historical_received_amount <= 0) {
        $error = 'Nominal bersih historis wajib lebih dari nol.';
    } elseif ($posting_mode === 'historical' && $historical_received_amount > $amount) {
        $error = 'Nominal bersih historis tidak boleh melebihi nilai termin.';
    } elseif ($posting_mode !== 'posted' && !finance_user_can_use_non_posting()) {
        $error = 'Hanya admin atau super-admin yang boleh mencatat data historis/saldo awal.';
    } elseif ($posting_mode !== 'posted' && $historical_reason === '') {
        $error = 'Alasan pencatatan historis/saldo awal wajib diisi.';
    } else {
        try {
            $posting_mode = finance_validate_posting_mode($posting_mode, $historical_reason, true);
            if ($posting_mode === 'historical') {
                $historical_tax_amount = max(0, $amount - $historical_received_amount);
                $historical_tax_percentage = $amount > 0 ? round($historical_tax_amount / $amount * 100, 2) : 0;
            }
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO project_payment_terms
                (project_id, term_name, percentage, amount, tax_percentage, tax_amount, received_amount, pic_employee_id, pic_name, due_date, paid_date, status, posting_mode, historical_reason, notes)
                VALUES
                (:project_id, :term_name, :percentage, :amount, :tax_percentage, :tax_amount, :received_amount, :pic_employee_id, :pic_name, :due_date, :paid_date, :status, :posting_mode, :historical_reason, :notes)
            ");

            $stmt->execute([
                ':project_id' => $project_id,
                ':term_name' => $term_name,
                ':percentage' => $percentage,
                ':amount' => $amount,
                ':tax_percentage' => $posting_mode === 'historical' ? $historical_tax_percentage : 0,
                ':tax_amount' => $posting_mode === 'historical' ? $historical_tax_amount : 0,
                ':received_amount' => $posting_mode === 'historical' ? $historical_received_amount : 0,
                ':pic_employee_id' => $pic_employee_id,
                ':pic_name' => $pic_name ?: null,
                ':due_date' => $due_date,
                ':paid_date' => $posting_mode === 'historical' ? $historical_paid_date : null,
                ':status' => $posting_mode === 'historical' ? 'paid' : 'unpaid',
                ':posting_mode' => $posting_mode,
                ':historical_reason' => $historical_reason,
                ':notes' => $notes ?: null
            ]);

            $term_id = (int) $pdo->lastInsertId();

            // Integrasi finance: setiap termin otomatis menjadi Piutang.
            finance_sync_project_term_receivable($pdo, $term_id, $_SESSION['user_id'] ?? null);

            if ($posting_mode === 'historical') {
                $projectTrx = $pdo->prepare("INSERT INTO project_transactions
                    (project_id, transaction_date, transaction_type, funding_source, account_id, payment_method, category, description, amount, posting_mode, historical_reason, source_module, source_id)
                    VALUES (:project_id, :transaction_date, 'income', 'company', NULL, 'other', 'Termin Pembayaran', :description, :amount, 'historical', :historical_reason, 'term', :source_id)");
                $projectTrx->execute([
                    ':project_id' => $project_id,
                    ':transaction_date' => $historical_paid_date,
                    ':description' => 'Pembayaran historis ' . $term_name,
                    ':amount' => $historical_received_amount,
                    ':historical_reason' => $historical_reason,
                    ':source_id' => $term_id,
                ]);
                $projectTransactionId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE project_payment_terms SET transaction_id = :transaction_id WHERE id = :id")
                    ->execute([':transaction_id' => $projectTransactionId, ':id' => $term_id]);
                finance_mark_project_term_paid($pdo, $term_id, $historical_paid_date, null, 'other', null, $_SESSION['user_id'] ?? null);
            }

            log_activity($pdo, 'Project Termin', 'CREATE', finance_posting_mode_label($posting_mode) . ($historical_reason ? ' — ' . $historical_reason : ''), $term_id, 'project_payment_terms');

            $pdo->commit();

            redirect('pages/projects/terms.php?project_id=' . $project_id);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Gagal menyimpan termin dan piutang otomatis: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Tambah Termin Pembayaran</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/terms.php?project_id=' . $project_id); ?>" class="btn btn-outline-success">
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
                            placeholder="Contoh: Termin 1 / DP 30%"
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
                                placeholder="Contoh: 30"
                                oninput="calculateAmountFromPercentage()">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">
                            Jika isi persen, jumlah tagihan akan otomatis dihitung.
                        </small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Jumlah Tagihan</label>
                        <input
                            type="text"
                            class="form-control currency-input"
                            data-target="amount"
                            id="amount_display"
                            placeholder="Rp 1.000.000"
                            required
                            oninput="calculatePercentageFromAmount()">

                        <input type="hidden" name="amount" id="amount">
                        <small class="text-muted">
                            Jika isi rupiah, persentase akan otomatis dihitung.
                        </small>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Tanggal Jatuh Tempo</label>
                        <input type="date" name="due_date" class="form-control" required>
                    </div>
                    <?php if (finance_user_can_use_non_posting()): ?>
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Dampak Saldo</label>
                        <select name="posting_mode" id="posting_mode" class="form-select" onchange="togglePostingMode()">
                            <option value="posted">Transaksi berjalan — pembayaran nanti menambah saldo</option>
                            <option value="historical">Historis sudah dibayar — tidak memengaruhi saldo</option>
                            <option value="opening_outstanding">Piutang awal masih terbuka — pembayaran nanti menambah saldo</option>
                        </select>
                        <small class="text-muted">Pilih historis hanya jika penerimaan sudah termasuk dalam saldo riil perusahaan.</small>
                    </div>
                    <div class="col-md-6 d-none" id="historical_paid_date_box">
                        <label class="form-label fw-semibold">Tanggal Pembayaran Historis</label>
                        <input type="date" name="historical_paid_date" class="form-control" disabled>
                    </div>
                    <div class="col-md-6 d-none" id="historical_received_amount_box">
                        <label class="form-label fw-semibold">Nominal Bersih Diterima (Historis)</label>
                        <input type="text" name="historical_received_amount" id="historical_received_amount" class="form-control currency-input" disabled>
                        <small class="text-muted">Isi nominal setelah potongan pajak.</small>
                    </div>
                    <div class="col-md-6 d-none" id="historical_tax_preview_box">
                        <div class="alert alert-warning rounded-4 mb-0 h-100">
                            <span class="small">Potongan pajak historis</span>
                            <div class="fw-bold fs-5" id="historical_tax_preview">Rp 0</div>
                            <small id="historical_tax_percent_preview">0%</small>
                        </div>
                    </div>
                    <div class="col-md-12 d-none" id="historical_reason_box">
                        <label class="form-label fw-semibold">Alasan / Referensi Migrasi</label>
                        <textarea name="historical_reason" class="form-control" rows="2" placeholder="Contoh: Sudah diterima sebelum saldo awal sistem ditetapkan"></textarea>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-6"><label class="form-label fw-semibold">PIC Penerima Termin</label><select name="pic_employee_id" class="form-select"><option value="">PIC di luar daftar karyawan</option><?php foreach($employees as $employee): ?><option value="<?= (int)$employee['id']; ?>"><?= htmlspecialchars($employee['full_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Nama PIC / Kontak</label><input type="text" name="pic_name" class="form-control" placeholder="Nama orang yang menerima/menangani termin"></div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea
                            name="notes"
                            rows="4"
                            class="form-control"
                            placeholder="Contoh: Ditagih setelah pekerjaan tahap pertama selesai."></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-nukari">
                            <i class="bi bi-save me-1"></i> Simpan Termin
                        </button>

                        <a href="<?= base_url('pages/projects/terms.php?project_id=' . $project_id); ?>" class="btn btn-light">
                            Batal
                        </a>
                    </div>

                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <h5 class="fw-bold mb-3">Informasi Project</h5>

                <div class="mb-3">
                    <div class="text-muted small">Nama Project</div>
                    <div class="fw-semibold"><?= htmlspecialchars($project['project_name']); ?></div>
                </div>

                <div class="mb-3">
                    <div class="text-muted small">Nilai Kontrak</div>
                    <div class="fw-bold text-success fs-5"><?= rupiah($project['contract_value']); ?></div>
                </div>

                <hr>

                <h5 class="fw-bold mb-3">
                    <i class="bi bi-info-circle me-1"></i> Panduan Termin
                </h5>

                <p class="text-muted small mb-2">
                    Kamu bisa mengisi <strong>persentase</strong> terlebih dahulu agar nilai rupiah otomatis muncul.
                </p>

                <p class="text-muted small mb-0">
                    Bisa juga sebaliknya: isi <strong>jumlah rupiah</strong>, maka persentase terhadap nilai kontrak akan otomatis dihitung.
                </p>
            </div>
        </div>

    </div>

</main>

<script>
    const contractValue = <?= (float) $project['contract_value']; ?>;

    function togglePostingMode() {
        const field = document.getElementById('posting_mode');
        if (!field) return;
        const historical = field.value === 'historical';
        const nonPosted = field.value !== 'posted';
        const paidDateBox = document.getElementById('historical_paid_date_box');
        const receivedBox = document.getElementById('historical_received_amount_box');
        const taxBox = document.getElementById('historical_tax_preview_box');
        paidDateBox.classList.toggle('d-none', !historical);
        receivedBox.classList.toggle('d-none', !historical);
        taxBox.classList.toggle('d-none', !historical);
        document.getElementById('historical_reason_box').classList.toggle('d-none', !nonPosted);
        const paidDate = document.querySelector('[name="historical_paid_date"]');
        const receivedAmount = document.getElementById('historical_received_amount');
        paidDate.disabled = !historical;
        paidDate.required = historical;
        receivedAmount.disabled = !historical;
        receivedAmount.required = historical;
        document.querySelector('[name="historical_reason"]').required = nonPosted;
        refreshHistoricalTax(historical);
    }

    function cleanNumber(value) {
        return value.replace(/[^0-9]/g, '');
    }

    function refreshHistoricalTax(useGrossAsDefault = false) {
        const amountField = document.getElementById('amount');
        const receivedField = document.getElementById('historical_received_amount');
        if (!amountField || !receivedField) return;
        const gross = Number(cleanNumber(amountField.value)) || 0;
        let received = Number(cleanNumber(receivedField.value)) || 0;
        if (useGrossAsDefault && receivedField.dataset.autoGross === '1' && gross > 0) {
            received = gross;
            receivedField.value = new Intl.NumberFormat('id-ID').format(gross);
        }
        const tax = Math.max(0, gross - Math.min(gross, received));
        const percentage = gross > 0 ? tax / gross * 100 : 0;
        document.getElementById('historical_tax_preview').textContent = 'Rp ' + new Intl.NumberFormat('id-ID').format(tax);
        document.getElementById('historical_tax_percent_preview').textContent = percentage.toFixed(2).replace('.', ',') + '%';
    }

    const historicalReceivedInput = document.getElementById('historical_received_amount');
    if (historicalReceivedInput) historicalReceivedInput.dataset.autoGross = historicalReceivedInput.value.trim() === '' ? '1' : '0';
    historicalReceivedInput?.addEventListener('input', function () {
        this.dataset.autoGross = '0';
        refreshHistoricalTax();
    });
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
            refreshHistoricalTax(document.getElementById('posting_mode')?.value === 'historical');
        }
    }

    function calculatePercentageFromAmount() {
        const percentageInput = document.getElementById('percentage');
        const amountDisplay = document.getElementById('amount_display');
        const amountHidden = document.getElementById('amount');

        const rawAmount = cleanNumber(amountDisplay.value);

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
        refreshHistoricalTax(document.getElementById('posting_mode')?.value === 'historical');
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
