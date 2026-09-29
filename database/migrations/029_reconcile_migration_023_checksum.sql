-- Rekonsiliasi terkontrol untuk migrasi 023.
-- Struktur kolom, enum, dan index dari migrasi 023 telah diverifikasi pada
-- database aktif sebelum checksum file diterima sebagai baseline kanonis.

CREATE TABLE IF NOT EXISTS schema_migration_reconciliations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(120) NOT NULL,
    old_checksum CHAR(64) NOT NULL,
    new_checksum CHAR(64) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    reconciled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_migration_reconciliation_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migration_reconciliations
    (version, old_checksum, new_checksum, reason)
SELECT
    version,
    checksum,
    'a6cea0dad0443cbccffe0e15004d242d973c6ebbceaeed0fbebea5f29b578b54',
    'Verified attendance, cash advance, and employee loan schema before reconciliation'
FROM schema_migrations
WHERE version = '023_hr_payroll_attendance_and_legacy_loans.sql'
  AND checksum = '6223c3ef14a9bb2a432fc18d7ce1ca7c5e29b8d0b7ea420b15eb986447d4f121';

UPDATE schema_migrations
SET checksum = 'a6cea0dad0443cbccffe0e15004d242d973c6ebbceaeed0fbebea5f29b578b54'
WHERE version = '023_hr_payroll_attendance_and_legacy_loans.sql'
  AND checksum = '6223c3ef14a9bb2a432fc18d7ce1ca7c5e29b8d0b7ea420b15eb986447d4f121';
