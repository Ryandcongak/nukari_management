-- Jalankan sekali untuk memisahkan jam kerja dan lembur serta menyimpan histori tarif.
ALTER TABLE attendances ADD COLUMN overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER work_minutes;
ALTER TABLE attendances ADD COLUMN overtime_method VARCHAR(20) NOT NULL DEFAULT 'hourly' AFTER overtime_minutes;
ALTER TABLE attendances ADD COLUMN overtime_rate DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER overtime_method;
ALTER TABLE attendances ADD COLUMN overtime_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER overtime_rate;
ALTER TABLE attendances ADD COLUMN raw_scan_times TEXT NULL AFTER overtime_amount;

ALTER TABLE payroll_settings ADD COLUMN default_overtime_method VARCHAR(20) NOT NULL DEFAULT 'hourly' AFTER overtime_multiplier;
ALTER TABLE payroll_settings ADD COLUMN default_overtime_rate DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER default_overtime_method;
ALTER TABLE payroll_settings ADD COLUMN default_overtime_fixed_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER default_overtime_rate;

UPDATE payroll_settings SET normal_work_hours = 8.00;
