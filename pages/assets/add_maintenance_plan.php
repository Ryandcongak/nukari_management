<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';

$assetId = (int)($_POST['asset_id'] ?? 0);
if ($assetId <= 0 || $_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/assets/index.php');

try {
    $planName = clean_input($_POST['plan_name'] ?? '');
    if ($planName === '') throw new Exception('Nama maintenance wajib diisi.');
    $scheduleType = $_POST['schedule_type'] ?? 'date';
    if (!in_array($scheduleType, ['date','kilometer','hour','combined','incidental'], true)) $scheduleType = 'date';
    $stmt = $pdo->prepare("INSERT INTO asset_maintenance_plans (asset_id, plan_name, maintenance_category, schedule_type, interval_value, interval_unit, last_service_date, next_service_date, last_meter_value, next_meter_value, estimated_cost, reminder_days, notes, created_by) VALUES (:asset_id,:plan_name,:maintenance_category,:schedule_type,:interval_value,:interval_unit,:last_service_date,:next_service_date,:last_meter_value,:next_meter_value,:estimated_cost,:reminder_days,:notes,:created_by)");
    $stmt->execute([
        ':asset_id'=>$assetId,
        ':plan_name'=>$planName,
        ':maintenance_category'=>clean_input($_POST['maintenance_category'] ?? 'service'),
        ':schedule_type'=>$scheduleType,
        ':interval_value'=>(int)($_POST['interval_value'] ?? 0) ?: null,
        ':interval_unit'=>($_POST['interval_unit'] ?? '') ?: null,
        ':last_service_date'=>($_POST['last_service_date'] ?? '') ?: null,
        ':next_service_date'=>($_POST['next_service_date'] ?? '') ?: null,
        ':last_meter_value'=>($_POST['last_meter_value'] ?? '') !== '' ? asset_money_value($_POST['last_meter_value']) : null,
        ':next_meter_value'=>($_POST['next_meter_value'] ?? '') !== '' ? asset_money_value($_POST['next_meter_value']) : null,
        ':estimated_cost'=>asset_money_value($_POST['estimated_cost'] ?? 0),
        ':reminder_days'=>max(0, (int)($_POST['reminder_days'] ?? 7)),
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $pdo->prepare("UPDATE assets SET maintenance_required=1, updated_at=NOW() WHERE id=:id")->execute([':id'=>$assetId]);
    if (!empty($_POST['next_service_date'])) {
        asset_create_maintenance_reminder($pdo, $assetId, clean_input($_POST['maintenance_category'] ?? 'service'), 'Jadwal ' . $planName, 'Maintenance terjadwal untuk aset ID #' . $assetId, $_POST['next_service_date'], max(0, (int)($_POST['reminder_days'] ?? 7)));
    }
    $_SESSION['success'] = 'Rencana maintenance berhasil ditambahkan.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $assetId . '#maintenance');
