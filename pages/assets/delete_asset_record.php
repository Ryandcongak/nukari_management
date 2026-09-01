<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();
$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$assetId = (int)($_GET['asset_id'] ?? 0);
$map = [
    'maintenance' => 'asset_maintenance',
    'document' => 'asset_documents',
    'movement' => 'asset_movements',
    'rental' => 'asset_rentals',
    'rental_payment' => 'asset_rental_payments'
];
if ($id > 0 && $assetId > 0 && isset($map[$type])) {
    $pdo->prepare("DELETE FROM {$map[$type]} WHERE id=:id")->execute([':id'=>$id]);
    $_SESSION['success'] = 'Data berhasil dihapus.';
}
redirect('pages/assets/detail.php?id=' . $assetId);
