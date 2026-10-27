<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM assets WHERE id=:id LIMIT 1");
$stmt->execute([':id'=>$id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if ($asset) {
    try {
        $filesToDelete = array_filter([$asset['photo'] ?? null]);
        foreach ([
            ['project_asset_photos', 'image_path'],
            ['asset_documents', 'file_path'],
            ['asset_maintenance', 'proof_file'],
            ['asset_rental_payments', 'proof_file'],
            ['asset_sales', 'proof_file'],
            ['asset_audits', 'proof_file'],
        ] as [$table, $column]) {
            $fileStmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE asset_id=:asset_id AND {$column} IS NOT NULL");
            $fileStmt->execute([':asset_id' => $id]);
            $filesToDelete = array_merge($filesToDelete, $fileStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM project_asset_photos WHERE asset_id=:asset_id")->execute([':asset_id'=>$id]);
        $pdo->prepare("DELETE FROM assets WHERE id=:id")->execute([':id'=>$id]);
        $pdo->commit();

        foreach (array_unique($filesToDelete) as $file) upload_delete_file($file);
        $_SESSION['success'] = 'Aset berhasil dihapus.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = 'Aset gagal dihapus: ' . $e->getMessage();
    }
}

redirect('pages/assets/index.php');
