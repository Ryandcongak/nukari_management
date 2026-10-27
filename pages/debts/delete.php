<?php
require_once __DIR__ . '/helpers.php';
require_post();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM debts WHERE id=:id");
$stmt->execute([':id'=>$id]);
$debt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$debt) {
    redirect('pages/debts/index.php?error=Data tidak ditemukan');
}
if (!in_array((string)($debt['source_module'] ?? 'manual'), ['', 'manual'], true)) {
    redirect('pages/debts/detail.php?id=' . $id . '&error=' . urlencode('Data hasil integrasi harus dihapus dari modul sumbernya.'));
}

try {
    $pdo->beginTransaction();
    $filesToDelete = array_filter([$debt['proof_file'] ?? null]);

    $stmt = $pdo->prepare("SELECT transaction_id FROM debt_payments WHERE debt_id=:id");
    $stmt->execute([':id'=>$id]);
    $trxIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($trxIds || (float)($debt['paid_amount'] ?? 0) > 0) {
        throw new Exception('Data yang sudah memiliki pembayaran tidak dapat langsung dihapus. Hapus/koreksi pembayaran terlebih dahulu.');
    }

    if (!empty($debt['funding_transaction_id'])) $trxIds[] = (int)$debt['funding_transaction_id'];
    $trxIds = array_values(array_unique(array_filter(array_map('intval', $trxIds))));
    $accountIds = [];
    if ($trxIds) {
        $in = implode(',', array_fill(0, count($trxIds), '?'));
        $fileStmt = $pdo->prepare("SELECT proof_file FROM transactions WHERE id IN ($in) AND proof_file IS NOT NULL");
        $fileStmt->execute($trxIds);
        $filesToDelete = array_merge($filesToDelete, $fileStmt->fetchAll(PDO::FETCH_COLUMN));
        $accountStmt = $pdo->prepare("SELECT DISTINCT account_id FROM transactions WHERE id IN ($in) AND account_id IS NOT NULL");
        $accountStmt->execute($trxIds);
        $accountIds = $accountStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $del = $pdo->prepare("DELETE FROM debts WHERE id=:id");
    $del->execute([':id'=>$id]);

    if ($trxIds) {
        $in = implode(',', array_fill(0, count($trxIds), '?'));
        $pdo->prepare("DELETE FROM transactions WHERE id IN ($in)")->execute($trxIds);
    }

    foreach ($accountIds as $accountId) finance_recalculate_account_balance($pdo, (int)$accountId);

    $pdo->commit();
    foreach (array_unique($filesToDelete) as $file) upload_delete_file($file);
    redirect('pages/debts/index.php?message=Data berhasil dihapus');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect('pages/debts/index.php?error=Gagal menghapus data: ' . urlencode($e->getMessage()));
}
