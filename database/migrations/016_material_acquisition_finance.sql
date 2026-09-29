ALTER TABLE raw_material_movements
    ADD COLUMN acquisition_type ENUM('purchase','free','donation','initial_stock','return','other') NULL AFTER reference_type,
    ADD COLUMN funding_source ENUM('none','company','operational_fund','personal','supplier_credit') NOT NULL DEFAULT 'none' AFTER acquisition_type,
    ADD COLUMN account_id INT NULL AFTER funding_source,
    ADD COLUMN operational_fund_id INT NULL AFTER account_id,
    ADD COLUMN personal_employee_id INT NULL AFTER operational_fund_id,
    ADD COLUMN personal_name VARCHAR(150) NULL AFTER personal_employee_id,
    ADD COLUMN supplier_id INT NULL AFTER personal_name,
    ADD COLUMN payment_method ENUM('cash','bank_transfer','qris','other') NOT NULL DEFAULT 'cash' AFTER supplier_id,
    ADD COLUMN reference_code VARCHAR(100) NULL AFTER payment_method,
    ADD COLUMN proof_file VARCHAR(255) NULL AFTER reference_code,
    ADD COLUMN transaction_id INT NULL AFTER proof_file,
    ADD COLUMN debt_id INT NULL AFTER transaction_id,
    ADD KEY idx_material_movement_funding (funding_source),
    ADD KEY idx_material_movement_account (account_id),
    ADD KEY idx_material_movement_opfund (operational_fund_id),
    ADD KEY idx_material_movement_supplier (supplier_id),
    ADD KEY idx_material_movement_transaction (transaction_id),
    ADD KEY idx_material_movement_debt (debt_id);

ALTER TABLE products
    MODIFY product_type VARCHAR(60) NOT NULL DEFAULT 'recycle';

INSERT INTO master_data_items(group_code,item_code,item_name,description,is_system,sort_order) VALUES
('product_type','recycle','Produk Recycle','Produk hasil daur ulang material',1,10),
('product_type','eco_enzyme','Produk Eco Enzyme','Produk berbasis eco enzyme',1,20),
('product_type','fertilizer','Produk Pupuk','Produk pupuk dan nutrisi tanaman',1,30),
('product_type','other','Produk Lainnya','Jenis produk Nukari lainnya',1,999)
ON DUPLICATE KEY UPDATE item_name=VALUES(item_name),description=VALUES(description),status='active';
