<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/attendance_overtime_helper.php';

$rows = $pdo->query("
    SELECT id, attendance_date, status, check_in, check_out,
           attendance_type, attendance_day_value,
           late_minutes, early_leave_minutes, work_minutes,
           overtime_minutes, overtime_method, overtime_rate, overtime_amount
    FROM attendances
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

$storedTotal = 0.0;
$computedTotal = 0.0;
$mismatches = [];
$typeMismatchCount = 0;
$valueMismatchCount = 0;
$statusMismatchCount = 0;
$lateMismatchCount = 0;
$earlyMismatchCount = 0;
$workMismatchCount = 0;
$overtimeMismatchCount = 0;
$overtimeAmountMismatchCount = 0;
$reviewRequiredCount = 0;
$incompleteScanCount = 0;
$shortIrregularCount = 0;
$distribution = [];
$weekdayMismatchCounts = array_fill(1, 7, 0);

foreach ($rows as $row) {
    $computed = attendance_simple_calculate($row);
    $storedValue = (float)($row['attendance_day_value'] ?? 0);
    $storedTotal += $storedValue;
    $computedTotal += (float)$computed['attendance_day_value'];
    $computedType = $computed['attendance_type'];
    $distribution[$computedType] = ($distribution[$computedType] ?? 0) + 1;
    $classification = attendance_day_classification($row['check_in'] ?? null, $row['check_out'] ?? null, $row['status'] ?? null);
    if (!empty($classification['needs_review'])) {
        $reviewRequiredCount++;
        if (attendance_scan_is_incomplete($row['check_in'] ?? null, $row['check_out'] ?? null)) {
            $incompleteScanCount++;
        } else {
            $shortIrregularCount++;
        }
    }
    $typeMismatch = ($row['attendance_type'] ?? '') !== $computedType;
    $valueMismatch = abs($storedValue - (float)$computed['attendance_day_value']) > 0.001;
    $statusMismatch = ($row['status'] ?? '') !== $computed['status'];
    $lateMismatch = (int)($row['late_minutes'] ?? 0) !== (int)$computed['late_minutes'];
    $earlyMismatch = (int)($row['early_leave_minutes'] ?? 0) !== (int)$computed['early_leave_minutes'];
    $workMismatch = (int)($row['work_minutes'] ?? 0) !== (int)$computed['work_minutes'];
    $overtimeMismatch = (int)($row['overtime_minutes'] ?? 0) !== (int)$computed['overtime_minutes'];
    $overtimeAmountMismatch = abs((float)($row['overtime_amount'] ?? 0) - (float)$computed['overtime_amount']) > 0.005;
    if ($typeMismatch) $typeMismatchCount++;
    if ($valueMismatch) $valueMismatchCount++;
    if ($statusMismatch) $statusMismatchCount++;
    if ($lateMismatch) $lateMismatchCount++;
    if ($earlyMismatch) $earlyMismatchCount++;
    if ($workMismatch) $workMismatchCount++;
    if ($overtimeMismatch) $overtimeMismatchCount++;
    if ($overtimeAmountMismatch) $overtimeAmountMismatchCount++;
    if ($typeMismatch || $valueMismatch || $statusMismatch || $lateMismatch || $earlyMismatch || $workMismatch || $overtimeMismatch || $overtimeAmountMismatch) {
        $mismatches[] = (int)$row['id'];
        $weekday = (int)(new DateTimeImmutable((string)$row['attendance_date']))->format('N');
        $weekdayMismatchCounts[$weekday]++;
    }
}

ksort($distribution);
echo json_encode([
    'rows' => count($rows),
    'stored_day_total' => $storedTotal,
    'computed_day_total' => $computedTotal,
    'mismatch_count' => count($mismatches),
    'type_mismatch_count' => $typeMismatchCount,
    'value_mismatch_count' => $valueMismatchCount,
    'status_mismatch_count' => $statusMismatchCount,
    'late_mismatch_count' => $lateMismatchCount,
    'early_leave_mismatch_count' => $earlyMismatchCount,
    'work_mismatch_count' => $workMismatchCount,
    'overtime_mismatch_count' => $overtimeMismatchCount,
    'overtime_amount_mismatch_count' => $overtimeAmountMismatchCount,
    'mismatch_ids' => array_slice($mismatches, 0, 50),
    'mismatch_by_weekday' => [
        'monday' => $weekdayMismatchCounts[1],
        'tuesday' => $weekdayMismatchCounts[2],
        'wednesday' => $weekdayMismatchCounts[3],
        'thursday' => $weekdayMismatchCounts[4],
        'friday' => $weekdayMismatchCounts[5],
        'saturday' => $weekdayMismatchCounts[6],
        'sunday' => $weekdayMismatchCounts[7],
    ],
    'review_required_count' => $reviewRequiredCount,
    'incomplete_scan_count' => $incompleteScanCount,
    'short_irregular_scan_count' => $shortIrregularCount,
    'computed_type_distribution' => $distribution,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($mismatches ? 1 : 0);
