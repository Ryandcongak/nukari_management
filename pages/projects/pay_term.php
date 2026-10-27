<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';
require_once __DIR__ . '/../../includes/project_status_helper.php';


function uploadProjectEvidenceReceipts($files, $transaction_id, $pdo)
{
    $transaction_id = (int) $transaction_id;
    if ($transaction_id <= 0) return [];
    $paths = upload_store_multiple($files, 'project_transactions', 'trx_' . $transaction_id, upload_image_mimes(), 5242880, 10);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_transaction_receipts (transaction_id, image_path)
            VALUES (:transaction_id, :image_path)
        ");
        foreach ($paths as $path) $stmt->execute([':transaction_id' => $transaction_id, ':image_path' => $path]);
    } catch (Throwable $e) {
        foreach ($paths as $path) upload_delete_file($path);
        throw $e;
    }
    return $paths;
}

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT t.*, p.project_name
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

$accountsStmt = $pdo->query("SELECT * FROM finance_accounts WHERE status = 'active' ORDER BY is_default DESC, account_name ASC");
$financeAccounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Konfirmasi Pembayaran Termin - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadedReceiptPaths = [];

    if ($term['status'] === 'paid') {
        redirect('pages/projects/detail.php?id=' . $term['project_id']);
    }

    try {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $paymentMethod = finance_normalize_payment_method($_POST['payment_method'] ?? 'bank_transfer');
        $receivedAmount = (float) preg_replace('/[^\d]/', '', (string)($_POST['received_amount'] ?? 0));
        if ($receivedAmount <= 0) throw new Exception('Nominal bersih yang diterima wajib lebih dari nol.');
        if ($receivedAmount > (float)$term['amount']) throw new Exception('Nominal bersih diterima tidak boleh melebihi nilai tagihan termin.');
        $taxAmount = max(0, (float)$term['amount'] - $receivedAmount);
        $taxPercentage = (float)$term['amount'] > 0 ? round($taxAmount / (float)$term['amount'] * 100, 2) : 0;
        if ($accountId <= 0) {
            throw new Exception('Pilih akun kas/bank tujuan pembayaran termin terlebih dahulu.');
        }

        $pdo->beginTransaction();

        $update = $pdo->prepare("
            UPDATE project_payment_terms
            SET status = 'paid', paid_date = CURDATE(), tax_percentage = :tax_percentage,
                tax_amount = :tax_amount, received_amount = :received_amount
            WHERE id = :id
        ");
        $update->execute([
            ':id' => $id,
            ':tax_percentage' => $taxPercentage,
            ':tax_amount' => $taxAmount,
            ':received_amount' => $receivedAmount,
        ]);

        $insert = $pdo->prepare("
            INSERT INTO project_transactions
            (
                project_id,
                transaction_date,
                transaction_type,
                category,
                description,
                funding_source,
                person_name,
                account_id,
                payment_method,
                amount,
                source_module,
                source_id
            )
            VALUES
            (
                :project_id,
                CURDATE(),
                'income',
                'Termin Pembayaran',
                :description,
                'company',
                NULL,
                :account_id,
                :payment_method,
                :amount,
                'term',
                :source_id
            )
        ");

        $insert->execute([
            ':project_id' => $term['project_id'],
            ':description' => 'Pembayaran ' . $term['term_name'],
            ':account_id' => $accountId,
            ':payment_method' => $paymentMethod,
            ':amount' => $receivedAmount,
            ':source_id' => $id,
        ]);

        $transaction_id = (int) $pdo->lastInsertId();

        $updateTermTransaction = $pdo->prepare("
            UPDATE project_payment_terms
            SET transaction_id = :transaction_id, paid_date = CURDATE()
            WHERE id = :id
            LIMIT 1
        ");
        $updateTermTransaction->execute([
            ':transaction_id' => $transaction_id,
            ':id' => $id
        ]);

        $uploadedReceiptPaths = uploadProjectEvidenceReceipts($_FILES['receipt_images'] ?? null, $transaction_id, $pdo);
        $firstReceiptPath = $uploadedReceiptPaths[0] ?? null;

        // Integrasi finance: pembayaran termin otomatis melunasi Piutang dan masuk ke transaksi keuangan.
        finance_mark_project_term_paid($pdo, (int) $id, date('Y-m-d'), $accountId, $paymentMethod, $firstReceiptPath, $_SESSION['user_id'] ?? null);

        $pdo->commit();
        project_sync_completion_status($pdo, (int)$term['project_id']);

        redirect('pages/projects/detail.php?id=' . $term['project_id'] . '&tab=terms');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($uploadedReceiptPaths as $path) upload_delete_file($path);
        $error = 'Terjadi kesalahan saat memproses pembayaran: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Konfirmasi Pembayaran Termin</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($term['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/terms.php?project_id=' . $term['project_id']); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-6">
            <div class="panel">
                <h5 class="fw-bold mb-3">Detail Termin</h5>

                <table class="table table-borderless align-middle">
                    <tr>
                        <td class="text-muted">Nama Termin</td>
                        <td class="fw-semibold"><?= htmlspecialchars($term['term_name']); ?></td>
                    </tr>

                    <tr>
                        <td class="text-muted">Jumlah</td>
                        <td class="fw-bold text-success"><?= rupiah($term['amount']); ?></td>
                    </tr>

                    <tr>
                        <td class="text-muted">Jatuh Tempo</td>
                        <td><?= date('d M Y', strtotime($term['due_date'])); ?></td>
                    </tr>

                    <tr>
                        <td class="text-muted">Status</td>
                        <td>
                            <?php if ($term['status'] === 'paid'): ?>
                                <span class="badge rounded-pill badge-soft-green">Paid</span>
                            <?php else: ?>
                                <span class="badge rounded-pill badge-soft-orange">Unpaid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="panel">
                <h5 class="fw-bold mb-3">Konfirmasi</h5>

                <p class="text-muted">
                    Pastikan pembayaran ini benar-benar sudah diterima di rekening atau kas perusahaan.
                </p>

                <div class="alert alert-warning rounded-4">
                    <strong>Setelah dikonfirmasi:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Status termin menjadi <strong>Paid</strong>.</li>
                        <li>Nominal masuk ke <strong>pendapatan project</strong>.</li>
                        <li>Transaksi tercatat sebagai income dari kas perusahaan.</li>
                        <li>Tindakan ini tidak bisa dibatalkan otomatis.</li>
                    </ul>
                </div>

                <?php if ($term['status'] !== 'paid'): ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Dana Masuk Ke Akun</label>
                            <select name="account_id" class="form-select" required>
                                <option value="">Pilih akun kas/bank tujuan</option>
                                <?php foreach ($financeAccounts as $acc): ?>
                                    <option value="<?= (int)$acc['id']; ?>"><?= htmlspecialchars($acc['account_name']); ?> - Saldo <?= rupiah($acc['current_balance'] ?? 0); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Saldo akun ini akan bertambah setelah pembayaran dikonfirmasi.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Metode Pembayaran</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="qris">QRIS</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nominal Bersih yang Diterima</label>
                            <input type="text" name="received_amount" class="form-control" inputmode="numeric" value="<?= number_format((float)$term['amount'],0,',','.'); ?>" required>
                            <small class="text-muted">Sistem menghitung potongan dan persentase pajak otomatis dari selisih nilai tagihan.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Bukti Pembayaran Termin</label>
                            <input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple>
                            <small class="text-muted">Bisa upload beberapa bukti transfer/pembayaran.</small>
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-circle me-1"></i> Konfirmasi Pembayaran
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-success rounded-4 text-center mb-0">
                        Termin ini sudah dibayar.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
