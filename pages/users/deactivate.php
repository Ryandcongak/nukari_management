<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';
require_post();
require_permission('users.manage');

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/users/index.php');
}

if ($id == $_SESSION['user_id']) {
    redirect('pages/users/index.php');
}

$targetStmt = $pdo->prepare("SELECT username, role, status FROM users WHERE id=:id LIMIT 1");
$targetStmt->execute([':id' => $id]);
$target = $targetStmt->fetch(PDO::FETCH_ASSOC);
if (!$target) redirect('pages/users/index.php');
if ($target['role'] === 'super_admin' && $target['status'] === 'active') {
    $activeSuperadmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin' AND status='active'")->fetchColumn();
    if ($activeSuperadmins <= 1) redirect('pages/users/index.php?error=Superadmin aktif terakhir tidak dapat dinonaktifkan');
}

$stmt = $pdo->prepare("
    UPDATE users 
    SET status = 'inactive',
        updated_at = NOW()
    WHERE id = :id
");

$stmt->execute([
    ':id' => $id
]);

log_activity($pdo, 'User Management', 'DEACTIVATE', 'Menonaktifkan akun ' . $target['username'] . '.', (int)$id, 'users');

redirect('pages/users/index.php');
