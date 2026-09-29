CREATE TABLE company_parties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    party_code VARCHAR(50) NOT NULL,
    party_type ENUM('individual','organization') NOT NULL DEFAULT 'individual',
    display_name VARCHAR(180) NOT NULL,
    employee_id INT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    identity_number VARCHAR(100) NULL,
    tax_number VARCHAR(100) NULL,
    address TEXT NULL,
    bank_name VARCHAR(100) NULL,
    bank_account_number VARCHAR(100) NULL,
    bank_account_name VARCHAR(180) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_party_code (party_code),
    KEY idx_company_party_name (display_name),
    KEY idx_company_party_employee (employee_id),
    KEY idx_company_party_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE company_party_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    party_id INT NOT NULL,
    role_type VARCHAR(60) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    ownership_percent DECIMAL(7,4) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_party_role_party (party_id),
    KEY idx_party_role_type (role_type),
    CONSTRAINT fk_party_role_party FOREIGN KEY (party_id) REFERENCES company_parties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO company_parties (party_code,party_type,display_name,employee_id,status,notes,created_at)
SELECT CONCAT('PIC-',LPAD(MIN(f.id),5,'0')),'individual',f.person_name,f.employee_id,'active','Dimigrasikan dari Dana Operasional.',NOW()
FROM operational_funds f
LEFT JOIN company_parties p ON (f.employee_id IS NOT NULL AND p.employee_id=f.employee_id) OR (f.employee_id IS NULL AND p.display_name=f.person_name)
WHERE p.id IS NULL
GROUP BY f.employee_id,f.person_name;

ALTER TABLE operational_funds
    ADD COLUMN holder_party_id INT NULL AFTER fund_code,
    ADD KEY idx_operational_fund_party (holder_party_id);

UPDATE operational_funds f
JOIN company_parties p ON (f.employee_id IS NOT NULL AND p.employee_id=f.employee_id) OR (f.employee_id IS NULL AND p.display_name=f.person_name)
SET f.holder_party_id=p.id
WHERE f.holder_party_id IS NULL;

ALTER TABLE payrolls
    ADD COLUMN funding_source ENUM('company','operational_fund') NOT NULL DEFAULT 'company' AFTER paid_date,
    ADD COLUMN operational_fund_id INT NULL AFTER funding_source,
    ADD COLUMN payment_party_id INT NULL AFTER operational_fund_id,
    ADD KEY idx_payroll_funding_source (funding_source),
    ADD KEY idx_payroll_operational_fund (operational_fund_id),
    ADD KEY idx_payroll_payment_party (payment_party_id);
