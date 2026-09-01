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

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT transaction_id FROM debt_payments WHERE debt_id=:id AND transaction_id IS NOT NULL");
    $stmt->execute([':id'=>$id]);
    $trxIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $del = $pdo->prepare("DELETE FROM debts WHERE id=:id");
    $del->execute([':id'=>$id]);

    if ($trxIds) {
        $in = implode(',', array_fill(0, count($trxIds), '?'));
        $pdo->prepare("DELETE FROM transactions WHERE id IN ($in)")->execute($trxIds);
    }

    $pdo->commit();
    redirect('pages/debts/index.php?message=Data berhasil dihapus');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect('pages/debts/index.php?error=Gagal menghapus data: ' . urlencode($e->getMessage()));
}
