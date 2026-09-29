-- Finance consolidation, payroll payment source, project term PIC, and project tracking.
-- Designed for MySQL 5.7. Run once after migrations 001-008.

ALTER TABLE payrolls
    ADD COLUMN payment_account_id INT NULL AFTER paid_date,
    ADD COLUMN payment_transaction_id INT NULL AFTER payment_account_id,
    ADD KEY idx_payroll_payment_account (payment_account_id),
    ADD KEY idx_payroll_payment_transaction (payment_transaction_id);

ALTER TABLE project_payment_terms
    ADD COLUMN pic_employee_id INT NULL AFTER received_amount,
    ADD COLUMN pic_name VARCHAR(150) NULL AFTER pic_employee_id,
    ADD KEY idx_project_terms_pic (pic_employee_id);

ALTER TABLE debts
    MODIFY source_module ENUM('manual','project_term','custom_order','reimbursement','asset_rental','payroll','product_sale','operational_fund','other') NOT NULL DEFAULT 'manual',
    ADD COLUMN principal_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER amount,
    ADD COLUMN interest_percentage DECIMAL(7,3) NOT NULL DEFAULT 0 AFTER principal_amount,
    ADD COLUMN interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER interest_percentage,
    ADD COLUMN receiving_account_id INT NULL AFTER interest_amount,
    ADD COLUMN funding_transaction_id INT NULL AFTER receiving_account_id,
    ADD KEY idx_debts_receiving_account (receiving_account_id),
    ADD KEY idx_debts_funding_transaction (funding_transaction_id);

UPDATE debts SET principal_amount = amount WHERE principal_amount = 0;

CREATE TABLE project_milestones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    start_date DATE NULL,
    due_date DATE NOT NULL,
    status ENUM('not_started','in_progress','in_review','completed') NOT NULL DEFAULT 'not_started',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_milestone_project (project_id),
    KEY idx_milestone_due (due_date, status),
    CONSTRAINT fk_milestone_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    milestone_id INT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    assignee_employee_id INT NULL,
    start_at DATETIME NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    status ENUM('not_started','in_progress','in_review','completed') NOT NULL DEFAULT 'not_started',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_task_project (project_id),
    KEY idx_task_milestone (milestone_id),
    KEY idx_task_assignee (assignee_employee_id),
    KEY idx_task_due (due_at, status),
    CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_milestone FOREIGN KEY (milestone_id) REFERENCES project_milestones(id) ON DELETE SET NULL,
    CONSTRAINT fk_task_assignee FOREIGN KEY (assignee_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_task_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    employee_id INT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    progress_percent TINYINT UNSIGNED NULL,
    activity_note TEXT NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_task_log_task (task_id),
    KEY idx_task_log_employee (employee_id),
    KEY idx_task_log_started (started_at),
    CONSTRAINT fk_task_log_task FOREIGN KEY (task_id) REFERENCES project_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_log_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
