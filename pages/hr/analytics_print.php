<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations.view');
require_once __DIR__ . '/analytics_helper.php';

$pageTitle = 'Print HR Analytics';
$data = hr_build_analytics($pdo, $_GET);
extract($data);

function hr_print_status_class($class)
{
    $map = [
        'success' => 'status-success',
        'primary' => 'status-primary',
        'warning' => 'status-warning',
        'danger' => 'status-danger',
        'secondary' => 'status-secondary',
    ];
    return $map[$class] ?? 'status-secondary';
}

$statusCounts = ['success'=>0,'primary'=>0,'warning'=>0,'danger'=>0,'secondary'=>0];
foreach ($rows as $r) {
    $c = $r['evaluation_class'] ?? 'secondary';
    if (!isset($statusCounts[$c])) $statusCounts[$c] = 0;
    $statusCounts[$c]++;
}
$totalStatus = max(1, array_sum($statusCounts));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle); ?></title>
    <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <style>
        :root {
            --green:#15803d;
            --green-soft:#dcfce7;
            --blue:#2563eb;
            --blue-soft:#dbeafe;
            --yellow:#f59e0b;
            --yellow-soft:#fef3c7;
            --red:#dc2626;
            --red-soft:#fee2e2;
            --purple:#7c3aed;
            --purple-soft:#ede9fe;
            --slate:#475569;
            --slate-soft:#f1f5f9;
        }
        body { background:#eef2f7; color:#111827; font-size: 12px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .report-page { max-width: 1240px; margin: 24px auto; background:#fff; padding: 30px; border-radius: 24px; box-shadow: 0 24px 60px rgba(15,23,42,.10); overflow:hidden; }
        .report-title { background: linear-gradient(135deg, #14532d 0%, #15803d 48%, #2563eb 100%); color:#fff; border-radius: 22px; padding: 24px; margin-bottom: 20px; }
        .report-title h2 { letter-spacing:-.02em; }
        .small-muted { color:#64748b; font-size: 11px; }
        .small-light { color:rgba(255,255,255,.82); font-size:11px; }
        .stat { border-radius: 18px; padding: 15px; height: 100%; border:1px solid #e5e7eb; }
        .stat-label { font-weight: 800; font-size: 11px; opacity:.82; }
        .stat-value { font-weight: 900; font-size: 23px; line-height:1.05; margin-top:6px; }
        .stat-green { background:linear-gradient(135deg,#fff,#ecfdf5); border-left:5px solid var(--green); color:#14532d; }
        .stat-blue { background:linear-gradient(135deg,#fff,#eff6ff); border-left:5px solid var(--blue); color:#1e3a8a; }
        .stat-yellow { background:linear-gradient(135deg,#fff,#fffbeb); border-left:5px solid var(--yellow); color:#92400e; }
        .stat-red { background:linear-gradient(135deg,#fff,#fef2f2); border-left:5px solid var(--red); color:#991b1b; }
        .stat-purple { background:linear-gradient(135deg,#fff,#f5f3ff); border-left:5px solid var(--purple); color:#5b21b6; }
        .stat-slate { background:linear-gradient(135deg,#fff,#f8fafc); border-left:5px solid var(--slate); color:#334155; }
        .section-title { font-size: 15px; font-weight: 900; margin: 22px 0 10px; color:#14532d; }
        .section-card { border:1px solid #e5e7eb; border-radius:18px; padding:16px; background:linear-gradient(180deg,#fff,#f8fafc); }
        .table th { background:#14532d !important; color:#fff !important; font-size: 11px; white-space: nowrap; border-color:#bbf7d0 !important; }
        .table td { vertical-align: middle; }
        .table tbody tr:nth-child(even) td { background:#f8fafc !important; }
        .score { font-weight: 900; color:#1d4ed8; background:#eff6ff !important; border-radius:8px; }
        .badge-status { display:inline-block; padding:5px 9px; border-radius:999px; font-weight:800; font-size:10px; }
        .status-success { background:var(--green-soft); color:#166534; }
        .status-primary { background:var(--blue-soft); color:#1d4ed8; }
        .status-warning { background:var(--yellow-soft); color:#92400e; }
        .status-danger { background:var(--red-soft); color:#991b1b; }
        .status-secondary { background:#e5e7eb; color:#374151; }
        .print-actions { position: sticky; top:0; background:#eef2f7; padding: 12px 0; z-index: 5; }
        .mini-bar { height: 9px; border-radius:999px; background:#e5e7eb; overflow:hidden; min-width:85px; }
        .mini-fill { height:100%; border-radius:999px; display:block; background:linear-gradient(90deg,#16a34a,#2563eb); }
        .status-row { display:grid; grid-template-columns: 150px 1fr 42px; gap:10px; align-items:center; margin-bottom:9px; }
        .legend-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:7px; }
        .formula-box { background:linear-gradient(135deg,#ecfdf5,#eff6ff); border:1px solid #bfdbfe; border-radius:18px; padding:15px; color:#334155; }
        @media print {
            @page { size: landscape; margin: 8mm; }
            body { background:#fff; }
            .report-page { max-width:none; margin:0; padding:0; box-shadow:none; border-radius:0; }
            .print-actions { display:none !important; }
            .stat, .table, .section-card, .report-title { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="container print-actions text-end">
    <button onclick="window.print()" class="btn btn-success btn-sm">Print</button>
    <button onclick="window.close()" class="btn btn-outline-secondary btn-sm">Tutup</button>
</div>

<div class="report-page">
    <div class="report-title d-flex justify-content-between align-items-start gap-3">
        <div>
            <h2 class="fw-bold mb-1">HR Analytics Report</h2>
            <div class="small-light">Periode: <?= h($periodLabel); ?> · <?= h($periodText); ?></div>
            <div class="small-light">Dicetak: <?= h(date('d M Y H:i')); ?></div>
        </div>
        <div class="text-end small-light">
            <strong style="color:#fff;">PT Nukari Kriya Raya</strong><br>
            Internal HR Evaluation Report<br>
            Professional Performance Overview
        </div>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?= h($errorMessage); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col"><div class="stat stat-blue"><div class="stat-label">Karyawan</div><div class="stat-value"><?= hr_to_int($summary['employees']); ?></div></div></div>
        <div class="col"><div class="stat stat-green"><div class="stat-label">Avg Score</div><div class="stat-value"><?= h($summary['average_score']); ?></div></div></div>
        <div class="col"><div class="stat stat-purple"><div class="stat-label">Attendance</div><div class="stat-value"><?= h($summary['average_attendance_rate']); ?>%</div></div></div>
        <div class="col"><div class="stat stat-slate"><div class="stat-label">Jam Kerja</div><div class="stat-value"><?= hr_decimal_hours($summary['work_minutes']); ?></div></div></div>
        <div class="col"><div class="stat stat-yellow"><div class="stat-label">Lembur</div><div class="stat-value"><?= hr_decimal_hours($summary['overtime_minutes']); ?></div></div></div>
        <div class="col"><div class="stat stat-red"><div class="stat-label">Perlu Perhatian</div><div class="stat-value"><?= hr_to_int($summary['need_attention']); ?></div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-7">
            <div class="formula-box">
                <strong>Formula score:</strong> kehadiran efektif 35%, disiplin 25%, jam kerja efektif 25%, lembur 10%, dan cuti/izin approved 5%.
                Periode mengikuti setting payroll. Semua hari dihitung 8 jam. Cuti/izin/sakit/dinas approved tidak langsung menurunkan attendance rate.
                Keterlambatan dan pulang awal memakai nilai baku yang tersimpan pada absensi, tanpa toleransi tambahan di Analytics.
                Setengah hari pagi: masuk 07.00–08.30 dan pulang 11.30–13.59. Setengah hari siang: masuk 11.30–13.30 dan pulang mulai 16.30.
                Hari penuh pada semua hari: masuk 08.00–08.30 dan pulang 16.30–17.30.
                Karyawan tanpa alpha dengan durasi lembur melebihi total waktu telat dan pulang awal mendapat apresiasi Sangat Baik sebagai loyal dan berdedikasi.
                Status Baik diberikan saat frekuensi lembur melampaui masing-masing kejadian telat/pulang awal, atau durasi lembur menutup minimal 75% waktu kedisiplinan.
            </div>
        </div>
        <div class="col-md-5">
            <div class="section-card">
                <strong class="d-block mb-2">Komposisi Status Evaluasi</strong>
                <?php
                $statusMeta = [
                    'success' => ['Sangat Baik', '#16a34a'],
                    'primary' => ['Baik', '#2563eb'],
                    'warning' => ['Perlu Perhatian', '#f59e0b'],
                    'danger' => ['Perlu Evaluasi', '#dc2626'],
                    'secondary' => ['Belum Ada Data', '#64748b'],
                ];
                foreach ($statusMeta as $key => $meta):
                    $percent = round(($statusCounts[$key] ?? 0) / $totalStatus * 100, 1);
                ?>
                    <div class="status-row">
                        <div><span class="legend-dot" style="background:<?= h($meta[1]); ?>"></span><?= h($meta[0]); ?></div>
                        <div class="mini-bar"><span class="mini-fill" style="width:<?= h($percent); ?>%; background:<?= h($meta[1]); ?>"></span></div>
                        <div class="text-end fw-bold"><?= hr_to_int($statusCounts[$key] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="section-title">Top Score</div>
            <table class="table table-sm table-bordered">
                <thead><tr><th>Rank</th><th>Nama</th><th>Dept.</th><th class="text-end">Score</th></tr></thead>
                <tbody>
                <?php foreach ($topScore as $row): ?>
                    <tr><td class="fw-bold text-success"><?= hr_to_int($row['rank']); ?></td><td><?= h($row['full_name']); ?></td><td><?= h($row['department'] ?? '-'); ?></td><td class="text-end score"><?= h($row['score']); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($topScore)): ?><tr><td colspan="4" class="text-center text-muted">Belum ada data.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="col-md-6">
            <div class="section-title">Prioritas Evaluasi</div>
            <table class="table table-sm table-bordered">
                <thead><tr><th>Nama</th><th>Status</th><th>Catatan</th></tr></thead>
                <tbody>
                <?php foreach ($needReview as $row): ?>
                    <tr>
                        <td><?= h($row['full_name']); ?></td>
                        <td><span class="badge-status <?= h(hr_print_status_class($row['evaluation_class'] ?? 'secondary')); ?>"><?= h($row['evaluation_note']); ?></span></td>
                        <td><?= h($row['meeting_note']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($needReview)): ?><tr><td colspan="3" class="text-center text-muted">Tidak ada prioritas evaluasi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section-title">Ringkasan Perbandingan & Kandidat Reward</div>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><div class="section-card"><strong>Kandidat Reward</strong><div class="small-muted mb-2">Score ≥90, tanpa alpha dan tanpa pulang awal.</div><?php foreach ($rewardCandidates as $r): ?><div><?= h($r['full_name']); ?> — <strong><?= h($r['score']); ?></strong></div><?php endforeach; ?><?php if (!$rewardCandidates): ?><span class="small-muted">Belum ada kandidat.</span><?php endif; ?></div></div>
        <div class="col-md-6"><div class="section-card"><strong>Datang Awal Terbanyak</strong><?php foreach ($mostEarlyArrival as $r): ?><div><?= h($r['full_name']); ?> — <?= hr_to_int($r['early_arrival_days']); ?>x</div><?php endforeach; ?><?php if (!$mostEarlyArrival): ?><div class="small-muted">Belum ada data.</div><?php endif; ?></div></div>
        <div class="col-md-3"><div class="section-card"><strong>Tanpa/Jarang Pulang Awal</strong><?php foreach ($bestNoEarlyLeave as $r): ?><div><?= h($r['full_name']); ?> — <?= hr_to_int($r['early_leave_days']); ?>x</div><?php endforeach; ?></div></div>
        <div class="col-md-3"><div class="section-card"><strong>Cuti Paling Sedikit</strong><?php foreach ($leastLeave as $r): ?><div><?= h($r['full_name']); ?> — <?= hr_to_int($r['approved_leave_days']); ?> hari</div><?php endforeach; ?></div></div>
        <div class="col-md-3"><div class="section-card"><strong>Lembur Terbanyak</strong><?php foreach ($mostOvertime as $r): ?><div><?= h($r['full_name']); ?> — <?= hr_decimal_hours($r['total_overtime_minutes']); ?> jam</div><?php endforeach; ?></div></div>
        <div class="col-md-3"><div class="section-card"><strong>Perlu Dilihat</strong><div class="small-muted">Pulang awal, cuti, dan lembur rendah tetap perlu konteks admin.</div></div></div>
    </div>

    <div class="section-title">Detail Evaluasi Karyawan</div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Karyawan</th>
                    <th>Departemen</th>
                    <th class="text-center">Hadir</th>
                    <th class="text-center">Cuti/Izin</th>
                    <th class="text-center">Alpha</th>
                    <th class="text-center">Telat</th>
                    <th class="text-center">Pulang Awal</th>
                    <th class="text-end">Jam Kerja</th>
                    <th class="text-end">Lembur</th>
                    <th class="text-center">Attendance</th>
                    <th class="text-center">Score</th>
                    <th>Status</th>
                    <th>Catatan Meeting</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-bold text-success"><?= hr_to_int($row['rank']); ?></td>
                    <td><strong><?= h($row['full_name']); ?></strong><br><span class="small-muted"><?= h($row['employee_code'] ?? '-'); ?> · <?= h($row['position'] ?? '-'); ?></span></td>
                    <td><?= h($row['department'] ?? '-'); ?></td>
                    <td class="text-center"><?= hr_day_number($row['present_days']); ?>/<?= hr_day_number($row['effective_work_days']); ?><?php if (hr_to_int($row['half_day_count'] ?? 0) > 0): ?><br><span class="small-muted">Setengah: <?= hr_to_int($row['half_day_count']); ?>x</span><?php endif; ?></td>
                    <td class="text-center"><?= hr_to_int($row['approved_leave_days']); ?></td>
                    <td class="text-center"><?= hr_to_int($row['absent_days']); ?></td>
                    <td class="text-center"><?= hr_to_int($row['late_days']); ?>x<br><span class="small-muted"><?= hr_minutes_to_text($row['late_minutes']); ?></span></td>
                    <td class="text-center"><?= hr_to_int($row['early_leave_days']); ?>x<br><span class="small-muted"><?= hr_minutes_to_text($row['early_leave_minutes']); ?></span></td>
                    <td class="text-end"><?= hr_decimal_hours($row['total_work_minutes']); ?> jam</td>
                    <td class="text-end"><?= hr_decimal_hours($row['total_overtime_minutes']); ?> jam</td>
                    <td class="text-center">
                        <strong><?= h($row['attendance_rate']); ?>%</strong>
                        <div class="mini-bar mx-auto mt-1"><span class="mini-fill" style="width:<?= h($row['attendance_rate']); ?>%"></span></div>
                    </td>
                    <td class="text-center score"><?= h($row['score']); ?></td>
                    <td><span class="badge-status <?= h(hr_print_status_class($row['evaluation_class'] ?? 'secondary')); ?>"><?= h($row['evaluation_note']); ?></span></td>
                    <td><?= h($row['meeting_note']); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="14" class="text-center text-muted py-4">Belum ada data.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="small-muted mt-3">
        Catatan: Report ini merupakan alat evaluasi internal. Keputusan bonus, teguran, promosi, atau pembinaan tetap harus mempertimbangkan konteks kerja dan keputusan manajemen.
    </div>
</div>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 300); });</script>
</body>
</html>
