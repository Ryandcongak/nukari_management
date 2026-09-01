<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Tambah Akun Keuangan - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accountName = clean_input($_POST['account_name'] ?? '');
        $accountType = in_array($_POST['account_type'] ?? '', ['cash','bank','qris','ewallet','other'], true) ? $_POST['account_type'] : 'cash';
        $bankName = clean_input($_POST['bank_name'] ?? '');
        $accountNumber = clean_input($_POST['account_number'] ?? '');
        $openingBalance = f_money_to_number($_POST['opening_balance'] ?? 0);
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;
        $notes = clean_input($_POST['notes'] ?? '');

        if ($accountName === '') throw new Exception('Nama akun wajib diisi.');

        $pdo->beginTransaction();

        if ($isDefault) {
            $pdo->exec("UPDATE finance_accounts SET is_default = 0");
        }

        $stmt = $pdo->prepare("
            INSERT INTO finance_accounts
            (account_code, account_name, account_type, bank_name, account_number, opening_balance, current_balance, is_default, status, notes, created_at, updated_at)
            VALUES
            (:code, :name, :type, :bank_name, :account_number, :opening, :current, :is_default, 'active', :notes, NOW(), NOW())
        ");
        $stmt->execute([
            ':code' => f_account_code($pdo, $accountType),
            ':name' => $accountName,
            ':type' => $accountType,
            ':bank_name' => $bankName ?: null,
            ':account_number' => $accountNumber ?: null,
            ':opening' => $openingBalance,
            ':current' => $openingBalance,
            ':is_default' => $isDefault,
            ':notes' => $notes ?: null
        ]);

        $pdo->commit();
        redirect('pages/finance/accounts.php?message=Akun berhasil ditambahkan');
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
            <h1 class="fw-bold mb-1">Tambah Akun Keuangan</h1>
            <p class="text-muted mb-0">Buat akun kas, bank, QRIS, atau e-wallet.</p>
        </div>
        <a href="<?= base_url('pages/finance/accounts.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Nama Akun</label><input type="text" name="account_name" class="form-control" required placeholder="Kas Kantor, Bank BCA, QRIS, dll"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Jenis Akun</label><select name="account_type" class="form-select"><option value="cash">Kas</option><option value="bank">Bank</option><option value="qris">QRIS</option><option value="ewallet">E-Wallet</option><option value="other">Lainnya</option></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Nama Bank / Provider</label><input type="text" name="bank_name" class="form-control" placeholder="BCA, BNI, Mandiri, QRIS Provider, dll"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Nomor Rekening / ID</label><input type="text" name="account_number" class="form-control"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Saldo Awal</label><input type="text" name="opening_balance" class="form-control money-input" value="0"></div>
            <div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="isDefault"><label class="form-check-label" for="isDefault">Jadikan akun default</label></div></div>
            <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" rows="4" class="form-control"></textarea></div>
            <div class="col-12 text-end"><button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Akun</button></div>
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
