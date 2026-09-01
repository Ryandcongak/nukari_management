<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Dana Operasional - Nukari';
$userId = $_SESSION['user_id'] ?? null;
$errors = [];
$success = '';

$employees = $pdo->query("SELECT id, full_name, position FROM employees WHERE employment_status='active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$accounts = $pdo->query("SELECT * FROM finance_accounts WHERE status='active' ORDER BY is_default DESC, account_type ASC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'receive') {
            $receiverType = $_POST['receiver_type'] ?? 'employee';
            $employeeId = null;
            $personName = '';
            if ($receiverType === 'employee') {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id=:id LIMIT 1");
                $stmt->execute([':id' => $employeeId]);
                $personName = (string)$stmt->fetchColumn();
                if (!$employeeId || $personName === '') {
                    throw new Exception('Pilih karyawan penerima dana operasional.');
                }
            } else {
                $personName = trim($_POST['person_name'] ?? '');
                if ($personName === '') {
                    throw new Exception('Nama penerima dana operasional wajib diisi.');
                }
            }

            $amount = f_money_to_number($_POST['amount'] ?? 0);
            $accountId = (int)($_POST['account_id'] ?? 0);
            $date = $_POST['movement_date'] ?? date('Y-m-d');
            $method = $_POST['payment_method'] ?? 'bank_transfer';
            $notes = trim($_POST['description'] ?? '');

            if ($amount <= 0) throw new Exception('Nominal dana harus lebih dari nol.');
            if (!$accountId) throw new Exception('Pilih akun kas/bank sumber dana perusahaan.');

            $pdo->beginTransaction();
            $fund = opfund_find_open($pdo, $employeeId, $personName);
            $fundId = $fund ? (int)$fund['id'] : opfund_create($pdo, $employeeId, $personName, $userId, 'Dibuat otomatis dari pemberian dana operasional.');
            opfund_add_movement($pdo, $fundId, 'receive', $amount, $date, $accountId, $notes ?: 'Dana operasional diberikan kepada ' . $personName, 'finance', 'operational_funds', $fundId, null, $method, $userId);
            $pdo->commit();
            $success = 'Dana operasional berhasil dicatat.';
        }

        if ($action === 'return') {
            $fundId = (int)($_POST['fund_id'] ?? 0);
            $amount = f_money_to_number($_POST['amount'] ?? 0);
            $accountId = (int)($_POST['account_id'] ?? 0);
            $date = $_POST['movement_date'] ?? date('Y-m-d');
            $method = $_POST['payment_method'] ?? 'cash';
            $notes = trim($_POST['description'] ?? '');

            $stmt = $pdo->prepare("SELECT * FROM operational_funds WHERE id=:id LIMIT 1");
            $stmt->execute([':id' => $fundId]);
            $fund = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fund) throw new Exception('Data dana operasional tidak ditemukan.');
            if ($amount <= 0) throw new Exception('Nominal pengembalian harus lebih dari nol.');
            if ($amount > (float)$fund['current_balance']) throw new Exception('Nominal pengembalian tidak boleh melebihi sisa saldo personal.');
            if (!$accountId) throw new Exception('Pilih akun kas/bank tujuan pengembalian.');

            $pdo->beginTransaction();
            opfund_add_movement($pdo, $fundId, 'return', $amount, $date, $accountId, $notes ?: 'Pengembalian sisa dana operasional dari ' . $fund['person_name'], 'finance', 'operational_funds', $fundId, null, $method, $userId);
            $pdo->commit();
            $success = 'Pengembalian dana operasional berhasil dicatat.';
        }

        f_recalculate_all_accounts($pdo);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = $e->getMessage();
}

$summary = opfund_summary($pdo);
$funds = $pdo->query("SELECT f.*, e.position, e.employee_code
    FROM operational_funds f
    LEFT JOIN employees e ON e.id = f.employee_id
    ORDER BY FIELD(f.status,'open','closed','cancelled'), f.updated_at DESC, f.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$recentMovements = $pdo->query("SELECT m.*, f.fund_code, f.person_name, a.account_name, p.project_name
    FROM operational_fund_movements m
    INNER JOIN operational_funds f ON f.id = m.fund_id
    LEFT JOIN finance_accounts a ON a.id = m.account_id
    LEFT JOIN projects p ON p.id = m.project_id
    ORDER BY m.movement_date DESC, m.id DESC
    LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Dana Operasional</h1>
            <p class="text-muted mb-0">Kelola uang perusahaan yang dititipkan ke personal sebelum dipakai untuk project, payroll, bahan produk, aset, atau operasional.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/finance/index.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Keuangan</a>
            <button class="btn btn-nukari" data-bs-toggle="modal" data-bs-target="#receiveModal"><i class="bi bi-wallet2 me-1"></i> Berikan Dana</button>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success rounded-4"><?= f_e($success); ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endforeach; ?>
    <?php if (!opfund_table_exists($pdo, 'operational_funds')): ?>
        <div class="alert alert-warning rounded-4">Tabel Dana Operasional belum tersedia. Import SQL migration terlebih dahulu.</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6"><div class="panel h-100"><div class="text-muted small">Dana Aktif Dipegang Personal</div><div class="fs-4 fw-bold text-success"><?= rupiah($summary['active_balance']); ?></div><small class="text-muted"><?= (int)$summary['open_count']; ?> dompet operasional open</small></div></div>
        <div class="col-xl-3 col-md-6"><div class="panel h-100"><div class="text-muted small">Total Pernah Diberikan</div><div class="fs-4 fw-bold text-primary"><?= rupiah($summary['total_received']); ?></div><small class="text-muted">Bukan expense, masih uang perusahaan</small></div></div>
        <div class="col-xl-3 col-md-6"><div class="panel h-100"><div class="text-muted small">Potensi Talangan Personal</div><div class="fs-4 fw-bold text-warning"><?= rupiah($summary['total_advance']); ?></div><small class="text-muted">Jika pemakaian melebihi dana</small></div></div>
        <div class="col-xl-3 col-md-6"><div class="panel h-100"><div class="text-muted small">Status Integrasi</div><div class="fs-5 fw-bold text-success">Global Finance</div><small class="text-muted">Siap dipakai Project, Payroll, Produk, Aset</small></div></div>
    </div>

    <div class="panel mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Panduan Dana Operasional</h5>
                <div class="text-muted small">Dana operasional berbeda dengan dana talangan. Uang yang diberikan perusahaan kepada personal belum menjadi biaya sampai digunakan untuk transaksi.</div>
            </div>
            <span class="badge rounded-pill bg-success-subtle text-success">Tahap 1 - Core Engine</span>
        </div>
        <div class="row g-2 small">
            <div class="col-md-4"><div class="border rounded-4 p-3 h-100"><b>1. Berikan Dana</b><br>Kas/Bank perusahaan berkurang, tetapi belum menjadi expense.</div></div>
            <div class="col-md-4"><div class="border rounded-4 p-3 h-100"><b>2. Dipakai Transaksi</b><br>Nanti Project/Payroll/Produk/Aset akan mengurangi saldo personal.</div></div>
            <div class="col-md-4"><div class="border rounded-4 p-3 h-100"><b>3. Kembalikan Sisa</b><br>Sisa dana bisa dikembalikan ke Kas/Bank perusahaan.</div></div>
        </div>
    </div>

    <div class="panel mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Saldo Operasional Personal</h5>
            <span class="badge rounded-pill bg-warning-subtle text-warning">Bukan Dana Talangan</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead><tr><th>Kode</th><th>Nama</th><th>Diterima</th><th>Dipakai</th><th>Dikembalikan</th><th>Sisa</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$funds): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada dana operasional.</td></tr>
                <?php endif; ?>
                <?php foreach ($funds as $f): ?>
                    <tr>
                        <td><b><?= f_e($f['fund_code']); ?></b><br><small class="text-muted">#<?= (int)$f['id']; ?></small></td>
                        <td><b><?= f_e($f['person_name']); ?></b><br><small class="text-muted"><?= $f['employee_id'] ? 'Karyawan #' . (int)$f['employee_id'] : 'Manual/Other'; ?></small></td>
                        <td><?= rupiah($f['total_received']); ?></td>
                        <td><?= rupiah($f['total_used']); ?></td>
                        <td><?= rupiah($f['total_returned']); ?></td>
                        <td class="fw-bold <?= (float)$f['current_balance'] > 0 ? 'text-success' : 'text-muted'; ?>"><?= rupiah($f['current_balance']); ?></td>
                        <td><?= $f['status'] === 'open' ? '<span class="badge rounded-pill bg-success">Open</span>' : '<span class="badge rounded-pill bg-secondary">Closed</span>'; ?></td>
                        <td>
                            <?php if ((float)$f['current_balance'] > 0): ?>
                                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#returnModal" data-fund-id="<?= (int)$f['id']; ?>" data-name="<?= f_e($f['person_name']); ?>" data-balance="<?= (float)$f['current_balance']; ?>">Kembalikan</button>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <h5 class="fw-bold mb-3">Mutasi Dana Operasional Terbaru</h5>
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead><tr><th>Tanggal</th><th>Kode</th><th>Nama</th><th>Jenis</th><th>Akun</th><th>Keterangan</th><th class="text-end">Nominal</th></tr></thead>
                <tbody>
                <?php if (!$recentMovements): ?><tr><td colspan="7" class="text-center text-muted py-4">Belum ada mutasi.</td></tr><?php endif; ?>
                <?php foreach ($recentMovements as $m): ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($m['movement_date'])); ?></td>
                        <td><b><?= f_e($m['fund_code']); ?></b></td>
                        <td><?= f_e($m['person_name']); ?></td>
                        <td><span class="badge rounded-pill <?= $m['movement_type']==='receive' ? 'bg-primary-subtle text-primary' : ($m['movement_type']==='return' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'); ?>"><?= f_e(opfund_movement_label($m['movement_type'])); ?></span></td>
                        <td><?= f_e($m['account_name'] ?: '-'); ?></td>
                        <td><?= f_e($m['description'] ?: '-'); ?></td>
                        <td class="text-end fw-bold"><?= rupiah($m['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div class="modal fade" id="receiveModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0">
            <input type="hidden" name="action" value="receive">
            <div class="modal-header"><h5 class="modal-title fw-bold">Berikan Dana Operasional</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Tanggal</label><input type="date" name="movement_date" class="form-control" value="<?= date('Y-m-d'); ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Akun Sumber Perusahaan</label><select name="account_id" class="form-select" required><option value="">Pilih akun</option><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id']; ?>"><?= f_e($a['account_name']); ?> - <?= rupiah($a['current_balance']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Penerima</label><select name="receiver_type" id="receiverType" class="form-select"><option value="employee">Karyawan Terdaftar</option><option value="manual">Nama Manual</option></select></div>
                    <div class="col-md-6 receiver-employee"><label class="form-label">Karyawan</label><select name="employee_id" class="form-select"><option value="">Pilih karyawan</option><?php foreach ($employees as $e): ?><option value="<?= (int)$e['id']; ?>"><?= f_e($e['full_name']); ?><?= $e['position'] ? ' - ' . f_e($e['position']) : ''; ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6 receiver-manual d-none"><label class="form-label">Nama Manual</label><input type="text" name="person_name" class="form-control" placeholder="Nama lengkap penerima"></div>
                    <div class="col-md-6"><label class="form-label">Nominal</label><input type="text" name="amount" class="form-control" required placeholder="Contoh: 5000000"></div>
                    <div class="col-md-6"><label class="form-label">Metode</label><select name="payment_method" class="form-select"><option value="bank_transfer">Bank Transfer</option><option value="cash">Cash</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
                    <div class="col-12"><label class="form-label">Keterangan</label><textarea name="description" class="form-control" rows="3" placeholder="Contoh: Dana belanja material project / operasional lapangan"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari">Simpan</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content rounded-4 border-0">
            <input type="hidden" name="action" value="return">
            <input type="hidden" name="fund_id" id="returnFundId">
            <div class="modal-header"><h5 class="modal-title fw-bold">Kembalikan Sisa Dana</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3 small text-muted" id="returnInfo"></div>
                <div class="mb-3"><label class="form-label">Tanggal</label><input type="date" name="movement_date" class="form-control" value="<?= date('Y-m-d'); ?>" required></div>
                <div class="mb-3"><label class="form-label">Akun Tujuan Perusahaan</label><select name="account_id" class="form-select" required><option value="">Pilih akun</option><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id']; ?>"><?= f_e($a['account_name']); ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label">Nominal Dikembalikan</label><input type="text" name="amount" id="returnAmount" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Metode</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
                <div class="mb-3"><label class="form-label">Keterangan</label><textarea name="description" class="form-control" rows="3"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari">Simpan</button></div>
        </form>
    </div>
</div>

<script>
document.getElementById('receiverType')?.addEventListener('change', function(){
    document.querySelector('.receiver-employee')?.classList.toggle('d-none', this.value !== 'employee');
    document.querySelector('.receiver-manual')?.classList.toggle('d-none', this.value !== 'manual');
});
const returnModal = document.getElementById('returnModal');
returnModal?.addEventListener('show.bs.modal', function(event){
    const btn = event.relatedTarget;
    document.getElementById('returnFundId').value = btn.getAttribute('data-fund-id');
    document.getElementById('returnAmount').value = btn.getAttribute('data-balance');
    document.getElementById('returnInfo').textContent = 'Penerima: ' + btn.getAttribute('data-name') + ' | Sisa saldo: Rp ' + Number(btn.getAttribute('data-balance')).toLocaleString('id-ID');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
