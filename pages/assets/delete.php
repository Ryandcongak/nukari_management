<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

function asset_safe_delete_file_local($path) {
    if (!$path) return;
    $file = realpath(__DIR__ . '/../../' . $path);
    $root = realpath(__DIR__ . '/../../uploads/assets');
    if ($file && $root && strpos($file, $root) === 0 && file_exists($file)) {
        @unlink($file);
    }
}

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM assets WHERE id=:id LIMIT 1");
$stmt->execute([':id'=>$id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if ($asset) {
    if (!empty($asset['photo'])) {
        asset_safe_delete_file_local($asset['photo']);
    }

    $photos = $pdo->prepare("SELECT image_path FROM project_asset_photos WHERE asset_id=:asset_id");
    $photos->execute([':asset_id'=>$id]);
    foreach ($photos->fetchAll(PDO::FETCH_ASSOC) as $ph) {
        asset_safe_delete_file_local($ph['image_path']);
    }

    $pdo->prepare("DELETE FROM project_asset_photos WHERE asset_id=:asset_id")->execute([':asset_id'=>$id]);
    $pdo->prepare("DELETE FROM assets WHERE id=:id")->execute([':id'=>$id]);

    $_SESSION['success'] = 'Aset berhasil dihapus.';
}

redirect('pages/assets/index.php');
