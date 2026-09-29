ALTER TABLE transactions ADD COLUMN transaction_name VARCHAR(180) NULL AFTER transaction_date;
UPDATE transactions SET transaction_name=COALESCE(NULLIF(TRIM(description),''),transaction_code) WHERE transaction_name IS NULL OR transaction_name='';
ALTER TABLE transactions MODIFY transaction_name VARCHAR(180) NOT NULL DEFAULT '';

ALTER TABLE transaction_categories DROP INDEX uq_transaction_category_name_type;
UPDATE transaction_categories SET type='both';
ALTER TABLE transaction_categories ADD UNIQUE KEY uq_transaction_category_name (category_name);
