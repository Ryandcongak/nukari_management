-- Tim multi-anggota untuk daily project tracking sederhana.
CREATE TABLE project_task_members (
    task_id INT NOT NULL,
    employee_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id, employee_id),
    KEY idx_task_member_employee (employee_id),
    CONSTRAINT fk_task_member_task FOREIGN KEY (task_id) REFERENCES project_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_member_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
