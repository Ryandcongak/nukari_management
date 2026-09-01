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

$oldTermName = $term['term_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $term_name = clean_input($_POST['term_name'] ?? '');
    $percentage = $_POST['percentage'] !== '' ? $_POST['percentage'] : null;
    $amount = parse_amount_id($_POST['amount'] ?? 0);
    $due_date = $_POST['due_date'] ?? '';
    $notes = clean_input($_POST['notes'] ?? '');

    if ($term_name === '') {
        $error = 'Nama termin wajib diisi.';
    } elseif ($amount <= 0) {
        $error = 'Jumlah termin harus lebih dari 0.';
    } elseif ($due_date === '') {
        $error = 'Tanggal jatuh tempo wajib diisi.';
    } else {
        try {
            $pdo->beginTransaction();

            $update = $pdo->prepare("
                UPDATE project_payment_terms SET
                    term_name = :term_name,
                    percentage = :percentage,
                    amount = :amount,
                    due_date = :due_date,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $update->execute([
                ':term_name' => $term_name,
                ':percentage' => $percentage,
                ':amount' => $amount,
                ':due_date' => $due_date,
                ':notes' => $notes ?: null,
                ':id' => $id
            ]);

            if ($term['status'] === 'paid') {
                $updateTrx = $pdo->prepare("
                    UPDATE project_transactions
                    SET 
                        description = :new_description,
                        amount = :amount
                    WHERE project_id = :project_id
                    AND transaction_type = 'income'
                    AND category = 'Termin Pembayaran'
                    AND description = :old_description
                    LIMIT 1
                ");

                $updateTrx->execute([
                    ':new_description' => 'Pembayaran ' . $term_name,
                    ':amount' => $amount,
                    ':project_id' => $term['project_id'],
                    ':old_description' => 'Pembayaran ' . $oldTermName
                ]);
            }

            // Integrasi finance: perubahan termin otomatis memperbarui Piutang.
            finance_sync_project_term_receivable($pdo, (int) $id, $_SESSION['user_id'] ?? null);

            if ($term['status'] === 'paid') {
                // Jika termin sudah paid, nominal pembayaran piutang ikut disesuaikan.
                finance_mark_project_term_paid($pdo, (int) $id, null, 'cash', $_SESSION['user_id'] ?? null);
            }

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

    function cleanNumberLocal(value) {
        return String(value).replace(/[^0-9]/g, '');
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

        const rawAmount = cleanNumberLocal(amountDisplay.value);

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