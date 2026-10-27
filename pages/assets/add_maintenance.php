<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? $_GET['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/assets/detail.php?id=' . $id);

$proof = null;
try {
    $assetStmt = $pdo->prepare("SELECT * FROM assets WHERE id=:id LIMIT 1");
    $assetStmt->execute([':id'=>$id]);
    $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) throw new Exception('Aset tidak ditemukan.');
    $proof = asset_upload_file($_FILES['proof_file'] ?? [], 'assets', 'maintenance', ['jpg','jpeg','png','webp','pdf']);
    $laborCost = asset_money_value($_POST['labor_cost'] ?? 0);
    $partsCost = asset_money_value($_POST['parts_cost'] ?? 0);
    $cost = asset_money_value($_POST['cost'] ?? 0);
    if ($cost <= 0) $cost = $laborCost + $partsCost;
    $projectId = (int)($_POST['project_id'] ?? 0) ?: null;
    $accountId = (int)($_POST['account_id'] ?? 0) ?: null;
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $maintenanceDate = $_POST['maintenance_date'] ?: date('Y-m-d');
    $postingMode = finance_validate_posting_mode($_POST['posting_mode'] ?? 'posted', $_POST['historical_reason'] ?? null);
    $historicalReason = $postingMode === 'historical' ? trim((string)($_POST['historical_reason'] ?? '')) : null;
    if ($cost > 0 && !$accountId) throw new Exception('Pilih akun kas/bank untuk pembayaran maintenance.');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO asset_maintenance (asset_id, plan_id, project_id, account_id, payment_method, maintenance_date, maintenance_type, description, cost, labor_cost, parts_cost, meter_value, condition_before, condition_after, vendor_name, next_maintenance_date, proof_file, notes, created_by) VALUES (:asset_id, :plan_id, :project_id, :account_id, :payment_method, :maintenance_date, :maintenance_type, :description, :cost, :labor_cost, :parts_cost, :meter_value, :condition_before, :condition_after, :vendor_name, :next_maintenance_date, :proof_file, :notes, :created_by)");
    $stmt->execute([
        ':asset_id' => $id,
        ':plan_id' => (int)($_POST['plan_id'] ?? 0) ?: null,
        ':project_id' => $projectId,
        ':account_id' => $accountId,
        ':payment_method' => $paymentMethod,
        ':maintenance_date' => $maintenanceDate,
        ':maintenance_type' => clean_input($_POST['maintenance_type'] ?? ''),
        ':description' => clean_input($_POST['description'] ?? ''),
        ':cost' => $cost,
        ':labor_cost' => $laborCost,
        ':parts_cost' => $partsCost,
        ':meter_value' => ($_POST['meter_value'] ?? '') !== '' ? asset_money_value($_POST['meter_value']) : null,
        ':condition_before' => clean_input($_POST['condition_before'] ?? ''),
        ':condition_after' => clean_input($_POST['condition_after'] ?? ''),
        ':vendor_name' => clean_input($_POST['vendor_name'] ?? ''),
        ':next_maintenance_date' => ($_POST['next_maintenance_date'] ?? '') ?: null,
        ':proof_file' => $proof,
        ':notes' => clean_input($_POST['notes'] ?? ''),
        ':created_by' => $_SESSION['user_id'] ?? null,
    ]);
    $maintenanceId = (int)$pdo->lastInsertId();
    $description = 'Maintenance aset: ' . ($asset['asset_name'] ?? '-') . ' - ' . clean_input($_POST['maintenance_type'] ?? 'Service');
    $transactionId = null;
    $projectTransactionId = null;
    if ($cost > 0) {
        $transactionId = asset_create_transaction($pdo, 'expense', $cost, $description, $paymentMethod, $proof, $projectId, $id, $accountId, $maintenanceDate, $postingMode, $historicalReason);
        if ($projectId) {
            $projectTransactionId = asset_create_project_mirror($pdo, $projectId, 'expense', $cost, 'Maintenance Aset', $description, 'asset_maintenance', $maintenanceId, $paymentMethod, $accountId, $maintenanceDate, $postingMode, $historicalReason);
        }
        $pdo->prepare("UPDATE asset_maintenance SET transaction_id=:transaction_id, project_transaction_id=:project_transaction_id WHERE id=:id")->execute([':transaction_id'=>$transactionId, ':project_transaction_id'=>$projectTransactionId, ':id'=>$maintenanceId]);
        log_activity($pdo, 'Asset Maintenance', 'CREATE', finance_posting_mode_label($postingMode) . ($historicalReason ? ' — ' . $historicalReason : ''), $transactionId, 'transactions');
    }
    if (!empty($_POST['plan_id'])) {
        $pdo->prepare("UPDATE asset_maintenance_plans SET last_service_date=:last_date, next_service_date=:next_date, last_meter_value=:meter_value, updated_at=NOW() WHERE id=:id AND asset_id=:asset_id")->execute([':last_date'=>$maintenanceDate, ':next_date'=>($_POST['next_maintenance_date'] ?? '') ?: null, ':meter_value'=>($_POST['meter_value'] ?? '') !== '' ? asset_money_value($_POST['meter_value']) : null, ':id'=>(int)$_POST['plan_id'], ':asset_id'=>$id]);
    }
    $conditionAfter = $_POST['condition_after'] ?? 'good';
    if (!in_array($conditionAfter, ['good','maintenance','damaged'], true)) $conditionAfter = 'good';
    $assetStatusAfter = $conditionAfter === 'good' ? 'active' : $conditionAfter;
    $pdo->prepare("UPDATE assets SET condition_status=:condition_status, asset_status=:asset_status, updated_at=NOW() WHERE id=:id AND asset_status NOT IN ('sold','lost','disposed','rented')")->execute([':condition_status'=>$conditionAfter, ':asset_status'=>$assetStatusAfter, ':id'=>$id]);
    $eventType = (stripos((string)($_POST['maintenance_type'] ?? ''), 'oli') !== false) ? 'oil_change' : 'maintenance';
    asset_add_event($pdo, $id, $eventType, 'Maintenance / Service Aset', clean_input($_POST['description'] ?? ''), 'asset_maintenance', $maintenanceId, ($_POST['maintenance_date'] ?: date('Y-m-d')) . ' 00:00:00');
    if (!empty($_POST['next_maintenance_date'])) {
        asset_create_maintenance_reminder($pdo, $id, $eventType === 'oil_change' ? 'oil_change' : 'service', 'Reminder Maintenance Aset', 'Jadwal maintenance berikutnya untuk aset ID #' . $id, $_POST['next_maintenance_date']);
    }
    $pdo->commit();
    $_SESSION['success'] = 'Riwayat maintenance berhasil ditambahkan.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    upload_delete_file($proof);
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $id . '#maintenance');
