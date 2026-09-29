-- Seluruh hari Senin-Minggu memakai aturan absensi yang sama.
-- Migrasi ini menormalkan seluruh field turunan tanpa mengubah tanggal,
-- check-in/check-out, raw scan, catatan, tarif lembur, atau waktu pembuatan.

CREATE TABLE IF NOT EXISTS attendance_derived_values_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_version VARCHAR(120) NOT NULL,
    attendance_id INT NOT NULL,
    old_status VARCHAR(20) NOT NULL,
    new_status VARCHAR(20) NOT NULL,
    old_attendance_type VARCHAR(40) NOT NULL,
    new_attendance_type VARCHAR(40) NOT NULL,
    old_day_value DECIMAL(4,2) NOT NULL,
    new_day_value DECIMAL(4,2) NOT NULL,
    old_late_minutes INT NOT NULL,
    new_late_minutes INT NOT NULL,
    old_early_leave_minutes INT NOT NULL,
    new_early_leave_minutes INT NOT NULL,
    old_work_minutes INT NOT NULL,
    new_work_minutes INT NOT NULL,
    old_overtime_minutes INT NOT NULL,
    new_overtime_minutes INT NOT NULL,
    old_overtime_amount DECIMAL(15,2) NOT NULL,
    new_overtime_amount DECIMAL(15,2) NOT NULL,
    audited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_derived_audit (migration_version, attendance_id),
    KEY idx_attendance_derived_attendance (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_attendance_base_028;
CREATE TEMPORARY TABLE tmp_attendance_base_028 (
    attendance_id INT NOT NULL PRIMARY KEY,
    new_attendance_type VARCHAR(40) NOT NULL,
    new_day_value DECIMAL(4,2) NOT NULL
) ENGINE=InnoDB;

INSERT INTO tmp_attendance_base_028 (attendance_id, new_attendance_type, new_day_value)
SELECT
    id,
    CASE
        WHEN LOWER(COALESCE(status, '')) IN ('permission','izin','sick','sakit','absent','alpha','alpa','tidak hadir')
          OR check_in IS NULL OR check_out IS NULL THEN 'absent'
        WHEN check_in BETWEEN '07:00:00' AND '08:30:00'
         AND check_out BETWEEN '11:30:00' AND '13:59:59' THEN 'half_day_morning'
        WHEN check_in BETWEEN '11:30:00' AND '13:30:00'
         AND check_out >= '16:30:00' THEN 'half_day_afternoon'
        WHEN check_in BETWEEN '08:00:00' AND '08:30:00'
         AND check_out BETWEEN '16:30:00' AND '17:30:00' THEN 'full_day'
        ELSE 'full_day_irregular'
    END,
    CASE
        WHEN LOWER(COALESCE(status, '')) IN ('permission','izin','sick','sakit','absent','alpha','alpa','tidak hadir')
          OR check_in IS NULL OR check_out IS NULL THEN 0.00
        WHEN check_in BETWEEN '07:00:00' AND '08:30:00'
         AND check_out BETWEEN '11:30:00' AND '13:59:59' THEN 0.50
        WHEN check_in BETWEEN '11:30:00' AND '13:30:00'
         AND check_out >= '16:30:00' THEN 0.50
        ELSE 1.00
    END
FROM attendances;

DROP TEMPORARY TABLE IF EXISTS tmp_attendance_derived_028;
CREATE TEMPORARY TABLE tmp_attendance_derived_028 (
    attendance_id INT NOT NULL PRIMARY KEY,
    new_status VARCHAR(20) NOT NULL,
    new_attendance_type VARCHAR(40) NOT NULL,
    new_day_value DECIMAL(4,2) NOT NULL,
    new_late_minutes INT NOT NULL,
    new_early_leave_minutes INT NOT NULL,
    new_work_minutes INT NOT NULL,
    new_overtime_minutes INT NOT NULL,
    new_overtime_amount DECIMAL(15,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO tmp_attendance_derived_028 (
    attendance_id,
    new_status,
    new_attendance_type,
    new_day_value,
    new_late_minutes,
    new_early_leave_minutes,
    new_work_minutes,
    new_overtime_minutes
)
SELECT
    a.id,
    CASE
        WHEN LOWER(COALESCE(a.status, '')) IN ('permission','sick','absent') THEN LOWER(a.status)
        WHEN a.check_in IS NULL AND a.check_out IS NULL THEN 'absent'
        WHEN b.new_day_value > 0
         AND b.new_attendance_type NOT IN ('half_day_morning','half_day_afternoon')
         AND a.check_in > '08:30:00' THEN 'late'
        ELSE 'present'
    END,
    b.new_attendance_type,
    b.new_day_value,
    CASE
        WHEN b.new_day_value <= 0 OR a.check_in IS NULL THEN 0
        WHEN b.new_attendance_type = 'half_day_afternoon' AND a.check_in > '13:30:00'
            THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.attendance_date, '13:30:00'), TIMESTAMP(a.attendance_date, a.check_in))
        WHEN b.new_attendance_type <> 'half_day_afternoon' AND a.check_in > '08:30:00'
            THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.attendance_date, '08:30:00'), TIMESTAMP(a.attendance_date, a.check_in))
        ELSE 0
    END,
    CASE
        WHEN b.new_day_value <= 0 OR a.check_out IS NULL THEN 0
        WHEN b.new_attendance_type = 'half_day_morning' AND a.check_out < '11:30:00'
            THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.attendance_date, a.check_out), TIMESTAMP(a.attendance_date, '11:30:00'))
        WHEN b.new_attendance_type <> 'half_day_morning' AND a.check_out < '16:30:00'
            THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.attendance_date, a.check_out), TIMESTAMP(a.attendance_date, '16:30:00'))
        ELSE 0
    END,
    CASE
        WHEN b.new_day_value >= 1 THEN 480
        WHEN b.new_day_value > 0 THEN 240
        ELSE 0
    END,
    CASE
        WHEN b.new_day_value <= 0 OR a.check_out IS NULL THEN 0
        WHEN b.new_attendance_type = 'half_day_morning' AND a.check_out > '12:30:00'
            THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.attendance_date, '12:30:00'), TIMESTAMP(a.attendance_date, a.check_out))
        WHEN b.new_attendance_type <> 'half_day_morning' AND a.check_out > '17:30:00'
            THEN TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.attendance_date, '17:30:00'), TIMESTAMP(a.attendance_date, a.check_out))
        ELSE 0
    END
FROM attendances a
INNER JOIN tmp_attendance_base_028 b ON b.attendance_id = a.id;

UPDATE tmp_attendance_derived_028 t
INNER JOIN attendances a ON a.id = t.attendance_id
SET t.new_overtime_amount = CASE
    WHEN t.new_overtime_minutes <= 0 THEN 0
    WHEN COALESCE(a.overtime_method, 'hourly') = 'fixed' THEN GREATEST(0, COALESCE(a.overtime_amount, 0))
    ELSE ROUND(
        (
            FLOOR(t.new_overtime_minutes / 60)
            + CASE WHEN MOD(t.new_overtime_minutes, 60) >= 40 THEN 1 ELSE 0 END
        ) * GREATEST(0, COALESCE(a.overtime_rate, 0)),
        2
    )
END;

START TRANSACTION;

INSERT IGNORE INTO attendance_derived_values_audit (
    migration_version,
    attendance_id,
    old_status,
    new_status,
    old_attendance_type,
    new_attendance_type,
    old_day_value,
    new_day_value,
    old_late_minutes,
    new_late_minutes,
    old_early_leave_minutes,
    new_early_leave_minutes,
    old_work_minutes,
    new_work_minutes,
    old_overtime_minutes,
    new_overtime_minutes,
    old_overtime_amount,
    new_overtime_amount
)
SELECT
    '028_uniform_seven_day_attendance.sql',
    a.id,
    a.status,
    t.new_status,
    a.attendance_type,
    t.new_attendance_type,
    a.attendance_day_value,
    t.new_day_value,
    a.late_minutes,
    t.new_late_minutes,
    a.early_leave_minutes,
    t.new_early_leave_minutes,
    a.work_minutes,
    t.new_work_minutes,
    a.overtime_minutes,
    t.new_overtime_minutes,
    a.overtime_amount,
    t.new_overtime_amount
FROM attendances a
INNER JOIN tmp_attendance_derived_028 t ON t.attendance_id = a.id
WHERE a.status <> t.new_status
   OR a.attendance_type <> t.new_attendance_type
   OR a.attendance_day_value <> t.new_day_value
   OR a.late_minutes <> t.new_late_minutes
   OR a.early_leave_minutes <> t.new_early_leave_minutes
   OR a.work_minutes <> t.new_work_minutes
   OR a.overtime_minutes <> t.new_overtime_minutes
   OR a.overtime_amount <> t.new_overtime_amount;

UPDATE attendances a
INNER JOIN tmp_attendance_derived_028 t ON t.attendance_id = a.id
SET
    a.status = t.new_status,
    a.attendance_type = t.new_attendance_type,
    a.attendance_day_value = t.new_day_value,
    a.late_minutes = t.new_late_minutes,
    a.early_leave_minutes = t.new_early_leave_minutes,
    a.work_minutes = t.new_work_minutes,
    a.overtime_minutes = t.new_overtime_minutes,
    a.overtime_amount = t.new_overtime_amount
WHERE a.status <> t.new_status
   OR a.attendance_type <> t.new_attendance_type
   OR a.attendance_day_value <> t.new_day_value
   OR a.late_minutes <> t.new_late_minutes
   OR a.early_leave_minutes <> t.new_early_leave_minutes
   OR a.work_minutes <> t.new_work_minutes
   OR a.overtime_minutes <> t.new_overtime_minutes
   OR a.overtime_amount <> t.new_overtime_amount;

-- Kolom kalender lama dinetralkan agar instalasi dan konfigurasi lama tidak
-- dapat menghidupkan kembali aturan khusus akhir pekan.
UPDATE payroll_settings
SET normal_work_hours = 8.00,
    off_monday = 0,
    off_tuesday = 0,
    off_wednesday = 0,
    off_thursday = 0,
    off_friday = 0,
    off_saturday = 0,
    off_sunday = 0;

UPDATE projects
SET work_schedule_type = 'all_days_full',
    saturday_work_value = 1.00,
    sunday_work_value = 1.00;

COMMIT;

ALTER TABLE payroll_settings
    MODIFY off_saturday TINYINT(1) NOT NULL DEFAULT 0,
    MODIFY off_sunday TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE projects
    MODIFY work_schedule_type VARCHAR(50) NOT NULL DEFAULT 'all_days_full',
    MODIFY saturday_work_value DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    MODIFY sunday_work_value DECIMAL(3,2) NOT NULL DEFAULT 1.00;

DROP TEMPORARY TABLE IF EXISTS tmp_attendance_derived_028;
DROP TEMPORARY TABLE IF EXISTS tmp_attendance_base_028;
