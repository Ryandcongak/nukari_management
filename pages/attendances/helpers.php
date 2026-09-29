<?php
function attendance_time(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) throw new InvalidArgumentException('Format jam tidak valid.');
    return strlen($value) === 5 ? $value . ':00' : $value;
}
function attendance_minutes(?string $start, ?string $end): int {
    if (!$start || !$end) return 0;
    $a = strtotime('2000-01-01 '.$start); $b = strtotime('2000-01-01 '.$end);
    if ($b <= $a) throw new InvalidArgumentException('Jam keluar harus lebih besar daripada jam masuk.');
    return (int)(($b-$a)/60);
}

require_once __DIR__ . '/../../includes/attendance_overtime_helper.php';

function attendance_calculate(array $input): array {
    $firstFilledTime = static function (array $fields) use ($input): ?string {
        foreach ($fields as $field) {
            if (isset($input[$field]) && trim((string)$input[$field]) !== '') {
                return (string)$input[$field];
            }
        }
        return null;
    };
    $checkIn = $firstFilledTime(['check_in', 'morning_in', 'afternoon_in', 'overtime_in']);
    $checkOut = $firstFilledTime(['check_out', 'overtime_out', 'afternoon_out', 'morning_out']);

    return attendance_simple_calculate(array_merge($input, [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
    ]));
}
