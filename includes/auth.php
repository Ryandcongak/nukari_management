<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

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
    $rules = [
        '/pages/users/' => ['super_admin', 'admin'],
        '/pages/payroll/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/finance/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/debts/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/reimbursements/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/employees/' => ['super_admin', 'admin', 'manager'],
        '/pages/attendances/' => ['super_admin', 'admin', 'manager'],
        '/pages/hr/' => ['super_admin', 'admin', 'manager'],
        '/pages/projects/' => ['super_admin', 'admin', 'manager', 'finance', 'staff'],
        '/pages/assets/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/products/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/clients/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/suppliers/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/notifications/' => ['super_admin', 'admin', 'manager'],
    ];
    foreach ($rules as $prefix => $roles) {
        if (strpos($path, $prefix) !== false) {
            require_role(...$roles);
            break;
        }
    }
}

$updateActivity = $pdo->prepare("
    UPDATE users 
    SET last_activity = NOW() 
    WHERE id = :id
");
$updateActivity->execute([
    ':id' => $_SESSION['user_id']
]);
