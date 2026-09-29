-- Konsolidasi kategori lama ke nama baku tanpa memutus histori transaksi.
UPDATE transactions SET category_id=(SELECT id FROM transaction_categories WHERE category_name='Gaji / Upah' LIMIT 1)
WHERE category_id IN (SELECT id FROM transaction_categories WHERE category_name='Payroll / Gaji');
DELETE FROM transaction_categories WHERE category_name='Payroll / Gaji';

UPDATE transactions SET category_id=(SELECT id FROM transaction_categories WHERE category_name='Penjualan Produk' LIMIT 1)
WHERE category_id IN (SELECT id FROM transaction_categories WHERE category_name='Pendapatan Produk');
DELETE FROM transaction_categories WHERE category_name='Pendapatan Produk';

UPDATE transactions SET category_id=(SELECT id FROM transaction_categories WHERE category_name='Dana Talangan' LIMIT 1)
WHERE category_id IN (SELECT id FROM transaction_categories WHERE category_name='Dana Talangan Project');
DELETE FROM transaction_categories WHERE category_name='Dana Talangan Project';

-- Kategori uji yang sudah dipakai tetap tersimpan untuk histori, tetapi tidak muncul pada transaksi baru.
UPDATE transaction_categories SET status='inactive' WHERE category_name='Sekop';
