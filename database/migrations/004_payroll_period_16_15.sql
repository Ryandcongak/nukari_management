-- Bulan payroll mengikuti bulan tanggal tutup buku.
-- Contoh: payroll September = 16 Agustus s.d. 15 September; dibayar tanggal 28.
ALTER TABLE payroll_settings
    MODIFY start_day INT NOT NULL DEFAULT 16,
    MODIFY end_day INT NOT NULL DEFAULT 15,
    MODIFY payday_day INT NOT NULL DEFAULT 28;

UPDATE payroll_settings
SET start_day = 16,
    end_day = 15,
    payday_day = 28,
    notes = 'Periode tanggal 16 bulan sebelumnya sampai tanggal 15 bulan payroll.',
    updated_at = NOW();
