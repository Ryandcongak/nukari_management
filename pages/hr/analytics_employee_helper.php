<?php

require_once __DIR__ . '/analytics_helper.php';

/**
 * Hanya teruskan parameter periode yang memengaruhi evaluasi individual.
 * Filter tabel seperti keyword/departemen/status tidak boleh membuat hasil
 * seorang karyawan berubah menjadi kosong saat modal atau PDF dibuka.
 */
function hr_employee_evaluation_period_source(array $source): array
{
    $type = strtolower(trim((string)($source['type'] ?? 'monthly')));
    if (!in_array($type, ['monthly', 'yearly', 'weekly', 'custom'], true)) {
        $type = 'monthly';
    }

    $result = ['type' => $type];
    foreach (['month', 'year', 'week_start', 'start_date', 'end_date'] as $key) {
        if (isset($source[$key]) && !is_array($source[$key])) {
            $result[$key] = trim((string)$source[$key]);
        }
    }

    return $result;
}

function hr_employee_detail_events(array $row): array
{
    $events = [];
    $durations = [];

    if (($row['half_day_type'] ?? '') === 'morning') {
        $events[] = 'Setengah hari pagi';
        $durations[] = '0,5 hari';
    } elseif (($row['half_day_type'] ?? '') === 'afternoon') {
        $events[] = 'Setengah hari siang';
        $durations[] = '0,5 hari';
    }
    if (!empty($row['early_arrival_type'])) {
        $events[] = 'Datang lebih awal';
        $durations[] = 'Sangat bagus';
    }
    if ((int)($row['late_minutes'] ?? 0) > 0) {
        $events[] = 'Terlambat';
        $durations[] = hr_minutes_to_text($row['late_minutes']);
    }
    if ((int)($row['early_leave_minutes'] ?? 0) > 0) {
        $events[] = 'Pulang awal';
        $durations[] = hr_minutes_to_text($row['early_leave_minutes']);
    }
    if ((int)($row['overtime_minutes'] ?? 0) > 0) {
        $events[] = 'Lembur';
        $durations[] = hr_minutes_to_text($row['overtime_minutes']);
    }
    if (in_array(strtolower((string)($row['status'] ?? '')), ['absent', 'alpha', 'alpa', 'tidak hadir'], true)) {
        $events[] = 'Alpha';
        $durations[] = 'Tidak hadir';
    }
    if (empty($row['check_in']) xor empty($row['check_out'])) {
        $events[] = 'Scan tidak lengkap';
        $durations[] = 'Perlu koreksi';
    }

    return ['events' => $events, 'durations' => $durations];
}

function hr_build_employee_evaluation(PDO $pdo, int $employeeId, array $source): ?array
{
    if ($employeeId <= 0) return null;

    $periodSource = hr_employee_evaluation_period_source($source);
    [$periodStart, $periodEnd, $periodLabel, $periodText] = hr_get_period_range(
        $pdo,
        $periodSource['type'],
        $periodSource
    );

    $employeeStmt = $pdo->prepare("
        SELECT id, full_name, employee_code, position, department, phone
        FROM employees
        WHERE id = :id
          AND LOWER(COALESCE(employment_status, 'active')) = 'active'
          AND LOWER(COALESCE(position, '')) NOT REGEXP :excluded_positions
        LIMIT 1
    ");
    $employeeStmt->execute([
        ':id' => $employeeId,
        ':excluded_positions' => hr_executive_position_regex(),
    ]);
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) return null;

    $detailStmt = $pdo->prepare("
        SELECT
            attendance_date,
            check_in,
            check_out,
            status,
            attendance_type,
            attendance_day_value,
            CASE
                WHEN attendance_type = 'half_day_morning' THEN 'morning'
                WHEN attendance_type = 'half_day_afternoon' THEN 'afternoon'
                ELSE NULL
            END AS half_day_type,
            COALESCE(late_minutes, 0) AS late_minutes,
            COALESCE(early_leave_minutes, 0) AS early_leave_minutes,
            CASE
                WHEN attendance_type = 'half_day_morning' AND TIME(check_in) < '08:00:00' THEN 'morning'
                WHEN attendance_type = 'half_day_afternoon' AND TIME(check_in) < '12:00:00' THEN 'afternoon'
                WHEN COALESCE(attendance_type, '') NOT IN ('half_day_morning','half_day_afternoon') AND TIME(check_in) < '08:00:00' THEN 'full_day'
                ELSE NULL
            END AS early_arrival_type,
            COALESCE(overtime_minutes, 0) AS overtime_minutes
        FROM attendances
        WHERE employee_id = :employee_id
          AND attendance_date BETWEEN :period_start AND :period_end
          AND (
              COALESCE(late_minutes, 0) > 0
              OR COALESCE(early_leave_minutes, 0) > 0
              OR COALESCE(overtime_minutes, 0) > 0
              OR LOWER(COALESCE(status, '')) IN ('absent', 'alpha', 'alpa', 'tidak hadir')
              OR attendance_type IN ('half_day_morning','half_day_afternoon')
              OR ((check_in IS NULL) <> (check_out IS NULL))
              OR (TIME(check_in) < '08:00:00')
          )
        ORDER BY attendance_date ASC
    ");
    $detailStmt->execute([
        ':employee_id' => $employeeId,
        ':period_start' => $periodStart,
        ':period_end' => $periodEnd,
    ]);
    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $analyticsData = hr_build_analytics($pdo, $periodSource);
    $evaluation = null;
    foreach ($analyticsData['rows'] as $analyticsRow) {
        if ((int)$analyticsRow['employee_id'] === $employeeId) {
            $evaluation = $analyticsRow;
            break;
        }
    }

    if (!$evaluation) return null;

    $counts = [
        'late' => count(array_filter($details, fn($row) => (int)$row['late_minutes'] > 0)),
        'early_leave' => count(array_filter($details, fn($row) => (int)$row['early_leave_minutes'] > 0)),
        'overtime' => count(array_filter($details, fn($row) => (int)$row['overtime_minutes'] > 0)),
        'absent' => count(array_filter($details, fn($row) => in_array(strtolower((string)$row['status']), ['absent', 'alpha', 'alpa', 'tidak hadir'], true))),
        'half_day' => count(array_filter($details, fn($row) => !empty($row['half_day_type']))),
        'early_arrival' => count(array_filter($details, fn($row) => !empty($row['early_arrival_type']))),
        'incomplete_scan' => count(array_filter($details, fn($row) => empty($row['check_in']) xor empty($row['check_out']))),
    ];

    return [
        'employee' => $employee,
        'evaluation' => $evaluation,
        'explanation' => hr_evaluation_explanation($evaluation),
        'details' => $details,
        'counts' => $counts,
        'periodSource' => $periodSource,
        'periodStart' => $periodStart,
        'periodEnd' => $periodEnd,
        'periodLabel' => $periodLabel,
        'periodText' => $periodText,
    ];
}

