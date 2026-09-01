<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$rentalId = (int)($_POST['rental_id'] ?? 0);
$assetId = (int)($_POST['asset_id'] ?? 0);
if ($rentalId <= 0 || $assetId <= 0) redirect('pages/assets/index.php');

try {
    $amount = asset_money_value($_POST['amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $proof = asset_upload_file($_FILES['proof_file'] ?? [], 'assets', 'rental_payment', ['jpg','jpeg','png','webp','pdf']);
    $transactionId = asset_create_transaction($pdo, 'income', $amount, 'Pembayaran sewa aset ID #' . $assetId, $paymentMethod, $proof);
    $stmt = $pdo->prepare("INSERT INTO asset_rental_payments (rental_id, asset_id, payment_date, period_start, period_end, amount, payment_method, transaction_id, proof_file, notes, created_by) VALUES (:rental_id, :asset_id, :payment_date, :period_start, :period_end, :amount, :payment_method, :transaction_id, :proof_file, :notes, :created_by)");
    $stmt->execute([
        ':rental_id'=>$rentalId,
        ':asset_id'=>$assetId,
        ':payment_date'=>$_POST['payment_date'] ?: date('Y-m-d'),
        ':period_start'=>($_POST['period_start'] ?? '') ?: null,
        ':period_end'=>($_POST['period_end'] ?? '') ?: null,
        ':amount'=>$amount,
        ':payment_method'=>$paymentMethod,
        ':transaction_id'=>$transactionId,
        ':proof_file'=>$proof,
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    if (!empty($_POST['next_due_date'])) {
        $pdo->prepare("UPDATE asset_rentals SET next_due_date=:next_due_date, updated_at=NOW() WHERE id=:id")->execute([':next_due_date'=>$_POST['next_due_date'], ':id'=>$rentalId]);
        asset_create_notification($pdo, 'asset_rental_due', 'Jatuh Tempo Sewa Aset Berikutnya', 'Tagihan sewa berikutnya untuk aset ID #' . $assetId, $_POST['next_due_date'], 'warning', 'bi-key', 'pages/assets/detail.php?id=' . $assetId);
    }
    $_SESSION['success'] = 'Pembayaran sewa berhasil dicatat dan masuk ke transaksi income.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $assetId . '#rental');
