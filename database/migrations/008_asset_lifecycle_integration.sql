ALTER TABLE assets
    ADD COLUMN maintenance_required TINYINT(1) NOT NULL DEFAULT 0 AFTER use_type;

CREATE TABLE asset_maintenance_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    plan_name VARCHAR(150) NOT NULL,
    maintenance_category VARCHAR(50) NOT NULL DEFAULT 'service',
    schedule_type ENUM('date','kilometer','hour','combined','incidental') NOT NULL DEFAULT 'date',
    interval_value INT DEFAULT NULL,
    interval_unit ENUM('day','week','month','year','kilometer','hour') DEFAULT NULL,
    last_service_date DATE DEFAULT NULL,
    next_service_date DATE DEFAULT NULL,
    last_meter_value DECIMAL(15,2) DEFAULT NULL,
    next_meter_value DECIMAL(15,2) DEFAULT NULL,
    estimated_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
    reminder_days INT NOT NULL DEFAULT 7,
    notes TEXT,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_asset_maintenance_plans_asset (asset_id),
    KEY idx_asset_maintenance_plans_due (next_service_date, status),
    CONSTRAINT fk_asset_maintenance_plans_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_maintenance_plans_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE asset_maintenance
    ADD COLUMN plan_id INT DEFAULT NULL AFTER asset_id,
    ADD COLUMN project_id INT DEFAULT NULL AFTER plan_id,
    ADD COLUMN account_id INT DEFAULT NULL AFTER project_id,
    ADD COLUMN payment_method ENUM('cash','bank_transfer','qris','other') NOT NULL DEFAULT 'cash' AFTER account_id,
    ADD COLUMN labor_cost DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER cost,
    ADD COLUMN parts_cost DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER labor_cost,
    ADD COLUMN meter_value DECIMAL(15,2) DEFAULT NULL AFTER parts_cost,
    ADD COLUMN condition_before VARCHAR(100) DEFAULT NULL AFTER meter_value,
    ADD COLUMN condition_after VARCHAR(100) DEFAULT NULL AFTER condition_before,
    ADD COLUMN transaction_id INT DEFAULT NULL AFTER proof_file,
    ADD COLUMN project_transaction_id INT DEFAULT NULL AFTER transaction_id,
    ADD KEY idx_asset_maintenance_project (project_id),
    ADD KEY idx_asset_maintenance_transaction (transaction_id),
    ADD KEY idx_asset_maintenance_project_transaction (project_transaction_id);

ALTER TABLE asset_rentals
    ADD COLUMN project_id INT DEFAULT NULL AFTER asset_id,
    ADD KEY idx_asset_rentals_project (project_id);

ALTER TABLE asset_rental_payments
    ADD COLUMN project_id INT DEFAULT NULL AFTER asset_id,
    ADD COLUMN account_id INT DEFAULT NULL AFTER project_id,
    ADD COLUMN project_transaction_id INT DEFAULT NULL AFTER transaction_id,
    ADD KEY idx_asset_rental_payments_project (project_id),
    ADD KEY idx_asset_rental_payments_project_transaction (project_transaction_id);

ALTER TABLE asset_sales
    ADD COLUMN project_id INT DEFAULT NULL AFTER asset_id,
    ADD COLUMN account_id INT DEFAULT NULL AFTER project_id,
    ADD COLUMN project_transaction_id INT DEFAULT NULL AFTER transaction_id,
    ADD KEY idx_asset_sales_project (project_id),
    ADD KEY idx_asset_sales_project_transaction (project_transaction_id);

ALTER TABLE project_transactions
    MODIFY COLUMN source_module ENUM('manual','term','fund','worker','asset_sale','asset_rental','asset_maintenance') NOT NULL DEFAULT 'manual';

UPDATE asset_sales sale
JOIN assets asset ON asset.id = sale.asset_id
LEFT JOIN project_transactions project_transaction
    ON project_transaction.source_module = 'asset_sale' AND project_transaction.source_id = sale.id
SET sale.project_id = COALESCE(sale.project_id, asset.source_project_id),
    sale.project_transaction_id = project_transaction.id
WHERE asset.source_project_id IS NOT NULL;

UPDATE assets
SET condition_status = 'good'
WHERE asset_status = 'sold' AND condition_status = 'lost';
