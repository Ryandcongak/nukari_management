<?php
function project_sync_completion_status(PDO $pdo, int $projectId): string
{
    if ($projectId <= 0) return 'planning';
    $stmt = $pdo->prepare("SELECT status FROM projects WHERE id=:id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $projectId]);
    $current = (string)($stmt->fetchColumn() ?: 'planning');
    if ($current === 'cancelled') return $current;

    $task = $pdo->prepare("SELECT COUNT(*) total,SUM(status='completed') completed FROM project_tasks WHERE project_id=:id");
    $task->execute([':id' => $projectId]);
    $tasks = $task->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'completed'=>0];
    $term = $pdo->prepare("SELECT COUNT(*) total,SUM(status='paid') paid FROM project_payment_terms WHERE project_id=:id");
    $term->execute([':id' => $projectId]);
    $terms = $term->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'paid'=>0];

    $workComplete = (int)$tasks['total'] > 0 && (int)$tasks['total'] === (int)$tasks['completed'];
    $paymentComplete = (int)$terms['total'] > 0 && (int)$terms['total'] === (int)$terms['paid'];
    $next = $workComplete && $paymentComplete ? 'completed' : ((int)$tasks['total'] > 0 ? 'ongoing' : 'planning');
    if ($next !== $current) {
        $update = $pdo->prepare("UPDATE projects SET status=:status,updated_at=NOW() WHERE id=:id AND status<>'cancelled'");
        $update->execute([':status'=>$next, ':id'=>$projectId]);
    }
    return $next;
}
