-- Payroll harian tidak memakai konfigurasi hari libur maupun potongan cuti/alpha.
-- Kolom lama dipertahankan agar data historis dan instalasi lama tetap kompatibel.
UPDATE payroll_settings
SET off_monday = 0,
    off_tuesday = 0,
    off_wednesday = 0,
    off_thursday = 0,
    off_friday = 0,
    off_saturday = 0,
    off_sunday = 0,
    unpaid_leave_deduction_method = 'none',
    absent_deduction_method = 'none',
    updated_at = NOW();
