-- Satukan nilai hari Absensi, HR, Project, Dashboard, dan Payroll.
-- Raw scan, status, menit kerja/lembur, nilai uang, dan updated_at tidak diubah.

CREATE TABLE IF NOT EXISTS attendance_classification_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_version VARCHAR(120) NOT NULL,
    attendance_id INT NOT NULL,
    old_attendance_type VARCHAR(40) NOT NULL,
    old_day_value DECIMAL(4,2) NOT NULL,
    new_attendance_type VARCHAR(40) NOT NULL,
    new_day_value DECIMAL(4,2) NOT NULL,
    audited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_classification_audit (migration_version, attendance_id),
    KEY idx_attendance_classification_attendance (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_attendance_classification_025;
CREATE TEMPORARY TABLE tmp_attendance_classification_025 (
    attendance_id INT NOT NULL PRIMARY KEY,
    new_attendance_type VARCHAR(40) NOT NULL,
    new_day_value DECIMAL(4,2) NOT NULL
) ENGINE=InnoDB;

INSERT INTO tmp_attendance_classification_025 (attendance_id, new_attendance_type, new_day_value)
SELECT
    id,
    CASE
        WHEN LOWER(COALESCE(status, '')) IN ('absent','alpha','alpa','tidak hadir') OR check_in IS NULL OR check_out IS NULL THEN 'absent'
        WHEN check_in BETWEEN '07:00:00' AND '08:30:00' AND check_out BETWEEN '11:30:00' AND '13:59:59' THEN 'half_day_morning'
        WHEN check_in BETWEEN '11:30:00' AND '13:30:00' AND check_out >= '16:30:00' THEN 'half_day_afternoon'
        WHEN check_in BETWEEN '08:00:00' AND '08:30:00' AND check_out BETWEEN '16:30:00' AND '17:30:00' THEN 'full_day'
        ELSE 'full_day_irregular'
    END,
    CASE
        WHEN LOWER(COALESCE(status, '')) IN ('absent','alpha','alpa','tidak hadir') OR check_in IS NULL OR check_out IS NULL THEN 0.00
        WHEN check_in BETWEEN '07:00:00' AND '08:30:00' AND check_out BETWEEN '11:30:00' AND '13:59:59' THEN 0.50
        WHEN check_in BETWEEN '11:30:00' AND '13:30:00' AND check_out >= '16:30:00' THEN 0.50
        ELSE 1.00
    END
FROM attendances;

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
    '025_attendance_classification_consistency.sql',
    a.id,
    a.attendance_type,
    a.attendance_day_value,
    t.new_attendance_type,
    t.new_day_value
FROM attendances a
INNER JOIN tmp_attendance_classification_025 t ON t.attendance_id = a.id
WHERE a.attendance_type <> t.new_attendance_type
   OR a.attendance_day_value <> t.new_day_value;

UPDATE attendances a
INNER JOIN tmp_attendance_classification_025 t ON t.attendance_id = a.id
SET
    a.attendance_type = t.new_attendance_type,
    a.attendance_day_value = t.new_day_value
WHERE a.attendance_type <> t.new_attendance_type
   OR a.attendance_day_value <> t.new_day_value;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_attendance_classification_025;
