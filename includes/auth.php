<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/authorization_helper.php';
require_once __DIR__ . '/activity_log.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', request_is_https() ? '1' : '0');
    session_start();
}

send_security_headers();

require_once __DIR__ . '/../config/database.php';

if (!is_logged_in()) {
    redirect('login.php');
}

$currentRole = (string)($_SESSION['role'] ?? '');
if (!is_valid_app_role($currentRole)) {
    session_unset();
    session_destroy();
    redirect('login.php?role_invalid=1');
}

if (!empty($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 7200) {
    session_unset();
    session_destroy();
    redirect('login.php?expired=1');
}
$_SESSION['last_seen'] = time();

$path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$readPermission = read_permission_for_path($path);
if ($readPermission !== null) require_permission($readPermission);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') verify_csrf_or_abort();

// Matriks izin minimum untuk perubahan data. Pemeriksaan dilakukan di server,
// sehingga mengetik URL secara langsung tidak melewati pembatasan menu.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $roles = mutation_roles_for_path($path);
    if ($roles !== null) require_role(...$roles);

    // Semua perubahan tercatat minimal pada tingkat request. Modul kritis tetap
    // menambahkan audit rinci (nilai, alasan, dan referensi transaksi) sendiri.
    // Nilai form tidak disimpan di sini agar password, token, dan data sensitif
    // tidak pernah masuk ke log umum.
    $auditPath = $path;
    $auditAction = strtoupper((string)($_POST['action'] ?? pathinfo($path, PATHINFO_FILENAME)));
    $auditAction = preg_replace('/[^A-Z0-9_-]+/', '_', $auditAction) ?: 'POST';
    $auditReferenceId = null;
    foreach (['id', 'payroll_id', 'employee_id', 'project_id', 'transaction_id'] as $idField) {
        if (isset($_POST[$idField]) && is_scalar($_POST[$idField]) && (int)$_POST[$idField] > 0) {
            $auditReferenceId = (int)$_POST[$idField];
            break;
        }
    }
    $auditUserId = (int)($_SESSION['user_id'] ?? 0) ?: null;
    register_shutdown_function(static function () use ($pdo, $auditPath, $auditAction, $auditReferenceId, $auditUserId): void {
        if ($pdo->inTransaction()) return;
        $status = http_response_code();
        $status = $status > 0 ? $status : 200;
        log_activity(
            $pdo,
            'Request ' . trim(dirname($auditPath), '/'),
            'REQUEST_' . $auditAction,
            'POST ' . $auditPath . ' selesai dengan HTTP ' . $status . '.',
            $auditReferenceId,
            null,
            $auditUserId
        );
    });
}

$updateActivity = $pdo->prepare("
    UPDATE users 
    SET last_activity = NOW() 
    WHERE id = :id
");
$updateActivity->execute([
    ':id' => $_SESSION['user_id']
]);
