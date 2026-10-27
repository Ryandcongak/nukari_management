-- Empat role resmi aplikasi. VARCHAR dipakai agar perubahan role berikutnya
-- selalu dilakukan melalui validasi aplikasi dan tidak merusak akun lama.
ALTER TABLE users
    MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'admin';
