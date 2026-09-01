<?php

if (!defined('HR_LATE_TOLERANCE_TIME')) {
    define('HR_LATE_TOLERANCE_TIME', '08:20:00');
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
        $stmt = $pdo->prepare("SHOW TABLES LIKE :table_name");
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

function hr_get_period_range($type, array $source)
{
    $today = new DateTime();

    $weekStart = hr_safe_date($source['week_start'] ?? '', $today->format('Y-m-d'));
    $month = (isset($source['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$source['month'])) ? $source['month'] : $today->format('Y-m');
    $year = (isset($source['year']) && preg_match('/^\d{4}$/', (string)$source['year'])) ? $source['year'] : $today->format('Y');
    $startDate = hr_safe_date($source['start_date'] ?? '', $today->format('Y-m-01'));
    $endDate = hr_safe_date($source['end_date'] ?? '', $today->format('Y-m-t'));

    if ($type === 'weekly') {
        $start = new DateTime($weekStart);
        $end = clone $start;
        $end->modify('+6 days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Mingguan', $start->format('d M Y') . ' - ' . $end->format('d M Y')];
    }

    if ($type === 'yearly') {
        return [$year . '-01-01', $year . '-12-31', 'Tahunan', $year];
    }

    if ($type === 'custom') {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) $end = clone $start;
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Custom', $start->format('d M Y') . ' - ' . $end->format('d M Y')];
    }

    $start = new DateTime($month . '-01');
    $end = clone $start;
    $end->modify('last day of this month');
    return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Bulanan', $start->format('F Y')];
}

function hr_count_weekdays($startDate, $endDate)
{
    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) return 0;

        $days = 0;
        while ($start <= $end) {
            $day = (int)$start->format('N');
            if (!in_array($day, [6, 7], true)) $days++;
            $start->modify('+1 day');
        }
        return $days;
    } catch (Throwable $e) {
        return 0;
    }
}

function hr_get_department_options(PDO $pdo)
{
    try {
        $employeeCols = hr_table_columns($pdo, 'employees');
        if (!hr_has_column($employeeCols, 'department')) return [];
        $stmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> '' ORDER BY department ASC");
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
            $days = hr_count_weekdays($start, $end);
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
    $lateToleranceTime = HR_LATE_TOLERANCE_TIME;
    $periodType = $source['type'] ?? 'weekly';
    $allowedTypes = ['weekly', 'monthly', 'yearly', 'custom'];
    if (!in_array($periodType, $allowedTypes, true)) $periodType = 'weekly';

    [$periodStart, $periodEnd, $periodLabel, $periodText] = hr_get_period_range($periodType, $source);

    $keyword = trim((string)($source['keyword'] ?? ''));
    $departmentFilter = trim((string)($source['department'] ?? ''));
    $statusFilter = trim((string)($source['evaluation'] ?? ''));

    $attendanceCols = hr_table_columns($pdo, 'attendances');
    $employeeCols = hr_table_columns($pdo, 'employees');

    $dateColumn = hr_first_col($attendanceCols, ['attendance_date', 'date', 'work_date', 'tanggal']);
    $statusColumn = hr_first_col($attendanceCols, ['status', 'attendance_status']);

    $morningIn = hr_first_col($attendanceCols, ['morning_check_in', 'check_in_morning', 'pagi_masuk', 'clock_in_morning']);
    $morningOut = hr_first_col($attendanceCols, ['morning_check_out', 'check_out_morning', 'pagi_keluar', 'clock_out_morning']);
    $afternoonIn = hr_first_col($attendanceCols, ['afternoon_check_in', 'check_in_afternoon', 'siang_masuk', 'clock_in_afternoon']);
    $afternoonOut = hr_first_col($attendanceCols, ['afternoon_check_out', 'check_out_afternoon', 'siang_keluar', 'clock_out_afternoon']);
    $checkIn = hr_first_col($attendanceCols, ['check_in', 'clock_in', 'time_in']);
    $checkOut = hr_first_col($attendanceCols, ['check_out', 'clock_out', 'time_out']);

    $lateMinutesCol = hr_first_col($attendanceCols, ['late_minutes', 'total_late_minutes', 'minutes_late']);
    $earlyLeaveMinutesCol = hr_first_col($attendanceCols, ['early_leave_minutes', 'total_early_leave_minutes', 'minutes_early_leave']);
    $overtimeMinutesCol = hr_first_col($attendanceCols, ['overtime_minutes', 'total_overtime_minutes', 'lembur_minutes']);

    $lateMinutesExpression = hr_safe_minutes_column_expr($lateMinutesCol);
    $earlyLeaveMinutesExpression = hr_safe_minutes_column_expr($earlyLeaveMinutesCol);
    $overtimeMinutesExpression = $overtimeMinutesCol
        ? hr_safe_minutes_column_expr($overtimeMinutesCol)
        : hr_time_diff_minutes_expr(hr_first_col($attendanceCols, ['overtime_in', 'lembur_masuk', 'overtime_start']), hr_first_col($attendanceCols, ['overtime_out', 'lembur_keluar', 'overtime_end']));

    if ($morningIn && $morningOut && $afternoonIn && $afternoonOut) {
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

        if ($keyword !== '') {
            $employeeWhere .= " AND (e.full_name LIKE :keyword OR e.employee_code LIKE :keyword OR e.position LIKE :keyword OR e.department LIKE :keyword)";
            $params[':keyword'] = '%' . $keyword . '%';
        }

        if ($departmentFilter !== '' && hr_has_column($employeeCols, 'department')) {
            $employeeWhere .= " AND e.department = :department";
            $params[':department'] = $departmentFilter;
        }

        $workPositiveCase = "($workMinutesExpression) > 0";

        if ($statusColumn) {
            $presentCase = "CASE WHEN LOWER(COALESCE(a.`$statusColumn`, '')) IN ('present','hadir','late','terlambat') OR $workPositiveCase THEN 1 ELSE 0 END";
            $absentCase = "CASE WHEN LOWER(COALESCE(a.`$statusColumn`, '')) IN ('absent','alpha','alpa','tidak hadir') THEN 1 ELSE 0 END";
            $lateTimeExpression = $checkIn
                ? "TIME(COALESCE(a.`$checkIn`, '00:00:00'))"
                : "'00:00:00'";
            $lateCase = "CASE 
                WHEN $lateTimeExpression > '$lateToleranceTime'
                THEN 1 
                ELSE 0 
            END";
        } else {
            $presentCase = "CASE WHEN a.id IS NOT NULL AND $workPositiveCase THEN 1 ELSE 0 END";
            $absentCase = "0";
            $lateTimeExpression = $checkIn
                ? "TIME(COALESCE(a.`$checkIn`, '00:00:00'))"
                : "'00:00:00'";
            $lateCase = "CASE 
                WHEN $lateTimeExpression > '$lateToleranceTime'
                THEN 1 
                ELSE 0 
            END";
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
                COALESCE(SUM($absentCase), 0) AS absent_days,
                COALESCE(SUM($lateCase), 0) AS late_days,
                COALESCE(SUM($lateMinutesExpression), 0) AS late_minutes,
                COALESCE(SUM(CASE WHEN $earlyLeaveMinutesExpression > 0 THEN 1 ELSE 0 END), 0) AS early_leave_days,
                COALESCE(SUM($earlyLeaveMinutesExpression), 0) AS early_leave_minutes,
                COALESCE(SUM($workMinutesExpression), 0) AS total_work_minutes,
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
    $workingDays = max(1, hr_count_weekdays($periodStart, $periodEnd));
    $leaveSummary = hr_get_leave_summary($pdo, $periodStart, $periodEnd);

    $standardWorkMinutesPerDay = 420; // 08.00-12.00 + 14.00-17.00 = 7 jam kerja efektif.

    foreach ($rows as &$row) {
        $employeeId = (int)$row['employee_id'];
        $leave = $leaveSummary[$employeeId] ?? [
            'approved_leave_days' => 0,
            'paid_leave_days' => 0,
            'unpaid_leave_days' => 0,
            'sick_days' => 0,
            'official_duty_days' => 0,
        ];

        $presentDays = hr_to_int($row['present_days']);
        $absentDays = hr_to_int($row['absent_days']);
        $lateDays = hr_to_int($row['late_days']);
        $earlyLeaveDays = hr_to_int($row['early_leave_days']);
        $workMinutes = hr_to_int($row['total_work_minutes']);
        $overtimeMinutes = hr_to_int($row['total_overtime_minutes']);

        $approvedLeaveDays = min($workingDays, hr_to_int($leave['approved_leave_days']));
        $paidLeaveDays = hr_to_int($leave['paid_leave_days']);
        $unpaidLeaveDays = hr_to_int($leave['unpaid_leave_days']);
        $officialDutyDays = hr_to_int($leave['official_duty_days']);

        $effectiveWorkDays = max(0, $workingDays - $approvedLeaveDays);
        $attendanceTargetDays = max(1, $effectiveWorkDays);
        $expectedWorkMinutes = max(1, $effectiveWorkDays * $standardWorkMinutesPerDay);

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

        $row['period_days'] = $periodDays;
        $row['working_days'] = $workingDays;
        $row['effective_work_days'] = $effectiveWorkDays;
        $row['standard_work_minutes'] = $expectedWorkMinutes;
        $row['attendance_rate'] = round($attendanceRate, 1);
        $row['work_rate'] = round($workRate, 1);
        $row['discipline_rate'] = round($disciplineRate, 1);
        $row['approved_leave_days'] = $approvedLeaveDays;
        $row['paid_leave_days'] = $paidLeaveDays;
        $row['unpaid_leave_days'] = $unpaidLeaveDays;
        $row['official_duty_days'] = $officialDutyDays;
        $row['score'] = $score;

        if ($effectiveWorkDays <= 0 && $approvedLeaveDays > 0) {
            $row['evaluation_note'] = 'Cuti/Dinas';
            $row['evaluation_class'] = 'primary';
            $row['meeting_note'] = 'Periode ini didominasi pengajuan approved, tidak perlu dibandingkan penuh.';
        } elseif ($presentDays <= 0 && $workMinutes <= 0 && $approvedLeaveDays <= 0) {
            $row['evaluation_note'] = 'Belum ada data';
            $row['evaluation_class'] = 'secondary';
            $row['meeting_note'] = 'Belum ada data absensi pada periode ini.';
        } elseif ($absentDays >= 2 || $score < 55) {
            $row['evaluation_note'] = 'Perlu evaluasi';
            $row['evaluation_class'] = 'danger';
            $row['meeting_note'] = 'Bahas penyebab ketidakhadiran, jam kerja rendah, atau masalah disiplin.';
        } elseif ($lateDays >= 3 || $earlyLeaveDays >= 3 || $score < 70) {
            $row['evaluation_note'] = 'Perlu perhatian';
            $row['evaluation_class'] = 'warning';
            $row['meeting_note'] = 'Evaluasi kedisiplinan dan konsistensi jam kerja.';
        } elseif ($score >= 90 && $lateDays === 0 && $absentDays === 0) {
            $row['evaluation_note'] = 'Sangat baik';
            $row['evaluation_class'] = 'success';
            $row['meeting_note'] = 'Dapat dijadikan contoh kedisiplinan dan konsistensi kerja.';
        } else {
            $row['evaluation_note'] = 'Baik';
            $row['evaluation_class'] = 'primary';
            $row['meeting_note'] = 'Pertahankan performa dan monitoring rutin.';
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
        $summary['present_days'] += hr_to_int($row['present_days']);
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
        'errorMessage' => $errorMessage,
    ];
}
