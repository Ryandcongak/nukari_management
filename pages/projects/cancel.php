<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT id, project_name, status 
    FROM projects 
    WHERE id = :id 
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $update = $pdo->prepare("
        UPDATE projects 
        SET status = 'cancelled'
        WHERE id = :id
    ");

    $update->execute([':id' => $id]);

    redirect('pages/projects/index.php');
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header">
        <h1 class="fw-bold text-warning">Batalkan Project</h1>
        <p class="text-muted"><?= htmlspecialchars($project['project_name']); ?></p>
    </div>

    <div class="panel">

        <h5 class="fw-bold mb-3">Konfirmasi Pembatalan</h5>

        <div class="alert alert-warning">
            Project akan dibatalkan, tetapi data tetap tersimpan.
        </div>

        <form method="POST">
            <button type="submit" class="btn btn-warning">
                Batalkan Project
            </button>

            <a href="<?= base_url('pages/projects/index.php'); ?>" class="btn btn-light">
                Kembali
            </a>
        </form>

    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>