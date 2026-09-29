<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_status_helper.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("
    SELECT t.*, p.project_name
    FROM project_payment_terms t
    JOIN projects p ON t.project_id = p.id
    WHERE t.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$term = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$term) {
    redirect('pages/projects/index.php');
}

$pageTitle = 'Hapus Termin Project - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        $pdo->beginTransaction();

        // =========================
        // HAPUS TRANSAKSI JIKA PAID
        // =========================
        if ($term['status'] === 'paid') {

            $deleteTrx = $pdo->prepare("
                DELETE FROM project_transactions
                WHERE project_id = :project_id
                AND transaction_type = 'income'
                AND category = 'Termin Pembayaran'
                AND description LIKE :desc
                LIMIT 1
            ");

            $deleteTrx->execute([
                ':project_id' => $term['project_id'],
                ':desc' => 'Pembayaran ' . $term['term_name'] . '%'
            ]);
        }

        // =========================
        // HAPUS TERMIN
        // =========================
        $delete = $pdo->prepare("
            DELETE FROM project_payment_terms
            WHERE id = :id
            LIMIT 1
        ");

        $delete->execute([':id' => $id]);

        $pdo->commit();
        project_sync_completion_status($pdo, (int)$term['project_id']);

        redirect('pages/projects/terms.php?project_id=' . $term['project_id']);
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Gagal menghapus termin.';
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1 text-danger">Hapus Termin</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($term['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/terms.php?project_id=' . $term['project_id']); ?>" class="btn btn-outline-success">
            ← Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-8">
            <div class="panel">

                <h5 class="fw-bold mb-3">Konfirmasi Hapus Termin</h5>

                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <li>Termin <strong><?= htmlspecialchars($term['term_name']); ?></strong> akan dihapus.</li>

                        <?php if ($term['status'] === 'paid'): ?>
                            <li>Transaksi income terkait juga akan ikut dihapus.</li>
                        <?php endif; ?>

                        <li>Data tidak dapat dikembalikan.</li>
                    </ul>
                </div>

                <form method="POST">
                    <button type="submit" class="btn btn-danger">
                        🗑 Hapus Permanen
                    </button>

                    <a href="<?= base_url('pages/projects/terms.php?project_id=' . $term['project_id']); ?>" class="btn btn-light">
                        Batal
                    </a>
                </form>

            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">

                <h5 class="fw-bold mb-3">Panduan</h5>

                <p class="small text-muted mb-2">
                    Gunakan hapus hanya jika termin salah input.
                </p>

                <p class="small text-muted mb-0">
                    Untuk perubahan nilai atau tanggal, gunakan fitur edit agar histori tetap rapi.
                </p>

            </div>
        </div>

    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
