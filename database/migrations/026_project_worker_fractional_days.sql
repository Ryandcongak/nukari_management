-- Project worker cost must preserve half-day attendance values.
ALTER TABLE project_workers
    MODIFY COLUMN work_days DECIMAL(10,2) NOT NULL DEFAULT 1.00;
