<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/finance_helper.php';

$tables = ['transactions', 'debts', 'debt_payments', 'products', 'product_sales'];
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

echo "Write transaction tests passed (finance upsert, partial receivable, product stock; rollback clean).\n";
