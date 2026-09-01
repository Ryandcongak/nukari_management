<?php
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
