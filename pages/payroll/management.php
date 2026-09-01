<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$pageTitle = 'Payroll Management - Nukari';

$error = '';
$success = '';
$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
if ($currentUserId <= 0) {
    $currentUserId = null;
}

$month = $_GET['month'] ?? $_POST['month'] ?? $_POST['payroll_month'] ?? $_POST['deduction_month'] ?? date('Y-m');
$keyword = trim($_GET['keyword'] ?? '');
$activeTab = $_GET['tab'] ?? $_POST['tab'] ?? 'components';

$allowedTabs = ['components', 'cash', 'leaves', 'loans'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'components';
}

function pm_redirect($tab, $month, $message)
{
    $month = preg_match('/^\d{4}-\d{2}$/', (string)$month) ? $month : date('Y-m');
    header('Location: management.php?tab=' . urlencode($tab) . '&month=' . urlencode($month) . '&success=' . urlencode($message));
    exit;
}

if (isset($_GET['success']) && $_GET['success'] !== '') {
    $success = trim($_GET['success']);
}

function pm_money($value)
{
    if (function_exists('rupiah')) {
        return rupiah($value);
    }
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function pm_number($value)
{
    return number_format((float)$value, 0, ',', '.');
}

function pm_normalize_money($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
    return (float)$value;
}

function pm_safe_date($date)
{
    if (empty($date)) {
        return '-';
    }
    return date('d M Y', strtotime($date));
}

function pm_component_type_label($type)
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
            return ucfirst((string)$type);
    }
}

function pm_component_type_badge($type)
{
    switch ($type) {
        case 'allowance':
            return 'badge-soft-blue';
        case 'bonus':
            return 'badge-soft-green';
        case 'deduction':
            return 'badge-soft-red';
        case 'tax':
            return 'badge-soft-orange';
        default:
            return 'badge-soft-blue';
    }
}

function pm_status_badge($status)
{
    switch ($status) {
        case 'paid':
            return '<span class="badge rounded-pill badge-soft-green">Lunas</span>';
        case 'cancelled':
            return '<span class="badge rounded-pill badge-soft-red">Dibatalkan</span>';
        case 'approved':
            return '<span class="badge rounded-pill badge-soft-green">Approved</span>';
        case 'rejected':
            return '<span class="badge rounded-pill badge-soft-red">Rejected</span>';
        case 'pending':
            return '<span class="badge rounded-pill badge-soft-orange">Pending</span>';
        case 'active':
        default:
            return '<span class="badge rounded-pill badge-soft-orange">Aktif</span>';
    }
}

function pm_leave_type_label($type)
{
    switch ($type) {
        case 'annual_leave':
            return 'Cuti Tahunan';
        case 'sick':
            return 'Sakit';
        case 'permission':
            return 'Izin';
        case 'unpaid_leave':
            return 'Izin Tidak Dibayar';
        case 'official_duty':
            return 'Dinas Luar';
        default:
            return ucfirst((string)$type);
    }
}

function pm_calculate_leave_days($startDate, $endDate)
{
    $start = strtotime($startDate);
    $end = strtotime($endDate);
    if (!$start || !$end || $end < $start) {
        return 1;
    }
    $days = 0;
    while ($start <= $end) {
        $day = (int)date('N', $start);
        if (!in_array($day, [6, 7], true)) {
            $days++;
        }
        $start = strtotime('+1 day', $start);
    }
    return max(1, $days);
}

function pm_upload_file($fieldName, $folder, $prefix)
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
    $fileName = $_FILES[$fieldName]['name'];
    $fileTmp = $_FILES[$fieldName]['tmp_name'];
    $fileSize = $_FILES[$fieldName]['size'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        throw new Exception('Format bukti harus JPG, JPEG, PNG, atau PDF.');
    }

    if ($fileSize > 5 * 1024 * 1024) {
        throw new Exception('Ukuran file bukti maksimal 5MB.');
    }

    $uploadDir = __DIR__ . '/../../uploads/' . $folder . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newFileName = $prefix . '_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;

    if (!move_uploaded_file($fileTmp, $targetPath)) {
        throw new Exception('Gagal upload file bukti.');
    }

    return 'uploads/' . $folder . '/' . $newFileName;
}

function pm_recalculate_employee_loan(PDO $pdo, $loanId)
{
    $loanStmt = $pdo->prepare("SELECT loan_amount FROM employee_loans WHERE id = :id LIMIT 1");
    $loanStmt->execute([':id' => $loanId]);
    $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        return;
    }

    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(payment_amount), 0) FROM employee_loan_payments WHERE loan_id = :loan_id");
    $paidStmt->execute([':loan_id' => $loanId]);
    $totalPaid = (float)$paidStmt->fetchColumn();

    $loanAmount = (float)$loan['loan_amount'];
    $remaining = max(0, $loanAmount - $totalPaid);
    $status = $remaining <= 0 ? 'paid' : 'active';

    $updateStmt = $pdo->prepare("
        UPDATE employee_loans
        SET total_paid = :total_paid,
            remaining_amount = :remaining_amount,
            status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE :status END,
            updated_at = NOW()
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':total_paid' => $totalPaid,
        ':remaining_amount' => $remaining,
        ':status' => $status,
        ':id' => $loanId
    ]);
}


function pm_get_employee_monthly_salary(PDO $pdo, int $employeeId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(salary_amount, 0) FROM employees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $employeeId]);
    return max(0, (float)$stmt->fetchColumn());
}

function pm_get_employee_loan_payment_total(PDO $pdo, int $employeeId, string $paymentPeriod): float
{
    if (!preg_match('/^\d{4}-\d{2}$/', $paymentPeriod)) {
        return 0;
    }

    [$year, $month] = explode('-', $paymentPeriod);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(payment_amount), 0)
        FROM employee_loan_payments
        WHERE employee_id = :employee_id
          AND period_year = :period_year
          AND period_month = :period_month
    ");
    $stmt->execute([
        ':employee_id' => $employeeId,
        ':period_year' => (int)$year,
        ':period_month' => (int)$month
    ]);

    return (float)$stmt->fetchColumn();
}

function pm_get_max_loan_payment(PDO $pdo, int $employeeId, float $remainingAmount, string $paymentPeriod): array
{
    $monthlySalary = pm_get_employee_monthly_salary($pdo, $employeeId);
    $currentPeriodPaid = pm_get_employee_loan_payment_total($pdo, $employeeId, $paymentPeriod);

    $availableBySalary = max(0, $monthlySalary - $currentPeriodPaid);
    $maxPayment = min(max(0, $remainingAmount), $availableBySalary);

    return [
        'monthly_salary' => $monthlySalary,
        'current_period_paid' => $currentPeriodPaid,
        'available_by_salary' => $availableBySalary,
        'remaining_amount' => max(0, $remainingAmount),
        'max_payment' => max(0, $maxPayment),
    ];
}


$employeeStmt = $pdo->query("
    SELECT id, full_name, employee_code, position, department, salary_type, salary_amount
    FROM employees
    WHERE employment_status = 'active'
    ORDER BY full_name ASC
");
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_component') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $componentType = $_POST['component_type'] ?? '';
            $componentName = trim($_POST['component_name'] ?? '');
            $amount = pm_normalize_money($_POST['amount'] ?? 0);
            $payrollMonth = $_POST['payroll_month'] ?? date('Y-m');
            $notes = trim($_POST['notes'] ?? '');
            $allowedTypes = ['allowance', 'bonus', 'deduction', 'tax'];

            if ($employeeId <= 0) throw new Exception('Karyawan wajib dipilih.');
            if (!in_array($componentType, $allowedTypes, true)) throw new Exception('Jenis komponen tidak valid.');
            if ($componentName === '') throw new Exception('Nama komponen wajib diisi.');
            if ($amount <= 0) throw new Exception('Nominal harus lebih dari 0.');
            if (!preg_match('/^\d{4}-\d{2}$/', $payrollMonth)) throw new Exception('Format bulan payroll tidak valid.');

            $stmt = $pdo->prepare("
                INSERT INTO payroll_components (employee_id, component_type, component_name, amount, payroll_month, notes)
                VALUES (:employee_id, :component_type, :component_name, :amount, :payroll_month, :notes)
            ");
            $stmt->execute([
                ':employee_id' => $employeeId,
                ':component_type' => $componentType,
                ':component_name' => $componentName,
                ':amount' => $amount,
                ':payroll_month' => $payrollMonth,
                ':notes' => $notes !== '' ? $notes : null
            ]);
            $newComponentId = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Payroll Management', 'CREATE_COMPONENT', 'Menambahkan komponen payroll "' . $componentName . '" periode ' . $payrollMonth . ' sebesar ' . pm_money($amount) . '.', $newComponentId, 'payroll_components', $currentUserId);
            pm_redirect('components', $payrollMonth, 'Komponen payroll berhasil ditambahkan.');
        }

        if ($action === 'delete_component') {
            $componentId = (int)($_POST['component_id'] ?? 0);
            if ($componentId <= 0) throw new Exception('Data komponen tidak valid.');
            $oldStmt = $pdo->prepare("SELECT component_name, amount, payroll_month FROM payroll_components WHERE id = :id LIMIT 1");
            $oldStmt->execute([':id' => $componentId]);
            $oldComponent = $oldStmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("DELETE FROM payroll_components WHERE id = :id");
            $stmt->execute([':id' => $componentId]);
            log_activity($pdo, 'Payroll Management', 'DELETE_COMPONENT', 'Menghapus komponen payroll "' . ($oldComponent['component_name'] ?? '-') . '" periode ' . ($oldComponent['payroll_month'] ?? $month) . ' sebesar ' . pm_money($oldComponent['amount'] ?? 0) . '.', $componentId, 'payroll_components', $currentUserId);
            pm_redirect('components', $month, 'Komponen payroll berhasil dihapus.');
        }

        if ($action === 'add_cash_advance') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $advanceDate = $_POST['advance_date'] ?? date('Y-m-d');
            $amount = pm_normalize_money($_POST['amount'] ?? 0);
            $deductionMonth = $_POST['deduction_month'] ?? date('Y-m');
            $notes = trim($_POST['notes'] ?? '');

            if ($employeeId <= 0) throw new Exception('Karyawan wajib dipilih.');
            if ($amount <= 0) throw new Exception('Nominal kasbon harus lebih dari 0.');
            if (!preg_match('/^\d{4}-\d{2}$/', $deductionMonth)) throw new Exception('Bulan pemotongan tidak valid.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $advanceDate)) throw new Exception('Tanggal kasbon tidak valid.');

            $proofPath = pm_upload_file('proof_file', 'cash_advances', 'cash_advance');
            $stmt = $pdo->prepare("
                INSERT INTO employee_cash_advances (employee_id, advance_date, amount, remaining_amount, deduction_month, proof_file, status, notes)
                VALUES (:employee_id, :advance_date, :amount, :remaining_amount, :deduction_month, :proof_file, 'active', :notes)
            ");
            $stmt->execute([
                ':employee_id' => $employeeId,
                ':advance_date' => $advanceDate,
                ':amount' => $amount,
                ':remaining_amount' => $amount,
                ':deduction_month' => $deductionMonth,
                ':proof_file' => $proofPath,
                ':notes' => $notes !== '' ? $notes : null
            ]);
            $newCashId = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Kasbon', 'CREATE', 'Menambahkan kasbon periode potong ' . $deductionMonth . ' sebesar ' . pm_money($amount) . '.', $newCashId, 'employee_cash_advances', $currentUserId);
            pm_redirect('cash', $deductionMonth, 'Kasbon berhasil ditambahkan.');
        }

        if (in_array($action, ['cash_mark_paid', 'cash_cancel', 'cash_delete'], true)) {
            $cashId = (int)($_POST['cash_id'] ?? 0);
            if ($cashId <= 0) throw new Exception('Data kasbon tidak valid.');

            if ($action === 'cash_mark_paid') {
                $stmt = $pdo->prepare("UPDATE employee_cash_advances SET status='paid', remaining_amount=0, updated_at=NOW() WHERE id=:id");
                $stmt->execute([':id' => $cashId]);
                log_activity($pdo, 'Kasbon', 'MARK_PAID', 'Menandai kasbon lunas.', $cashId, 'employee_cash_advances', $currentUserId);
                pm_redirect('cash', $month, 'Kasbon berhasil ditandai lunas.');
            }

            if ($action === 'cash_cancel') {
                $stmt = $pdo->prepare("UPDATE employee_cash_advances SET status='cancelled', updated_at=NOW() WHERE id=:id");
                $stmt->execute([':id' => $cashId]);
                log_activity($pdo, 'Kasbon', 'CANCEL', 'Membatalkan kasbon.', $cashId, 'employee_cash_advances', $currentUserId);
                pm_redirect('cash', $month, 'Kasbon berhasil dibatalkan.');
            }

            if ($action === 'cash_delete') {
                $fileStmt = $pdo->prepare("SELECT proof_file FROM employee_cash_advances WHERE id=:id LIMIT 1");
                $fileStmt->execute([':id' => $cashId]);
                $cash = $fileStmt->fetch(PDO::FETCH_ASSOC);
                if ($cash && !empty($cash['proof_file'])) {
                    $filePath = __DIR__ . '/../../' . $cash['proof_file'];
                    if (file_exists($filePath)) unlink($filePath);
                }
                $stmt = $pdo->prepare("DELETE FROM employee_cash_advances WHERE id=:id");
                $stmt->execute([':id' => $cashId]);
                log_activity($pdo, 'Kasbon', 'DELETE', 'Menghapus data kasbon permanen.', $cashId, 'employee_cash_advances', $currentUserId);
                pm_redirect('cash', $month, 'Data kasbon berhasil dihapus permanen.');
            }
        }

        if ($action === 'add_leave') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $leaveType = $_POST['leave_type'] ?? 'permission';
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $isPaid = $_POST['is_paid'] ?? 'yes';
            $reason = trim($_POST['reason'] ?? '');
            $allowedTypes = ['annual_leave', 'sick', 'permission', 'unpaid_leave', 'official_duty'];

            if ($employeeId <= 0) throw new Exception('Karyawan wajib dipilih.');
            if (!in_array($leaveType, $allowedTypes, true)) throw new Exception('Jenis pengajuan tidak valid.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) throw new Exception('Tanggal mulai tidak valid.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) throw new Exception('Tanggal selesai tidak valid.');
            if (strtotime($endDate) < strtotime($startDate)) throw new Exception('Tanggal selesai tidak boleh lebih awal dari tanggal mulai.');

            $proofPath = pm_upload_file('proof_file', 'leaves', 'leave');
            if ($leaveType === 'unpaid_leave') $isPaid = 'no';
            $totalDays = pm_calculate_leave_days($startDate, $endDate);

            $stmt = $pdo->prepare("
                INSERT INTO employee_leaves (employee_id, leave_type, start_date, end_date, total_days, is_paid, status, proof_file, reason)
                VALUES (:employee_id, :leave_type, :start_date, :end_date, :total_days, :is_paid, 'pending', :proof_file, :reason)
            ");
            $stmt->execute([
                ':employee_id' => $employeeId,
                ':leave_type' => $leaveType,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':total_days' => $totalDays,
                ':is_paid' => $isPaid,
                ':proof_file' => $proofPath,
                ':reason' => $reason !== '' ? $reason : null
            ]);
            $newLeaveId = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Cuti/Izin', 'CREATE', 'Menambahkan pengajuan ' . $leaveType . ' tanggal ' . $startDate . ' sampai ' . $endDate . ' (' . $totalDays . ' hari).', $newLeaveId, 'employee_leaves', $currentUserId);
            pm_redirect('leaves', date('Y-m', strtotime($startDate)), 'Pengajuan cuti/izin berhasil ditambahkan.');
        }

        if (in_array($action, ['leave_approve', 'leave_reject', 'leave_cancel', 'leave_delete'], true)) {
            $leaveId = (int)($_POST['leave_id'] ?? 0);
            $approvalNotes = trim($_POST['approval_notes'] ?? '');
            if ($leaveId <= 0) throw new Exception('Data pengajuan tidak valid.');

            if ($action === 'leave_approve') {
                $stmt = $pdo->prepare("UPDATE employee_leaves SET status='approved', approval_notes=:notes, approved_at=NOW(), updated_at=NOW() WHERE id=:id");
                $stmt->execute([':notes' => $approvalNotes !== '' ? $approvalNotes : null, ':id' => $leaveId]);
                log_activity($pdo, 'Cuti/Izin', 'APPROVE', 'Menyetujui pengajuan cuti/izin. Catatan: ' . ($approvalNotes !== '' ? $approvalNotes : '-'), $leaveId, 'employee_leaves', $currentUserId);
                pm_redirect('leaves', $month, 'Pengajuan berhasil disetujui.');
            }
            if ($action === 'leave_reject') {
                $stmt = $pdo->prepare("UPDATE employee_leaves SET status='rejected', approval_notes=:notes, updated_at=NOW() WHERE id=:id");
                $stmt->execute([':notes' => $approvalNotes !== '' ? $approvalNotes : null, ':id' => $leaveId]);
                log_activity($pdo, 'Cuti/Izin', 'REJECT', 'Menolak pengajuan cuti/izin. Catatan: ' . ($approvalNotes !== '' ? $approvalNotes : '-'), $leaveId, 'employee_leaves', $currentUserId);
                pm_redirect('leaves', $month, 'Pengajuan berhasil ditolak.');
            }
            if ($action === 'leave_cancel') {
                $stmt = $pdo->prepare("UPDATE employee_leaves SET status='cancelled', approval_notes=:notes, updated_at=NOW() WHERE id=:id");
                $stmt->execute([':notes' => $approvalNotes !== '' ? $approvalNotes : null, ':id' => $leaveId]);
                log_activity($pdo, 'Cuti/Izin', 'CANCEL', 'Membatalkan pengajuan cuti/izin. Catatan: ' . ($approvalNotes !== '' ? $approvalNotes : '-'), $leaveId, 'employee_leaves', $currentUserId);
                pm_redirect('leaves', $month, 'Pengajuan berhasil dibatalkan.');
            }
            if ($action === 'leave_delete') {
                $fileStmt = $pdo->prepare("SELECT proof_file FROM employee_leaves WHERE id=:id LIMIT 1");
                $fileStmt->execute([':id' => $leaveId]);
                $leave = $fileStmt->fetch(PDO::FETCH_ASSOC);
                if ($leave && !empty($leave['proof_file'])) {
                    $filePath = __DIR__ . '/../../' . $leave['proof_file'];
                    if (file_exists($filePath)) unlink($filePath);
                }
                $stmt = $pdo->prepare("DELETE FROM employee_leaves WHERE id=:id");
                $stmt->execute([':id' => $leaveId]);
                log_activity($pdo, 'Cuti/Izin', 'DELETE', 'Menghapus pengajuan cuti/izin permanen.', $leaveId, 'employee_leaves', $currentUserId);
                pm_redirect('leaves', $month, 'Data pengajuan berhasil dihapus permanen.');
            }
        }

        if ($action === 'add_loan') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $loanDate = $_POST['loan_date'] ?? date('Y-m-d');
            $loanAmount = pm_normalize_money($_POST['loan_amount'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($employeeId <= 0) throw new Exception('Karyawan wajib dipilih.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $loanDate)) throw new Exception('Tanggal pinjaman tidak valid.');
            if ($loanAmount <= 0) throw new Exception('Nominal pinjaman harus lebih dari 0.');

            $stmt = $pdo->prepare("
                INSERT INTO employee_loans (employee_id, loan_date, loan_amount, total_paid, remaining_amount, status, notes)
                VALUES (:employee_id, :loan_date, :loan_amount, 0, :remaining_amount, 'active', :notes)
            ");
            $stmt->execute([
                ':employee_id' => $employeeId,
                ':loan_date' => $loanDate,
                ':loan_amount' => $loanAmount,
                ':remaining_amount' => $loanAmount,
                ':notes' => $notes !== '' ? $notes : null
            ]);
            $newLoanId = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Loan', 'CREATE', 'Menambahkan pinjaman karyawan sebesar ' . pm_money($loanAmount) . ' tanggal ' . $loanDate . '.', $newLoanId, 'employee_loans', $currentUserId);
            pm_redirect('loans', $month, 'Pinjaman karyawan berhasil ditambahkan.');
        }

        if ($action === 'add_loan_payment') {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
            $paymentPeriod = $_POST['payment_period'] ?? $month;
            $paymentAmount = pm_normalize_money($_POST['payment_amount'] ?? 0);
            $notes = trim($_POST['payment_notes'] ?? '');

            if ($loanId <= 0) throw new Exception('Data pinjaman tidak valid.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) throw new Exception('Tanggal cicilan tidak valid.');
            if (!preg_match('/^\d{4}-\d{2}$/', $paymentPeriod)) throw new Exception('Periode cicilan tidak valid.');
            if ($paymentAmount <= 0) throw new Exception('Nominal cicilan harus lebih dari 0.');

            $pdo->beginTransaction();

            $loanStmt = $pdo->prepare("
                SELECT el.id, el.employee_id, el.remaining_amount, el.status,
                       e.full_name, COALESCE(e.salary_amount, 0) AS salary_amount
                FROM employee_loans el
                JOIN employees e ON el.employee_id = e.id
                WHERE el.id = :id
                FOR UPDATE
            ");
            $loanStmt->execute([':id' => $loanId]);
            $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);

            if (!$loan) throw new Exception('Pinjaman tidak ditemukan.');
            if ($loan['status'] !== 'active') throw new Exception('Cicilan hanya bisa ditambahkan untuk pinjaman aktif.');

            $remainingAmount = (float)$loan['remaining_amount'];
            $monthlySalary = max(0, (float)$loan['salary_amount']);
            if ($monthlySalary <= 0) {
                throw new Exception('Gaji karyawan belum diatur, cicilan pinjaman tidak dapat diproses.');
            }

            $currentPeriodPaid = pm_get_employee_loan_payment_total($pdo, (int)$loan['employee_id'], $paymentPeriod);
            $availableBySalary = max(0, $monthlySalary - $currentPeriodPaid);
            $maxAllowedPayment = min($remainingAmount, $availableBySalary);

            if ($paymentAmount > $remainingAmount) {
                throw new Exception('Nominal cicilan lebih besar dari sisa pinjaman. Sisa pinjaman saat ini: ' . pm_money($remainingAmount) . '.');
            }

            if ($paymentAmount > $availableBySalary) {
                throw new Exception(
                    'Cicilan melebihi batas gaji periode ini. Gaji: ' . pm_money($monthlySalary) .
                        ', cicilan yang sudah masuk periode ini: ' . pm_money($currentPeriodPaid) .
                        ', maksimal cicilan tambahan: ' . pm_money($maxAllowedPayment) . '.'
                );
            }

            if ($maxAllowedPayment <= 0) {
                throw new Exception('Tidak ada saldo gaji yang tersedia untuk cicilan pada periode ini.');
            }

            [$periodYear, $periodMonth] = explode('-', $paymentPeriod);
            $stmt = $pdo->prepare("
                INSERT INTO employee_loan_payments (loan_id, employee_id, payroll_id, payment_date, period_month, period_year, payment_amount, notes)
                VALUES (:loan_id, :employee_id, NULL, :payment_date, :period_month, :period_year, :payment_amount, :notes)
            ");
            $stmt->execute([
                ':loan_id' => $loanId,
                ':employee_id' => $loan['employee_id'],
                ':payment_date' => $paymentDate,
                ':period_month' => (int)$periodMonth,
                ':period_year' => (int)$periodYear,
                ':payment_amount' => $paymentAmount,
                ':notes' => $notes !== '' ? $notes : null
            ]);
            $newPaymentId = (int)$pdo->lastInsertId();
            log_activity($pdo, 'Loan', 'ADD_PAYMENT', 'Menambahkan cicilan pinjaman untuk ' . ($loan['full_name'] ?? '-') . ' periode ' . $paymentPeriod . ' sebesar ' . pm_money($paymentAmount) . '.', $newPaymentId, 'employee_loan_payments', $currentUserId);

            pm_recalculate_employee_loan($pdo, $loanId);
            $pdo->commit();

            pm_redirect('loans', $paymentPeriod, 'Cicilan pinjaman berhasil ditambahkan.');
        }

        if (in_array($action, ['loan_cancel', 'loan_reactivate', 'loan_delete'], true)) {
            $loanId = (int)($_POST['loan_id'] ?? 0);
            if ($loanId <= 0) throw new Exception('Data pinjaman tidak valid.');

            if ($action === 'loan_cancel') {
                $stmt = $pdo->prepare("UPDATE employee_loans SET status='cancelled', updated_at=NOW() WHERE id=:id");
                $stmt->execute([':id' => $loanId]);
                log_activity($pdo, 'Loan', 'CANCEL', 'Membatalkan pinjaman karyawan.', $loanId, 'employee_loans', $currentUserId);
                pm_redirect('loans', $month, 'Pinjaman berhasil dibatalkan.');
            }
            if ($action === 'loan_reactivate') {
                pm_recalculate_employee_loan($pdo, $loanId);
                log_activity($pdo, 'Loan', 'REACTIVATE', 'Mengaktifkan kembali pinjaman sesuai sisa pinjaman.', $loanId, 'employee_loans', $currentUserId);
                pm_redirect('loans', $month, 'Status pinjaman berhasil diaktifkan kembali sesuai sisa pinjaman.');
            }
            if ($action === 'loan_delete') {
                $stmt = $pdo->prepare("DELETE FROM employee_loans WHERE id=:id");
                $stmt->execute([':id' => $loanId]);
                log_activity($pdo, 'Loan', 'DELETE', 'Menghapus data pinjaman permanen.', $loanId, 'employee_loans', $currentUserId);
                pm_redirect('loans', $month, 'Data pinjaman berhasil dihapus permanen.');
            }
        }

        if ($action === 'delete_loan_payment') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $loanId = (int)($_POST['loan_id'] ?? 0);
            if ($paymentId <= 0 || $loanId <= 0) throw new Exception('Data cicilan tidak valid.');
            $stmt = $pdo->prepare("DELETE FROM employee_loan_payments WHERE id=:id");
            $stmt->execute([':id' => $paymentId]);
            log_activity($pdo, 'Loan', 'DELETE_PAYMENT', 'Menghapus cicilan pinjaman.', $paymentId, 'employee_loan_payments', $currentUserId);
            pm_recalculate_employee_loan($pdo, $loanId);
            pm_redirect('loans', $month, 'Cicilan pinjaman berhasil dihapus.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$periodStart = $month . '-01';
$periodEnd = date('Y-m-t', strtotime($periodStart));
$periodYear = (int)substr($month, 0, 4);
$periodMonth = (int)substr($month, 5, 2);

$componentStmt = $pdo->prepare("
    SELECT pc.*, e.full_name, e.employee_code, e.position, e.department
    FROM payroll_components pc
    JOIN employees e ON pc.employee_id = e.id
    WHERE pc.payroll_month = :month
    ORDER BY pc.created_at DESC, e.full_name ASC
");
$componentStmt->execute([':month' => $month]);
$components = $componentStmt->fetchAll(PDO::FETCH_ASSOC);
$componentSummary = ['allowance' => 0, 'bonus' => 0, 'deduction' => 0, 'tax' => 0, 'count' => count($components)];
foreach ($components as $component) {
    if (isset($componentSummary[$component['component_type']])) {
        $componentSummary[$component['component_type']] += (float)$component['amount'];
    }
}
$componentSummary['total_addition'] = $componentSummary['allowance'] + $componentSummary['bonus'];
$componentSummary['total_subtraction'] = $componentSummary['deduction'] + $componentSummary['tax'];

$cashStmt = $pdo->prepare("
    SELECT eca.*, e.full_name, e.employee_code, e.position, e.department
    FROM employee_cash_advances eca
    JOIN employees e ON eca.employee_id = e.id
    WHERE eca.deduction_month = :month
    ORDER BY eca.advance_date DESC, e.full_name ASC
");
$cashStmt->execute([':month' => $month]);
$cashAdvances = $cashStmt->fetchAll(PDO::FETCH_ASSOC);
$cashSummary = ['count' => count($cashAdvances), 'active' => 0, 'paid' => 0, 'cancelled' => 0, 'total_amount' => 0, 'total_remaining' => 0];
foreach ($cashAdvances as $cash) {
    if (isset($cashSummary[$cash['status']])) $cashSummary[$cash['status']]++;
    $cashSummary['total_amount'] += (float)$cash['amount'];
    if ($cash['status'] === 'active') $cashSummary['total_remaining'] += (float)$cash['remaining_amount'];
}

$leaveStmt = $pdo->prepare("
    SELECT el.*, e.full_name, e.employee_code, e.position, e.department
    FROM employee_leaves el
    JOIN employees e ON el.employee_id = e.id
    WHERE el.start_date <= :period_end AND el.end_date >= :period_start
    ORDER BY el.start_date DESC, e.full_name ASC
");
$leaveStmt->execute([':period_start' => $periodStart, ':period_end' => $periodEnd]);
$leaves = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
$leaveSummary = ['count' => count($leaves), 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'paid_days' => 0, 'unpaid_days' => 0];
foreach ($leaves as $leave) {
    if (isset($leaveSummary[$leave['status']])) $leaveSummary[$leave['status']]++;
    if ($leave['status'] === 'approved') {
        if ($leave['is_paid'] === 'yes') $leaveSummary['paid_days'] += (int)$leave['total_days'];
        else $leaveSummary['unpaid_days'] += (int)$leave['total_days'];
    }
}

$loanStmt = $pdo->prepare("
    SELECT el.*,
           e.full_name,
           e.employee_code,
           e.position,
           e.department,
           e.salary_type,
           COALESCE(e.salary_amount, 0) AS salary_amount,
           COALESCE(pp.period_total, 0) AS current_period_payment,
           COALESCE(ep.employee_period_total, 0) AS employee_period_payment
    FROM employee_loans el
    JOIN employees e ON el.employee_id = e.id
    LEFT JOIN (
        SELECT loan_id, SUM(payment_amount) AS period_total
        FROM employee_loan_payments
        WHERE period_year = :period_year AND period_month = :period_month
        GROUP BY loan_id
    ) pp ON pp.loan_id = el.id
    LEFT JOIN (
        SELECT employee_id, SUM(payment_amount) AS employee_period_total
        FROM employee_loan_payments
        WHERE period_year = :period_year AND period_month = :period_month
        GROUP BY employee_id
    ) ep ON ep.employee_id = el.employee_id
    ORDER BY FIELD(el.status, 'active', 'paid', 'cancelled'), el.loan_date DESC, e.full_name ASC
");
$loanStmt->execute([':period_year' => $periodYear, ':period_month' => $periodMonth]);
$loans = $loanStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($loans as &$loan) {
    $salaryLimit = max(0, (float)($loan['salary_amount'] ?? 0));
    $employeePeriodPayment = max(0, (float)($loan['employee_period_payment'] ?? 0));
    $availableBySalary = max(0, $salaryLimit - $employeePeriodPayment);
    $loan['available_by_salary'] = $availableBySalary;
    $loan['max_payment_period'] = min(max(0, (float)$loan['remaining_amount']), $availableBySalary);
    $loan['is_over_salary_limit'] = $employeePeriodPayment > $salaryLimit && $salaryLimit > 0;
}
unset($loan);
$loanSummary = ['count' => count($loans), 'active' => 0, 'paid' => 0, 'cancelled' => 0, 'total_loan' => 0, 'total_paid' => 0, 'total_remaining' => 0, 'period_payment' => 0];
foreach ($loans as $loan) {
    if (isset($loanSummary[$loan['status']])) $loanSummary[$loan['status']]++;
    $loanSummary['total_loan'] += (float)$loan['loan_amount'];
    $loanSummary['total_paid'] += (float)$loan['total_paid'];
    if ($loan['status'] === 'active') $loanSummary['total_remaining'] += (float)$loan['remaining_amount'];
    $loanSummary['period_payment'] += (float)$loan['current_period_payment'];
}

$paymentsStmt = $pdo->prepare("
    SELECT elp.*, e.full_name, e.employee_code, el.loan_amount
    FROM employee_loan_payments elp
    JOIN employees e ON elp.employee_id = e.id
    JOIN employee_loans el ON elp.loan_id = el.id
    WHERE elp.period_year = :period_year AND elp.period_month = :period_month
    ORDER BY elp.payment_date DESC, e.full_name ASC
");
$paymentsStmt->execute([':period_year' => $periodYear, ':period_month' => $periodMonth]);
$periodPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Payroll Management</h1>
            <p class="text-muted mb-0">Kelola komponen payroll, kasbon, cuti/izin, dan pinjaman dalam satu halaman.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/payroll/index.php?month=' . urlencode($month)); ?>" class="btn btn-outline-success"><i class="bi bi-cash-stack me-1"></i> Payroll Bulanan</a>
            <a href="<?= base_url('pages/payroll/settings.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-gear me-1"></i> Setting</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-3"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success rounded-3"><?= htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab); ?>">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Periode Payroll</label>
                <input type="month" name="month" value="<?= htmlspecialchars($month); ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Search Cepat</label>
                <input type="text" id="globalTableSearch" class="form-control" placeholder="Cari di tabel aktif...">
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-nukari"><i class="bi bi-search me-1"></i> Tampilkan</button>
            </div>
        </form>
    </div>

    <ul class="nav nav-pills gap-2 mb-4" id="payrollManagementTabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab === 'components' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-components" type="button">Komponen Payroll</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab === 'cash' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-cash" type="button">Kasbon</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab === 'leaves' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-leaves" type="button">Cuti / Izin</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link <?= $activeTab === 'loans' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tab-loans" type="button">Loans / Pinjaman</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade <?= $activeTab === 'components' ? 'show active' : ''; ?>" id="tab-components">
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card border-blue">
                        <div class="stat-title">Total Komponen</div>
                        <div class="stat-number"><?= pm_number($componentSummary['count']); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-green">
                        <div class="stat-title">Tunjangan + Bonus</div>
                        <div class="stat-number" style="font-size:22px;"><?= pm_money($componentSummary['total_addition']); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-red">
                        <div class="stat-title">Potongan + Pajak</div>
                        <div class="stat-number" style="font-size:22px;"><?= pm_money($componentSummary['total_subtraction']); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-orange">
                        <div class="stat-title">Bulan</div>
                        <div class="stat-number" style="font-size:22px;"><?= htmlspecialchars(date('M Y', strtotime($month . '-01'))); ?></div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Daftar Komponen Payroll</h5>
                        <p class="text-muted mb-0">Bonus, tunjangan, potongan manual, dan pajak manual.</p>
                    </div>
                    <button class="btn btn-nukari" data-bs-toggle="modal" data-bs-target="#modalAddComponent"><i class="bi bi-plus-circle me-1"></i> Tambah Komponen</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle searchable-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Karyawan</th>
                                <th>Bulan</th>
                                <th>Jenis</th>
                                <th>Nama</th>
                                <th>Nominal</th>
                                <th>Catatan</th>
                                <th>Dibuat</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($components): $no = 1;
                                foreach ($components as $component): ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($component['full_name']); ?></div><small class="text-muted"><?= htmlspecialchars($component['employee_code'] ?? '-'); ?> · <?= htmlspecialchars($component['position'] ?? '-'); ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($component['payroll_month']); ?></td>
                                        <td><span class="badge rounded-pill <?= pm_component_type_badge($component['component_type']); ?>"><?= pm_component_type_label($component['component_type']); ?></span></td>
                                        <td><?= htmlspecialchars($component['component_name']); ?></td>
                                        <td class="<?= in_array($component['component_type'], ['deduction', 'tax'], true) ? 'text-danger' : 'text-success'; ?> fw-semibold"><?= pm_money($component['amount']); ?></td>
                                        <td><?= !empty($component['notes']) ? nl2br(htmlspecialchars($component['notes'])) : '<span class="text-muted">-</span>'; ?></td>
                                        <td><?= date('d M Y H:i', strtotime($component['created_at'])); ?></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Hapus komponen payroll ini?');">
                                                <input type="hidden" name="action" value="delete_component"><input type="hidden" name="tab" value="components"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="component_id" value="<?= (int)$component['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">Belum ada komponen payroll untuk bulan ini.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab === 'cash' ? 'show active' : ''; ?>" id="tab-cash">
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card border-blue">
                        <div class="stat-title">Total Kasbon</div>
                        <div class="stat-number"><?= (int)$cashSummary['count']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-orange">
                        <div class="stat-title">Aktif</div>
                        <div class="stat-number"><?= (int)$cashSummary['active']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-red">
                        <div class="stat-title">Total Potongan</div>
                        <div class="stat-number" style="font-size:22px;"><?= pm_money($cashSummary['total_amount']); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-green">
                        <div class="stat-title">Sisa Aktif</div>
                        <div class="stat-number" style="font-size:22px;"><?= pm_money($cashSummary['total_remaining']); ?></div>
                    </div>
                </div>
            </div>
            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Daftar Kasbon</h5>
                        <p class="text-muted mb-0">Kasbon sekali potong sesuai bulan pemotongan.</p>
                    </div>
                    <button class="btn btn-nukari" data-bs-toggle="modal" data-bs-target="#modalAddCash"><i class="bi bi-plus-circle me-1"></i> Tambah Kasbon</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle searchable-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Karyawan</th>
                                <th>Tanggal</th>
                                <th>Bulan Potong</th>
                                <th>Nominal</th>
                                <th>Sisa</th>
                                <th>Status</th>
                                <th>Bukti</th>
                                <th>Catatan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($cashAdvances): $no = 1;
                                foreach ($cashAdvances as $cash): ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($cash['full_name']); ?></div><small class="text-muted"><?= htmlspecialchars($cash['employee_code'] ?? '-'); ?> · <?= htmlspecialchars($cash['position'] ?? '-'); ?></small>
                                        </td>
                                        <td><?= pm_safe_date($cash['advance_date']); ?></td>
                                        <td><?= htmlspecialchars($cash['deduction_month']); ?></td>
                                        <td class="fw-semibold text-danger"><?= pm_money($cash['amount']); ?></td>
                                        <td><?= pm_money($cash['remaining_amount']); ?></td>
                                        <td><?= pm_status_badge($cash['status']); ?></td>
                                        <td><?php if (!empty($cash['proof_file'])): ?><a href="<?= base_url($cash['proof_file']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Lihat</a><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                        <td><?= !empty($cash['notes']) ? nl2br(htmlspecialchars($cash['notes'])) : '<span class="text-muted">-</span>'; ?></td>
                                        <td class="text-nowrap">
                                            <?php if ($cash['status'] === 'active'): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Tandai kasbon ini lunas?');"><input type="hidden" name="action" value="cash_mark_paid"><input type="hidden" name="tab" value="cash"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="cash_id" value="<?= (int)$cash['id']; ?>"><button class="btn btn-sm btn-outline-success"><i class="bi bi-check2"></i></button></form>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Batalkan kasbon ini?');"><input type="hidden" name="action" value="cash_cancel"><input type="hidden" name="tab" value="cash"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="cash_id" value="<?= (int)$cash['id']; ?>"><button class="btn btn-sm btn-outline-warning"><i class="bi bi-x-circle"></i></button></form>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus permanen kasbon ini?');"><input type="hidden" name="action" value="cash_delete"><input type="hidden" name="tab" value="cash"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="cash_id" value="<?= (int)$cash['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">Belum ada data kasbon untuk bulan ini.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab === 'leaves' ? 'show active' : ''; ?>" id="tab-leaves">
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card border-blue">
                        <div class="stat-title">Total Pengajuan</div>
                        <div class="stat-number"><?= (int)$leaveSummary['count']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-orange">
                        <div class="stat-title">Pending</div>
                        <div class="stat-number"><?= (int)$leaveSummary['pending']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-green">
                        <div class="stat-title">Approved</div>
                        <div class="stat-number"><?= (int)$leaveSummary['approved']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-red">
                        <div class="stat-title">Unpaid Leave</div>
                        <div class="stat-number"><?= (int)$leaveSummary['unpaid_days']; ?> hari</div>
                    </div>
                </div>
            </div>
            <div class="panel">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Daftar Cuti / Izin</h5>
                        <p class="text-muted mb-0">Pengajuan cuti, izin, sakit, dinas luar, dan izin tidak dibayar.</p>
                    </div>
                    <button class="btn btn-nukari" data-bs-toggle="modal" data-bs-target="#modalAddLeave"><i class="bi bi-plus-circle me-1"></i> Tambah Pengajuan</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle searchable-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Karyawan</th>
                                <th>Jenis</th>
                                <th>Tanggal</th>
                                <th>Hari</th>
                                <th>Dibayar</th>
                                <th>Status</th>
                                <th>Alasan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($leaves): $no = 1;
                                foreach ($leaves as $leave): ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($leave['full_name']); ?></div><small class="text-muted"><?= htmlspecialchars($leave['employee_code'] ?? '-'); ?> · <?= htmlspecialchars($leave['position'] ?? '-'); ?></small>
                                        </td>
                                        <td><?= pm_leave_type_label($leave['leave_type']); ?></td>
                                        <td><?= pm_safe_date($leave['start_date']); ?> - <?= pm_safe_date($leave['end_date']); ?></td>
                                        <td><?= (int)$leave['total_days']; ?></td>
                                        <td><?= $leave['is_paid'] === 'yes' ? '<span class="badge rounded-pill badge-soft-green">Ya</span>' : '<span class="badge rounded-pill badge-soft-red">Tidak</span>'; ?></td>
                                        <td><?= pm_status_badge($leave['status']); ?></td>
                                        <td><?= !empty($leave['reason']) ? nl2br(htmlspecialchars($leave['reason'])) : '<span class="text-muted">-</span>'; ?></td>
                                        <td class="text-nowrap">
                                            <?php if ($leave['status'] === 'pending'): ?>
                                                <form method="POST" class="d-inline"><input type="hidden" name="action" value="leave_approve"><input type="hidden" name="tab" value="leaves"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>"><button class="btn btn-sm btn-outline-success"><i class="bi bi-check2"></i></button></form>
                                                <form method="POST" class="d-inline"><input type="hidden" name="action" value="leave_reject"><input type="hidden" name="tab" value="leaves"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></form>
                                            <?php endif; ?>
                                            <?php if ($leave['status'] !== 'cancelled'): ?><form method="POST" class="d-inline" onsubmit="return confirm('Batalkan pengajuan ini?');"><input type="hidden" name="action" value="leave_cancel"><input type="hidden" name="tab" value="leaves"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>"><button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise"></i></button></form><?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus permanen pengajuan ini?');"><input type="hidden" name="action" value="leave_delete"><input type="hidden" name="tab" value="leaves"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">Belum ada data cuti/izin pada periode ini.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?= $activeTab === 'loans' ? 'show active' : ''; ?>" id="tab-loans">
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card border-blue">
                        <div class="stat-title">Total Pinjaman</div>
                        <div class="stat-number"><?= (int)$loanSummary['count']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-orange">
                        <div class="stat-title">Aktif</div>
                        <div class="stat-number"><?= (int)$loanSummary['active']; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-red">
                        <div class="stat-title">Sisa Aktif</div>
                        <div class="stat-number" style="font-size:22px;"><?= pm_money($loanSummary['total_remaining']); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card border-green">
                        <div class="stat-title">Cicilan Periode Ini</div>
                        <div class="stat-number" style="font-size:22px;"><?= pm_money($loanSummary['period_payment']); ?></div>
                    </div>
                </div>
            </div>
            <div class="panel mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Daftar Pinjaman</h5>
                        <p class="text-muted mb-0">Pinjaman karyawan dengan cicilan fleksibel per bulan.</p>
                    </div>
                    <button class="btn btn-nukari" data-bs-toggle="modal" data-bs-target="#modalAddLoan"><i class="bi bi-plus-circle me-1"></i> Tambah Pinjaman</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle searchable-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Karyawan</th>
                                <th>Tanggal</th>
                                <th>Pinjaman</th>
                                <th>Terbayar</th>
                                <th>Sisa</th>
                                <th>Cicilan Bulan Ini</th>
                                <th>Maks Cicil</th>
                                <th>Status</th>
                                <th>Catatan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($loans): $no = 1;
                                foreach ($loans as $loan): ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($loan['full_name']); ?></div><small class="text-muted"><?= htmlspecialchars($loan['employee_code'] ?? '-'); ?> · <?= htmlspecialchars($loan['position'] ?? '-'); ?></small>
                                        </td>
                                        <td><?= pm_safe_date($loan['loan_date']); ?></td>
                                        <td class="fw-semibold"><?= pm_money($loan['loan_amount']); ?></td>
                                        <td class="text-success fw-semibold"><?= pm_money($loan['total_paid']); ?></td>
                                        <td class="text-danger fw-semibold"><?= pm_money($loan['remaining_amount']); ?></td>
                                        <td>
                                            <?= pm_money($loan['current_period_payment']); ?>
                                            <?php if (!empty($loan['is_over_salary_limit'])): ?>
                                                <div><small class="text-danger fw-semibold">Melebihi gaji periode ini</small></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="fw-semibold <?= ((float)$loan['max_payment_period'] <= 0 && $loan['status'] === 'active') ? 'text-danger' : 'text-success'; ?>">
                                                <?= pm_money($loan['max_payment_period'] ?? 0); ?>
                                            </span>
                                            <div><small class="text-muted">Gaji: <?= pm_money($loan['salary_amount'] ?? 0); ?></small></div>
                                        </td>
                                        <td><?= pm_status_badge($loan['status']); ?></td>
                                        <td><?= !empty($loan['notes']) ? nl2br(htmlspecialchars($loan['notes'])) : '<span class="text-muted">-</span>'; ?></td>
                                        <td class="text-nowrap">
                                            <?php if ($loan['status'] === 'active'): ?>
                                                <?php if ((float)($loan['max_payment_period'] ?? 0) > 0): ?>
                                                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalAddPayment<?= (int)$loan['id']; ?>"><i class="bi bi-plus"></i> Cicil</button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Tidak ada batas gaji tersedia untuk cicilan periode ini"><i class="bi bi-lock"></i> Cicil</button>
                                                <?php endif; ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Batalkan pinjaman ini?');"><input type="hidden" name="action" value="loan_cancel"><input type="hidden" name="tab" value="loans"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>"><button class="btn btn-sm btn-outline-warning"><i class="bi bi-x-circle"></i></button></form>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline"><input type="hidden" name="action" value="loan_reactivate"><input type="hidden" name="tab" value="loans"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i></button></form>
                                            <?php endif; ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus permanen pinjaman ini? Semua histori cicilan juga akan terhapus.');"><input type="hidden" name="action" value="loan_delete"><input type="hidden" name="tab" value="loans"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">Belum ada data pinjaman.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel">
                <h5 class="fw-bold mb-3">Histori Cicilan Periode <?= htmlspecialchars(date('M Y', strtotime($month . '-01'))); ?></h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle searchable-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Karyawan</th>
                                <th>Tanggal</th>
                                <th>Nominal</th>
                                <th>Catatan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($periodPayments): $no = 1;
                                foreach ($periodPayments as $payment): ?>
                                    <tr>
                                        <td><?= $no++; ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($payment['full_name']); ?></div><small class="text-muted"><?= htmlspecialchars($payment['employee_code'] ?? '-'); ?></small>
                                        </td>
                                        <td><?= pm_safe_date($payment['payment_date']); ?></td>
                                        <td class="text-success fw-semibold"><?= pm_money($payment['payment_amount']); ?></td>
                                        <td><?= !empty($payment['notes']) ? nl2br(htmlspecialchars($payment['notes'])) : '<span class="text-muted">-</span>'; ?></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Hapus cicilan ini?');"><input type="hidden" name="action" value="delete_loan_payment"><input type="hidden" name="tab" value="loans"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>"><input type="hidden" name="loan_id" value="<?= (int)$payment['loan_id']; ?>"><input type="hidden" name="payment_id" value="<?= (int)$payment['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Belum ada cicilan pada periode ini.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="modalAddComponent" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content"><input type="hidden" name="action" value="add_component"><input type="hidden" name="tab" value="components">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Tambah Komponen Payroll</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold">Karyawan</label><select name="employee_id" class="form-select" required>
                            <option value="">Pilih Karyawan</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id']; ?>"><?= htmlspecialchars($employee['full_name']); ?><?= !empty($employee['employee_code']) ? ' - ' . htmlspecialchars($employee['employee_code']) : ''; ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Bulan</label><input type="month" name="payroll_month" class="form-control" value="<?= htmlspecialchars($month); ?>" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Jenis</label><select name="component_type" class="form-select" required>
                            <option value="allowance">Tunjangan</option>
                            <option value="bonus">Bonus</option>
                            <option value="deduction">Potongan</option>
                            <option value="tax">Pajak</option>
                        </select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Nama Komponen</label><input type="text" name="component_name" class="form-control" placeholder="Contoh: Bonus Project" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Nominal</label><input type="text" name="amount" class="form-control money-input" placeholder="250.000" required></div>
                    <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-nukari">Simpan</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalAddCash" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" enctype="multipart/form-data" class="modal-content"><input type="hidden" name="action" value="add_cash_advance"><input type="hidden" name="tab" value="cash">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Tambah Kasbon</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold">Karyawan</label><select name="employee_id" class="form-select" required>
                            <option value="">Pilih Karyawan</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id']; ?>"><?= htmlspecialchars($employee['full_name']); ?><?= !empty($employee['employee_code']) ? ' - ' . htmlspecialchars($employee['employee_code']) : ''; ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Tanggal Kasbon</label><input type="date" name="advance_date" class="form-control" value="<?= date('Y-m-d'); ?>" required></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Bulan Potong</label><input type="month" name="deduction_month" class="form-control" value="<?= htmlspecialchars($month); ?>" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Nominal</label><input type="text" name="amount" class="form-control money-input" placeholder="200.000" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Bukti</label><input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf"></div>
                    <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-nukari">Simpan</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalAddLeave" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" enctype="multipart/form-data" class="modal-content"><input type="hidden" name="action" value="add_leave"><input type="hidden" name="tab" value="leaves">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Tambah Cuti / Izin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold">Karyawan</label><select name="employee_id" class="form-select" required>
                            <option value="">Pilih Karyawan</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id']; ?>"><?= htmlspecialchars($employee['full_name']); ?><?= !empty($employee['employee_code']) ? ' - ' . htmlspecialchars($employee['employee_code']) : ''; ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Jenis</label><select name="leave_type" id="leaveTypeModal" class="form-select" required>
                            <option value="annual_leave">Cuti Tahunan</option>
                            <option value="sick">Sakit</option>
                            <option value="permission">Izin</option>
                            <option value="unpaid_leave">Izin Tidak Dibayar</option>
                            <option value="official_duty">Dinas Luar</option>
                        </select></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Mulai</label><input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d'); ?>" required></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Selesai</label><input type="date" name="end_date" class="form-control" value="<?= date('Y-m-d'); ?>" required></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Dibayar?</label><select name="is_paid" id="isPaidModal" class="form-select">
                            <option value="yes">Ya</option>
                            <option value="no">Tidak</option>
                        </select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Bukti</label><input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf"></div>
                    <div class="col-md-12"><label class="form-label fw-semibold">Alasan</label><textarea name="reason" class="form-control" rows="3"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-nukari">Simpan</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalAddLoan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content"><input type="hidden" name="action" value="add_loan"><input type="hidden" name="tab" value="loans"><input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Tambah Pinjaman</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold">Karyawan</label><select name="employee_id" class="form-select" required>
                            <option value="">Pilih Karyawan</option><?php foreach ($employees as $employee): ?><option value="<?= (int)$employee['id']; ?>"><?= htmlspecialchars($employee['full_name']); ?><?= !empty($employee['employee_code']) ? ' - ' . htmlspecialchars($employee['employee_code']) : ''; ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Tanggal Pinjaman</label><input type="date" name="loan_date" class="form-control" value="<?= date('Y-m-d'); ?>" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Nominal Pinjaman</label><input type="text" name="loan_amount" class="form-control money-input" placeholder="5.000.000" required></div>
                    <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" class="form-control" rows="3"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-nukari">Simpan</button></div>
        </form>
    </div>
</div>

<?php foreach ($loans as $loan): ?>
    <?php
    $loanMaxPayment = (float)($loan['max_payment_period'] ?? 0);
    $loanSalary = (float)($loan['salary_amount'] ?? 0);
    $loanEmployeePeriodPayment = (float)($loan['employee_period_payment'] ?? 0);
    $loanRemaining = (float)($loan['remaining_amount'] ?? 0);
    ?>
    <div class="modal fade" id="modalAddPayment<?= (int)$loan['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" class="modal-content loan-payment-form" data-max-payment="<?= htmlspecialchars((string)$loanMaxPayment); ?>">
                <input type="hidden" name="action" value="add_loan_payment">
                <input type="hidden" name="tab" value="loans">
                <input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold mb-1">Tambah Cicilan Pinjaman</h5>
                        <div class="text-muted small"><?= htmlspecialchars($loan['full_name']); ?> · <?= htmlspecialchars($loan['employee_code'] ?? '-'); ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <div class="p-3 rounded-4 border bg-light h-100">
                                <div class="text-muted small fw-semibold">Sisa Pinjaman</div>
                                <div class="fw-bold text-danger"><?= pm_money($loanRemaining); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 rounded-4 border bg-light h-100">
                                <div class="text-muted small fw-semibold">Gaji Bulanan</div>
                                <div class="fw-bold"><?= pm_money($loanSalary); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 rounded-4 border bg-light h-100">
                                <div class="text-muted small fw-semibold">Sudah Dicicil Bulan Ini</div>
                                <div class="fw-bold text-success"><?= pm_money($loanEmployeePeriodPayment); ?></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="p-3 rounded-4 border <?= $loanMaxPayment > 0 ? 'border-success bg-light' : 'border-danger bg-light'; ?> h-100">
                                <div class="text-muted small fw-semibold">Maksimal Cicilan Tambahan</div>
                                <div class="fw-bold <?= $loanMaxPayment > 0 ? 'text-success' : 'text-danger'; ?>"><?= pm_money($loanMaxPayment); ?></div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($loan['is_over_salary_limit'])): ?>
                        <div class="alert alert-danger rounded-4">
                            Cicilan periode ini sudah melebihi gaji bulanan karyawan. Hapus/koreksi cicilan lama terlebih dahulu sebelum menambah cicilan baru.
                        </div>
                    <?php elseif ($loanMaxPayment <= 0): ?>
                        <div class="alert alert-warning rounded-4">
                            Tidak ada saldo gaji yang tersedia untuk cicilan tambahan pada periode ini.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info rounded-4 mb-3">
                            Sistem akan menolak cicilan yang melebihi <strong>sisa pinjaman</strong> atau melebihi <strong>gaji bulanan pada periode payroll ini</strong>.
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tanggal Bayar</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Periode Payroll</label>
                            <input type="month" name="payment_period" class="form-control" value="<?= htmlspecialchars($month); ?>" required>
                            <small class="text-muted">Batas maksimal mengikuti periode yang sedang dibuka.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Nominal Cicilan</label>
                            <input type="text" name="payment_amount" class="form-control money-input loan-payment-amount" placeholder="<?= $loanMaxPayment > 0 ? htmlspecialchars(pm_number($loanMaxPayment)) : '0'; ?>" <?= $loanMaxPayment <= 0 ? 'disabled' : 'required'; ?>>
                            <small class="loan-payment-warning text-danger fw-semibold d-none">Nominal melebihi batas maksimal.</small>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Catatan</label>
                            <textarea name="payment_notes" class="form-control" rows="3" placeholder="Contoh: Cicilan pinjaman periode <?= htmlspecialchars(date('M Y', strtotime($month . '-01'))); ?>"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-nukari" <?= $loanMaxPayment <= 0 ? 'disabled' : ''; ?>>Simpan Cicilan</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const globalSearch = document.getElementById('globalTableSearch');
        if (globalSearch) {
            globalSearch.addEventListener('keyup', function() {
                const keyword = this.value.toLowerCase();
                const activePane = document.querySelector('.tab-pane.show.active');
                if (!activePane) return;
                activePane.querySelectorAll('.searchable-table tbody tr').forEach(function(row) {
                    row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
                });
            });
        }

        document.querySelectorAll('.money-input').forEach(function(input) {
            input.addEventListener('input', function() {
                let value = this.value.replace(/[^0-9]/g, '');
                this.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            });
        });

        const leaveType = document.getElementById('leaveTypeModal');
        const isPaid = document.getElementById('isPaidModal');
        if (leaveType && isPaid) {
            leaveType.addEventListener('change', function() {
                if (this.value === 'unpaid_leave') {
                    isPaid.value = 'no';
                    isPaid.setAttribute('disabled', 'disabled');
                } else {
                    isPaid.removeAttribute('disabled');
                }
            });
        }

        function parseRupiah(value) {
            return parseFloat(String(value || '').replace(/[^0-9]/g, '')) || 0;
        }

        document.querySelectorAll('.loan-payment-form').forEach(function(form) {
            const maxPayment = parseFloat(form.dataset.maxPayment || '0');
            const amountInput = form.querySelector('.loan-payment-amount');
            const warning = form.querySelector('.loan-payment-warning');
            const submitBtn = form.querySelector('button[type="submit"]');

            if (!amountInput) return;

            function validateLoanPayment() {
                const amount = parseRupiah(amountInput.value);
                const invalid = amount > maxPayment || amount <= 0;

                if (warning) {
                    warning.classList.toggle('d-none', !invalid);
                    if (amount > maxPayment) {
                        warning.textContent = 'Nominal melebihi batas maksimal cicilan.';
                    } else if (amount <= 0) {
                        warning.textContent = 'Nominal cicilan wajib diisi.';
                    }
                }

                if (submitBtn) {
                    submitBtn.disabled = invalid || maxPayment <= 0;
                }

                amountInput.classList.toggle('is-invalid', invalid && amountInput.value !== '');
            }

            amountInput.addEventListener('input', validateLoanPayment);
            form.addEventListener('submit', function(event) {
                validateLoanPayment();
                if (submitBtn && submitBtn.disabled) {
                    event.preventDefault();
                }
            });
        });
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>