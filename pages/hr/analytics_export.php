<?php
/**
 * HR Analytics Export - Native XLSX, One Sheet, Two Tables
 *
 * Tujuan:
 * 1. Hanya satu sheet.
 * 2. Jika tabel terlalu panjang, data dibagi menjadi 2 tabel dalam 1 sheet:
 *    - Tabel 1: Kehadiran & Kedisiplinan
 *    - Tabel 2: Jam Kerja, Score & Evaluasi
 * 3. Tampilan penuh warna dan lebih nyaman dibaca.
 * 4. File valid XLSX, bukan HTML yang dipaksa menjadi Excel.
 * 5. Page setup A4 Landscape.
 */

ob_start();

require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations.view');
require_once __DIR__ . '/analytics_helper.php';

$data = hr_build_analytics($pdo, $_GET);

$rows        = $data['rows'] ?? [];
$summary     = $data['summary'] ?? [];
$periodText  = $data['periodText'] ?? '-';
$periodLabel = $data['periodLabel'] ?? '-';

$lateToleranceLabel = defined('HR_LATE_TOLERANCE_TIME')
    ? substr(HR_LATE_TOLERANCE_TIME, 0, 5)
    : '08:20';

function xlsx_escape($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_number($value)
{
    if ($value === null || $value === '') {
        return 0;
    }

    return is_numeric($value) ? $value + 0 : 0;
}

function xlsx_col($index)
{
    $index++;
    $letters = '';

    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - $mod) / 26);
    }

    return $letters;
}

function xlsx_cell($row, $col, $value, $style = 0, $type = 's')
{
    $ref = xlsx_col($col) . $row;

    if ($type === 'n') {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . xlsx_number($value) . '</v></c>';
    }

    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . xlsx_escape($value) . '</t></is></c>';
}

function xlsx_row($rowNumber, array $cells, $height = null)
{
    $heightAttr = $height ? ' ht="' . $height . '" customHeight="1"' : '';
    $xml = '<row r="' . $rowNumber . '"' . $heightAttr . '>';

    foreach ($cells as $index => $cell) {
        $value = $cell[0] ?? '';
        $style = $cell[1] ?? 0;
        $type  = $cell[2] ?? 's';

        $xml .= xlsx_cell($rowNumber, $index, $value, $style, $type);
    }

    $xml .= '</row>';

    return $xml;
}

function xlsx_sheet_xml(array $rows, array $mergeCells = [], array $widths = [], array $heights = [])
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ';
    $xml .= 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="8" topLeftCell="A9" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

    if (!empty($widths)) {
        $xml .= '<cols>';
        foreach ($widths as $i => $width) {
            $col = $i + 1;
            $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';

    foreach ($rows as $rowNumber => $cells) {
        $xml .= xlsx_row($rowNumber, $cells, $heights[$rowNumber] ?? null);
    }

    $xml .= '</sheetData>';

    if (!empty($mergeCells)) {
        $xml .= '<mergeCells count="' . count($mergeCells) . '">';
        foreach ($mergeCells as $merge) {
            $xml .= '<mergeCell ref="' . $merge . '"/>';
        }
        $xml .= '</mergeCells>';
    }

    $xml .= '<printOptions horizontalCentered="1"/>';
    $xml .= '<pageMargins left="0.25" right="0.25" top="0.45" bottom="0.45" header="0.2" footer="0.2"/>';
    $xml .= '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
    $xml .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';

    $xml .= '</worksheet>';

    return $xml;
}

function xlsx_create_package($filename, $sheetName, $sheetXml)
{
    $tmp = tempnam(sys_get_temp_dir(), 'hr_xlsx_');

    if ($tmp === false) {
        throw new RuntimeException('Unable to create temporary file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create XLSX package.');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>');

    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="15000"/></bookViews>
    <sheets>
        <sheet name="' . xlsx_escape($sheetName) . '" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');

    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <fonts count="7">
        <font><sz val="10"/><color rgb="FF111827"/><name val="Arial"/></font>
        <font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
        <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
        <font><b/><sz val="11"/><color rgb="FF14532D"/><name val="Arial"/></font>
        <font><b/><sz val="12"/><color rgb="FF1E3A8A"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FF92400E"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FF991B1B"/><name val="Arial"/></font>
    </fonts>
    <fills count="14">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF14532D"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF15803D"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFFFBEB"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="3">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FFD1D5DB"/></left>
            <right style="thin"><color rgb="FFD1D5DB"/></right>
            <top style="thin"><color rgb="FFD1D5DB"/></top>
            <bottom style="thin"><color rgb="FFD1D5DB"/></bottom>
            <diagonal/>
        </border>
        <border>
            <left style="thin"><color rgb="FF94A3B8"/></left>
            <right style="thin"><color rgb="FF94A3B8"/></right>
            <top style="medium"><color rgb="FF14532D"/></top>
            <bottom style="thin"><color rgb="FF94A3B8"/></bottom>
            <diagonal/>
        </border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="18">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1"/>
        <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="6" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="8" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="9" borderId="1" xfId="0" applyFill="1"/>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="right"/></xf>
        <xf numFmtId="0" fontId="5" fillId="12" borderId="1" xfId="0" applyFont="1" applyFill="1"/>
        <xf numFmtId="0" fontId="3" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="8" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>
    </cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>');

    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dc:title>HR Analytics Report</dc:title>
    <dc:creator>PT Nukari Kriya Raya</dc:creator>
    <dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>
</cp:coreProperties>');

    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
    <Application>PT Nukari Kriya Raya Management System</Application>
</Properties>');

    $zip->close();

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: max-age=0');

    readfile($tmp);
    unlink($tmp);
    exit;
}

function status_style_index($class)
{
    if ($class === 'success') return 13;
    if ($class === 'primary') return 14;
    if ($class === 'warning') return 15;
    if ($class === 'danger') return 16;
    return 17;
}

$sheetRows = [];
$mergeCells = [];
$heights = [];

$sheetRows[1] = [['HR ANALYTICS REPORT - PT NUKARI KRIYA RAYA', 1]];
$mergeCells[] = 'A1:O1';
$heights[1] = 28;

$sheetRows[2] = [['Periode: ' . $periodLabel . ' · ' . $periodText . ' · Toleransi Telat: ' . $lateToleranceLabel . ' · Export: ' . date('d M Y H:i'), 5]];
$mergeCells[] = 'A2:O2';

$sheetRows[4] = [
    ['Karyawan', 3], [xlsx_number($summary['employees'] ?? 0), 4, 'n'],
    ['Avg Score', 3], [xlsx_number($summary['average_score'] ?? 0), 4, 'n'],
    ['Attendance', 3], [xlsx_number($summary['average_attendance_rate'] ?? 0), 4, 'n'],
    ['Jam Kerja', 3], [round(xlsx_number($summary['work_minutes'] ?? 0) / 60, 2), 4, 'n'],
    ['Lembur', 3], [round(xlsx_number($summary['overtime_minutes'] ?? 0) / 60, 2), 4, 'n'],
    ['Perlu Perhatian', 3], [xlsx_number($summary['need_attention'] ?? 0), 4, 'n'],
    ['', 0], ['', 0], ['', 0]
];

$sheetRows[6] = [['TABEL 1 - KEHADIRAN & KEDISIPLINAN', 5]];
$mergeCells[] = 'A6:L6';
$heights[6] = 22;

$sheetRows[7] = [
    ['Rank', 2], ['Nama', 2], ['Kode', 2], ['Jabatan', 2], ['Departemen', 2],
    ['Hari Kerja Efektif', 2], ['Hadir', 2], ['Cuti/Izin Approved', 2],
    ['Izin Tidak Dibayar', 2], ['Alpha', 2], ['Telat (x)', 2], ['Menit Telat', 2]
];

$currentRow = 8;
foreach ($rows as $row) {
    $sheetRows[$currentRow] = [
        [xlsx_number($row['rank'] ?? 0), 10, 'n'],
        [$row['full_name'] ?? '', 9],
        [$row['employee_code'] ?? '', 0],
        [$row['position'] ?? '', 0],
        [$row['department'] ?? '', 0],
        [xlsx_number($row['effective_work_days'] ?? 0), 10, 'n'],
        [xlsx_number($row['present_days'] ?? 0), 10, 'n'],
        [xlsx_number($row['approved_leave_days'] ?? 0), 10, 'n'],
        [xlsx_number($row['unpaid_leave_days'] ?? 0), 10, 'n'],
        [xlsx_number($row['absent_days'] ?? 0), 10, 'n'],
        [xlsx_number($row['late_days'] ?? 0), 10, 'n'],
        [xlsx_number($row['late_minutes'] ?? 0), 10, 'n'],
    ];
    $currentRow++;
}

if (empty($rows)) {
    $sheetRows[$currentRow] = [['Belum ada data.', 12]];
    $mergeCells[] = 'A' . $currentRow . ':L' . $currentRow;
    $currentRow++;
}

$currentRow += 2;

$sheetRows[$currentRow] = [['TABEL 2 - JAM KERJA, SCORE & EVALUASI', 5]];
$mergeCells[] = 'A' . $currentRow . ':O' . $currentRow;
$heights[$currentRow] = 22;
$currentRow++;

$sheetRows[$currentRow] = [
    ['Rank', 2], ['Nama', 2], ['Kode', 2], ['Jabatan', 2], ['Departemen', 2],
    ['Pulang Awal (x)', 2], ['Menit Pulang Awal', 2], ['Jam Kerja', 2],
    ['Target Jam Kerja', 2], ['Lembur', 2], ['Attendance Rate (%)', 2],
    ['Work Rate (%)', 2], ['Score', 2], ['Status', 2], ['Catatan Meeting', 2]
];
$currentRow++;

foreach ($rows as $row) {
    $statusStyle = status_style_index($row['evaluation_class'] ?? 'secondary');

    $sheetRows[$currentRow] = [
        [xlsx_number($row['rank'] ?? 0), 10, 'n'],
        [$row['full_name'] ?? '', 9],
        [$row['employee_code'] ?? '', 0],
        [$row['position'] ?? '', 0],
        [$row['department'] ?? '', 0],
        [xlsx_number($row['early_leave_days'] ?? 0), 10, 'n'],
        [xlsx_number($row['early_leave_minutes'] ?? 0), 10, 'n'],
        [round(xlsx_number($row['total_work_minutes'] ?? 0) / 60, 2), 11, 'n'],
        [round(xlsx_number($row['standard_work_minutes'] ?? 0) / 60, 2), 11, 'n'],
        [round(xlsx_number($row['total_overtime_minutes'] ?? 0) / 60, 2), 11, 'n'],
        [xlsx_number($row['attendance_rate'] ?? 0), 10, 'n'],
        [xlsx_number($row['work_rate'] ?? 0), 10, 'n'],
        [xlsx_number($row['score'] ?? 0), 14, 'n'],
        [$row['evaluation_note'] ?? '', $statusStyle],
        [$row['meeting_note'] ?? '', 0],
    ];

    $currentRow++;
}

if (empty($rows)) {
    $sheetRows[$currentRow] = [['Belum ada data.', 12]];
    $mergeCells[] = 'A' . $currentRow . ':O' . $currentRow;
    $currentRow++;
}

$currentRow += 1;
$sheetRows[$currentRow] = [['Catatan: File ini adalah XLSX asli satu sheet. Data dibagi menjadi 2 tabel agar lebih mudah dibaca dan cocok untuk A4 Landscape.', 12]];
$mergeCells[] = 'A' . $currentRow . ':O' . $currentRow;

$widths = [8, 26, 14, 18, 18, 18, 16, 20, 20, 12, 12, 14, 12, 20, 34];

if (!class_exists('ZipArchive')) {
    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename = 'hr-analytics-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "\xEF\xBB\xBF";
    echo "HR Analytics Report - PT Nukari Kriya Raya\n";
    echo "Periode," . $periodLabel . " - " . $periodText . "\n";
    echo "Toleransi Telat," . $lateToleranceLabel . "\n\n";

    echo "TABEL 1 - KEHADIRAN & KEDISIPLINAN\n";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Rank', 'Nama', 'Kode', 'Jabatan', 'Departemen', 'Hari Kerja Efektif', 'Hadir', 'Cuti/Izin Approved', 'Izin Tidak Dibayar', 'Alpha', 'Telat', 'Menit Telat']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['rank'] ?? 0,
            $row['full_name'] ?? '',
            $row['employee_code'] ?? '',
            $row['position'] ?? '',
            $row['department'] ?? '',
            $row['effective_work_days'] ?? 0,
            $row['present_days'] ?? 0,
            $row['approved_leave_days'] ?? 0,
            $row['unpaid_leave_days'] ?? 0,
            $row['absent_days'] ?? 0,
            $row['late_days'] ?? 0,
            $row['late_minutes'] ?? 0,
        ]);
    }

    echo "\nTABEL 2 - JAM KERJA, SCORE & EVALUASI\n";
    fputcsv($out, ['Rank', 'Nama', 'Kode', 'Jabatan', 'Departemen', 'Pulang Awal', 'Menit Pulang Awal', 'Jam Kerja', 'Target Jam Kerja', 'Lembur', 'Attendance Rate', 'Work Rate', 'Score', 'Status', 'Catatan Meeting']);

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['rank'] ?? 0,
            $row['full_name'] ?? '',
            $row['employee_code'] ?? '',
            $row['position'] ?? '',
            $row['department'] ?? '',
            $row['early_leave_days'] ?? 0,
            $row['early_leave_minutes'] ?? 0,
            round(xlsx_number($row['total_work_minutes'] ?? 0) / 60, 2),
            round(xlsx_number($row['standard_work_minutes'] ?? 0) / 60, 2),
            round(xlsx_number($row['total_overtime_minutes'] ?? 0) / 60, 2),
            $row['attendance_rate'] ?? 0,
            $row['work_rate'] ?? 0,
            $row['score'] ?? 0,
            $row['evaluation_note'] ?? '',
            $row['meeting_note'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

$sheetXml = xlsx_sheet_xml($sheetRows, $mergeCells, $widths, $heights);

xlsx_create_package('hr-analytics-' . date('Ymd-His') . '.xlsx', 'HR Analytics', $sheetXml);
