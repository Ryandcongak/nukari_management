<?php
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Data Absensi - Nukari';

$filter_date = $_GET['date'] ?? date('Y-m-d');

function format_time_attendance($time)
{
    return !empty($time) ? date('H:i', strtotime($time)) : '-';
}

function format_minutes_attendance($minutes)
{
    $minutes = (int) $minutes;

    if ($minutes <= 0) {
        return '-';
    }

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    if ($hours > 0) {
        return $hours . ' jam ' . $mins . ' menit';
    }

    return $mins . ' menit';
}

$stmt = $pdo->prepare("
    SELECT 
        a.*,
        e.full_name,
        e.employee_code,
        e.fingerprint_id,
        e.position,
        e.department
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.attendance_date = :filter_date
    ORDER BY e.full_name ASC
");

$stmt->execute([
    ':filter_date' => $filter_date
]);

$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPresent = 0;
$totalLate = 0;
$totalAbsent = 0;
$totalEarlyLeave = 0;

foreach ($attendances as $att) {
    if ($att['status'] === 'absent') {
        $totalAbsent++;
    } else {
        $totalPresent++;
    }

    if ((int) $att['late_minutes'] > 0 || $att['status'] === 'late') {
        $totalLate++;
    }

    if ((int) $att['early_leave_minutes'] > 0) {
        $totalEarlyLeave++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Data Absensi</h1>
            <p class="text-muted mb-0">Pantau hasil import absensi dari mesin fingerprint.</p>
        </div>

        <a href="<?= base_url('pages/attendances/import.php'); ?>" class="btn btn-nukari">
            <i class="bi bi-upload me-1"></i> Import Absensi
        </a>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Hadir</div>
                <div class="stat-number"><?= $totalPresent; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Terlambat</div>
                <div class="stat-number"><?= $totalLate; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Pulang Awal</div>
                <div class="stat-number"><?= $totalEarlyLeave; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Tanggal</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= date('d M Y', strtotime($filter_date)); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Filter Tanggal</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date); ?>">
            </div>

            <div class="col-md-3">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-search me-1"></i> Tampilkan
                </button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Kode</th>
                        <th>Fingerprint</th>
                        <th>Pagi Masuk</th>
                        <th>Pagi Keluar</th>
                        <th>Siang Masuk</th>
                        <th>Siang Keluar</th>
                        <th>Lembur Masuk</th>
                        <th>Lembur Keluar</th>
                        <th>Terlambat</th>
                        <th>Pulang Awal</th>
                        <th>Total Kerja</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($attendances): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($attendances as $att): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($att['full_name']); ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($att['position'] ?? '-'); ?>
                                        <?= !empty($att['department']) ? ' - ' . htmlspecialchars($att['department']) : ''; ?>
                                    </small>
                                </td>

                                <td><?= htmlspecialchars($att['employee_code'] ?? '-'); ?></td>

                                <td>
                                    <span class="badge rounded-pill badge-soft-blue">
                                        <?= htmlspecialchars($att['fingerprint_id'] ?? '-'); ?>
                                    </span>
                                </td>

                                <td><?= format_time_attendance($att['morning_in']); ?></td>
                                <td><?= format_time_attendance($att['morning_out']); ?></td>
                                <td><?= format_time_attendance($att['afternoon_in']); ?></td>
                                <td><?= format_time_attendance($att['afternoon_out']); ?></td>
                                <td><?= format_time_attendance($att['overtime_in']); ?></td>
                                <td><?= format_time_attendance($att['overtime_out']); ?></td>

                                <td>
                                    <?php if ((int) $att['late_minutes'] > 0): ?>
                                        <span class="badge rounded-pill badge-soft-orange">
                                            <?= format_minutes_attendance($att['late_minutes']); ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ((int) $att['early_leave_minutes'] > 0): ?>
                                        <span class="badge rounded-pill badge-soft-red">
                                            <?= format_minutes_attendance($att['early_leave_minutes']); ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td><?= format_minutes_attendance($att['work_minutes']); ?></td>

                                <td>
                                    <?php if ($att['status'] === 'late'): ?>
                                        <span class="badge rounded-pill badge-soft-orange">Terlambat</span>
                                    <?php elseif ($att['status'] === 'absent'): ?>
                                        <span class="badge rounded-pill badge-soft-red">Alpha</span>
                                    <?php elseif ($att['status'] === 'permission'): ?>
                                        <span class="badge rounded-pill badge-soft-blue">Izin</span>
                                    <?php elseif ($att['status'] === 'sick'): ?>
                                        <span class="badge rounded-pill badge-soft-blue">Sakit</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-green">Hadir</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="14" class="text-center text-muted py-4">
                                Belum ada data absensi pada tanggal ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>