ALTER TABLE project_payment_terms
    ADD COLUMN tax_percentage DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER amount,
    ADD COLUMN tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER tax_percentage,
    ADD COLUMN received_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER tax_amount;

ALTER TABLE project_transactions
    MODIFY COLUMN source_module ENUM('manual','term','fund','worker','asset_sale') NOT NULL DEFAULT 'manual';

UPDATE transactions transaction_record
JOIN asset_sales sale ON sale.transaction_id = transaction_record.id
JOIN assets asset ON asset.id = sale.asset_id
SET transaction_record.project_id = asset.source_project_id,
    transaction_record.source_module = 'asset',
    transaction_record.source_table = 'assets',
    transaction_record.source_id = asset.id
WHERE asset.source_project_id IS NOT NULL;

INSERT INTO project_transactions
    (project_id, transaction_date, transaction_type, funding_source, payment_method, category, description, amount, is_asset, source_module, source_id)
SELECT asset.source_project_id, sale.sale_date, 'income', 'company', sale.payment_method,
       'Penjualan Aset', CONCAT('Penjualan aset: ', asset.asset_name, ' kepada ', COALESCE(NULLIF(sale.buyer_name, ''), '-')),
       sale.sale_price, 0, 'asset_sale', sale.id
FROM asset_sales sale
JOIN assets asset ON asset.id = sale.asset_id
LEFT JOIN project_transactions project_transaction
    ON project_transaction.source_module = 'asset_sale' AND project_transaction.source_id = sale.id
WHERE asset.source_project_id IS NOT NULL
  AND project_transaction.id IS NULL;
