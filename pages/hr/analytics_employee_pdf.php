<?php

require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations.view');
require_once __DIR__ . '/analytics_employee_pdf_helper.php';

$employeeId = filter_var($_GET['employee_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$mode = strtolower(trim((string)($_GET['mode'] ?? 'inline')));
if (!in_array($mode, ['inline', 'download'], true)) $mode = 'inline';

if (!$employeeId) {
    http_response_code(400);
    exit('Karyawan tidak valid.');
}

try {
    $payload = hr_build_employee_evaluation($pdo, (int)$employeeId, $_GET);
    if (!$payload) {
        http_response_code(404);
        exit('Data karyawan atau evaluasi pada periode ini tidak ditemukan.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $pdf = hr_render_employee_evaluation_pdf($payload, current_user_name());
    $pdf->stream(hr_employee_pdf_filename($payload), [
        'Attachment' => $mode === 'download',
        'compress' => true,
    ]);
} catch (Throwable $e) {
    error_log('HR Analytics employee PDF failed: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    exit('PDF evaluasi belum dapat dibuat. Silakan coba kembali.');
}
