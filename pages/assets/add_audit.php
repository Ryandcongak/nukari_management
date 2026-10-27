<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
$proof = null;
try {
    $assetStmt = $pdo->prepare("SELECT location, responsible_employee_id FROM assets WHERE id=:id LIMIT 1");
    $assetStmt->execute([':id'=>$id]);
    $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
    $proof = asset_upload_file($_FILES['proof_file'] ?? [], 'assets', 'asset_audit', ['jpg','jpeg','png','webp','pdf']);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO asset_audits (asset_id, audit_date, audit_type, physical_status, system_location, actual_location, system_employee_id, actual_employee_id, condition_status, condition_score, notes, proof_file, created_by) VALUES (:asset_id,:audit_date,:audit_type,:physical_status,:system_location,:actual_location,:system_employee_id,:actual_employee_id,:condition_status,:condition_score,:notes,:proof_file,:created_by)");
    $stmt->execute([
        ':asset_id'=>$id,
        ':audit_date'=>$_POST['audit_date'] ?: date('Y-m-d'),
        ':audit_type'=>$_POST['audit_type'] ?? 'audit',
        ':physical_status'=>$_POST['physical_status'] ?? 'found',
        ':system_location'=>$asset['location'] ?? null,
        ':actual_location'=>clean_input($_POST['actual_location'] ?? ''),
        ':system_employee_id'=>$asset['responsible_employee_id'] ?? null,
        ':actual_employee_id'=>($_POST['actual_employee_id'] ?? '') !== '' ? (int)$_POST['actual_employee_id'] : null,
        ':condition_status'=>$_POST['condition_status'] ?? 'good',
        ':condition_score'=>(int)($_POST['condition_score'] ?? 100),
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':proof_file'=>$proof,
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $auditId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE assets SET location=COALESCE(NULLIF(:actual_location,''),location), responsible_employee_id=COALESCE(:actual_employee_id,responsible_employee_id), condition_status=:condition_status, condition_score=:condition_score, updated_at=NOW() WHERE id=:id")
        ->execute([':actual_location'=>clean_input($_POST['actual_location'] ?? ''), ':actual_employee_id'=>($_POST['actual_employee_id'] ?? '') !== '' ? (int)$_POST['actual_employee_id'] : null, ':condition_status'=>$_POST['condition_status'] ?? 'good', ':condition_score'=>(int)($_POST['condition_score'] ?? 100), ':id'=>$id]);
    asset_add_event($pdo, $id, $_POST['audit_type'] === 'stock_opname' ? 'stock_opname' : 'audit', 'Audit / Stock Opname Aset', clean_input($_POST['notes'] ?? ''), 'asset_audits', $auditId, ($_POST['audit_date'] ?: date('Y-m-d')) . ' 00:00:00');
    $pdo->commit();
    $_SESSION['success'] = 'Audit aset berhasil disimpan.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    upload_delete_file($proof);
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id='.$id.'#audit');
