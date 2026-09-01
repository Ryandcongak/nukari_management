<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('pages/suppliers/index.php?error=Vendor tidak valid'); }
$stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = :id");
$stmt->execute([':id'=>$id]);
redirect('pages/suppliers/index.php?message=Vendor berhasil dihapus');
