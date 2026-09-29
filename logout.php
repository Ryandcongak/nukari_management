<?php
require_once __DIR__ . '/includes/functions.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', request_is_https() ? '1' : '0');
session_start();

require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET last_activity = NULL
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $_SESSION['user_id']
    ]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header("Location: login.php");
exit;
