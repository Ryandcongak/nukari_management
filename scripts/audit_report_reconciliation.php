<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../pages/reports/report_data.php';

$from = '2000-01-01';
$to = '2099-12-31';
$failures = [];
$results = [];

function report_assert_close(array &$failures, string $label, float $actual, float $expected): void
{
    if (abs($actual - $expected) > 0.01) {
        $failures[] = ['metric' => $label, 'report' => $actual, 'source' => $expected];
    }
}

function report_sum(array $rows, string $column): float
{
    return array_sum(array_map(static fn(array $row): float => (float)($row[$column] ?? 0), $rows));
}

$finance = nr_report($pdo, 'finance', $from, $to);
$financeSource = $pdo->query("SELECT COUNT(*) row_count,COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense FROM transactions WHERE posting_mode='posted' AND account_id IS NOT NULL AND transaction_date BETWEEN '2000-01-01' AND '2099-12-31'")->fetch(PDO::FETCH_ASSOC);
$fundFlowSource = $pdo->query("SELECT COUNT(*) row_count,COALESCE(SUM(CASE WHEN movement_type='adjustment' THEN amount ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN movement_type='expense' THEN amount ELSE 0 END),0) expense FROM operational_fund_movements WHERE posting_mode='posted' AND movement_type IN ('expense','adjustment') AND movement_date BETWEEN '2000-01-01' AND '2099-12-31'")->fetch(PDO::FETCH_ASSOC);
$expectedFinanceRows=(float)$financeSource['row_count']+(float)$fundFlowSource['row_count'];
$expectedFinanceIncome=(float)$financeSource['income']+(float)$fundFlowSource['income'];
$expectedFinanceExpense=(float)$financeSource['expense']+(float)$fundFlowSource['expense'];
report_assert_close($failures, 'finance.rows', count($finance['raw']), $expectedFinanceRows);
report_assert_close($failures, 'finance.income', report_sum(array_filter($finance['raw'], fn($row) => $row['type'] === 'income'), 'amount'), $expectedFinanceIncome);
report_assert_close($failures, 'finance.expense', report_sum(array_filter($finance['raw'], fn($row) => $row['type'] === 'expense'), 'amount'), $expectedFinanceExpense);
if (empty($finance['finance'])) {
    $failures[]=['metric'=>'finance.position','message'=>'Ringkasan posisi uang tidak tersedia.'];
} else {
    $storedCentral=(float)$pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM finance_accounts WHERE status='active'")->fetchColumn();
    $storedPic=(float)$pdo->query("SELECT COALESCE(SUM(GREATEST(current_balance,0)),0) FROM operational_funds WHERE status<>'cancelled'")->fetchColumn();
    report_assert_close($failures,'finance.central_cash',$finance['finance']['ending']['central_cash'],$storedCentral);
    report_assert_close($failures,'finance.pic_cash',$finance['finance']['ending']['pic_cash'],$storedPic);
}
$results['finance'] = ['rows' => count($finance['raw']), 'income' => $expectedFinanceIncome, 'expense' => $expectedFinanceExpense, 'total_cash'=>(float)($finance['finance']['ending']['total_cash']??0)];

$projects = nr_report($pdo, 'projects', $from, $to);
$projectSource = $pdo->query("SELECT COUNT(*) row_count,COALESCE(SUM(contract_value),0) contracts FROM projects WHERE (start_date IS NULL OR start_date<='2099-12-31') AND (end_date IS NULL OR end_date>='2000-01-01')")->fetch(PDO::FETCH_ASSOC);
$termSource = (float)$pdo->query("SELECT COALESCE(SUM(t.amount),0) FROM project_payment_terms t JOIN projects p ON p.id=t.project_id WHERE t.status='paid' AND (p.start_date IS NULL OR p.start_date<='2099-12-31') AND (p.end_date IS NULL OR p.end_date>='2000-01-01')")->fetchColumn();
$projectExpenseSource = (float)$pdo->query("SELECT COALESCE(SUM(t.amount),0) FROM project_transactions t JOIN projects p ON p.id=t.project_id WHERE t.transaction_type='expense' AND (p.start_date IS NULL OR p.start_date<='2099-12-31') AND (p.end_date IS NULL OR p.end_date>='2000-01-01')")->fetchColumn();
report_assert_close($failures, 'projects.rows', count($projects['raw']), (float)$projectSource['row_count']);
report_assert_close($failures, 'projects.contracts', report_sum($projects['raw'], 'contract_value'), (float)$projectSource['contracts']);
report_assert_close($failures, 'projects.paid_terms', report_sum($projects['raw'], 'paid_amount'), $termSource);
report_assert_close($failures, 'projects.expenses', report_sum($projects['raw'], 'expense'), $projectExpenseSource);
$results['projects'] = ['rows' => count($projects['raw']), 'contracts' => (float)$projectSource['contracts'], 'paid_terms' => $termSource, 'expenses' => $projectExpenseSource];

$payroll = nr_report($pdo, 'payroll', $from, $to);
$payrollSource = $pdo->query("SELECT COUNT(*) row_count,COALESCE(SUM(base_salary),0) base_salary,COALESCE(SUM(additional_amount),0) additional_amount,COALESCE(SUM(total_deduction+total_tax+bpjs_health+bpjs_employment),0) deductions,COALESCE(SUM(net_salary),0) net_salary FROM payrolls WHERE CONCAT(payroll_month,'-01') BETWEEN '2000-01-01' AND '2099-12-31'")->fetch(PDO::FETCH_ASSOC);
report_assert_close($failures, 'payroll.rows', count($payroll['raw']), (float)$payrollSource['row_count']);
report_assert_close($failures, 'payroll.base_salary', report_sum($payroll['raw'], 'base_salary'), (float)$payrollSource['base_salary']);
report_assert_close($failures, 'payroll.additional_amount', report_sum($payroll['raw'], 'additional_amount'), (float)$payrollSource['additional_amount']);
report_assert_close($failures, 'payroll.deductions', report_sum($payroll['raw'], 'deductions'), (float)$payrollSource['deductions']);
report_assert_close($failures, 'payroll.net_salary', report_sum($payroll['raw'], 'net_salary'), (float)$payrollSource['net_salary']);
$results['payroll'] = array_map('floatval', $payrollSource);

$assets = nr_report($pdo, 'assets', $from, $to);
$assetSource = $pdo->query("SELECT COUNT(*) row_count,COALESCE(SUM(purchase_price),0) purchase_price,COALESCE(SUM(COALESCE(current_value,purchase_price)),0) current_value FROM assets")->fetch(PDO::FETCH_ASSOC);
report_assert_close($failures, 'assets.rows', count($assets['raw']), (float)$assetSource['row_count']);
report_assert_close($failures, 'assets.purchase_price', report_sum($assets['raw'], 'purchase_price'), (float)$assetSource['purchase_price']);
report_assert_close($failures, 'assets.current_value', report_sum($assets['raw'], 'current_value'), (float)$assetSource['current_value']);
$results['assets'] = array_map('floatval', $assetSource);

$products = nr_report($pdo, 'products', $from, $to);
$productSource = $pdo->query("SELECT COUNT(*) row_count,COALESCE(SUM(qty),0) qty,COALESCE(SUM(net_amount),0) net_amount,COALESCE(SUM(profit_amount),0) profit_amount FROM product_sales WHERE sale_date BETWEEN '2000-01-01' AND '2099-12-31'")->fetch(PDO::FETCH_ASSOC);
report_assert_close($failures, 'products.rows', count($products['raw']), (float)$productSource['row_count']);
report_assert_close($failures, 'products.qty', report_sum($products['raw'], 'qty'), (float)$productSource['qty']);
report_assert_close($failures, 'products.net_amount', report_sum($products['raw'], 'net_amount'), (float)$productSource['net_amount']);
report_assert_close($failures, 'products.profit_amount', report_sum($products['raw'], 'profit_amount'), (float)$productSource['profit_amount']);
$results['products'] = array_map('floatval', $productSource);

$employees = nr_report($pdo, 'employees', $from, $to);
$employeeSource = $pdo->query("SELECT COUNT(*) row_count,COALESCE(SUM(salary_amount),0) salary_amount FROM employees")->fetch(PDO::FETCH_ASSOC);
report_assert_close($failures, 'employees.rows', count($employees['raw']), (float)$employeeSource['row_count']);
report_assert_close($failures, 'employees.salary_amount', report_sum($employees['raw'], 'salary_amount'), (float)$employeeSource['salary_amount']);
$results['employees'] = array_map('floatval', $employeeSource);

foreach (['finance' => $finance, 'projects' => $projects, 'payroll' => $payroll, 'assets' => $assets, 'products' => $products, 'employees' => $employees] as $type => $report) {
    if ($report['error'] !== '') $failures[] = ['metric' => $type . '.error', 'message' => $report['error']];
}

echo json_encode([
    'status' => $failures ? 'failed' : 'ok',
    'period' => [$from, $to],
    'reports' => $results,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failures ? 1 : 0);
