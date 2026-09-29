<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
require_post();
$borrowId = (int)($_POST['borrowing_id'] ?? 0);
$assetId = (int)($_POST['asset_id'] ?? 0);
if ($borrowId <= 0) redirect('pages/assets/index.php');
try {
    $returnCondition = $_POST['return_condition'] ?? 'good';
    $notes = clean_input($_POST['notes'] ?? '');
    $stmt = $pdo->prepare("UPDATE asset_borrowings SET check_in_date=NOW(), status='returned', return_condition=:return_condition, notes=CONCAT(COALESCE(notes,''), '\nReturn: ', :notes), returned_by=:returned_by, updated_at=NOW() WHERE id=:id");
    $stmt->execute([':return_condition'=>$returnCondition, ':notes'=>$notes, ':returned_by'=>$_SESSION['user_id'] ?? null, ':id'=>$borrowId]);
    if ($assetId > 0) {
        $pdo->prepare("UPDATE assets SET condition_status=:condition_status, updated_at=NOW() WHERE id=:id")->execute([':condition_status'=>$returnCondition, ':id'=>$assetId]);
        asset_add_event($pdo, $assetId, 'check_in', 'Aset dikembalikan / check-in', $notes, 'asset_borrowings', $borrowId);
    }
    $_SESSION['success'] = 'Pengembalian aset berhasil dicatat.';
} catch (Exception $e) { $_SESSION['error'] = $e->getMessage(); }
redirect($assetId ? 'pages/assets/detail.php?id='.$assetId.'#borrowing' : 'pages/assets/index.php');
