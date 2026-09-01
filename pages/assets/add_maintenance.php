<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? $_GET['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/assets/detail.php?id=' . $id);

try {
    $proof = asset_upload_file($_FILES['proof_file'] ?? [], 'assets', 'maintenance', ['jpg','jpeg','png','webp','pdf']);
    $cost = asset_money_value($_POST['cost'] ?? 0);
    $stmt = $pdo->prepare("INSERT INTO asset_maintenance (asset_id, maintenance_date, maintenance_type, description, cost, vendor_name, next_maintenance_date, proof_file, notes, created_by) VALUES (:asset_id, :maintenance_date, :maintenance_type, :description, :cost, :vendor_name, :next_maintenance_date, :proof_file, :notes, :created_by)");
    $stmt->execute([
        ':asset_id' => $id,
        ':maintenance_date' => $_POST['maintenance_date'] ?: date('Y-m-d'),
        ':maintenance_type' => clean_input($_POST['maintenance_type'] ?? ''),
        ':description' => clean_input($_POST['description'] ?? ''),
        ':cost' => $cost,
        ':vendor_name' => clean_input($_POST['vendor_name'] ?? ''),
        ':next_maintenance_date' => ($_POST['next_maintenance_date'] ?? '') ?: null,
        ':proof_file' => $proof,
        ':notes' => clean_input($_POST['notes'] ?? ''),
        ':created_by' => $_SESSION['user_id'] ?? null,
    ]);
    $maintenanceId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE assets SET condition_status='maintenance', asset_status='maintenance', updated_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
    $eventType = (stripos((string)($_POST['maintenance_type'] ?? ''), 'oli') !== false) ? 'oil_change' : 'maintenance';
    asset_add_event($pdo, $id, $eventType, 'Maintenance / Service Aset', clean_input($_POST['description'] ?? ''), 'asset_maintenance', $maintenanceId, ($_POST['maintenance_date'] ?: date('Y-m-d')) . ' 00:00:00');
    if (!empty($_POST['next_maintenance_date'])) {
        asset_create_notification($pdo, 'asset_maintenance', 'Reminder Maintenance Aset', 'Jadwal maintenance berikutnya untuk aset ID #' . $id, $_POST['next_maintenance_date'], 'warning', 'bi-tools', 'pages/assets/detail.php?id=' . $id);
    }
    $_SESSION['success'] = 'Riwayat maintenance berhasil ditambahkan.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $id . '#maintenance');
