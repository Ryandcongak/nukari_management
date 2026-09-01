<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Edit Akun Keuangan - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM finance_accounts WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$a) {
    redirect('pages/finance/accounts.php?error=Akun tidak ditemukan');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accountName = clean_input($_POST['account_name'] ?? '');
        $accountType = in_array($_POST['account_type'] ?? '', ['cash','bank','qris','ewallet','other'], true) ? $_POST['account_type'] : 'cash';
        $bankName = clean_input($_POST['bank_name'] ?? '');
        $accountNumber = clean_input($_POST['account_number'] ?? '');
        $openingBalance = f_money_to_number($_POST['opening_balance'] ?? 0);
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active';
        $notes = clean_input($_POST['notes'] ?? '');

        if ($accountName === '') throw new Exception('Nama akun wajib diisi.');

        $pdo->beginTransaction();

        if ($isDefault) {
            $pdo->exec("UPDATE finance_accounts SET is_default = 0");
        }

        $stmt = $pdo->prepare("
            UPDATE finance_accounts
            SET account_name = :name,
                account_type = :type,
                bank_name = :bank_name,
                account_number = :account_number,
                opening_balance = :opening,
                is_default = :is_default,
                status = :status,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $accountName,
            ':type' => $accountType,
            ':bank_name' => $bankName ?: null,
            ':account_number' => $accountNumber ?: null,
            ':opening' => $openingBalance,
            ':is_default' => $isDefault,
            ':status' => $status,
            ':notes' => $notes ?: null,
            ':id' => $id
        ]);

        f_recalculate_account($pdo, $id);

        $pdo->commit();

        redirect('pages/finance/accounts.php?message=Akun berhasil diperbarui');
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
            <h1 class="fw-bold mb-1">Edit Akun Keuangan</h1>
            <p class="text-muted mb-0"><?= f_e($a['account_code']); ?></p>
        </div>
        <a href="<?= base_url('pages/finance/accounts.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Nama Akun</label><input type="text" name="account_name" class="form-control" required value="<?= f_e($a['account_name']); ?>"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Jenis Akun</label><select name="account_type" class="form-select"><?php foreach(['cash'=>'Kas','bank'=>'Bank','qris'=>'QRIS','ewallet'=>'E-Wallet','other'=>'Lainnya'] as $k=>$v): ?><option value="<?= $k; ?>" <?= $a['account_type']===$k?'selected':''; ?>><?= $v; ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Nama Bank / Provider</label><input type="text" name="bank_name" class="form-control" value="<?= f_e($a['bank_name']); ?>"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Nomor Rekening / ID</label><input type="text" name="account_number" class="form-control" value="<?= f_e($a['account_number']); ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Saldo Awal</label><input type="text" name="opening_balance" class="form-control money-input" value="<?= number_format((float)$a['opening_balance'], 0, ',', '.'); ?>"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="active" <?= $a['status']==='active'?'selected':''; ?>>Active</option><option value="inactive" <?= $a['status']==='inactive'?'selected':''; ?>>Inactive</option></select></div>
            <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="isDefault" <?= $a['is_default'] ? 'checked' : ''; ?>><label class="form-check-label" for="isDefault">Jadikan akun default</label></div></div>
            <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" rows="4" class="form-control"><?= f_e($a['notes']); ?></textarea></div>
            <div class="col-12 text-end"><button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Perubahan</button></div>
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
