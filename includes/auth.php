<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/authorization_helper.php';

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

if (!empty($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 7200) {
    session_unset();
    session_destroy();
    redirect('login.php?expired=1');
}
$_SESSION['last_seen'] = time();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') verify_csrf_or_abort();

// Matriks izin minimum untuk perubahan data. Pemeriksaan dilakukan di server,
// sehingga mengetik URL secara langsung tidak melewati pembatasan menu.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $roles = mutation_roles_for_path($path);
    if ($roles !== null) require_role(...$roles);
}

$updateActivity = $pdo->prepare("
    UPDATE users 
    SET last_activity = NOW() 
    WHERE id = :id
");
$updateActivity->execute([
    ':id' => $_SESSION['user_id']
]);
