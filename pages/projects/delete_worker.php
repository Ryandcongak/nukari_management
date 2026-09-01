<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM project_workers
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$worker = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$worker) {
    redirect('pages/projects/index.php');
}

$project_id = $worker['project_id'];

try {
    $pdo->beginTransaction();

    if (!empty($worker['transaction_id'])) {
        if (function_exists('finance_clear_project_expense_links')) {
            finance_clear_project_expense_links($pdo, 'project_transactions', (int)$worker['transaction_id']);
        }

        $deleteTrx = $pdo->prepare("
            DELETE FROM project_transactions
            WHERE id = :id
            AND project_id = :project_id
            AND category = 'Biaya Pekerja'
            LIMIT 1
        ");

        $deleteTrx->execute([
            ':id' => $worker['transaction_id'],
            ':project_id' => $project_id
        ]);
    }

    $deleteWorker = $pdo->prepare("
        DELETE FROM project_workers
        WHERE id = :id
        LIMIT 1
    ");

    $deleteWorker->execute([
        ':id' => $id
    ]);

    $pdo->commit();

    redirect('pages/projects/detail.php?id=' . $project_id . '&tab=workers#tab-workers');
} catch (Exception $e) {
    $pdo->rollBack();

    redirect('pages/projects/detail.php?id=' . $project_id . '&tab=workers#tab-workers');
}
