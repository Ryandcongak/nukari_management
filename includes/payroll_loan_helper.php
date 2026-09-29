<?php

if (!function_exists('payroll_loan_column_exists')) {
    function payroll_loan_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name LIMIT 1");
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (bool)$stmt->fetchColumn();
    }
}

function payroll_ensure_loan_policy_schema(PDO $pdo): void
{
    if (!payroll_loan_column_exists($pdo, 'payroll_settings', 'loan_installment_max_percent')) {
        $pdo->exec("ALTER TABLE payroll_settings ADD COLUMN loan_installment_max_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER default_overtime_fixed_amount");
    }
    $columns = [
        'loan_total_snapshot' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER loan_deduction",
        'loan_paid_snapshot' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER loan_total_snapshot",
        'loan_remaining_snapshot' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER loan_paid_snapshot",
        'loan_payment_count_snapshot' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER loan_remaining_snapshot",
    ];
    foreach ($columns as $column => $definition) {
        if (!payroll_loan_column_exists($pdo, 'payrolls', $column)) {
            $pdo->exec("ALTER TABLE payrolls ADD COLUMN {$column} {$definition}");
        }
    }
}

function payroll_loan_installment_percent(PDO $pdo): float
{
    $percent = (float)($pdo->query("SELECT loan_installment_max_percent FROM payroll_settings ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 50);
    return min(100, max(1, $percent));
}

function payroll_loan_period_salary(PDO $pdo, int $employeeId, string $period): float
{
    if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period)) return 0;

    $stmt = $pdo->prepare("SELECT net_salary,loan_deduction,base_salary FROM payrolls WHERE employee_id=:employee_id AND payroll_month=:period LIMIT 1");
    $stmt->execute([':employee_id' => $employeeId, ':period' => $period]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($payroll) {
        $beforeLoan = (float)$payroll['net_salary'] + (float)$payroll['loan_deduction'];
        if ($beforeLoan > 0) return $beforeLoan;
        if ((float)$payroll['base_salary'] > 0) return (float)$payroll['base_salary'];
    }

    $stmt = $pdo->prepare("SELECT salary_type,salary_amount FROM employees WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => $employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) return 0;
    $amount = max(0, (float)$employee['salary_amount']);
    return ($employee['salary_type'] ?? 'monthly') === 'daily' ? $amount * 26 : $amount;
}

function payroll_loan_period_payment_total(PDO $pdo, int $employeeId, string $period): float
{
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) return 0;
    [$year, $month] = array_map('intval', explode('-', $period));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM employee_loan_payments WHERE employee_id=:employee_id AND period_year=:year AND period_month=:month");
    $stmt->execute([':employee_id' => $employeeId, ':year' => $year, ':month' => $month]);
    return (float)$stmt->fetchColumn();
}

function payroll_loan_calculate_limit(float $salary, float $percent, float $currentPayments, float $remainingAmount): array
{
    $salary = max(0, $salary);
    $percent = min(100, max(1, $percent));
    $currentPayments = max(0, $currentPayments);
    $periodCap = $salary * ($percent / 100);
    $available = max(0, $periodCap - $currentPayments);
    return [
        'salary' => $salary,
        'percent' => $percent,
        'period_cap' => $periodCap,
        'current_period_payments' => $currentPayments,
        'available' => $available,
        'max_payment' => min(max(0, $remainingAmount), $available),
    ];
}

function payroll_loan_limit(PDO $pdo, int $employeeId, float $remainingAmount, string $period): array
{
    $salary = payroll_loan_period_salary($pdo, $employeeId, $period);
    $percent = payroll_loan_installment_percent($pdo);
    $currentPayments = payroll_loan_period_payment_total($pdo, $employeeId, $period);
    return payroll_loan_calculate_limit($salary, $percent, $currentPayments, $remainingAmount);
}

function payroll_loan_snapshot(PDO $pdo, int $employeeId, string $period, string $periodEnd): array
{
    [$year, $month] = array_map('intval', explode('-', $period));
    $periodKey = ($year * 100) + $month;
    $loanStmt = $pdo->prepare("SELECT COALESCE(SUM(l.loan_amount),0) total_amount,COALESCE(SUM(l.opening_paid_amount),0) opening_paid FROM employee_loans l WHERE l.employee_id=:employee_id AND l.status<>'cancelled' AND (l.loan_date<=:period_end OR EXISTS (SELECT 1 FROM employee_loan_payments lp WHERE lp.loan_id=l.id AND ((lp.period_year*100)+lp.period_month)<=:period_key))");
    $loanStmt->execute([':employee_id' => $employeeId, ':period_end' => $periodEnd, ':period_key' => $periodKey]);
    $loan = $loanStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $paymentStmt = $pdo->prepare("SELECT COALESCE(SUM(lp.payment_amount),0) paid_amount,COUNT(*) payment_count FROM employee_loan_payments lp INNER JOIN employee_loans l ON l.id=lp.loan_id WHERE lp.employee_id=:employee_id AND l.status<>'cancelled' AND (l.loan_date<=:period_end OR ((lp.period_year*100)+lp.period_month)<=:period_key_scope) AND ((lp.period_year*100)+lp.period_month)<=:period_key_limit");
    $paymentStmt->execute([':employee_id' => $employeeId, ':period_end' => $periodEnd, ':period_key_scope' => $periodKey, ':period_key_limit' => $periodKey]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (float)($loan['total_amount'] ?? 0);
    $paid = (float)($loan['opening_paid'] ?? 0) + (float)($payment['paid_amount'] ?? 0);
    return [
        'total' => $total,
        'paid' => min($total, $paid),
        'remaining' => max(0, $total - $paid),
        'payment_count' => (int)($payment['payment_count'] ?? 0),
    ];
}
