<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('pages/clients/index.php?error=Client tidak valid'); }

$stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = :id");
$stmt->execute([':id' => $id]);
$used = (int)$stmt->fetchColumn();

if ($used > 0) {
    redirect('pages/clients/index.php?error=Client sudah dipakai di project. Ubah status menjadi inactive jika tidak digunakan lagi.');
}

$stmt = $pdo->prepare("DELETE FROM clients WHERE id = :id");
$stmt->execute([':id' => $id]);
redirect('pages/clients/index.php?message=Client berhasil dihapus');
