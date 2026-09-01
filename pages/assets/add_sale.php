<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/assets/detail.php?id=' . $id);

try {
    $assetStmt = $pdo->prepare("SELECT * FROM assets WHERE id=:id LIMIT 1");
    $assetStmt->execute([':id'=>$id]);
    $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$asset) throw new Exception('Aset tidak ditemukan.');

    $salePrice = asset_money_value($_POST['sale_price'] ?? 0);
    $bookValue = asset_money_value($_POST['book_value'] ?? ($asset['current_value'] ?? $asset['purchase_price'] ?? 0));
    $profitLoss = $salePrice - $bookValue;
    $proof = asset_upload_file($_FILES['proof_file'] ?? [], 'assets', 'asset_sale', ['jpg','jpeg','png','webp','pdf']);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $desc = 'Penjualan aset: ' . ($asset['asset_name'] ?? '-') . ' kepada ' . clean_input($_POST['buyer_name'] ?? '-');
    $transactionId = asset_create_transaction($pdo, 'income', $salePrice, $desc, $paymentMethod, $proof);

    $stmt = $pdo->prepare("INSERT INTO asset_sales (asset_id, sale_date, buyer_name, buyer_phone, sale_price, book_value, profit_loss, payment_method, transaction_id, proof_file, notes, created_by) VALUES (:asset_id, :sale_date, :buyer_name, :buyer_phone, :sale_price, :book_value, :profit_loss, :payment_method, :transaction_id, :proof_file, :notes, :created_by)");
    $stmt->execute([
        ':asset_id'=>$id,
        ':sale_date'=>$_POST['sale_date'] ?: date('Y-m-d'),
        ':buyer_name'=>clean_input($_POST['buyer_name'] ?? ''),
        ':buyer_phone'=>clean_input($_POST['buyer_phone'] ?? ''),
        ':sale_price'=>$salePrice,
        ':book_value'=>$bookValue,
        ':profit_loss'=>$profitLoss,
        ':payment_method'=>$paymentMethod,
        ':transaction_id'=>$transactionId,
        ':proof_file'=>$proof,
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $pdo->prepare("UPDATE assets SET asset_status='sold', condition_status='lost', current_value=0, disposed_at=:sale_date, disposed_reason='Dijual kembali', updated_at=NOW() WHERE id=:id")->execute([':sale_date'=>$_POST['sale_date'] ?: date('Y-m-d'), ':id'=>$id]);
    asset_add_event($pdo, $id, 'sale', 'Aset dijual kembali', $desc . '. Harga jual: ' . $salePrice . ', laba/rugi: ' . $profitLoss, 'asset_sales', (int)$pdo->lastInsertId(), ($_POST['sale_date'] ?: date('Y-m-d')) . ' 00:00:00');
    $_SESSION['success'] = 'Penjualan aset berhasil dicatat dan transaksi income otomatis dibuat.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $id . '#sale');
