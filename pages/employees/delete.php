<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$mode = $_GET['mode'] ?? 'inactive';

if ($id <= 0) {
    redirect('pages/employees/index.php');
}

$checkStmt = $pdo->prepare("
    SELECT id, full_name, employment_status
    FROM employees
    WHERE id = :id
    LIMIT 1
");
$checkStmt->execute([':id' => $id]);
$employee = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    redirect('pages/employees/index.php');
}

try {
    if ($mode === 'delete') {
        $stmt = $pdo->prepare("
            DELETE FROM employees
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        redirect('pages/employees/index.php?success=delete');
    } else {
        $stmt = $pdo->prepare("
            UPDATE employees
            SET employment_status = 'inactive',
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        redirect('pages/employees/index.php?success=inactive');
    }
} catch (Exception $e) {
    error_log('Employee delete/deactivate failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Gagal memproses data karyawan. Periksa apakah data masih digunakan oleh modul lain.';
    redirect('pages/employees/index.php');
}
