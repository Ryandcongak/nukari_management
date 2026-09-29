<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/attendance_overtime_helper.php';

$requiredColumns = ['reviewed_at', 'reviewed_by', 'review_note'];
$columns = $pdo->query("SHOW COLUMNS FROM attendances")->fetchAll(PDO::FETCH_COLUMN);
foreach ($requiredColumns as $column) {
    if (!in_array($column, $columns, true)) {
        fwrite(STDERR, "FAIL kolom {$column} belum tersedia. Jalankan migrasi terlebih dahulu.\n");
        exit(1);
    }
}

$attendance = $pdo->query("
    SELECT *
    FROM attendances
    WHERE ((check_in IS NULL) <> (check_out IS NULL))
       OR (
            check_in IS NOT NULL
        AND check_out IS NOT NULL
        AND attendance_type = 'full_day_irregular'
        AND TIMESTAMPDIFF(MINUTE, TIMESTAMP(attendance_date, check_in), TIMESTAMP(attendance_date, check_out)) < 360
       )
    ORDER BY id
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$attendance) {
    echo "Attendance review tracking test passed (schema valid; tidak ada data anomali).\n";
    exit(0);
}

$reviewerId = $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
$originalReview = [
    'reviewed_at' => $attendance['reviewed_at'] ?? null,
    'reviewed_by' => $attendance['reviewed_by'] ?? null,
    'review_note' => $attendance['review_note'] ?? null,
];

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE attendances SET reviewed_at=NOW(),reviewed_by=:reviewed_by,review_note=:review_note WHERE id=:id");
    $stmt->execute([
        ':reviewed_by' => $reviewerId !== false ? (int)$reviewerId : null,
        ':review_note' => 'Pengujian transaksi review; perubahan akan di-rollback.',
        ':id' => (int)$attendance['id'],
    ]);
    $check = $pdo->prepare("SELECT * FROM attendances WHERE id=:id");
    $check->execute([':id' => (int)$attendance['id']]);
    $confirmed = $check->fetch(PDO::FETCH_ASSOC);
    if (!$confirmed || attendance_review_state($confirmed) !== 'confirmed') {
        throw new RuntimeException('Status review tidak berubah menjadi confirmed.');
    }
    if ((string)$confirmed['attendance_day_value'] !== (string)$attendance['attendance_day_value']
        || $confirmed['check_in'] !== $attendance['check_in']
        || $confirmed['check_out'] !== $attendance['check_out']) {
        throw new RuntimeException('Konfirmasi review mengubah data kehadiran.');
    }
    $pdo->rollBack();

    $check->execute([':id' => (int)$attendance['id']]);
    $restored = $check->fetch(PDO::FETCH_ASSOC);
    foreach ($originalReview as $field => $value) {
        if (($restored[$field] ?? null) != $value) {
            throw new RuntimeException("Rollback tidak memulihkan {$field}.");
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Attendance review tracking test passed (confirm + rollback, nilai kehadiran tetap).\n";
