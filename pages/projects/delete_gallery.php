<?php
require_once __DIR__ . '/../../includes/auth.php';
require_post();

$id = $_POST['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT *
    FROM project_galleries
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$gallery = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gallery) {
    redirect('pages/projects/index.php');
}

$project_id = $gallery['project_id'];

try {
    $path = (string)($gallery['image_path'] ?? '');
    $isExternal = preg_match('/^https?:\/\//i', $path);

    $delete = $pdo->prepare("
        DELETE FROM project_galleries
        WHERE id = :id
        LIMIT 1
    ");
    $delete->execute([':id' => $id]);
    if (!$isExternal) upload_delete_file($path);

    redirect('pages/projects/detail.php?id=' . $project_id . '&tab=gallery');
} catch (Throwable $e) {
    redirect('pages/projects/detail.php?id=' . $project_id . '&tab=gallery');
}
