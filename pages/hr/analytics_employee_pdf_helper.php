<?php

require_once __DIR__ . '/analytics_employee_helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function hr_pdf_text($value): string
{
    $normalized = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}"], '-', (string)$value);
    return h($normalized);
}

function hr_employee_pdf_slug($value): string
{
    $value = strtolower(trim((string)$value));
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    return trim($value, '-') ?: 'karyawan';
}

function hr_employee_pdf_filename(array $payload): string
{
    $employee = $payload['employee'];
    $periodSource = $payload['periodSource'] ?? [];
    $type = $periodSource['type'] ?? 'monthly';
    if ($type === 'monthly' && preg_match('/^\d{4}-\d{2}$/', (string)($periodSource['month'] ?? ''))) {
        $period = $periodSource['month'];
    } elseif ($type === 'yearly' && preg_match('/^\d{4}$/', (string)($periodSource['year'] ?? ''))) {
        $period = $periodSource['year'];
    } else {
        $period = date('Ymd', strtotime($payload['periodStart'])) . '-'
            . date('Ymd', strtotime($payload['periodEnd']));
    }

    return 'Evaluasi-'
        . hr_employee_pdf_slug($employee['full_name'] ?? 'karyawan') . '-'
        . $period . '.pdf';
}

function hr_employee_pdf_html(array $payload, string $generatedBy, ?DateTimeInterface $generatedAt = null): string
{
    $employee = $payload['employee'];
    $evaluation = $payload['evaluation'];
    $explanation = $payload['explanation'];
    $details = $payload['details'];
    $counts = $payload['counts'];
    $generatedAt = $generatedAt ?: new DateTimeImmutable();

    $statusColors = [
        'success' => ['#166534', '#dcfce7'],
        'primary' => ['#1d4ed8', '#dbeafe'],
        'warning' => ['#92400e', '#fef3c7'],
        'danger' => ['#991b1b', '#fee2e2'],
        'secondary' => ['#374151', '#e5e7eb'],
    ];
    [$statusColor, $statusBackground] = $statusColors[$evaluation['evaluation_class'] ?? 'secondary'] ?? $statusColors['secondary'];

    $components = [
        ['Kehadiran', $evaluation['attendance_rate'], 35],
        ['Disiplin', $evaluation['discipline_rate'], 25],
        ['Jam Kerja', min(100, hr_to_float($evaluation['work_rate'])), 25],
        ['Lembur', $evaluation['overtime_score'], 10],
        ['Cuti/Izin', $evaluation['leave_score'], 5],
    ];

    ob_start();
    ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm 18mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #1f2937; font-size: 9px; line-height: 1.42; }
        .header { background: #14532d; color: #fff; padding: 16px 18px; border-radius: 10px; margin-bottom: 12px; }
        .header-table, .identity-table, .score-table, .component-table, .summary-table, .insight-table { width: 100%; border-collapse: collapse; }
        .company { font-size: 10px; font-weight: bold; letter-spacing: .5px; }
        .title { margin: 4px 0 2px; font-size: 21px; font-weight: bold; }
        .header-meta { color: #dcfce7; font-size: 8px; }
        .doc-code { text-align: right; vertical-align: top; font-size: 8px; color: #dcfce7; }
        .doc-code strong { display: block; color: #fff; font-size: 9px; margin-bottom: 3px; }
        .section { margin-top: 12px; }
        .section-title { color: #14532d; font-size: 11px; font-weight: bold; padding-bottom: 4px; border-bottom: 1px solid #bbf7d0; margin-bottom: 7px; }
        .identity-table td { width: 50%; border: 1px solid #e5e7eb; padding: 7px 9px; vertical-align: top; }
        .label { color: #64748b; font-size: 7px; text-transform: uppercase; letter-spacing: .35px; margin-bottom: 2px; }
        .value { color: #111827; font-size: 9px; font-weight: bold; }
        .score-table { margin-top: 10px; }
        .score-table td { vertical-align: middle; }
        .score-main { width: 24%; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 9px; padding: 11px; text-align: center; }
        .score-number { color: #1d4ed8; font-size: 28px; font-weight: bold; line-height: 1; }
        .score-caption { color: #64748b; font-size: 7px; margin-top: 4px; }
        .score-summary { padding-left: 12px; }
        .status { display: inline-block; color: <?= h($statusColor); ?>; background: <?= h($statusBackground); ?>; border-radius: 12px; padding: 4px 9px; font-size: 8px; font-weight: bold; margin-bottom: 5px; }
        .summary-text { color: #475569; }
        .component-table { table-layout: fixed; margin-top: 8px; }
        .component-table td { width: 20%; border: 1px solid #e5e7eb; background: #f8fafc; padding: 6px; text-align: center; }
        .component-name { color: #64748b; font-size: 7px; }
        .component-value { color: #111827; font-weight: bold; font-size: 11px; margin: 2px 0; }
        .component-weight { color: #64748b; font-size: 6.5px; }
        .summary-table { table-layout: fixed; }
        .summary-table td { width: 14.28%; border: 1px solid #e5e7eb; padding: 6px 4px; text-align: center; }
        .summary-table .metric { color: #111827; font-size: 12px; font-weight: bold; }
        .summary-table .metric-label { color: #64748b; font-size: 6.5px; }
        .insight-table { table-layout: fixed; }
        .insight-table td { width: 33.33%; vertical-align: top; border: 1px solid #e5e7eb; padding: 8px 9px; }
        .insight-title { font-weight: bold; font-size: 8px; margin-bottom: 4px; }
        .positive { color: #166534; }
        .reason { color: #92400e; }
        .action { color: #1d4ed8; }
        ul { margin: 0; padding-left: 13px; }
        li { margin-bottom: 3px; }
        .detail-table { width: 100%; border-collapse: collapse; font-size: 7.5px; }
        .detail-table thead { display: table-header-group; }
        .detail-table tr { page-break-inside: avoid; }
        .detail-table th { background: #14532d; color: #fff; padding: 6px; text-align: left; }
        .detail-table td { border: 1px solid #e5e7eb; padding: 5px 6px; vertical-align: top; }
        .detail-table tr:nth-child(even) td { background: #f8fafc; }
        .event { display: inline-block; background: #e5e7eb; color: #374151; border-radius: 8px; padding: 2px 5px; margin: 0 2px 2px 0; }
        .notice { margin-top: 12px; border: 1px solid #bfdbfe; background: #eff6ff; color: #334155; border-radius: 7px; padding: 7px 9px; font-size: 7px; }
        .footer { position: fixed; left: 0; right: 0; bottom: -11mm; border-top: 1px solid #e5e7eb; padding-top: 5px; color: #64748b; font-size: 6.5px; }
        .empty { color: #64748b; text-align: center; padding: 12px; border: 1px solid #e5e7eb; background: #f8fafc; }
    </style>
</head>
<body>
    <div class="footer">
        PT Nukari Kriya Raya - Informasi evaluasi karyawan
    </div>

    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <div class="company">PT NUKARI KRIYA RAYA</div>
                    <div class="title">Evaluasi <?= hr_pdf_text($employee['full_name']); ?></div>
                    <div class="header-meta"><?= hr_pdf_text($payload['periodLabel']); ?> - <?= hr_pdf_text($payload['periodText']); ?></div>
                </td>
                <td class="doc-code">
                    <strong>INFORMASI EVALUASI</strong>
                    Dibuat oleh <?= hr_pdf_text($generatedBy); ?><br>
                    <?= hr_pdf_text($generatedAt->format('d/m/Y H:i')); ?>
                </td>
            </tr>
        </table>
    </div>

    <table class="identity-table">
        <tr>
            <td><div class="label">Nama Karyawan</div><div class="value"><?= hr_pdf_text($employee['full_name']); ?></div></td>
            <td><div class="label">Kode Karyawan</div><div class="value"><?= hr_pdf_text($employee['employee_code'] ?: '-'); ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Jabatan</div><div class="value"><?= hr_pdf_text($employee['position'] ?: '-'); ?></div></td>
            <td><div class="label">Departemen</div><div class="value"><?= hr_pdf_text($employee['department'] ?: '-'); ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Periode Mulai</div><div class="value"><?= hr_pdf_text(date('d/m/Y', strtotime($payload['periodStart']))); ?></div></td>
            <td><div class="label">Periode Selesai</div><div class="value"><?= hr_pdf_text(date('d/m/Y', strtotime($payload['periodEnd']))); ?></div></td>
        </tr>
    </table>

    <table class="score-table">
        <tr>
            <td class="score-main">
                <div class="score-number"><?= hr_pdf_text(hr_number($evaluation['score'], 1)); ?></div>
                <div class="score-caption">NILAI DARI 100</div>
            </td>
            <td class="score-summary">
                <span class="status"><?= hr_pdf_text($evaluation['evaluation_note']); ?></span>
                <div class="summary-text"><?= hr_pdf_text($explanation['summary']); ?></div>
            </td>
        </tr>
    </table>

    <table class="component-table">
        <tr>
            <?php foreach ($components as [$label, $value, $weight]): ?>
                <td>
                    <div class="component-name"><?= hr_pdf_text($label); ?></div>
                    <div class="component-value"><?= hr_pdf_text(hr_number($value, 1)); ?></div>
                    <div class="component-weight">Bobot <?= (int)$weight; ?>% - <?= hr_pdf_text(hr_number(($value * $weight) / 100, 1)); ?> poin</div>
                </td>
            <?php endforeach; ?>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">Ringkasan Kehadiran dan Kedisiplinan</div>
        <table class="summary-table"><tr>
            <?php foreach ([
                ['Telat', $counts['late']],
                ['Pulang Awal', $counts['early_leave']],
                ['Lembur', $counts['overtime']],
                ['Setengah Hari', $counts['half_day']],
                ['Datang Awal', $counts['early_arrival']],
                ['Alpha', $counts['absent']],
                ['Perlu Diperiksa', $counts['incomplete_scan']],
            ] as [$label, $value]): ?>
                <td><div class="metric"><?= (int)$value; ?>x</div><div class="metric-label"><?= hr_pdf_text($label); ?></div></td>
            <?php endforeach; ?>
        </tr></table>
    </div>

    <div class="section">
        <div class="section-title">Ringkasan Evaluasi</div>
        <table class="insight-table"><tr>
            <td><div class="insight-title positive">Hal Positif</div><ul><?php foreach ($explanation['positives'] as $item): ?><li><?= hr_pdf_text($item); ?></li><?php endforeach; ?></ul></td>
            <td><div class="insight-title reason">Catatan Evaluasi</div><ul><?php foreach ($explanation['reasons'] as $item): ?><li><?= hr_pdf_text($item); ?></li><?php endforeach; ?></ul></td>
            <td><div class="insight-title action">Saran dan Tindak Lanjut</div><ul><?php foreach ($explanation['actions'] as $item): ?><li><?= hr_pdf_text($item); ?></li><?php endforeach; ?></ul></td>
        </tr></table>
    </div>

    <div class="section">
        <div class="section-title">Rincian Kejadian pada Periode Evaluasi</div>
        <?php if ($details): ?>
            <table class="detail-table">
                <thead><tr><th style="width:15%">Tanggal</th><th style="width:16%">Jam</th><th style="width:39%">Kejadian</th><th style="width:30%">Durasi/Keterangan</th></tr></thead>
                <tbody>
                <?php foreach ($details as $row): ?>
                    <?php $eventData = hr_employee_detail_events($row); if (!$eventData['events']) continue; ?>
                    <tr>
                        <td><?= hr_pdf_text(date('d/m/Y', strtotime($row['attendance_date']))); ?></td>
                        <td><?= hr_pdf_text($row['check_in'] ? substr($row['check_in'], 0, 5) : '-'); ?> - <?= hr_pdf_text($row['check_out'] ? substr($row['check_out'], 0, 5) : '-'); ?></td>
                        <td><?php foreach ($eventData['events'] as $event): ?><span class="event"><?= hr_pdf_text($event); ?></span><?php endforeach; ?></td>
                        <td><?= hr_pdf_text(implode(' - ', $eventData['durations'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty">Tidak ada kejadian khusus pada periode evaluasi ini.</div>
        <?php endif; ?>
    </div>

    <div class="notice">
        Dokumen ini merupakan informasi dan pengingat kepada karyawan berdasarkan data kehadiran dan aktivitas kerja pada periode tersebut. Apabila terdapat ketidaksesuaian data, silakan menghubungi bagian HR/Admin untuk dilakukan pemeriksaan. Dokumen ini bukan surat peringatan dan bukan dasar pemotongan payroll otomatis.
    </div>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function hr_render_employee_evaluation_pdf(array $payload, string $generatedBy, ?DateTimeInterface $generatedAt = null): Dompdf
{
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('chroot', dirname(__DIR__, 2));

    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml(hr_employee_pdf_html($payload, $generatedBy, $generatedAt), 'UTF-8');
    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $canvas->page_text(510, 812, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 6.5, [0.39, 0.45, 0.55]);

    return $dompdf;
}
