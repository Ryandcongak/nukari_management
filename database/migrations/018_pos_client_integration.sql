ALTER TABLE product_cashier_sales
    ADD COLUMN client_id INT NULL AFTER sale_date,
    ADD KEY idx_cashier_sales_client (client_id),
    ADD CONSTRAINT fk_cashier_sales_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;

ALTER TABLE product_sales
    ADD COLUMN client_id INT NULL AFTER sale_date,
    ADD KEY idx_product_sales_client (client_id),
    ADD CONSTRAINT fk_product_sales_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;

ALTER TABLE product_custom_orders
    ADD COLUMN client_id INT NULL AFTER order_code,
    ADD COLUMN customer_email VARCHAR(150) NULL AFTER customer_phone,
    ADD KEY idx_custom_orders_client (client_id),
    ADD CONSTRAINT fk_custom_orders_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;
