<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$pageTitle = 'Pengaturan Saldo Awal - Nukari';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('super_admin', 'admin');
    try {
        $cutoverDate = trim((string)($_POST['cutover_date'] ?? ''));
        if ($cutoverDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoverDate)) {
            throw new Exception('Tanggal mulai saldo wajib diisi dengan format tanggal yang benar.');
        }
        $earliestPostedDate = finance_earliest_posted_date($pdo);
        if ($earliestPostedDate !== null && $cutoverDate > $earliestPostedDate) {
            throw new Exception('Tanggal mulai saldo tidak boleh melewati mutasi terposting paling awal '.$earliestPostedDate.'. Ubah transaksi lama menjadi historis terlebih dahulu jika memang tidak boleh memengaruhi saldo.');
        }
        $stmt = $pdo->prepare("UPDATE finance_settings SET cutover_date=:cutover_date, historical_mode_enabled=1, updated_by=:updated_by, updated_at=NOW() WHERE id=1");
        $stmt->execute([':cutover_date'=>$cutoverDate, ':updated_by'=>$_SESSION['user_id'] ?? null]);
        log_activity($pdo, 'Finance Settings', 'UPDATE', 'Tanggal mulai saldo ditetapkan ke '.$cutoverDate, 1, 'finance_settings');
        $success = 'Tanggal mulai saldo berhasil diperbarui.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$settings = finance_get_settings($pdo);
$cutoverDate = $settings['cutover_date'] ?? date('Y-m-d');
$earliestPostedDate = finance_earliest_posted_date($pdo);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h1 class="fw-bold mb-1">Pengaturan Saldo Awal</h1><p class="text-muted mb-0">Titik pemisah antara riwayat lama dan transaksi berjalan.</p></div>
        <a href="<?= base_url('pages/finance/index.php'); ?>" class="btn btn-outline-success">Kembali</a>
    </div>
    <?php if ($success): ?><div class="alert alert-success rounded-4"><?= f_e($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="panel">
                <form method="POST" class="row g-3">
                    <?= csrf_field(); ?>
                    <div class="col-12"><label class="form-label fw-semibold">Tanggal Mulai Saldo Riil</label><input type="date" name="cutover_date" class="form-control" value="<?= f_e($cutoverDate); ?>" max="<?= f_e($earliestPostedDate ?: date('Y-m-d')); ?>" <?= finance_user_can_use_non_posting() ? '' : 'disabled'; ?> required><div class="form-text">Saldo awal akun harus sama dengan kondisi riil pada awal tanggal ini. Mutasi terposting paling awal: <strong><?= f_e($earliestPostedDate ?: 'belum ada'); ?></strong>. Data yang sudah selesai sebelumnya dicatat sebagai historis.</div></div>
                    <?php if (finance_user_can_use_non_posting()): ?><div class="col-12"><button class="btn btn-nukari">Simpan Pengaturan</button></div><?php endif; ?>
                </form>
            </div>
        </div>
        <div class="col-lg-5"><div class="panel"><h5 class="fw-bold">Aturan</h5><ul class="small text-muted mb-0"><li>Transaksi berjalan mengubah saldo kas/bank.</li><li>Historis selesai hanya menjadi catatan.</li><li>Saldo awal terbuka tetap menjadi hutang/piutang; pelunasannya kelak mengubah saldo.</li><li>Mode khusus hanya dapat dipilih admin/super-admin dan selalu memerlukan alasan.</li></ul></div></div>
    </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
