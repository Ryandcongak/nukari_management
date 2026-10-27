<?php
require_once __DIR__ . '/helpers.php';
require_post();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM reimbursements WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    redirect('pages/reimbursements/index.php?error=Data tidak ditemukan');
}

try {
    $pdo->beginTransaction();
    $filesToDelete = array_filter([$r['proof_file'] ?? null]);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reimbursement_payments WHERE reimbursement_id = :id");
    $stmt->execute([':id' => $id]);
    $paymentCount = (int)$stmt->fetchColumn();

    if ($paymentCount > 0 || in_array($r['status'], ['partial','paid'], true)) {
        throw new Exception('Data tidak bisa dihapus karena sudah memiliki pembayaran.');
    }

    if (!empty($r['debt_id'])) {
        $stmt = $pdo->prepare("SELECT paid_amount, proof_file FROM debts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$r['debt_id']]);
        $linkedDebt = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $paid = (float)($linkedDebt['paid_amount'] ?? 0);
        if (!empty($linkedDebt['proof_file'])) $filesToDelete[] = $linkedDebt['proof_file'];

        if ($paid > 0) {
            throw new Exception('Data tidak bisa dihapus karena hutang sudah memiliki pembayaran.');
        }

        $stmt = $pdo->prepare("DELETE FROM debts WHERE id = :id");
        $stmt->execute([':id' => (int)$r['debt_id']]);
    }

    $stmt = $pdo->prepare("DELETE FROM reimbursements WHERE id = :id");
    $stmt->execute([':id' => $id]);

    $pdo->commit();
    foreach (array_unique($filesToDelete) as $file) upload_delete_file($file);

    redirect('pages/reimbursements/index.php?message=Data dana talangan berhasil dihapus');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect('pages/reimbursements/detail.php?id=' . $id . '&error=' . urlencode($e->getMessage()));
}
