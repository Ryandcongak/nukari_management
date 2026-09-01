<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

$id=(int)($_GET['id']??0);
if($id>0){
    $stmt=$pdo->prepare("SELECT photo FROM products WHERE id=?");
    $stmt->execute([$id]);
    $p=$stmt->fetch(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("DELETE FROM products WHERE id=?");
    $stmt->execute([$id]);

    if (!empty($p['photo']) && !preg_match('~^https?://~i', $p['photo'])) {
        $path = __DIR__ . '/../../' . ltrim($p['photo'], '/');
        if (is_file($path)) @unlink($path);
    }
}
header('Location: index.php');exit;
