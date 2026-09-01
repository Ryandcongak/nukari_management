<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
$single = isset($_GET['single']) && (int) $_GET['single'] === 1;

if ($projectId <= 0 && $id > 0) {
    $findProject = $pdo->prepare("SELECT project_id FROM project_workers WHERE id = :id LIMIT 1");
    $findProject->execute([':id' => $id]);
    $projectId = (int) $findProject->fetchColumn();
}

if ($projectId <= 0) {
    redirect('pages/projects/index.php');
}

function ws_money($value)
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function ws_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ws_date($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    return date('d/m/Y', strtotime($date));
}

function ws_num($value, $decimals = 1)
{
    $value = (float) $value;
    if (floor($value) == $value) {
        return number_format($value, 0, ',', '.');
    }
    return number_format($value, $decimals, ',', '.');
}

function ws_label_worker_type($type)
{
    $labels = [
        'employee' => 'Karyawan Internal',
        'freelance' => 'Freelance / Manual',
        'borongan' => 'Borongan',
    ];
    return $labels[$type] ?? ucfirst((string) $type);
}

function ws_label_payment_type($type)
{
    $labels = [
        'daily' => 'Harian',
        'per_attendance' => 'Per Kehadiran',
        'monthly' => 'Bulanan',
        'borongan' => 'Borongan',
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', (string) $type));
}

$projectStmt = $pdo->prepare("\n    SELECT p.*, c.client_name\n    FROM projects p\n    LEFT JOIN clients c ON p.client_id = c.id\n    WHERE p.id = :project_id\n    LIMIT 1\n");
$projectStmt->execute([':project_id' => $projectId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

$whereSingle = '';
$params = [':project_id' => $projectId];
if ($single && $id > 0) {
    $whereSingle = ' AND pw.id = :id ';
    $params[':id'] = $id;
}

$stmt = $pdo->prepare("\n    SELECT pw.*,\n           p.project_name, p.project_code, p.project_location, p.start_date AS project_start, p.end_date AS project_end,\n           c.client_name,\n           e.full_name AS employee_full_name, e.position, e.department\n    FROM project_workers pw\n    LEFT JOIN projects p ON pw.project_id = p.id\n    LEFT JOIN clients c ON p.client_id = c.id\n    LEFT JOIN employees e ON pw.employee_id = e.id\n    WHERE pw.project_id = :project_id {$whereSingle}
      AND pw.worker_type <> 'employee'\n    ORDER BY\n        CASE pw.worker_type\n            WHEN 'employee' THEN 1\n            WHEN 'freelance' THEN 2\n            WHEN 'borongan' THEN 3\n            ELSE 4\n        END,\n        pw.worker_name ASC,\n        pw.id ASC\n");
$stmt->execute($params);
$workers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$attendanceMap = [];
foreach ($workers as $worker) {
    if (($worker['worker_type'] ?? '') === 'employee' && !empty($worker['employee_id']) && !empty($worker['start_date']) && !empty($worker['end_date'])) {
        $attStmt = $pdo->prepare("\n            SELECT\n                COUNT(*) AS attendance_days,\n                COALESCE(SUM(work_minutes), 0) AS total_work_minutes,\n                COALESCE(SUM(\n                    CASE\n                        WHEN overtime_in IS NOT NULL AND overtime_out IS NOT NULL AND overtime_out > overtime_in\n                        THEN TIMESTAMPDIFF(MINUTE, CONCAT(attendance_date, ' ', overtime_in), CONCAT(attendance_date, ' ', overtime_out))\n                        ELSE 0\n                    END\n                ), 0) AS overtime_minutes\n            FROM attendances\n            WHERE employee_id = :employee_id\n              AND attendance_date BETWEEN :start_date AND :end_date\n              AND status IN ('present','late')\n        ");
        $attStmt->execute([
            ':employee_id' => (int) $worker['employee_id'],
            ':start_date' => $worker['start_date'],
            ':end_date' => $worker['end_date'],
        ]);
        $attendanceMap[(int) $worker['id']] = $attStmt->fetch(PDO::FETCH_ASSOC);
    }
}

$backUrl = 'detail.php?id=' . (int) $projectId . '&tab=workers';
$singleModeUrl = $id > 0 ? 'worker_slip.php?id=' . (int) $id . '&single=1' : '';
$allModeUrl = 'worker_slip.php?project_id=' . (int) $projectId;
$totalWorkers = count($workers);
$totalAll = 0;
foreach ($workers as $w) {
    $totalAll += (float) ($w['total_amount'] ?? 0);
}

function render_worker_slip(array $worker, ?array $attendance = null)
{
    $workDays = (int) ($worker['work_days'] ?? 0);
    $rate = (float) ($worker['rate'] ?? 0);
    $baseAmount = (($worker['worker_type'] ?? '') === 'borongan') ? $rate : ($workDays * $rate);
    $overtimeHours = (float) ($worker['overtime_hours'] ?? 0);
    $overtimeRate = (float) ($worker['overtime_rate'] ?? 0);
    $overtimeAmount = (float) ($worker['overtime_amount'] ?? ($overtimeHours * $overtimeRate));
    $bonusAmount = (float) ($worker['bonus_amount'] ?? 0);
    $deductionAmount = (float) ($worker['deduction_amount'] ?? 0);
    $totalAmount = (float) ($worker['total_amount'] ?? ($baseAmount + $overtimeAmount + $bonusAmount - $deductionAmount));
    $periodText = ws_date($worker['start_date'] ?? null) . ' - ' . ws_date($worker['end_date'] ?? null);
    ?>
    <div class="slip-card">
        <div class="slip-header">
            <div>
                <div class="company-name">PT Nukari Kriya Raya</div>
                <div class="company-address">Bukti pembayaran pekerja freelance/borongan</div>
            </div>
            <div class="slip-title">
                <strong>Slip Pembayaran Project</strong>
                <span>No. PWS-<?= str_pad((string) $worker['id'], 5, '0', STR_PAD_LEFT); ?></span>
                <span>Cetak: <?= date('d/m/Y'); ?></span>
            </div>
        </div>

        <div class="grid-2">
            <div class="box">
                <h4>Data Project</h4>
                <div class="line-row"><span class="label">Project</span><span class="value"><?= ws_e($worker['project_name'] ?: '-'); ?></span></div>
                <div class="line-row"><span class="label">Kode</span><span class="value"><?= ws_e($worker['project_code'] ?: '-'); ?></span></div>
                <div class="line-row"><span class="label">Client</span><span class="value"><?= ws_e($worker['client_name'] ?: '-'); ?></span></div>
                <div class="line-row"><span class="label">Lokasi</span><span class="value"><?= ws_e($worker['project_location'] ?: '-'); ?></span></div>
            </div>
            <div class="box">
                <h4>Data Pekerja</h4>
                <div class="line-row"><span class="label">Nama</span><span class="value"><?= ws_e($worker['worker_name']); ?></span></div>
                <div class="line-row"><span class="label">Tipe</span><span class="value"><?= ws_e(ws_label_worker_type($worker['worker_type'] ?? '')); ?></span></div>
                <div class="line-row"><span class="label">Pembayaran</span><span class="value"><?= ws_e(ws_label_payment_type($worker['payment_type'] ?? '')); ?></span></div>
                <div class="line-row"><span class="label">Periode</span><span class="value"><?= ws_e($periodText); ?></span></div>
            </div>
        </div>

        <?php if ($attendance): ?>
            <div class="box attendance-box">
                <h4>Referensi Absensi Fingerprint</h4>
                <div class="line-row compact-row">
                    <span class="label">Hadir</span><span class="value"><?= (int) $attendance['attendance_days']; ?> hari</span>
                    <span class="label">Jam Kerja</span><span class="value"><?= ws_num(((int) $attendance['total_work_minutes']) / 60); ?> jam</span>
                    <span class="label">Lembur FP</span><span class="value"><?= ws_num(((int) $attendance['overtime_minutes']) / 60); ?> jam</span>
                </div>
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th class="text-right">Perhitungan</th>
                    <th class="text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Upah Dasar</td>
                    <td class="text-right"><?= (($worker['worker_type'] ?? '') === 'borongan') ? 'Borongan' : ($workDays . ' hari × ' . ws_money($rate)); ?></td>
                    <td class="text-right"><?= ws_money($baseAmount); ?></td>
                </tr>
                <tr>
                    <td>Lembur</td>
                    <td class="text-right"><?= ws_num($overtimeHours); ?> jam × <?= ws_money($overtimeRate); ?></td>
                    <td class="text-right"><?= ws_money($overtimeAmount); ?></td>
                </tr>
                <tr>
                    <td>Tambahan / Bonus</td>
                    <td class="text-right">-</td>
                    <td class="text-right text-success"><?= ws_money($bonusAmount); ?></td>
                </tr>
                <tr>
                    <td>Potongan</td>
                    <td class="text-right">-</td>
                    <td class="text-right text-danger">- <?= ws_money($deductionAmount); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="summary">
            <div class="summary-row"><span>Upah Dasar</span><strong><?= ws_money($baseAmount); ?></strong></div>
            <div class="summary-row"><span>Lembur + Tambahan - Potongan</span><strong><?= ws_money($overtimeAmount + $bonusAmount - $deductionAmount); ?></strong></div>
            <div class="summary-row"><span>TOTAL DIBAYARKAN</span><span><?= ws_money($totalAmount); ?></span></div>
        </div>

        <div class="notes"><strong>Catatan:</strong> <?= ws_e($worker['notes'] ?: '-'); ?></div>
        <div class="acceptance">Dengan menandatangani slip ini, pekerja menyatakan pembayaran telah diterima sesuai nominal yang tercantum.</div>

        <div class="signature">
            <div>
                Penerima,
                <div class="sign-line"><?= ws_e($worker['worker_name']); ?></div>
            </div>
            <div>
                Perusahaan,
                <div class="sign-line">PT Nukari Kriya Raya</div>
            </div>
        </div>
        <div class="cut-line">Potong di garis slip</div>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Slip Pekerja Project - <?= ws_e($project['project_name'] ?? 'Project'); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 18px; font-family: Arial, sans-serif; background: #eef2f5; color: #111827; }
        .actions { max-width: 210mm; margin: 0 auto 12px; display: flex; gap: 8px; justify-content: flex-end; align-items: center; flex-wrap: wrap; }
        .btn { border: 0; border-radius: 8px; padding: 9px 14px; font-size: 13px; text-decoration: none; cursor: pointer; display: inline-block; font-weight: 700; }
        .btn-back { background: #e5e7eb; color: #111827; }
        .btn-single { background: #1f2937; color: #fff; }
        .btn-all { background: #0f766e; color: #fff; }
        .btn-print { background: #15803d; color: #fff; }
        .hint { max-width: 210mm; margin: 0 auto 12px; font-size: 12px; color: #4b5563; text-align: right; }
        .project-summary { max-width: 210mm; margin: 0 auto 12px; background: #fff; border-radius: 12px; padding: 12px 14px; box-shadow: 0 6px 20px rgba(0,0,0,.06); display: flex; justify-content: space-between; gap: 12px; align-items: center; }
        .project-summary h2 { margin: 0 0 4px; font-size: 17px; }
        .project-summary p { margin: 0; font-size: 12px; color: #4b5563; }
        .summary-total { text-align: right; font-size: 12px; color: #4b5563; }
        .summary-total strong { display: block; font-size: 18px; color: #15803d; }
        .page { width: 210mm; min-height: 297mm; margin: 0 auto 14px; padding: 10mm; background: #fff; display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 7mm; box-shadow: 0 10px 30px rgba(0, 0, 0, .08); page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .slip-card { border: 1.5px dashed #6b7280; border-radius: 10px; padding: 6mm; height: 100%; overflow: hidden; position: relative; display: flex; flex-direction: column; gap: 4px; }
        .slip-card.empty { border-color: #cbd5e1; }
        .slip-header { display: grid; grid-template-columns: 1.15fr .95fr; gap: 6px; align-items: start; border-bottom: 2px solid #15803d; padding-bottom: 5px; margin-bottom: 3px; }
        .company-name { font-size: 14px; line-height: 1.1; font-weight: 800; color: #15803d; margin-bottom: 2px; }
        .company-address { font-size: 8.7px; color: #4b5563; line-height: 1.25; }
        .slip-title { text-align: right; }
        .slip-title strong { font-size: 13px; display: block; text-transform: uppercase; }
        .slip-title span { font-size: 8.7px; color: #374151; font-weight: 700; display: block; line-height: 1.25; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
        .box { border: 1px solid #cfd8e3; border-radius: 7px; padding: 5px; min-height: 0; }
        .box h4 { margin: 0 0 4px; font-size: 9.5px; color: #15803d; }
        .line-row { display: flex; justify-content: space-between; gap: 6px; font-size: 8.7px; line-height: 1.18; margin: 2px 0; }
        .compact-row { gap: 4px; flex-wrap: wrap; }
        .label { color: #374151; }
        .value { font-weight: 700; text-align: right; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; font-size: 8.4px; line-height: 1.12; margin-top: 1px; }
        td, th { border: 1px solid #cfd8e3; padding: 3px 4px; vertical-align: top; }
        th { background: #f8fafc; color: #111827; }
        .text-right { text-align: right; }
        .text-success { color: #079447; font-weight: 800; }
        .text-danger { color: #c1121f; font-weight: 800; }
        .summary { border: 1.5px solid #15803d; border-radius: 7px; overflow: hidden; margin-top: 2px; }
        .summary-row { display: flex; justify-content: space-between; gap: 8px; padding: 4px 6px; border-bottom: 1px solid #d1d5db; font-size: 9px; }
        .summary-row:last-child { border-bottom: 0; background: #15803d; color: #fff; font-size: 12px; font-weight: 800; padding: 5px 6px; }
        .notes { font-size: 7.4px; line-height: 1.15; color: #4b5563; max-height: 16px; overflow: hidden; margin-top: 1px; }
        .acceptance { font-size: 7.3px; line-height: 1.18; color: #111827; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px; margin-top: 2px; }
        .signature { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: auto; font-size: 8.3px; text-align: center; padding-top: 5px; }
        .sign-line { border-top: 1px solid #111827; margin-top: 20px; padding-top: 3px; font-weight: 700; }
        .cut-line { position: absolute; right: 7px; bottom: 5px; font-size: 7.2px; color: #94a3b8; }
        .empty-message { max-width: 210mm; margin: 0 auto; background: #fff; padding: 18px; border-radius: 12px; color: #4b5563; }
        @media print {
            @page { size: A4 portrait; margin: 0; }
            body { background: #fff; padding: 0; }
            .actions, .hint, .project-summary { display: none; }
            .page { width: 210mm; height: 297mm; min-height: 297mm; margin: 0; padding: 8mm; box-shadow: none; gap: 6mm; page-break-after: always; }
            .page:last-child { page-break-after: auto; }
            .slip-card { padding: 5.5mm; border-color: #111827; }
            .slip-card.empty { border-color: #d1d5db; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <a class="btn btn-back" href="<?= ws_e($backUrl); ?>">Kembali</a>
        <?php if ($singleModeUrl): ?>
            <a class="btn btn-single" href="<?= ws_e($singleModeUrl); ?>">Cetak Slip Ini Saja</a>
        <?php endif; ?>
        <a class="btn btn-all" href="<?= ws_e($allModeUrl); ?>">Cetak Semua Slip Project</a>
        <button class="btn btn-print" onclick="window.print()">Print / Cetak</button>
    </div>

    <div class="project-summary">
        <div>
            <h2><?= ws_e($project['project_name'] ?? '-'); ?></h2>
            <p>Kode: <?= ws_e($project['project_code'] ?? '-'); ?> | Client: <?= ws_e($project['client_name'] ?? '-'); ?> | Slip Non-Employee: <?= (int) $totalWorkers; ?></p>
        </div>
        <div class="summary-total">
            Total Slip Freelance/Borongan
            <strong><?= ws_money($totalAll); ?></strong>
        </div>
    </div>

    <div class="hint">Format A4 portrait dibagi 4 slip. Setiap halaman berisi maksimal 4 pekerja berbeda dari project ini.</div>

    <?php if (!$workers): ?>
        <div class="empty-message">Belum ada pekerja freelance/borongan yang memiliki slip project. Karyawan internal memakai slip Payroll.</div>
    <?php else: ?>
        <?php foreach (array_chunk($workers, 4) as $chunk): ?>
            <div class="page">
                <?php foreach ($chunk as $worker): ?>
                    <?php render_worker_slip($worker, $attendanceMap[(int) $worker['id']] ?? null); ?>
                <?php endforeach; ?>
                <?php for ($i = count($chunk); $i < 4; $i++): ?>
                    <div class="slip-card empty"></div>
                <?php endfor; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
