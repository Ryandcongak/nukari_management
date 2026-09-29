<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payroll_workforce_helper.php';
require_once __DIR__ . '/../../includes/payroll_additions_helper.php';
require_once __DIR__ . '/../../includes/attendance_overtime_helper.php';
require_once __DIR__ . '/../finance/helpers.php';

$pageTitle = 'Detail Payroll - Nukari';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . base_url('pages/payroll/index.php'));
    exit;
}

$error = '';
$success = '';

function detail_day_number($value): string
{
    return rtrim(rtrim(number_format((float)$value, 2, ',', '.'), '0'), ',');
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
if ($currentUserId <= 0) {
    $currentUserId = null;
}

$currentUserRole = strtolower((string)($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'admin'));
$canUnlockPayroll = in_array($currentUserRole, ['admin', 'super_admin', 'superadmin', 'director', 'direktur', 'owner'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_payroll') {
    $reason = trim($_POST['unlock_reason'] ?? '');

    if (!$canUnlockPayroll) {
        $error = 'Anda tidak memiliki izin untuk membuka lock payroll.';
    } elseif ($reason === '') {
        $error = 'Alasan unlock wajib diisi.';
    } else {
        try {
            $pdo->beginTransaction();
            finance_delete_payroll_transaction_if_exists($pdo, $id);
            opfund_delete_movements_by_source($pdo, 'payroll', 'payrolls', $id);

            $unlockStmt = $pdo->prepare("
                UPDATE payrolls
                SET
                    status = 'draft',
                    paid_date = NULL,
                    funding_source = 'company',
                    operational_fund_id = NULL,
                    payment_party_id = NULL,
                    payment_account_id = NULL,
                    payment_transaction_id = NULL,
                    is_locked = 0,
                    unlocked_at = NOW(),
                    unlocked_by = :unlocked_by,
                    unlock_reason = :unlock_reason,
                    updated_at = NOW()
                WHERE id = :id
                AND COALESCE(is_locked, 0) = 1
                LIMIT 1
            ");
            $unlockStmt->execute([
                ':unlocked_by' => $currentUserId,
                ':unlock_reason' => $reason,
                ':id' => $id
            ]);

            if ($unlockStmt->rowCount() > 0) {
                $pdo->commit();
                header('Location: ' . base_url('pages/payroll/detail.php?id=' . $id . '&unlocked=1'));
                exit;
            }

            $pdo->rollBack();
            $error = 'Payroll ini tidak dalam kondisi locked, atau sudah dibuka sebelumnya.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Gagal membuka lock payroll: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['locked'])) {
    $success = 'Payroll berhasil ditandai PAID dan otomatis dikunci.';
}

if (isset($_GET['unlocked'])) {
    $success = 'Payroll berhasil dibuka kembali. Status dikembalikan menjadi Draft.';
}


function detail_money($value)
{
    if (function_exists('rupiah')) {
        return rupiah($value);
    }

    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function detail_minutes($minutes)
{
    $minutes = (int)$minutes;

    if ($minutes <= 0) {
        return '-';
    }

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    if ($hours > 0 && $mins > 0) {
        return $hours . ' jam ' . $mins . ' menit';
    }

    if ($hours > 0) {
        return $hours . ' jam';
    }

    return $mins . ' menit';
}

function detail_time($time)
{
    return !empty($time) ? date('H:i', strtotime($time)) : '-';
}

function component_label($type)
{
    switch ($type) {
        case 'allowance':
            return 'Tunjangan';
        case 'bonus':
            return 'Bonus';
        case 'deduction':
            return 'Potongan';
        case 'tax':
            return 'Pajak';
        default:
            return ucfirst($type);
    }
}

$stmt = $pdo->prepare("
    SELECT 
        p.*,
        e.full_name,
        e.employee_code,
        e.fingerprint_id,
        e.position,
        e.department,
        e.salary_type,
        e.salary_amount,
        e.phone,
        e.join_date
    FROM payrolls p
    JOIN employees e ON p.employee_id = e.id
    WHERE p.id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $id
]);

$payroll = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payroll) {
    header('Location: ' . base_url('pages/payroll/index.php'));
    exit;
}

$projectAllocations = [];
if (function_exists('payroll_workforce_schema_ready') && payroll_workforce_schema_ready($pdo)) {
    $allocStmt = $pdo->prepare("
        SELECT a.*, p.project_name, p.project_code, c.client_name
        FROM payroll_project_allocations a
        LEFT JOIN projects p ON p.id = a.project_id
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE a.payroll_id = :payroll_id
        ORDER BY a.period_start ASC, p.project_name ASC
    ");
    $allocStmt->execute([':payroll_id' => $id]);
    $projectAllocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
}

$isLocked = ((int)($payroll['is_locked'] ?? 0) === 1);
$isPaid = ($payroll['status'] === 'paid');

$componentStmt = $pdo->prepare("
    SELECT *
    FROM payroll_components
    WHERE employee_id = :employee_id
    AND payroll_month = :payroll_month
    ORDER BY component_type ASC, created_at ASC
");

$componentStmt->execute([
    ':employee_id' => $payroll['employee_id'],
    ':payroll_month' => $payroll['payroll_month']
]);

$components = $componentStmt->fetchAll(PDO::FETCH_ASSOC);

$attendanceStmt = $pdo->prepare("
    SELECT *
    FROM attendances
    WHERE employee_id = :employee_id
    AND attendance_date BETWEEN :period_start AND :period_end
    ORDER BY attendance_date ASC
");

$attendanceStmt->execute([
    ':employee_id' => $payroll['employee_id'],
    ':period_start' => $payroll['period_start'],
    ':period_end' => $payroll['period_end']
]);

$attendances = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

$periodMonthText = (string)($payroll['payroll_month'] ?? date('Y-m'));
$periodYear = (int)date('Y', strtotime($periodMonthText . '-01'));
$periodMonthNumber = (int)date('m', strtotime($periodMonthText . '-01'));

$loanSummaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN l.status <> 'cancelled' AND l.loan_date <= :loan_period_end THEN l.loan_amount ELSE 0 END), 0) AS total_loan_amount,
        COALESCE(SUM(CASE WHEN l.status <> 'cancelled' AND l.loan_date <= :opening_paid_period_end THEN l.opening_paid_amount ELSE 0 END), 0) AS opening_paid_amount,
        COUNT(CASE WHEN l.status <> 'cancelled' AND l.loan_date <= :loan_count_period_end THEN 1 END) AS total_loan_count,
        COUNT(CASE WHEN l.status = 'active' THEN 1 END) AS active_loan_count,
        COALESCE(SUM(CASE WHEN l.status = 'active' THEN l.remaining_amount ELSE 0 END), 0) AS active_remaining_amount
    FROM employee_loans l
    WHERE l.employee_id = :employee_id
");

$loanSummaryStmt->execute([
    ':employee_id' => $payroll['employee_id'],
    ':loan_period_end' => $payroll['period_end'],
    ':opening_paid_period_end' => $payroll['period_end'],
    ':loan_count_period_end' => $payroll['period_end']
]);

$loanSummary = $loanSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$loanPaymentSummaryStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN payment_date <= :payment_period_end THEN payment_amount ELSE 0 END), 0) AS paid_until_period,
        COUNT(CASE WHEN payment_date <= :payment_count_period_end THEN 1 END) AS payment_count_until_period,
        COALESCE(SUM(CASE WHEN period_month = :period_month AND period_year = :period_year THEN payment_amount ELSE 0 END), 0) AS paid_this_period
    FROM employee_loan_payments
    WHERE employee_id = :employee_id
");

$loanPaymentSummaryStmt->execute([
    ':employee_id' => $payroll['employee_id'],
    ':payment_period_end' => $payroll['period_end'],
    ':payment_count_period_end' => $payroll['period_end'],
    ':period_month' => $periodMonthNumber,
    ':period_year' => $periodYear
]);

$loanPaymentSummary = $loanPaymentSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$loanHistoryStmt = $pdo->prepare("
    SELECT
        lp.*,
        el.loan_amount,
        el.remaining_amount,
        el.status AS loan_status
    FROM employee_loan_payments lp
    LEFT JOIN employee_loans el ON lp.loan_id = el.id
    WHERE lp.employee_id = :employee_id
    ORDER BY lp.payment_date DESC, lp.id DESC
    LIMIT 10
");

$loanHistoryStmt->execute([
    ':employee_id' => $payroll['employee_id']
]);

$loanHistories = $loanHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

$loanDeduction = (float)($payroll['loan_deduction'] ?? 0);
$cashAdvanceDeduction = (float)($payroll['cash_advance_deduction'] ?? 0);
$unpaidLeaveDeduction = (float)($payroll['unpaid_leave_deduction'] ?? 0);

$totalLoanAmount = (float)($loanSummary['total_loan_amount'] ?? 0);
$totalLoanPaidUntilPeriod = (float)($loanSummary['opening_paid_amount'] ?? 0) + (float)($loanPaymentSummary['paid_until_period'] ?? 0);
$totalLoanPaidThisPeriod = (float)($loanPaymentSummary['paid_this_period'] ?? 0);
$loanPaymentCountUntilPeriod = (int)($loanPaymentSummary['payment_count_until_period'] ?? 0);
$activeLoanCount = (int)($loanSummary['active_loan_count'] ?? 0);
$activeLoanRemaining = (float)($loanSummary['active_remaining_amount'] ?? 0);
$loanRemainingByPeriod = max(0, $totalLoanAmount - $totalLoanPaidUntilPeriod);
$loanProgressPercent = $totalLoanAmount > 0 ? min(100, round(($totalLoanPaidUntilPeriod / $totalLoanAmount) * 100, 1)) : 0;

$totalBpjs = (float)$payroll['bpjs_health'] + (float)$payroll['bpjs_employment'];
$additionalDetails = json_decode((string)($payroll['additional_details'] ?? '[]'), true);
if (!is_array($additionalDetails)) $additionalDetails = [];
$totalIncome = (float)$payroll['base_salary'] + (float)$payroll['total_allowance'] + (float)$payroll['total_bonus'] + (float)$payroll['overtime_amount'] + (float)($payroll['additional_amount'] ?? 0);
$totalCut = (float)$payroll['total_deduction'] + (float)$payroll['total_tax'] + $totalBpjs;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <?php if ($success !== ''): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>


    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Detail Payroll</h1>
            <p class="text-muted mb-0">
                Rincian gaji karyawan periode <?= htmlspecialchars($payroll['payroll_month']); ?>.
            </p>
            <br>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php if (!$isPaid && !$isLocked): ?>
                    <a href="<?= base_url('pages/payroll/pay.php?id=' . $payroll['id']); ?>"
                        class="btn btn-success"
                        onclick="return confirm('Tandai payroll ini sebagai PAID dan LOCKED? Setelah locked, payroll tidak akan berubah oleh generate ulang.');">
                        <i class="bi bi-check-circle me-1"></i> Tandai Paid & Lock
                    </a>
                <?php endif; ?>

                <?php if ($isLocked && $canUnlockPayroll): ?>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#unlockPayrollModal">
                        <i class="bi bi-unlock me-1"></i> Unlock Payroll
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/payroll/index.php?month=' . urlencode($payroll['payroll_month'])); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>

            <a href="<?= base_url('pages/payroll/slip.php?id=' . $payroll['id']); ?>" class="btn btn-nukari" target="_blank">
                <i class="bi bi-printer me-1"></i> Slip Gaji
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Total Gaji Bersih</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= detail_money($payroll['net_salary']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Total Pendapatan</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= detail_money($totalIncome); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Total Potongan</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= detail_money($totalCut); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Status Payroll</div>
                <div class="stat-number" style="font-size:22px;">
                    <?php if ($isLocked): ?>
                        Locked
                    <?php else: ?>
                        <?= $isPaid ? 'Paid' : 'Draft'; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isLocked): ?>
        <div class="alert alert-dark border-0 shadow-sm rounded-4 mb-4">
            <div class="d-flex align-items-start gap-3">
                <div class="fs-4"><i class="bi bi-lock-fill"></i></div>
                <div>
                    <div class="fw-bold mb-1">Payroll ini sudah PAID dan LOCKED.</div>
                    <div class="small mb-0">
                        Data payroll ini tidak akan berubah walaupun absensi, cuti/izin, kasbon, loan, atau komponen payroll diedit dan payroll digenerate ulang.
                        <?php if (!empty($payroll['locked_at'])): ?>
                            <br>Dikunci pada: <strong><?= date('d M Y H:i', strtotime($payroll['locked_at'])); ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif (!empty($payroll['unlock_reason'])): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4">
            <div class="fw-bold mb-1"><i class="bi bi-unlock me-1"></i> Payroll pernah dibuka kembali.</div>
            <div class="small">
                Alasan terakhir: <?= nl2br(htmlspecialchars($payroll['unlock_reason'])); ?>
                <?php if (!empty($payroll['unlocked_at'])): ?>
                    <br>Waktu unlock: <strong><?= date('d M Y H:i', strtotime($payroll['unlocked_at'])); ?></strong>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($projectAllocations)): ?>
        <div class="panel mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-1">Alokasi Kerja Project</h5>
                    <p class="text-muted mb-0 small">Hari project memakai rate project. Hari di luar project tetap memakai rate dasar workshop.</p>
                </div>
                <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2">
                    <?= detail_day_number($payroll['project_work_days'] ?? 0); ?> hari · <?= detail_money($payroll['project_worker_amount'] ?? 0); ?>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Periode</th>
                            <th class="text-end">Hari</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projectAllocations as $alloc): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($alloc['project_name'] ?? '-'); ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($alloc['project_code'] ?? '-'); ?><?= !empty($alloc['client_name']) ? ' · ' . htmlspecialchars($alloc['client_name']) : ''; ?></small>
                                </td>
                                <td><?= !empty($alloc['period_start']) ? date('d M Y', strtotime($alloc['period_start'])) : '-'; ?> - <?= !empty($alloc['period_end']) ? date('d M Y', strtotime($alloc['period_end'])) : '-'; ?></td>
                                <td class="text-end"><?= (int)$alloc['work_days']; ?></td>
                                <td class="text-end"><?= detail_money($alloc['rate']); ?></td>
                                <td class="text-end fw-semibold"><?= detail_money($alloc['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">

        <div class="col-lg-6">
            <div class="panel h-100">
                <h5 class="fw-bold mb-3">Informasi Karyawan</h5>

                <table class="table table-borderless align-middle mb-0">
                    <tr>
                        <td width="180" class="text-muted">Nama</td>
                        <td class="fw-semibold"><?= htmlspecialchars($payroll['full_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Kode Karyawan</td>
                        <td><?= htmlspecialchars($payroll['employee_code'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Fingerprint ID</td>
                        <td><?= htmlspecialchars($payroll['fingerprint_id'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Jabatan</td>
                        <td><?= htmlspecialchars($payroll['position'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Departemen</td>
                        <td><?= htmlspecialchars($payroll['department'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Tipe Gaji</td>
                        <td><?= htmlspecialchars($payroll['salary_type']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Nominal Gaji Master</td>
                        <td><?= detail_money($payroll['salary_amount']); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="panel h-100">
                <h5 class="fw-bold mb-3">Periode & Rekap Absensi</h5>

                <table class="table table-borderless align-middle mb-0">
                    <tr>
                        <td width="220" class="text-muted">Bulan Payroll</td>
                        <td class="fw-semibold"><?= htmlspecialchars($payroll['payroll_month']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Periode</td>
                        <td>
                            <?= date('d M Y', strtotime($payroll['period_start'])); ?>
                            -
                            <?= date('d M Y', strtotime($payroll['period_end'])); ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Hari Kerja</td>
                        <td><?= (int)$payroll['total_work_days']; ?> hari</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Hadir</td>
                        <td><?= detail_day_number($payroll['total_present_days']); ?> hari</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Alpha / Tidak Hadir</td>
                        <td><?= (int)$payroll['total_absent_days']; ?> hari</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Terlambat</td>
                        <td><?= detail_minutes($payroll['total_late_minutes']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Pulang Awal</td>
                        <td><?= detail_minutes($payroll['total_early_leave_minutes']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Lembur</td>
                        <td><?= detail_minutes($payroll['total_overtime_minutes']); ?></td>
                    </tr>
                </table>
            </div>
        </div>

    </div>

    <?php if ($isLocked): ?>
        <div class="alert alert-dark border-0 shadow-sm rounded-4 mb-4">
            <div class="d-flex align-items-start gap-3">
                <div class="fs-4"><i class="bi bi-lock-fill"></i></div>
                <div>
                    <div class="fw-bold mb-1">Payroll ini sudah PAID dan LOCKED.</div>
                    <div class="small mb-0">
                        Data payroll ini tidak akan berubah walaupun absensi, cuti/izin, kasbon, loan, atau komponen payroll diedit dan payroll digenerate ulang.
                        <?php if (!empty($payroll['locked_at'])): ?>
                            <br>Dikunci pada: <strong><?= date('d M Y H:i', strtotime($payroll['locked_at'])); ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif (!empty($payroll['unlock_reason'])): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-4">
            <div class="fw-bold mb-1"><i class="bi bi-unlock me-1"></i> Payroll pernah dibuka kembali.</div>
            <div class="small">
                Alasan terakhir: <?= nl2br(htmlspecialchars($payroll['unlock_reason'])); ?>
                <?php if (!empty($payroll['unlocked_at'])): ?>
                    <br>Waktu unlock: <strong><?= date('d M Y H:i', strtotime($payroll['unlocked_at'])); ?></strong>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">

        <div class="col-lg-6">
            <div class="panel h-100">
                <h5 class="fw-bold mb-3">Pendapatan</h5>

                <table class="table align-middle">
                    <tr>
                        <td>Gaji Pokok</td>
                        <td class="text-end fw-semibold"><?= detail_money($payroll['base_salary']); ?></td>
                    </tr>
                    <?php if ((float)($payroll['project_worker_amount'] ?? 0) > 0): ?>
                        <tr>
                            <td class="text-muted">Workshop</td>
                            <td class="text-end text-muted"><?= (int)($payroll['workshop_days'] ?? 0); ?> hari · <?= detail_money($payroll['workshop_amount'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Project</td>
                            <td class="text-end text-primary"><?= detail_day_number($payroll['project_work_days'] ?? 0); ?> hari · <?= detail_money($payroll['project_worker_amount'] ?? 0); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Tunjangan</td>
                        <td class="text-end fw-semibold"><?= detail_money($payroll['total_allowance']); ?></td>
                    </tr>
                    <tr>
                        <td>Bonus</td>
                        <td class="text-end fw-semibold"><?= detail_money($payroll['total_bonus']); ?></td>
                    </tr>
                    <tr>
                        <td>Upah Lembur</td>
                        <td class="text-end fw-semibold"><?= detail_money($payroll['overtime_amount']); ?></td>
                    </tr>
                    <tr>
                        <td>Tambahan</td>
                        <td class="text-end fw-semibold"><?= detail_money($payroll['additional_amount'] ?? 0); ?></td>
                    </tr>
                    <tr class="fw-bold">
                        <td>Total Pendapatan</td>
                        <td class="text-end text-success"><?= detail_money($totalIncome); ?></td>
                    </tr>
                </table>

                <small class="text-muted">
                    Rate harian: <?= detail_money($payroll['daily_rate']); ?> ·
                    Rate per jam: <?= detail_money($payroll['hourly_rate']); ?>
                </small>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="panel h-100">
                <h5 class="fw-bold mb-3">Potongan</h5>

                <table class="table align-middle">
                    <tr>
                        <td>Potongan Komponen / Lainnya</td>
                        <td class="text-end fw-semibold text-danger"><?= detail_money(max(0, (float)$payroll['total_deduction'] - $cashAdvanceDeduction - $loanDeduction - $unpaidLeaveDeduction)); ?></td>
                    </tr>
                    <tr>
                        <td>Potongan Cuti Tidak Dibayar</td>
                        <td class="text-end fw-semibold text-danger"><?= detail_money($unpaidLeaveDeduction); ?></td>
                    </tr>
                    <tr>
                        <td>Kasbon</td>
                        <td class="text-end fw-semibold text-danger"><?= detail_money($cashAdvanceDeduction); ?></td>
                    </tr>
                    <tr>
                        <td>Cicilan Loan</td>
                        <td class="text-end fw-semibold text-danger"><?= detail_money($loanDeduction); ?></td>
                    </tr>
                    <tr>
                        <td>Pajak</td>
                        <td class="text-end fw-semibold text-danger"><?= detail_money($payroll['total_tax']); ?></td>
                    </tr>
                    <tr>
                        <td>BPJS Kesehatan</td>
                        <td class="text-end fw-semibold text-danger"><?= detail_money($payroll['bpjs_health']); ?></td>
                    </tr>
                    <tr>
                        <td>BPJS Ketenagakerjaan</td>
                        <td class="text-end fw-semibold text-danger"><?= detail_money($payroll['bpjs_employment']); ?></td>
                    </tr>
                    <tr class="fw-bold">
                        <td>Total Potongan</td>
                        <td class="text-end text-danger"><?= detail_money($totalCut); ?></td>
                    </tr>
                    <tr class="fw-bold fs-5">
                        <td>Total Gaji Bersih</td>
                        <td class="text-end text-success"><?= detail_money($payroll['net_salary']); ?></td>
                    </tr>
                </table>
            </div>
        </div>

    </div>

    <?php if ($additionalDetails): ?>
        <div class="panel mb-4">
            <h5 class="fw-bold mb-3">Rincian Tambahan (Snapshot Payroll)</h5>
            <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Kategori</th><th>Nama Tambahan</th><th>Tanggal</th><th>Jumlah</th><th>Nominal</th><th>Total</th><th>Catatan</th></tr></thead><tbody>
            <?php foreach ($additionalDetails as $detail): ?><tr><td><?= htmlspecialchars(payroll_addition_category_label($detail['category'] ?? 'other')); ?></td><td><?= htmlspecialchars($detail['addition_name'] ?? '-'); ?></td><td><?= htmlspecialchars(implode(', ', array_map(fn($date) => date('d M Y', strtotime($date)), $detail['dates'] ?? []))); ?></td><td><?= (int)($detail['date_count'] ?? 0); ?>x</td><td><?= detail_money($detail['unit_amount'] ?? 0); ?></td><td class="fw-semibold text-success"><?= detail_money($detail['total_amount'] ?? 0); ?></td><td><?= htmlspecialchars($detail['notes'] ?? '-'); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    <?php endif; ?>

    <div class="panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Informasi Pinjaman Karyawan</h5>
                <div class="text-muted">Ringkasan pinjaman dan histori cicilan karyawan sampai periode payroll ini.</div>
            </div>
            <span class="badge <?= $activeLoanCount > 0 ? 'bg-warning text-dark' : 'bg-success'; ?> rounded-pill px-3 py-2">
                <?= $activeLoanCount > 0 ? $activeLoanCount . ' Loan Aktif' : 'Tidak Ada Loan Aktif'; ?>
            </span>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="border rounded-4 p-3 h-100 bg-light">
                    <div class="text-muted small fw-semibold">Total Pinjaman</div>
                    <div class="fs-5 fw-bold"><?= detail_money($totalLoanAmount); ?></div>
                    <div class="small text-muted"><?= (int)($loanSummary['total_loan_count'] ?? 0); ?> pinjaman tercatat</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-4 p-3 h-100 bg-light">
                    <div class="text-muted small fw-semibold">Sudah Dibayar</div>
                    <div class="fs-5 fw-bold text-success"><?= detail_money($totalLoanPaidUntilPeriod); ?></div>
                    <div class="small text-muted"><?= $loanPaymentCountUntilPeriod; ?> kali cicilan</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-4 p-3 h-100 bg-light">
                    <div class="text-muted small fw-semibold">Sisa Pinjaman</div>
                    <div class="fs-5 fw-bold text-danger"><?= detail_money($loanRemainingByPeriod); ?></div>
                    <div class="small text-muted">Sampai periode ini</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-4 p-3 h-100 bg-light">
                    <div class="text-muted small fw-semibold">Cicilan Bulan Ini</div>
                    <div class="fs-5 fw-bold text-primary"><?= detail_money($loanDeduction); ?></div>
                    <div class="small text-muted">Masuk potongan payroll</div>
                </div>
            </div>
        </div>

        <?php if ($totalLoanAmount > 0): ?>
            <div class="mb-4">
                <div class="d-flex justify-content-between small fw-semibold mb-1">
                    <span>Progress Pembayaran Pinjaman</span>
                    <span><?= $loanProgressPercent; ?>%</span>
                </div>
                <div class="progress rounded-pill" style="height: 12px;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $loanProgressPercent; ?>%;"></div>
                </div>
                <div class="small text-muted mt-1">
                    <?= detail_money($totalLoanPaidUntilPeriod); ?> dari <?= detail_money($totalLoanAmount); ?> sudah dibayar.
                </div>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tanggal Cicilan</th>
                        <th>Nominal</th>
                        <th>Periode</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($loanHistories): ?>
                        <?php foreach ($loanHistories as $loanHistory): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($loanHistory['payment_date'])); ?></td>
                                <td class="fw-semibold text-success"><?= detail_money($loanHistory['payment_amount']); ?></td>
                                <td><?= sprintf('%02d/%s', (int)$loanHistory['period_month'], (int)$loanHistory['period_year']); ?></td>
                                <td><?= !empty($loanHistory['notes']) ? nl2br(htmlspecialchars($loanHistory['notes'])) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                Belum ada histori cicilan pinjaman untuk karyawan ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">Komponen Tambahan Payroll</h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Jenis</th>
                        <th>Nama Komponen</th>
                        <th>Nominal</th>
                        <th>Catatan</th>
                        <th>Tanggal Input</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($components): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($components as $component): ?>
                            <tr>
                                <td><?= $no++; ?></td>
                                <td><?= component_label($component['component_type']); ?></td>
                                <td><?= htmlspecialchars($component['component_name']); ?></td>
                                <td class="<?= in_array($component['component_type'], ['deduction', 'tax']) ? 'text-danger fw-semibold' : 'text-success fw-semibold'; ?>">
                                    <?= detail_money($component['amount']); ?>
                                </td>
                                <td><?= !empty($component['notes']) ? nl2br(htmlspecialchars($component['notes'])) : '-'; ?></td>
                                <td><?= date('d M Y H:i', strtotime($component['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Tidak ada komponen tambahan untuk payroll ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <h5 class="fw-bold mb-3">Rincian Absensi Periode Payroll</h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Datang</th>
                        <th>Pulang</th>
                        <th>Terlambat</th>
                        <th>Pulang Awal</th>
                        <th>Jam Kerja</th>
                        <th>Jam Lembur</th>
                        <th>Nilai Lembur</th>
                        <th>Nilai Hari</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($attendances): ?>
                        <?php foreach ($attendances as $attendance): ?>
                            <?php $dayClass = attendance_day_classification($attendance['check_in'] ?? null, $attendance['check_out'] ?? null, $attendance['status'] ?? null); ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($attendance['attendance_date'])); ?></td>
                                <td><?= detail_time($attendance['check_in']); ?></td>
                                <td><?= detail_time($attendance['check_out']); ?></td>
                                <td><?= detail_minutes($attendance['late_minutes']); ?></td>
                                <td><?= detail_minutes($attendance['early_leave_minutes']); ?></td>
                                <td><?= detail_minutes($attendance['work_minutes']); ?></td>
                                <td><?= detail_minutes($attendance['overtime_minutes'] ?? 0); ?></td>
                                <td><?= detail_money($attendance['overtime_amount'] ?? 0); ?><div class="small text-muted"><?= ($attendance['overtime_method'] ?? 'hourly') === 'fixed' ? 'Borongan' : 'Per jam'; ?></div></td>
                                <td><strong><?= detail_day_number($attendance['attendance_day_value'] ?? 0); ?> hari</strong><div class="small <?= !empty($dayClass['needs_review']) ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?= htmlspecialchars($dayClass['label']); ?></div></td>
                                <td>
                                    <?php if (!empty($dayClass['needs_review'])): ?>
                                        <span class="badge rounded-pill badge-soft-red">Perlu Diperiksa</span>
                                    <?php elseif ($attendance['status'] === 'late'): ?>
                                        <span class="badge rounded-pill badge-soft-orange">Terlambat</span>
                                    <?php elseif ($attendance['status'] === 'absent'): ?>
                                        <span class="badge rounded-pill badge-soft-red">Alpha</span>
                                    <?php elseif ($attendance['status'] === 'permission'): ?>
                                        <span class="badge rounded-pill badge-soft-blue">Izin</span>
                                    <?php elseif ($attendance['status'] === 'sick'): ?>
                                        <span class="badge rounded-pill badge-soft-blue">Sakit</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-green">Hadir</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                Tidak ada data absensi pada periode payroll ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($payroll['notes'])): ?>
            <div class="alert alert-light border rounded-3 mt-3 mb-0">
                <strong>Catatan Payroll:</strong><br>
                <?= nl2br(htmlspecialchars($payroll['notes'])); ?>
            </div>
        <?php endif; ?>
    </div>


    <div class="modal fade" id="unlockPayrollModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content border-0 shadow rounded-4">
                <input type="hidden" name="action" value="unlock_payroll">

                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold">Unlock Payroll</h5>
                        <p class="text-muted small mb-0">Payroll akan kembali menjadi Draft dan bisa berubah saat generate ulang.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-warning small border-0 rounded-3">
                        <strong>Perhatian:</strong> gunakan fitur ini hanya jika ada kesalahan payroll, absensi, cuti/izin, kasbon, loan, atau komponen gaji.
                    </div>

                    <label class="form-label fw-semibold">Alasan Unlock <span class="text-danger">*</span></label>
                    <textarea name="unlock_reason" class="form-control" rows="4" required placeholder="Contoh: Ada koreksi cicilan loan / absensi belum lengkap / salah tekan paid."></textarea>
                </div>

                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-unlock me-1"></i> Unlock Payroll
                    </button>
                </div>
            </form>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
