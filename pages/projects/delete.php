<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("SELECT p.id,p.project_name,p.status,
    (SELECT COUNT(*) FROM project_transactions t WHERE t.project_id=p.id) transaction_count,
    (SELECT COUNT(*) FROM project_payment_terms pt WHERE pt.project_id=p.id) term_count,
    (SELECT COUNT(*) FROM project_funds pf WHERE pf.project_id=p.id) fund_count,
    (SELECT COUNT(*) FROM project_workers pw WHERE pw.project_id=p.id) worker_count,
    (SELECT COUNT(*) FROM transactions ft WHERE ft.project_id=p.id) finance_transaction_count,
    (SELECT COUNT(*) FROM debts d WHERE d.project_id=p.id) debt_count,
    (SELECT COUNT(*) FROM operational_fund_movements om WHERE om.project_id=p.id) operational_movement_count
    FROM projects p WHERE p.id=:id LIMIT 1");
$stmt->execute([':id' => $id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

$canDelete = (int)$project['transaction_count'] === 0
    && (int)$project['term_count'] === 0
    && (int)$project['fund_count'] === 0
    && (int)$project['worker_count'] === 0
    && (int)$project['finance_transaction_count'] === 0
    && (int)$project['debt_count'] === 0
    && (int)$project['operational_movement_count'] === 0;
if (!$canDelete) {
    redirect('pages/projects/index.php?error=Project tidak dapat dihapus permanen karena sudah memiliki termin, pekerja, pendanaan, atau transaksi keuangan. Gunakan Batalkan untuk menyimpan histori.');
}

$pageTitle = 'Hapus Permanen Project - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if ($password === '') {
        $error = 'Password wajib diisi.';
    } else {
        $userStmt = $pdo->prepare("
            SELECT password 
            FROM users 
            WHERE id = :id 
            LIMIT 1
        ");
        $userStmt->execute([
            ':id' => $_SESSION['user_id']
        ]);

        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Password salah. Project tidak dihapus.';
        } else {
            try {
                $galleryStmt = $pdo->prepare("SELECT image_path FROM project_galleries WHERE project_id=:id AND image_path IS NOT NULL");
                $galleryStmt->execute([':id' => $id]);
                $galleryFiles = array_values(array_filter($galleryStmt->fetchAll(PDO::FETCH_COLUMN), static fn($path) => !preg_match('/^https?:\/\//i', (string)$path)));
                $pdo->beginTransaction();

                $pdo->prepare("DELETE FROM project_transactions WHERE project_id = :id")->execute([':id' => $id]);
                $pdo->prepare("DELETE FROM project_funds WHERE project_id = :id")->execute([':id' => $id]);
                $pdo->prepare("DELETE FROM project_workers WHERE project_id = :id")->execute([':id' => $id]);
                $pdo->prepare("DELETE FROM project_payment_terms WHERE project_id = :id")->execute([':id' => $id]);
                $pdo->prepare("DELETE FROM project_galleries WHERE project_id = :id")->execute([':id' => $id]);

                $pdo->prepare("DELETE FROM projects WHERE id = :id")->execute([':id' => $id]);

                $pdo->commit();
                foreach (array_unique($galleryFiles) as $file) upload_delete_file($file);

                redirect('pages/projects/index.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Gagal menghapus project secara permanen. Kemungkinan masih ada relasi data lain.';
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1 text-danger">Hapus Permanen Project</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/index.php'); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-8">
            <div class="panel">

                <h5 class="fw-bold mb-3">Konfirmasi Hapus Permanen</h5>

                <div class="alert alert-danger rounded-4">
                    <strong>PERINGATAN:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Project akan dihapus permanen.</li>
                        <li>Semua transaksi project akan terhapus.</li>
                        <li>Semua pendanaan project akan terhapus.</li>
                        <li>Semua pekerja project akan terhapus.</li>
                        <li>Semua termin pembayaran akan terhapus.</li>
                        <li>Data tidak bisa dikembalikan.</li>
                    </ul>
                </div>

                <form method="POST">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Masukkan Password Akun Anda</label>
                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Password akun login"
                            required>
                    </div>

                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i> Ya, Hapus Permanen
                    </button>

                    <a href="<?= base_url('pages/projects/index.php'); ?>" class="btn btn-light">
                        Batal
                    </a>

                </form>

            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-info-circle me-1"></i> Panduan
                </h5>

                <p class="text-muted small mb-2">
                    Gunakan fitur ini hanya untuk menghapus data contoh, salah input, atau project yang benar-benar tidak diperlukan.
                </p>

                <p class="text-muted small mb-0">
                    Untuk project asli perusahaan, lebih aman menggunakan tombol <strong>Batalkan</strong>, bukan hapus permanen.
                </p>
            </div>
        </div>

    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
