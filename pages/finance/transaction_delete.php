<?php
require_once __DIR__ . '/helpers.php';
require_post();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$t) {
    redirect('pages/finance/transactions.php?error=Transaksi tidak ditemukan');
}

if (($t['source_module'] ?? 'manual') !== 'manual') {
    redirect('pages/finance/transactions.php?error=Hanya transaksi manual yang bisa dihapus dari halaman ini');
}

try {
    $pdo->beginTransaction();

    $accountId = !empty($t['account_id']) ? (int)$t['account_id'] : null;

    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = :id");
    $stmt->execute([':id' => $id]);

    if ($accountId) {
        f_recalculate_account($pdo, $accountId);
    }

    $pdo->commit();

    redirect('pages/finance/transactions.php?message=Transaksi berhasil dihapus');
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect('pages/finance/transactions.php?error=' . urlencode($e->getMessage()));
}
