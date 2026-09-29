ALTER TABLE attendances
    ADD COLUMN reviewed_at DATETIME NULL AFTER source,
    ADD COLUMN reviewed_by INT(11) NULL AFTER reviewed_at,
    ADD COLUMN review_note VARCHAR(500) NULL AFTER reviewed_by,
    ADD KEY idx_attendance_reviewed_at (reviewed_at),
    ADD KEY idx_attendance_reviewed_by (reviewed_by),
    ADD CONSTRAINT fk_attendances_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON DELETE SET NULL;
