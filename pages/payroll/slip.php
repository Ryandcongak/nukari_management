<?php
require_once __DIR__ . '/../../includes/auth.php';

function slip_money($value)
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
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
$idsParam = trim($_GET['ids'] ?? '');
$month = trim($_GET['month'] ?? '');
$printAll = isset($_GET['all']) || isset($_GET['bulk']);

$where = [];
$params = [];

if ($id > 0) {
    $where[] = 'p.id = :id';
    $params[':id'] = $id;
} elseif ($idsParam !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam)), fn($v) => $v > 0));
    if (!$ids) {
        header('Location: ' . base_url('pages/payroll/index.php'));
        exit;
    }
    $placeholders = [];
    foreach ($ids as $i => $payrollId) {
        $ph = ':id' . $i;
        $placeholders[] = $ph;
        $params[$ph] = $payrollId;
    }
    $where[] = 'p.id IN (' . implode(',', $placeholders) . ')';
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
              AND el.loan_date <= p.period_end
        ), 0) AS total_loan_amount,
        COALESCE((
            SELECT SUM(lp.payment_amount)
            FROM employee_loan_payments lp
            WHERE lp.employee_id = e.id
              AND lp.payment_date <= p.period_end
        ), 0) AS total_loan_paid_until_period,
        COALESCE((
            SELECT COUNT(*)
            FROM employee_loan_payments lp
            WHERE lp.employee_id = e.id
              AND lp.payment_date <= p.period_end
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
$printAllUrl = base_url('pages/payroll/slip.php?month=' . urlencode($currentMonth) . '&all=1');
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

        body {
            margin: 0;
            padding: 18px;
            font-family: Arial, sans-serif;
            background: #eef2f5;
            color: #111827;
        }

        .actions {
            max-width: 210mm;
            margin: 0 auto 12px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            align-items: center;
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

        .btn-all {
            background: #1f2937;
            color: #fff;
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
            width: 210mm;
            min-height: 297mm;
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
            display: flex;
            flex-direction: column;
            gap: 5px;
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
                size: A4 portrait;
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
                width: 210mm;
                height: 297mm;
                min-height: 297mm;
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
        <a href="<?= htmlspecialchars($backUrl); ?>" class="btn btn-back">Kembali</a>

        <?php if (!$printAll): ?>
            <a href="<?= htmlspecialchars($printAllUrl); ?>" class="btn btn-all">Cetak Semua Slip Periode Ini</a>
        <?php endif; ?>

        <button onclick="window.print()" class="btn btn-print">Cetak Slip</button>
    </div>
    <div class="hint">
        Periode slip: <strong><?= htmlspecialchars($currentMonth); ?></strong>. Gunakan tombol <strong>Cetak Semua Slip Periode Ini</strong> untuk mencetak semua karyawan tanpa edit URL manual.
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
                $totalIncome = (float)field_value($p, 'base_salary') + (float)field_value($p, 'total_allowance') + (float)field_value($p, 'total_bonus') + (float)field_value($p, 'overtime_amount');
                $loanDeduction = (float)field_value($p, 'loan_deduction');
                $activeLoanRemaining = (float)field_value($p, 'active_loan_remaining');
                $totalLoanAmount = (float)field_value($p, 'total_loan_amount');
                $totalLoanPaidUntilPeriod = (float)field_value($p, 'total_loan_paid_until_period');
                $totalLoanPaymentCount = (int)field_value($p, 'total_loan_payment_count');
                $loanRemainingAfterDeduction = max(0, $totalLoanAmount - $totalLoanPaidUntilPeriod);
                $cashAdvance = (float)field_value($p, 'cash_advance_deduction');
                $unpaidLeaveDeduction = (float)field_value($p, 'unpaid_leave_deduction');
                $totalTax = (float)field_value($p, 'total_tax');
                $manualDeduction = max(0, (float)field_value($p, 'total_deduction') - $unpaidLeaveDeduction - $cashAdvance - $loanDeduction);
                $totalDeduction = (float)field_value($p, 'total_deduction') + $totalTax + $totalBpjs;
                $overtimeText = slip_minutes(field_value($p, 'total_overtime_minutes'));
                $notes = trim((string)field_value($p, 'notes', ''));
                ?>

                <div class="slip-card">
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
                            <div class="line"><span class="label">Hari Kerja</span><span class="value"><?= (int)field_value($p, 'total_work_days'); ?> hari</span></div>
                            <div class="line"><span class="label">Hadir</span><span class="value"><?= (int)field_value($p, 'total_present_days'); ?> hari</span></div>
                            <div class="line"><span class="label">Paid</span><span class="value"><?= (int)field_value($p, 'paid_leave_days'); ?> hari</span></div>
                            <div class="line"><span class="label">Unpaid</span><span class="value"><?= (int)field_value($p, 'unpaid_leave_days'); ?> hari</span></div>
                            <div class="line"><span class="label">Alpha</span><span class="value"><?= (int)field_value($p, 'total_absent_days'); ?> hari</span></div>
                            <div class="line"><span class="label">Lembur</span><span class="value"><?= $overtimeText; ?></span></div>
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
                                        <td class="text-right"><?= (int)field_value($p, 'project_work_days'); ?> hr · <?= slip_money(field_value($p, 'project_worker_amount')); ?></td>
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
                                <tr>
                                    <td>Lembur</td>
                                    <td class="text-right"><?= slip_money(field_value($p, 'overtime_amount')); ?></td>
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
                                    <td>Manual/Alpha</td>
                                    <td class="text-right text-danger"><?= slip_money($manualDeduction); ?></td>
                                </tr>
                                <tr>
                                    <td>Unpaid Leave</td>
                                    <td class="text-right text-danger"><?= slip_money($unpaidLeaveDeduction); ?></td>
                                </tr>
                                <tr>
                                    <td>Kasbon</td>
                                    <td class="text-right text-danger"><?= slip_money($cashAdvance); ?></td>
                                </tr>
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
                                <tr>
                                    <td>BPJS + Pajak</td>
                                    <td class="text-right text-danger"><?= slip_money($totalBpjs + $totalTax); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="summary">
                        <div class="summary-row"><span>Total Pendapatan</span><strong><?= slip_money($totalIncome); ?></strong></div>
                        <div class="summary-row"><span>Total Potongan</span><strong><?= slip_money($totalDeduction); ?></strong></div>
                        <div class="summary-row"><span>Gaji Bersih</span><span><?= slip_money(field_value($p, 'net_salary')); ?></span></div>
                    </div>

                    <div class="notes">
                        Catatan: <?= $notes !== '' ? htmlspecialchars($notes) : 'Slip dibuat otomatis oleh Sistem Management PT Nukari Kriya Raya.'; ?>
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
                    <div class="cut-line">Garis potong</div>
                </div>
            <?php endfor; ?>
        </div>
    <?php endforeach; ?>
</body>

</html>