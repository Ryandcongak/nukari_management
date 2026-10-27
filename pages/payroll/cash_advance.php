<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/employee_funding_helper.php';

$pageTitle = 'Kasbon Karyawan - Nukari';

$error = '';
$success = '';

$month = $_GET['month'] ?? $_POST['deduction_month'] ?? date('Y-m');
$keyword = trim($_GET['keyword'] ?? '');
$statusFilter = $_GET['status'] ?? '';

function cash_money($value)
{
    if (function_exists('rupiah')) {
        return rupiah($value);
    }

    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function normalize_cash_money($value)
{
    $value = trim((string)$value);
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    return (float)$value;
}

function cash_status_badge($status)
{
    if ($status === 'paid') {
        return '<span class="badge rounded-pill badge-soft-green">Lunas</span>';
    }

    if ($status === 'cancelled') {
        return '<span class="badge rounded-pill badge-soft-red">Dibatalkan</span>';
    }

    return '<span class="badge rounded-pill badge-soft-orange">Aktif</span>';
}

$employeeStmt = $pdo->query("
    SELECT id, full_name, employee_code, position, department
    FROM employees
    WHERE employment_status = 'active'
    ORDER BY full_name ASC
");
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);
$fundingOptions=employee_funding_options($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_cash_advance') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $advanceDate = $_POST['advance_date'] ?? date('Y-m-d');
        $amount = normalize_cash_money($_POST['amount'] ?? 0);
        $deductionMonth = $_POST['deduction_month'] ?? date('Y-m');
        $notes = trim($_POST['notes'] ?? '');
        $postingMode = $_POST['posting_mode'] ?? 'posted';
        $historicalReason = trim($_POST['historical_reason'] ?? '');
        $proofPath = null;

        if ($employeeId <= 0) {
            $error = 'Karyawan wajib dipilih.';
        } elseif ($amount <= 0) {
            $error = 'Nominal kasbon harus lebih dari 0.';
        } elseif (!preg_match('/^\d{4}-\d{2}$/', $deductionMonth)) {
            $error = 'Bulan pemotongan tidak valid.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $advanceDate)) {
            $error = 'Tanggal kasbon tidak valid.';
        } else {
            try {
                $postingMode=finance_validate_posting_mode($postingMode,$historicalReason);
                $historicalReason=$postingMode==='historical'?trim($historicalReason):null;
                if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                    $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
                    $fileName = $_FILES['proof_file']['name'];
                    $fileTmp = $_FILES['proof_file']['tmp_name'];
                    $fileSize = $_FILES['proof_file']['size'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowedExt)) {
                        throw new Exception('Format bukti harus JPG, JPEG, PNG, atau PDF.');
                    }

                    if ($fileSize > 5 * 1024 * 1024) {
                        throw new Exception('Ukuran file bukti maksimal 5MB.');
                    }

                    $uploadDir = __DIR__ . '/../../uploads/cash_advances/';

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    $newFileName = 'cash_advance_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.' . $ext;
                    $targetPath = $uploadDir . $newFileName;

                    if (!move_uploaded_file($fileTmp, $targetPath)) {
                        throw new Exception('Gagal upload bukti kasbon.');
                    }

                    $proofPath = 'uploads/cash_advances/' . $newFileName;
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO employee_cash_advances (
                        employee_id,
                        advance_date,
                        amount,
                        remaining_amount,
                        deduction_month,
                        proof_file,
                        status,
                        notes,funding_source,account_id,operational_fund_id,funding_party_id,disbursement_status,posting_mode,historical_reason,payment_method,created_by
                    ) VALUES (
                        :employee_id,
                        :advance_date,
                        :amount,
                        :remaining_amount,
                        :deduction_month,
                        :proof_file,
                        'active',
                        :notes,:funding_source,:account_id,:operational_fund_id,:funding_party_id,'disbursed',:posting_mode,:historical_reason,:payment_method,:created_by
                    )
                ");

                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':advance_date' => $advanceDate,
                    ':amount' => $amount,
                    ':remaining_amount' => $amount,
                    ':deduction_month' => $deductionMonth,
                    ':proof_file' => $proofPath,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':funding_source'=>$_POST['funding_source']??'company',':account_id'=>($_POST['account_id']??'')?:null,':operational_fund_id'=>($_POST['operational_fund_id']??'')?:null,':funding_party_id'=>($_POST['funding_party_id']??'')?:null,':posting_mode'=>$postingMode,':historical_reason'=>$historicalReason,':payment_method'=>$_POST['payment_method']??'cash',':created_by'=>$_SESSION['user_id']??null
                ]);
                $cashId=(int)$pdo->lastInsertId();
                $links=employee_funding_disburse($pdo,'employee_cash_advances',$cashId,$amount,$advanceDate,$_POST['funding_source']??'company',(int)($_POST['account_id']??0),(int)($_POST['operational_fund_id']??0),(int)($_POST['funding_party_id']??0),$_POST['payment_method']??'cash','Pencairan kasbon karyawan #'.$cashId,$_SESSION['user_id']??null,$postingMode,$historicalReason);
                $pdo->prepare("UPDATE employee_cash_advances SET transaction_id=:tx,debt_id=:debt WHERE id=:id")->execute([':tx'=>$links['transaction_id'],':debt'=>$links['debt_id'],':id'=>$cashId]);
                $pdo->commit();

                $success = 'Kasbon berhasil ditambahkan. Jangan lupa Generate / Recalculate Payroll agar masuk sebagai potongan.';
                $month = $deductionMonth;
            } catch (Exception $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                $error = 'Gagal menambahkan kasbon: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'cancel') {
        $cashId = (int)($_POST['cash_id'] ?? 0);

        if ($cashId > 0) {
            try {
                $pdo->beginTransaction();
                employee_funding_cancel($pdo,'employee_cash_advances',$cashId);
                $pdo->commit();
                $success = 'Kasbon berhasil dibatalkan dan sumber dana dikembalikan.';
            } catch (Exception $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                $error = 'Gagal membatalkan kasbon: '.$e->getMessage();
            }
        }
    }

    if ($action === 'delete') {
        $cashId = (int)($_POST['cash_id'] ?? 0);

        if ($cashId > 0) {
            try {
                $pdo->beginTransaction();
                employee_funding_assert_reversible($pdo,'employee_cash_advances',$cashId);
                employee_funding_clear($pdo,'employee_cash_advances',$cashId);
                $stmt = $pdo->prepare("
                    SELECT proof_file
                    FROM employee_cash_advances
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $cashId]);
                $cash = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($cash && !empty($cash['proof_file'])) {
                    $filePath = __DIR__ . '/../../' . $cash['proof_file'];

                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }

                $deleteStmt = $pdo->prepare("
                    DELETE FROM employee_cash_advances
                    WHERE id = :id
                ");
                $deleteStmt->execute([':id' => $cashId]);
                $pdo->commit();

                $success = 'Data kasbon berhasil dihapus permanen.';
            } catch (Exception $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                $error = 'Gagal menghapus kasbon: ' . $e->getMessage();
            }
        }
    }
}

$where = [];
$params = [
    ':month' => $month
];

$where[] = "eca.deduction_month = :month";

if ($keyword !== '') {
    $where[] = "(
        e.full_name LIKE :keyword_name
        OR e.employee_code LIKE :keyword_code
        OR e.position LIKE :keyword_position
        OR e.department LIKE :keyword_department
        OR eca.notes LIKE :keyword_notes
    )";
    $keywordValue = '%' . $keyword . '%';
    $params[':keyword_name'] = $keywordValue;
    $params[':keyword_code'] = $keywordValue;
    $params[':keyword_position'] = $keywordValue;
    $params[':keyword_department'] = $keywordValue;
    $params[':keyword_notes'] = $keywordValue;
}

if ($statusFilter !== '') {
    $where[] = "eca.status = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        eca.*,
        e.full_name,
        e.employee_code,
        e.position,
        e.department,
        fa.account_name, ofu.fund_code, ofu.person_name AS fund_person_name,
        COALESCE(fp.display_name,ofu.person_name) AS fund_holder_name,
        cp.display_name AS funding_party_name
    FROM employee_cash_advances eca
    JOIN employees e ON eca.employee_id = e.id
    LEFT JOIN finance_accounts fa ON fa.id=eca.account_id
    LEFT JOIN operational_funds ofu ON ofu.id=eca.operational_fund_id
    LEFT JOIN company_parties fp ON fp.id=ofu.holder_party_id
    LEFT JOIN company_parties cp ON cp.id=eca.funding_party_id
    WHERE {$whereSql}
    ORDER BY eca.advance_date DESC, e.full_name ASC
");

$stmt->execute($params);
$cashAdvances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'count' => count($cashAdvances),
    'active' => 0,
    'paid' => 0,
    'cancelled' => 0,
    'total_amount' => 0,
    'total_remaining' => 0
];

foreach ($cashAdvances as $cash) {
    if (isset($summary[$cash['status']])) {
        $summary[$cash['status']]++;
    }

    $summary['total_amount'] += (float)$cash['amount'];

    if ($cash['status'] === 'active') {
        $summary['total_remaining'] += (float)$cash['remaining_amount'];
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Kasbon Karyawan</h1>
            <p class="text-muted mb-0">
                Catat kasbon karyawan beserta bukti agar otomatis menjadi potongan payroll.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/payroll/index.php?month=' . urlencode($month)); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Payroll
            </a>

            <a href="<?= base_url('pages/payroll/components.php?month=' . urlencode($month)); ?>" class="btn btn-outline-primary">
                <i class="bi bi-plus-circle me-1"></i> Komponen Payroll
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-3">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success rounded-3">
            <?= htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Total Data Kasbon</div>
                <div class="stat-number"><?= (int)$summary['count']; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Kasbon Aktif</div>
                <div class="stat-number"><?= (int)$summary['active']; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Sisa Potongan Aktif</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= cash_money($summary['total_remaining']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Total Kasbon Bulan Ini</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= cash_money($summary['total_amount']); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">Tambah Kasbon</h5>

        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_cash_advance">

            <div class="col-md-3">
                <label class="form-label fw-semibold">Karyawan</label>
                <select name="employee_id" class="form-select" required>
                    <option value="">Pilih Karyawan</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int)$employee['id']; ?>">
                            <?= htmlspecialchars($employee['full_name']); ?>
                            <?= !empty($employee['employee_code']) ? ' - ' . htmlspecialchars($employee['employee_code']) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Tanggal Kasbon</label>
                <input type="date" name="advance_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Bulan Potong</label>
                <input type="month" name="deduction_month" class="form-control" value="<?= htmlspecialchars($month); ?>" required>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Nominal</label>
                <input type="text" name="amount" id="amountInput" class="form-control" placeholder="Contoh: 500.000" required>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Upload Bukti</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                <small class="text-muted">JPG, PNG, PDF. Maks 5MB.</small>
            </div>

            <div class="col-md-3"><label class="form-label fw-semibold">Sumber Dana</label><select name="funding_source" class="form-select funding-source"><option value="company">Kas/Bank Perusahaan</option><option value="operational_fund">Dana Operasional PIC</option><option value="party_advance">Talangan Pihak/PIC</option></select></div>
            <div class="col-md-3 funding-choice" data-source="company"><label class="form-label">Akun Kas/Bank</label><select name="account_id" class="form-select"><option value="">Pilih akun</option><?php foreach($fundingOptions['accounts'] as $a):?><option value="<?=$a['id'];?>"><?=htmlspecialchars($a['account_name']);?> · <?=cash_money($a['current_balance']);?></option><?php endforeach;?></select></div>
            <div class="col-md-3 funding-choice d-none" data-source="operational_fund"><label class="form-label">Dana Operasional PIC</label><select name="operational_fund_id" class="form-select"><option value="">Pilih dana</option><?php foreach($fundingOptions['funds'] as $f):?><option value="<?=$f['id'];?>"><?=htmlspecialchars($f['display_name']?:$f['person_name']);?> · <?=cash_money($f['current_balance']);?></option><?php endforeach;?></select></div>
            <div class="col-md-3 funding-choice d-none" data-source="party_advance"><label class="form-label">Pihak/PIC Penalang</label><select name="funding_party_id" class="form-select"><option value="">Pilih pihak</option><?php foreach($fundingOptions['parties'] as $p):?><option value="<?=$p['id'];?>"><?=htmlspecialchars($p['display_name']);?></option><?php endforeach;?></select></div>
            <div class="col-md-3"><label class="form-label">Metode</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bank_transfer">Transfer</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
            <?php if (finance_user_can_use_non_posting()): ?>
            <div class="col-md-4"><label class="form-label fw-semibold">Pengaruh ke Saldo</label><select name="posting_mode" class="form-select posting-mode"><option value="posted">Kasbon baru — kurangi saldo</option><option value="historical">Kasbon lama — tidak ubah saldo</option></select></div>
            <div class="col-md-8 historical-reason d-none"><label class="form-label fw-semibold">Alasan Catatan Lama</label><input type="text" name="historical_reason" class="form-control" placeholder="Contoh: dicairkan sebelum saldo awal sistem"></div>
            <?php else: ?><input type="hidden" name="posting_mode" value="posted"><?php endif; ?>

            <div class="col-md-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Contoh: Kasbon kebutuhan pribadi / operasional"></textarea>
            </div>

            <div class="col-md-12">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-save me-1"></i> Simpan Kasbon
                </button>
            </div>
        </form>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-semibold">Bulan Potong</label>
                <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Cari</label>
                <input type="text" name="keyword" class="form-control" value="<?= htmlspecialchars($keyword); ?>" placeholder="Nama, kode, catatan">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="">Semua</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>Aktif</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>

            <div class="col-md-2 d-grid">
                <a href="<?= base_url('pages/payroll/cash_advance.php'); ?>" class="btn btn-outline-secondary">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Daftar Kasbon</h5>
                <p class="text-muted mb-0">
                    Kasbon aktif akan dipakai sebagai potongan saat payroll digenerate.
                </p>
            </div>

            <input
                type="text"
                id="tableSearch"
                class="form-control"
                style="max-width: 320px;"
                placeholder="Search cepat di tabel...">
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="cashTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Tanggal</th>
                        <th>Bulan Potong</th>
                        <th>Nominal</th>
                        <th>Sumber Dana</th>
                        <th>Sisa</th>
                        <th>Bukti</th>
                        <th>Status</th>
                        <th>Catatan</th>
                        <th width="260">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($cashAdvances): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($cashAdvances as $cash): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($cash['full_name']); ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($cash['employee_code'] ?? '-'); ?> ·
                                        <?= htmlspecialchars($cash['position'] ?? '-'); ?>
                                        <?= !empty($cash['department']) ? ' · ' . htmlspecialchars($cash['department']) : ''; ?>
                                    </small>
                                </td>

                                <td><?= date('d M Y', strtotime($cash['advance_date'])); ?></td>

                                <td><?= htmlspecialchars($cash['deduction_month']); ?></td>

                                <td class="fw-semibold">
                                    <?= cash_money($cash['amount']); ?>
                                </td>

                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(employee_funding_source_label($cash)); ?></span><div><small class="text-muted"><?= htmlspecialchars(ucwords(str_replace('_',' ',$cash['payment_method']??'cash'))); ?></small></div></td>

                                <td class="fw-semibold text-danger">
                                    <?= cash_money($cash['remaining_amount']); ?>
                                </td>

                                <td>
                                    <?php if (!empty($cash['proof_file'])): ?>
                                        <a href="<?= base_url($cash['proof_file']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-paperclip"></i> Lihat
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= cash_status_badge($cash['status']); ?></td>

                                <td>
                                    <?= !empty($cash['notes']) ? nl2br(htmlspecialchars($cash['notes'])) : '<span class="text-muted">-</span>'; ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php if ($cash['status'] === 'active'): ?>
                                            <form method="POST" onsubmit="return confirm('Batalkan kasbon ini? Data tetap tersimpan sebagai arsip, tetapi tidak masuk payroll.');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="cash_id" value="<?= (int)$cash['id']; ?>">
                                                <input type="hidden" name="deduction_month" value="<?= htmlspecialchars($month); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    Batal
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" onsubmit="return confirm('Hapus permanen data kasbon ini? Data dan bukti kasbon akan dihapus.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="cash_id" value="<?= (int)$cash['id']; ?>">
                                            <input type="hidden" name="deduction_month" value="<?= htmlspecialchars($month); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                Belum ada data kasbon pada bulan ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($cashAdvances): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="4">TOTAL</td>
                            <td><?= cash_money($summary['total_amount']); ?></td>
                            <td class="text-danger"><?= cash_money($summary['total_remaining']); ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</main>
<script>document.querySelector('.funding-source')?.addEventListener('change',function(){document.querySelectorAll('.funding-choice').forEach(e=>e.classList.toggle('d-none',e.dataset.source!==this.value));});document.querySelector('.posting-mode')?.addEventListener('change',function(){document.querySelector('.historical-reason')?.classList.toggle('d-none',this.value!=='historical');});</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const amountInput = document.getElementById('amountInput');

        if (amountInput) {
            amountInput.addEventListener('input', function() {
                let value = this.value.replace(/[^0-9]/g, '');

                if (value === '') {
                    this.value = '';
                    return;
                }

                this.value = new Intl.NumberFormat('id-ID').format(value);
            });
        }

        const searchInput = document.getElementById('tableSearch');
        const table = document.getElementById('cashTable');

        if (searchInput && table) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr');

                rows.forEach(function(row) {
                    const text = row.innerText.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
