-- Izin dan sakit dicatat terpisah dari alpha, tetapi tidak dihitung sebagai
-- hari hadir meskipun jam scan terisi.
START TRANSACTION;

INSERT IGNORE INTO attendance_classification_audit (
    migration_version,
    attendance_id,
    old_attendance_type,
    old_day_value,
    new_attendance_type,
    new_day_value
)
SELECT
    '027_attendance_leave_day_values.sql',
    id,
    attendance_type,
    attendance_day_value,
    'absent',
    0.00
FROM attendances
WHERE status IN ('permission','sick')
  AND (attendance_type <> 'absent' OR attendance_day_value <> 0.00);

UPDATE attendances
SET attendance_type = 'absent', attendance_day_value = 0.00
WHERE status IN ('permission','sick')
  AND (attendance_type <> 'absent' OR attendance_day_value <> 0.00);

COMMIT;
