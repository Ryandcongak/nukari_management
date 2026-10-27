-- Pisahkan catatan historis dari transaksi yang benar-benar mengubah saldo.
-- Seluruh data lama tetap "posted" agar saldo berjalan tidak berubah saat migrasi.

CREATE TABLE finance_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    cutover_date DATE NULL,
    historical_mode_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO finance_settings (id, cutover_date, historical_mode_enabled)
VALUES (1, CURDATE(), 1);

ALTER TABLE transactions
    ADD COLUMN posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER amount,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD COLUMN migration_batch_id VARCHAR(100) NULL AFTER historical_reason,
    ADD COLUMN posted_at DATETIME NULL AFTER migration_batch_id,
    ADD COLUMN posted_by INT NULL AFTER posted_at,
    ADD COLUMN reversal_of_transaction_id INT NULL AFTER posted_by,
    ADD KEY idx_transactions_posting_mode (posting_mode),
    ADD KEY idx_transactions_posted_date (posting_mode, transaction_date);

UPDATE transactions
SET posted_at = COALESCE(updated_at, created_at),
    posted_by = created_by
WHERE posting_mode = 'posted';

ALTER TABLE project_payment_terms
    ADD COLUMN posting_mode ENUM('posted','historical','opening_outstanding') NOT NULL DEFAULT 'posted' AFTER status,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_project_terms_posting_mode (posting_mode);

ALTER TABLE project_transactions
    ADD COLUMN posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER amount,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_project_transactions_posting_mode (posting_mode);

ALTER TABLE debts
    ADD COLUMN posting_mode ENUM('posted','historical','opening_outstanding') NOT NULL DEFAULT 'posted' AFTER status,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_debts_posting_mode (posting_mode);

ALTER TABLE debt_payments
    ADD COLUMN posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER amount,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_debt_payments_posting_mode (posting_mode);

ALTER TABLE operational_fund_movements
    ADD COLUMN posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER amount,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_opfund_movements_posting_mode (posting_mode);

ALTER TABLE payrolls
    ADD COLUMN payment_posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER payment_transaction_id,
    ADD COLUMN payment_historical_reason VARCHAR(500) NULL AFTER payment_posting_mode;

ALTER TABLE reimbursement_payments
    ADD COLUMN posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER amount,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_reimbursement_payments_posting_mode (posting_mode);

ALTER TABLE employee_cash_advances
    ADD COLUMN posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER disbursement_status,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_cash_advance_posting_mode (posting_mode);

ALTER TABLE employee_loans
    ADD COLUMN posting_mode ENUM('posted','historical') NOT NULL DEFAULT 'posted' AFTER disbursement_status,
    ADD COLUMN historical_reason VARCHAR(500) NULL AFTER posting_mode,
    ADD KEY idx_employee_loan_posting_mode (posting_mode);

UPDATE employee_loans
SET posting_mode = 'historical',
    historical_reason = COALESCE(historical_reason, 'Saldo awal pinjaman sebelum penggunaan sistem')
WHERE loan_origin = 'opening_balance';
