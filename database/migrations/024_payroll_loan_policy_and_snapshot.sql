-- Idempotent because older application code may already have created these
-- columns at runtime before the migration runner records version 024.

SET @nukari_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings' AND COLUMN_NAME = 'loan_installment_max_percent'),
    'SELECT 1',
    'ALTER TABLE payroll_settings ADD COLUMN loan_installment_max_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER default_overtime_fixed_amount'
);
PREPARE nukari_stmt FROM @nukari_sql;
EXECUTE nukari_stmt;
DEALLOCATE PREPARE nukari_stmt;

SET @nukari_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls' AND COLUMN_NAME = 'loan_total_snapshot'),
    'SELECT 1',
    'ALTER TABLE payrolls ADD COLUMN loan_total_snapshot DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER loan_deduction'
);
PREPARE nukari_stmt FROM @nukari_sql;
EXECUTE nukari_stmt;
DEALLOCATE PREPARE nukari_stmt;

SET @nukari_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls' AND COLUMN_NAME = 'loan_paid_snapshot'),
    'SELECT 1',
    'ALTER TABLE payrolls ADD COLUMN loan_paid_snapshot DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER loan_total_snapshot'
);
PREPARE nukari_stmt FROM @nukari_sql;
EXECUTE nukari_stmt;
DEALLOCATE PREPARE nukari_stmt;

SET @nukari_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls' AND COLUMN_NAME = 'loan_remaining_snapshot'),
    'SELECT 1',
    'ALTER TABLE payrolls ADD COLUMN loan_remaining_snapshot DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER loan_paid_snapshot'
);
PREPARE nukari_stmt FROM @nukari_sql;
EXECUTE nukari_stmt;
DEALLOCATE PREPARE nukari_stmt;

SET @nukari_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payrolls' AND COLUMN_NAME = 'loan_payment_count_snapshot'),
    'SELECT 1',
    'ALTER TABLE payrolls ADD COLUMN loan_payment_count_snapshot INT UNSIGNED NOT NULL DEFAULT 0 AFTER loan_remaining_snapshot'
);
PREPARE nukari_stmt FROM @nukari_sql;
EXECUTE nukari_stmt;
DEALLOCATE PREPARE nukari_stmt;
