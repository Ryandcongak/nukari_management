<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';

$assetId = (int)($_POST['asset_id'] ?? 0);
$rentalId = (int)($_POST['rental_id'] ?? 0);
if ($assetId <= 0 || $rentalId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/assets/index.php');

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE asset_rentals SET status='completed', rental_end=COALESCE(rental_end,CURDATE()), updated_at=NOW() WHERE id=:id AND asset_id=:asset_id AND status IN ('active','overdue')");
    $stmt->execute([':id'=>$rentalId, ':asset_id'=>$assetId]);
    if (!$stmt->rowCount()) throw new Exception('Penyewaan aktif tidak ditemukan.');
    $pdo->prepare("UPDATE assets SET asset_status=CASE WHEN condition_status='good' THEN 'active' ELSE condition_status END, ownership_status='owned', updated_at=NOW() WHERE id=:id AND asset_status='rented'")->execute([':id'=>$assetId]);
    asset_add_event($pdo, $assetId, 'rental', 'Penyewaan selesai', 'Aset dikembalikan ke status tersedia.', 'asset_rentals', $rentalId);
    $pdo->commit();
    $_SESSION['success'] = 'Penyewaan selesai dan status aset telah diperbarui.';
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $assetId . '#rental');
