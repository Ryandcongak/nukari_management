<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

if (!has_role('super_admin', 'admin')) {
    redirect('pages/dashboard.php');
}

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/users/index.php');
}

if ($id == $_SESSION['user_id']) {
    redirect('pages/users/index.php');
}

$stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
$stmt->execute([
    ':id' => $id
]);

redirect('pages/users/index.php');
