<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';

if (!function_exists('asset_export_table_exists')) {
    function asset_export_table_exists(PDO $pdo, $table) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
            $stmt->execute([':table_name' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('asset_export_rows')) {
    function asset_export_rows(PDO $pdo, $sql) {
        try {
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('asset_export_cell')) {
    function asset_export_cell($value) {
        return (string)($value ?? '');
    }
}

if (!function_exists('asset_export_money')) {
    function asset_export_money($value) {
        return is_numeric($value) ? (float)$value : 0;
    }
}

if (!function_exists('asset_export_count_status')) {
    function asset_export_count_status(array $rows, string $field, string $value): int {
        $count = 0;
        foreach ($rows as $row) {
            if (($row[$field] ?? '') === $value) {
                $count++;
            }
        }
        return $count;
    }
}

try {
    if (function_exists('asset_sync_due_reminders')) {
        asset_sync_due_reminders($pdo);
    }
} catch (Throwable $e) {
    // Jangan gagalkan export hanya karena sync reminder gagal.
}

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    exit('Vendor autoload tidak ditemukan. Pastikan folder vendor/PhpSpreadsheet ada.');
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\PageMargins;

$assets = asset_export_rows($pdo, "
    SELECT a.*, e.full_name responsible_name, p.project_name source_project_name
    FROM assets a
    LEFT JOIN employees e ON a.responsible_employee_id = e.id
    LEFT JOIN projects p ON a.source_project_id = p.id
    ORDER BY a.id DESC
");

$reminders = asset_export_table_exists($pdo, 'asset_reminders') ? asset_export_rows($pdo, "
    SELECT r.*, a.asset_code, a.asset_name
    FROM asset_reminders r
    LEFT JOIN assets a ON r.asset_id = a.id
    ORDER BY r.due_date ASC, r.id ASC
") : [];

$maintenance = asset_export_table_exists($pdo, 'asset_maintenance') ? asset_export_rows($pdo, "
    SELECT m.*, a.asset_code, a.asset_name
    FROM asset_maintenance m
    LEFT JOIN assets a ON m.asset_id = a.id
    ORDER BY m.maintenance_date DESC, m.id DESC
") : [];

$rentals = asset_export_table_exists($pdo, 'asset_rentals') ? asset_export_rows($pdo, "
    SELECT r.*, a.asset_code, a.asset_name
    FROM asset_rentals r
    LEFT JOIN assets a ON r.asset_id = a.id
    ORDER BY r.rental_start DESC, r.id DESC
") : [];

$payments = asset_export_table_exists($pdo, 'asset_rental_payments') ? asset_export_rows($pdo, "
    SELECT p.*, a.asset_code, a.asset_name
    FROM asset_rental_payments p
    LEFT JOIN assets a ON p.asset_id = a.id
    ORDER BY p.payment_date DESC, p.id DESC
") : [];

$audits = asset_export_table_exists($pdo, 'asset_audits') ? asset_export_rows($pdo, "
    SELECT au.*, a.asset_code, a.asset_name, e.full_name checked_by_name
    FROM asset_audits au
    LEFT JOIN assets a ON au.asset_id = a.id
    LEFT JOIN employees e ON au.checked_by = e.id
    ORDER BY au.audit_date DESC, au.id DESC
") : [];

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('Nukari Management')
    ->setCompany('PT Nukari Kriya Raya')
    ->setTitle('Laporan Aset Nukari')
    ->setSubject('Asset Report')
    ->setDescription('Laporan aset profesional untuk cetak landscape A4.');

$themeGreen = '0F6B4F';
$themeGreen2 = '148A5B';
$themeLight = 'EAF7EF';
$themeGold = 'D8C13F';
$darkText = '111827';
$mutedText = '64748B';
$border = 'D7DEE8';
$softGray = 'F8FAFC';
$danger = 'DC2626';
$warning = 'F59E0B';
$blue = '2563EB';

function setupPrintSheet(Worksheet $sheet, string $title = ''): void {
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()
        ->setTop(0.35)
        ->setRight(0.25)
        ->setLeft(0.25)
        ->setBottom(0.35)
        ->setHeader(0.15)
        ->setFooter(0.15);
    $sheet->getHeaderFooter()->setOddFooter('&LPT Nukari Kriya Raya&RHalaman &P dari &N');
    if ($title !== '') {
        $sheet->getHeaderFooter()->setOddHeader('&C' . $title);
    }
    // Compatible with older PhpSpreadsheet versions bundled in MAMP projects.
    if (method_exists($sheet, 'setShowGridlines')) {
        $sheet->setShowGridlines(false);
    }
}

function applyHeaderBlock(Worksheet $sheet, string $title, string $subtitle, string $lastColumn, string $themeGreen, string $themeGold): void {
    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue('A1', $title);
    $sheet->setCellValue('A2', $subtitle);
    $sheet->getStyle("A1:{$lastColumn}2")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $themeGreen]],
        'font' => ['color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20);
    $sheet->getStyle('A2')->getFont()->setSize(10);
    $sheet->getRowDimension(1)->setRowHeight(30);
    $sheet->getRowDimension(2)->setRowHeight(20);
    $sheet->getStyle("{$lastColumn}1:{$lastColumn}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($themeGold);
}

function styleDataTable(Worksheet $sheet, int $headerRow, int $lastColIndex, int $lastRow, array $moneyCols = [], array $dateCols = [], array $numberCols = []): void {
    $lastCol = Coordinate::stringFromColumnIndex($lastColIndex);
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '334155'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'CBD5E1']]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(23);
    if ($lastRow >= $headerRow) {
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D7DEE8']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
        ]);
        $sheet->getStyle("A" . ($headerRow + 1) . ":{$lastCol}{$lastRow}")->getFont()->setSize(9);
        for ($r = $headerRow + 1; $r <= $lastRow; $r++) {
            if ($r % 2 === 0) {
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            }
        }
    }
    foreach ($moneyCols as $colLetter) {
        $sheet->getStyle("{$colLetter}" . ($headerRow + 1) . ":{$colLetter}{$lastRow}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
    }
    foreach ($dateCols as $colLetter) {
        $sheet->getStyle("{$colLetter}" . ($headerRow + 1) . ":{$colLetter}{$lastRow}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    }
    foreach ($numberCols as $colLetter) {
        $sheet->getStyle("{$colLetter}" . ($headerRow + 1) . ":{$colLetter}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
    }
    $sheet->setAutoFilter("A{$headerRow}:{$lastCol}" . max($headerRow, $lastRow));
    $sheet->freezePane('A' . ($headerRow + 1));
}

function writeTable(Worksheet $sheet, int $startRow, array $headers, array $rows, array $moneyCols = [], array $dateCols = [], array $numberCols = []): int {
    $col = 1;
    foreach ($headers as $header) {
        $sheet->setCellValueByColumnAndRow($col, $startRow, $header);
        $col++;
    }
    $rowNum = $startRow + 1;
    foreach ($rows as $row) {
        $col = 1;
        foreach ($row as $value) {
            if ($value === null || $value === '') {
                $sheet->setCellValueByColumnAndRow($col, $rowNum, '');
            } elseif (is_numeric($value) && !preg_match('/^0[0-9]+/', (string)$value)) {
                $sheet->setCellValueByColumnAndRow($col, $rowNum, (float)$value);
            } else {
                $sheet->setCellValueExplicitByColumnAndRow($col, $rowNum, asset_export_cell($value), DataType::TYPE_STRING);
            }
            $col++;
        }
        $rowNum++;
    }
    styleDataTable($sheet, $startRow, count($headers), max($startRow, $rowNum - 1), $moneyCols, $dateCols, $numberCols);
    return max($startRow, $rowNum - 1);
}

function setWidths(Worksheet $sheet, array $widths): void {
    foreach ($widths as $col => $width) {
        $sheet->getColumnDimension($col)->setWidth($width);
    }
}

function writeKpiCard(Worksheet $sheet, string $range, string $labelCell, string $valueCell, string $label, $value, string $fillColor = 'F8FAFC'): void {
    $sheet->mergeCells($range);
    $sheet->setCellValue($labelCell, $label);
    $sheet->setCellValue($valueCell, $value);
    $sheet->getStyle($range)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D7DEE8']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle($labelCell)->getFont()->setBold(true)->setSize(8)->getColor()->setRGB('64748B');
    $sheet->getStyle($valueCell)->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('111827');
}

$totalAssets = count($assets);
$totalValue = 0;
$totalPurchase = 0;
$totalMaintenanceCost = 0;
$totalRentalIncome = 0;
foreach ($assets as $a) {
    $totalValue += asset_export_money($a['current_value'] ?? 0);
    $totalPurchase += asset_export_money($a['purchase_price'] ?? 0);
}
foreach ($maintenance as $m) {
    $totalMaintenanceCost += asset_export_money($m['cost'] ?? 0);
}
foreach ($payments as $p) {
    $totalRentalIncome += asset_export_money($p['amount'] ?? 0);
}
$activeAssets = asset_export_count_status($assets, 'asset_status', 'active');
$maintenanceAssets = asset_export_count_status($assets, 'asset_status', 'maintenance');
$damagedAssets = asset_export_count_status($assets, 'asset_status', 'damaged');
$lostAssets = asset_export_count_status($assets, 'asset_status', 'lost');
$rentedAssets = asset_export_count_status($assets, 'asset_status', 'rented');
$overdueReminders = 0;
$upcomingReminders = 0;
$today = date('Y-m-d');
$next30 = date('Y-m-d', strtotime('+30 days'));
foreach ($reminders as $r) {
    $due = $r['due_date'] ?? '';
    $status = $r['status'] ?? 'active';
    if ($status !== 'done' && $due !== '') {
        if ($due < $today) $overdueReminders++;
        if ($due >= $today && $due <= $next30) $upcomingReminders++;
    }
}

// ===============================
// Sheet 1: Ringkasan
// ===============================
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Ringkasan');
setupPrintSheet($sheet, 'Laporan Aset - Ringkasan');
applyHeaderBlock($sheet, 'Laporan & Dashboard Aset', 'PT Nukari Kriya Raya • Dicetak: ' . date('d/m/Y H:i') . ' • Format A4 Landscape', 'L', $themeGreen, $themeGold);
setWidths($sheet, ['A'=>14,'B'=>14,'C'=>14,'D'=>14,'E'=>14,'F'=>14,'G'=>14,'H'=>14,'I'=>14,'J'=>14,'K'=>14,'L'=>14]);

writeKpiCard($sheet, 'A4:B6', 'A4', 'A5', 'TOTAL ASET', $totalAssets, 'F8FAFC');
writeKpiCard($sheet, 'C4:D6', 'C4', 'C5', 'TOTAL NILAI', $totalValue, 'EAF7EF');
writeKpiCard($sheet, 'E4:F6', 'E4', 'E5', 'AKTIF', $activeAssets, 'ECFDF5');
writeKpiCard($sheet, 'G4:H6', 'G4', 'G5', 'MAINTENANCE', $maintenanceAssets, 'FFF7ED');
writeKpiCard($sheet, 'I4:J6', 'I4', 'I5', 'RUSAK/HILANG', ($damagedAssets + $lostAssets), 'FEF2F2');
writeKpiCard($sheet, 'K4:L6', 'K4', 'K5', 'DISEWAKAN', $rentedAssets, 'EFF6FF');
$sheet->getStyle('C5')->getNumberFormat()->setFormatCode('"Rp" #,##0');

writeKpiCard($sheet, 'A8:B10', 'A8', 'A9', 'BIAYA MAINTENANCE', $totalMaintenanceCost, 'FFF7ED');
writeKpiCard($sheet, 'C8:D10', 'C8', 'C9', 'PENDAPATAN SEWA', $totalRentalIncome, 'EFF6FF');
writeKpiCard($sheet, 'E8:F10', 'E8', 'E9', 'REMINDER 30 HARI', $upcomingReminders, 'FEFCE8');
writeKpiCard($sheet, 'G8:H10', 'G8', 'G9', 'REMINDER OVERDUE', $overdueReminders, 'FEF2F2');
writeKpiCard($sheet, 'I8:J10', 'I8', 'I9', 'NILAI BELI', $totalPurchase, 'F8FAFC');
writeKpiCard($sheet, 'K8:L10', 'K8', 'K9', 'SELISIH NILAI', ($totalValue - $totalPurchase), 'F8FAFC');
$sheet->getStyle('A9:C9')->getNumberFormat()->setFormatCode('"Rp" #,##0');
$sheet->getStyle('I9:L9')->getNumberFormat()->setFormatCode('"Rp" #,##0');

$categorySummary = [];
foreach ($assets as $a) {
    $cat = trim((string)($a['category'] ?? 'Tanpa Kategori')) ?: 'Tanpa Kategori';
    if (!isset($categorySummary[$cat])) $categorySummary[$cat] = ['count'=>0, 'value'=>0];
    $categorySummary[$cat]['count']++;
    $categorySummary[$cat]['value'] += asset_export_money($a['current_value'] ?? 0);
}
$catRows = [];
foreach ($categorySummary as $cat => $data) {
    $catRows[] = [$cat, $data['count'], $data['value']];
}
$sheet->setCellValue('A12', 'Aset per Kategori');
$sheet->getStyle('A12')->getFont()->setBold(true)->setSize(12);
writeTable($sheet, 13, ['Kategori', 'Total Aset', 'Nilai Saat Ini'], $catRows, ['C'], [], ['B']);

$maintenanceByMonth = [];
foreach ($maintenance as $m) {
    $month = substr((string)($m['maintenance_date'] ?? ''), 0, 7) ?: 'Tanpa Tanggal';
    if (!isset($maintenanceByMonth[$month])) $maintenanceByMonth[$month] = 0;
    $maintenanceByMonth[$month] += asset_export_money($m['cost'] ?? 0);
}
krsort($maintenanceByMonth);
$maintRows = [];
foreach (array_slice($maintenanceByMonth, 0, 12, true) as $month => $cost) {
    $maintRows[] = [$month, $cost];
}
$sheet->setCellValue('E12', 'Biaya Maintenance per Bulan');
$sheet->getStyle('E12')->getFont()->setBold(true)->setSize(12);
$endRow = 13;
$sheet->setCellValue('E13', 'Bulan');
$sheet->setCellValue('F13', 'Biaya');
$r = 14;
foreach ($maintRows as $mr) {
    $sheet->setCellValue("E{$r}", $mr[0]);
    $sheet->setCellValue("F{$r}", $mr[1]);
    $r++;
}
styleDataTable($sheet, 13, 6, max(13, $r-1), ['F'], [], []);

$reminderRows = [];
foreach ($reminders as $rmd) {
    $status = $rmd['status'] ?? 'active';
    if (($rmd['due_date'] ?? '') !== '' && $status !== 'done') {
        $reminderRows[] = [$rmd['due_date'] ?? '', $rmd['title'] ?? '', $rmd['asset_code'] ?? '', $rmd['asset_name'] ?? '', $rmd['priority'] ?? '', $status];
    }
}
usort($reminderRows, function($a, $b) { return strcmp((string)$a[0], (string)$b[0]); });
$reminderRows = array_slice($reminderRows, 0, 10);
$sheet->setCellValue('H12', 'Reminder Terdekat');
$sheet->getStyle('H12')->getFont()->setBold(true)->setSize(12);
$row = 13;
$headers = ['Tanggal','Judul','Kode','Aset','Prioritas','Status'];
for ($i=0; $i<count($headers); $i++) $sheet->setCellValueByColumnAndRow(8+$i, $row, $headers[$i]);
$row++;
foreach ($reminderRows as $rr) {
    for ($i=0; $i<count($rr); $i++) $sheet->setCellValueByColumnAndRow(8+$i, $row, $rr[$i]);
    $row++;
}
styleDataTable($sheet, 13, 13, max(13, $row-1), [], ['H'], []);
$sheet->getStyle('A1:L40')->getAlignment()->setWrapText(true);

// ===============================
// Sheet 2: Daftar Aset
// ===============================
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Daftar Aset');
setupPrintSheet($sheet, 'Laporan Aset - Daftar Aset');
applyHeaderBlock($sheet, 'Daftar Aset / Inventaris', 'Data lengkap aset, status, nilai, lokasi, PIC, dan project asal.', 'S', $themeGreen, $themeGold);
$assetRows = [];
$no = 1;
foreach ($assets as $a) {
    $assetRows[] = [
        $no++,
        $a['asset_code'] ?? '',
        $a['asset_name'] ?? '',
        $a['category'] ?? '',
        $a['asset_status'] ?? '',
        $a['condition_status'] ?? '',
        $a['condition_score'] ?? 0,
        $a['ownership_status'] ?? '',
        $a['use_type'] ?? '',
        $a['purchase_date'] ?? '',
        $a['purchase_price'] ?? 0,
        $a['current_value'] ?? 0,
        $a['residual_value'] ?? 0,
        $a['useful_life_months'] ?? 0,
        $a['warranty_until'] ?? '',
        $a['location'] ?? '',
        $a['responsible_name'] ?? '',
        $a['source_type'] ?? '',
        $a['source_project_name'] ?? '',
    ];
}
writeTable($sheet, 4, ['No','Kode','Nama Aset','Kategori','Status','Kondisi','Score','Ownership','Use Type','Tgl Beli','Harga Beli','Nilai Kini','Nilai Residu','Umur Ekonomis','Garansi','Lokasi','PIC','Sumber','Project Asal'], $assetRows, ['K','L','M'], ['J','O'], ['A','G','N']);
setWidths($sheet, ['A'=>5,'B'=>18,'C'=>24,'D'=>16,'E'=>14,'F'=>14,'G'=>9,'H'=>14,'I'=>14,'J'=>12,'K'=>15,'L'=>15,'M'=>15,'N'=>12,'O'=>12,'P'=>20,'Q'=>18,'R'=>14,'S'=>26]);
$sheet->getStyle('A4:S' . max(4, count($assetRows)+4))->getAlignment()->setWrapText(true);

// ===============================
// Reminder
// ===============================
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Reminder');
setupPrintSheet($sheet, 'Laporan Aset - Reminder');
applyHeaderBlock($sheet, 'Reminder Aset', 'Jadwal pengingat service, pajak, garansi, kontrak, dan kebutuhan operasional lain.', 'I', $themeGreen, $themeGold);
$rows = [];
$no = 1;
foreach ($reminders as $r) {
    $label = function_exists('asset_reminder_type_label') ? asset_reminder_type_label($r['reminder_type'] ?? '') : ($r['reminder_type'] ?? '');
    $rows[] = [$no++, $r['due_date'] ?? '', $label, $r['title'] ?? '', $r['asset_code'] ?? '', $r['asset_name'] ?? '', $r['priority'] ?? '', $r['status'] ?? '', $r['description'] ?? ''];
}
writeTable($sheet, 4, ['No','Jatuh Tempo','Jenis','Judul','Kode Aset','Nama Aset','Prioritas','Status','Deskripsi'], $rows, [], ['B'], ['A']);
setWidths($sheet, ['A'=>5,'B'=>13,'C'=>20,'D'=>28,'E'=>18,'F'=>24,'G'=>12,'H'=>12,'I'=>45]);
$sheet->getStyle('A4:I' . max(4, count($rows)+4))->getAlignment()->setWrapText(true);

// ===============================
// Maintenance
// ===============================
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Maintenance');
setupPrintSheet($sheet, 'Laporan Aset - Maintenance');
applyHeaderBlock($sheet, 'Maintenance Aset', 'Riwayat service/perbaikan, vendor, biaya, dan jadwal maintenance berikutnya.', 'J', $themeGreen, $themeGold);
$rows = [];
$no = 1;
foreach ($maintenance as $m) {
    $rows[] = [$no++, $m['maintenance_date'] ?? '', $m['asset_code'] ?? '', $m['asset_name'] ?? '', $m['maintenance_type'] ?? '', $m['description'] ?? '', $m['cost'] ?? 0, $m['vendor_name'] ?? '', $m['next_maintenance_date'] ?? '', $m['notes'] ?? ''];
}
writeTable($sheet, 4, ['No','Tanggal','Kode Aset','Nama Aset','Jenis','Deskripsi','Biaya','Vendor','Maintenance Berikutnya','Catatan'], $rows, ['G'], ['B','I'], ['A']);
setWidths($sheet, ['A'=>5,'B'=>12,'C'=>18,'D'=>24,'E'=>18,'F'=>42,'G'=>14,'H'=>20,'I'=>16,'J'=>35]);
$sheet->getStyle('A4:J' . max(4, count($rows)+4))->getAlignment()->setWrapText(true);

// ===============================
// Sewa Aset
// ===============================
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Sewa Aset');
setupPrintSheet($sheet, 'Laporan Aset - Sewa Aset');
applyHeaderBlock($sheet, 'Penyewaan Aset', 'Data aset yang disewakan, nilai sewa, deposit, jatuh tempo, dan status.', 'M', $themeGreen, $themeGold);
$rows = [];
$no = 1;
foreach ($rentals as $r) {
    $rows[] = [$no++, $r['asset_code'] ?? '', $r['asset_name'] ?? '', $r['renter_name'] ?? '', $r['renter_phone'] ?? '', $r['rental_start'] ?? '', $r['rental_end'] ?? '', $r['billing_cycle'] ?? '', $r['rental_amount'] ?? 0, $r['deposit_amount'] ?? 0, $r['next_due_date'] ?? '', $r['status'] ?? '', $r['notes'] ?? ''];
}
writeTable($sheet, 4, ['No','Kode Aset','Nama Aset','Penyewa','Telepon','Mulai','Selesai','Siklus','Nilai Sewa','Deposit','Jatuh Tempo','Status','Catatan'], $rows, ['I','J'], ['F','G','K'], ['A']);
setWidths($sheet, ['A'=>5,'B'=>18,'C'=>24,'D'=>24,'E'=>15,'F'=>12,'G'=>12,'H'=>14,'I'=>15,'J'=>14,'K'=>14,'L'=>12,'M'=>34]);
$sheet->getStyle('A4:M' . max(4, count($rows)+4))->getAlignment()->setWrapText(true);

// ===============================
// Pembayaran Sewa
// ===============================
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Pembayaran Sewa');
setupPrintSheet($sheet, 'Laporan Aset - Pembayaran Sewa');
applyHeaderBlock($sheet, 'Pembayaran Sewa Aset', 'Riwayat pembayaran sewa yang terhubung dengan aset.', 'I', $themeGreen, $themeGold);
$rows = [];
$no = 1;
foreach ($payments as $p) {
    $rows[] = [$no++, $p['payment_date'] ?? '', $p['asset_code'] ?? '', $p['asset_name'] ?? '', $p['period_start'] ?? '', $p['period_end'] ?? '', $p['amount'] ?? 0, $p['payment_method'] ?? '', $p['notes'] ?? ''];
}
writeTable($sheet, 4, ['No','Tanggal Bayar','Kode Aset','Nama Aset','Periode Mulai','Periode Selesai','Jumlah','Metode','Catatan'], $rows, ['G'], ['B','E','F'], ['A']);
setWidths($sheet, ['A'=>5,'B'=>14,'C'=>18,'D'=>26,'E'=>14,'F'=>14,'G'=>15,'H'=>15,'I'=>42]);
$sheet->getStyle('A4:I' . max(4, count($rows)+4))->getAlignment()->setWrapText(true);

// ===============================
// Audit
// ===============================
$sheet = $spreadsheet->createSheet();
$sheet->setTitle('Audit');
setupPrintSheet($sheet, 'Laporan Aset - Audit');
applyHeaderBlock($sheet, 'Audit / Stock Opname Aset', 'Status audit aset, kondisi fisik, lokasi ditemukan, dan catatan pemeriksaan.', 'I', $themeGreen, $themeGold);
$rows = [];
$no = 1;
foreach ($audits as $a) {
    $rows[] = [$no++, $a['audit_date'] ?? '', $a['asset_code'] ?? '', $a['asset_name'] ?? '', $a['audit_status'] ?? '', $a['physical_condition'] ?? '', $a['location_found'] ?? '', $a['checked_by_name'] ?? '', $a['notes'] ?? ''];
}
writeTable($sheet, 4, ['No','Tanggal Audit','Kode Aset','Nama Aset','Status Audit','Kondisi Fisik','Lokasi Ditemukan','Dicek Oleh','Catatan'], $rows, [], ['B'], ['A']);
setWidths($sheet, ['A'=>5,'B'=>14,'C'=>18,'D'=>26,'E'=>16,'F'=>22,'G'=>24,'H'=>18,'I'=>44]);
$sheet->getStyle('A4:I' . max(4, count($rows)+4))->getAlignment()->setWrapText(true);

// Style workbook-wide font
foreach ($spreadsheet->getAllSheets() as $ws) {
    $ws->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
}

$spreadsheet->setActiveSheetIndex(0);
$filename = 'asset-report-' . date('Ymd-His') . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
