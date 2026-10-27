<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/finance_helper.php';
require_once __DIR__ . '/../includes/employee_funding_helper.php';

$tables = ['transactions', 'debts', 'debt_payments', 'operational_fund_movements', 'project_payment_terms', 'products', 'product_sales', 'employee_cash_advances'];
$beforeCounts = [];
foreach ($tables as $table) {
    $beforeCounts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

$account = $pdo->query("SELECT id,current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    fwrite(STDERR, "FAIL tidak ada akun finance aktif untuk pengujian.\n");
    exit(1);
}

$accountId = (int)$account['id'];
$balanceBefore = (float)$account['current_balance'];
$sourceId = random_int(1500000000, 2000000000);
$suffix = date('YmdHis') . random_int(100, 999);

try {
    $pdo->beginTransaction();

    $financeSettings = finance_get_settings($pdo);
    if (!empty($financeSettings['cutover_date'])) {
        $beforeCutover = date('Y-m-d', strtotime($financeSettings['cutover_date'].' -1 day'));
        $cutoverRejected = false;
        try {
            finance_validate_posting_date($pdo, $beforeCutover, 'posted');
        } catch (Exception $e) {
            $cutoverRejected = true;
        }
        finance_validate_posting_date($pdo, $beforeCutover, 'historical');
        if (!$cutoverRejected) throw new RuntimeException('Tanggal sebelum cutover masih diizinkan sebagai transaksi berjalan.');
    }

    // Finance: upsert sumber yang sama tidak boleh membuat transaksi ganda,
    // dan perubahan tipe/nominal harus langsung tercermin pada saldo akun.
    $transactionId = finance_upsert_transaction(
        $pdo, 'other', 'integration_test', $sourceId, 'income', null, 12345,
        'Integration test income', 'Integration Test', date('Y-m-d'), 'cash',
        'TEST-' . $suffix, null, $accountId
    );
    $balance = (float)$pdo->query("SELECT current_balance FROM finance_accounts WHERE id={$accountId}")->fetchColumn();
    if (!$transactionId || abs($balance - ($balanceBefore + 12345)) > 0.01) {
        throw new RuntimeException('Insert transaksi income tidak memperbarui saldo dengan benar.');
    }

    $updatedTransactionId = finance_upsert_transaction(
        $pdo, 'other', 'integration_test', $sourceId, 'expense', null, 2345,
        'Integration test expense', 'Integration Test', date('Y-m-d'), 'cash',
        'TEST-' . $suffix, null, $accountId
    );
    $balance = (float)$pdo->query("SELECT current_balance FROM finance_accounts WHERE id={$accountId}")->fetchColumn();
    $sourceCountStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE source_module='other' AND source_table='integration_test' AND source_id=:source_id");
    $sourceCountStmt->execute([':source_id' => $sourceId]);
    if ($updatedTransactionId !== $transactionId || (int)$sourceCountStmt->fetchColumn() !== 1 || abs($balance - ($balanceBefore - 2345)) > 0.01) {
        throw new RuntimeException('Upsert transaksi membuat duplikasi atau saldo yang salah.');
    }

    // Catatan historis harus tetap tersimpan untuk audit, tetapi tidak boleh
    // mengubah saldo akun yang sudah menjadi saldo riil perusahaan.
    $historicalId = finance_upsert_transaction(
        $pdo, 'other', 'integration_test_historical', $sourceId + 1, 'expense', null, 54321,
        'Integration test historical', 'Integration Test', date('Y-m-d'), 'cash',
        'TEST-HIST-' . $suffix, null, $accountId, null, 'historical', 'Data sebelum saldo awal pengujian'
    );
    $historical = $pdo->query("SELECT posting_mode,historical_reason FROM transactions WHERE id=" . (int)$historicalId)->fetch(PDO::FETCH_ASSOC);
    $balance = (float)$pdo->query("SELECT current_balance FROM finance_accounts WHERE id={$accountId}")->fetchColumn();
    if (!$historical || $historical['posting_mode'] !== 'historical' || $historical['historical_reason'] === '' || abs($balance - ($balanceBefore - 2345)) > 0.01) {
        throw new RuntimeException('Catatan historis mengubah saldo atau kehilangan alasan audit.');
    }

    $fund = $pdo->query("SELECT id,current_balance,total_used FROM operational_funds WHERE status='open' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($fund) {
        $movementId = opfund_add_movement(
            $pdo, (int)$fund['id'], 'expense', 6543, date('Y-m-d'), null,
            'Integration test historical operational fund', 'other', 'integration_test_historical',
            $sourceId + 2, null, 'cash', null, 'historical', 'Mutasi lama sebelum saldo awal pengujian'
        );
        $fundAfter = $pdo->query("SELECT current_balance,total_used FROM operational_funds WHERE id=" . (int)$fund['id'])->fetch(PDO::FETCH_ASSOC);
        $movementMode = $pdo->query("SELECT posting_mode FROM operational_fund_movements WHERE id=" . (int)$movementId)->fetchColumn();
        if ($movementMode !== 'historical'
            || abs((float)$fundAfter['current_balance'] - (float)$fund['current_balance']) > 0.01
            || abs((float)$fundAfter['total_used'] - (float)$fund['total_used']) > 0.01) {
            throw new RuntimeException('Mutasi Dana Operasional historis mengubah saldo berjalan.');
        }

        // Mutasi pemberian dan pengembalian yang saling membalik harus
        // memulihkan saldo dana PIC maupun akun perusahaan secara presisi.
        $accountBeforeReversal = (float)$pdo->query("SELECT current_balance FROM finance_accounts WHERE id={$accountId}")->fetchColumn();
        $fundBeforeReversal = (float)$pdo->query("SELECT current_balance FROM operational_funds WHERE id=".(int)$fund['id'])->fetchColumn();
        opfund_add_movement($pdo, (int)$fund['id'], 'receive', 4321, date('Y-m-d'), $accountId, 'Integration test receive', 'finance', 'integration_reversal', $sourceId + 3, null, 'cash');
        opfund_add_movement($pdo, (int)$fund['id'], 'return', 4321, date('Y-m-d'), $accountId, 'Integration test reversal', 'finance', 'integration_reversal', $sourceId + 4, null, 'cash');
        $accountAfterReversal = (float)$pdo->query("SELECT current_balance FROM finance_accounts WHERE id={$accountId}")->fetchColumn();
        $fundAfterReversal = (float)$pdo->query("SELECT current_balance FROM operational_funds WHERE id=".(int)$fund['id'])->fetchColumn();
        if (abs($accountAfterReversal - $accountBeforeReversal) > 0.01 || abs($fundAfterReversal - $fundBeforeReversal) > 0.01) {
            throw new RuntimeException('Mutasi pembalik Dana Operasional tidak memulihkan saldo akun/dana.');
        }

        // Hapus transaksi manual yang memakai Dana PIC harus ikut menghapus
        // mutasi sumbernya dan mengembalikan saldo dana tersebut.
        $manualStmt = $pdo->prepare("INSERT INTO transactions(transaction_code,transaction_date,type,amount,description,payment_method,source_module,created_at,updated_at) VALUES(:code,CURDATE(),'expense',3210,'Integration linked transaction','cash','manual',NOW(),NOW())");
        $manualStmt->execute([':code'=>'TEST-LINK-'.$suffix]);
        $manualTransactionId = (int)$pdo->lastInsertId();
        opfund_add_movement($pdo, (int)$fund['id'], 'expense', 3210, date('Y-m-d'), null, 'Integration linked expense', 'finance', 'transactions', $manualTransactionId, null, 'cash');
        $linkedDebtStmt = $pdo->prepare("INSERT INTO debts(debt_code,type,related_type,related_name,category,issue_date,amount,principal_amount,paid_amount,status,source_module,source_table,source_id) VALUES(:code,'debt','personal','Integration PIC','Talangan Operasional',CURDATE(),3210,3210,0,'unpaid','other','transactions',:source_id)");
        $linkedDebtStmt->execute([':code'=>'TEST-LINK-DEBT-'.$suffix,':source_id'=>$manualTransactionId]);
        finance_delete_manual_transaction_links($pdo, $manualTransactionId);
        $movementCount = (int)$pdo->query("SELECT COUNT(*) FROM operational_fund_movements WHERE source_module='finance' AND source_table='transactions' AND source_id={$manualTransactionId}")->fetchColumn();
        $linkedDebtCount = (int)$pdo->query("SELECT COUNT(*) FROM debts WHERE source_module='other' AND source_table='transactions' AND source_id={$manualTransactionId}")->fetchColumn();
        $fundAfterCleanup = (float)$pdo->query("SELECT current_balance FROM operational_funds WHERE id=".(int)$fund['id'])->fetchColumn();
        if ($movementCount !== 0 || $linkedDebtCount !== 0 || abs($fundAfterCleanup - $fundBeforeReversal) > 0.01) {
            throw new RuntimeException('Penghapusan relasi transaksi manual tidak membersihkan mutasi/hutang atau memulihkan saldo Dana PIC.');
        }
    }

    // Unlock payroll harus memulihkan kasbon yang sebelumnya dialokasikan.
    $payroll = $pdo->query("SELECT id,employee_id,payroll_month FROM payrolls ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($payroll) {
        $cashStmt = $pdo->prepare("INSERT INTO employee_cash_advances(employee_id,advance_date,amount,remaining_amount,deduction_month,status,allocated_payroll_id,deducted_at,funding_source,disbursement_status,posting_mode,historical_reason,payment_method) VALUES(:employee_id,CURDATE(),9876,0,:deduction_month,'paid',:payroll_id,NOW(),'company','disbursed','historical','Integration test','cash')");
        $cashStmt->execute([':employee_id'=>(int)$payroll['employee_id'],':deduction_month'=>$payroll['payroll_month'],':payroll_id'=>(int)$payroll['id']]);
        $cashAdvanceId = (int)$pdo->lastInsertId();
        employee_cash_advance_restore_for_payroll($pdo, (int)$payroll['id']);
        $cashAdvance = $pdo->query("SELECT amount,remaining_amount,status,allocated_payroll_id,deducted_at FROM employee_cash_advances WHERE id={$cashAdvanceId}")->fetch(PDO::FETCH_ASSOC);
        if (!$cashAdvance || abs((float)$cashAdvance['remaining_amount'] - (float)$cashAdvance['amount']) > 0.01 || $cashAdvance['status'] !== 'active' || $cashAdvance['allocated_payroll_id'] !== null || $cashAdvance['deducted_at'] !== null) {
            throw new RuntimeException('Unlock payroll tidak memulihkan saldo/status kasbon.');
        }
    }

    $projectId = (int)$pdo->query("SELECT id FROM projects WHERE deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    if ($projectId > 0) {
        $termStmt = $pdo->prepare("INSERT INTO project_payment_terms(project_id,term_name,amount,received_amount,due_date,paid_date,status,posting_mode,historical_reason,notes) VALUES(:project_id,:term_name,7777,7777,CURDATE(),CURDATE(),'paid','historical','Termin lama sebelum saldo awal pengujian','Integration test')");
        $termStmt->execute([':project_id'=>$projectId,':term_name'=>'TEST-HIST-TERM-'.$suffix]);
        $termId = (int)$pdo->lastInsertId();
        finance_mark_project_term_paid($pdo, $termId, date('Y-m-d'), null, 'other', null, null);
        $termTransaction = $pdo->query("SELECT posting_mode FROM transactions WHERE source_module='project' AND source_table='project_payment_terms' AND source_id={$termId}")->fetchColumn();
        $termDebtCount = (int)$pdo->query("SELECT COUNT(*) FROM debts WHERE source_module='project_term' AND source_id={$termId}")->fetchColumn();
        $balance = (float)$pdo->query("SELECT current_balance FROM finance_accounts WHERE id={$accountId}")->fetchColumn();
        if ($termTransaction !== 'historical' || $termDebtCount !== 0 || abs($balance - ($balanceBefore - 2345)) > 0.01) {
            throw new RuntimeException('Termin historis memengaruhi saldo atau membuat piutang aktif.');
        }
    }

    // Utang/piutang: pembayaran parsial harus memiliki transaksi dan status yang selaras.
    $debtStmt = $pdo->prepare("INSERT INTO debts (debt_code,type,related_type,related_name,issue_date,amount,principal_amount,paid_amount,status,source_module,source_table,source_id) VALUES (:code,'receivable','other','Integration Test',CURDATE(),100000,100000,0,'unpaid','other','integration_test',:source_id)");
    $debtStmt->execute([':code' => 'TEST-DEBT-' . $suffix, ':source_id' => $sourceId]);
    $debtId = (int)$pdo->lastInsertId();
    $paymentTransactionId = finance_upsert_transaction(
        $pdo, 'debt_payment', 'debt_payments', $debtId, 'income', null, 40000,
        'Integration test debt payment', 'Pembayaran Piutang', date('Y-m-d'), 'cash',
        'TEST-PAY-' . $suffix, null, $accountId
    );
    $paymentStmt = $pdo->prepare("INSERT INTO debt_payments (debt_id,payment_date,amount,payment_method,account_id,transaction_id,notes) VALUES (:debt_id,CURDATE(),40000,'cash',:account_id,:transaction_id,'Integration test')");
    $paymentStmt->execute([':debt_id' => $debtId, ':account_id' => $accountId, ':transaction_id' => $paymentTransactionId]);
    $pdo->prepare("UPDATE debts SET paid_amount=40000,status='partial',updated_at=NOW() WHERE id=:id")->execute([':id' => $debtId]);
    $debtCheck = $pdo->query("SELECT d.status,d.paid_amount,COALESCE(SUM(p.amount),0) payment_total FROM debts d LEFT JOIN debt_payments p ON p.debt_id=d.id WHERE d.id={$debtId} GROUP BY d.id")->fetch(PDO::FETCH_ASSOC);
    if (!$debtCheck || $debtCheck['status'] !== 'partial' || abs((float)$debtCheck['paid_amount'] - (float)$debtCheck['payment_total']) > 0.01) {
        throw new RuntimeException('Pembayaran parsial piutang tidak konsisten.');
    }

    // Penjualan/stok: stok berkurang atomik dan nominal penjualan tetap seimbang.
    $productStmt = $pdo->prepare("INSERT INTO products (product_code,barcode_code,product_name,base_price,hpp_price,selling_price,stock,minimum_stock,unit,status) VALUES (:code,:barcode,'Integration Test Product',10000,10000,15000,10,0,'pcs','active')");
    $productStmt->execute([':code' => 'TEST-' . $suffix, ':barcode' => 'TESTBAR-' . $suffix]);
    $productId = (int)$pdo->lastInsertId();
    $stockUpdate = $pdo->prepare("UPDATE products SET stock=stock-3 WHERE id=:id AND stock>=3");
    $stockUpdate->execute([':id' => $productId]);
    if ($stockUpdate->rowCount() !== 1) throw new RuntimeException('Pengurangan stok atomik gagal.');

    $saleStmt = $pdo->prepare("INSERT INTO product_sales (sale_code,product_id,sale_date,qty,unit_price,gross_amount,net_amount,hpp_total,profit_amount,payment_method,notes) VALUES (:code,:product_id,CURDATE(),3,15000,45000,45000,30000,15000,'cash','Integration test')");
    $saleStmt->execute([':code' => 'TEST-SALE-' . $suffix, ':product_id' => $productId]);
    $saleId = (int)$pdo->lastInsertId();
    $stock = (int)$pdo->query("SELECT stock FROM products WHERE id={$productId}")->fetchColumn();
    $sale = $pdo->query("SELECT net_amount,hpp_total,profit_amount FROM product_sales WHERE id={$saleId}")->fetch(PDO::FETCH_ASSOC);
    if ($stock !== 7 || !$sale || abs((float)$sale['net_amount'] - (float)$sale['hpp_total'] - (float)$sale['profit_amount']) > 0.01) {
        throw new RuntimeException('Stok atau nominal penjualan tidak konsisten.');
    }

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($beforeCounts as $table => $beforeCount) {
    $afterCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($afterCount !== $beforeCount) {
        fwrite(STDERR, "FAIL rollback menyisakan data pada {$table}.\n");
        exit(1);
    }
}
$balanceAfter = (float)$pdo->query("SELECT current_balance FROM finance_accounts WHERE id={$accountId}")->fetchColumn();
if (abs($balanceAfter - $balanceBefore) > 0.01) {
    fwrite(STDERR, "FAIL rollback tidak memulihkan saldo akun.\n");
    exit(1);
}

echo "Write transaction tests passed (finance links, opfund reversal, payroll cash-advance restore, historical no-balance, debt payment, stock; rollback clean).\n";
