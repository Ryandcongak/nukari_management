<?php

require_once __DIR__ . '/../../includes/payroll_period_helper.php';

function hr_executive_position_regex(): string
{
    // Dewan pengawas, pemilik, dan jajaran eksekutif tidak menjadi objek
    // evaluasi kehadiran karyawan. Termasuk variasi/typo data lama "Komisari".
    return 'direktur|director|komisaris|komisari|commissioner|chief executive|chief financial|chief operating|(^|[^a-z])(ceo|cfo|coo)([^a-z]|$)|owner|pemilik|founder';
}

/*
|--------------------------------------------------------------------------
| HR Analytics Helper - Nukari Management
|--------------------------------------------------------------------------
| Satu sumber logic untuk analytics.php, analytics_print.php, dan export.
| Dibuat agar perhitungan tidak dobel dan hasil halaman/print/export konsisten.
*/

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function hr_to_int($value)
{
    return (int)($value ?? 0);
}

function hr_to_float($value)
{
    return (float)($value ?? 0);
}

function hr_minutes_to_text($minutes)
{
    $minutes = (int)$minutes;
    if ($minutes <= 0) return '-';

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    if ($hours > 0 && $mins > 0) return $hours . ' jam ' . $mins . ' menit';
    if ($hours > 0) return $hours . ' jam';
    return $mins . ' menit';
}

function hr_decimal_hours($minutes)
{
    $minutes = (int)$minutes;
    if ($minutes <= 0) return '0';

    $hours = round($minutes / 60, 1);
    return rtrim(rtrim(number_format($hours, 1, ',', '.'), '0'), ',');
}

function hr_number($value, $decimals = 1)
{
    return number_format((float)$value, $decimals, ',', '.');
}

function hr_day_number($value)
{
    return rtrim(rtrim(number_format((float)$value, 1, ',', '.'), '0'), ',');
}

function hr_has_column(array $columns, $column)
{
    return in_array($column, $columns, true);
}

function hr_first_col(array $columns, array $candidates)
{
    foreach ($candidates as $candidate) {
        if (hr_has_column($columns, $candidate)) return $candidate;
    }
    return null;
}

function hr_table_exists(PDO $pdo, $table)
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
            LIMIT 1
        ");
        $stmt->execute([':table_name' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function hr_table_columns(PDO $pdo, $table)
{
    try {
        if (!hr_table_exists($pdo, $table)) return [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Throwable $e) {
        return [];
    }
}

function hr_time_diff_minutes_expr($startColumn, $endColumn, $alias = 'a')
{
    if (!$startColumn || !$endColumn) return "0";

    return "
        CASE
            WHEN $alias.`$startColumn` IS NOT NULL
             AND $alias.`$startColumn` <> ''
             AND $alias.`$endColumn` IS NOT NULL
             AND $alias.`$endColumn` <> ''
            THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, $alias.`$startColumn`, $alias.`$endColumn`))
            ELSE 0
        END
    ";
}

function hr_safe_minutes_column_expr($column, $alias = 'a')
{
    return $column ? "COALESCE($alias.`$column`, 0)" : "0";
}

function hr_safe_date($value, $fallback)
{
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    return $fallback;
}

function hr_get_payroll_setting(PDO $pdo)
{
    try {
        if (!hr_table_exists($pdo, 'payroll_settings')) return ['start_day' => 16, 'end_day' => 15];
        return $pdo->query("SELECT start_day, end_day FROM payroll_settings WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC)
            ?: ['start_day' => 16, 'end_day' => 15];
    } catch (Throwable $e) {
        return ['start_day' => 16, 'end_day' => 15];
    }
}

function hr_get_period_range(PDO $pdo, $type, array $source)
{
    $today = new DateTime();

    $weekStart = hr_safe_date($source['week_start'] ?? '', $today->format('Y-m-d'));
    $month = (isset($source['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$source['month'])) ? $source['month'] : $today->format('Y-m');
    $year = (isset($source['year']) && preg_match('/^\d{4}$/', (string)$source['year'])) ? $source['year'] : $today->format('Y');
    $startDate = hr_safe_date($source['start_date'] ?? '', $today->format('Y-m-01'));
    $endDate = hr_safe_date($source['end_date'] ?? '', $today->format('Y-m-t'));
    $payrollSetting = hr_get_payroll_setting($pdo);

    if ($type === 'weekly') {
        $start = new DateTime($weekStart);
        $end = clone $start;
        $end->modify('+6 days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Mingguan', $start->format('d M Y') . ' - ' . $end->format('d M Y')];
    }

    if ($type === 'yearly') {
        [$start] = payroll_date_range($year . '-01', $payrollSetting);
        [, $end] = payroll_date_range($year . '-12', $payrollSetting);
        return [$start, $end, 'Tahunan', 'Payroll ' . $year . ' · ' . date('d/m/Y', strtotime($start)) . '–' . date('d/m/Y', strtotime($end))];
    }

    if ($type === 'custom') {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) $end = clone $start;
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Custom', $start->format('d M Y') . ' - ' . $end->format('d M Y')];
    }

    [$start, $end] = payroll_date_range($month, $payrollSetting);
    return [$start, $end, 'Bulanan', 'Payroll ' . date('m/Y', strtotime($month . '-01')) . ' · ' . date('d/m/Y', strtotime($start)) . '–' . date('d/m/Y', strtotime($end))];
}

function hr_count_calendar_days($startDate, $endDate)
{
    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) return 0;

        return $start->diff($end)->days + 1;
    } catch (Throwable $e) {
        return 0;
    }
}

function hr_schedule_summary($startDate, $endDate)
{
    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) return ['days' => 0, 'minutes' => 0];

        $days = 0;
        $minutes = 0;
        while ($start <= $end) {
            $days++;
            $minutes += 480;
            $start->modify('+1 day');
        }
        return ['days' => $days, 'minutes' => $minutes];
    } catch (Throwable $e) {
        return ['days' => 0, 'minutes' => 0];
    }
}

function hr_get_department_options(PDO $pdo)
{
    try {
        $employeeCols = hr_table_columns($pdo, 'employees');
        if (!hr_has_column($employeeCols, 'department')) return [];
        $stmt = $pdo->prepare("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> '' AND LOWER(COALESCE(position,'')) NOT REGEXP :excluded_positions ORDER BY department ASC");
        $stmt->execute([':excluded_positions' => hr_executive_position_regex()]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'department');
    } catch (Throwable $e) {
        return [];
    }
}

function hr_badge_eval($class, $text)
{
    $map = [
        'success' => 'success',
        'primary' => 'primary',
        'warning' => 'warning',
        'danger' => 'danger',
        'secondary' => 'secondary',
    ];

    $class = $map[$class] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $class . '">' . h($text) . '</span>';
}

function hr_evaluation_explanation(array $row): array
{
    $status = $row['evaluation_note'] ?? 'Belum ada data';
    $reasons = [];
    $positives = [];
    $actions = [];

    if (hr_to_int($row['absent_days'] ?? 0) > 0) $reasons[] = 'Alpha ' . hr_to_int($row['absent_days']) . ' hari.';
    if (hr_to_int($row['late_days'] ?? 0) > 0) $reasons[] = 'Terlambat ' . hr_to_int($row['late_days']) . ' kali (' . hr_minutes_to_text($row['late_minutes'] ?? 0) . ').';
    if (hr_to_int($row['early_leave_days'] ?? 0) > 0) $reasons[] = 'Pulang awal ' . hr_to_int($row['early_leave_days']) . ' kali (' . hr_minutes_to_text($row['early_leave_minutes'] ?? 0) . ').';
    if (hr_to_int($row['unpaid_leave_days'] ?? 0) > 0) $reasons[] = 'Cuti tidak dibayar ' . hr_to_int($row['unpaid_leave_days']) . ' hari.';

    if (hr_to_float($row['attendance_rate'] ?? 0) >= 90) $positives[] = 'Kehadiran tinggi: ' . hr_number($row['attendance_rate'], 1) . '%.';
    if (hr_to_int($row['absent_days'] ?? 0) === 0) $positives[] = 'Tidak memiliki alpha.';
    if (hr_to_int($row['early_leave_days'] ?? 0) === 0) $positives[] = 'Tidak pernah pulang awal.';
    if (hr_to_int($row['early_arrival_days'] ?? 0) > 0) $positives[] = 'Datang lebih awal ' . hr_to_int($row['early_arrival_days']) . ' kali.';
    if (hr_to_int($row['total_overtime_minutes'] ?? 0) > 0) $positives[] = 'Kontribusi lembur ' . hr_decimal_hours($row['total_overtime_minutes']) . ' jam.';

    if ($status === 'Sangat baik') {
        $summary = 'Kinerja dinilai sangat baik karena kehadiran dan kedisiplinan kuat, atau kontribusi lembur melampaui total masalah keterlambatan dan pulang awal.';
        $actions[] = 'Layak dipertimbangkan sebagai kandidat reward setelah validasi atasan.';
        $actions[] = 'Pertahankan konsistensi tanpa mendorong lembur berlebihan.';
    } elseif ($status === 'Baik') {
        $summary = 'Kinerja berada pada kondisi baik dan tidak memenuhi pemicu Perlu Perhatian atau Perlu Evaluasi.';
        if (!empty($row['is_good_contributor'])) $summary .= ' Kontribusi lembur juga membantu menyeimbangkan catatan kedisiplinan.';
        $actions[] = 'Pertahankan pola kehadiran dan perbaiki catatan kecil yang masih muncul.';
    } elseif ($status === 'Perlu perhatian') {
        $summary = 'Status ini muncul karena nilai di bawah 70, terlambat minimal 3 kali, atau pulang awal minimal 3 kali.';
        $actions[] = 'Konfirmasi penyebab keterlambatan atau pulang awal bersama karyawan.';
        $actions[] = 'Buat target perbaikan sederhana untuk periode berikutnya.';
    } elseif ($status === 'Perlu evaluasi') {
        $summary = 'Status ini muncul karena nilai di bawah 55 atau terdapat alpha minimal 2 hari.';
        $actions[] = 'Lakukan pertemuan evaluasi dan verifikasi data absensi terlebih dahulu.';
        $actions[] = 'Catat penyebab, tindakan perbaikan, dan tenggat evaluasi berikutnya.';
    } elseif ($status === 'Cuti/Dinas') {
        $summary = 'Periode didominasi cuti atau dinas yang telah disetujui sehingga tidak dinilai sebagai masalah kehadiran.';
        $actions[] = 'Tidak memerlukan tindakan selama dokumen persetujuan lengkap.';
    } else {
        $summary = 'Belum tersedia data absensi yang cukup untuk memberikan penilaian.';
        $actions[] = 'Periksa impor fingerprint atau pencatatan absensi periode ini.';
    }

    if (!$reasons) $reasons[] = 'Tidak ada pemicu pelanggaran utama pada periode ini.';
    if (!$positives) $positives[] = 'Belum ada indikator positif khusus yang tercatat.';

    return compact('summary', 'reasons', 'positives', 'actions');
}

function hr_bar_width($value, $max)
{
    $max = max(1, (float)$max);
    $value = max(0, (float)$value);
    return min(100, round(($value / $max) * 100, 1));
}

function hr_get_leave_summary(PDO $pdo, $periodStart, $periodEnd)
{
    $result = [];

    if (!hr_table_exists($pdo, 'employee_leaves')) return $result;

    $cols = hr_table_columns($pdo, 'employee_leaves');
    foreach (['employee_id', 'start_date', 'end_date'] as $required) {
        if (!hr_has_column($cols, $required)) return $result;
    }

    $statusCol = hr_has_column($cols, 'status') ? 'status' : null;
    $typeCol = hr_has_column($cols, 'leave_type') ? 'leave_type' : null;
    $isPaidCol = hr_has_column($cols, 'is_paid') ? 'is_paid' : null;

    $where = "start_date <= :period_end AND end_date >= :period_start";
    if ($statusCol) {
        $where .= " AND LOWER(COALESCE(`$statusCol`, '')) = 'approved'";
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM employee_leaves WHERE $where");
        $stmt->execute([
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $leave) {
            $employeeId = (int)($leave['employee_id'] ?? 0);
            if ($employeeId <= 0) continue;

            $start = max($periodStart, (string)$leave['start_date']);
            $end = min($periodEnd, (string)$leave['end_date']);
            $days = hr_count_calendar_days($start, $end);
            if ($days <= 0) continue;

            if (!isset($result[$employeeId])) {
                $result[$employeeId] = [
                    'approved_leave_days' => 0,
                    'paid_leave_days' => 0,
                    'unpaid_leave_days' => 0,
                    'sick_days' => 0,
                    'official_duty_days' => 0,
                ];
            }

            $type = $typeCol ? strtolower((string)($leave[$typeCol] ?? '')) : '';
            $isPaid = $isPaidCol ? strtolower((string)($leave[$isPaidCol] ?? 'yes')) : 'yes';

            $result[$employeeId]['approved_leave_days'] += $days;

            if ($type === 'sick') {
                $result[$employeeId]['sick_days'] += $days;
            }

            if ($type === 'official_duty') {
                $result[$employeeId]['official_duty_days'] += $days;
            }

            if ($type === 'unpaid_leave' || $isPaid === 'no') {
                $result[$employeeId]['unpaid_leave_days'] += $days;
            } else {
                $result[$employeeId]['paid_leave_days'] += $days;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $result;
}

function hr_build_analytics(PDO $pdo, array $source = [])
{
    $periodType = $source['type'] ?? 'monthly';
    $allowedTypes = ['monthly', 'yearly'];
    if (!in_array($periodType, $allowedTypes, true)) $periodType = 'monthly';

    [$periodStart, $periodEnd, $periodLabel, $periodText] = hr_get_period_range($pdo, $periodType, $source);

    $keyword = trim((string)($source['keyword'] ?? ''));
    $departmentFilter = trim((string)($source['department'] ?? ''));
    $statusFilter = trim((string)($source['evaluation'] ?? ''));

    $attendanceCols = hr_table_columns($pdo, 'attendances');
    $employeeCols = hr_table_columns($pdo, 'employees');

    $dateColumn = hr_first_col($attendanceCols, ['attendance_date', 'date', 'work_date', 'tanggal']);
    $statusColumn = hr_first_col($attendanceCols, ['status', 'attendance_status']);

    $morningIn = hr_first_col($attendanceCols, ['morning_in', 'morning_check_in', 'check_in_morning', 'pagi_masuk', 'clock_in_morning']);
    $morningOut = hr_first_col($attendanceCols, ['morning_out', 'morning_check_out', 'check_out_morning', 'pagi_keluar', 'clock_out_morning']);
    $afternoonIn = hr_first_col($attendanceCols, ['afternoon_in', 'afternoon_check_in', 'check_in_afternoon', 'siang_masuk', 'clock_in_afternoon']);
    $afternoonOut = hr_first_col($attendanceCols, ['afternoon_out', 'afternoon_check_out', 'check_out_afternoon', 'siang_keluar', 'clock_out_afternoon']);
    $checkIn = hr_first_col($attendanceCols, ['check_in', 'clock_in', 'time_in']);
    $checkOut = hr_first_col($attendanceCols, ['check_out', 'clock_out', 'time_out']);

    $lateMinutesCol = hr_first_col($attendanceCols, ['late_minutes', 'total_late_minutes', 'minutes_late']);
    $earlyLeaveMinutesCol = hr_first_col($attendanceCols, ['early_leave_minutes', 'total_early_leave_minutes', 'minutes_early_leave']);
    $overtimeMinutesCol = hr_first_col($attendanceCols, ['overtime_minutes', 'total_overtime_minutes', 'lembur_minutes']);
    $workMinutesCol = hr_first_col($attendanceCols, ['work_minutes', 'total_work_minutes', 'minutes_worked']);
    $attendanceTypeCol = hr_first_col($attendanceCols, ['attendance_type']);
    $attendanceDayValueCol = hr_first_col($attendanceCols, ['attendance_day_value']);

    $morningHalfCase = $attendanceTypeCol
        ? "a.`$attendanceTypeCol` = 'half_day_morning'"
        : (($checkIn && $checkOut)
            ? "TIME(a.`$checkIn`) BETWEEN '07:00:00' AND '08:30:00' AND TIME(a.`$checkOut`) BETWEEN '11:30:00' AND '13:59:59'"
            : '0');
    $afternoonHalfCase = $attendanceTypeCol
        ? "a.`$attendanceTypeCol` = 'half_day_afternoon'"
        : (($checkIn && $checkOut)
            ? "TIME(a.`$checkIn`) BETWEEN '11:30:00' AND '13:30:00' AND TIME(a.`$checkOut`) >= '16:30:00'"
            : '0');
    $halfDayCase = "(($morningHalfCase) OR ($afternoonHalfCase))";
    $earlyArrivalCase = ($checkIn && $checkOut)
        ? "(($morningHalfCase AND TIME(a.`$checkIn`) < '08:00:00') OR ($afternoonHalfCase AND TIME(a.`$checkIn`) < '12:00:00') OR (NOT ($halfDayCase) AND TIME(a.`$checkIn`) < '08:00:00'))"
        : '0';
    if ($lateMinutesCol) {
        // Nilai tersimpan berasal dari kalkulator absensi baku. Jangan beri
        // toleransi kedua di Analytics karena hasilnya harus sama dengan slip.
        $lateMinutesExpression = hr_safe_minutes_column_expr($lateMinutesCol);
    } elseif ($checkIn && $checkOut) {
        $lateMinutesExpression = "CASE
            WHEN $morningHalfCase THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.`$dateColumn`, '08:30:00'), TIMESTAMP(a.`$dateColumn`, TIME(a.`$checkIn`))))
            WHEN $afternoonHalfCase THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.`$dateColumn`, '13:30:00'), TIMESTAMP(a.`$dateColumn`, TIME(a.`$checkIn`))))
            WHEN TIME(a.`$checkIn`) > '08:30:00' THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.`$dateColumn`, '08:30:00'), TIMESTAMP(a.`$dateColumn`, TIME(a.`$checkIn`)))
            ELSE 0
        END";
    } else {
        $lateMinutesExpression = '0';
    }
    if ($earlyLeaveMinutesCol) {
        // Gunakan snapshot kanonik yang sama untuk Senin sampai Minggu.
        $earlyLeaveMinutesExpression = hr_safe_minutes_column_expr($earlyLeaveMinutesCol);
    } elseif ($checkIn && $checkOut) {
        $earlyLeaveMinutesExpression = "CASE
            WHEN $morningHalfCase THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.`$dateColumn`, TIME(a.`$checkOut`)), TIMESTAMP(a.`$dateColumn`, '11:30:00')))
            WHEN $afternoonHalfCase THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.`$dateColumn`, TIME(a.`$checkOut`)), TIMESTAMP(a.`$dateColumn`, '16:30:00')))
            WHEN TIME(a.`$checkOut`) < '16:30:00' THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.`$dateColumn`, TIME(a.`$checkOut`)), TIMESTAMP(a.`$dateColumn`, '16:30:00'))
            ELSE 0
        END";
    } else {
        $earlyLeaveMinutesExpression = '0';
    }
    $overtimeMinutesExpression = $overtimeMinutesCol
        ? hr_safe_minutes_column_expr($overtimeMinutesCol)
        : hr_time_diff_minutes_expr(hr_first_col($attendanceCols, ['overtime_in', 'lembur_masuk', 'overtime_start']), hr_first_col($attendanceCols, ['overtime_out', 'lembur_keluar', 'overtime_end']));

    if ($workMinutesCol) {
        $workMinutesExpression = hr_safe_minutes_column_expr($workMinutesCol);
    } elseif ($morningIn && $morningOut && $afternoonIn && $afternoonOut) {
        $workMinutesExpression = '(' . hr_time_diff_minutes_expr($morningIn, $morningOut) . ' + ' . hr_time_diff_minutes_expr($afternoonIn, $afternoonOut) . ')';
    } elseif ($checkIn && $checkOut) {
        $workMinutesExpression = hr_time_diff_minutes_expr($checkIn, $checkOut);
    } else {
        $workMinutesExpression = '0';
    }

    $rows = [];
    $errorMessage = '';

    if (!$dateColumn) {
        $errorMessage = 'Kolom tanggal absensi tidak ditemukan pada tabel attendances.';
    } else {
        $employeeWhere = '1=1';
        $params = [
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
        ];

        if (hr_has_column($employeeCols, 'employment_status')) {
            $employeeWhere .= " AND LOWER(COALESCE(e.employment_status, 'active')) = 'active'";
        }
        if (hr_has_column($employeeCols, 'position')) {
            $employeeWhere .= " AND LOWER(COALESCE(e.position, '')) NOT REGEXP :excluded_positions";
            $params[':excluded_positions'] = hr_executive_position_regex();
        }

        if ($keyword !== '') {
            $employeeWhere .= " AND (e.full_name LIKE :keyword_name OR e.employee_code LIKE :keyword_code OR e.position LIKE :keyword_position OR e.department LIKE :keyword_department)";
            $keywordPattern = '%' . $keyword . '%';
            $params[':keyword_name'] = $keywordPattern;
            $params[':keyword_code'] = $keywordPattern;
            $params[':keyword_position'] = $keywordPattern;
            $params[':keyword_department'] = $keywordPattern;
        }

        if ($departmentFilter !== '' && hr_has_column($employeeCols, 'department')) {
            $employeeWhere .= " AND e.department = :department";
            $params[':department'] = $departmentFilter;
        }

        $workPositiveCase = "($workMinutesExpression) > 0";
        if ($attendanceDayValueCol) {
            $presentCase = "CASE WHEN a.id IS NULL THEN 0 ELSE GREATEST(0, LEAST(1, COALESCE(a.`$attendanceDayValueCol`, 0))) END";
        } elseif ($statusColumn) {
            $presentCase = "CASE WHEN LOWER(COALESCE(a.`$statusColumn`, '')) IN ('present','hadir','late','terlambat') OR $workPositiveCase THEN CASE WHEN $halfDayCase THEN 0.5 ELSE 1 END ELSE 0 END";
        } else {
            $presentCase = "CASE WHEN a.id IS NOT NULL AND $workPositiveCase THEN CASE WHEN $halfDayCase THEN 0.5 ELSE 1 END ELSE 0 END";
        }

        if ($statusColumn) {
            $absentCase = "CASE WHEN LOWER(COALESCE(a.`$statusColumn`, '')) IN ('absent','alpha','alpa','tidak hadir') THEN 1 ELSE 0 END";
            $lateCase = "CASE WHEN $lateMinutesExpression > 0 THEN 1 ELSE 0 END";
        } else {
            $absentCase = "0";
            $lateCase = "CASE WHEN $lateMinutesExpression > 0 THEN 1 ELSE 0 END";
        }

        $selectDepartment = hr_has_column($employeeCols, 'department') ? 'e.department' : "'' AS department";
        $selectPosition = hr_has_column($employeeCols, 'position') ? 'e.position' : "'' AS position";
        $selectEmployeeCode = hr_has_column($employeeCols, 'employee_code') ? 'e.employee_code' : "'' AS employee_code";

        $sql = "
            SELECT
                e.id AS employee_id,
                e.full_name,
                $selectEmployeeCode,
                $selectPosition,
                $selectDepartment,
                COUNT(a.id) AS attendance_rows,
                COALESCE(SUM($presentCase), 0) AS present_days,
                COALESCE(SUM(CASE WHEN $halfDayCase THEN 1 ELSE 0 END), 0) AS half_day_count,
                COALESCE(SUM(CASE WHEN $earlyArrivalCase THEN 1 ELSE 0 END), 0) AS early_arrival_days,
                COALESCE(SUM($absentCase), 0) AS absent_days,
                COALESCE(SUM($lateCase), 0) AS late_days,
                COALESCE(SUM($lateMinutesExpression), 0) AS late_minutes,
                COALESCE(SUM(CASE WHEN $earlyLeaveMinutesExpression > 0 THEN 1 ELSE 0 END), 0) AS early_leave_days,
                COALESCE(SUM($earlyLeaveMinutesExpression), 0) AS early_leave_minutes,
                COALESCE(SUM($workMinutesExpression), 0) AS total_work_minutes,
                COALESCE(SUM(CASE WHEN $overtimeMinutesExpression > 0 THEN 1 ELSE 0 END), 0) AS overtime_days,
                COALESCE(SUM($overtimeMinutesExpression), 0) AS total_overtime_minutes
            FROM employees e
            LEFT JOIN attendances a
                ON a.employee_id = e.id
               AND a.`$dateColumn` BETWEEN :period_start AND :period_end
            WHERE $employeeWhere
            GROUP BY e.id, e.full_name, employee_code, position, department
            ORDER BY e.full_name ASC
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = [];
            $errorMessage = 'Gagal membaca data HR Analytics: ' . $e->getMessage();
        }
    }

    $periodDays = max(1, (new DateTime($periodStart))->diff(new DateTime($periodEnd))->days + 1);
    $schedule = hr_schedule_summary($periodStart, $periodEnd);
    $workingDays = max(1, $schedule['days']);
    $leaveSummary = hr_get_leave_summary($pdo, $periodStart, $periodEnd);

    foreach ($rows as &$row) {
        $employeeId = (int)$row['employee_id'];
        $leave = $leaveSummary[$employeeId] ?? [
            'approved_leave_days' => 0,
            'paid_leave_days' => 0,
            'unpaid_leave_days' => 0,
            'sick_days' => 0,
            'official_duty_days' => 0,
        ];

        $presentDays = hr_to_float($row['present_days']);
        $absentDays = hr_to_int($row['absent_days']);
        $lateDays = hr_to_int($row['late_days']);
        $earlyLeaveDays = hr_to_int($row['early_leave_days']);
        $workMinutes = hr_to_int($row['total_work_minutes']);
        $overtimeDays = hr_to_int($row['overtime_days']);
        $overtimeMinutes = hr_to_int($row['total_overtime_minutes']);

        $approvedLeaveDays = min($workingDays, hr_to_int($leave['approved_leave_days']));
        $paidLeaveDays = hr_to_int($leave['paid_leave_days']);
        $unpaidLeaveDays = hr_to_int($leave['unpaid_leave_days']);
        $officialDutyDays = hr_to_int($leave['official_duty_days']);

        $effectiveWorkDays = max(0, $workingDays - $approvedLeaveDays);
        $attendanceTargetDays = max(1, $effectiveWorkDays);
        $expectedWorkMinutes = max(1, $schedule['minutes'] - ($approvedLeaveDays * 480));

        $attendanceRate = $effectiveWorkDays > 0 ? min(100, ($presentDays / $attendanceTargetDays) * 100) : 100;
        $workRate = $effectiveWorkDays > 0 ? min(120, ($workMinutes / $expectedWorkMinutes) * 100) : 100;

        $disciplinePenalty = min(100, ($lateDays * 10) + ($earlyLeaveDays * 7) + ($absentDays * 18));
        $disciplineRate = max(0, 100 - $disciplinePenalty);

        $overtimeScore = min(100, ($overtimeMinutes / 60) * 18);
        $leaveScore = 100;
        if ($unpaidLeaveDays > 0) $leaveScore -= min(40, $unpaidLeaveDays * 12);
        if ($approvedLeaveDays > 0 && $unpaidLeaveDays <= 0) $leaveScore = 95;
        if ($officialDutyDays > 0) $leaveScore = 100;

        $score =
            ($attendanceRate * 0.35) +
            ($disciplineRate * 0.25) +
            (min(100, $workRate) * 0.25) +
            ($overtimeScore * 0.10) +
            ($leaveScore * 0.05);

        if ($effectiveWorkDays > 0 && $presentDays <= 0 && $workMinutes <= 0 && $approvedLeaveDays <= 0) {
            $score = min($score, 45);
        }

        $score = max(0, min(100, round($score, 1)));
        $isLoyalAndDedicated = $presentDays > 0
            && $absentDays === 0
            && $overtimeMinutes > (hr_to_int($row['late_minutes']) + hr_to_int($row['early_leave_minutes']));
        $issueMinutes = hr_to_int($row['late_minutes']) + hr_to_int($row['early_leave_minutes']);
        $isGoodContributor = $presentDays > 0
            && $absentDays === 0
            && $overtimeMinutes > 0
            && (
                $overtimeDays > max($lateDays, $earlyLeaveDays)
                || ($issueMinutes > 0 && $overtimeMinutes >= ($issueMinutes * 0.75))
            );

        if ($isLoyalAndDedicated) {
            $score = max(90, $score);
        } elseif ($isGoodContributor) {
            $score = max(70, $score);
        }

        $row['period_days'] = $periodDays;
        $row['working_days'] = $workingDays;
        $row['effective_work_days'] = $effectiveWorkDays;
        $row['standard_work_minutes'] = $expectedWorkMinutes;
        $row['attendance_rate'] = round($attendanceRate, 1);
        $row['work_rate'] = round($workRate, 1);
        $row['discipline_rate'] = round($disciplineRate, 1);
        $row['overtime_score'] = round($overtimeScore, 1);
        $row['leave_score'] = round($leaveScore, 1);
        $row['approved_leave_days'] = $approvedLeaveDays;
        $row['paid_leave_days'] = $paidLeaveDays;
        $row['unpaid_leave_days'] = $unpaidLeaveDays;
        $row['official_duty_days'] = $officialDutyDays;
        $row['score'] = $score;
        $row['is_loyal_and_dedicated'] = $isLoyalAndDedicated;
        $row['is_good_contributor'] = $isGoodContributor;

        $meetingFacts = [];
        if (hr_to_int($row['early_arrival_days'] ?? 0) > 0) $meetingFacts[] = 'datang lebih awal ' . hr_to_int($row['early_arrival_days']) . 'x';
        if ($lateDays > 0) $meetingFacts[] = 'telat ' . $lateDays . 'x (' . hr_minutes_to_text($row['late_minutes']) . ')';
        if ($earlyLeaveDays > 0) $meetingFacts[] = 'pulang awal ' . $earlyLeaveDays . 'x (' . hr_minutes_to_text($row['early_leave_minutes']) . ')';
        if ($absentDays > 0) $meetingFacts[] = 'alpha ' . $absentDays . ' hari';
        $meetingFacts[] = 'kehadiran ' . hr_number($attendanceRate, 1) . '%';
        if ($overtimeMinutes > 0) $meetingFacts[] = 'lembur ' . $overtimeDays . 'x (' . hr_decimal_hours($overtimeMinutes) . ' jam)';
        $meetingSummary = ucfirst(implode(', ', $meetingFacts)) . '.';

        if ($isLoyalAndDedicated) {
            $row['evaluation_note'] = 'Sangat baik';
            $row['evaluation_class'] = 'success';
            $row['meeting_note'] = 'Loyal & berdedikasi — durasi lembur melampaui total waktu telat dan pulang awal. ' . $meetingSummary;
        } elseif ($isGoodContributor) {
            $row['evaluation_note'] = 'Baik';
            $row['evaluation_class'] = 'primary';
            $row['meeting_note'] = 'Kontribusi lembur menyeimbangkan catatan kedisiplinan. ' . $meetingSummary;
        } elseif ($effectiveWorkDays <= 0 && $approvedLeaveDays > 0) {
            $row['evaluation_note'] = 'Cuti/Dinas';
            $row['evaluation_class'] = 'primary';
            $row['meeting_note'] = 'Periode didominasi cuti/dinas approved. ' . $meetingSummary;
        } elseif ($presentDays <= 0 && $workMinutes <= 0 && $approvedLeaveDays <= 0) {
            $row['evaluation_note'] = 'Belum ada data';
            $row['evaluation_class'] = 'secondary';
            $row['meeting_note'] = 'Belum ada data absensi pada periode ini.';
        } elseif ($absentDays >= 2 || $score < 55) {
            $row['evaluation_note'] = 'Perlu evaluasi';
            $row['evaluation_class'] = 'danger';
            $row['meeting_note'] = $meetingSummary;
        } elseif ($lateDays >= 3 || $earlyLeaveDays >= 3 || $score < 70) {
            $row['evaluation_note'] = 'Perlu perhatian';
            $row['evaluation_class'] = 'warning';
            $row['meeting_note'] = $meetingSummary;
        } elseif ($score >= 90 && $lateDays === 0 && $absentDays === 0) {
            $row['evaluation_note'] = 'Sangat baik';
            $row['evaluation_class'] = 'success';
            $row['meeting_note'] = $meetingSummary;
        } else {
            $row['evaluation_note'] = 'Baik';
            $row['evaluation_class'] = 'primary';
            $row['meeting_note'] = $meetingSummary;
        }
    }
    unset($row);

    if ($statusFilter !== '') {
        $allowedStatus = ['success', 'primary', 'warning', 'danger', 'secondary'];
        if (in_array($statusFilter, $allowedStatus, true)) {
            $rows = array_values(array_filter($rows, fn($r) => ($r['evaluation_class'] ?? '') === $statusFilter));
        }
    }

    $ranked = $rows;
    usort($ranked, function ($a, $b) {
        $scoreCompare = hr_to_float($b['score']) <=> hr_to_float($a['score']);
        if ($scoreCompare !== 0) return $scoreCompare;
        return hr_to_int($b['total_work_minutes']) <=> hr_to_int($a['total_work_minutes']);
    });

    foreach ($ranked as $i => $rankRow) {
        foreach ($rows as &$row) {
            if ((int)$row['employee_id'] === (int)$rankRow['employee_id']) {
                $row['rank'] = $i + 1;
                break;
            }
        }
        unset($row);
    }

    usort($rows, fn($a, $b) => hr_to_int($a['rank'] ?? 999999) <=> hr_to_int($b['rank'] ?? 999999));

    $summary = [
        'employees' => count($rows),
        'present_days' => 0,
        'absent_days' => 0,
        'late_days' => 0,
        'early_leave_days' => 0,
        'approved_leave_days' => 0,
        'work_minutes' => 0,
        'overtime_minutes' => 0,
        'need_attention' => 0,
        'average_score' => 0,
        'average_attendance_rate' => 0,
        'working_days' => $workingDays,
    ];

    $scoreTotal = 0;
    $attendanceRateTotal = 0;

    foreach ($rows as $row) {
        $summary['present_days'] += hr_to_float($row['present_days']);
        $summary['absent_days'] += hr_to_int($row['absent_days']);
        $summary['late_days'] += hr_to_int($row['late_days']);
        $summary['early_leave_days'] += hr_to_int($row['early_leave_days']);
        $summary['approved_leave_days'] += hr_to_int($row['approved_leave_days']);
        $summary['work_minutes'] += hr_to_int($row['total_work_minutes']);
        $summary['overtime_minutes'] += hr_to_int($row['total_overtime_minutes']);
        $scoreTotal += hr_to_float($row['score']);
        $attendanceRateTotal += hr_to_float($row['attendance_rate']);
        if (in_array($row['evaluation_class'], ['danger', 'warning', 'secondary'], true)) {
            $summary['need_attention']++;
        }
    }

    if ($summary['employees'] > 0) {
        $summary['average_score'] = round($scoreTotal / $summary['employees'], 1);
        $summary['average_attendance_rate'] = round($attendanceRateTotal / $summary['employees'], 1);
    }

    $topScore = array_slice($rows, 0, 5);

    $topWork = $rows;
    usort($topWork, fn($a, $b) => hr_to_int($b['total_work_minutes']) <=> hr_to_int($a['total_work_minutes']));
    $topWork = array_slice($topWork, 0, 5);

    $topOvertime = $rows;
    usort($topOvertime, fn($a, $b) => hr_to_int($b['total_overtime_minutes']) <=> hr_to_int($a['total_overtime_minutes']));
    $topOvertime = array_slice($topOvertime, 0, 5);

    $topLate = $rows;
    usort($topLate, fn($a, $b) => hr_to_int($b['late_days']) <=> hr_to_int($a['late_days']));
    $topLate = array_slice($topLate, 0, 5);

    $needReview = array_values(array_filter($rows, fn($r) => in_array($r['evaluation_class'], ['danger', 'warning', 'secondary'], true)));
    usort($needReview, fn($a, $b) => hr_to_float($a['score']) <=> hr_to_float($b['score']));
    $needReview = array_slice($needReview, 0, 7);

    // Perbandingan perilaku kerja hanya memakai karyawan yang memiliki data
    // absensi pada periode terpilih agar nilai nol karena "belum ada data"
    // tidak keliru dianggap sebagai pencapaian terbaik.
    $comparisonRows = array_values(array_filter($rows, fn($r) => hr_to_int($r['attendance_rows'] ?? 0) > 0));
    $takeComparison = static function (array $source, callable $sort): array {
        usort($source, $sort);
        return array_slice($source, 0, 5);
    };

    $mostEarlyArrival = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($b['early_arrival_days'] ?? 0) <=> hr_to_int($a['early_arrival_days'] ?? 0))
        ?: (hr_to_float($b['score']) <=> hr_to_float($a['score']))
    );
    $leastEarlyArrival = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($a['early_arrival_days'] ?? 0) <=> hr_to_int($b['early_arrival_days'] ?? 0))
        ?: (hr_to_float($a['score']) <=> hr_to_float($b['score']))
    );
    $bestNoEarlyLeave = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($a['early_leave_days'] ?? 0) <=> hr_to_int($b['early_leave_days'] ?? 0))
        ?: (hr_to_int($a['early_leave_minutes'] ?? 0) <=> hr_to_int($b['early_leave_minutes'] ?? 0))
        ?: (hr_to_float($b['score']) <=> hr_to_float($a['score']))
    );
    $mostEarlyLeave = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($b['early_leave_days'] ?? 0) <=> hr_to_int($a['early_leave_days'] ?? 0))
        ?: (hr_to_int($b['early_leave_minutes'] ?? 0) <=> hr_to_int($a['early_leave_minutes'] ?? 0))
    );
    $leastLeave = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($a['approved_leave_days'] ?? 0) <=> hr_to_int($b['approved_leave_days'] ?? 0))
        ?: (hr_to_float($b['score']) <=> hr_to_float($a['score']))
    );
    $mostLeave = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($b['approved_leave_days'] ?? 0) <=> hr_to_int($a['approved_leave_days'] ?? 0))
        ?: (hr_to_float($a['score']) <=> hr_to_float($b['score']))
    );
    $mostOvertime = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($b['total_overtime_minutes'] ?? 0) <=> hr_to_int($a['total_overtime_minutes'] ?? 0))
        ?: (hr_to_float($b['score']) <=> hr_to_float($a['score']))
    );
    $leastOvertime = $takeComparison($comparisonRows, fn($a, $b) =>
        (hr_to_int($a['total_overtime_minutes'] ?? 0) <=> hr_to_int($b['total_overtime_minutes'] ?? 0))
        ?: (hr_to_float($a['score']) <=> hr_to_float($b['score']))
    );
    $rewardCandidates = array_values(array_filter($rows, fn($r) =>
        hr_to_float($r['score']) >= 90
        && hr_to_int($r['absent_days']) === 0
        && hr_to_int($r['early_leave_days']) === 0
        && hr_to_int($r['attendance_rows'] ?? 0) > 0
    ));
    usort($rewardCandidates, fn($a, $b) => hr_to_float($b['score']) <=> hr_to_float($a['score']));
    $rewardCandidates = array_slice($rewardCandidates, 0, 5);

    return [
        'periodType' => $periodType,
        'periodStart' => $periodStart,
        'periodEnd' => $periodEnd,
        'periodLabel' => $periodLabel,
        'periodText' => $periodText,
        'keyword' => $keyword,
        'departmentFilter' => $departmentFilter,
        'statusFilter' => $statusFilter,
        'departmentOptions' => hr_get_department_options($pdo),
        'rows' => $rows,
        'summary' => $summary,
        'topScore' => $topScore,
        'topWork' => $topWork,
        'topOvertime' => $topOvertime,
        'topLate' => $topLate,
        'needReview' => $needReview,
        'mostEarlyArrival' => $mostEarlyArrival,
        'leastEarlyArrival' => $leastEarlyArrival,
        'bestNoEarlyLeave' => $bestNoEarlyLeave,
        'mostEarlyLeave' => $mostEarlyLeave,
        'leastLeave' => $leastLeave,
        'mostLeave' => $mostLeave,
        'mostOvertime' => $mostOvertime,
        'leastOvertime' => $leastOvertime,
        'rewardCandidates' => $rewardCandidates,
        'errorMessage' => $errorMessage,
    ];
}
