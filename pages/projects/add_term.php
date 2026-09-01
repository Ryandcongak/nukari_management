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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $term_name = clean_input($_POST['term_name'] ?? '');
    $percentage = $_POST['percentage'] !== '' ? $_POST['percentage'] : null;
    $amount = parse_amount_id($_POST['amount'] ?? 0);
    $due_date = $_POST['due_date'] ?? '';
    $notes = clean_input($_POST['notes'] ?? '');

    if ($term_name === '') {
        $error = 'Nama termin wajib diisi.';
    } elseif ($amount <= 0) {
        $error = 'Jumlah tagihan harus lebih dari 0.';
    } elseif ($due_date === '') {
        $error = 'Tanggal jatuh tempo wajib diisi.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO project_payment_terms
                (project_id, term_name, percentage, amount, due_date, status, notes)
                VALUES
                (:project_id, :term_name, :percentage, :amount, :due_date, 'unpaid', :notes)
            ");

            $stmt->execute([
                ':project_id' => $project_id,
                ':term_name' => $term_name,
                ':percentage' => $percentage,
                ':amount' => $amount,
                ':due_date' => $due_date,
                ':notes' => $notes ?: null
            ]);

            $term_id = (int) $pdo->lastInsertId();

            // Integrasi finance: setiap termin otomatis menjadi Piutang.
            finance_sync_project_term_receivable($pdo, $term_id, $_SESSION['user_id'] ?? null);

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

    function cleanNumber(value) {
        return value.replace(/[^0-9]/g, '');
    }

    function calculateAmountFromPercentage() {
        const percentageInput = document.getElementById('percentage');
        const amountDisplay = document.getElementById('amount_display');
        const amountHidden = document.getElementById('amount');

        const percentage = parseFloat(percentageInput.value);

        if (!isNaN(percentage) && percentage > 0 && contractValue > 0) {
            const amount = Math.round(contractValue * percentage / 100);

            amountHidden.value = amount;
            amountDisplay.value = 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
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
            return;
        }

        amountHidden.value = rawAmount;

        if (contractValue > 0) {
            const percentage = (parseFloat(rawAmount) / contractValue) * 100;
            percentageInput.value = percentage.toFixed(2);
        }
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>