<?php
require_once __DIR__ . '/helpers.php';
require_post();

$paymentId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT dp.*,d.source_module,d.debt_code FROM debt_payments dp JOIN debts d ON d.id=dp.debt_id WHERE dp.id=:id LIMIT 1");
$stmt->execute([':id'=>$paymentId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    redirect('pages/debts/index.php?error=' . urlencode('Pembayaran tidak ditemukan.'));
}

$debtId = (int)$payment['debt_id'];
if (!in_array((string)($payment['source_module'] ?? 'manual'), ['', 'manual'], true)) {
    redirect('pages/debts/detail.php?id='.$debtId.'&error='.urlencode('Pembayaran hasil integrasi harus dikoreksi dari modul sumbernya.'));
}

try {
    $pdo->beginTransaction();
    $accountId = (int)($payment['account_id'] ?? 0);
    $transactionId = (int)($payment['transaction_id'] ?? 0);
    $filesToDelete = array_filter([$payment['proof_file'] ?? null]);
    if ($transactionId) {
        $fileStmt = $pdo->prepare("SELECT proof_file FROM transactions WHERE id=:id AND proof_file IS NOT NULL");
        $fileStmt->execute([':id' => $transactionId]);
        $transactionProof = $fileStmt->fetchColumn();
        if ($transactionProof) $filesToDelete[] = $transactionProof;
    }

    $pdo->prepare("DELETE FROM debt_payments WHERE id=:id AND debt_id=:debt_id")->execute([':id'=>$paymentId,':debt_id'=>$debtId]);
    if ($transactionId) $pdo->prepare("DELETE FROM transactions WHERE id=:id")->execute([':id'=>$transactionId]);

    finance_update_debt_status($pdo, $debtId);
    if ($accountId) finance_recalculate_account_balance($pdo, $accountId);
    log_activity($pdo, 'Debt Payment', 'DELETE', 'Pembayaran dibatalkan dan saldo dihitung ulang', $paymentId, 'debt_payments');
    $pdo->commit();
    foreach (array_unique($filesToDelete) as $file) upload_delete_file($file);
    redirect('pages/debts/detail.php?id='.$debtId.'&message='.urlencode('Pembayaran berhasil dihapus dan saldo sudah dihitung ulang.'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect('pages/debts/detail.php?id='.$debtId.'&error='.urlencode('Gagal menghapus pembayaran: '.$e->getMessage()));
}
