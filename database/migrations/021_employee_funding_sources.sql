ALTER TABLE employee_cash_advances
    ADD COLUMN funding_source ENUM('company','operational_fund','party_advance') NOT NULL DEFAULT 'company' AFTER deduction_month,
    ADD COLUMN account_id INT NULL AFTER funding_source,
    ADD COLUMN operational_fund_id INT NULL AFTER account_id,
    ADD COLUMN funding_party_id INT NULL AFTER operational_fund_id,
    ADD COLUMN disbursement_status ENUM('draft','disbursed','cancelled') NOT NULL DEFAULT 'disbursed' AFTER funding_party_id,
    ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER disbursement_status,
    ADD COLUMN transaction_id INT NULL AFTER payment_method,
    ADD COLUMN debt_id INT NULL AFTER transaction_id,
    ADD COLUMN created_by INT NULL AFTER debt_id,
    ADD KEY idx_cash_advance_source (funding_source),
    ADD KEY idx_cash_advance_account (account_id),
    ADD KEY idx_cash_advance_opfund (operational_fund_id),
    ADD KEY idx_cash_advance_party (funding_party_id);

ALTER TABLE employee_loans
    ADD COLUMN funding_source ENUM('company','operational_fund','party_advance') NOT NULL DEFAULT 'company' AFTER loan_amount,
    ADD COLUMN account_id INT NULL AFTER funding_source,
    ADD COLUMN operational_fund_id INT NULL AFTER account_id,
    ADD COLUMN funding_party_id INT NULL AFTER operational_fund_id,
    ADD COLUMN disbursement_status ENUM('draft','disbursed','cancelled') NOT NULL DEFAULT 'disbursed' AFTER funding_party_id,
    ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER disbursement_status,
    ADD COLUMN transaction_id INT NULL AFTER payment_method,
    ADD COLUMN debt_id INT NULL AFTER transaction_id,
    ADD COLUMN created_by INT NULL AFTER debt_id,
    ADD KEY idx_employee_loan_source (funding_source),
    ADD KEY idx_employee_loan_account (account_id),
    ADD KEY idx_employee_loan_opfund (operational_fund_id),
    ADD KEY idx_employee_loan_party (funding_party_id);

