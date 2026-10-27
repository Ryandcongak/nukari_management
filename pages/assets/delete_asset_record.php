<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
require_post();
$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$assetId = (int)($_GET['asset_id'] ?? 0);
$map = [
    'maintenance' => 'asset_maintenance',
    'document' => 'asset_documents',
    'movement' => 'asset_movements',
    'rental' => 'asset_rentals',
    'rental_payment' => 'asset_rental_payments',
    'audit' => 'asset_audits'
];
if ($id > 0 && $assetId > 0 && isset($map[$type])) {
    try {
        $pdo->beginTransaction();
        $filesToDelete = [];
        $fileColumn = [
            'maintenance' => 'proof_file',
            'document' => 'file_path',
            'rental_payment' => 'proof_file',
            'audit' => 'proof_file',
        ][$type] ?? null;
        if ($fileColumn) {
            $fileStmt = $pdo->prepare("SELECT {$fileColumn} FROM {$map[$type]} WHERE id=:id AND asset_id=:asset_id LIMIT 1");
            $fileStmt->execute([':id' => $id, ':asset_id' => $assetId]);
            $recordFile = $fileStmt->fetchColumn();
            if ($recordFile) $filesToDelete[] = $recordFile;
        }
        if ($type === 'maintenance') {
            $stmt = $pdo->prepare("SELECT transaction_id, project_transaction_id FROM asset_maintenance WHERE id=:id AND asset_id=:asset_id LIMIT 1");
            $stmt->execute([':id'=>$id, ':asset_id'=>$assetId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                if (!empty($record['project_transaction_id'])) $pdo->prepare("DELETE FROM project_transactions WHERE id=:id AND source_module='asset_maintenance'")->execute([':id'=>(int)$record['project_transaction_id']]);
                if (!empty($record['transaction_id'])) {
                    $accountStmt = $pdo->prepare("SELECT account_id FROM transactions WHERE id=:id LIMIT 1");
                    $accountStmt->execute([':id'=>(int)$record['transaction_id']]);
                    $accountId = (int)$accountStmt->fetchColumn();
                    $pdo->prepare("DELETE FROM transactions WHERE id=:id")->execute([':id'=>(int)$record['transaction_id']]);
                    if ($accountId && function_exists('finance_recalculate_account_balance')) finance_recalculate_account_balance($pdo, $accountId);
                }
            }
        } elseif ($type === 'rental_payment') {
            $stmt = $pdo->prepare("SELECT transaction_id, project_transaction_id FROM asset_rental_payments WHERE id=:id AND asset_id=:asset_id LIMIT 1");
            $stmt->execute([':id' => $id, ':asset_id' => $assetId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                if (!empty($record['project_transaction_id'])) {
                    $pdo->prepare("DELETE FROM project_transactions WHERE id=:id AND source_module='asset_rental'")
                        ->execute([':id' => (int)$record['project_transaction_id']]);
                }
                if (!empty($record['transaction_id'])) {
                    $accountStmt = $pdo->prepare("SELECT account_id FROM transactions WHERE id=:id LIMIT 1");
                    $accountStmt->execute([':id' => (int)$record['transaction_id']]);
                    $accountId = (int)$accountStmt->fetchColumn();
                    $pdo->prepare("DELETE FROM transactions WHERE id=:id")->execute([':id' => (int)$record['transaction_id']]);
                    if ($accountId && function_exists('finance_recalculate_account_balance')) {
                        finance_recalculate_account_balance($pdo, $accountId);
                    }
                }
            }
        }
        $pdo->prepare("DELETE FROM {$map[$type]} WHERE id=:id AND asset_id=:asset_id")->execute([':id'=>$id, ':asset_id'=>$assetId]);
        $pdo->commit();
        foreach (array_unique($filesToDelete) as $file) upload_delete_file($file);
        $_SESSION['success'] = 'Data berhasil dihapus.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
}
redirect('pages/assets/detail.php?id=' . $assetId);
