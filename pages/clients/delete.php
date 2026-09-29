<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('pages/clients/index.php?error=Client tidak valid'); }

$stmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM projects WHERE client_id=:project_client_id) + (SELECT COUNT(*) FROM product_cashier_sales WHERE client_id=:sale_client_id) + (SELECT COUNT(*) FROM product_custom_orders WHERE client_id=:order_client_id)");
$stmt->execute([':project_client_id' => $id, ':sale_client_id' => $id, ':order_client_id' => $id]);
$used = (int)$stmt->fetchColumn();

if ($used > 0) {
    $pdo->prepare("UPDATE clients SET status='inactive',updated_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
    redirect('pages/clients/index.php?message=Client memiliki riwayat transaksi dan telah dinonaktifkan tanpa menghapus histori.');
}

$stmt = $pdo->prepare("DELETE FROM clients WHERE id = :id");
$stmt->execute([':id' => $id]);
redirect('pages/clients/index.php?message=Client berhasil dihapus');
