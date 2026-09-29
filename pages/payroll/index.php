<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payroll_workforce_helper.php';
require_once __DIR__ . '/../../includes/attendance_overtime_helper.php';
require_once __DIR__ . '/../../includes/payroll_period_helper.php';
require_once __DIR__ . '/../../includes/payroll_additions_helper.php';
require_once __DIR__ . '/../../includes/payroll_loan_helper.php';

attendance_ensure_extended_schema($pdo);
payroll_ensure_fractional_attendance_days($pdo);
payroll_ensure_additions_schema($pdo);
payroll_ensure_loan_policy_schema($pdo);

$pageTitle = 'Payroll - Nukari';

$error = '';
$success = '';

$month = $_GET['month'] ?? $_POST['month'] ?? date('Y-m');
$keyword = trim($_GET['keyword'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$bpjsHealthPercent = (float)($_GET['bpjs_health_percent'] ?? $_POST['bpjs_health_percent'] ?? 0);
$bpjsEmploymentPercent = (float)($_GET['bpjs_employment_percent'] ?? $_POST['bpjs_employment_percent'] ?? 0);
$taxPercent = (float)($_GET['tax_percent'] ?? $_POST['tax_percent'] ?? 0);
$overtimeMultiplier = (float)($_GET['overtime_multiplier'] ?? $_POST['overtime_multiplier'] ?? 1.5);

if ($overtimeMultiplier <= 0) {
    $overtimeMultiplier = 1.5;
}

function count_work_days($startDate, $endDate)
{
    // Tidak ada setting hari libur pada payroll harian. Nilai ini hanya
    // menunjukkan jumlah hari kalender dalam periode tutup buku.
    return (int)floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
}

function count_work_days_overlap($startDate, $endDate, $periodStart, $periodEnd)
{
    $start = max(strtotime($startDate), strtotime($periodStart));
    $end = min(strtotime($endDate), strtotime($periodEnd));

    if ($end < $start) {
        return 0;
    }

    // Seluruh hari kalender bernilai sama, termasuk Sabtu dan Minggu.
    return (int)floor(($end - $start) / 86400) + 1;
}

function payroll_minutes_to_hour($minutes)
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

function payroll_number($value)
{
    return number_format((float)$value, 0, ',', '.');
}

function payroll_money($value)
{
    if (function_exists('rupiah')) {
        return rupiah($value);
    }

    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function payroll_day_number($value)
{
    $formatted = number_format((float)$value, 2, ',', '.');
    return rtrim(rtrim($formatted, '0'), ',');
}

function get_component_totals($pdo, $employeeId, $month)
{
    $stmt = $pdo->prepare("
        SELECT component_type, SUM(amount) AS total
        FROM payroll_components
        WHERE employee_id = :employee_id
        AND payroll_month = :payroll_month
        GROUP BY component_type
    ");

    $stmt->execute([
        ':employee_id' => $employeeId,
        ':payroll_month' => $month
    ]);

    $result = [
        'allowance' => 0,
        'bonus' => 0,
        'deduction' => 0,
        'tax' => 0
    ];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($result[$row['component_type']])) {
            $result[$row['component_type']] = (float)$row['total'];
        }
    }

    return $result;
}

function get_cash_advance_total($pdo, $employeeId, $month)
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(remaining_amount), 0) AS total_cash_advance
        FROM employee_cash_advances
        WHERE employee_id = :employee_id
        AND deduction_month = :deduction_month
        AND status = 'active'
    ");

    $stmt->execute([
        ':employee_id' => $employeeId,
        ':deduction_month' => $month
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (float)($row['total_cash_advance'] ?? 0);
}


function get_loan_deduction_total($pdo, $employeeId, $month)
{
    [$year, $monthNumber] = explode('-', $month);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) AS total_loan_deduction
        FROM employee_loan_payments
        WHERE employee_id = :employee_id
        AND period_month = :period_month
        AND period_year = :period_year
    ");

    $stmt->execute([
        ':employee_id' => $employeeId,
        ':period_month' => (int)$monthNumber,
        ':period_year' => (int)$year
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (float)($row['total_loan_deduction'] ?? 0);
}

function sync_loan_payments_to_payroll($pdo, $employeeId, $month, $payrollId)
{
    [$year, $monthNumber] = explode('-', $month);

    $stmt = $pdo->prepare("
        UPDATE employee_loan_payments
        SET payroll_id = :payroll_id
        WHERE employee_id = :employee_id
        AND period_month = :period_month
        AND period_year = :period_year
    ");

    $stmt->execute([
        ':payroll_id' => $payrollId,
        ':employee_id' => $employeeId,
        ':period_month' => (int)$monthNumber,
        ':period_year' => (int)$year
    ]);
}

function get_leave_totals($pdo, $employeeId, $periodStart, $periodEnd)
{
    $stmt = $pdo->prepare("
        SELECT start_date, end_date, is_paid, leave_type
        FROM employee_leaves
        WHERE employee_id = :employee_id
        AND status = 'approved'
        AND start_date <= :period_end
        AND end_date >= :period_start
    ");

    $stmt->execute([
        ':employee_id' => $employeeId,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd
    ]);

    $paidLeaveDays = 0;
    $unpaidLeaveDays = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
        $days = count_work_days_overlap(
            $leave['start_date'],
            $leave['end_date'],
            $periodStart,
            $periodEnd
        );

        if ($leave['is_paid'] === 'yes') {
            $paidLeaveDays += $days;
        } else {
            $unpaidLeaveDays += $days;
        }
    }

    return [
        'paid_leave_days' => $paidLeaveDays,
        'unpaid_leave_days' => $unpaidLeaveDays
    ];
}

function calculate_employee_payroll($pdo, $employee, $month, $periodStart, $periodEnd, $totalWorkDays, $bpjsHealthPercent, $bpjsEmploymentPercent, $taxPercent, $overtimeMultiplier)
{
    $attendanceStmt = $pdo->prepare("
        SELECT attendance_date, status, check_in, check_out,
               attendance_type, attendance_day_value,
               overtime_in, overtime_out, overtime_minutes,
               overtime_method, overtime_rate, overtime_amount,
               late_minutes, early_leave_minutes
        FROM attendances
        WHERE employee_id = :employee_id
          AND attendance_date BETWEEN :period_start AND :period_end
    ");

    $attendanceStmt->execute([
        ':employee_id' => $employee['id'],
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd
    ]);

    $rawPresentDays = 0.0;
    $recordedAbsent = 0;
    $lateMinutes = 0;
    $earlyLeaveMinutes = 0;
    $overtimeMinutes = 0;
    $recordedOvertimeAmount = 0.0;

    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendanceRow) {
        $status = $attendanceRow['status'] ?? 'present';
        if ($status === 'absent') {
            $recordedAbsent++;
        }
        // Nilai 0 / 0,5 / 1 disimpan oleh helper Absensi dan menjadi satu-satunya
        // sumber hari hadir untuk Absensi, HR, Project, Dashboard, dan Payroll.
        $rawPresentDays += max(0.0, min(1.0, (float)($attendanceRow['attendance_day_value'] ?? 0)));

        $lateMinutes += (int)($attendanceRow['late_minutes'] ?? 0);
        $earlyLeaveMinutes += (int)($attendanceRow['early_leave_minutes'] ?? 0);

        // Payroll memakai snapshot lembur yang tersimpan pada setiap absensi.
        // Jamnya hanya masuk ke ringkasan ketika nominal lembur tanggal tersebut
        // sudah diisi, sehingga jam dan nilai lembur selalu mewakili data yang sama.
        $recordedOvertimeAmountForDay = (float)($attendanceRow['overtime_amount'] ?? 0);
        if ($recordedOvertimeAmountForDay > 0) {
            $recordedOvertimeMinutes = (int)($attendanceRow['overtime_minutes'] ?? 0);
            if ($recordedOvertimeMinutes <= 0 && !empty($attendanceRow['overtime_in']) && !empty($attendanceRow['overtime_out'])) {
                $recordedOvertimeMinutes = attendance_minutes($attendanceRow['overtime_in'], $attendanceRow['overtime_out']);
            }
            $overtimeMinutes += $recordedOvertimeMinutes;
        }
        $recordedOvertimeAmount += $recordedOvertimeAmountForDay;
    }

    // Cuti/izin dan alpha tetap berada di modul HR, bukan komponen payroll.
    $paidLeaveDays = 0;
    $unpaidLeaveDays = 0;

    // Kehadiran payroll tidak boleh melebihi total hari kerja.
    $presentDays = min((float)$totalWorkDays, max(0.0, $rawPresentDays));
    $absentDays = $recordedAbsent;

    $salaryType = $employee['salary_type'] ?? 'monthly';
    $salaryAmount = (float)($employee['salary_amount'] ?? 0);

    $projectAllocations = function_exists('payroll_get_project_worker_allocations')
        ? payroll_get_project_worker_allocations($pdo, (int)$employee['id'], $periodStart, $periodEnd)
        : [];
    $projectSummary = function_exists('payroll_summarize_project_allocations')
        ? payroll_summarize_project_allocations($projectAllocations)
        : ['project_work_days' => 0, 'project_worker_amount' => 0, 'project_count' => 0];

    $projectWorkDays = (float)($projectSummary['project_work_days'] ?? 0);
    $projectWorkerAmount = (float)($projectSummary['project_worker_amount'] ?? 0);
    $workshopDays = 0;
    $workshopAmount = 0;

    if ($salaryType === 'monthly') {
        // Karyawan bulanan tetap memakai gaji pokok bulanan.
        // Alokasi project dicatat untuk pembebanan biaya project, bukan menambah slip payroll otomatis.
        $baseSalary = $salaryAmount;
        $dailyRate = $totalWorkDays > 0 ? $salaryAmount / $totalWorkDays : 0;
        $workshopDays = max(0, $presentDays - $projectWorkDays);
        $workshopAmount = $workshopDays * $dailyRate;
    } else {
        // Untuk karyawan harian/per kehadiran, hari project memakai rate project.
        // Hari di luar project tetap memakai rate dasar workshop dari data karyawan.
        $dailyRate = $salaryAmount;
        $payableAttendanceDays = $presentDays;
        $workshopDays = max(0, $payableAttendanceDays - $projectWorkDays);
        $workshopAmount = $workshopDays * $dailyRate;
        $baseSalary = $workshopAmount + $projectWorkerAmount;
    }

    $hourlyRate = $dailyRate > 0 ? $dailyRate / 8 : 0;

    // Absensi/alpha, keterlambatan, dan pulang awal hanya dicatat sebagai evaluasi HR.
    // Sesuai kebijakan payroll Nukari, absen tidak pernah memotong gaji.
    // Perhitungan dari absensi yang bernilai uang hanya lembur dan cuti tidak dibayar.
    $absenceDeduction = 0;
    $lateDeduction = 0;
    $earlyLeaveDeduction = 0;
    $unpaidLeaveDeduction = 0;

    $attendanceDeduction = 0;

    // Snapshot nominal per absensi menjaga histori tarif per jam/borongan.
    $overtimeAmount = $recordedOvertimeAmount;

    $components = get_component_totals($pdo, $employee['id'], $month);

    $allowance = $components['allowance'];
    $bonus = $components['bonus'];
    $manualDeduction = $components['deduction'];
    $manualTax = $components['tax'];
    $additionSnapshot = payroll_addition_snapshot($pdo, (int)$employee['id'], $month);
    $additionalAmount = (float)$additionSnapshot['total'];

    $cashAdvanceDeduction = get_cash_advance_total($pdo, $employee['id'], $month);
    $loanDeductionRequested = get_loan_deduction_total($pdo, $employee['id'], $month);

    $grossBeforeTax = $baseSalary + $allowance + $bonus + $overtimeAmount;

    $bpjsHealth = $grossBeforeTax * ($bpjsHealthPercent / 100);
    $bpjsEmployment = $grossBeforeTax * ($bpjsEmploymentPercent / 100);
    $autoTax = $grossBeforeTax * ($taxPercent / 100);

    $totalTax = $manualTax + $autoTax;

    // Potongan sebelum cicilan loan.
    $totalDeductionBeforeLoan = $attendanceDeduction + $manualDeduction + $cashAdvanceDeduction;
    // Tambahan operasional (bensin/project/lainnya) dibayarkan setelah basis
    // BPJS dan pajak otomatis dihitung.
    $netBeforeLoan = $grossBeforeTax + $additionalAmount - $totalDeductionBeforeLoan - $totalTax - $bpjsHealth - $bpjsEmployment;

    // Cicilan loan dibatasi oleh persentase kebijakan dan gaji bersih yang
    // tersedia sebelum loan pada periode ini.
    $loanInstallmentPercent = payroll_loan_installment_percent($pdo);
    $maxLoanDeduction = max(0, min($netBeforeLoan, $netBeforeLoan * ($loanInstallmentPercent / 100)));
    $loanDeduction = min($loanDeductionRequested, $maxLoanDeduction);
    $loanDeductionRemaining = max(0, $loanDeductionRequested - $loanDeduction);
    $loanSnapshot = payroll_loan_snapshot($pdo, (int)$employee['id'], $month, $periodEnd);

    $totalDeduction = $totalDeductionBeforeLoan + $loanDeduction;
    $netSalary = $netBeforeLoan - $loanDeduction;

    if ($netSalary < 0) {
        $netSalary = 0;
    }

    return [
        'employee_id' => $employee['id'],
        'full_name' => $employee['full_name'],
        'employee_code' => $employee['employee_code'] ?? '-',
        'position' => $employee['position'] ?? '-',
        'department' => $employee['department'] ?? '-',
        'salary_type' => $salaryType,
        'salary_amount' => $salaryAmount,
        'base_salary' => $baseSalary,
        'workshop_days' => $workshopDays,
        'workshop_amount' => $workshopAmount,
        'project_work_days' => $projectWorkDays,
        'project_worker_amount' => $projectWorkerAmount,
        'project_allocations' => $projectAllocations,
        'total_work_days' => $totalWorkDays,
        'total_present_days' => $presentDays,
        'total_absent_days' => $absentDays,
        'paid_leave_days' => $paidLeaveDays,
        'unpaid_leave_days' => $unpaidLeaveDays,
        'unpaid_leave_deduction' => $unpaidLeaveDeduction,
        'total_late_minutes' => $lateMinutes,
        'total_early_leave_minutes' => $earlyLeaveMinutes,
        'total_overtime_minutes' => $overtimeMinutes,
        'daily_rate' => $dailyRate,
        'hourly_rate' => $hourlyRate,
        'total_allowance' => $allowance,
        'total_bonus' => $bonus,
        'overtime_amount' => $overtimeAmount,
        'additional_amount' => $additionalAmount,
        'additional_details' => $additionSnapshot['details'],
        'attendance_deduction' => $attendanceDeduction,
        'manual_deduction' => $manualDeduction,
        'cash_advance_deduction' => $cashAdvanceDeduction,
        'loan_deduction_requested' => $loanDeductionRequested,
        'loan_deduction' => $loanDeduction,
        'loan_deduction_remaining' => $loanDeductionRemaining,
        'loan_total_snapshot' => $loanSnapshot['total'],
        'loan_paid_snapshot' => $loanSnapshot['paid'],
        'loan_remaining_snapshot' => $loanSnapshot['remaining'],
        'loan_payment_count_snapshot' => $loanSnapshot['payment_count'],
        'total_deduction' => $totalDeduction,
        'total_tax' => $totalTax,
        'bpjs_health' => $bpjsHealth,
        'bpjs_employment' => $bpjsEmployment,
        'net_salary' => $netSalary
    ];
}

$settingStmt = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1");
$setting = $settingStmt->fetch(PDO::FETCH_ASSOC);

if (!$setting) {
    $pdo->query("
        INSERT INTO payroll_settings
        (setting_name, start_day, end_day, payday_day, notes)
        VALUES
        ('Default Payroll', 16, 15, 28, 'Periode tanggal 16 bulan sebelumnya sampai tanggal 15 bulan payroll.')
    ");

    $settingStmt = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1");
    $setting = $settingStmt->fetch(PDO::FETCH_ASSOC);
}

[$periodStart, $periodEnd] = payroll_date_range($month, $setting);
$totalWorkDays = count_work_days($periodStart, $periodEnd);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_payroll') {
    try {
        $pdo->beginTransaction();

        $employeeStmt = $pdo->query("
            SELECT *
            FROM employees
            WHERE employment_status = 'active'
            ORDER BY full_name ASC
        ");

        $employeesToGenerate = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

        $generated = 0;
        $regenerated = 0;
        $locked = 0;

        foreach ($employeesToGenerate as $employee) {
            $calc = calculate_employee_payroll(
                $pdo,
                $employee,
                $month,
                $periodStart,
                $periodEnd,
                $totalWorkDays,
                $bpjsHealthPercent,
                $bpjsEmploymentPercent,
                $taxPercent,
                $overtimeMultiplier
            );

            $checkStmt = $pdo->prepare("
                SELECT id, status, is_locked
                FROM payrolls
                WHERE employee_id = :employee_id
                AND payroll_month = :payroll_month
                LIMIT 1
            ");

            $checkStmt->execute([
                ':employee_id' => $employee['id'],
                ':payroll_month' => $month
            ]);

            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            $notes = 'Generated from attendance period ' . $periodStart . ' to ' . $periodEnd;
            $notes .= '. Kehadiran, cuti/izin, dan alpha disimpan sebagai snapshot informasi tanpa potongan otomatis.';
            if (!empty($calc['project_allocations'])) {
                $notes .= ' Alokasi project internal: ' . payroll_project_allocation_text($calc['project_allocations']) . '.';
            }

            if ($calc['cash_advance_deduction'] > 0) {
                $notes .= ' Kasbon deducted: ' . payroll_money($calc['cash_advance_deduction']) . '.';
            }

            if (($calc['loan_deduction'] ?? 0) > 0) {
                $notes .= ' Loan installment deducted: ' . payroll_money($calc['loan_deduction']) . '.';
            }

            if (($calc['loan_deduction_remaining'] ?? 0) > 0) {
                $notes .= ' Loan installment request exceeded available salary and was capped. Unprocessed amount: ' . payroll_money($calc['loan_deduction_remaining']) . '.';
            }

            if ($existing) {
                if ($existing['status'] === 'paid' || !empty($existing['is_locked'])) {
                    $locked++;
                    continue;
                }

                $update = $pdo->prepare("
                    UPDATE payrolls SET
                        period_start = :period_start,
                        period_end = :period_end,
                        base_salary = :base_salary,
                        workshop_days = :workshop_days,
                        workshop_amount = :workshop_amount,
                        project_work_days = :project_work_days,
                        project_worker_amount = :project_worker_amount,
                        total_work_days = :total_work_days,
                        total_present_days = :total_present_days,
                        total_absent_days = :total_absent_days,
                        paid_leave_days = :paid_leave_days,
                        unpaid_leave_days = :unpaid_leave_days,
                        total_late_minutes = :total_late_minutes,
                        total_early_leave_minutes = :total_early_leave_minutes,
                        total_overtime_minutes = :total_overtime_minutes,
                        daily_rate = :daily_rate,
                        hourly_rate = :hourly_rate,
                        total_allowance = :total_allowance,
                        total_bonus = :total_bonus,
                        overtime_amount = :overtime_amount,
                        additional_amount = :additional_amount,
                        additional_details = :additional_details,
                        cash_advance_deduction = :cash_advance_deduction,
                        loan_deduction = :loan_deduction,
                        loan_total_snapshot = :loan_total_snapshot,
                        loan_paid_snapshot = :loan_paid_snapshot,
                        loan_remaining_snapshot = :loan_remaining_snapshot,
                        loan_payment_count_snapshot = :loan_payment_count_snapshot,
                        total_deduction = :total_deduction,
                        unpaid_leave_deduction = :unpaid_leave_deduction,
                        total_tax = :total_tax,
                        bpjs_health = :bpjs_health,
                        bpjs_employment = :bpjs_employment,
                        net_salary = :net_salary,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id
                    AND status = 'draft'
                ");

                $update->execute([
                    ':period_start' => $periodStart,
                    ':period_end' => $periodEnd,
                    ':base_salary' => $calc['base_salary'],
                    ':workshop_days' => $calc['workshop_days'] ?? 0,
                    ':workshop_amount' => $calc['workshop_amount'] ?? 0,
                    ':project_work_days' => $calc['project_work_days'] ?? 0,
                    ':project_worker_amount' => $calc['project_worker_amount'] ?? 0,
                    ':total_work_days' => $calc['total_work_days'],
                    ':total_present_days' => $calc['total_present_days'],
                    ':total_absent_days' => $calc['total_absent_days'],
                    ':paid_leave_days' => $calc['paid_leave_days'],
                    ':unpaid_leave_days' => $calc['unpaid_leave_days'],
                    ':total_late_minutes' => $calc['total_late_minutes'],
                    ':total_early_leave_minutes' => $calc['total_early_leave_minutes'],
                    ':total_overtime_minutes' => $calc['total_overtime_minutes'],
                    ':daily_rate' => $calc['daily_rate'],
                    ':hourly_rate' => $calc['hourly_rate'],
                    ':total_allowance' => $calc['total_allowance'],
                    ':total_bonus' => $calc['total_bonus'],
                    ':overtime_amount' => $calc['overtime_amount'],
                    ':additional_amount' => $calc['additional_amount'],
                    ':additional_details' => json_encode($calc['additional_details'], JSON_UNESCAPED_UNICODE),
                    ':cash_advance_deduction' => $calc['cash_advance_deduction'],
                    ':loan_deduction' => $calc['loan_deduction'],
                    ':loan_total_snapshot' => $calc['loan_total_snapshot'],
                    ':loan_paid_snapshot' => $calc['loan_paid_snapshot'],
                    ':loan_remaining_snapshot' => $calc['loan_remaining_snapshot'],
                    ':loan_payment_count_snapshot' => $calc['loan_payment_count_snapshot'],
                    ':total_deduction' => $calc['total_deduction'],
                    ':unpaid_leave_deduction' => $calc['unpaid_leave_deduction'],
                    ':total_tax' => $calc['total_tax'],
                    ':bpjs_health' => $calc['bpjs_health'],
                    ':bpjs_employment' => $calc['bpjs_employment'],
                    ':net_salary' => $calc['net_salary'],
                    ':notes' => $notes,
                    ':id' => $existing['id']
                ]);

                sync_loan_payments_to_payroll($pdo, $employee['id'], $month, (int)$existing['id']);
                if (function_exists('payroll_sync_project_allocations')) {
                    payroll_sync_project_allocations($pdo, (int)$existing['id'], (int)$employee['id'], $calc['project_allocations'] ?? []);
                }

                $regenerated++;
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO payrolls (
                        employee_id,
                        payroll_month,
                        period_start,
                        period_end,
                        base_salary,
                        workshop_days,
                        workshop_amount,
                        project_work_days,
                        project_worker_amount,
                        total_work_days,
                        total_present_days,
                        total_absent_days,
                        paid_leave_days,
                        unpaid_leave_days,
                        total_late_minutes,
                        total_early_leave_minutes,
                        total_overtime_minutes,
                        daily_rate,
                        hourly_rate,
                        total_allowance,
                        total_bonus,
                        overtime_amount,
                        additional_amount,
                        additional_details,
                        cash_advance_deduction,
                        loan_deduction,
                        loan_total_snapshot,
                        loan_paid_snapshot,
                        loan_remaining_snapshot,
                        loan_payment_count_snapshot,
                        total_deduction,
                        unpaid_leave_deduction,
                        total_tax,
                        bpjs_health,
                        bpjs_employment,
                        net_salary,
                        status,
                        notes
                    ) VALUES (
                        :employee_id,
                        :payroll_month,
                        :period_start,
                        :period_end,
                        :base_salary,
                        :workshop_days,
                        :workshop_amount,
                        :project_work_days,
                        :project_worker_amount,
                        :total_work_days,
                        :total_present_days,
                        :total_absent_days,
                        :paid_leave_days,
                        :unpaid_leave_days,
                        :total_late_minutes,
                        :total_early_leave_minutes,
                        :total_overtime_minutes,
                        :daily_rate,
                        :hourly_rate,
                        :total_allowance,
                        :total_bonus,
                        :overtime_amount,
                        :additional_amount,
                        :additional_details,
                        :cash_advance_deduction,
                        :loan_deduction,
                        :loan_total_snapshot,
                        :loan_paid_snapshot,
                        :loan_remaining_snapshot,
                        :loan_payment_count_snapshot,
                        :total_deduction,
                        :unpaid_leave_deduction,
                        :total_tax,
                        :bpjs_health,
                        :bpjs_employment,
                        :net_salary,
                        'draft',
                        :notes
                    )
                ");

                $insert->execute([
                    ':employee_id' => $employee['id'],
                    ':payroll_month' => $month,
                    ':period_start' => $periodStart,
                    ':period_end' => $periodEnd,
                    ':base_salary' => $calc['base_salary'],
                    ':workshop_days' => $calc['workshop_days'] ?? 0,
                    ':workshop_amount' => $calc['workshop_amount'] ?? 0,
                    ':project_work_days' => $calc['project_work_days'] ?? 0,
                    ':project_worker_amount' => $calc['project_worker_amount'] ?? 0,
                    ':total_work_days' => $calc['total_work_days'],
                    ':total_present_days' => $calc['total_present_days'],
                    ':total_absent_days' => $calc['total_absent_days'],
                    ':paid_leave_days' => $calc['paid_leave_days'],
                    ':unpaid_leave_days' => $calc['unpaid_leave_days'],
                    ':total_late_minutes' => $calc['total_late_minutes'],
                    ':total_early_leave_minutes' => $calc['total_early_leave_minutes'],
                    ':total_overtime_minutes' => $calc['total_overtime_minutes'],
                    ':daily_rate' => $calc['daily_rate'],
                    ':hourly_rate' => $calc['hourly_rate'],
                    ':total_allowance' => $calc['total_allowance'],
                    ':total_bonus' => $calc['total_bonus'],
                    ':overtime_amount' => $calc['overtime_amount'],
                    ':additional_amount' => $calc['additional_amount'],
                    ':additional_details' => json_encode($calc['additional_details'], JSON_UNESCAPED_UNICODE),
                    ':cash_advance_deduction' => $calc['cash_advance_deduction'],
                    ':loan_deduction' => $calc['loan_deduction'],
                    ':loan_total_snapshot' => $calc['loan_total_snapshot'],
                    ':loan_paid_snapshot' => $calc['loan_paid_snapshot'],
                    ':loan_remaining_snapshot' => $calc['loan_remaining_snapshot'],
                    ':loan_payment_count_snapshot' => $calc['loan_payment_count_snapshot'],
                    ':total_deduction' => $calc['total_deduction'],
                    ':unpaid_leave_deduction' => $calc['unpaid_leave_deduction'],
                    ':total_tax' => $calc['total_tax'],
                    ':bpjs_health' => $calc['bpjs_health'],
                    ':bpjs_employment' => $calc['bpjs_employment'],
                    ':net_salary' => $calc['net_salary'],
                    ':notes' => $notes
                ]);

                $newPayrollId = (int)$pdo->lastInsertId();
                sync_loan_payments_to_payroll($pdo, $employee['id'], $month, $newPayrollId);
                if (function_exists('payroll_sync_project_allocations')) {
                    payroll_sync_project_allocations($pdo, $newPayrollId, (int)$employee['id'], $calc['project_allocations'] ?? []);
                }

                $generated++;
            }
        }

        $pdo->commit();

        $success = "Payroll berhasil diproses untuk {$month}. Data baru: {$generated}, diperbarui: {$regenerated}, terkunci karena PAID: {$locked}. Record bulan lain tidak diubah.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Gagal generate payroll: ' . $e->getMessage();
    }
}

$where = [];
$params = [
    ':month' => $month
];

$where[] = "p.payroll_month = :month";

if ($keyword !== '') {
    $where[] = "(e.full_name LIKE :keyword_name OR e.employee_code LIKE :keyword_code OR e.position LIKE :keyword_position OR e.department LIKE :keyword_department)";
    $keywordPattern = '%' . $keyword . '%';
    $params[':keyword_name'] = $keywordPattern;
    $params[':keyword_code'] = $keywordPattern;
    $params[':keyword_position'] = $keywordPattern;
    $params[':keyword_department'] = $keywordPattern;
}

if ($statusFilter !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$payrollStmt = $pdo->prepare("
    SELECT
        p.*,
        e.full_name,
        e.employee_code,
        e.position,
        e.department,
        e.salary_type,
        e.salary_amount,
        COALESCE(ca.cash_advance_total, 0) AS cash_advance_total,
        COALESCE(al.active_loan_remaining, 0) AS active_loan_remaining
    FROM payrolls p
    JOIN employees e ON p.employee_id = e.id
    LEFT JOIN (
        SELECT employee_id, deduction_month, SUM(remaining_amount) AS cash_advance_total
        FROM employee_cash_advances
        WHERE status = 'active'
        GROUP BY employee_id, deduction_month
    ) ca ON ca.employee_id = p.employee_id AND ca.deduction_month = p.payroll_month
    LEFT JOIN (
        SELECT employee_id, SUM(remaining_amount) AS active_loan_remaining
        FROM employee_loans
        WHERE status = 'active'
        GROUP BY employee_id
    ) al ON al.employee_id = p.employee_id
    WHERE {$whereSql}
    ORDER BY e.full_name ASC
");

$payrollStmt->execute($params);
$payrolls = $payrollStmt->fetchAll(PDO::FETCH_ASSOC);

// Daftar periode yang sudah pernah dibuat tetap tersedia sebagai histori admin.
$historyStmt = $pdo->query("
    SELECT payroll_month, MIN(period_start) AS period_start, MAX(period_end) AS period_end,
           COUNT(*) AS employee_count, SUM(status = 'paid') AS paid_count
    FROM payrolls
    GROUP BY payroll_month
    ORDER BY payroll_month DESC
");
$payrollHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$displayPeriodStart = $payrolls[0]['period_start'] ?? $periodStart;
$displayPeriodEnd = $payrolls[0]['period_end'] ?? $periodEnd;
$displayTotalDays = count_work_days($displayPeriodStart, $displayPeriodEnd);

$summary = [
    'employees' => count($payrolls),
    'base_salary' => 0,
    'allowance_bonus_overtime' => 0,
    'additional_amount' => 0,
    'cash_advance' => 0,
    'loan_deduction' => 0,
    'active_loan_remaining' => 0,
    'deduction_tax_bpjs' => 0,
    'net_salary' => 0,
    'paid' => 0,
    'draft' => 0,
    'paid_leave_days' => 0,
    'unpaid_leave_days' => 0
];

foreach ($payrolls as $row) {
    $summary['base_salary'] += (float)$row['base_salary'];
    $summary['allowance_bonus_overtime'] += (float)$row['total_allowance'] + (float)$row['total_bonus'] + (float)$row['overtime_amount'] + (float)($row['additional_amount'] ?? 0);
    $summary['additional_amount'] += (float)($row['additional_amount'] ?? 0);
    $summary['cash_advance'] += (float)($row['cash_advance_deduction'] ?? $row['cash_advance_total'] ?? 0);
    $summary['loan_deduction'] += (float)($row['loan_deduction'] ?? 0);
    $summary['active_loan_remaining'] += (float)($row['active_loan_remaining'] ?? 0);
    $summary['deduction_tax_bpjs'] += (float)$row['total_deduction'] + (float)$row['total_tax'] + (float)$row['bpjs_health'] + (float)$row['bpjs_employment'];
    $summary['net_salary'] += (float)$row['net_salary'];
    $summary['paid_leave_days'] += (int)$row['paid_leave_days'];
    $summary['unpaid_leave_days'] += (int)$row['unpaid_leave_days'];

    if ($row['status'] === 'paid') {
        $summary['paid']++;
    } else {
        $summary['draft']++;
    }
}

$exportQuery = http_build_query([
    'month' => $month,
    'keyword' => $keyword,
    'status' => $statusFilter,
    'bpjs_health_percent' => $bpjsHealthPercent,
    'bpjs_employment_percent' => $bpjsEmploymentPercent,
    'tax_percent' => $taxPercent,
    'overtime_multiplier' => $overtimeMultiplier
]);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Payroll</h1>
            <p class="text-muted mb-0">
                Payroll karyawan dengan perhitungan lembur, cuti/izin, kasbon, cicilan pinjaman, pajak, BPJS, dan komponen gaji lainnya.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/payroll/management.php?month=' . urlencode($month)); ?>" class="btn btn-dark">
                <i class="bi bi-people me-1"></i> Kelola Semua Karyawan
            </a>

            <a href="<?= base_url('pages/payroll/settings.php'); ?>" class="btn btn-outline-success">
                <i class="bi bi-gear me-1"></i> Setting Payroll
            </a>

            <a href="<?= base_url('pages/attendances/index.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-calendar-check me-1"></i> Absensi
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
                <div class="stat-title">Karyawan Payroll</div>
                <div class="stat-number"><?= payroll_number($summary['employees']); ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Total Gaji Bersih</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= payroll_money($summary['net_salary']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Kasbon Terpotong</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= payroll_money($summary['cash_advance']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Cicilan Loan</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= payroll_money($summary['loan_deduction']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Status</div>
                <div class="stat-number" style="font-size:20px;">
                    <?= $summary['draft']; ?> Draft / <?= $summary['paid']; ?> Paid
                </div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">

            <div class="col-md-2">
                <label class="form-label fw-semibold">Bulan Payroll</label>
                <input type="month" name="month" value="<?= htmlspecialchars($month); ?>" class="form-control">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Cari Karyawan</label>
                <input
                    type="text"
                    name="keyword"
                    value="<?= htmlspecialchars($keyword); ?>"
                    class="form-control"
                    placeholder="Nama, kode, jabatan, departemen">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="">Semua</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>

            <div class="col-md-1">
                <label class="form-label fw-semibold">BPJS Kes (%)</label>
                <input type="number" step="0.01" name="bpjs_health_percent" value="<?= htmlspecialchars($bpjsHealthPercent); ?>" class="form-control">
            </div>

            <div class="col-md-1">
                <label class="form-label fw-semibold">BPJS TK (%)</label>
                <input type="number" step="0.01" name="bpjs_employment_percent" value="<?= htmlspecialchars($bpjsEmploymentPercent); ?>" class="form-control">
            </div>

            <div class="col-md-1">
                <label class="form-label fw-semibold">Pajak (%)</label>
                <input type="number" step="0.01" name="tax_percent" value="<?= htmlspecialchars($taxPercent); ?>" class="form-control">
            </div>

            <div class="col-md-1">
                <label class="form-label fw-semibold">Lembur x</label>
                <input type="number" step="0.01" name="overtime_multiplier" value="<?= htmlspecialchars($overtimeMultiplier); ?>" class="form-control">
            </div>

            <div class="col-md-1 d-grid">
                <button class="btn btn-nukari">
                    <i class="bi bi-search"></i>
                </button>
            </div>

        </form>
    </div>

    <?php if ($payrollHistory): ?>
        <div class="panel mb-4">
            <h5 class="fw-bold mb-2">Histori Payroll Bulanan</h5>
            <p class="text-muted small mb-3">Pilih bulan untuk membuka kembali record yang pernah digenerate.</p>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($payrollHistory as $history): ?>
                    <a href="<?= base_url('pages/payroll/index.php?month=' . urlencode($history['payroll_month'])); ?>"
                       class="btn btn-sm <?= $history['payroll_month'] === $month ? 'btn-nukari' : 'btn-outline-secondary'; ?>">
                        <?= htmlspecialchars(date('M Y', strtotime($history['payroll_month'] . '-01'))); ?>
                        <span class="badge bg-light text-dark ms-1"><?= (int)$history['employee_count']; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="fw-bold mb-1">Periode Perhitungan</h5>
                <p class="text-muted mb-0">
                    <?= date('d M Y', strtotime($displayPeriodStart)); ?> sampai <?= date('d M Y', strtotime($displayPeriodEnd)); ?>
                    · Hari kalender: <strong><?= $displayTotalDays; ?></strong>
                    · Normal: <strong>semua hari bernilai 8 jam; setengah hari dibayar 0,5 tarif untuk gaji harian/per kehadiran</strong>
                </p>
            </div>

            <form method="POST" onsubmit="return confirm('Generate payroll bulan ini? Data draft akan diperbarui, data paid/locked tidak akan diubah. Absen/telat tidak memotong gaji; lembur tetap dihitung.');">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="generate_payroll">
                <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                <input type="hidden" name="bpjs_health_percent" value="<?= htmlspecialchars($bpjsHealthPercent); ?>">
                <input type="hidden" name="bpjs_employment_percent" value="<?= htmlspecialchars($bpjsEmploymentPercent); ?>">
                <input type="hidden" name="tax_percent" value="<?= htmlspecialchars($taxPercent); ?>">
                <input type="hidden" name="overtime_multiplier" value="<?= htmlspecialchars($overtimeMultiplier); ?>">

                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-calculator me-1"></i> Generate / Recalculate Payroll
                </button>
            </form>
        </div>
    </div>

    <div class="panel">

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Daftar Payroll</h5>
                <p class="text-muted mb-0">
                    Centang karyawan yang slipnya ingin dicetak, lalu pilih <strong>Cetak Slip Terpilih</strong>.
                    Payroll adalah record bulanan berdasarkan bulan tutup buku.
                </p>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form id="selectedSlipForm"
                    method="GET"
                    action="<?= base_url('pages/payroll/slip.php'); ?>"
                    target="_blank"
                    class="m-0">
                    <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                    <button type="submit" id="printSelectedSlips" class="btn btn-outline-success d-inline-flex align-items-center px-3 py-2 shadow-sm flex-shrink-0" disabled>
                        <i class="bi bi-printer me-2"></i>
                        <span>Cetak Slip Terpilih</span>
                    </button>
                </form>

                <a href="<?= base_url('pages/payroll/export_payroll_excel.php?' . $exportQuery); ?>"
                    class="btn btn-success d-inline-flex align-items-center px-3 py-2 shadow-sm flex-shrink-0"
                    target="_blank">
                    <i class="bi bi-file-earmark-excel me-2"></i>
                    Export Excel
                </a>

                <div class="position-relative flex-shrink-0" style="width: 320px; max-width: 320px;">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    <input
                        type="text"
                        id="tableSearch"
                        class="form-control ps-5 shadow-sm"
                        placeholder="Search cepat di tabel...">
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="payrollTable">
                <thead>
                    <tr>
                        <th data-no-sort="true" class="text-center">
                            <input type="checkbox" id="selectAllPayrolls" class="form-check-input" aria-label="Pilih semua payroll yang terlihat">
                        </th>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Gaji Pokok</th>
                        <th>Hari Periode</th>
                        <th>Hadir</th>
                        <th>Terlambat</th>
                        <th>Lembur</th>
                        <th>Tunjangan</th>
                        <th>Bonus</th>
                        <th>Upah Lembur</th>
                        <th>Tambahan</th>
                        <th>Kasbon</th>
                        <th>Cicilan Loan</th>
                        <th>Sisa Loan</th>
                        <th>Total Potongan</th>
                        <th>Pajak</th>
                        <th>BPJS</th>
                        <th>Total Gaji</th>
                        <th>Status</th>
                        <th width="220">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($payrolls): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($payrolls as $row): ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox"
                                        class="form-check-input payroll-slip-checkbox"
                                        name="ids[]"
                                        value="<?= (int)$row['id']; ?>"
                                        form="selectedSlipForm"
                                        aria-label="Pilih slip <?= htmlspecialchars($row['full_name']); ?>">
                                </td>
                                <td><?= $no++; ?></td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($row['full_name']); ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($row['employee_code'] ?? '-'); ?> ·
                                        <?= htmlspecialchars($row['position'] ?? '-'); ?>
                                        <?= !empty($row['department']) ? ' · ' . htmlspecialchars($row['department']) : ''; ?>
                                    </small>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?= payroll_money($row['base_salary']); ?></div>
                                    <small class="text-muted d-block">
                                        <?= htmlspecialchars($row['salary_type']); ?> · Harian: <?= payroll_money($row['daily_rate']); ?>
                                    </small>
                                    <?php if ((float)($row['project_worker_amount'] ?? 0) > 0): ?>
                                        <small class="text-primary d-block">
                                            Project: <?= payroll_day_number($row['project_work_days'] ?? 0); ?> hari · <?= payroll_money($row['project_worker_amount'] ?? 0); ?>
                                        </small>
                                        <small class="text-muted d-block">
                                            Workshop: <?= payroll_day_number($row['workshop_days'] ?? 0); ?> hari · <?= payroll_money($row['workshop_amount'] ?? 0); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td><?= payroll_day_number($row['total_work_days']); ?> hari</td>
                                <td><?= payroll_day_number($row['total_present_days']); ?> hari</td>
                                <td><?= payroll_minutes_to_hour($row['total_late_minutes']); ?></td>
                                <td><?= (float)$row['overtime_amount'] > 0 ? payroll_minutes_to_hour($row['total_overtime_minutes']) : '-'; ?></td>
                                <td><?= payroll_money($row['total_allowance']); ?></td>
                                <td><?= payroll_money($row['total_bonus']); ?></td>
                                <td><?= payroll_money($row['overtime_amount']); ?></td>
                                <td class="text-success fw-semibold"><?= payroll_money($row['additional_amount'] ?? 0); ?></td>

                                <td class="text-danger fw-semibold">
                                    <?= payroll_money($row['cash_advance_deduction'] ?? $row['cash_advance_total'] ?? 0); ?>
                                </td>

                                <td class="text-danger fw-semibold">
                                    <?= payroll_money($row['loan_deduction'] ?? 0); ?>
                                </td>

                                <td class="text-muted">
                                    <?= payroll_money($row['active_loan_remaining'] ?? 0); ?>
                                </td>

                                <td class="text-danger">
                                    <?= payroll_money($row['total_deduction']); ?>
                                </td>

                                <td class="text-danger">
                                    <?= payroll_money($row['total_tax']); ?>
                                </td>

                                <td class="text-danger">
                                    <?= payroll_money((float)$row['bpjs_health'] + (float)$row['bpjs_employment']); ?>
                                    <small class="text-muted d-block">
                                        Kes: <?= payroll_money($row['bpjs_health']); ?> · TK: <?= payroll_money($row['bpjs_employment']); ?>
                                    </small>
                                </td>

                                <td class="fw-bold text-success">
                                    <?= payroll_money($row['net_salary']); ?>
                                </td>

                                <td>
                                    <?php if ($row['status'] === 'paid'): ?>
                                        <span class="badge rounded-pill badge-soft-green">Paid</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-orange">Draft</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a href="<?= base_url('pages/payroll/management.php?month=' . urlencode($row['payroll_month']) . '&employee_id=' . (int)$row['employee_id']); ?>" class="btn btn-sm btn-dark">
                                            <i class="bi bi-sliders me-1"></i> Kelola
                                        </a>

                                        <a href="<?= base_url('pages/payroll/detail.php?id=' . $row['id']); ?>" class="btn btn-sm btn-outline-primary">
                                            Detail
                                        </a>

                                        <a href="<?= base_url('pages/payroll/slip.php?id=' . $row['id']); ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                            Slip
                                        </a>

                                        <?php if ($row['status'] !== 'paid'): ?>
                                            <a href="<?= base_url('pages/payroll/pay.php?id=' . $row['id']); ?>"
                                                class="btn btn-sm btn-success"
                                                onclick="return confirm('Tandai payroll ini sebagai PAID?');">
                                                Paid
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="21" class="text-center text-muted py-4">
                                Belum ada data payroll untuk bulan ini. Klik tombol <strong>Generate / Recalculate Payroll</strong>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($payrolls): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="3">TOTAL</td>
                            <td><?= payroll_money($summary['base_salary']); ?></td>
                            <td colspan="4"></td>
                            <td colspan="4"><?= payroll_money($summary['allowance_bonus_overtime']); ?></td>
                            <td class="text-danger"><?= payroll_money($summary['cash_advance']); ?></td>
                            <td class="text-danger"><?= payroll_money($summary['loan_deduction']); ?></td>
                            <td class="text-muted"><?= payroll_money($summary['active_loan_remaining']); ?></td>
                            <td colspan="3" class="text-danger"><?= payroll_money($summary['deduction_tax_bpjs']); ?></td>
                            <td class="text-success"><?= payroll_money($summary['net_salary']); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('tableSearch');
        const table = document.getElementById('payrollTable');
        const selectedSlipForm = document.getElementById('selectedSlipForm');
        const selectAllPayrolls = document.getElementById('selectAllPayrolls');
        const printSelectedSlips = document.getElementById('printSelectedSlips');
        const slipCheckboxes = table ? Array.from(table.querySelectorAll('.payroll-slip-checkbox')) : [];

        function visibleSlipCheckboxes() {
            return slipCheckboxes.filter(function(checkbox) {
                return checkbox.closest('tr').style.display !== 'none';
            });
        }

        function updateSlipSelection() {
            const selectedCount = slipCheckboxes.filter(function(checkbox) {
                return checkbox.checked;
            }).length;
            const visibleCheckboxes = visibleSlipCheckboxes();
            const visibleSelectedCount = visibleCheckboxes.filter(function(checkbox) {
                return checkbox.checked;
            }).length;

            if (printSelectedSlips) {
                printSelectedSlips.disabled = selectedCount === 0;
                const label = printSelectedSlips.querySelector('span');
                if (label) {
                    label.textContent = selectedCount > 0
                        ? 'Cetak Slip Terpilih (' + selectedCount + ')'
                        : 'Cetak Slip Terpilih';
                }
            }

            if (selectAllPayrolls) {
                selectAllPayrolls.checked = visibleCheckboxes.length > 0
                    && visibleSelectedCount === visibleCheckboxes.length;
                selectAllPayrolls.indeterminate = visibleSelectedCount > 0
                    && visibleSelectedCount < visibleCheckboxes.length;
            }
        }

        slipCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', updateSlipSelection);
        });

        if (selectAllPayrolls) {
            selectAllPayrolls.addEventListener('change', function() {
                visibleSlipCheckboxes().forEach(function(checkbox) {
                    checkbox.checked = selectAllPayrolls.checked;
                });
                updateSlipSelection();
            });
        }

        if (selectedSlipForm) {
            selectedSlipForm.addEventListener('submit', function(event) {
                if (!slipCheckboxes.some(function(checkbox) { return checkbox.checked; })) {
                    event.preventDefault();
                }
            });
        }

        if (searchInput && table) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr');

                rows.forEach(function(row) {
                    const text = row.innerText.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });

                updateSlipSelection();
            });
        }

        if (table) {
            const headers = table.querySelectorAll('thead th');

            headers.forEach(function(header, index) {
                if (header.dataset.noSort === 'true') {
                    return;
                }

                header.style.cursor = 'pointer';

                header.addEventListener('click', function() {
                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const asc = header.dataset.sortAsc !== 'true';

                    rows.sort(function(a, b) {
                        const aTextRaw = a.children[index]?.innerText || '';
                        const bTextRaw = b.children[index]?.innerText || '';

                        const aText = aTextRaw.replace(/[^\d,-]/g, '').replace(',', '.');
                        const bText = bTextRaw.replace(/[^\d,-]/g, '').replace(',', '.');

                        const aNumber = parseFloat(aText);
                        const bNumber = parseFloat(bText);

                        if (!isNaN(aNumber) && !isNaN(bNumber)) {
                            return asc ? aNumber - bNumber : bNumber - aNumber;
                        }

                        const aString = aTextRaw.toLowerCase();
                        const bString = bTextRaw.toLowerCase();

                        return asc ? aString.localeCompare(bString) : bString.localeCompare(aString);
                    });

                    header.dataset.sortAsc = asc ? 'true' : 'false';

                    rows.forEach(function(row) {
                        tbody.appendChild(row);
                    });
                });
            });
        }

        updateSlipSelection();
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
