<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Tambah Transaksi Manual - Nukari';
$error = '';

$accounts = $pdo->query("SELECT id, account_name, account_type FROM finance_accounts WHERE status='active' ORDER BY is_default DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, category_name, type FROM transaction_categories ORDER BY type ASC, category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, project_name FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $type = in_array($_POST['type'] ?? '', ['income','expense'], true) ? $_POST['type'] : 'expense';
        $transactionDate = $_POST['transaction_date'] ?: date('Y-m-d');
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $amount = f_money_to_number($_POST['amount'] ?? 0);
        $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash','bank_transfer','qris','other'], true) ? $_POST['payment_method'] : 'cash';
        $description = clean_input($_POST['description'] ?? '');
        $referenceCode = clean_input($_POST['reference_code'] ?? '');
        $proofFile = f_upload_file('proof_file');

        if ($amount <= 0) {
            throw new Exception('Nominal transaksi harus lebih dari 0.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (transaction_code, transaction_date, type, category_id, account_id, project_id, amount, description, payment_method, proof_file, source_module, source_table, source_id, reference_code, created_by, created_at, updated_at)
            VALUES
            (:code, :date, :type, :category_id, :account_id, :project_id, :amount, :description, :payment_method, :proof_file, 'manual', NULL, NULL, :reference_code, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            ':code' => f_transaction_code($pdo, $type),
            ':date' => $transactionDate,
            ':type' => $type,
            ':category_id' => $categoryId,
            ':account_id' => $accountId,
            ':project_id' => $projectId,
            ':amount' => $amount,
            ':description' => $description ?: null,
            ':payment_method' => $paymentMethod,
            ':proof_file' => $proofFile,
            ':reference_code' => $referenceCode ?: null,
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);

        if ($accountId) {
            f_recalculate_account($pdo, $accountId);
        }

        $pdo->commit();

        redirect('pages/finance/transactions.php?message=Transaksi berhasil ditambahkan');
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
            <h1 class="fw-bold mb-1">Tambah Transaksi Manual</h1>
            <p class="text-muted mb-0">Gunakan untuk transaksi yang tidak berasal dari modul otomatis.</p>
        </div>
        <a href="<?= base_url('pages/finance/transactions.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="type" class="form-select" required>
                    <option value="income">Income / Pemasukan</option>
                    <option value="expense">Expense / Pengeluaran</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal</label>
                <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nominal</label>
                <input type="text" name="amount" class="form-control money-input" required placeholder="Contoh: 1.500.000">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Akun Kas/Bank</label>
                <select name="account_id" class="form-select">
                    <option value="">Tidak ditentukan</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id']; ?>"><?= f_e($a['account_name']); ?> - <?= f_e(ucfirst($a['account_type'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <select name="category_id" class="form-select">
                    <option value="">Tanpa kategori</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id']; ?>"><?= f_e($c['category_name']); ?> (<?= f_e($c['type']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Metode Pembayaran</label>
                <select name="payment_method" class="form-select">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="qris">QRIS</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Project</label>
                <select name="project_id" class="form-select">
                    <option value="">Tidak terkait project</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id']; ?>"><?= f_e($p['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Kode Referensi</label>
                <input type="text" name="reference_code" class="form-control" placeholder="No nota, invoice, referensi bank, dll">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Bukti</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="description" rows="4" class="form-control" placeholder="Keterangan transaksi..."></textarea>
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Transaksi</button>
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
