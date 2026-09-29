ALTER TABLE products
    ADD COLUMN base_price DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER description,
    ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER base_price;

UPDATE products
SET base_price=CASE WHEN hpp_price>0 THEN hpp_price WHEN production_cost>0 THEN production_cost ELSE 0 END;

ALTER TABLE product_cashier_sale_items
    ADD COLUMN base_price DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER unit_price;

UPDATE product_cashier_sale_items SET base_price=hpp_price WHERE base_price=0;

