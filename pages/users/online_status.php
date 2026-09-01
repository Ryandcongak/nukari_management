<?php
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

$stmt = $pdo->query("
    SELECT id, last_activity
    FROM users
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];

foreach ($users as $user) {
    $isOnline = false;

    if (!empty($user['last_activity'])) {
        $lastActivity = strtotime($user['last_activity']);
        $isOnline = (time() - $lastActivity) <= 180; // 3 menit
    }

    $data[] = [
        'id' => $user['id'],
        'online' => $isOnline,
        'last_activity' => $user['last_activity']
            ? date('d M Y H:i', strtotime($user['last_activity']))
            : '-'
    ];
}

echo json_encode($data);
