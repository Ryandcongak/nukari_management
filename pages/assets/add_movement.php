<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
try {
    $assetStmt = $pdo->prepare("SELECT location, responsible_employee_id FROM assets WHERE id=:id LIMIT 1");
    $assetStmt->execute([':id'=>$id]);
    $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) throw new Exception('Aset tidak ditemukan.');
    $toLocation = clean_input($_POST['to_location'] ?? '');
    $toEmployee = ((int)($_POST['to_employee_id'] ?? 0)) ?: null;
    $stmt = $pdo->prepare("INSERT INTO asset_movements (asset_id, movement_date, from_location, to_location, from_employee_id, to_employee_id, description, created_by) VALUES (:asset_id, :movement_date, :from_location, :to_location, :from_employee_id, :to_employee_id, :description, :created_by)");
    $stmt->execute([
        ':asset_id'=>$id,
        ':movement_date'=>$_POST['movement_date'] ?: date('Y-m-d'),
        ':from_location'=>$asset['location'] ?? null,
        ':to_location'=>$toLocation ?: null,
        ':from_employee_id'=>$asset['responsible_employee_id'] ?? null,
        ':to_employee_id'=>$toEmployee,
        ':description'=>clean_input($_POST['description'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $movementId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE assets SET location=:location, responsible_employee_id=:employee_id, updated_at=NOW() WHERE id=:id")->execute([':location'=>$toLocation ?: null, ':employee_id'=>$toEmployee, ':id'=>$id]);
    asset_add_event($pdo, $id, 'movement', 'Mutasi lokasi / PIC aset', clean_input($_POST['description'] ?? ''), 'asset_movements', $movementId, ($_POST['movement_date'] ?: date('Y-m-d')) . ' 00:00:00');
    $_SESSION['success'] = 'Mutasi aset berhasil dicatat.';
} catch (Exception $e) { $_SESSION['error'] = $e->getMessage(); }
redirect('pages/assets/detail.php?id=' . $id . '#movement');
