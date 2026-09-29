<?php

// Helper ini juga dipakai langsung oleh modul Payroll. Definisikan fungsi
// waktu dasar jika halaman pemanggil belum memuat helpers Absensi.
if (!function_exists('attendance_time')) {
    function attendance_time(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            throw new InvalidArgumentException('Format jam tidak valid.');
        }
        return strlen($value) === 5 ? $value . ':00' : $value;
    }
}

if (!function_exists('attendance_minutes')) {
    function attendance_minutes(?string $start, ?string $end): int
    {
        if (!$start || !$end) return 0;
        $startTime = strtotime('2000-01-01 ' . $start);
        $endTime = strtotime('2000-01-01 ' . $end);
        if ($endTime <= $startTime) {
            throw new InvalidArgumentException('Jam keluar harus lebih besar daripada jam masuk.');
        }
        return (int)(($endTime - $startTime) / 60);
    }
}

function attendance_extended_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name LIMIT 1");
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    return (bool)$stmt->fetchColumn();
}

function attendance_ensure_extended_schema(PDO $pdo): void
{
    $columns = [
        'overtime_minutes' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER work_minutes",
        'overtime_method' => "VARCHAR(20) NOT NULL DEFAULT 'hourly' AFTER overtime_minutes",
        'overtime_rate' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER overtime_method",
        'overtime_amount' => "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER overtime_rate",
        'raw_scan_times' => "TEXT NULL AFTER overtime_amount",
    ];
    foreach ($columns as $column => $definition) {
        if (!attendance_extended_column_exists($pdo, 'attendances', $column)) {
            $pdo->exec("ALTER TABLE attendances ADD COLUMN {$column} {$definition}");
        }
    }
}

/**
 * Convert overtime minutes to payable whole hours.
 * Remaining minutes of 40 or more are rounded up; 0-39 are rounded down.
 */
function attendance_payable_overtime_hours(int $minutes): int
{
    $minutes = max(0, $minutes);
    $wholeHours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    return $wholeHours + ($remainingMinutes >= 40 ? 1 : 0);
}

/**
 * Nilai kehadiran baku yang dipakai bersama oleh Absensi, HR, dan Payroll.
 * Semua hari memakai aturan yang sama; Sabtu/Minggu tidak lagi otomatis 0,5.
 */
function attendance_day_classification(?string $checkIn, ?string $checkOut, ?string $status = null): array
{
    $checkIn = attendance_time($checkIn);
    $checkOut = attendance_time($checkOut);
    $status = strtolower(trim((string)$status));

    if (in_array($status, ['permission', 'izin'], true)) {
        return ['type' => 'absent', 'label' => 'Izin (Tidak Dihitung Hadir)', 'value' => 0.0, 'needs_review' => false];
    }
    if (in_array($status, ['sick', 'sakit'], true)) {
        return ['type' => 'absent', 'label' => 'Sakit (Tidak Dihitung Hadir)', 'value' => 0.0, 'needs_review' => false];
    }
    if (in_array($status, ['absent', 'alpha', 'alpa', 'tidak hadir'], true)) {
        return ['type' => 'absent', 'label' => 'Tidak Hadir', 'value' => 0.0, 'needs_review' => false];
    }

    if (!$checkIn || !$checkOut) {
        $hasPartialScan = (bool)($checkIn || $checkOut);
        return [
            'type' => 'absent',
            'label' => $hasPartialScan ? 'Scan Tidak Lengkap' : 'Tidak Hadir',
            'value' => 0.0,
            'needs_review' => $hasPartialScan,
        ];
    }

    $between = static function (string $value, string $start, string $end): bool {
        return $value >= $start && $value <= $end;
    };

    // Setengah hari pagi tetap bernilai 0,5 bila pulang sedikit melewati
    // 12:30 karena lembur. Batas sebelum 14:00 sengaja konservatif agar
    // pekerja full-day yang scan istirahat siang tidak salah diklasifikasikan.
    if ($between($checkIn, '07:00:00', '08:30:00') && $between($checkOut, '11:30:00', '13:59:59')) {
        return ['type' => 'half_day_morning', 'label' => 'Setengah Hari Pagi', 'value' => 0.5, 'needs_review' => false];
    }

    // Jam pulang di atas 17:30 merupakan lembur, bukan alasan mengubah shift
    // setengah hari siang menjadi satu hari penuh.
    if ($between($checkIn, '11:30:00', '13:30:00') && $checkOut >= '16:30:00') {
        return ['type' => 'half_day_afternoon', 'label' => 'Setengah Hari Siang', 'value' => 0.5, 'needs_review' => false];
    }

    if ($between($checkIn, '08:00:00', '08:30:00') && $between($checkOut, '16:30:00', '17:30:00')) {
        return ['type' => 'full_day', 'label' => 'Hari Penuh', 'value' => 1.0, 'needs_review' => false];
    }

    // Scan di luar rentang baku tetap dicatat sebagai kehadiran penuh agar data
    // lama tidak kehilangan upah. Pasangan scan berdurasi kurang dari 6 jam
    // perlu dikonfirmasi karena bisa berupa half-day atau scan tidak lengkap.
    // Penanda review tidak mengubah upah secara otomatis.
    $durationMinutes = attendance_minutes($checkIn, $checkOut);
    $needsReview = $durationMinutes < 360;
    return [
        'type' => 'full_day_irregular',
        'label' => $needsReview ? 'Jam Tidak Baku — Perlu Diperiksa' : 'Hadir (Jam Tidak Baku)',
        'value' => 1.0,
        'needs_review' => $needsReview,
    ];
}

function attendance_scan_is_incomplete(?string $checkIn, ?string $checkOut): bool
{
    return (bool)($checkIn || $checkOut) && !($checkIn && $checkOut);
}

function attendance_day_number($value): string
{
    $formatted = number_format((float)$value, 2, ',', '.');
    return rtrim(rtrim($formatted, '0'), ',');
}

/**
 * Calculate effective working minutes from the 08:00 schedule start and
 * exclude the 12:00-14:00 break. An early scan is proof of arrival only and
 * must not increase normal/overtime duration.
 */
function attendance_effective_work_minutes(?string $checkIn, ?string $checkOut): int
{
    if (!$checkIn || !$checkOut) {
        return 0;
    }

    $duration = attendance_minutes($checkIn, $checkOut);
    $actualStart = strtotime('2000-01-01 ' . $checkIn);
    $end = strtotime('2000-01-01 ' . $checkOut);
    $scheduleStart = strtotime('2000-01-01 08:00:00');
    $start = max($actualStart, $scheduleStart);

    if ($end <= $start) {
        return 0;
    }

    $duration = (int)floor(($end - $start) / 60);
    $breakStart = strtotime('2000-01-01 12:00:00');
    $breakEnd = strtotime('2000-01-01 14:00:00');
    $breakOverlapSeconds = max(0, min($end, $breakEnd) - max($start, $breakStart));

    return max(0, $duration - (int)floor($breakOverlapSeconds / 60));
}

/**
 * Nilai normatif seluruh hari adalah 8 jam. Nilai hari payroll ditentukan
 * terpisah oleh attendance_day_classification().
 */
function attendance_regular_minutes_for_date(?string $attendanceDate): int
{
    if (!$attendanceDate) {
        return 480;
    }

    return 480;
}

function attendance_simple_calculate(array $input): array
{
    $checkIn = attendance_time($input['check_in'] ?? $input['morning_in'] ?? null);
    $checkOut = attendance_time($input['check_out'] ?? $input['afternoon_out'] ?? null);
    $attendanceDate = trim((string)($input['attendance_date'] ?? ''));
    $regularMinutes = attendance_regular_minutes_for_date($attendanceDate ?: null);
    $classification = attendance_day_classification($checkIn, $checkOut, $input['status'] ?? null);
    $workMinutes = $classification['value'] >= 1 ? 480 : ($classification['value'] > 0 ? 240 : 0);
    $overtimeStart = $classification['type'] === 'half_day_morning' ? '12:30:00' : '17:30:00';
    $overtimeMinutes = $classification['value'] > 0 && $checkOut && $checkOut > $overtimeStart
        ? attendance_minutes($overtimeStart, $checkOut)
        : 0;
    $scheduledStart = $classification['type'] === 'half_day_afternoon' ? '13:30:00' : '08:30:00';
    $scheduledEnd = $classification['type'] === 'half_day_morning' ? '11:30:00' : '16:30:00';
    $lateMinutes = $classification['value'] > 0 && $checkIn && $checkIn > $scheduledStart
        ? attendance_minutes($scheduledStart, $checkIn)
        : 0;
    $earlyLeaveMinutes = $classification['value'] > 0 && $checkOut && $checkOut < $scheduledEnd
        ? attendance_minutes($checkOut, $scheduledEnd)
        : 0;
    $requestedMethod = $input['overtime_method'] ?? 'hourly';
    $method = in_array($requestedMethod, ['hourly', 'fixed'], true) ? $requestedMethod : 'hourly';
    $rate = max(0, (float)($input['overtime_rate'] ?? 0));
    $fixedAmount = max(0, (float)($input['overtime_amount'] ?? 0));
    $payableOvertimeHours = attendance_payable_overtime_hours($overtimeMinutes);
    $overtimeAmount = $overtimeMinutes <= 0
        ? 0
        : ($method === 'fixed' ? $fixedAmount : $payableOvertimeHours * $rate);
    $requested = $input['status'] ?? 'present';
    $hasScan = (bool)($checkIn || $checkOut);
    $status = in_array($requested, ['permission', 'sick', 'absent'], true)
        ? $requested
        : (!$hasScan ? 'absent' : ($lateMinutes > 0 ? 'late' : 'present'));

    if ($status === 'absent' && $hasScan) {
        throw new InvalidArgumentException('Status Alpha tidak boleh memiliki jam absensi.');
    }

    return [
        'morning_in' => $checkIn,
        'morning_out' => null,
        'afternoon_in' => null,
        'afternoon_out' => $checkOut,
        'overtime_in' => null,
        'overtime_out' => null,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'late_minutes' => $lateMinutes,
        'early_leave_minutes' => $earlyLeaveMinutes,
        'work_minutes' => $workMinutes,
        'overtime_minutes' => $overtimeMinutes,
        'overtime_method' => $method,
        'overtime_rate' => $rate,
        'overtime_amount' => round($overtimeAmount, 2),
        'status' => $status,
        'attendance_type' => $classification['type'],
        'attendance_day_value' => $classification['value'],
    ];
}
