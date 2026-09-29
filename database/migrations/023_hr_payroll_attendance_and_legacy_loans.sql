-- Penyatuan aturan Absensi, HR Analytics, dan Payroll.
-- Jalankan satu kali setelah migration 021.

ALTER TABLE attendances
    ADD COLUMN attendance_type ENUM('full_day','full_day_irregular','half_day_morning','half_day_afternoon','absent') NOT NULL DEFAULT 'full_day' AFTER status,
    ADD COLUMN attendance_day_value DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER attendance_type,
    ADD KEY idx_attendance_type (attendance_type);

ALTER TABLE employee_cash_advances
    MODIFY COLUMN status ENUM('active','allocated','paid','cancelled') NOT NULL DEFAULT 'active',
    ADD COLUMN allocated_payroll_id INT NULL AFTER remaining_amount,
    ADD COLUMN deducted_at DATETIME NULL AFTER allocated_payroll_id,
    ADD KEY idx_cash_advance_payroll (allocated_payroll_id);

ALTER TABLE employee_loans
    ADD COLUMN loan_origin ENUM('new_disbursement','opening_balance') NOT NULL DEFAULT 'new_disbursement' AFTER loan_date,
    ADD COLUMN opening_paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER loan_amount,
    ADD COLUMN opening_balance_date DATE NULL AFTER opening_paid_amount,
    ADD KEY idx_employee_loan_origin (loan_origin);

UPDATE employee_loans
SET loan_origin = 'new_disbursement'
WHERE loan_origin IS NULL OR loan_origin = '';
