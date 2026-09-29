ALTER TABLE transaction_categories
    ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER type,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_transaction_category_name_type (category_name, type);
