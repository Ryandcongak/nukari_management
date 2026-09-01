<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$pageTitle = 'Import Absensi Fingerprint - Nukari';

$error = '';
$success = '';
$imported = 0;
$updated = 0;
$skipped = 0;
$notFoundEmployees = [];

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
    $checkIn = $morningIn;
    $checkOut = $afternoonOut ?: $morningOut;

    $lateMinutes = 0;
    if ($morningIn && $morningIn > '08:00:00') {
        $lateMinutes = calculate_minutes('08:00:00', $morningIn);
    }

    $earlyLeaveMinutes = 0;
    if ($afternoonOut && $afternoonOut < '17:00:00') {
        $earlyLeaveMinutes = calculate_minutes($afternoonOut, '17:00:00');
    }

    $workMinutes = 0;

    if ($morningIn && $morningOut) {
        $workMinutes += calculate_minutes($morningIn, $morningOut);
    }

    if ($afternoonIn && $afternoonOut) {
        $workMinutes += calculate_minutes($afternoonIn, $afternoonOut);
    }

    if ($overtimeIn && $overtimeOut) {
        $workMinutes += calculate_minutes($overtimeIn, $overtimeOut);
    }

    $status = 'present';

    if (!$morningIn && !$morningOut && !$afternoonIn && !$afternoonOut && !$overtimeIn && !$overtimeOut) {
        $status = 'absent';
    } elseif ($lateMinutes > 0) {
        $status = 'late';
    }

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM attendances
        WHERE employee_id = :employee_id
        AND attendance_date = :attendance_date
        LIMIT 1
    ");

    $checkStmt->execute([
        ':employee_id' => $employeeId,
        ':attendance_date' => $attendanceDate
    ]);

    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE attendances SET
                morning_in = :morning_in,
                morning_out = :morning_out,
                afternoon_in = :afternoon_in,
                afternoon_out = :afternoon_out,
                overtime_in = :overtime_in,
                overtime_out = :overtime_out,
                check_in = :check_in,
                check_out = :check_out,
                late_minutes = :late_minutes,
                early_leave_minutes = :early_leave_minutes,
                work_minutes = :work_minutes,
                status = :status,
                notes = :notes,
                source = 'fingerprint',
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':morning_in' => $morningIn,
            ':morning_out' => $morningOut,
            ':afternoon_in' => $afternoonIn,
            ':afternoon_out' => $afternoonOut,
            ':overtime_in' => $overtimeIn,
            ':overtime_out' => $overtimeOut,
            ':check_in' => $checkIn,
            ':check_out' => $checkOut,
            ':late_minutes' => $lateMinutes,
            ':early_leave_minutes' => $earlyLeaveMinutes,
            ':work_minutes' => $workMinutes,
            ':status' => $status,
            ':notes' => 'Imported from fingerprint Excel',
            ':id' => $existing['id']
        ]);

        $updated++;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO attendances (
                employee_id,
                attendance_date,
                morning_in,
                morning_out,
                afternoon_in,
                afternoon_out,
                overtime_in,
                overtime_out,
                check_in,
                check_out,
                late_minutes,
                early_leave_minutes,
                work_minutes,
                status,
                notes,
                source
            ) VALUES (
                :employee_id,
                :attendance_date,
                :morning_in,
                :morning_out,
                :afternoon_in,
                :afternoon_out,
                :overtime_in,
                :overtime_out,
                :check_in,
                :check_out,
                :late_minutes,
                :early_leave_minutes,
                :work_minutes,
                :status,
                :notes,
                'fingerprint'
            )
        ");

        $stmt->execute([
            ':employee_id' => $employeeId,
            ':attendance_date' => $attendanceDate,
            ':morning_in' => $morningIn,
            ':morning_out' => $morningOut,
            ':afternoon_in' => $afternoonIn,
            ':afternoon_out' => $afternoonOut,
            ':overtime_in' => $overtimeIn,
            ':overtime_out' => $overtimeOut,
            ':check_in' => $checkIn,
            ':check_out' => $checkOut,
            ':late_minutes' => $lateMinutes,
            ':early_leave_minutes' => $earlyLeaveMinutes,
            ':work_minutes' => $workMinutes,
            ':status' => $status,
            ':notes' => 'Imported from fingerprint Excel'
        ]);

        $imported++;
    }
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

                                save_attendance_data(
                                    $pdo,
                                    $employee['id'],
                                    $attendanceDate,
                                    $morningIn,
                                    $morningOut,
                                    $afternoonIn,
                                    $afternoonOut,
                                    $overtimeIn,
                                    $overtimeOut,
                                    $imported,
                                    $updated
                                );
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
            <p class="text-muted mb-0">Upload langsung file Excel hasil download dari mesin fingerprint.</p>
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
                <strong>Beberapa Fingerprint ID tidak ditemukan di data karyawan:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($notFoundEmployees as $fid => $empName): ?>
                        <li>
                            Fingerprint ID: <strong><?= htmlspecialchars($fid); ?></strong>
                            <?= $empName !== '-' ? ' - ' . htmlspecialchars($empName) : ''; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

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
