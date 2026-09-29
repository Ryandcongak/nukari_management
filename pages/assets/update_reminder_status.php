<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();
$id = (int)($_POST['id'] ?? 0);
$assetId = (int)($_POST['asset_id'] ?? 0);
$status = $_POST['status'] ?? 'done';
if ($id > 0 && in_array($status, ['done','cancelled','active'], true)) {
    $stmt = $pdo->prepare("UPDATE asset_reminders SET status=:status, completed_at=IF(:completion_status='done',NOW(),completed_at), updated_at=NOW() WHERE id=:id");
    $stmt->execute([':status'=>$status, ':completion_status'=>$status, ':id'=>$id]);
}
redirect($assetId ? 'pages/assets/detail.php?id='.$assetId.'#reminders' : 'pages/assets/index.php');
