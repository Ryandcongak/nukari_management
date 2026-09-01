<?php
require_once __DIR__ . '/../../includes/auth.php';

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    redirect('pages/projects/index.php');
}

/* DATA PROJECT */
$stmt = $pdo->prepare("
    SELECT id, project_name, contract_value
    FROM projects
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

/* DATA PEKERJA */
$workersStmt = $pdo->prepare("
    SELECT *
    FROM project_workers
    WHERE project_id = :id
    ORDER BY id DESC
");
$workersStmt->execute([':id' => $project_id]);
$workers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);

/* TOTAL */
$totalWorkerCost = 0;
$totalEmployee = 0;
$totalFreelance = 0;
$totalBorongan = 0;

foreach ($workers as $w) {
    $totalWorkerCost += (float) $w['rate'];

    if ($w['worker_type'] === 'employee') {
        $totalEmployee++;
    } elseif ($w['worker_type'] === 'freelance') {
        $totalFreelance++;
    } elseif ($w['worker_type'] === 'borongan') {
        $totalBorongan++;
    }
}

$pageTitle = 'Pekerja Project - Nukari';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Pekerja Project</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p>
        </div>

        <div class="d-flex gap-2">
            <a href="<?= base_url('pages/projects/add_worker.php?project_id=' . $project_id); ?>" class="btn btn-nukari">
                <i class="bi bi-plus-circle me-1"></i> Tambah Pekerja
            </a>

            <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">

        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Total Pekerja</div>
                <div class="stat-number"><?= count($workers); ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Employee</div>
                <div class="stat-number"><?= $totalEmployee; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Freelance</div>
                <div class="stat-number"><?= $totalFreelance; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Total Biaya</div>
                <div class="stat-number"><?= rupiah($totalWorkerCost); ?></div>
            </div>
        </div>

    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">
            <i class="bi bi-info-circle me-1"></i> Panduan Pekerja Project
        </h5>

        <div class="row g-3">
            <div class="col-md-4">
                <div class="border rounded-4 p-3 h-100">
                    <div class="fw-bold text-success mb-1">Employee</div>
                    <small class="text-muted">
                        Karyawan tetap perusahaan yang ditugaskan ke project.
                    </small>
                </div>
            </div>

            <div class="col-md-4">
                <div class="border rounded-4 p-3 h-100">
                    <div class="fw-bold text-warning mb-1">Freelance</div>
                    <small class="text-muted">
                        Pekerja lepas yang dibayar harian, per kedatangan, atau sesuai kebutuhan.
                    </small>
                </div>
            </div>

            <div class="col-md-4">
                <div class="border rounded-4 p-3 h-100">
                    <div class="fw-bold text-danger mb-1">Borongan</div>
                    <small class="text-muted">
                        Tim atau orang yang dibayar berdasarkan paket pekerjaan/project.
                    </small>
                </div>
            </div>
        </div>

        <hr>

        <p class="text-muted small mb-0">
            Nilai <strong>rate</strong> akan dihitung sebagai biaya tenaga kerja project.
            Untuk tahap awal, rate dihitung langsung sebagai total estimasi biaya pekerja.
        </p>
    </div>

    <div class="panel">
        <h5 class="fw-bold mb-3">Daftar Pekerja</h5>

        <div class="table-responsive">
            <table class="table table-hover align-middle">

                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Tipe</th>
                        <th>Sistem Bayar</th>
                        <th>Rate</th>
                        <th>Catatan</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($workers): ?>
                        <?php foreach ($workers as $w): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($w['worker_name']); ?></td>

                                <td>
                                    <?php if ($w['worker_type'] === 'employee'): ?>
                                        <span class="badge rounded-pill badge-soft-green">Employee</span>
                                    <?php elseif ($w['worker_type'] === 'freelance'): ?>
                                        <span class="badge rounded-pill badge-soft-orange">Freelance</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-red">Borongan</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($w['payment_type'] === 'daily'): ?>
                                        <span class="badge rounded-pill badge-soft-blue">Harian</span>
                                    <?php elseif ($w['payment_type'] === 'per_attendance'): ?>
                                        <span class="badge rounded-pill badge-soft-purple">Per Kedatangan</span>
                                    <?php elseif ($w['payment_type'] === 'monthly'): ?>
                                        <span class="badge rounded-pill badge-soft-green">Bulanan</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-orange">Borongan</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= rupiah($w['rate']); ?></td>

                                <td><?= htmlspecialchars($w['notes'] ?? '-'); ?></td>

                                <td class="text-end">
                                    <a href="<?= base_url('pages/projects/edit_worker.php?id=' . $w['id']); ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                    </a>

                                    <a
                                        href="<?= base_url('pages/projects/delete_worker.php?id=' . $w['id']); ?>"
                                        onclick="return confirm('Yakin ingin menghapus pekerja dari project ini?')"
                                        class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i> Hapus
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <tr class="table-light fw-bold">
                            <td colspan="3">Total Biaya Tenaga Kerja</td>
                            <td><?= rupiah($totalWorkerCost); ?></td>
                            <td colspan="2">
                                Employee: <?= $totalEmployee; ?> |
                                Freelance: <?= $totalFreelance; ?> |
                                Borongan: <?= $totalBorongan; ?>
                            </td>
                        </tr>

                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Belum ada pekerja pada project ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>