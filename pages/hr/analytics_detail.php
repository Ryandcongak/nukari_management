<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/analytics_helper.php';

$employeeId = (int)($_GET['employee_id'] ?? 0);
$periodType = $_GET['type'] ?? 'monthly';
[$periodStart, $periodEnd] = hr_get_period_range($pdo, $periodType, $_GET);

$employeeStmt = $pdo->prepare("SELECT id, full_name, employee_code, position, department FROM employees WHERE id = :id AND LOWER(COALESCE(position,'')) NOT REGEXP :excluded_positions LIMIT 1");
$employeeStmt->execute([':id' => $employeeId, ':excluded_positions' => hr_executive_position_regex()]);
$employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    http_response_code(404);
    echo '<div class="alert alert-warning mb-0">Data karyawan tidak ditemukan atau jabatan tidak termasuk objek evaluasi HR Analytics.</div>';
    exit;
}

$detailStmt = $pdo->prepare("
    SELECT
        attendance_date,
        check_in,
        check_out,
        status,
        attendance_type,
        attendance_day_value,
        CASE
            WHEN attendance_type = 'half_day_morning' THEN 'morning'
            WHEN attendance_type = 'half_day_afternoon' THEN 'afternoon'
            ELSE NULL
        END AS half_day_type,
        COALESCE(late_minutes, 0) AS late_minutes,
        COALESCE(early_leave_minutes, 0) AS early_leave_minutes,
        CASE
            WHEN attendance_type = 'half_day_morning' AND TIME(check_in) < '08:00:00' THEN 'morning'
            WHEN attendance_type = 'half_day_afternoon' AND TIME(check_in) < '12:00:00' THEN 'afternoon'
            WHEN COALESCE(attendance_type, '') NOT IN ('half_day_morning','half_day_afternoon') AND TIME(check_in) < '08:00:00' THEN 'full_day'
            ELSE NULL
        END AS early_arrival_type,
        COALESCE(overtime_minutes, 0) AS overtime_minutes
    FROM attendances
    WHERE employee_id = :employee_id
      AND attendance_date BETWEEN :period_start AND :period_end
      AND (
          COALESCE(late_minutes, 0) > 0
          OR COALESCE(early_leave_minutes, 0) > 0
          OR COALESCE(overtime_minutes, 0) > 0
          OR LOWER(COALESCE(status, '')) IN ('absent', 'alpha', 'alpa', 'tidak hadir')
          OR attendance_type IN ('half_day_morning','half_day_afternoon')
          OR ((check_in IS NULL) <> (check_out IS NULL))
          OR (TIME(check_in) < '08:00:00')
      )
    ORDER BY attendance_date ASC
");
$detailStmt->execute([
    ':employee_id' => $employeeId,
    ':period_start' => $periodStart,
    ':period_end' => $periodEnd,
]);
$details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

$analyticsData = hr_build_analytics($pdo, $_GET);
$evaluation = null;
foreach ($analyticsData['rows'] as $analyticsRow) {
    if ((int)$analyticsRow['employee_id'] === $employeeId) {
        $evaluation = $analyticsRow;
        break;
    }
}
$evaluation = $evaluation ?: [
    'score'=>0, 'evaluation_note'=>'Belum ada data', 'evaluation_class'=>'secondary',
    'attendance_rate'=>0, 'discipline_rate'=>0, 'work_rate'=>0,
    'overtime_score'=>0, 'leave_score'=>0,
];
$explanation = hr_evaluation_explanation($evaluation);

$lateDays = count(array_filter($details, fn($row) => (int)$row['late_minutes'] > 0));
$earlyDays = count(array_filter($details, fn($row) => (int)$row['early_leave_minutes'] > 0));
$overtimeDays = count(array_filter($details, fn($row) => (int)$row['overtime_minutes'] > 0));
$absentDays = count(array_filter($details, fn($row) => in_array(strtolower((string)$row['status']), ['absent', 'alpha', 'alpa', 'tidak hadir'], true)));
$halfDays = count(array_filter($details, fn($row) => !empty($row['half_day_type'])));
$earlyArrivalDays = count(array_filter($details, fn($row) => !empty($row['early_arrival_type'])));
$incompleteScans = count(array_filter($details, fn($row) => empty($row['check_in']) xor empty($row['check_out'])));
?>

<div class="employee-detail-head mb-3">
    <h5 class="mb-1"><?= h($employee['full_name']); ?></h5>
    <div class="text-muted small">
        <?= h($employee['employee_code'] ?: '-'); ?> · <?= h($employee['position'] ?: '-'); ?> · <?= h($employee['department'] ?: '-'); ?>
    </div>
    <div class="text-muted small mt-1">Periode <?= h(date('d/m/Y', strtotime($periodStart))); ?>–<?= h(date('d/m/Y', strtotime($periodEnd))); ?></div>
</div>

<div class="card border-0 bg-light rounded-4 mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div><div class="text-muted small">Hasil Evaluasi</div><div class="d-flex align-items-center gap-2 mt-1"><?= hr_badge_eval($evaluation['evaluation_class'], $evaluation['evaluation_note']); ?><span class="fs-4 fw-bold"><?= h($evaluation['score']); ?>/100</span></div></div>
            <div class="text-muted small" style="max-width:680px"><?= h($explanation['summary']); ?></div>
        </div>
        <div class="row g-2 mb-3">
            <?php $components = [
                ['Kehadiran', $evaluation['attendance_rate'], 35],
                ['Disiplin', $evaluation['discipline_rate'], 25],
                ['Jam Kerja', min(100, hr_to_float($evaluation['work_rate'])), 25],
                ['Lembur', $evaluation['overtime_score'], 10],
                ['Cuti/Izin', $evaluation['leave_score'], 5],
            ]; ?>
            <?php foreach ($components as [$label, $value, $weight]): ?>
                <div class="col-6 col-lg"><div class="detail-summary"><span><?= h($label); ?> · <?= $weight; ?>%</span><strong><?= hr_number($value, 1); ?></strong><small class="text-muted">Kontribusi <?= hr_number(($value * $weight) / 100, 1); ?> poin</small></div></div>
            <?php endforeach; ?>
        </div>
        <div class="row g-3">
            <div class="col-lg-4"><div class="fw-semibold text-success mb-1"><i class="bi bi-check-circle me-1"></i>Hal Positif</div><ul class="small mb-0 ps-3"><?php foreach ($explanation['positives'] as $item): ?><li><?= h($item); ?></li><?php endforeach; ?></ul></div>
            <div class="col-lg-4"><div class="fw-semibold text-warning mb-1"><i class="bi bi-exclamation-circle me-1"></i>Dasar/Catatan</div><ul class="small mb-0 ps-3"><?php foreach ($explanation['reasons'] as $item): ?><li><?= h($item); ?></li><?php endforeach; ?></ul></div>
            <div class="col-lg-4"><div class="fw-semibold text-primary mb-1"><i class="bi bi-arrow-right-circle me-1"></i>Saran Tindakan</div><ul class="small mb-0 ps-3"><?php foreach ($explanation['actions'] as $item): ?><li><?= h($item); ?></li><?php endforeach; ?></ul></div>
        </div>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Telat</span><strong><?= $lateDays; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Pulang awal</span><strong><?= $earlyDays; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Lembur</span><strong><?= $overtimeDays; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Setengah hari</span><strong><?= $halfDays; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Datang awal</span><strong><?= $earlyArrivalDays; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Alpha</span><strong><?= $absentDays; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Perlu diperiksa</span><strong><?= $incompleteScans; ?>x</strong></div></div>
</div>

<div class="table-responsive">
    <table class="table table-sm align-middle detail-table mb-0">
        <thead><tr><th>Tanggal</th><th>Jam</th><th>Kejadian</th><th>Durasi</th></tr></thead>
        <tbody>
        <?php foreach ($details as $row): ?>
            <?php
            $events = [];
            $durations = [];
            if (($row['half_day_type'] ?? '') === 'morning') {
                $events[] = '<span class="badge text-bg-info">Setengah hari pagi</span>';
                $durations[] = '0,5 hari';
            } elseif (($row['half_day_type'] ?? '') === 'afternoon') {
                $events[] = '<span class="badge text-bg-info">Setengah hari siang</span>';
                $durations[] = '0,5 hari';
            }
            if (!empty($row['early_arrival_type'])) {
                $events[] = '<span class="badge text-bg-success">Datang lebih awal</span>';
                $durations[] = 'Sangat bagus';
            }
            if ((int)$row['late_minutes'] > 0) {
                $events[] = '<span class="badge text-bg-warning">Terlambat</span>';
                $durations[] = hr_minutes_to_text($row['late_minutes']);
            }
            if ((int)$row['early_leave_minutes'] > 0) {
                $events[] = '<span class="badge text-bg-danger">Pulang awal</span>';
                $durations[] = hr_minutes_to_text($row['early_leave_minutes']);
            }
            if ((int)$row['overtime_minutes'] > 0) {
                $events[] = '<span class="badge text-bg-primary">Lembur</span>';
                $durations[] = hr_minutes_to_text($row['overtime_minutes']);
            }
            if (in_array(strtolower((string)$row['status']), ['absent', 'alpha', 'alpa', 'tidak hadir'], true)) {
                $events[] = '<span class="badge text-bg-secondary">Alpha</span>';
                $durations[] = 'Tidak hadir';
            }
            if (empty($row['check_in']) xor empty($row['check_out'])) {
                $events[] = '<span class="badge text-bg-danger">Scan tidak lengkap</span>';
                $durations[] = '0 hari · perlu koreksi';
            }
            if (!$events) continue;
            ?>
            <tr>
                <td class="text-nowrap"><?= h(date('d/m/Y', strtotime($row['attendance_date']))); ?></td>
                <td class="text-nowrap"><?= h($row['check_in'] ? substr($row['check_in'], 0, 5) : '-'); ?>–<?= h($row['check_out'] ? substr($row['check_out'], 0, 5) : '-'); ?></td>
                <td><div class="d-flex flex-wrap gap-1"><?= implode('', $events); ?></div></td>
                <td><?= h(implode(' · ', $durations)); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$details): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Tidak ada setengah hari, keterlambatan, pulang awal, lembur, atau alpha pada periode ini.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="small text-muted mt-3">Semua hari memakai jadwal yang sama. Setengah hari pagi/siang ditentukan dari rentang jam absensi perusahaan.</div>
