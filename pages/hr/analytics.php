<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/analytics_helper.php';

$pageTitle = 'HR Analytics - Evaluasi Karyawan';

$data = hr_build_analytics($pdo, $_GET);
extract($data);

$statusCounts = [
    'success' => 0,
    'primary' => 0,
    'warning' => 0,
    'danger' => 0,
    'secondary' => 0,
];
$departmentScores = [];
foreach ($rows as $chartRow) {
    $class = $chartRow['evaluation_class'] ?? 'secondary';
    if (!isset($statusCounts[$class])) $statusCounts[$class] = 0;
    $statusCounts[$class]++;

    $dept = trim((string)($chartRow['department'] ?? 'Tanpa Departemen'));
    if ($dept === '') $dept = 'Tanpa Departemen';
    if (!isset($departmentScores[$dept])) {
        $departmentScores[$dept] = ['total' => 0, 'count' => 0];
    }
    $departmentScores[$dept]['total'] += (float)($chartRow['score'] ?? 0);
    $departmentScores[$dept]['count']++;
}

$departmentChartLabels = [];
$departmentChartValues = [];
foreach ($departmentScores as $dept => $value) {
    $departmentChartLabels[] = $dept;
    $departmentChartValues[] = $value['count'] > 0 ? round($value['total'] / $value['count'], 1) : 0;
}

$topScoreChartLabels = [];
$topScoreChartValues = [];
foreach (array_slice($topScore, 0, 8) as $chartRow) {
    $topScoreChartLabels[] = $chartRow['full_name'] ?? '-';
    $topScoreChartValues[] = (float)($chartRow['score'] ?? 0);
}

$attendanceChartLabels = ['Hadir', 'Alpha', 'Cuti/Izin Approved', 'Terlambat', 'Pulang Awal'];
$attendanceChartValues = [
    (float)($summary['present_days'] ?? 0),
    (int)($summary['absent_days'] ?? 0),
    (int)($summary['approved_leave_days'] ?? 0),
    (int)($summary['late_days'] ?? 0),
    (int)($summary['early_leave_days'] ?? 0),
];

$chartData = [
    'statusLabels' => ['Sangat Baik', 'Baik', 'Perlu Perhatian', 'Perlu Evaluasi', 'Belum Ada Data'],
    'statusValues' => array_values($statusCounts),
    'attendanceLabels' => $attendanceChartLabels,
    'attendanceValues' => $attendanceChartValues,
    'departmentLabels' => $departmentChartLabels,
    'departmentValues' => $departmentChartValues,
    'topScoreLabels' => $topScoreChartLabels,
    'topScoreValues' => $topScoreChartValues,
];

$printQuery = http_build_query($_GET);
$printQuery = $printQuery !== '' ? '?' . $printQuery : '';

$exportQuery = $_GET;
$exportQuery['format'] = 'csv';
$exportUrl = base_url('pages/hr/analytics_export.php?' . http_build_query($exportQuery));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
    .main-content { transition: margin-left .25s ease, width .25s ease; }
    .analytics-hero {
        border-radius: 18px;
        background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
        box-shadow: 0 18px 45px rgba(15, 23, 42, .07);
        padding: 24px;
        border: 1px solid #dcfce7;
    }
    .period-badge {
        display: inline-flex; align-items: center; gap: 8px;
        background: #dcfce7; color: #047857; border-radius: 999px;
        padding: 7px 12px; font-weight: 700; font-size: 13px;
    }
    .analytics-card, .stat-card {
        background: #fff; border-radius: 16px; border: 1px solid #e5e7eb;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
    }
    .analytics-card { padding: 20px; }
    .stat-card { padding: 17px; height: 100%; border-left: 4px solid #15803d; }
    .stat-label { color: #64748b; font-weight: 700; font-size: 13px; margin-bottom: 7px; }
    .stat-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; }
    .stat-sub { color: #64748b; font-size: 12px; margin-top: 6px; }
    .filter-box { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 13px; padding: 16px; }

    .chart-card {
        background: #fff; border-radius: 16px; border: 1px solid #e5e7eb;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .05); padding: 18px; height: 100%;
    }
    .chart-title { font-weight: 900; color: #111827; margin-bottom: 4px; }
    .chart-subtitle { color: #64748b; font-size: 12px; margin-bottom: 16px; }
    .chart-wrap { position: relative; min-height: 250px; }
    .chart-wrap.chart-small { min-height: 220px; }
    .stat-card.stat-success { border-left-color: #16a34a; background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%); }
    .stat-card.stat-primary { border-left-color: #2563eb; background: linear-gradient(135deg, #ffffff 0%, #eff6ff 100%); }
    .stat-card.stat-warning { border-left-color: #f59e0b; background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%); }
    .stat-card.stat-danger { border-left-color: #dc2626; background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%); }
    .stat-card.stat-purple { border-left-color: #7c3aed; background: linear-gradient(135deg, #ffffff 0%, #f5f3ff 100%); }
    .stat-card.stat-slate { border-left-color: #475569; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
    .btn-nukari { background: #15803d; border-color: #15803d; color: #fff; }
    .btn-nukari:hover { background: #0f5132; border-color: #0f5132; color: #fff; }
    .ranking-list { display: flex; flex-direction: column; gap: 12px; }
    .ranking-item {
        display: flex; justify-content: space-between; align-items: center; gap: 14px;
        padding: 11px 13px; border-radius: 12px; border: 1px solid #e5e7eb; background: #f8fafc;
    }
    .ranking-name { font-weight: 700; color: #111827; }
    .ranking-meta, .employee-meta { font-size: 12px; color: #64748b; }
    .score-pill {
        display: inline-flex; align-items: center; gap: 6px; border-radius: 999px;
        padding: 7px 11px; background: #eff6ff; color: #1d4ed8; font-weight: 900;
        min-width: 64px; justify-content: center;
    }
    .rank-badge {
        width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
        border-radius: 50%; background: #ecfdf5; color: #047857; font-weight: 900; margin-right: 8px;
    }
    .mini-bar { height: 8px; border-radius: 999px; background: #e5e7eb; overflow: hidden; min-width: 80px; }
    .mini-bar span { display: block; height: 100%; border-radius: 999px; background: #15803d; }
    .table thead th { background: #f1f5f9; color: #334155; font-size: 12px; white-space: nowrap; }
    .table td { vertical-align: middle; }
    .insight-box { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 13px; padding: 15px; }

    .colorful-section {
        background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 42%, #eff6ff 100%);
        border: 1px solid #dbeafe;
    }
    .analytics-table-card {
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-top: 5px solid #15803d;
    }
    .table tbody tr:nth-child(odd) { background: #ffffff; }
    .table tbody tr:nth-child(even) { background: #f8fafc; }
    .table tbody tr:hover { background: #ecfdf5; }
    .status-legend { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
    .legend-pill { display:inline-flex; align-items:center; gap:7px; padding:7px 10px; border-radius:999px; font-size:12px; font-weight:800; background:#fff; border:1px solid #e5e7eb; }
    .legend-dot { width:10px; height:10px; border-radius:999px; display:inline-block; }
    .detail-summary { background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:11px 12px; display:flex; justify-content:space-between; gap:10px; }
    .detail-summary span { color:#64748b; font-size:12px; }
    .detail-summary strong { color:#0f172a; }
    .detail-table .badge { font-weight:600; }
    .employee-detail-head h5 { font-weight:750; color:#0f172a; }

    @media print {
        .sidebar, .topbar, .btn, .filter-print-hidden { display: none !important; }
        body { background: #fff !important; }
        main { margin: 0 !important; padding: 0 !important; }
        .analytics-card, .analytics-hero, .stat-card { box-shadow: none !important; break-inside: avoid; }
    }
</style>

<main class="main-content content-wrapper">
    <div class="analytics-hero mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="period-badge mb-3">
                    <i class="bi bi-bar-chart-line"></i>
                    <?= h($periodLabel); ?> · <?= h($periodText); ?>
                </div>
                <h1 class="fw-bold mb-2">HR Analytics</h1>
                <p class="text-muted mb-0">
                    Evaluasi karyawan berdasarkan kehadiran efektif, disiplin, jam kerja, cuti approved, dan lembur.
                    Data ini untuk bahan meeting dan pengambilan keputusan, bukan untuk potongan payroll otomatis.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2 filter-print-hidden">
                <a href="<?= h($exportUrl); ?>" class="btn btn-outline-primary">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel
                </a>
                <a href="<?= base_url('pages/hr/analytics_print.php' . $printQuery); ?>" class="btn btn-success" target="_blank">
                    <i class="bi bi-printer me-1"></i> Print
                </a>
            </div>
        </div>
    </div>

    <div class="analytics-card mb-4 filter-print-hidden">
        <form method="GET" class="filter-box">
            <div class="row g-3 align-items-end">
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold">Jenis Evaluasi</label>
                    <select name="type" id="periodType" class="form-select">
                        <option value="monthly" <?= $periodType === 'monthly' ? 'selected' : ''; ?>>Bulanan</option>
                        <option value="yearly" <?= $periodType === 'yearly' ? 'selected' : ''; ?>>Tahunan</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6 period-field period-monthly">
                    <label class="form-label fw-semibold">Bulan</label>
                    <input type="month" name="month" value="<?= h($_GET['month'] ?? date('Y-m')); ?>" class="form-control">
                </div>
                <div class="col-lg-2 col-md-6 period-field period-yearly">
                    <label class="form-label fw-semibold">Tahun</label>
                    <input type="number" name="year" min="2020" max="2100" value="<?= h($_GET['year'] ?? date('Y')); ?>" class="form-control">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold">Departemen</label>
                    <select name="department" class="form-select">
                        <option value="">Semua</option>
                        <?php foreach ($departmentOptions as $department): ?>
                            <option value="<?= h($department); ?>" <?= $departmentFilter === $department ? 'selected' : ''; ?>><?= h($department); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="evaluation" class="form-select">
                        <option value="">Semua</option>
                        <option value="success" <?= $statusFilter === 'success' ? 'selected' : ''; ?>>Sangat baik</option>
                        <option value="primary" <?= $statusFilter === 'primary' ? 'selected' : ''; ?>>Baik</option>
                        <option value="warning" <?= $statusFilter === 'warning' ? 'selected' : ''; ?>>Perlu perhatian</option>
                        <option value="danger" <?= $statusFilter === 'danger' ? 'selected' : ''; ?>>Perlu evaluasi</option>
                        <option value="secondary" <?= $statusFilter === 'secondary' ? 'selected' : ''; ?>>Belum ada data</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label fw-semibold">Cari Karyawan</label>
                    <input type="text" name="keyword" value="<?= h($keyword); ?>" class="form-control" placeholder="Nama, kode, jabatan, departemen">
                </div>
                <div class="col-lg-3 col-md-12 d-flex gap-2">
                    <button type="submit" class="btn btn-nukari flex-fill"><i class="bi bi-search me-1"></i> Terapkan</button>
                    <a href="<?= base_url('pages/hr/analytics.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a href="<?= base_url('pages/hr/analytics.php?type=monthly&month=' . date('Y-m')); ?>" class="btn btn-sm btn-outline-success">Bulan Ini</a>
                <a href="<?= base_url('pages/hr/analytics.php?type=yearly&year=' . date('Y')); ?>" class="btn btn-sm btn-outline-success">Tahun Ini</a>
            </div>
        </form>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?= h($errorMessage); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6"><div class="stat-card stat-primary"><div class="stat-label">Karyawan</div><div class="stat-value"><?= hr_to_int($summary['employees']); ?></div><div class="stat-sub">Masuk evaluasi</div></div></div>
        <div class="col-xl-2 col-md-4 col-sm-6"><div class="stat-card stat-success"><div class="stat-label">Rata-rata Score</div><div class="stat-value"><?= h($summary['average_score']); ?></div><div class="stat-sub">Skala 0–100</div></div></div>
        <div class="col-xl-2 col-md-4 col-sm-6"><div class="stat-card stat-purple"><div class="stat-label">Attendance Rate</div><div class="stat-value"><?= h($summary['average_attendance_rate']); ?>%</div><div class="stat-sub">Hari kalender efektif</div></div></div>
        <div class="col-xl-2 col-md-4 col-sm-6"><div class="stat-card stat-slate"><div class="stat-label">Jam Kerja</div><div class="stat-value"><?= hr_decimal_hours($summary['work_minutes']); ?></div><div class="stat-sub">Total jam</div></div></div>
        <div class="col-xl-2 col-md-4 col-sm-6"><div class="stat-card stat-warning"><div class="stat-label">Lembur</div><div class="stat-value"><?= hr_decimal_hours($summary['overtime_minutes']); ?></div><div class="stat-sub">Total jam</div></div></div>
        <div class="col-xl-2 col-md-4 col-sm-6"><div class="stat-card stat-danger"><div class="stat-label">Perlu Perhatian</div><div class="stat-value"><?= hr_to_int($summary['need_attention']); ?></div><div class="stat-sub">Warning/evaluasi</div></div></div>
    </div>


    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-lg-6">
            <div class="chart-card">
                <div class="chart-title"><i class="bi bi-pie-chart text-success me-2"></i>Komposisi Status Evaluasi</div>
                <div class="chart-subtitle">Perbandingan status karyawan pada periode ini.</div>
                <div class="chart-wrap chart-small"><canvas id="statusChart"></canvas></div>
            </div>
        </div>
        <div class="col-xl-4 col-lg-6">
            <div class="chart-card">
                <div class="chart-title"><i class="bi bi-calendar2-check text-primary me-2"></i>Ringkasan Absensi</div>
                <div class="chart-subtitle">Hadir, alpha, izin/cuti, terlambat, dan pulang awal.</div>
                <div class="chart-wrap chart-small"><canvas id="attendanceChart"></canvas></div>
            </div>
        </div>
        <div class="col-xl-4 col-lg-12">
            <div class="chart-card">
                <div class="chart-title"><i class="bi bi-award text-warning me-2"></i>Top Score Karyawan</div>
                <div class="chart-subtitle">Karyawan dengan score tertinggi berdasarkan filter aktif.</div>
                <div class="chart-wrap chart-small"><canvas id="topScoreChart"></canvas></div>
            </div>
        </div>
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-title"><i class="bi bi-building text-success me-2"></i>Rata-rata Score per Departemen</div>
                <div class="chart-subtitle">Membantu melihat departemen mana yang perlu pendampingan atau evaluasi.</div>
                <div class="chart-wrap"><canvas id="departmentChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="analytics-card mb-4 colorful-section">
        <div class="row g-3 align-items-center">
            <div class="col-lg-8">
                <h5 class="fw-bold mb-2"><i class="bi bi-info-circle text-success me-2"></i>Formula Penilaian</h5>
                <div class="text-muted small">
                    Score dihitung dari: kehadiran efektif 35%, disiplin 25%, jam kerja efektif 25%, kontribusi lembur 10%, dan cuti/izin approved 5%.
                    Periode mengikuti setting payroll. Semua hari dihitung 8 jam. Cuti/izin/sakit/dinas approved tidak langsung menurunkan attendance rate.
                    Keterlambatan dan pulang awal memakai nilai baku yang tersimpan pada absensi, tanpa toleransi tambahan di Analytics.
                    Setengah hari pagi: masuk 07.00–08.30 dan pulang 11.30–13.59. Setengah hari siang: masuk 11.30–13.30 dan pulang mulai 16.30.
                    Hari penuh pada semua hari: masuk 08.00–08.30 dan pulang 16.30–17.30.
                    Karyawan tanpa alpha dengan durasi lembur melebihi total waktu telat dan pulang awal mendapat apresiasi Sangat Baik sebagai loyal dan berdedikasi.
                    Status Baik diberikan saat frekuensi lembur melampaui masing-masing kejadian telat/pulang awal, atau durasi lembur menutup minimal 75% waktu kedisiplinan.
                </div>
            </div>
            <div class="col-lg-4">
                <div class="insight-box small">
                    <strong>Jadwal kerja aktif:</strong> 8 jam/hari<br>
                    <span class="text-muted">Aturan yang sama berlaku Senin–Minggu.</span>
                </div>
            </div>
            <div class="col-12">
                <div class="status-legend">
                    <span class="legend-pill"><span class="legend-dot" style="background:#16a34a"></span>Sangat Baik</span>
                    <span class="legend-pill"><span class="legend-dot" style="background:#2563eb"></span>Baik</span>
                    <span class="legend-pill"><span class="legend-dot" style="background:#f59e0b"></span>Perlu Perhatian</span>
                    <span class="legend-pill"><span class="legend-dot" style="background:#dc2626"></span>Perlu Evaluasi</span>
                    <span class="legend-pill"><span class="legend-dot" style="background:#64748b"></span>Belum Ada Data</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="analytics-card h-100">
                <h5 class="fw-bold mb-3"><i class="bi bi-trophy text-warning me-2"></i>Top Score</h5>
                <div class="ranking-list">
                    <?php foreach ($topScore as $row): ?>
                        <div class="ranking-item">
                            <div>
                                <div class="ranking-name"><span class="rank-badge"><?= hr_to_int($row['rank']); ?></span><?= h($row['full_name']); ?></div>
                                <div class="ranking-meta"><?= h($row['employee_code'] ?? '-'); ?> · <?= h($row['position'] ?? '-'); ?></div>
                            </div>
                            <span class="score-pill"><?= h($row['score']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($topScore)): ?><div class="text-muted">Belum ada data.</div><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="analytics-card h-100">
                <h5 class="fw-bold mb-3"><i class="bi bi-clipboard2-pulse text-primary me-2"></i>Prioritas Evaluasi</h5>
                <div class="ranking-list">
                    <?php foreach ($needReview as $row): ?>
                        <div class="ranking-item">
                            <div>
                                <div class="ranking-name"><?= h($row['full_name']); ?></div>
                                <div class="ranking-meta"><?= h($row['meeting_note']); ?></div>
                            </div>
                            <?= hr_badge_eval($row['evaluation_class'], $row['evaluation_note']); ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($needReview)): ?><div class="text-muted">Tidak ada prioritas evaluasi.</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="analytics-card mb-4 border border-warning-subtle" style="background:linear-gradient(135deg,#fffbeb,#ffffff)">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div><h5 class="fw-bold mb-1"><i class="bi bi-award-fill text-warning me-2"></i>Rekomendasi Kandidat Reward</h5><div class="text-muted small">Score minimal 90, tidak alpha, tidak pulang awal, dan memiliki data absensi pada periode ini.</div></div>
            <div class="d-flex flex-wrap gap-2"><?php foreach ($rewardCandidates as $row): ?><span class="badge rounded-pill text-bg-warning px-3 py-2"><?= h($row['full_name']); ?> · <?= h($row['score']); ?></span><?php endforeach; ?><?php if (empty($rewardCandidates)): ?><span class="text-muted small">Belum ada kandidat yang memenuhi seluruh syarat.</span><?php endif; ?></div>
        </div>
    </div>

    <h5 class="fw-bold mb-3"><i class="bi bi-stars text-success me-2"></i>Pencapaian Positif</h5>
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-success">Paling Sering Datang Awal</h6><div class="ranking-list"><?php foreach ($mostEarlyArrival as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_to_int($row['early_arrival_days']); ?>x</strong></div><?php endforeach; ?><?php if (!$mostEarlyArrival): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-success">Tidak/Paling Jarang Pulang Awal</h6><div class="ranking-list"><?php foreach ($bestNoEarlyLeave as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_to_int($row['early_leave_days']); ?>x</strong></div><?php endforeach; ?><?php if (!$bestNoEarlyLeave): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-success">Libur/Cuti Paling Sedikit</h6><div class="ranking-list"><?php foreach ($leastLeave as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_to_int($row['approved_leave_days']); ?> hari</strong></div><?php endforeach; ?><?php if (!$leastLeave): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-success">Lembur Terbanyak</h6><div class="ranking-list"><?php foreach ($mostOvertime as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_decimal_hours($row['total_overtime_minutes']); ?> jam</strong></div><?php endforeach; ?><?php if (!$mostOvertime): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
    </div>

    <h5 class="fw-bold mb-1"><i class="bi bi-arrow-left-right text-warning me-2"></i>Informasi Pembanding</h5>
    <div class="text-muted small mb-3">Data kebalikan untuk konteks admin, bukan penalti otomatis. Cuti approved dan sedikit lembur tidak selalu berarti kinerja buruk.</div>
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-danger">Paling Jarang Datang Awal</h6><div class="ranking-list"><?php foreach ($leastEarlyArrival as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_to_int($row['early_arrival_days']); ?>x</strong></div><?php endforeach; ?><?php if (!$leastEarlyArrival): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-danger">Pulang Awal Terbanyak</h6><div class="ranking-list"><?php foreach ($mostEarlyLeave as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_to_int($row['early_leave_days']); ?>x</strong></div><?php endforeach; ?><?php if (!$mostEarlyLeave): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-danger">Libur/Cuti Terbanyak</h6><div class="ranking-list"><?php foreach ($mostLeave as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_to_int($row['approved_leave_days']); ?> hari</strong></div><?php endforeach; ?><?php if (!$mostLeave): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="analytics-card h-100"><h6 class="fw-bold text-danger">Lembur Paling Sedikit</h6><div class="ranking-list"><?php foreach ($leastOvertime as $row): ?><div class="ranking-item"><div class="ranking-name"><?= h($row['full_name']); ?></div><strong><?= hr_decimal_hours($row['total_overtime_minutes']); ?> jam</strong></div><?php endforeach; ?><?php if (!$leastOvertime): ?><div class="text-muted">Belum ada data.</div><?php endif; ?></div></div></div>
    </div>

    <div class="analytics-card analytics-table-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h4 class="fw-bold mb-1">Tabel Evaluasi Karyawan</h4>
                <div class="text-muted">Periode <?= h($periodText); ?> · <?= h($summary['working_days']); ?> hari kalender · <?= h($summary['employees']); ?> karyawan</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Rank</th><th>Karyawan</th><th>Dept.</th><th class="text-center">Hadir</th><th class="text-center">Cuti/Izin</th><th class="text-center">Alpha</th><th class="text-center">Telat</th><th class="text-center">Pulang Awal</th><th class="text-end">Jam Kerja</th><th class="text-end">Lembur</th><th class="text-center">Attendance</th><th class="text-center">Score</th><th>Status</th><th>Catatan Meeting</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><span class="rank-badge"><?= hr_to_int($row['rank']); ?></span></td>
                            <td><strong><?= h($row['full_name']); ?></strong><div class="employee-meta"><?= h($row['employee_code'] ?? '-'); ?> · <?= h($row['position'] ?? '-'); ?></div></td>
                            <td><?= h($row['department'] ?? '-'); ?></td>
                            <td class="text-center"><?= hr_day_number($row['present_days']); ?>/<?= hr_day_number($row['effective_work_days']); ?><?php if (hr_to_int($row['half_day_count'] ?? 0) > 0): ?><div class="employee-meta">Setengah hari: <?= hr_to_int($row['half_day_count']); ?>x</div><?php endif; ?></td>
                            <td class="text-center"><?= hr_to_int($row['approved_leave_days']); ?><div class="employee-meta">Unpaid: <?= hr_to_int($row['unpaid_leave_days']); ?></div></td>
                            <td class="text-center"><?= hr_to_int($row['absent_days']); ?></td>
                            <td class="text-center"><?= hr_to_int($row['late_days']); ?>x<div class="employee-meta"><?= hr_minutes_to_text($row['late_minutes']); ?></div></td>
                            <td class="text-center"><?= hr_to_int($row['early_leave_days']); ?>x<div class="employee-meta"><?= hr_minutes_to_text($row['early_leave_minutes']); ?></div></td>
                            <td class="text-end"><?= hr_decimal_hours($row['total_work_minutes']); ?> jam<div class="employee-meta">Target <?= hr_decimal_hours($row['standard_work_minutes']); ?> jam</div></td>
                            <td class="text-end"><?= hr_decimal_hours($row['total_overtime_minutes']); ?> jam</td>
                            <td class="text-center"><strong><?= h($row['attendance_rate']); ?>%</strong><div class="mini-bar mx-auto mt-1"><span style="width: <?= h($row['attendance_rate']); ?>%"></span></div></td>
                            <td class="text-center"><span class="score-pill"><i class="bi bi-speedometer2"></i><?= h($row['score']); ?></span></td>
                            <td><?= hr_badge_eval($row['evaluation_class'], $row['evaluation_note']); ?></td>
                            <td class="small text-muted"><?= h($row['meeting_note']); ?></td>
                            <td><button type="button" class="btn btn-sm btn-outline-primary detail-employee-btn" data-employee-id="<?= hr_to_int($row['employee_id']); ?>" data-employee-name="<?= h($row['full_name']); ?>"><i class="bi bi-eye me-1"></i>Detail</button></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="15" class="text-center text-muted py-4">Belum ada data karyawan atau absensi pada periode ini.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-light border mt-3 mb-0 small text-muted">
            <strong>Catatan:</strong> HR Analytics adalah alat evaluasi internal. Untuk payroll, tetap gunakan modul payroll agar kebijakan gaji, lembur, dan izin tidak tercampur dengan score evaluasi.
        </div>
    </div>

    <div class="modal fade" id="employeeDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title">Detail Evaluasi Karyawan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body" id="employeeDetailContent">
                    <div class="text-center text-muted py-5">Memuat detail...</div>
                </div>
            </div>
        </div>
    </div>
</main>


<script src="<?= base_url('assets/vendor/chartjs/chart.umd.js'); ?>"></script>
<script>
(function() {
    const chartData = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
    if (typeof Chart === 'undefined') return;

    const colors = {
        green: '#16a34a',
        blue: '#2563eb',
        yellow: '#f59e0b',
        red: '#dc2626',
        slate: '#64748b',
        purple: '#7c3aed',
        emeraldSoft: 'rgba(22, 163, 74, 0.14)',
        blueSoft: 'rgba(37, 99, 235, 0.14)',
        yellowSoft: 'rgba(245, 158, 11, 0.18)',
        redSoft: 'rgba(220, 38, 38, 0.14)',
        slateSoft: 'rgba(100, 116, 139, 0.14)',
        purpleSoft: 'rgba(124, 58, 237, 0.14)'
    };

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { usePointStyle: true, boxWidth: 9, font: { weight: '600' } } },
            tooltip: { backgroundColor: '#0f172a', padding: 12, cornerRadius: 12 }
        }
    };

    function hasValue(values) {
        return Array.isArray(values) && values.some(v => Number(v) > 0);
    }

    const statusCanvas = document.getElementById('statusChart');
    if (statusCanvas && hasValue(chartData.statusValues)) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: chartData.statusLabels,
                datasets: [{
                    data: chartData.statusValues,
                    backgroundColor: [colors.green, colors.blue, colors.yellow, colors.red, colors.slate],
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 6
                }]
            },
            options: { ...commonOptions, cutout: '64%' }
        });
    }

    const attendanceCanvas = document.getElementById('attendanceChart');
    if (attendanceCanvas && hasValue(chartData.attendanceValues)) {
        new Chart(attendanceCanvas, {
            type: 'bar',
            data: {
                labels: chartData.attendanceLabels,
                datasets: [{
                    label: 'Jumlah',
                    data: chartData.attendanceValues,
                    backgroundColor: [colors.green, colors.red, colors.blue, colors.yellow, colors.purple],
                    borderRadius: 12,
                    borderSkipped: false
                }]
            },
            options: {
                ...commonOptions,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(148, 163, 184, .18)' } },
                    x: { grid: { display: false } }
                },
                plugins: { ...commonOptions.plugins, legend: { display: false } }
            }
        });
    }

    const topScoreCanvas = document.getElementById('topScoreChart');
    if (topScoreCanvas && hasValue(chartData.topScoreValues)) {
        new Chart(topScoreCanvas, {
            type: 'bar',
            data: {
                labels: chartData.topScoreLabels,
                datasets: [{
                    label: 'Score',
                    data: chartData.topScoreValues,
                    backgroundColor: colors.yellow,
                    borderRadius: 12,
                    borderSkipped: false
                }]
            },
            options: {
                ...commonOptions,
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, max: 100, grid: { color: 'rgba(148, 163, 184, .18)' } },
                    y: { grid: { display: false } }
                },
                plugins: { ...commonOptions.plugins, legend: { display: false } }
            }
        });
    }

    const departmentCanvas = document.getElementById('departmentChart');
    if (departmentCanvas && hasValue(chartData.departmentValues)) {
        new Chart(departmentCanvas, {
            type: 'line',
            data: {
                labels: chartData.departmentLabels,
                datasets: [{
                    label: 'Rata-rata Score',
                    data: chartData.departmentValues,
                    borderColor: colors.green,
                    backgroundColor: colors.emeraldSoft,
                    pointBackgroundColor: colors.green,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 3,
                    pointRadius: 6,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                ...commonOptions,
                scales: {
                    y: { beginAtZero: true, max: 100, grid: { color: 'rgba(148, 163, 184, .18)' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
})();
</script>

<script>
(function() {
    const periodType = document.getElementById('periodType');
    const fields = document.querySelectorAll('.period-field');
    function togglePeriodFields() {
        if (!periodType) return;
        const type = periodType.value;
        fields.forEach(field => field.style.display = 'none');
        document.querySelectorAll('.period-' + type).forEach(field => field.style.display = '');
    }
    if (periodType) {
        periodType.addEventListener('change', togglePeriodFields);
        togglePeriodFields();
    }
})();
</script>

<script>
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        const modalElement = document.getElementById('employeeDetailModal');
        const content = document.getElementById('employeeDetailContent');
        if (!modalElement || !content || typeof bootstrap === 'undefined') return;
        const detailModal = new bootstrap.Modal(modalElement);

        document.querySelectorAll('.detail-employee-btn').forEach(button => {
            button.addEventListener('click', async function() {
                content.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Memuat detail...</div>';
                detailModal.show();
                const params = new URLSearchParams(window.location.search);
                params.set('employee_id', this.dataset.employeeId);
                try {
                    const response = await fetch('<?= base_url('pages/hr/analytics_detail.php'); ?>?' + params.toString(), { credentials: 'same-origin' });
                    if (!response.ok) throw new Error('Gagal memuat detail');
                    content.innerHTML = await response.text();
                } catch (error) {
                    content.innerHTML = '<div class="alert alert-danger mb-0">Detail karyawan belum dapat dimuat. Silakan coba kembali.</div>';
                }
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
