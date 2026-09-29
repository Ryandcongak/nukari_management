<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$pageTitle = 'Import Absensi Fingerprint - Nukari';

$error = '';
$success = '';
$imported = 0;
$updated = 0;
$skipped = 0;
$notFoundEmployees = [];

attendance_ensure_extended_schema($pdo);

function clean_value($value)
{
    return trim((string)($value ?? ''));
}

function excel_time_to_mysql($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        try {
            $dateTime = ExcelDate::excelToDateTimeObject($value);
            return $dateTime->format('H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    $value = clean_value($value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/(\d{1,2})[:.](\d{2})/', $value, $m)) {
        return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
    }

    return null;
}

function get_year_month_from_sheet($rows)
{
    foreach ($rows as $row) {
        foreach ($row as $cell) {
            $cell = clean_value($cell);

            if (preg_match('/(\d{4})-(\d{2})-\d{2}\s*~\s*(\d{4})-(\d{2})-\d{2}/', $cell, $m)) {
                return $m[1] . '-' . $m[2];
            }
        }
    }

    return date('Y-m');
}

function get_attendance_date($dayCell, $yearMonth)
{
    $dayCell = clean_value($dayCell);

    if ($dayCell === '') {
        return null;
    }

    if (preg_match('/^(\d{1,2})/', $dayCell, $m)) {
        return $yearMonth . '-' . sprintf('%02d', (int)$m[1]);
    }

    return null;
}

function calculate_minutes($start, $end)
{
    if (!$start || !$end) {
        return 0;
    }

    $startTime = strtotime($start);
    $endTime = strtotime($end);

    if ($endTime <= $startTime) {
        return 0;
    }

    return (int)floor(($endTime - $startTime) / 60);
}

function get_employee_by_fingerprint($pdo, $fingerprintId)
{
    $stmt = $pdo->prepare("
        SELECT id, full_name
        FROM employees
        WHERE fingerprint_id = :fingerprint_id
        LIMIT 1
    ");

    $stmt->execute([
        ':fingerprint_id' => $fingerprintId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function normalize_employee_name($value)
{
    $value = clean_value($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function get_employee_by_name($pdo, $name)
{
    $normalizedName = normalize_employee_name($name);
    if ($normalizedName === '') {
        return null;
    }

    // Nama hanya dipakai sebagai fallback untuk laporan yang memang tidak
    // menyertakan User ID. Mesin kadang mengekspor nama pendek (contoh:
    // "sudiartini" untuk "Ni Wayan Sudiartini" atau "arki" untuk
    // "Arkadius"), maka cocokkan nama/kata/awalan yang tetap unik.
    $matches = [];
    $stmt = $pdo->query("SELECT id, full_name FROM employees ORDER BY id ASC");
    while ($employee = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $employeeName = normalize_employee_name($employee['full_name']);
        $words = preg_split('/\s+/u', $employeeName, -1, PREG_SPLIT_NO_EMPTY);
        $isMatch = $employeeName === $normalizedName || in_array($normalizedName, $words, true);

        if (!$isMatch && strlen($normalizedName) >= 4) {
            foreach ($words as $word) {
                $wordPrefix = substr($word, 0, strlen($normalizedName));
                if (strpos($word, $normalizedName) === 0 || levenshtein($wordPrefix, $normalizedName) <= 1) {
                    $isMatch = true;
                    break;
                }
            }
        }

        if ($isMatch) {
            $matches[] = $employee;
            if (count($matches) > 1) {
                return null;
            }
        }
    }

    return $matches[0] ?? null;
}

function horizontal_report_period(array $rows): ?array
{
    foreach ($rows as $row) {
        foreach ($row as $cell) {
            if (preg_match('/(\d{4})[.\/-](\d{2})[.\/-](\d{2})\s*~\s*(\d{4})[.\/-](\d{2})[.\/-](\d{2})/', clean_value($cell), $m)) {
                return ["{$m[1]}-{$m[2]}-{$m[3]}", "{$m[4]}-{$m[5]}-{$m[6]}"];
            }
        }
    }
    return null;
}

function horizontal_employee_header(array $row): ?array
{
    $fingerprintId = '';
    $name = '';
    foreach ($row as $index => $cell) {
        $label = strtolower(clean_value($cell));
        $label = preg_replace('/[^a-z]+/', ' ', $label);
        if (preg_match('/\buser\s*id\b/', $label)) {
            $raw = $row[$index + 1] ?? '';
            $fingerprintId = is_numeric($raw) ? (string)(int)$raw : clean_value($raw);
        }
        if (preg_match('/\bnama\b|\bname\b/', $label)) {
            $name = clean_value($row[$index + 1] ?? '');
        }
    }
    return ($fingerprintId !== '' || $name !== '')
        ? ['fingerprint_id' => $fingerprintId, 'name' => $name]
        : null;
}

function attendance_import_defaults(PDO $pdo): array
{
    $setting = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'overtime_method' => in_array(($setting['default_overtime_method'] ?? 'hourly'), ['hourly', 'fixed'], true) ? $setting['default_overtime_method'] : 'hourly',
        'overtime_rate' => (float)($setting['default_overtime_rate'] ?? 0),
        'overtime_amount' => (float)($setting['default_overtime_fixed_amount'] ?? 0),
    ];
}

function attendance_import_assert_payroll_editable(PDO $pdo, int $employeeId, string $date): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM payrolls
        WHERE employee_id = :employee_id
          AND :attendance_date BETWEEN period_start AND period_end
          AND (status = 'paid' OR COALESCE(is_locked, 0) = 1)
        LIMIT 1
    ");
    $stmt->execute([
        ':employee_id' => $employeeId,
        ':attendance_date' => $date,
    ]);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException('Import dibatalkan: absensi ' . $date . ' sudah masuk payroll yang dibayar atau dikunci.');
    }
}

function save_simple_import(PDO $pdo, int $employeeId, string $date, array $scanTimes, array $defaults, int &$imported, int &$updated): void
{
    sort($scanTimes);
    $checkIn = $scanTimes[0] ?? null;
    $checkOut = count($scanTimes) >= 2 ? $scanTimes[count($scanTimes) - 1] : null;
    $calculated = attendance_simple_calculate([
        'attendance_date' => $date,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'overtime_method' => $defaults['overtime_method'],
        'overtime_rate' => $defaults['overtime_rate'],
        'overtime_amount' => $defaults['overtime_amount'],
    ]);
    $params = [
        ':employee_id' => $employeeId, ':attendance_date' => $date,
        ':morning_in' => $calculated['morning_in'], ':afternoon_out' => $calculated['afternoon_out'],
        ':check_in' => $calculated['check_in'], ':check_out' => $calculated['check_out'],
        ':late_minutes' => $calculated['late_minutes'], ':early_leave_minutes' => $calculated['early_leave_minutes'],
        ':work_minutes' => $calculated['work_minutes'], ':overtime_minutes' => $calculated['overtime_minutes'],
        ':overtime_method' => $calculated['overtime_method'], ':overtime_rate' => $calculated['overtime_rate'],
        ':overtime_amount' => $calculated['overtime_amount'], ':status' => $calculated['status'],
        ':attendance_type' => $calculated['attendance_type'], ':attendance_day_value' => $calculated['attendance_day_value'],
        ':raw_scan_times' => json_encode($scanTimes),
    ];
    $check = $pdo->prepare("SELECT id,overtime_method,overtime_rate,overtime_amount FROM attendances WHERE employee_id=:employee_id AND attendance_date=:attendance_date LIMIT 1");
    $check->execute([':employee_id' => $employeeId, ':attendance_date' => $date]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        attendance_import_assert_payroll_editable($pdo, $employeeId, $date);
        // Import ulang boleh memperbarui scan, tetapi tidak mengganti histori tarif.
        $historical = attendance_simple_calculate([
            'attendance_date' => $date,
            'check_in' => $checkIn, 'check_out' => $checkOut,
            'overtime_method' => $existing['overtime_method'],
            'overtime_rate' => $existing['overtime_rate'],
            'overtime_amount' => $existing['overtime_amount'],
        ]);
        $params[':overtime_method'] = $historical['overtime_method'];
        $params[':overtime_rate'] = $historical['overtime_rate'];
        $params[':overtime_amount'] = $historical['overtime_amount'];
        $params[':id'] = $existing['id'];
        // employee_id dan attendance_date hanya dipakai untuk pencarian/INSERT.
        // PDO native prepare menolak parameter tambahan pada query UPDATE.
        unset($params[':employee_id'], $params[':attendance_date']);
        $stmt = $pdo->prepare("UPDATE attendances SET morning_in=:morning_in,morning_out=NULL,afternoon_in=NULL,afternoon_out=:afternoon_out,overtime_in=NULL,overtime_out=NULL,check_in=:check_in,check_out=:check_out,late_minutes=:late_minutes,early_leave_minutes=:early_leave_minutes,work_minutes=:work_minutes,overtime_minutes=:overtime_minutes,overtime_method=:overtime_method,overtime_rate=:overtime_rate,overtime_amount=:overtime_amount,raw_scan_times=:raw_scan_times,status=:status,attendance_type=:attendance_type,attendance_day_value=:attendance_day_value,notes='Imported from horizontal fingerprint report',source='fingerprint',updated_at=NOW() WHERE id=:id");
        $stmt->execute($params); $updated++;
    } else {
        $stmt = $pdo->prepare("INSERT INTO attendances (employee_id,attendance_date,morning_in,afternoon_out,check_in,check_out,late_minutes,early_leave_minutes,work_minutes,overtime_minutes,overtime_method,overtime_rate,overtime_amount,raw_scan_times,status,attendance_type,attendance_day_value,notes,source) VALUES (:employee_id,:attendance_date,:morning_in,:afternoon_out,:check_in,:check_out,:late_minutes,:early_leave_minutes,:work_minutes,:overtime_minutes,:overtime_method,:overtime_rate,:overtime_amount,:raw_scan_times,:status,:attendance_type,:attendance_day_value,'Imported from horizontal fingerprint report','fingerprint')");
        $stmt->execute($params); $imported++;
    }
}

function import_horizontal_report(PDO $pdo, array $rows, int &$imported, int &$updated, int &$skipped, array &$notFoundEmployees): bool
{
    $period = horizontal_report_period($rows);
    if (!$period) return false;
    [$periodStart, $periodEnd] = $period;
    $headers = [];
    foreach ($rows as $index => $row) {
        $header = horizontal_employee_header($row);
        if ($header) $headers[] = ['row' => $index] + $header;
    }
    if (!$headers) return false;
    $defaults = attendance_import_defaults($pdo);
    foreach ($headers as $position => $header) {
        $employee = $header['fingerprint_id'] !== ''
            ? get_employee_by_fingerprint($pdo, $header['fingerprint_id'])
            : get_employee_by_name($pdo, $header['name']);
        if (!$employee) {
            $unmatchedKey = $header['fingerprint_id'] !== ''
                ? $header['fingerprint_id']
                : 'Nama: ' . ($header['name'] ?: '-');
            $notFoundEmployees[$unmatchedKey] = $header['name'] ?: '-';
            $skipped++;
            continue;
        }
        $dayRowIndex = $header['row'] + 1;
        $scanEnd = ($headers[$position + 1]['row'] ?? count($rows)) - 1;
        $dateCursor = new DateTimeImmutable($periodStart);
        foreach (($rows[$dayRowIndex] ?? []) as $column => $dayValue) {
            if (!preg_match('/^\d{1,2}$/', clean_value($dayValue))) continue;
            if ($dateCursor->format('Y-m-d') > $periodEnd) break;
            $scanTimes = [];
            for ($scanRow = $dayRowIndex + 1; $scanRow <= $scanEnd; $scanRow++) {
                preg_match_all('/\b([01]?\d|2[0-3]):[0-5]\d\b/', clean_value($rows[$scanRow][$column] ?? ''), $matches);
                foreach ($matches[0] as $time) $scanTimes[] = sprintf('%02d:%s:00', (int)substr($time, 0, strpos($time, ':')), substr($time, strpos($time, ':') + 1));
            }
            if ($scanTimes) save_simple_import($pdo, (int)$employee['id'], $dateCursor->format('Y-m-d'), array_values(array_unique($scanTimes)), $defaults, $imported, $updated);
            $dateCursor = $dateCursor->modify('+1 day');
        }
    }
    return true;
}

function save_attendance_data(
    $pdo,
    $employeeId,
    $attendanceDate,
    $morningIn,
    $morningOut,
    $afternoonIn,
    $afternoonOut,
    $overtimeIn,
    $overtimeOut,
    &$imported,
    &$updated
) {
    $scanTimes = array_values(array_filter([
        $morningIn,
        $morningOut,
        $afternoonIn,
        $afternoonOut,
        $overtimeIn,
        $overtimeOut,
    ]));
    save_simple_import(
        $pdo,
        (int)$employeeId,
        (string)$attendanceDate,
        $scanTimes,
        attendance_import_defaults($pdo),
        $imported,
        $updated
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['attendance_file']) || $_FILES['attendance_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File wajib diupload.';
    } else {
        $file = $_FILES['attendance_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['xls', 'xlsx', 'csv'])) {
            $error = 'Format file harus .xls, .xlsx, atau .csv.';
        } else {
            try {
                validate_upload($file, ['application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/csv','text/plain'], 10485760);
            } catch (Throwable $uploadError) {
                $error = $uploadError->getMessage();
            }
        }
        if ($error === '') {
            $uploadDir = __DIR__ . '/../../uploads/attendance_imports/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $newFileName = 'attendance_' . date('Ymd_His') . '.' . $ext;
            $targetPath = $uploadDir . $newFileName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                $error = 'Gagal menyimpan file upload.';
            } else {
                try {
                    $spreadsheet = IOFactory::load($targetPath);

                    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                        $rows = $sheet->toArray(null, true, true, false);
                        if (import_horizontal_report($pdo, $rows, $imported, $updated, $skipped, $notFoundEmployees)) {
                            continue;
                        }
                        $yearMonth = get_year_month_from_sheet($rows);

                        $employeeBlocks = [];

                        foreach ($rows as $rowIndex => $row) {
                            foreach ($row as $colIndex => $cell) {
                                $cellText = strtolower(clean_value($cell));

                                if ($cellText === 'user id') {
                                    $fingerprintIdRaw = $row[$colIndex + 1] ?? '';
                                    $fingerprintId = clean_value($fingerprintIdRaw);

                                    if (is_numeric($fingerprintIdRaw)) {
                                        $fingerprintId = (string)(int)$fingerprintIdRaw;
                                    }

                                    if ($fingerprintId !== '') {
                                        $name = clean_value($rows[$rowIndex - 1][$colIndex + 1] ?? '');

                                        $employeeBlocks[] = [
                                            'start_col' => max(0, $colIndex - 8),
                                            'fingerprint_id' => $fingerprintId,
                                            'name' => $name
                                        ];
                                    }
                                }
                            }
                        }

                        foreach ($employeeBlocks as $block) {
                            $fingerprintId = $block['fingerprint_id'];
                            $startCol = $block['start_col'];

                            $employee = get_employee_by_fingerprint($pdo, $fingerprintId);

                            if (!$employee) {
                                $notFoundEmployees[$fingerprintId] = $block['name'] ?: '-';
                                $skipped++;
                                continue;
                            }

                            for ($i = 0; $i < count($rows); $i++) {
                                $dayCell = clean_value($rows[$i][$startCol] ?? '');

                                if (!preg_match('/^\d{1,2}\s+/', $dayCell)) {
                                    continue;
                                }

                                $attendanceDate = get_attendance_date($dayCell, $yearMonth);

                                if (!$attendanceDate) {
                                    $skipped++;
                                    continue;
                                }

                                $morningIn = excel_time_to_mysql($rows[$i][$startCol + 1] ?? null);
                                $morningOut = excel_time_to_mysql($rows[$i][$startCol + 3] ?? null);
                                $afternoonIn = excel_time_to_mysql($rows[$i][$startCol + 6] ?? null);
                                $afternoonOut = excel_time_to_mysql($rows[$i][$startCol + 8] ?? null);
                                $overtimeIn = excel_time_to_mysql($rows[$i][$startCol + 10] ?? null);
                                $overtimeOut = excel_time_to_mysql($rows[$i][$startCol + 12] ?? null);

                                if (!$morningIn && !$morningOut && !$afternoonIn && !$afternoonOut && !$overtimeIn && !$overtimeOut) {
                                    $skipped++;
                                    continue;
                                }

                                $legacyScans = array_values(array_filter([$morningIn, $morningOut, $afternoonIn, $afternoonOut, $overtimeIn, $overtimeOut]));
                                save_simple_import($pdo, (int)$employee['id'], $attendanceDate, $legacyScans, attendance_import_defaults($pdo), $imported, $updated);
                            }
                        }
                    }

                    if ($imported === 0 && $updated === 0 && $skipped === 0) {
                        $error = 'Tidak ada data absensi yang terbaca dari file.';
                    } else {
                        $success = "Import selesai. Data baru: {$imported}, data diperbarui: {$updated}, dilewati: {$skipped}.";
                    }
                } catch (Exception $e) {
                    $error = 'Gagal membaca file. Detail: ' . $e->getMessage();
                }
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Import Absensi Fingerprint</h1>
            <p class="text-muted mb-0">Mendukung laporan fingerprint lama dan format horizontal Catatan Kehadiran Karyawan.</p>
        </div>

        <a href="<?= base_url('pages/attendances/index.php'); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <div class="panel">

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success rounded-3">
                <?= htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($notFoundEmployees)): ?>
            <div class="alert alert-warning rounded-3">
                <strong>Beberapa karyawan tidak ditemukan atau memiliki nama ganda di data karyawan:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($notFoundEmployees as $fid => $empName): ?>
                        <li>
                            Identitas: <strong><?= htmlspecialchars($fid); ?></strong>
                            <?= $empName !== '-' ? ' - ' . htmlspecialchars($empName) : ''; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <div class="alert alert-info rounded-3">
                Sistem menggabungkan seluruh scan pada satu tanggal. Scan pertama menjadi <strong>jam datang</strong>, scan terakhir menjadi <strong>jam pulang</strong>. Semua hari memakai nilai standar 8 jam; setengah hari ditentukan dari rentang scan pagi/siang. Hari dengan satu scan tetap diimpor untuk diperiksa manual.
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">File Laporan Fingerprint</label>
                <input type="file" name="attendance_file" class="form-control" accept=".xls,.xlsx,.csv" required>

                <small class="text-muted d-block mt-2">
                    Upload file asli dari mesin fingerprint dalam format .xls, .xlsx, atau .csv.
                </small>
            </div>

            <button type="submit" class="btn btn-nukari">
                <i class="bi bi-upload me-1"></i> Import Absensi
            </button>

        </form>

    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
