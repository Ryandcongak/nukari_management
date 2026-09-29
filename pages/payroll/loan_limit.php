<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payroll_loan_helper.php';

header('Content-Type: application/json; charset=utf-8');
try {
    payroll_ensure_loan_policy_schema($pdo);
    $employeeId = (int)($_GET['employee_id'] ?? 0);
    $period = trim((string)($_GET['period'] ?? ''));
    $remaining = max(0, (float)($_GET['remaining'] ?? 0));
    if ($employeeId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period)) {
        throw new InvalidArgumentException('Parameter periode cicilan tidak valid.');
    }
    echo json_encode(['ok' => true] + payroll_loan_limit($pdo, $employeeId, $remaining, $period), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
