<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_overtime_helper.php';

$cases = [
    ['half morning', '07:30', '12:00', 'half_day_morning', 0.5],
    ['half morning plus overtime', '07:56', '13:17', 'half_day_morning', 0.5],
    ['half afternoon', '12:30', '17:00', 'half_day_afternoon', 0.5],
    ['half afternoon plus overtime', '12:51', '21:23', 'half_day_afternoon', 0.5],
    ['full day', '08:15', '17:00', 'full_day', 1.0],
    ['irregular full day', '07:55', '16:45', 'full_day_irregular', 1.0],
    ['incomplete scan', '08:00', null, 'absent', 0.0],
    ['permission with scans', '08:00', '18:00', 'absent', 0.0, 'permission'],
    ['sick with scans', '08:00', '18:00', 'absent', 0.0, 'sick'],
];

foreach ($cases as $case) {
    [$label, $checkIn, $checkOut, $expectedType, $expectedValue] = $case;
    $actual = attendance_day_classification($checkIn, $checkOut, $case[5] ?? 'present');
    if ($actual['type'] !== $expectedType || abs((float)$actual['value'] - $expectedValue) > 0.001) {
        fwrite(STDERR, sprintf(
            "FAIL %s: expected %s/%s, got %s/%s\n",
            $label,
            $expectedType,
            $expectedValue,
            $actual['type'],
            $actual['value']
        ));
        exit(1);
    }
}

$incomplete = attendance_day_classification('08:00', null, 'present');
if (empty($incomplete['needs_review'])) {
    fwrite(STDERR, "FAIL incomplete scan must be marked for review\n");
    exit(1);
}

$shortIrregular = attendance_day_classification('16:41', '16:42', 'present');
if (empty($shortIrregular['needs_review']) || $shortIrregular['type'] !== 'full_day_irregular') {
    fwrite(STDERR, "FAIL short irregular scan must be marked for review without changing its stored pay classification\n");
    exit(1);
}

$reviewCases = [
    ['normal attendance', ['check_in' => '08:10', 'check_out' => '17:00', 'status' => 'present'], 'not_required'],
    ['pending incomplete scan', ['check_in' => '08:10', 'check_out' => null, 'status' => 'present'], 'pending'],
    ['confirmed incomplete scan', ['check_in' => '08:10', 'check_out' => null, 'status' => 'present', 'reviewed_at' => '2026-09-29 10:00:00'], 'confirmed'],
    ['pending short scan', ['check_in' => '16:41', 'check_out' => '16:42', 'status' => 'present'], 'pending'],
];
foreach ($reviewCases as [$label, $attendance, $expectedState]) {
    $actualState = attendance_review_state($attendance);
    if ($actualState !== $expectedState) {
        fwrite(STDERR, "FAIL {$label}: expected {$expectedState}, got {$actualState}\n");
        exit(1);
    }
}

$weekdayDates = [
    'Monday' => '2026-09-21',
    'Tuesday' => '2026-09-22',
    'Wednesday' => '2026-09-23',
    'Thursday' => '2026-09-24',
    'Friday' => '2026-09-25',
    'Saturday' => '2026-09-26',
    'Sunday' => '2026-09-27',
];

$uniformCases = [
    ['full day without overtime', '08:15', '17:14', 'present', 'full_day', 1.0, 480, 0],
    ['full day with overtime', '08:15', '18:10', 'present', 'full_day_irregular', 1.0, 480, 40],
    ['half morning', '08:00', '12:00', 'present', 'half_day_morning', 0.5, 240, 0],
    ['half morning with overtime', '08:00', '13:10', 'present', 'half_day_morning', 0.5, 240, 40],
    ['half afternoon', '12:30', '17:00', 'present', 'half_day_afternoon', 0.5, 240, 0],
    ['half afternoon with overtime', '12:30', '18:10', 'present', 'half_day_afternoon', 0.5, 240, 40],
    ['permission never creates overtime', '08:00', '18:10', 'permission', 'absent', 0.0, 0, 0],
];

$uniformAssertions = 0;
foreach ($weekdayDates as $weekday => $date) {
    foreach ($uniformCases as $case) {
        [$label, $checkIn, $checkOut, $status, $expectedType, $expectedDay, $expectedWork, $expectedOvertime] = $case;
        $actual = attendance_simple_calculate([
            'attendance_date' => $date,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'status' => $status,
            'overtime_method' => 'hourly',
            'overtime_rate' => 10000,
        ]);
        $matches = $actual['attendance_type'] === $expectedType
            && abs((float)$actual['attendance_day_value'] - $expectedDay) < 0.001
            && (int)$actual['work_minutes'] === $expectedWork
            && (int)$actual['overtime_minutes'] === $expectedOvertime;
        if (!$matches) {
            fwrite(STDERR, sprintf(
                "FAIL %s on %s: expected %s/%s/%d/%d, got %s/%s/%d/%d\n",
                $label,
                $weekday,
                $expectedType,
                $expectedDay,
                $expectedWork,
                $expectedOvertime,
                $actual['attendance_type'],
                $actual['attendance_day_value'],
                $actual['work_minutes'],
                $actual['overtime_minutes']
            ));
            exit(1);
        }
        $uniformAssertions++;
    }
}

$roundingCases = [39 => 0, 40 => 1, 60 => 1, 99 => 1, 100 => 2];
foreach ($roundingCases as $minutes => $expectedHours) {
    $actualHours = attendance_payable_overtime_hours($minutes);
    if ($actualHours !== $expectedHours) {
        fwrite(STDERR, "FAIL overtime rounding {$minutes}: expected {$expectedHours}, got {$actualHours}\n");
        exit(1);
    }
}

echo "Attendance rule tests passed (" . count($cases) . " classifications, " . count($reviewCases) . " review states, {$uniformAssertions} weekday assertions, " . count($roundingCases) . " rounding assertions).\n";
