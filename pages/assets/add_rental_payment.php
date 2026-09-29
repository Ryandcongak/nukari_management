<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$rentalId = (int)($_POST['rental_id'] ?? 0);
$assetId = (int)($_POST['asset_id'] ?? 0);
if ($rentalId <= 0 || $assetId <= 0) redirect('pages/assets/index.php');

try {
    $stmt = $pdo->prepare("SELECT r.*, a.asset_name, a.asset_status FROM asset_rentals r JOIN assets a ON a.id=r.asset_id WHERE r.id=:rental_id AND r.asset_id=:asset_id LIMIT 1");
    $stmt->execute([':rental_id'=>$rentalId, ':asset_id'=>$assetId]);
    $rental = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rental || !in_array($rental['status'], ['active','overdue'], true)) throw new Exception('Penyewaan tidak aktif.');
    $amount = asset_money_value($_POST['amount'] ?? 0);
    if ($amount <= 0) throw new Exception('Nominal pembayaran wajib lebih dari nol.');
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $accountId = (int)($_POST['account_id'] ?? 0);
    if ($accountId <= 0) throw new Exception('Pilih akun kas/bank penerima pembayaran sewa.');
    $projectId = (int)($_POST['project_id'] ?? ($rental['project_id'] ?? 0)) ?: null;
    $paymentDate = $_POST['payment_date'] ?: date('Y-m-d');
    $proof = asset_upload_file($_FILES['proof_file'] ?? [], 'assets', 'rental_payment', ['jpg','jpeg','png','webp','pdf']);
    $description = 'Pembayaran sewa aset: ' . ($rental['asset_name'] ?? ('ID #' . $assetId));
    $pdo->beginTransaction();
    $transactionId = asset_create_transaction($pdo, 'income', $amount, $description, $paymentMethod, $proof, $projectId, $assetId, $accountId, $paymentDate);
    $stmt = $pdo->prepare("INSERT INTO asset_rental_payments (rental_id, asset_id, project_id, account_id, payment_date, period_start, period_end, amount, payment_method, transaction_id, proof_file, notes, created_by) VALUES (:rental_id, :asset_id, :project_id, :account_id, :payment_date, :period_start, :period_end, :amount, :payment_method, :transaction_id, :proof_file, :notes, :created_by)");
    $stmt->execute([
        ':rental_id'=>$rentalId,
        ':asset_id'=>$assetId,
        ':project_id'=>$projectId,
        ':account_id'=>$accountId,
        ':payment_date'=>$paymentDate,
        ':period_start'=>($_POST['period_start'] ?? '') ?: null,
        ':period_end'=>($_POST['period_end'] ?? '') ?: null,
        ':amount'=>$amount,
        ':payment_method'=>$paymentMethod,
        ':transaction_id'=>$transactionId,
        ':proof_file'=>$proof,
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $paymentId = (int)$pdo->lastInsertId();
    if ($projectId) {
        $projectTransactionId = asset_create_project_mirror($pdo, $projectId, 'income', $amount, 'Pendapatan Sewa Aset', $description, 'asset_rental', $paymentId, $paymentMethod, $accountId, $paymentDate);
        $pdo->prepare("UPDATE asset_rental_payments SET project_transaction_id=:project_transaction_id WHERE id=:id")->execute([':project_transaction_id'=>$projectTransactionId, ':id'=>$paymentId]);
    }
    if (!empty($_POST['next_due_date'])) {
        $pdo->prepare("UPDATE asset_rentals SET next_due_date=:next_due_date, updated_at=NOW() WHERE id=:id")->execute([':next_due_date'=>$_POST['next_due_date'], ':id'=>$rentalId]);
        asset_create_notification($pdo, 'asset_rental_due', 'Jatuh Tempo Sewa Aset Berikutnya', 'Tagihan sewa berikutnya untuk aset ID #' . $assetId, $_POST['next_due_date'], 'warning', 'bi-key', 'pages/assets/detail.php?id=' . $assetId);
    }
    $pdo->commit();
    $_SESSION['success'] = 'Pembayaran sewa berhasil dicatat dan masuk ke transaksi income.';
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $assetId . '#rental');
