-- Pastikan tanggal mulai saldo tidak berada setelah transaksi/mutasi yang
-- sudah terposting. Migrasi 031 awalnya memakai CURDATE(), sehingga instalasi
-- yang telah mempunyai transaksi lama dapat memiliki baseline yang terlambat.

UPDATE finance_settings fs
JOIN (
    SELECT MIN(posted_date) AS earliest_posted_date
    FROM (
        SELECT MIN(transaction_date) AS posted_date
        FROM transactions
        WHERE posting_mode = 'posted'
        UNION ALL
        SELECT MIN(movement_date) AS posted_date
        FROM operational_fund_movements
        WHERE posting_mode = 'posted'
    ) posted_dates
) first_posted ON first_posted.earliest_posted_date IS NOT NULL
SET fs.cutover_date = first_posted.earliest_posted_date,
    fs.updated_at = NOW()
WHERE fs.id = 1
  AND fs.cutover_date > first_posted.earliest_posted_date;
