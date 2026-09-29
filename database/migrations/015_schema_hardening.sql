CREATE TABLE IF NOT EXISTS project_fund_receipts (
    id INT NOT NULL AUTO_INCREMENT,
    fund_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_fund_receipts_fund_id (fund_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- project_milestones dan project_task_logs dipertahankan sebagai arsip legacy.
-- Project Tracking aktif menggunakan project_tasks dan project_task_members.
