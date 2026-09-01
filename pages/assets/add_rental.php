<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('pages/assets/detail.php?id=' . $id);

try {
    $amount = asset_money_value($_POST['rental_amount'] ?? 0);
    $deposit = asset_money_value($_POST['deposit_amount'] ?? 0);
    $stmt = $pdo->prepare("INSERT INTO asset_rentals (asset_id, renter_name, renter_phone, renter_address, rental_start, rental_end, billing_cycle, rental_amount, deposit_amount, next_due_date, status, notes, created_by) VALUES (:asset_id, :renter_name, :renter_phone, :renter_address, :rental_start, :rental_end, :billing_cycle, :rental_amount, :deposit_amount, :next_due_date, 'active', :notes, :created_by)");
    $stmt->execute([
        ':asset_id'=>$id,
        ':renter_name'=>clean_input($_POST['renter_name'] ?? ''),
        ':renter_phone'=>clean_input($_POST['renter_phone'] ?? ''),
        ':renter_address'=>clean_input($_POST['renter_address'] ?? ''),
        ':rental_start'=>$_POST['rental_start'] ?: date('Y-m-d'),
        ':rental_end'=>($_POST['rental_end'] ?? '') ?: null,
        ':billing_cycle'=>$_POST['billing_cycle'] ?? 'monthly',
        ':rental_amount'=>$amount,
        ':deposit_amount'=>$deposit,
        ':next_due_date'=>($_POST['next_due_date'] ?? '') ?: null,
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $rentalId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE assets SET asset_status='rented', ownership_status='rented_out', use_type='rental', updated_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
    asset_add_event($pdo, $id, 'rental', 'Aset disewakan', 'Penyewa: ' . clean_input($_POST['renter_name'] ?? '-') . ', nilai sewa: ' . $amount, 'asset_rentals', $rentalId, ($_POST['rental_start'] ?: date('Y-m-d')) . ' 00:00:00');
    if (!empty($_POST['next_due_date'])) {
        asset_create_notification($pdo, 'asset_rental_due', 'Jatuh Tempo Sewa Aset', 'Tagihan sewa aset ID #' . $id . ' kepada ' . clean_input($_POST['renter_name'] ?? '-'), $_POST['next_due_date'], 'warning', 'bi-key', 'pages/assets/detail.php?id=' . $id);
    }
    $_SESSION['success'] = 'Penyewaan aset berhasil dicatat.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}
redirect('pages/assets/detail.php?id=' . $id . '#rental');
