<?php
require_once __DIR__ . '/helpers.php';

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
        $proofFile = f_upload_file('proof_file', $t['proof_file']);

        if ($amount <= 0) throw new Exception('Nominal transaksi harus lebih dari 0.');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE transactions
            SET transaction_date = :date,
                type = :type,
                category_id = :category_id,
                account_id = :account_id,
                project_id = :project_id,
                amount = :amount,
                description = :description,
                payment_method = :payment_method,
                proof_file = :proof_file,
                reference_code = :reference_code,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
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
            ':id' => $id
        ]);

        if ($oldAccountId) f_recalculate_account($pdo, $oldAccountId);
        if ($accountId) f_recalculate_account($pdo, $accountId);

        $pdo->commit();

        redirect('pages/finance/transactions.php?message=Transaksi berhasil diperbarui');
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
            <h1 class="fw-bold mb-1">Edit Transaksi Manual</h1>
            <p class="text-muted mb-0"><?= f_e($t['transaction_code']); ?></p>
        </div>
        <a href="<?= base_url('pages/finance/transactions.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="type" class="form-select" required>
                    <option value="income" <?= $t['type'] === 'income' ? 'selected' : ''; ?>>Income / Pemasukan</option>
                    <option value="expense" <?= $t['type'] === 'expense' ? 'selected' : ''; ?>>Expense / Pengeluaran</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal</label>
                <input type="date" name="transaction_date" class="form-control" value="<?= f_e($t['transaction_date']); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nominal</label>
                <input type="text" name="amount" class="form-control money-input" value="<?= number_format((float)$t['amount'], 0, ',', '.'); ?>" required>
            </div>

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
                        <option value="<?= (int)$c['id']; ?>" <?= (int)$t['category_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?= f_e($c['category_name']); ?> (<?= f_e($c['type']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Metode Pembayaran</label>
                <select name="payment_method" class="form-select">
                    <?php foreach (['cash'=>'Cash','bank_transfer'=>'Bank Transfer','qris'=>'QRIS','other'=>'Lainnya'] as $k=>$v): ?>
                        <option value="<?= $k; ?>" <?= $t['payment_method'] === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                    <?php endforeach; ?>
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
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
