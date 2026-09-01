<?php
require_once __DIR__ . '/../../includes/auth.php';

$month = $_GET['month'] ?? date('Y-m');
$keyword = trim($_GET['keyword'] ?? '');
$statusFilter = $_GET['status'] ?? '';

function export_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column_name");
    $stmt->execute([':column_name' => $column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function xlsx_xml($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_col(int $index): string
{
    $letters = '';
    while ($index > 0) {
        $index--;
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intdiv($index, 26);
    }
    return $letters;
}

function xlsx_cell(string $cell, $value, int $style = 0, string $type = 's'): string
{
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';

    if ($type === 'n') {
        $num = is_numeric($value) ? (float)$value : 0;
        return '<c r="' . $cell . '"' . $styleAttr . '><v>' . $num . '</v></c>';
    }

    return '<c r="' . $cell . '" t="inlineStr"' . $styleAttr . '><is><t>' . xlsx_xml($value) . '</t></is></c>';
}

function xlsx_row(int $rowNumber, array $cells, ?float $height = null): string
{
    $heightAttr = $height !== null ? ' ht="' . $height . '" customHeight="1"' : '';
    return '<row r="' . $rowNumber . '"' . $heightAttr . '>' . implode('', $cells) . '</row>';
}

function minutes_to_hour($minutes): string
{
    $minutes = (int)$minutes;
    if ($minutes <= 0) return '-';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0 && $mins > 0) return $hours . ' jam ' . $mins . ' mnt';
    if ($hours > 0) return $hours . ' jam';
    return $mins . ' mnt';
}

function status_label($status): string
{
    $status = strtolower((string)$status);
    if ($status === 'paid') return 'PAID';
    if ($status === 'approved') return 'APPROVED';
    if ($status === 'draft') return 'DRAFT';
    if ($status === 'unpaid') return 'UNPAID';
    return strtoupper($status ?: 'DRAFT');
}

function loan_status_label($status): string
{
    $status = strtolower((string)$status);
    if ($status === 'paid') return 'LUNAS';
    if ($status === 'active') return 'AKTIF';
    if ($status === 'cancelled') return 'DIBATALKAN';
    return strtoupper($status ?: '-');
}

function safe_money_value($value): float
{
    return round((float)($value ?? 0), 2);
}

if (!class_exists('ZipArchive')) {
    die('Export XLSX membutuhkan PHP extension ZipArchive. Aktifkan extension zip di MAMP/PHP.');
}

$hasCashAdvanceDeduction = export_column_exists($pdo, 'payrolls', 'cash_advance_deduction');
$hasLoanDeduction = export_column_exists($pdo, 'payrolls', 'loan_deduction');
$hasUnpaidLeaveDeduction = export_column_exists($pdo, 'payrolls', 'unpaid_leave_deduction');
$hasManualBonus = export_column_exists($pdo, 'payrolls', 'manual_bonus');
$hasManualDeduction = export_column_exists($pdo, 'payrolls', 'manual_deduction');

$where = ['p.payroll_month = :month'];
$params = [':month' => $month];

if ($keyword !== '') {
    $where[] = "(e.full_name LIKE :keyword OR e.employee_code LIKE :keyword OR e.position LIKE :keyword OR e.department LIKE :keyword)";
    $params[':keyword'] = '%' . $keyword . '%';
}

if ($statusFilter !== '') {
    $where[] = "p.status = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);
$cashField = $hasCashAdvanceDeduction ? 'COALESCE(p.cash_advance_deduction, 0)' : 'COALESCE(ca.cash_advance_total, 0)';
$loanField = $hasLoanDeduction ? 'COALESCE(p.loan_deduction, 0)' : '0';
$unpaidField = $hasUnpaidLeaveDeduction ? 'COALESCE(p.unpaid_leave_deduction, 0)' : '0';
$manualBonusField = $hasManualBonus ? 'COALESCE(p.manual_bonus, 0)' : '0';
$manualDeductionField = $hasManualDeduction ? 'COALESCE(p.manual_deduction, 0)' : '0';

$stmt = $pdo->prepare("
    SELECT
        p.*,
        e.full_name,
        e.employee_code,
        e.position,
        e.department,
        e.salary_type,
        e.salary_amount,
        COALESCE(ca.cash_advance_total, 0) AS cash_advance_total,
        {$cashField} AS export_cash_advance_deduction,
        {$loanField} AS export_loan_deduction,
        {$unpaidField} AS export_unpaid_leave_deduction,
        {$manualBonusField} AS export_manual_bonus,
        {$manualDeductionField} AS export_manual_adjustment_deduction,
        COALESCE(loan_summary.total_loan_amount, 0) AS total_loan_amount,
        COALESCE(loan_summary.total_loan_paid, 0) AS total_loan_paid,
        COALESCE(loan_summary.total_loan_remaining, 0) AS total_loan_remaining,
        COALESCE(loan_summary.loan_payment_count, 0) AS loan_payment_count,
        COALESCE(loan_summary.active_loan_count, 0) AS active_loan_count,
        loan_summary.loan_status AS loan_status
    FROM payrolls p
    JOIN employees e ON e.id = p.employee_id
    LEFT JOIN (
        SELECT employee_id, deduction_month, SUM(remaining_amount) AS cash_advance_total
        FROM employee_cash_advances
        WHERE status = 'active'
        GROUP BY employee_id, deduction_month
    ) ca ON ca.employee_id = p.employee_id AND ca.deduction_month = p.payroll_month
    LEFT JOIN (
        SELECT
            l.employee_id,
            SUM(l.loan_amount) AS total_loan_amount,
            SUM(l.total_paid) AS total_loan_paid,
            SUM(l.remaining_amount) AS total_loan_remaining,
            SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_loan_count,
            CASE
                WHEN SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) > 0 THEN 'active'
                WHEN SUM(CASE WHEN l.status = 'paid' THEN 1 ELSE 0 END) > 0 THEN 'paid'
                ELSE MAX(l.status)
            END AS loan_status,
            COALESCE(lp.payment_count, 0) AS loan_payment_count
        FROM employee_loans l
        LEFT JOIN (
            SELECT employee_id, COUNT(*) AS payment_count
            FROM employee_loan_payments
            GROUP BY employee_id
        ) lp ON lp.employee_id = l.employee_id
        GROUP BY l.employee_id, lp.payment_count
    ) loan_summary ON loan_summary.employee_id = p.employee_id
    WHERE {$whereSql}
    ORDER BY e.full_name ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$normalizedRows = [];
$summary = [
    'count' => count($rows),
    'base_salary' => 0,
    'allowance_bonus' => 0,
    'manual_bonus' => 0,
    'overtime_amount' => 0,
    'gross_income' => 0,
    'cash_advance' => 0,
    'loan' => 0,
    'unpaid_leave' => 0,
    'manual_other_deduction' => 0,
    'total_payroll_deduction' => 0,
    'tax' => 0,
    'bpjs_health' => 0,
    'bpjs_employment' => 0,
    'bpjs_tax_total' => 0,
    'grand_total_deduction' => 0,
    'net_salary' => 0,
    'loan_amount' => 0,
    'loan_paid' => 0,
    'loan_remaining' => 0,
    'paid' => 0,
    'draft' => 0,
];

foreach ($rows as $row) {
    $baseSalary = safe_money_value($row['base_salary'] ?? 0);
    $allowanceBonus = safe_money_value($row['total_allowance'] ?? 0) + safe_money_value($row['total_bonus'] ?? 0);
    $manualBonus = safe_money_value($row['export_manual_bonus'] ?? 0);
    $overtimeAmount = safe_money_value($row['overtime_amount'] ?? 0);
    $grossIncome = $baseSalary + $allowanceBonus + $manualBonus + $overtimeAmount;

    $cashAdvance = safe_money_value($row['export_cash_advance_deduction'] ?? 0);
    $loanDeduction = safe_money_value($row['export_loan_deduction'] ?? 0);
    $unpaidLeaveDeduction = safe_money_value($row['export_unpaid_leave_deduction'] ?? 0);
    $totalPayrollDeduction = safe_money_value($row['total_deduction'] ?? 0);

    // IMPORTANT:
    // total_deduction dari payrolls sudah termasuk kasbon, cicilan loan, unpaid leave, dan potongan manual/lain.
    // Karena itu export tidak boleh menjumlahkan kasbon/loan ke total_deduction lagi.
    // Potongan manual/lain dihitung sebagai sisa breakdown agar tidak double deduction.
    $manualOtherDeduction = $totalPayrollDeduction - $cashAdvance - $loanDeduction - $unpaidLeaveDeduction;
    if (abs($manualOtherDeduction) < 0.01) {
        $manualOtherDeduction = 0;
    }
    $manualOtherDeduction = max(0, round($manualOtherDeduction, 2));

    // Jika ada adjustment deduction tersendiri, masukkan hanya sebagai informasi pembentuk potongan lain,
    // bukan dijumlahkan ulang ke total_deduction.
    $manualAdjustmentDeduction = safe_money_value($row['export_manual_adjustment_deduction'] ?? 0);

    $bpjsHealth = safe_money_value($row['bpjs_health'] ?? 0);
    $bpjsEmployment = safe_money_value($row['bpjs_employment'] ?? 0);
    $tax = safe_money_value($row['total_tax'] ?? 0);
    $bpjsTaxTotal = $bpjsHealth + $bpjsEmployment + $tax;
    $grandTotalDeduction = $totalPayrollDeduction + $bpjsTaxTotal;

    $netSalary = safe_money_value($row['net_salary'] ?? 0);

    $normalized = $row;
    $normalized['export_base_salary'] = $baseSalary;
    $normalized['export_allowance_bonus'] = $allowanceBonus;
    $normalized['export_manual_bonus_clean'] = $manualBonus;
    $normalized['export_overtime_amount'] = $overtimeAmount;
    $normalized['export_gross_income'] = $grossIncome;
    $normalized['export_cash_advance_clean'] = $cashAdvance;
    $normalized['export_loan_deduction_clean'] = $loanDeduction;
    $normalized['export_unpaid_leave_clean'] = $unpaidLeaveDeduction;
    $normalized['export_manual_other_deduction'] = $manualOtherDeduction;
    $normalized['export_manual_adjustment_deduction_clean'] = $manualAdjustmentDeduction;
    $normalized['export_total_payroll_deduction'] = $totalPayrollDeduction;
    $normalized['export_bpjs_tax_total'] = $bpjsTaxTotal;
    $normalized['export_grand_total_deduction'] = $grandTotalDeduction;
    $normalized['export_net_salary_clean'] = $netSalary;
    $normalizedRows[] = $normalized;

    $summary['base_salary'] += $baseSalary;
    $summary['allowance_bonus'] += $allowanceBonus;
    $summary['manual_bonus'] += $manualBonus;
    $summary['overtime_amount'] += $overtimeAmount;
    $summary['gross_income'] += $grossIncome;
    $summary['cash_advance'] += $cashAdvance;
    $summary['loan'] += $loanDeduction;
    $summary['unpaid_leave'] += $unpaidLeaveDeduction;
    $summary['manual_other_deduction'] += $manualOtherDeduction;
    $summary['total_payroll_deduction'] += $totalPayrollDeduction;
    $summary['tax'] += $tax;
    $summary['bpjs_health'] += $bpjsHealth;
    $summary['bpjs_employment'] += $bpjsEmployment;
    $summary['bpjs_tax_total'] += $bpjsTaxTotal;
    $summary['grand_total_deduction'] += $grandTotalDeduction;
    $summary['net_salary'] += $netSalary;
    $summary['loan_amount'] += safe_money_value($row['total_loan_amount'] ?? 0);
    $summary['loan_paid'] += safe_money_value($row['total_loan_paid'] ?? 0);
    $summary['loan_remaining'] += safe_money_value($row['total_loan_remaining'] ?? 0);

    if (($row['status'] ?? '') === 'paid') {
        $summary['paid']++;
    } else {
        $summary['draft']++;
    }
}

$rows = $normalizedRows;
$periodStart = $rows[0]['period_start'] ?? ($month . '-01');
$periodEnd = $rows[0]['period_end'] ?? date('Y-m-t', strtotime($month . '-01'));
$monthLabel = date('F Y', strtotime($month . '-01'));
$fileName = 'Laporan_Payroll_Nukari_' . $month . '.xlsx';

$sheetRows = [];
$mergeCells = [];
$rowNum = 1;
$lastCol = 'Q';

// Style indexes:
// 0 default, 1 title, 2 subtitle, 3 muted, 4 section, 5 summary label, 6 summary money,
// 7 table header, 8 text border, 9 center border, 10 money, 11 money red, 12 money green,
// 13 total label, 14 signature, 15 bold text, 16 orange status, 17 green status, 18 small note

$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'PT NUKARI KRIYA RAYA', 1)], 23);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum++;

$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'LAPORAN PAYROLL KARYAWAN', 2)], 20);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum++;

$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'Periode: ' . $monthLabel . ' | ' . date('d M Y', strtotime($periodStart)) . ' - ' . date('d M Y', strtotime($periodEnd)), 3)], 17);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum++;

$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'Tanggal Cetak: ' . date('d M Y H:i') . ' | Status Filter: ' . ($statusFilter !== '' ? strtoupper($statusFilter) : 'SEMUA'), 3)], 17);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum += 2;

$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'RINGKASAN UTAMA', 4)], 21);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum++;

$summaryLines = [
    ['Total Karyawan', $summary['count'], 'Draft / Paid', $summary['draft'] . ' / ' . $summary['paid'], 'Total Gaji Bersih', $summary['net_salary']],
    ['Total Pendapatan', $summary['gross_income'], 'Total Potongan Payroll', $summary['total_payroll_deduction'], 'BPJS + Pajak', $summary['bpjs_tax_total']],
    ['Kasbon', $summary['cash_advance'], 'Cicilan Loan', $summary['loan'], 'Pot. Manual/Lain', $summary['manual_other_deduction']],
    ['Total Pinjaman', $summary['loan_amount'], 'Sudah Dibayar Loan', $summary['loan_paid'], 'Sisa Pinjaman', $summary['loan_remaining']],
];

foreach ($summaryLines as $line) {
    $cells = [];
    $pairs = [1, 3, 7, 9, 13, 15];
    for ($i = 0; $i < 6; $i += 2) {
        $labelCol = $pairs[$i];
        $valueCol = $pairs[$i + 1];
        $labelCell = xlsx_col($labelCol) . $rowNum;
        $valueCell = xlsx_col($valueCol) . $rowNum;
        $cells[] = xlsx_cell($labelCell, $line[$i], 5);
        $isNumber = is_numeric($line[$i + 1]);
        $cells[] = xlsx_cell($valueCell, $line[$i + 1], $isNumber ? 6 : 5, $isNumber ? 'n' : 's');
        $mergeCells[] = xlsx_col($labelCol) . $rowNum . ':' . xlsx_col($labelCol + 1) . $rowNum;
        $mergeCells[] = xlsx_col($valueCol) . $rowNum . ':' . xlsx_col($valueCol + 3) . $rowNum;
    }
    $sheetRows[] = xlsx_row($rowNum, $cells, 20);
    $rowNum++;
}

$rowNum++;
$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'TABEL 1 - RINGKASAN PAYROLL', 4)], 21);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum++;

$headers1 = [
    'No',
    'Kode',
    'Nama Karyawan',
    'Jabatan',
    'Hari Kerja',
    'Hadir',
    'Lembur',
    'Gaji Pokok',
    'Tunj.+Bonus',
    'Manual Bonus',
    'Upah Lembur',
    'Total Pendapatan',
    'Total Pot. Payroll',
    'BPJS+Pajak',
    'Total Pot. Keseluruhan',
    'Gaji Bersih',
    'Status'
];
$headerCells = [];
foreach ($headers1 as $i => $header) {
    $headerCells[] = xlsx_cell(xlsx_col($i + 1) . $rowNum, $header, 7);
}
$sheetRows[] = xlsx_row($rowNum, $headerCells, 28);
$tableHeaderRow = $rowNum;
$rowNum++;

$dataStartRow = $rowNum;
if ($rows) {
    $no = 1;
    foreach ($rows as $row) {
        $status = status_label($row['status'] ?? 'draft');
        $data = [
            ['v' => $no++, 's' => 9, 't' => 'n'],
            ['v' => $row['employee_code'] ?? '-', 's' => 9],
            ['v' => $row['full_name'] ?? '-', 's' => 15],
            ['v' => $row['position'] ?? '-', 's' => 8],
            ['v' => (int)($row['total_work_days'] ?? 0), 's' => 9, 't' => 'n'],
            ['v' => (int)($row['total_present_days'] ?? 0), 's' => 9, 't' => 'n'],
            ['v' => minutes_to_hour($row['total_overtime_minutes'] ?? 0), 's' => 9],
            ['v' => $row['export_base_salary'], 's' => 10, 't' => 'n'],
            ['v' => $row['export_allowance_bonus'], 's' => 10, 't' => 'n'],
            ['v' => $row['export_manual_bonus_clean'], 's' => 10, 't' => 'n'],
            ['v' => $row['export_overtime_amount'], 's' => 10, 't' => 'n'],
            ['v' => $row['export_gross_income'], 's' => 10, 't' => 'n'],
            ['v' => $row['export_total_payroll_deduction'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_bpjs_tax_total'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_grand_total_deduction'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_net_salary_clean'], 's' => 12, 't' => 'n'],
            ['v' => $status, 's' => $status === 'PAID' ? 17 : 16],
        ];

        $cells = [];
        foreach ($data as $i => $item) {
            $cells[] = xlsx_cell(xlsx_col($i + 1) . $rowNum, $item['v'], $item['s'], $item['t'] ?? 's');
        }
        $sheetRows[] = xlsx_row($rowNum, $cells, 23);
        $rowNum++;
    }

    $totalCells = [xlsx_cell('A' . $rowNum, 'TOTAL', 13)];
    $mergeCells[] = 'A' . $rowNum . ':G' . $rowNum;
    $totalValues = [
        $summary['base_salary'],
        $summary['allowance_bonus'],
        $summary['manual_bonus'],
        $summary['overtime_amount'],
        $summary['gross_income'],
        $summary['total_payroll_deduction'],
        $summary['bpjs_tax_total'],
        $summary['grand_total_deduction'],
        $summary['net_salary'],
    ];
    for ($i = 0; $i < count($totalValues); $i++) {
        $col = 8 + $i;
        $style = ($col >= 13 && $col <= 15) ? 11 : ($col === 16 ? 12 : 10);
        $totalCells[] = xlsx_cell(xlsx_col($col) . $rowNum, $totalValues[$i], $style, 'n');
    }
    $totalCells[] = xlsx_cell('Q' . $rowNum, '', 8);
    $sheetRows[] = xlsx_row($rowNum, $totalCells, 25);
    $rowNum++;
} else {
    $sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'Belum ada data payroll untuk periode ini.', 9)], 24);
    $mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
    $rowNum++;
}
$dataEndRow = max($rowNum - 1, $tableHeaderRow);

$rowNum += 2;
$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'TABEL 2 - DETAIL POTONGAN & PINJAMAN', 4)], 21);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum++;

$headers2 = [
    'No',
    'Kode',
    'Nama Karyawan',
    'Kasbon',
    'Cicilan Loan',
    'Pot. Unpaid Leave',
    'Pot. Manual/Lain',
    'Total Pot. Payroll',
    'BPJS+Pajak',
    'Total Pot. Keseluruhan',
    'Total Pinjaman',
    'Sudah Dibayar',
    'Sisa Pinjaman',
    'Cicilan Ke',
    'Status Loan',
    'Catatan Validasi',
    'Status Payroll'
];
$headerCells2 = [];
foreach ($headers2 as $i => $header) {
    $headerCells2[] = xlsx_cell(xlsx_col($i + 1) . $rowNum, $header, 7);
}
$sheetRows[] = xlsx_row($rowNum, $headerCells2, 28);
$secondTableHeaderRow = $rowNum;
$rowNum++;

if ($rows) {
    $no = 1;
    foreach ($rows as $row) {
        $status = status_label($row['status'] ?? 'draft');
        $loanStatus = loan_status_label($row['loan_status'] ?? '-');
        $calculatedTotal = safe_money_value($row['export_cash_advance_clean'])
            + safe_money_value($row['export_loan_deduction_clean'])
            + safe_money_value($row['export_unpaid_leave_clean'])
            + safe_money_value($row['export_manual_other_deduction']);
        $difference = abs($calculatedTotal - safe_money_value($row['export_total_payroll_deduction']));
        $validationNote = $difference <= 1 ? 'Valid' : 'Cek: breakdown tidak sama dengan total_deduction';

        $data = [
            ['v' => $no++, 's' => 9, 't' => 'n'],
            ['v' => $row['employee_code'] ?? '-', 's' => 9],
            ['v' => $row['full_name'] ?? '-', 's' => 15],
            ['v' => $row['export_cash_advance_clean'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_loan_deduction_clean'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_unpaid_leave_clean'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_manual_other_deduction'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_total_payroll_deduction'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_bpjs_tax_total'], 's' => 11, 't' => 'n'],
            ['v' => $row['export_grand_total_deduction'], 's' => 11, 't' => 'n'],
            ['v' => safe_money_value($row['total_loan_amount'] ?? 0), 's' => 10, 't' => 'n'],
            ['v' => safe_money_value($row['total_loan_paid'] ?? 0), 's' => 10, 't' => 'n'],
            ['v' => safe_money_value($row['total_loan_remaining'] ?? 0), 's' => 11, 't' => 'n'],
            ['v' => ((int)($row['loan_payment_count'] ?? 0)) . ' kali', 's' => 9],
            ['v' => $loanStatus, 's' => $loanStatus === 'LUNAS' ? 17 : 16],
            ['v' => $validationNote, 's' => $validationNote === 'Valid' ? 17 : 16],
            ['v' => $status, 's' => $status === 'PAID' ? 17 : 16],
        ];

        $cells = [];
        foreach ($data as $i => $item) {
            $cells[] = xlsx_cell(xlsx_col($i + 1) . $rowNum, $item['v'], $item['s'], $item['t'] ?? 's');
        }
        $sheetRows[] = xlsx_row($rowNum, $cells, 23);
        $rowNum++;
    }

    $totalCells = [xlsx_cell('A' . $rowNum, 'TOTAL', 13)];
    $mergeCells[] = 'A' . $rowNum . ':C' . $rowNum;
    $totalValues = [
        $summary['cash_advance'],
        $summary['loan'],
        $summary['unpaid_leave'],
        $summary['manual_other_deduction'],
        $summary['total_payroll_deduction'],
        $summary['bpjs_tax_total'],
        $summary['grand_total_deduction'],
        $summary['loan_amount'],
        $summary['loan_paid'],
        $summary['loan_remaining'],
    ];
    for ($i = 0; $i < count($totalValues); $i++) {
        $col = 4 + $i;
        $style = ($col >= 4 && $col <= 10 || $col === 13) ? 11 : 10;
        $totalCells[] = xlsx_cell(xlsx_col($col) . $rowNum, $totalValues[$i], $style, 'n');
    }
    $totalCells[] = xlsx_cell('N' . $rowNum, '', 8);
    $totalCells[] = xlsx_cell('O' . $rowNum, '', 8);
    $totalCells[] = xlsx_cell('P' . $rowNum, '', 8);
    $totalCells[] = xlsx_cell('Q' . $rowNum, '', 8);
    $sheetRows[] = xlsx_row($rowNum, $totalCells, 25);
    $rowNum++;
}
$secondTableEndRow = max($rowNum - 1, $secondTableHeaderRow);

$rowNum += 2;
$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'CATATAN PERHITUNGAN', 4)], 21);
$mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
$rowNum++;
$noteLines = [
    '1. Total Pot. Payroll = Kasbon + Cicilan Loan + Pot. Unpaid Leave + Pot. Manual/Lain. Nilai ini sama dengan kolom total_deduction pada payrolls.',
    '2. BPJS + Pajak ditampilkan terpisah agar tidak tercampur dengan kasbon/loan dan tidak terjadi double deduction.',
    '3. Total Pot. Keseluruhan = Total Pot. Payroll + BPJS + Pajak. Gaji Bersih mengikuti nilai net_salary yang tersimpan saat payroll digenerate.',
];
foreach ($noteLines as $note) {
    $sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, $note, 18)], 18);
    $mergeCells[] = 'A' . $rowNum . ':' . $lastCol . $rowNum;
    $rowNum++;
}

$rowNum += 2;
$sheetRows[] = xlsx_row($rowNum, [
    xlsx_cell('A' . $rowNum, 'Dibuat oleh,', 14),
    xlsx_cell('G' . $rowNum, 'Diperiksa oleh,', 14),
    xlsx_cell('M' . $rowNum, 'Disetujui oleh,', 14),
], 22);
$mergeCells[] = 'A' . $rowNum . ':F' . $rowNum;
$mergeCells[] = 'G' . $rowNum . ':L' . $rowNum;
$mergeCells[] = 'M' . $rowNum . ':Q' . $rowNum;
$rowNum += 5;
$sheetRows[] = xlsx_row($rowNum, [
    xlsx_cell('A' . $rowNum, 'Admin / Finance', 14),
    xlsx_cell('G' . $rowNum, 'HR / Management', 14),
    xlsx_cell('M' . $rowNum, 'Direktur', 14),
], 24);
$mergeCells[] = 'A' . $rowNum . ':F' . $rowNum;
$mergeCells[] = 'G' . $rowNum . ':L' . $rowNum;
$mergeCells[] = 'M' . $rowNum . ':Q' . $rowNum;

$rowNum += 2;
$sheetRows[] = xlsx_row($rowNum, [xlsx_cell('A' . $rowNum, 'Catatan: Laporan ini dicetak otomatis dari sistem payroll PT Nukari Kriya Raya. Periksa kembali data sebelum ditandatangani.', 18)], 18);
$mergeCells[] = 'A' . $rowNum . ':Q' . $rowNum;

$colsXml = '<cols>';
$widths = [5, 9, 22, 18, 8, 8, 11, 13, 13, 13, 13, 14, 14, 13, 15, 14, 10];
foreach ($widths as $i => $width) {
    $col = $i + 1;
    $colsXml .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
}
$colsXml .= '</cols>';

$mergeXml = '';
if ($mergeCells) {
    $mergeXml = '<mergeCells count="' . count($mergeCells) . '">';
    foreach ($mergeCells as $ref) {
        $mergeXml .= '<mergeCell ref="' . $ref . '"/>';
    }
    $mergeXml .= '</mergeCells>';
}

$autoFilterXml = '<autoFilter ref="A' . $tableHeaderRow . ':' . $lastCol . $dataEndRow . '"/>';
$autoFilterXml .= '<autoFilter ref="A' . $secondTableHeaderRow . ':' . $lastCol . $secondTableEndRow . '"/>';

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>
    <dimension ref="A1:' . $lastCol . $rowNum . '"/>
    <sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="' . $tableHeaderRow . '" topLeftCell="A' . ($tableHeaderRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
    ' . $colsXml . '
    <sheetData>' . implode('', $sheetRows) . '</sheetData>
    ' . $autoFilterXml . '
    ' . $mergeXml . '
    <printOptions horizontalCentered="1"/>
    <pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>
    <pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>
</worksheet>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0"/></numFmts>
    <fonts count="10">
        <font><sz val="10"/><color rgb="FF111827"/><name val="Arial"/></font>
        <font><b/><sz val="15"/><color rgb="FF064E3B"/><name val="Arial"/></font>
        <font><b/><sz val="12"/><color rgb="FF111827"/><name val="Arial"/></font>
        <font><sz val="10"/><color rgb="FF6B7280"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FF111827"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FFDC2626"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FF047857"/><name val="Arial"/></font>
        <font><b/><sz val="10"/><color rgb="FFD97706"/><name val="Arial"/></font>
        <font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Arial"/></font>
    </fonts>
    <fills count="8">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFEFF6F2"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF064E3B"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFFFF7ED"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="4">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right><top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>
        <border><left style="thin"><color rgb="FF064E3B"/></left><right style="thin"><color rgb="FF064E3B"/></right><top style="thin"><color rgb="FF064E3B"/></top><bottom style="thin"><color rgb="FF064E3B"/></bottom><diagonal/></border>
        <border><bottom style="thin"><color rgb="FF111827"/></bottom></border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="19">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="164" fontId="5" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="0" fontId="4" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="164" fontId="6" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="164" fontId="7" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="0" fontId="5" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="0" fontId="5" fillId="0" borderId="3" xfId="0" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="8" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="7" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="9" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="left"/></xf>
    </cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <definedNames>
        <definedName name="_xlnm.Print_Titles" localSheetId="0">Payroll!$' . $tableHeaderRow . ':$' . $tableHeaderRow . '</definedName>
    </definedNames>
    <sheets><sheet name="Payroll" sheetId="1" r:id="rId1"/></sheets>
</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

$tmpFile = tempnam(sys_get_temp_dir(), 'payroll_xlsx_');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    die('Gagal membuat file export XLSX.');
}

$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $stylesXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');
header('Pragma: public');

readfile($tmpFile);
unlink($tmpFile);
exit;
