<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/employee_funding_helper.php';
require_once __DIR__ . '/../../includes/payroll_loan_helper.php';
require_once __DIR__ . '/../../includes/payroll_period_helper.php';

payroll_ensure_loan_policy_schema($pdo);

$pageTitle = 'Pinjaman Karyawan - Nukari';

$error = '';
$success = '';

$month = $_GET['month'] ?? $_POST['month'] ?? date('Y-m');
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month) ? (string)$month : date('Y-m');
$keyword = trim($_GET['keyword'] ?? '');
$statusFilter = $_GET['status'] ?? '';

function loan_money($value)
{
    if (function_exists('rupiah')) {
        return rupiah($value);
    }

    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function normalize_loan_money($value)
{
    $value = trim((string)$value);
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    return (float)$value;
}

function loan_status_badge($status)
{
    if ($status === 'paid') {
        return '<span class="badge rounded-pill badge-soft-green">Lunas</span>';
    }

    if ($status === 'cancelled') {
        return '<span class="badge rounded-pill badge-soft-red">Dibatalkan</span>';
    }

    return '<span class="badge rounded-pill badge-soft-orange">Aktif</span>';
}

function loan_safe_date($date)
{
    if (empty($date)) {
        return '-';
    }

    return date('d M Y', strtotime($date));
}

function loan_period_label($year, $month)
{
    if (empty($year) || empty($month)) {
        return '-';
    }

    return date('M Y', strtotime((int)$year . '-' . str_pad((int)$month, 2, '0', STR_PAD_LEFT) . '-01'));
}

function recalculate_employee_loan(PDO $pdo, $loanId)
{
    $loanStmt = $pdo->prepare("SELECT loan_amount,opening_paid_amount FROM employee_loans WHERE id = :id LIMIT 1");
    $loanStmt->execute([':id' => $loanId]);
    $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        return;
    }

    $paidStmt = $pdo->prepare("
        SELECT COALESCE(SUM(payment_amount), 0) AS total_paid
        FROM employee_loan_payments
        WHERE loan_id = :loan_id
    ");
    $paidStmt->execute([':loan_id' => $loanId]);
    $totalPaid = (float)($loan['opening_paid_amount'] ?? 0) + (float)$paidStmt->fetchColumn();

    $loanAmount = (float)$loan['loan_amount'];
    $remaining = max(0, $loanAmount - $totalPaid);
    $status = $remaining <= 0 ? 'paid' : 'active';

    $updateStmt = $pdo->prepare("
        UPDATE employee_loans
        SET total_paid = :total_paid,
            remaining_amount = :remaining_amount,
            status = CASE
                WHEN status = 'cancelled' THEN 'cancelled'
                ELSE :status
            END,
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


function get_employee_monthly_salary_limit(PDO $pdo, int $employeeId, string $period): float
{
    // Prioritas 1: gunakan payroll periode tersebut jika sudah pernah digenerate.
    // net_salary sudah termasuk loan deduction, maka ditambah kembali loan_deduction
    // agar batas cicilan dihitung dari gaji tersedia sebelum potongan loan bulan itu.
    $payrollStmt = $pdo->prepare(
        ""
            . "SELECT net_salary, loan_deduction, base_salary "
            . "FROM payrolls "
            . "WHERE employee_id = :employee_id AND payroll_month = :payroll_month "
            . "LIMIT 1"
    );
    $payrollStmt->execute([
        ':employee_id' => $employeeId,
        ':payroll_month' => $period
    ]);
    $payroll = $payrollStmt->fetch(PDO::FETCH_ASSOC);

    if ($payroll) {
        $netBeforeLoan = (float)($payroll['net_salary'] ?? 0) + (float)($payroll['loan_deduction'] ?? 0);
        if ($netBeforeLoan > 0) {
            return $netBeforeLoan;
        }

        if ((float)($payroll['base_salary'] ?? 0) > 0) {
            return (float)$payroll['base_salary'];
        }
    }

    // Prioritas 2: gunakan master gaji karyawan.
    $employeeStmt = $pdo->prepare(
        ""
            . "SELECT salary_type, salary_amount "
            . "FROM employees "
            . "WHERE id = :employee_id "
            . "LIMIT 1"
    );
    $employeeStmt->execute([':employee_id' => $employeeId]);
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        return 0;
    }

    $salaryType = $employee['salary_type'] ?? 'monthly';
    $salaryAmount = (float)($employee['salary_amount'] ?? 0);

    if ($salaryAmount <= 0) {
        return 0;
    }

    if ($salaryType === 'daily') {
        // Estimasi aman untuk karyawan harian jika payroll belum digenerate.
        // Angka 26 dipakai sebagai estimasi hari kerja maksimum bulanan.
        return $salaryAmount * 26;
    }

    return $salaryAmount;
}

function get_employee_period_loan_payment_total(PDO $pdo, int $employeeId, string $period, ?int $excludePaymentId = null): float
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        return 0;
    }

    [$periodYear, $periodMonth] = explode('-', $period);

    $sql = ""
        . "SELECT COALESCE(SUM(payment_amount), 0) "
        . "FROM employee_loan_payments "
        . "WHERE employee_id = :employee_id "
        . "AND period_year = :period_year "
        . "AND period_month = :period_month";

    $params = [
        ':employee_id' => $employeeId,
        ':period_year' => (int)$periodYear,
        ':period_month' => (int)$periodMonth
    ];

    if ($excludePaymentId !== null) {
        $sql .= " AND id <> :exclude_payment_id";
        $params[':exclude_payment_id'] = $excludePaymentId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (float)$stmt->fetchColumn();
}

function get_max_loan_payment_for_period(PDO $pdo, int $employeeId, float $remainingAmount, string $period): array
{
    $policy = payroll_loan_limit($pdo, $employeeId, $remainingAmount, $period);

    return [
        'salary_limit' => $policy['salary'],
        'current_period_payments' => $policy['current_period_payments'],
        'available_salary_limit' => $policy['available'],
        'max_payment' => $policy['max_payment'],
        'percent' => $policy['percent'],
        'period_cap' => $policy['period_cap'],
    ];
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

    if ($action === 'add_loan') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $loanDate = $_POST['loan_date'] ?? date('Y-m-d');
        $loanAmount = normalize_loan_money($_POST['loan_amount'] ?? 0);
        $loanOrigin = ($_POST['loan_origin'] ?? '') === 'opening_balance' ? 'opening_balance' : 'new_disbursement';
        $openingPaidAmount = $loanOrigin === 'opening_balance' ? normalize_loan_money($_POST['opening_paid_amount'] ?? 0) : 0;
        $notes = trim($_POST['notes'] ?? '');
        $postingMode = $loanOrigin === 'opening_balance' ? 'historical' : ($_POST['posting_mode'] ?? 'posted');
        $historicalReason = $loanOrigin === 'opening_balance' ? 'Saldo awal pinjaman sebelum penggunaan sistem' : trim($_POST['historical_reason'] ?? '');

        if ($employeeId <= 0) {
            $error = 'Karyawan wajib dipilih.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $loanDate)) {
            $error = 'Tanggal pinjaman tidak valid.';
        } elseif ($loanAmount <= 0) {
            $error = 'Nominal pinjaman harus lebih dari 0.';
        } elseif ($openingPaidAmount < 0 || $openingPaidAmount > $loanAmount) {
            $error = 'Total pembayaran sebelum sistem harus berada antara nol dan nominal pinjaman.';
        } else {
            try {
                $postingMode=finance_validate_posting_mode($postingMode,$historicalReason);
                $historicalReason=$postingMode==='historical'?trim($historicalReason):null;
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO employee_loans (
                        employee_id,
                        loan_date, loan_origin,
                        loan_amount,
                        opening_paid_amount, opening_balance_date, total_paid,
                        remaining_amount,
                        status,
                        notes,funding_source,account_id,operational_fund_id,funding_party_id,disbursement_status,posting_mode,historical_reason,payment_method,created_by
                    ) VALUES (
                        :employee_id,
                        :loan_date, :loan_origin,
                        :loan_amount,
                        :opening_paid_amount, :opening_balance_date, :opening_paid_amount_total,
                        :remaining_amount,
                        'active',
                        :notes,:funding_source,:account_id,:operational_fund_id,:funding_party_id,:disbursement_status,:posting_mode,:historical_reason,:payment_method,:created_by
                    )
                ");

                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':loan_date' => $loanDate,
                    ':loan_origin' => $loanOrigin,
                    ':loan_amount' => $loanAmount,
                    ':opening_paid_amount' => $openingPaidAmount,
                    ':opening_balance_date' => $loanOrigin === 'opening_balance' ? $loanDate : null,
                    ':opening_paid_amount_total' => $openingPaidAmount,
                    ':remaining_amount' => $loanAmount - $openingPaidAmount,
                    ':disbursement_status' => $loanOrigin === 'opening_balance' ? 'draft' : 'disbursed',
                    ':notes' => $notes !== '' ? $notes : null,':funding_source'=>$_POST['funding_source']??'company',':account_id'=>($_POST['account_id']??'')?:null,':operational_fund_id'=>($_POST['operational_fund_id']??'')?:null,':funding_party_id'=>($_POST['funding_party_id']??'')?:null,':posting_mode'=>$postingMode,':historical_reason'=>$historicalReason,':payment_method'=>$_POST['payment_method']??'cash',':created_by'=>$_SESSION['user_id']??null
                ]);
                $loanId=(int)$pdo->lastInsertId();
                if ($loanOrigin === 'new_disbursement') {
                    $links=employee_funding_disburse($pdo,'employee_loans',$loanId,$loanAmount,$loanDate,$_POST['funding_source']??'company',(int)($_POST['account_id']??0),(int)($_POST['operational_fund_id']??0),(int)($_POST['funding_party_id']??0),$_POST['payment_method']??'cash','Pencairan pinjaman karyawan #'.$loanId,$_SESSION['user_id']??null,$postingMode,$historicalReason);
                    $pdo->prepare("UPDATE employee_loans SET transaction_id=:tx,debt_id=:debt WHERE id=:id")->execute([':tx'=>$links['transaction_id'],':debt'=>$links['debt_id'],':id'=>$loanId]);
                }
                $pdo->commit();

                $success = 'Pinjaman karyawan berhasil ditambahkan.';
            } catch (Exception $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                $error = 'Gagal menambahkan pinjaman: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'add_payment') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $paymentPeriod = $_POST['payment_period'] ?? $month;
        $paymentAmount = normalize_loan_money($_POST['payment_amount'] ?? 0);
        $notes = trim($_POST['payment_notes'] ?? '');

        if ($loanId <= 0) {
            $error = 'Data pinjaman tidak valid.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            $error = 'Tanggal cicilan tidak valid.';
        } elseif (!preg_match('/^\d{4}-\d{2}$/', $paymentPeriod)) {
            $error = 'Periode cicilan tidak valid.';
        } elseif ($paymentAmount <= 0) {
            $error = 'Nominal cicilan harus lebih dari 0.';
        } else {
            try {
                $pdo->beginTransaction();

                $loanStmt = $pdo->prepare("
                    SELECT id, employee_id, remaining_amount, status
                    FROM employee_loans
                    WHERE id = :id
                    FOR UPDATE
                ");
                $loanStmt->execute([':id' => $loanId]);
                $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);

                if (!$loan) {
                    throw new Exception('Pinjaman tidak ditemukan.');
                }

                if ($loan['status'] !== 'active') {
                    throw new Exception('Cicilan hanya bisa ditambahkan untuk pinjaman aktif.');
                }

                $remainingAmount = (float)$loan['remaining_amount'];

                if ($paymentAmount > $remainingAmount) {
                    throw new Exception('Nominal cicilan lebih besar dari sisa pinjaman. Sisa saat ini: ' . loan_money($remainingAmount));
                }

                $limitInfo = get_max_loan_payment_for_period($pdo, (int)$loan['employee_id'], $remainingAmount, $paymentPeriod);

                if ($limitInfo['salary_limit'] <= 0) {
                    throw new Exception('Gaji karyawan belum tersedia. Lengkapi salary_amount karyawan atau generate payroll periode tersebut terlebih dahulu.');
                }

                if ($paymentAmount > $limitInfo['max_payment']) {
                    throw new Exception(
                        'Nominal cicilan melebihi batas gaji periode ini. ' .
                            'Batas cicilan yang bisa ditambahkan saat ini: ' . loan_money($limitInfo['max_payment']) . '. ' .
                            'Gaji tersedia: ' . loan_money($limitInfo['salary_limit']) . ', ' .
                            'cicilan loan lain periode ini: ' . loan_money($limitInfo['current_period_payments']) . ', ' .
                            'sisa pinjaman: ' . loan_money($remainingAmount) . '.'
                    );
                }

                [$periodYear, $periodMonth] = explode('-', $paymentPeriod);

                $insertStmt = $pdo->prepare("
                    INSERT INTO employee_loan_payments (
                        loan_id,
                        employee_id,
                        payroll_id,
                        payment_date,
                        period_month,
                        period_year,
                        payment_amount,
                        notes
                    ) VALUES (
                        :loan_id,
                        :employee_id,
                        NULL,
                        :payment_date,
                        :period_month,
                        :period_year,
                        :payment_amount,
                        :notes
                    )
                ");

                $insertStmt->execute([
                    ':loan_id' => $loanId,
                    ':employee_id' => (int)$loan['employee_id'],
                    ':payment_date' => $paymentDate,
                    ':period_month' => (int)$periodMonth,
                    ':period_year' => (int)$periodYear,
                    ':payment_amount' => $paymentAmount,
                    ':notes' => $notes !== '' ? $notes : null
                ]);

                recalculate_employee_loan($pdo, $loanId);

                $pdo->commit();
                $success = 'Cicilan pinjaman berhasil ditambahkan. Jangan lupa Generate / Recalculate Payroll agar cicilan periode ini masuk sebagai potongan gaji.';
                $month = $paymentPeriod;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Gagal menambahkan cicilan: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);

        if ($paymentId > 0) {
            try {
                $pdo->beginTransaction();

                $paymentStmt = $pdo->prepare("
                    SELECT loan_id
                    FROM employee_loan_payments
                    WHERE id = :id
                    LIMIT 1
                ");
                $paymentStmt->execute([':id' => $paymentId]);
                $loanId = (int)$paymentStmt->fetchColumn();

                if ($loanId <= 0) {
                    throw new Exception('Data cicilan tidak ditemukan.');
                }

                $deleteStmt = $pdo->prepare("DELETE FROM employee_loan_payments WHERE id = :id");
                $deleteStmt->execute([':id' => $paymentId]);

                recalculate_employee_loan($pdo, $loanId);

                $pdo->commit();
                $success = 'Cicilan berhasil dihapus dan sisa pinjaman dihitung ulang.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = 'Gagal menghapus cicilan: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'mark_paid') {
        $error = 'Pinjaman hanya dapat dilunasi melalui pencatatan cicilan agar histori pembayaran tetap lengkap.';
    }

    if ($action === 'cancel') {
        $loanId = (int)($_POST['loan_id'] ?? 0);

        if ($loanId > 0) {
            try {
                $pdo->beginTransaction();
                employee_funding_cancel($pdo,'employee_loans',$loanId);
                $pdo->commit();
                $success = 'Pinjaman berhasil dibatalkan dan sumber dana dikembalikan.';
            } catch (Exception $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                $error = 'Gagal membatalkan pinjaman: '.$e->getMessage();
            }
        }
    }

    if ($action === 'reactivate') {
        $loanId = (int)($_POST['loan_id'] ?? 0);

        if ($loanId > 0) {
            try {
                $pdo->beginTransaction();employee_funding_clear($pdo,'employee_loans',$loanId);
                recalculate_employee_loan($pdo, $loanId);
                $success = 'Status pinjaman berhasil diaktifkan kembali sesuai sisa pinjaman.';
            } catch (Exception $e) {
                $error = 'Gagal mengaktifkan pinjaman: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);

        if ($loanId > 0) {
            try {
                $deleteStmt = $pdo->prepare("DELETE FROM employee_loans WHERE id = :id");
                $deleteStmt->execute([':id' => $loanId]);
                $pdo->commit();

                $success = 'Data pinjaman berhasil dihapus permanen.';
            } catch (Exception $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                $error = 'Gagal menghapus pinjaman: ' . $e->getMessage();
            }
        }
    }
}

$where = [];
$params = [];

if ($keyword !== '') {
    $where[] = "(
        e.full_name LIKE :keyword_name
        OR e.employee_code LIKE :keyword_code
        OR e.position LIKE :keyword_position
        OR e.department LIKE :keyword_department
        OR el.notes LIKE :keyword_notes
    )";
    $keywordValue = '%' . $keyword . '%';
    $params[':keyword_name'] = $keywordValue;
    $params[':keyword_code'] = $keywordValue;
    $params[':keyword_position'] = $keywordValue;
    $params[':keyword_department'] = $keywordValue;
    $params[':keyword_notes'] = $keywordValue;
}

if ($statusFilter !== '') {
    $where[] = "el.status = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        el.*,
        e.full_name,
        e.employee_code,
        e.position,
        e.department,
        fa.account_name, ofu.fund_code, ofu.person_name AS fund_person_name,
        COALESCE(fp.display_name,ofu.person_name) AS fund_holder_name,
        cp.display_name AS funding_party_name,
        COALESCE(period_payments.period_total, 0) AS current_period_payment
    FROM employee_loans el
    JOIN employees e ON el.employee_id = e.id
    LEFT JOIN finance_accounts fa ON fa.id=el.account_id
    LEFT JOIN operational_funds ofu ON ofu.id=el.operational_fund_id
    LEFT JOIN company_parties fp ON fp.id=ofu.holder_party_id
    LEFT JOIN company_parties cp ON cp.id=el.funding_party_id
    LEFT JOIN (
        SELECT
            loan_id,
            SUM(payment_amount) AS period_total
        FROM employee_loan_payments
        WHERE period_year = :period_year
          AND period_month = :period_month
        GROUP BY loan_id
    ) period_payments ON period_payments.loan_id = el.id
    {$whereSql}
    ORDER BY
        FIELD(el.status, 'active', 'paid', 'cancelled'),
        el.loan_date DESC,
        e.full_name ASC
");

$queryParams = $params;
$queryParams[':period_year'] = (int)substr($month, 0, 4);
$queryParams[':period_month'] = (int)substr($month, 5, 2);
$stmt->execute($queryParams);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($loans as &$loan) {
    $limitInfo = get_max_loan_payment_for_period(
        $pdo,
        (int)$loan['employee_id'],
        (float)$loan['remaining_amount'],
        $month
    );

    $loan['salary_limit'] = $limitInfo['salary_limit'];
    $loan['current_employee_period_payments'] = $limitInfo['current_period_payments'];
    $loan['available_salary_limit'] = $limitInfo['available_salary_limit'];
    $loan['max_payment_this_period'] = $limitInfo['max_payment'];
}
unset($loan);

$paymentsStmt = $pdo->prepare("
    SELECT
        elp.*,
        e.full_name,
        e.employee_code,
        el.loan_amount,
        p.id AS payroll_exists
    FROM employee_loan_payments elp
    JOIN employees e ON elp.employee_id = e.id
    JOIN employee_loans el ON elp.loan_id = el.id
    LEFT JOIN payrolls p ON elp.payroll_id = p.id
    WHERE elp.period_year = :period_year
      AND elp.period_month = :period_month
    ORDER BY elp.payment_date DESC, e.full_name ASC
");
$paymentsStmt->execute([
    ':period_year' => (int)substr($month, 0, 4),
    ':period_month' => (int)substr($month, 5, 2)
]);
$periodPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'count' => count($loans),
    'active' => 0,
    'paid' => 0,
    'cancelled' => 0,
    'total_loan' => 0,
    'total_paid' => 0,
    'total_remaining' => 0,
    'period_payment' => 0
];

foreach ($loans as $loan) {
    if (isset($summary[$loan['status']])) {
        $summary[$loan['status']]++;
    }

    $summary['total_loan'] += (float)$loan['loan_amount'];
    $summary['total_paid'] += (float)$loan['total_paid'];

    if ($loan['status'] === 'active') {
        $summary['total_remaining'] += (float)$loan['remaining_amount'];
    }

    $summary['period_payment'] += (float)$loan['current_period_payment'];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Pinjaman Karyawan</h1>
            <p class="text-muted mb-0">
                Kelola pinjaman karyawan dengan cicilan fleksibel per periode payroll.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/payroll/index.php?month=' . urlencode($month)); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Payroll
            </a>

            <a href="<?= base_url('pages/payroll/cash_advance.php?month=' . urlencode($month)); ?>" class="btn btn-outline-primary">
                <i class="bi bi-wallet2 me-1"></i> Kasbon
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

    <div class="alert alert-info rounded-3">
        <strong>Aturan Cicilan:</strong> Total cicilan maksimal <?= htmlspecialchars((string)payroll_loan_installment_percent($pdo)); ?>% dari gaji periode yang dipilih dan tidak boleh melebihi sisa pinjaman. Jika payroll sudah digenerate, sistem memakai gaji bersih sebelum loan; jika belum, sistem memakai estimasi dari master gaji.
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Total Data Pinjaman</div>
                <div class="stat-number"><?= (int)$summary['count']; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Pinjaman Aktif</div>
                <div class="stat-number"><?= (int)$summary['active']; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Sisa Pinjaman Aktif</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= loan_money($summary['total_remaining']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Cicilan Periode Ini</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= loan_money($summary['period_payment']); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">Tambah Pinjaman Baru</h5>

        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_loan">
            <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">

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
            <div class="col-md-3"><label class="form-label fw-semibold">Jenis Pencatatan</label><select name="loan_origin" class="form-select"><option value="new_disbursement">Pinjaman Baru (kurangi dana)</option><option value="opening_balance">Saldo Awal/Lama (tanpa kurangi dana)</option></select><small class="text-muted">Sumber dana diabaikan untuk saldo awal.</small></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Sudah Dibayar Sebelum Sistem</label><input type="text" name="opening_paid_amount" class="form-control money-input" value="0"><small class="text-muted">Diisi hanya untuk saldo awal.</small></div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Tanggal Pinjaman</label>
                <input type="date" name="loan_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Nominal Pinjaman</label>
                <input type="text" name="loan_amount" class="form-control money-input" placeholder="Contoh: 5.000.000" required>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Catatan</label>
                <input type="text" name="notes" class="form-control" placeholder="Contoh: Pinjaman pribadi / darurat">
            </div>

            <div class="col-md-3"><label class="form-label fw-semibold">Sumber Dana</label><select name="funding_source" class="form-select funding-source"><option value="company">Kas/Bank Perusahaan</option><option value="operational_fund">Dana Operasional PIC</option><option value="party_advance">Talangan Pihak/PIC</option></select></div>
            <div class="col-md-3 funding-choice" data-source="company"><label class="form-label">Akun Kas/Bank</label><select name="account_id" class="form-select"><option value="">Pilih akun</option><?php foreach($fundingOptions['accounts'] as $a):?><option value="<?=$a['id'];?>"><?=htmlspecialchars($a['account_name']);?> · <?=loan_money($a['current_balance']);?></option><?php endforeach;?></select></div>
            <div class="col-md-3 funding-choice d-none" data-source="operational_fund"><label class="form-label">Dana Operasional PIC</label><select name="operational_fund_id" class="form-select"><option value="">Pilih dana</option><?php foreach($fundingOptions['funds'] as $f):?><option value="<?=$f['id'];?>"><?=htmlspecialchars($f['display_name']?:$f['person_name']);?> · <?=loan_money($f['current_balance']);?></option><?php endforeach;?></select></div>
            <div class="col-md-3 funding-choice d-none" data-source="party_advance"><label class="form-label">Pihak/PIC Penalang</label><select name="funding_party_id" class="form-select"><option value="">Pilih pihak</option><?php foreach($fundingOptions['parties'] as $p):?><option value="<?=$p['id'];?>"><?=htmlspecialchars($p['display_name']);?></option><?php endforeach;?></select></div>
            <div class="col-md-3"><label class="form-label">Metode</label><select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="bank_transfer">Transfer</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
            <?php if (finance_user_can_use_non_posting()): ?>
            <div class="col-md-4"><label class="form-label fw-semibold">Pengaruh ke Saldo</label><select name="posting_mode" class="form-select posting-mode"><option value="posted">Pencairan baru — kurangi saldo</option><option value="historical">Pencairan lama — tidak ubah saldo</option></select></div>
            <div class="col-md-8 historical-reason d-none"><label class="form-label fw-semibold">Alasan Catatan Lama</label><input type="text" name="historical_reason" class="form-control" placeholder="Wajib jika memilih catatan lama"></div>
            <?php else: ?><input type="hidden" name="posting_mode" value="posted"><?php endif; ?>

            <div class="col-md-12">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-save me-1"></i> Simpan Pinjaman
                </button>
            </div>
        </form>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-semibold">Periode Payroll</label>
                <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Cari</label>
                <input type="text" name="keyword" class="form-control" value="<?= htmlspecialchars($keyword); ?>" placeholder="Nama, kode, jabatan, catatan">
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
                <a href="<?= base_url('pages/payroll/loans.php'); ?>" class="btn btn-outline-secondary">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="panel mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Daftar Pinjaman</h5>
                <p class="text-muted mb-0">
                    Tambahkan cicilan sesuai kemampuan potong gaji pada periode payroll yang dipilih.
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
            <table class="table table-hover align-middle" id="loanTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Tanggal</th>
                        <th>Pinjaman</th>
                        <th>Sumber Dana</th>
                        <th>Terbayar</th>
                        <th>Sisa</th>
                        <th>Cicilan Periode Ini</th>
                        <th>Batas Cicilan</th>
                        <th>Status</th>
                        <th>Catatan</th>
                        <th width="380">Tambah Cicilan</th>
                        <th width="220">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($loans): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($loans as $loan): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($loan['full_name']); ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($loan['employee_code'] ?? '-'); ?> ·
                                        <?= htmlspecialchars($loan['position'] ?? '-'); ?>
                                        <?= !empty($loan['department']) ? ' · ' . htmlspecialchars($loan['department']) : ''; ?>
                                    </small>
                                </td>

                                <td><?= loan_safe_date($loan['loan_date']); ?></td>

                                <td class="fw-semibold">
                                    <?= loan_money($loan['loan_amount']); ?>
                                </td>

                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(employee_funding_source_label($loan)); ?></span><div><small class="text-muted"><?= htmlspecialchars(ucwords(str_replace('_',' ',$loan['payment_method']??'cash'))); ?></small></div></td>

                                <td class="fw-semibold text-success">
                                    <?= loan_money($loan['total_paid']); ?>
                                </td>

                                <td class="fw-semibold text-danger">
                                    <?= loan_money($loan['remaining_amount']); ?>
                                </td>

                                <td class="fw-semibold text-primary">
                                    <?= loan_money($loan['current_period_payment']); ?>
                                </td>

                                <td>
                                    <div class="fw-semibold <?= (float)$loan['max_payment_this_period'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?= loan_money($loan['max_payment_this_period']); ?>
                                    </div>
                                    <small class="text-muted d-block">
                                        Gaji acuan: <?= loan_money($loan['salary_limit']); ?>
                                    </small>
                                    <small class="text-muted d-block">
                                        Cicilan bulan ini: <?= loan_money($loan['current_employee_period_payments']); ?>
                                    </small>
                                </td>

                                <td><?= loan_status_badge($loan['status']); ?></td>

                                <td>
                                    <?= !empty($loan['notes']) ? nl2br(htmlspecialchars($loan['notes'])) : '<span class="text-muted">-</span>'; ?>
                                </td>

                                <td>
                                    <?php if ($loan['status'] === 'active'): ?>
                                        <form method="POST" class="row g-2 align-items-end loan-period-form" data-employee-id="<?= (int)$loan['employee_id']; ?>" data-remaining="<?= (float)$loan['remaining_amount']; ?>">
                                            <input type="hidden" name="action" value="add_payment">
                                            <input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>">
                                            <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">

                                            <div class="col-6">
                                                <label class="form-label small mb-1">Periode</label>
                                                <select name="payment_period" class="form-select form-select-sm loan-period-input" required>
                                                    <?php foreach (payroll_month_options($month) as $periodValue => $periodLabel): ?>
                                                        <option value="<?= htmlspecialchars($periodValue); ?>" <?= $periodValue === $month ? 'selected' : ''; ?>><?= htmlspecialchars($periodLabel); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="col-6">
                                                <label class="form-label small mb-1">Tanggal</label>
                                                <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d'); ?>" required>
                                            </div>

                                            <div class="col-7">
                                                <label class="form-label small mb-1">Nominal</label>
                                                <input
                                                    type="text"
                                                    name="payment_amount"
                                                    class="form-control form-control-sm money-input loan-payment-input"
                                                    placeholder="200.000"
                                                    data-max-payment="<?= (float)$loan['max_payment_this_period']; ?>"
                                                    data-remaining="<?= (float)$loan['remaining_amount']; ?>"
                                                    required>
                                                <small class="text-muted d-block mt-1 loan-max-label">
                                                    Maks: <?= loan_money($loan['max_payment_this_period']); ?>
                                                </small>
                                            </div>

                                            <div class="col-5 d-grid">
                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-primary"
                                                    <?= (float)$loan['max_payment_this_period'] <= 0 ? 'disabled' : ''; ?>
                                                    onclick="return confirm('Tambahkan cicilan pinjaman untuk periode ini?');">
                                                    Tambah
                                                </button>
                                            </div>

                                            <div class="col-12">
                                                <input type="text" name="payment_notes" class="form-control form-control-sm" placeholder="Catatan cicilan (opsional)">
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">Tidak tersedia</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php if ($loan['status'] === 'active'): ?>
                                            <form method="POST" onsubmit="return confirm('Batalkan pinjaman ini? Data tetap tersimpan sebagai arsip.');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>">
                                                <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    Batal
                                                </button>
                                            </form>
                                        <?php elseif ($loan['status'] === 'cancelled'): ?>
                                            <form method="POST" onsubmit="return confirm('Aktifkan kembali pinjaman ini?');">
                                                <input type="hidden" name="action" value="reactivate">
                                                <input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>">
                                                <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    Aktifkan
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" onsubmit="return confirm('Hapus permanen pinjaman ini? Semua histori cicilan juga akan terhapus.');">
                                            <input type="hidden" name="action" value="delete_loan">
                                            <input type="hidden" name="loan_id" value="<?= (int)$loan['id']; ?>">
                                            <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
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
                            <td colspan="12" class="text-center text-muted py-4">
                                Belum ada data pinjaman karyawan.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($loans): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="3">TOTAL</td>
                            <td><?= loan_money($summary['total_loan']); ?></td>
                            <td class="text-success"><?= loan_money($summary['total_paid']); ?></td>
                            <td class="text-danger"><?= loan_money($summary['total_remaining']); ?></td>
                            <td class="text-primary"><?= loan_money($summary['period_payment']); ?></td>
                            <td colspan="5"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Histori Cicilan Periode <?= htmlspecialchars($month); ?></h5>
                <p class="text-muted mb-0">
                    Daftar cicilan yang akan menjadi potongan pinjaman pada periode payroll ini.
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Tanggal</th>
                        <th>Periode</th>
                        <th>Nominal Cicilan</th>
                        <th>Payroll</th>
                        <th>Catatan</th>
                        <th width="120">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($periodPayments): ?>
                        <?php $paymentNo = 1; ?>
                        <?php foreach ($periodPayments as $payment): ?>
                            <tr>
                                <td><?= $paymentNo++; ?></td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($payment['full_name']); ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($payment['employee_code'] ?? '-'); ?></small>
                                </td>
                                <td><?= loan_safe_date($payment['payment_date']); ?></td>
                                <td><?= loan_period_label($payment['period_year'], $payment['period_month']); ?></td>
                                <td class="fw-semibold text-danger"><?= loan_money($payment['payment_amount']); ?></td>
                                <td>
                                    <?php if (!empty($payment['payroll_id'])): ?>
                                        <span class="badge rounded-pill badge-soft-green">Terhubung Payroll</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-orange">Belum Generate</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= !empty($payment['notes']) ? nl2br(htmlspecialchars($payment['notes'])) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td>
                                    <?php if (empty($payment['payroll_id'])): ?>
                                        <form method="POST" onsubmit="return confirm('Hapus cicilan ini? Sisa pinjaman akan dihitung ulang.');">
                                            <input type="hidden" name="action" value="delete_payment">
                                            <input type="hidden" name="payment_id" value="<?= (int)$payment['id']; ?>">
                                            <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">Locked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                Belum ada cicilan pinjaman pada periode ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($periodPayments): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="4">TOTAL CICILAN PERIODE INI</td>
                            <td class="text-danger"><?= loan_money($summary['period_payment']); ?></td>
                            <td colspan="3"></td>
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
        const moneyInputs = document.querySelectorAll('.money-input');

        moneyInputs.forEach(function(input) {
            input.addEventListener('input', function() {
                let value = this.value.replace(/[^0-9]/g, '');

                if (value === '') {
                    this.value = '';
                    return;
                }

                this.value = new Intl.NumberFormat('id-ID').format(value);
            });
        });

        const loanPaymentInputs = document.querySelectorAll('.loan-payment-input');

        loanPaymentInputs.forEach(function(input) {
            input.addEventListener('blur', function() {
                const numericValue = parseFloat(this.value.replace(/[^0-9]/g, '') || '0');
                const maxPayment = parseFloat(this.dataset.maxPayment || '0');

                if (maxPayment > 0 && numericValue > maxPayment) {
                    alert('Nominal cicilan melebihi batas cicilan periode ini. Maksimal: Rp ' + new Intl.NumberFormat('id-ID').format(maxPayment));
                    this.value = new Intl.NumberFormat('id-ID').format(Math.floor(maxPayment));
                }
            });
        });

        const loanPaymentForms = document.querySelectorAll('form');
        loanPaymentForms.forEach(function(form) {
            const paymentInput = form.querySelector('.loan-payment-input');
            if (!paymentInput) return;

            form.addEventListener('submit', function(event) {
                const numericValue = parseFloat(paymentInput.value.replace(/[^0-9]/g, '') || '0');
                const maxPayment = parseFloat(paymentInput.dataset.maxPayment || '0');

                if (numericValue <= 0) {
                    event.preventDefault();
                    alert('Nominal cicilan harus lebih dari 0.');
                    return;
                }

                if (numericValue > maxPayment) {
                    event.preventDefault();
                    alert('Nominal cicilan melebihi batas cicilan periode ini. Maksimal: Rp ' + new Intl.NumberFormat('id-ID').format(maxPayment));
                }
            });
        });

        document.querySelectorAll('.loan-period-form').forEach(function(form) {
            const periodInput = form.querySelector('.loan-period-input');
            const amountInput = form.querySelector('.loan-payment-input');
            const maxLabel = form.querySelector('.loan-max-label');
            const submitButton = form.querySelector('button[type="submit"]');
            if (!periodInput || !amountInput) return;

            periodInput.addEventListener('change', async function() {
                submitButton.disabled = true;
                if (maxLabel) maxLabel.textContent = 'Menghitung batas periode...';
                const query = new URLSearchParams({
                    employee_id: form.dataset.employeeId,
                    remaining: form.dataset.remaining,
                    period: periodInput.value
                });
                try {
                    const response = await fetch(<?= json_encode(base_url('pages/payroll/loan_limit.php')); ?> + '?' + query.toString(), {headers: {'Accept': 'application/json'}});
                    const data = await response.json();
                    if (!response.ok || !data.ok) throw new Error(data.message || 'Batas cicilan tidak dapat dihitung.');
                    amountInput.dataset.maxPayment = String(data.max_payment || 0);
                    if (maxLabel) maxLabel.textContent = 'Maks: Rp ' + new Intl.NumberFormat('id-ID').format(Math.floor(data.max_payment || 0)) + ' (' + data.percent + '% dari gaji periode)';
                    submitButton.disabled = Number(data.max_payment || 0) <= 0;
                    amountInput.dispatchEvent(new Event('blur'));
                } catch (error) {
                    amountInput.dataset.maxPayment = '0';
                    if (maxLabel) maxLabel.textContent = error.message;
                    submitButton.disabled = true;
                }
            });
        });

        const searchInput = document.getElementById('tableSearch');
        const table = document.getElementById('loanTable');

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
