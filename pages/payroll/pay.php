<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . base_url('pages/payroll/index.php'));
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
if ($currentUserId <= 0) {
    $currentUserId = null;
}

try {
    $stmt = $pdo->prepare("
        SELECT p.id, p.payroll_month, p.status, p.is_locked, e.full_name
        FROM payrolls p
        LEFT JOIN employees e ON p.employee_id = e.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        header('Location: ' . base_url('pages/payroll/index.php'));
        exit;
    }

    if ((int)($payroll['is_locked'] ?? 0) === 1 && $payroll['status'] === 'paid') {
        header('Location: ' . base_url('pages/payroll/detail.php?id=' . $id));
        exit;
    }

    $update = $pdo->prepare("
        UPDATE payrolls
        SET 
            status = 'paid',
            paid_date = COALESCE(paid_date, CURDATE()),
            is_locked = 1,
            locked_at = COALESCE(locked_at, NOW()),
            locked_by = COALESCE(locked_by, :locked_by),
            updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ");

    $update->execute([
        ':locked_by' => $currentUserId,
        ':id' => $id
    ]);

    log_activity(
        $pdo,
        'Payroll',
        'PAID_LOCKED',
        'Menandai payroll sebagai paid dan locked untuk ' . ($payroll['full_name'] ?? '-') . ' periode ' . ($payroll['payroll_month'] ?? '-'),
        $id,
        'payrolls',
        $currentUserId
    );

    header('Location: ' . base_url('pages/payroll/detail.php?id=' . $id . '&locked=1'));
    exit;
} catch (Exception $e) {
    die('Gagal mengubah status payroll: ' . htmlspecialchars($e->getMessage()));
}
