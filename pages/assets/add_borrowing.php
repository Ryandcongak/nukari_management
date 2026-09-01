<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? 0);
if ($id <= 0) redirect('pages/assets/index.php');
try {
    $employeeId = ($_POST['employee_id'] ?? '') !== '' ? (int)$_POST['employee_id'] : null;
    $borrowerName = clean_input($_POST['borrower_name'] ?? '');
    if ($employeeId && $borrowerName === '') {
        $e = $pdo->prepare("SELECT full_name FROM employees WHERE id=:id"); $e->execute([':id'=>$employeeId]); $borrowerName = $e->fetchColumn() ?: '';
    }
    if ($borrowerName === '') throw new Exception('Nama peminjam wajib diisi.');
    $stmt = $pdo->prepare("INSERT INTO asset_borrowings (asset_id, borrower_type, employee_id, borrower_name, borrower_phone, check_out_date, expected_return_date, status, purpose, out_condition, notes, created_by) VALUES (:asset_id,:borrower_type,:employee_id,:borrower_name,:borrower_phone,:check_out_date,:expected_return_date,'borrowed',:purpose,:out_condition,:notes,:created_by)");
    $stmt->execute([
        ':asset_id'=>$id,
        ':borrower_type'=>$_POST['borrower_type'] ?? 'employee',
        ':employee_id'=>$employeeId,
        ':borrower_name'=>$borrowerName,
        ':borrower_phone'=>clean_input($_POST['borrower_phone'] ?? ''),
        ':check_out_date'=>$_POST['check_out_date'] ?: date('Y-m-d H:i:s'),
        ':expected_return_date'=>$_POST['expected_return_date'] ?: null,
        ':purpose'=>clean_input($_POST['purpose'] ?? ''),
        ':out_condition'=>$_POST['out_condition'] ?? 'good',
        ':notes'=>clean_input($_POST['notes'] ?? ''),
        ':created_by'=>$_SESSION['user_id'] ?? null,
    ]);
    $borrowId = (int)$pdo->lastInsertId();
    asset_add_event($pdo, $id, 'check_out', 'Aset dipinjam / check-out', 'Dipinjam oleh: '.$borrowerName, 'asset_borrowings', $borrowId, $_POST['check_out_date'] ?: date('Y-m-d H:i:s'));
    if (!empty($_POST['expected_return_date'])) {
        asset_create_asset_reminder($pdo, $id, 'other', 'Pengembalian Aset', 'Aset harus dikembalikan oleh '.$borrowerName, $_POST['expected_return_date'], 1, 'warning', 'pages/assets/detail.php?id='.$id.'#borrowing');
    }
    $_SESSION['success'] = 'Peminjaman aset berhasil dicatat.';
} catch (Exception $e) { $_SESSION['error'] = $e->getMessage(); }
redirect('pages/assets/detail.php?id='.$id.'#borrowing');
