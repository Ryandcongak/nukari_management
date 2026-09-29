<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payroll_loan_helper.php';

payroll_ensure_loan_policy_schema($pdo);

function slip_money($value)
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function slip_day_number($value): string
{
    return rtrim(rtrim(number_format((float)$value, 2, ',', '.'), '0'), ',');
}

function slip_minutes($minutes)
{
    $minutes = (int)$minutes;
    if ($minutes <= 0) return '-';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0 && $mins > 0) return $hours . 'j ' . $mins . 'm';
    if ($hours > 0) return $hours . 'j';
    return $mins . 'm';
}

function field_value(array $row, string $key, $default = 0)
{
    return array_key_exists($key, $row) && $row[$key] !== null ? $row[$key] : $default;
}

function short_date($date)
{
    if (empty($date)) return '-';
    return date('d/m/Y', strtotime($date));
}

$id = (int)($_GET['id'] ?? 0);
$idsInput = $_GET['ids'] ?? '';
$ids = is_array($idsInput)
    ? array_values(array_filter(array_map('intval', $idsInput), fn($value) => $value > 0))
    : array_values(array_filter(array_map('intval', explode(',', trim((string)$idsInput))), fn($value) => $value > 0));
$ids = array_values(array_unique($ids));
$month = trim($_GET['month'] ?? '');
$printAll = isset($_GET['all']) || isset($_GET['bulk']);

$where = [];
$params = [];

if ($id > 0) {
    $where[] = 'p.id = :id';
    $params[':id'] = $id;
} elseif ($ids) {
    $placeholders = [];
    foreach ($ids as $i => $payrollId) {
        $ph = ':id' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $payrollId;
    }
    $where[] = 'p.id IN (' . implode(',', $placeholders) . ')';
    if ($month !== '') {
        $where[] = 'p.payroll_month = :selected_month';
        $params[':selected_month'] = $month;
    }
} elseif ($month !== '' && $printAll) {
    $where[] = 'p.payroll_month = :month';
    $params[':month'] = $month;
} else {
    header('Location: ' . base_url('pages/payroll/index.php'));
    exit;
}

$sql = "
    SELECT
        p.*,
        e.full_name,
        e.employee_code,
        e.fingerprint_id,
        e.position,
        e.department,
        e.salary_type,
        e.salary_amount,
        e.join_date,
        COALESCE((
            SELECT SUM(el.remaining_amount)
            FROM employee_loans el
            WHERE el.employee_id = e.id
              AND el.status = 'active'
        ), 0) AS active_loan_remaining,
        COALESCE((
            SELECT SUM(el.loan_amount)
            FROM employee_loans el
            WHERE el.employee_id = e.id
              AND el.status <> 'cancelled'
              AND (el.loan_date <= p.period_end OR EXISTS (
                  SELECT 1 FROM employee_loan_payments period_lp
                  WHERE period_lp.loan_id = el.id
                    AND ((period_lp.period_year * 100) + period_lp.period_month) <= CAST(REPLACE(p.payroll_month, '-', '') AS UNSIGNED)
              ))
        ), 0) AS total_loan_amount,
        COALESCE((
            SELECT SUM(lp.payment_amount)
            FROM employee_loan_payments lp
            WHERE lp.employee_id = e.id
              AND ((lp.period_year * 100) + lp.period_month) <= CAST(REPLACE(p.payroll_month, '-', '') AS UNSIGNED)
        ), 0) + COALESCE((
            SELECT SUM(el.opening_paid_amount)
            FROM employee_loans el
            WHERE el.employee_id = e.id
              AND el.status <> 'cancelled'
              AND (el.loan_date <= p.period_end OR EXISTS (
                  SELECT 1 FROM employee_loan_payments opening_lp
                  WHERE opening_lp.loan_id = el.id
                    AND ((opening_lp.period_year * 100) + opening_lp.period_month) <= CAST(REPLACE(p.payroll_month, '-', '') AS UNSIGNED)
              ))
        ), 0) AS total_loan_paid_until_period,
        COALESCE((
            SELECT COUNT(*)
            FROM employee_loan_payments lp
            WHERE lp.employee_id = e.id
              AND ((lp.period_year * 100) + lp.period_month) <= CAST(REPLACE(p.payroll_month, '-', '') AS UNSIGNED)
        ), 0) AS total_loan_payment_count
    FROM payrolls p
    JOIN employees e ON p.employee_id = e.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.full_name ASC, p.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$payrolls) {
    header('Location: ' . base_url('pages/payroll/index.php'));
    exit;
}

$pageTitle = count($payrolls) === 1
    ? 'Slip Gaji - ' . $payrolls[0]['full_name']
    : 'Slip Gaji Bulk - ' . ($month ?: 'Payroll');

$currentMonth = $month !== '' ? $month : ($payrolls[0]['payroll_month'] ?? date('Y-m'));
$backUrl = base_url('pages/payroll/index.php' . ($currentMonth ? '?month=' . urlencode($currentMonth) : ''));
$slipCount = count($payrolls);
$paper = strtolower(trim((string)($_GET['paper'] ?? 'a4')));
if (!in_array($paper, ['a4', 'f4'], true)) {
    $paper = 'a4';
}
$paperLabel = strtoupper($paper);
$paperHeight = $paper === 'f4' ? '330mm' : '297mm';
$pageRuleSize = $paper === 'f4' ? '210mm 330mm' : 'A4 portrait';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --slip-paper-width: 210mm;
            --slip-paper-height: <?= $paperHeight; ?>;
        }

        body {
            margin: 0;
            padding: 18px;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Arial, sans-serif;
            background: #eef2f5;
            color: #111827;
        }

        .actions {
            max-width: 210mm;
            margin: 0 auto 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
            align-items: center;
        }

        .paper-picker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-right: auto;
            font-size: 13px;
            color: #374151;
        }

        .paper-picker select {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #111827;
            padding: 8px 30px 8px 10px;
            font: inherit;
        }

        .btn {
            border: 0;
            border-radius: 8px;
            padding: 9px 14px;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            display: inline-block;
        }

        .btn-back {
            background: #e5e7eb;
            color: #111827;
        }

        .btn-print {
            background: #0f5132;
            color: #fff;
        }

        .hint {
            max-width: 210mm;
            margin: 0 auto 12px;
            font-size: 12px;
            color: #4b5563;
            text-align: right;
        }

        .page {
            width: var(--slip-paper-width);
            height: var(--slip-paper-height);
            min-height: var(--slip-paper-height);
            margin: 0 auto 14px;
            padding: 10mm;
            background: #fff;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 7mm;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .08);
            page-break-after: always;
        }

        .page:last-child {
            page-break-after: auto;
        }

        .slip-card {
            border: 1.5px dashed #6b7280;
            border-radius: 10px;
            padding: 8mm;
            height: 100%;
            overflow: hidden;
            position: relative;
        }

        .slip-card-inner {
            display: flex;
            flex-direction: column;
            gap: 5px;
            transform-origin: top left;
        }

        .slip-card.empty {
            border-color: #cbd5e1;
        }

        .slip-header {
            display: grid;
            grid-template-columns: 1.2fr .9fr;
            gap: 6px;
            align-items: start;
            border-bottom: 2px solid #0f5132;
            padding-bottom: 5px;
            margin-bottom: 4px;
        }

        .company-name {
            font-size: 15px;
            line-height: 1.1;
            font-weight: 800;
            color: #0f5132;
            margin-bottom: 2px;
        }

        .company-address {
            font-size: 9px;
            color: #4b5563;
        }

        .slip-title {
            text-align: right;
        }

        .slip-title strong {
            font-size: 14px;
            display: block;
        }

        .slip-title span {
            font-size: 9px;
            color: #374151;
            font-weight: 700;
            display: block;
            line-height: 1.25;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }

        .box {
            border: 1px solid #cfd8e3;
            border-radius: 7px;
            padding: 6px;
            min-height: 0;
        }

        .box h4 {
            margin: 0 0 4px;
            font-size: 10px;
            color: #0f5132;
        }

        .line {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            font-size: 9.2px;
            line-height: 1.25;
            margin: 2px 0;
        }

        .label {
            color: #374151;
        }

        .value {
            font-weight: 700;
            text-align: right;
            overflow-wrap: anywhere;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.7px;
            line-height: 1.15;
        }

        td {
            border: 1px solid #cfd8e3;
            padding: 3px 4px;
            vertical-align: top;
        }

        .text-right {
            text-align: right;
        }

        .text-success {
            color: #079447;
            font-weight: 800;
        }

        .text-danger {
            color: #c1121f;
            font-weight: 800;
        }

        .summary {
            border: 1.5px solid #0f5132;
            border-radius: 7px;
            overflow: hidden;
            margin-top: 2px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 4px 6px;
            border-bottom: 1px solid #d1d5db;
            font-size: 9.5px;
        }

        .summary-row:last-child {
            border-bottom: 0;
            background: #0f5132;
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            padding: 6px;
        }

        .notes {
            font-size: 7.7px;
            line-height: 1.15;
            color: #4b5563;
            text-align: center;
            max-height: 18px;
            overflow: hidden;
            margin-top: 2px;
        }

        .ttd {
            margin-bottom: 22px;
            padding-bottom: 3px;
        }

        .signature {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 5px;
            font-size: 8.5px;
            text-align: center;
        }

        .sign-line {
            border-top: 1px solid #111827;
            margin-top: 3px;
            padding-top: 3px;
            font-weight: 700;
        }

        .cut-line {
            position: absolute;
            right: 7px;
            bottom: 5px;
            font-size: 7.5px;
            color: #94a3b8;
        }

        @media print {
            @page {
                size: <?= $pageRuleSize; ?>;
                margin: 0;
            }

            body {
                background: #fff;
                padding: 0;
            }

            .actions,
            .hint {
                display: none;
            }

            .page {
                width: var(--slip-paper-width);
                height: var(--slip-paper-height);
                min-height: var(--slip-paper-height);
                margin: 0;
                padding: 8mm;
                box-shadow: none;
                gap: 6mm;
                page-break-after: always;
            }

            .page:last-child {
                page-break-after: auto;
            }

            .slip-card {
                padding: 6mm;
                border-color: #111827;
            }

            .slip-card.empty {
                border-color: #d1d5db;
            }
        }
    </style>
</head>

<body>
    <div class="actions">
        <label class="paper-picker" for="paperSize">
            <span>Ukuran kertas</span>
            <select id="paperSize" aria-label="Ukuran kertas slip gaji">
                <option value="a4" <?= $paper === 'a4' ? 'selected' : ''; ?>>A4 (210 × 297 mm)</option>
                <option value="f4" <?= $paper === 'f4' ? 'selected' : ''; ?>>F4 (210 × 330 mm)</option>
            </select>
        </label>
        <a href="<?= htmlspecialchars($backUrl); ?>" class="btn btn-back">Kembali</a>
        <button type="button" onclick="printPayrollSlips()" class="btn btn-print">Cetak Slip</button>
    </div>
    <div class="hint">
        Periode slip: <strong><?= htmlspecialchars($currentMonth); ?></strong> · Kertas: <strong><?= htmlspecialchars($paperLabel); ?></strong> · Portrait · 4 slip per halaman.
        <?= $slipCount === 1 ? 'Menampilkan 1 slip gaji.' : 'Menampilkan ' . $slipCount . ' slip gaji terpilih.'; ?>
    </div>

    <?php foreach (array_chunk($payrolls, 4) as $chunk): ?>
        <div class="page">
            <?php for ($i = 0; $i < 4; $i++): ?>
                <?php if (!isset($chunk[$i])): ?>
                    <div class="slip-card empty"></div>
                    <?php continue; ?>
                <?php endif; ?>

                <?php
                $p = $chunk[$i];
                $totalBpjs = (float)field_value($p, 'bpjs_health') + (float)field_value($p, 'bpjs_employment');
                $additionalAmount = (float)field_value($p, 'additional_amount');
                $additionalDetails = json_decode((string)field_value($p, 'additional_details', '[]'), true);
                if (!is_array($additionalDetails)) $additionalDetails = [];
                $totalIncome = (float)field_value($p, 'base_salary') + (float)field_value($p, 'total_allowance') + (float)field_value($p, 'total_bonus') + (float)field_value($p, 'overtime_amount') + $additionalAmount;
                $loanDeduction = (float)field_value($p, 'loan_deduction');
                $activeLoanRemaining = (float)field_value($p, 'active_loan_remaining');
                // Snapshot lama yang sempat tersimpan tanpa total loan dianggap
                // tidak valid dan memakai query periode sebagai fallback.
                $hasLoanSnapshot = (float)field_value($p, 'loan_total_snapshot') > 0;
                if ($hasLoanSnapshot && (float)field_value($p, 'loan_remaining_snapshot') <= 0 && $activeLoanRemaining > 0) {
                    $hasLoanSnapshot = false;
                }
                $totalLoanAmount = $hasLoanSnapshot ? (float)field_value($p, 'loan_total_snapshot') : (float)field_value($p, 'total_loan_amount');
                $totalLoanPaidUntilPeriod = $hasLoanSnapshot ? (float)field_value($p, 'loan_paid_snapshot') : (float)field_value($p, 'total_loan_paid_until_period');
                $totalLoanPaymentCount = $hasLoanSnapshot ? (int)field_value($p, 'loan_payment_count_snapshot') : (int)field_value($p, 'total_loan_payment_count');
                $loanRemainingAfterDeduction = $hasLoanSnapshot ? (float)field_value($p, 'loan_remaining_snapshot') : max(0, $totalLoanAmount - $totalLoanPaidUntilPeriod);
                $cashAdvance = (float)field_value($p, 'cash_advance_deduction');
                $unpaidLeaveDeduction = (float)field_value($p, 'unpaid_leave_deduction');
                $totalTax = (float)field_value($p, 'total_tax');
                $manualDeduction = max(0, (float)field_value($p, 'total_deduction') - $unpaidLeaveDeduction - $cashAdvance - $loanDeduction);
                $totalDeduction = (float)field_value($p, 'total_deduction') + $totalTax + $totalBpjs;
                $overtimeText = slip_minutes(field_value($p, 'total_overtime_minutes'));
                ?>

                <div class="slip-card">
                    <div class="slip-card-inner">
                    <div class="slip-header">
                        <div>
                            <div class="company-name">PT Nukari Kriya Raya</div>
                            <div class="company-address">Serangan, Denpasar, Bali</div>
                        </div>
                        <div class="slip-title">
                            <strong>SLIP GAJI</strong>
                            <span><?= short_date($p['period_start']); ?> - <?= short_date($p['period_end']); ?></span>
                            <span><?= htmlspecialchars($p['payroll_month']); ?> | <?= strtoupper(htmlspecialchars($p['status'])); ?></span>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="box">
                            <h4>Karyawan</h4>
                            <div class="line"><span class="label">Nama</span><span class="value"><?= htmlspecialchars($p['full_name']); ?></span></div>
                            <div class="line"><span class="label">Kode</span><span class="value"><?= htmlspecialchars($p['employee_code'] ?? '-'); ?></span></div>
                            <div class="line"><span class="label">Jabatan</span><span class="value"><?= htmlspecialchars($p['position'] ?? '-'); ?></span></div>
                            <div class="line"><span class="label">Dept.</span><span class="value"><?= htmlspecialchars($p['department'] ?? '-'); ?></span></div>
                        </div>

                        <div class="box">
                            <h4>Absensi</h4>
                            <div class="line"><span class="label">Hadir</span><span class="value"><?= slip_day_number(field_value($p, 'total_present_days')); ?> hari</span></div>
                            <?php if ((float)field_value($p, 'overtime_amount') > 0): ?>
                                <div class="line"><span class="label">Lembur</span><span class="value"><?= $overtimeText; ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="box">
                            <h4>Pendapatan</h4>
                            <table>
                                <tr>
                                    <td>Gaji Pokok</td>
                                    <td class="text-right"><?= slip_money(field_value($p, 'base_salary')); ?></td>
                                </tr>
                                <?php if ((float)field_value($p, 'project_worker_amount') > 0): ?>
                                    <tr>
                                        <td>Workshop</td>
                                        <td class="text-right"><?= (int)field_value($p, 'workshop_days'); ?> hr · <?= slip_money(field_value($p, 'workshop_amount')); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Project</td>
                                        <td class="text-right"><?= slip_day_number(field_value($p, 'project_work_days')); ?> hr · <?= slip_money(field_value($p, 'project_worker_amount')); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td>Tunjangan</td>
                                    <td class="text-right"><?= slip_money(field_value($p, 'total_allowance')); ?></td>
                                </tr>
                                <tr>
                                    <td>Bonus</td>
                                    <td class="text-right"><?= slip_money(field_value($p, 'total_bonus')); ?></td>
                                </tr>
                                <?php if ((float)field_value($p, 'overtime_amount') > 0): ?>
                                    <tr>
                                        <td>Lembur</td>
                                        <td class="text-right"><?= slip_money(field_value($p, 'overtime_amount')); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td>Tambahan</td>
                                    <td class="text-right"><?= slip_money($additionalAmount); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Total</strong></td>
                                    <td class="text-right text-success"><?= slip_money($totalIncome); ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="box">
                            <h4>Potongan</h4>
                            <table>
                                <tr>
                                    <td>Kasbon</td>
                                    <td class="text-right text-danger"><?= slip_money($cashAdvance); ?></td>
                                </tr>
                                <?php if ($totalLoanAmount > 0 || $loanDeduction > 0): ?>
                                    <tr>
                                        <td>Cicilan Loan</td>
                                        <td class="text-right text-danger"><?= slip_money($loanDeduction); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Pinjaman Awal</td>
                                        <td class="text-right"><?= slip_money($totalLoanAmount); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Sudah Dibayar</td>
                                        <td class="text-right text-success"><?= slip_money($totalLoanPaidUntilPeriod); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Sisa Loan</td>
                                        <td class="text-right"><strong><?= slip_money($loanRemainingAfterDeduction); ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td>Jumlah Cicilan</td>
                                        <td class="text-right"><?= $totalLoanPaymentCount; ?>x</td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="box">
                            <h4>Detail Tambahan</h4>
                            <?php if ($additionalDetails): ?>
                                <?php foreach ($additionalDetails as $detail): ?>
                                    <div class="line"><span class="label"><?= htmlspecialchars($detail['addition_name'] ?? 'Tambahan'); ?></span><span class="value"><?= (int)($detail['date_count'] ?? 0); ?>x</span></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="line"><span class="label">Tidak ada tambahan</span><span class="value">-</span></div>
                            <?php endif; ?>
                        </div>
                        <div></div>
                    </div>

                    <div class="summary">
                        <div class="summary-row"><span>Total Pendapatan</span><strong><?= slip_money($totalIncome); ?></strong></div>
                        <div class="summary-row"><span>Total Potongan</span><strong><?= slip_money($totalDeduction); ?></strong></div>
                        <div class="summary-row"><span>Gaji Bersih</span><span><?= slip_money(field_value($p, 'net_salary')); ?></span></div>
                    </div>

                    <div class="notes">
                        Catatan: Slip gaji ini dibuat sebagai bukti pembayaran gaji dan komponen penghasilan lainnya untuk periode yang tercantum.
                    </div>

                    <div class="signature">
                        <div>
                            <div class="ttd">Diketahui,</div>

                            <!-- Nama -->
                            <div><strong>I Wayan Darmaja</strong></div>

                            <!-- Garis tanda tangan -->
                            <div class="sign-line"></div>

                            <!-- Jabatan -->
                            <div>Direktur</div>
                        </div>
                        <div>
                            <div class="ttd">Diterima oleh,</div>
                            <div class="sign-line"><?= htmlspecialchars($p['full_name']); ?></div>
                        </div>
                    </div>
                    </div>
                    <div class="cut-line">Garis potong</div>
                </div>
            <?php endfor; ?>
        </div>
    <?php endforeach; ?>
    <script>
        function availableCardHeight(card) {
            const style = window.getComputedStyle(card);
            const printableHeight = card.clientHeight
                - parseFloat(style.paddingTop || 0)
                - parseFloat(style.paddingBottom || 0);
            // Sisakan ruang aman untuk pembulatan layout browser/printer dan
            // baris tanda tangan paling bawah agar tidak menyentuh garis potong.
            return Math.max(0, printableHeight - 12);
        }

        function fitSlipCard(card) {
            const inner = card.querySelector('.slip-card-inner');
            if (!inner) return;

            inner.style.width = '100%';
            inner.style.transform = 'none';

            const availableHeight = availableCardHeight(card);
            if (availableHeight <= 0 || inner.scrollHeight <= availableHeight + 1) return;

            let low = 0.35;
            let high = 1;
            let best = low;

            for (let i = 0; i < 14; i++) {
                const scale = (low + high) / 2;
                inner.style.width = `${100 / scale}%`;
                const fits = inner.scrollHeight * scale <= availableHeight + 0.5;

                if (fits) {
                    best = scale;
                    low = scale;
                } else {
                    high = scale;
                }
            }

            inner.style.width = `${100 / best}%`;
            const renderedHeight = inner.scrollHeight * best;
            if (renderedHeight > availableHeight) {
                best *= availableHeight / renderedHeight;
                inner.style.width = `${100 / best}%`;
            }
            inner.style.transform = `scale(${best})`;
        }

        function fitAllSlipCards() {
            document.querySelectorAll('.slip-card:not(.empty)').forEach(fitSlipCard);
        }

        function printPayrollSlips() {
            fitAllSlipCards();
            window.print();
        }

        document.getElementById('paperSize').addEventListener('change', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('paper', this.value);
            window.location.href = url.toString();
        });

        window.addEventListener('load', function () {
            requestAnimationFrame(fitAllSlipCards);
        });
        window.addEventListener('resize', fitAllSlipCards);
        window.addEventListener('beforeprint', fitAllSlipCards);
    </script>
</body>

</html>
