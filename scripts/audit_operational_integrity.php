<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/operational_fund_helper.php';

$checks = [];
$failureCount = 0;

function audit_rows(PDO $pdo, array &$checks, int &$failureCount, string $name, string $sql): void
{
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $count = count($rows);
    $checks[$name] = ['mismatch_count' => $count, 'sample' => array_slice($rows, 0, 10)];
    $failureCount += $count;
}

// Seluruh foreign key harus menunjuk ke data induk yang masih tersedia.
$foreignKeys = $pdo->query("
    SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME,CONSTRAINT_NAME
")->fetchAll(PDO::FETCH_ASSOC);
$orphanRows = [];
foreach ($foreignKeys as $foreignKey) {
    foreach (['TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'] as $key) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$foreignKey[$key])) {
            throw new RuntimeException('Identifier foreign key tidak valid.');
        }
    }
    $sql = sprintf(
        'SELECT COUNT(*) FROM `%s` child LEFT JOIN `%s` parent ON parent.`%s`=child.`%s` WHERE child.`%s` IS NOT NULL AND parent.`%s` IS NULL',
        $foreignKey['TABLE_NAME'],
        $foreignKey['REFERENCED_TABLE_NAME'],
        $foreignKey['REFERENCED_COLUMN_NAME'],
        $foreignKey['COLUMN_NAME'],
        $foreignKey['COLUMN_NAME'],
        $foreignKey['REFERENCED_COLUMN_NAME']
    );
    $count = (int)$pdo->query($sql)->fetchColumn();
    if ($count > 0) {
        $orphanRows[] = [
            'constraint' => $foreignKey['CONSTRAINT_NAME'],
            'table' => $foreignKey['TABLE_NAME'],
            'orphan_count' => $count,
        ];
    }
}
$checks['foreign_key_orphans'] = [
    'constraints_checked' => count($foreignKeys),
    'mismatch_count' => count($orphanRows),
    'sample' => array_slice($orphanRows, 0, 10),
];
$failureCount += count($orphanRows);

// Saldo akun harus sama dengan saldo awal + transaksi dan efek Dana Operasional.
$accountRows = $pdo->query("
    SELECT a.id,a.account_name,a.opening_balance,a.current_balance,
           COALESCE(SUM(CASE WHEN t.type='income' THEN t.amount ELSE -t.amount END),0) AS transaction_effect
    FROM finance_accounts a
    LEFT JOIN transactions t ON t.account_id=a.id
    GROUP BY a.id
    ORDER BY a.id
")->fetchAll(PDO::FETCH_ASSOC);
$accountMismatches = [];
foreach ($accountRows as $account) {
    $effect = opfund_account_effect_totals($pdo, (int)$account['id']);
    $computed = (float)$account['opening_balance'] + (float)$account['transaction_effect']
        - (float)$effect['out_total'] + (float)$effect['in_total'];
    if (abs((float)$account['current_balance'] - $computed) > 0.01) {
        $accountMismatches[] = [
            'id' => (int)$account['id'],
            'account' => $account['account_name'],
            'stored' => (float)$account['current_balance'],
            'computed' => $computed,
        ];
    }
}
$checks['finance_account_balances'] = ['mismatch_count' => count($accountMismatches), 'sample' => $accountMismatches];
$failureCount += count($accountMismatches);

audit_rows($pdo, $checks, $failureCount, 'operational_fund_balances', "
    SELECT f.id,f.fund_code,f.total_received,f.total_used,f.total_returned,f.total_personal_advance,f.current_balance,
           COALESCE(SUM(CASE WHEN m.movement_type='receive' THEN m.amount ELSE 0 END),0) AS computed_received,
           COALESCE(SUM(CASE WHEN m.movement_type='expense' THEN m.amount ELSE 0 END),0) AS computed_used,
           COALESCE(SUM(CASE WHEN m.movement_type='return' THEN m.amount ELSE 0 END),0) AS computed_returned,
           COALESCE(SUM(CASE WHEN m.movement_type='adjustment' THEN m.amount ELSE 0 END),0) AS computed_adjustment
    FROM operational_funds f
    LEFT JOIN operational_fund_movements m ON m.fund_id=f.id
    GROUP BY f.id
    HAVING ABS(total_received-computed_received)>0.01
        OR ABS(total_used-computed_used)>0.01
        OR ABS(total_returned-computed_returned)>0.01
        OR ABS(current_balance-(computed_received-computed_used-computed_returned+computed_adjustment))>0.01
        OR ABS(total_personal_advance-GREATEST(0,-(computed_received-computed_used-computed_returned+computed_adjustment)))>0.01
");

audit_rows($pdo, $checks, $failureCount, 'payroll_net_salary', "
    SELECT id,employee_id,payroll_month,net_salary,
           GREATEST(base_salary+total_allowance+total_bonus+overtime_amount+additional_amount-total_deduction-total_tax-bpjs_health-bpjs_employment,0) AS computed
    FROM payrolls
    HAVING ABS(net_salary-computed)>0.01
");

audit_rows($pdo, $checks, $failureCount, 'paid_payroll_finance_links', "
    SELECT p.id,p.employee_id,p.payroll_month,p.net_salary,p.funding_source,p.payment_transaction_id,
           t.id AS transaction_id,t.amount AS transaction_amount,t.type AS transaction_type
    FROM payrolls p
    LEFT JOIN transactions t ON t.id=p.payment_transaction_id
    WHERE p.status='paid' AND p.funding_source='company'
      AND (t.id IS NULL OR t.type<>'expense' OR ABS(t.amount-p.net_salary)>0.01 OR t.source_module<>'payroll' OR t.source_id<>p.id)
");

audit_rows($pdo, $checks, $failureCount, 'employee_loan_balances', "
    SELECT l.id,l.loan_amount,l.opening_paid_amount,l.total_paid,l.remaining_amount,l.status,
           COALESCE(SUM(p.payment_amount),0) AS payment_total
    FROM employee_loans l
    LEFT JOIN employee_loan_payments p ON p.loan_id=l.id
    GROUP BY l.id
    HAVING ABS(total_paid-(opening_paid_amount+payment_total))>0.01
        OR ABS(remaining_amount-GREATEST(loan_amount-opening_paid_amount-payment_total,0))>0.01
        OR status<>CASE WHEN status='cancelled' THEN 'cancelled' WHEN remaining_amount<=0 THEN 'paid' ELSE 'active' END
");

audit_rows($pdo, $checks, $failureCount, 'cash_advance_balances', "
    SELECT id,amount,remaining_amount,status,disbursement_status,transaction_id,debt_id
    FROM employee_cash_advances
    WHERE remaining_amount<0 OR remaining_amount>amount
       OR (status='paid' AND remaining_amount>0.01)
       OR (status='cancelled' AND (disbursement_status<>'cancelled' OR transaction_id IS NOT NULL OR debt_id IS NOT NULL))
");

audit_rows($pdo, $checks, $failureCount, 'project_term_receivables', "
    SELECT d.id,d.source_id,d.amount,d.paid_amount,d.status,t.amount AS term_amount,t.received_amount,t.status AS term_status
    FROM debts d
    LEFT JOIN project_payment_terms t ON t.id=d.source_id
    WHERE d.source_module='project_term'
      AND (t.id IS NULL OR ABS(d.amount-t.amount)>0.01 OR ABS(d.paid_amount-t.received_amount)>0.01 OR d.status<>t.status)
");

audit_rows($pdo, $checks, $failureCount, 'product_sale_amounts', "
    SELECT id,sale_code,gross_amount,marketplace_fee,shipping_cost,contribution_amount,net_amount,hpp_total,profit_amount
    FROM product_sales
    WHERE ABS(gross_amount-marketplace_fee-shipping_cost-contribution_amount-net_amount)>0.01
       OR ABS(net_amount-hpp_total-profit_amount)>0.01
");

audit_rows($pdo, $checks, $failureCount, 'cashier_sale_amounts', "
    SELECT s.id,s.receipt_code,s.subtotal,s.discount_amount,s.total_amount,
           COALESCE(SUM(i.gross_amount),0) AS item_gross,COALESCE(SUM(i.net_amount),0) AS item_net
    FROM product_cashier_sales s
    LEFT JOIN product_cashier_sale_items i ON i.sale_id=s.id
    GROUP BY s.id
    HAVING ABS(subtotal-item_gross)>0.01 OR ABS(total_amount-(subtotal-discount_amount))>0.01 OR ABS(total_amount-item_net)>0.01
");

audit_rows($pdo, $checks, $failureCount, 'cashier_finance_links', "
    SELECT s.id,s.receipt_code,s.total_amount,s.payment_status,s.transaction_id,t.amount AS transaction_amount,t.type
    FROM product_cashier_sales s
    LEFT JOIN transactions t ON t.id=s.transaction_id
    WHERE s.payment_status='paid' AND (t.id IS NULL OR t.type<>'income' OR ABS(t.amount-s.total_amount)>0.01)
");

audit_rows($pdo, $checks, $failureCount, 'negative_product_stock', "
    SELECT id,product_code,product_name,stock
    FROM products
    WHERE track_stock=1 AND stock<0
");

audit_rows($pdo, $checks, $failureCount, 'duplicate_source_transactions', "
    SELECT source_module,source_table,source_id,COUNT(*) AS duplicate_count
    FROM transactions
    WHERE source_table IS NOT NULL AND source_id IS NOT NULL
    GROUP BY source_module,source_table,source_id
    HAVING COUNT(*)>1
");

echo json_encode([
    'status' => $failureCount === 0 ? 'ok' : 'failed',
    'failure_count' => $failureCount,
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failureCount === 0 ? 0 : 1);
