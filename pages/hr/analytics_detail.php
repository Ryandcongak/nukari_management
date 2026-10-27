<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations.view');
require_once __DIR__ . '/analytics_employee_helper.php';

$employeeId = (int)($_GET['employee_id'] ?? 0);
$payload = hr_build_employee_evaluation($pdo, $employeeId, $_GET);

if (!$payload) {
    http_response_code(404);
    echo '<div class="alert alert-warning mb-0">Data karyawan atau hasil evaluasi pada periode ini tidak ditemukan.</div>';
    exit;
}

$employee = $payload['employee'];
$evaluation = $payload['evaluation'];
$explanation = $payload['explanation'];
$details = $payload['details'];
$counts = $payload['counts'];
$periodStart = $payload['periodStart'];
$periodEnd = $payload['periodEnd'];

$pdfQuery = $payload['periodSource'];
$pdfQuery['employee_id'] = $employeeId;
$reviewQuery = $pdfQuery;
$reviewQuery['mode'] = 'inline';
$downloadQuery = $pdfQuery;
$downloadQuery['mode'] = 'download';
$reviewUrl = base_url('pages/hr/analytics_employee_pdf.php?' . http_build_query($reviewQuery));
$downloadUrl = base_url('pages/hr/analytics_employee_pdf.php?' . http_build_query($downloadQuery));
?>

<div class="employee-detail-head mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h5 class="mb-1"><?= h($employee['full_name']); ?></h5>
            <div class="text-muted small">
                <?= h($employee['employee_code'] ?: '-'); ?> · <?= h($employee['position'] ?: '-'); ?> · <?= h($employee['department'] ?: '-'); ?>
            </div>
            <div class="text-muted small mt-1">Periode <?= h(date('d/m/Y', strtotime($periodStart))); ?>–<?= h(date('d/m/Y', strtotime($periodEnd))); ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= h($reviewUrl); ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-file-earmark-pdf me-1"></i>Review PDF
            </a>
            <a href="<?= h($downloadUrl); ?>" class="btn btn-success btn-sm">
                <i class="bi bi-download me-1"></i>Download PDF
            </a>
        </div>
    </div>
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
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Telat</span><strong><?= $counts['late']; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Pulang awal</span><strong><?= $counts['early_leave']; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Lembur</span><strong><?= $counts['overtime']; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Setengah hari</span><strong><?= $counts['half_day']; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Datang awal</span><strong><?= $counts['early_arrival']; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Alpha</span><strong><?= $counts['absent']; ?>x</strong></div></div>
    <div class="col-6 col-md-3"><div class="detail-summary"><span>Perlu diperiksa</span><strong><?= $counts['incomplete_scan']; ?>x</strong></div></div>
</div>

<div class="table-responsive">
    <table class="table table-sm align-middle detail-table mb-0">
        <thead><tr><th>Tanggal</th><th>Jam</th><th>Kejadian</th><th>Durasi</th></tr></thead>
        <tbody>
        <?php foreach ($details as $row): ?>
            <?php
            $eventData = hr_employee_detail_events($row);
            if (!$eventData['events']) continue;
            $eventClasses = [
                'Setengah hari pagi' => 'info',
                'Setengah hari siang' => 'info',
                'Datang lebih awal' => 'success',
                'Terlambat' => 'warning',
                'Pulang awal' => 'danger',
                'Lembur' => 'primary',
                'Alpha' => 'secondary',
                'Scan tidak lengkap' => 'danger',
            ];
            $events = array_map(
                fn($event) => '<span class="badge text-bg-' . ($eventClasses[$event] ?? 'secondary') . '">' . h($event) . '</span>',
                $eventData['events']
            );
            $durations = $eventData['durations'];
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
