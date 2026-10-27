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
    if ($activeSuperadmins <= 1) redirect('pages/users/index.php?error=Superadmin aktif terakhir tidak dapat dihapus');
}

log_activity($pdo, 'User Management', 'DELETE', 'Menghapus permanen akun ' . $target['username'] . '.', (int)$id, 'users');

$stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
$stmt->execute([
    ':id' => $id
]);

redirect('pages/users/index.php');
