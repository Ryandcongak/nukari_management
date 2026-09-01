-- Fondasi instalasi lokal Nukari Management.
-- Aman dijalankan berulang kali pada database yang sudah ada.

CREATE TABLE IF NOT EXISTS system_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_type VARCHAR(100) NOT NULL DEFAULT 'Operasional',
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500) NULL,
    due_date DATE NOT NULL,
    priority ENUM('low','warning','danger','urgent') NOT NULL DEFAULT 'warning',
    icon VARCHAR(100) NULL DEFAULT 'bi-bell',
    link_url VARCHAR(500) NULL,
    status ENUM('active','done','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notification_due_status (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    module VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT NULL,
    reference_id BIGINT NULL,
    reference_table VARCHAR(100) NULL,
    ip_address VARCHAR(100) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_created (created_at),
    INDEX idx_activity_reference (reference_table, reference_id),
    INDEX idx_activity_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
