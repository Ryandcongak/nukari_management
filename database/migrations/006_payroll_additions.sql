CREATE TABLE IF NOT EXISTS payroll_additions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    payroll_month VARCHAR(7) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'other',
    addition_name VARCHAR(150) NOT NULL,
    selected_dates TEXT NOT NULL,
    date_count INT UNSIGNED NOT NULL DEFAULT 1,
    unit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payroll_additions_period (payroll_month, employee_id),
    CONSTRAINT fk_payroll_additions_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payrolls
    ADD COLUMN additional_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER overtime_amount,
    ADD COLUMN additional_details LONGTEXT NULL AFTER additional_amount;
