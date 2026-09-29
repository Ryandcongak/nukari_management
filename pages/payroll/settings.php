<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payroll_period_helper.php';
require_once __DIR__ . '/../../includes/payroll_loan_helper.php';

$pageTitle = 'Setting Payroll - Nukari';
$error = '';
$success = '';

function payroll_setting_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'payroll_settings'
          AND COLUMN_NAME = :column_name
        LIMIT 1
    ");
    $stmt->execute([':column_name' => $column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function payroll_setting_add_column(PDO $pdo, string $column, string $definition): void
{
    if (!payroll_setting_column_exists($pdo, $column)) {
        $pdo->exec("ALTER TABLE payroll_settings ADD COLUMN {$column} {$definition}");
    }
}

// Auto update struktur tabel agar halaman setting bisa langsung dipakai.
// Jika kolom sudah ada, bagian ini tidak akan mengubah apa pun.
payroll_setting_add_column($pdo, 'normal_work_hours', "DECIMAL(5,2) NOT NULL DEFAULT 8.00 AFTER payday_day");
payroll_setting_add_column($pdo, 'off_monday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER normal_work_hours");
payroll_setting_add_column($pdo, 'off_tuesday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_monday");
payroll_setting_add_column($pdo, 'off_wednesday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_tuesday");
payroll_setting_add_column($pdo, 'off_thursday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_wednesday");
payroll_setting_add_column($pdo, 'off_friday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_thursday");
payroll_setting_add_column($pdo, 'off_saturday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_friday");
payroll_setting_add_column($pdo, 'off_sunday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_saturday");
payroll_setting_add_column($pdo, 'overtime_multiplier', "DECIMAL(5,2) NOT NULL DEFAULT 1.50 AFTER off_sunday");
payroll_setting_add_column($pdo, 'default_overtime_method', "VARCHAR(20) NOT NULL DEFAULT 'hourly' AFTER overtime_multiplier");
payroll_setting_add_column($pdo, 'default_overtime_rate', "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER default_overtime_method");
payroll_setting_add_column($pdo, 'default_overtime_fixed_amount', "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER default_overtime_rate");
payroll_setting_add_column($pdo, 'loan_installment_max_percent', "DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER default_overtime_fixed_amount");
payroll_setting_add_column($pdo, 'bpjs_health_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER overtime_multiplier");
payroll_setting_add_column($pdo, 'bpjs_employment_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER bpjs_health_percent");
payroll_setting_add_column($pdo, 'tax_percent', "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER bpjs_employment_percent");
payroll_setting_add_column($pdo, 'late_tolerance_minutes', "INT NOT NULL DEFAULT 0 AFTER tax_percent");
payroll_setting_add_column($pdo, 'early_leave_tolerance_minutes', "INT NOT NULL DEFAULT 0 AFTER late_tolerance_minutes");
payroll_setting_add_column($pdo, 'unpaid_leave_deduction_method', "VARCHAR(30) NOT NULL DEFAULT 'daily_rate' AFTER early_leave_tolerance_minutes");
payroll_setting_add_column($pdo, 'absent_deduction_method', "VARCHAR(30) NOT NULL DEFAULT 'daily_rate' AFTER unpaid_leave_deduction_method");
payroll_setting_add_column($pdo, 'is_active', "TINYINT(1) NOT NULL DEFAULT 1 AFTER notes");

$stmt = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$setting) {
    $pdo->query("
        INSERT INTO payroll_settings 
        (
            setting_name,
            start_day,
            end_day,
            payday_day,
            normal_work_hours,
            off_saturday,
            off_sunday,
            overtime_multiplier,
            bpjs_health_percent,
            bpjs_employment_percent,
            tax_percent,
            late_tolerance_minutes,
            early_leave_tolerance_minutes,
            unpaid_leave_deduction_method,
            absent_deduction_method,
            notes,
            is_active
        )
        VALUES
        (
            'Default Payroll',
            16,
            15,
            28,
            8.00,
            0,
            0,
            1.50,
            0.00,
            0.00,
            0.00,
            0,
            0,
            'daily_rate',
            'daily_rate',
            'Periode tanggal 16 bulan sebelumnya sampai tanggal 15 bulan payroll.',
            1
        )
    ");

    $stmt = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setting_name = clean_input($_POST['setting_name'] ?? '');
    $start_day = (int)($_POST['start_day'] ?? 16);
    $end_day = (int)($_POST['end_day'] ?? 15);
    $default_overtime_method = in_array(($_POST['default_overtime_method'] ?? 'hourly'), ['hourly', 'fixed'], true) ? $_POST['default_overtime_method'] : 'hourly';
    $default_overtime_rate = max(0, (float)preg_replace('/[^0-9]/', '', (string)($_POST['default_overtime_rate'] ?? 0)));
    $default_overtime_fixed_amount = max(0, (float)preg_replace('/[^0-9]/', '', (string)($_POST['default_overtime_fixed_amount'] ?? 0)));
    $loan_installment_max_percent = (float)($_POST['loan_installment_max_percent'] ?? 50);
    $notes = clean_input($_POST['notes'] ?? '');

    if ($setting_name === '') {
        $error = 'Nama setting wajib diisi.';
    } elseif ($start_day < 1 || $start_day > 31) {
        $error = 'Tanggal mulai harus antara 1 sampai 31.';
    } elseif ($end_day < 1 || $end_day > 31) {
        $error = 'Tanggal tutup buku harus antara 1 sampai 31.';
    } elseif (!payroll_period_is_contiguous($start_day, $end_day)) {
        $error = 'Periode harus berurutan tanpa tanggal ganda atau celah. Untuk tutup buku tanggal 15, tanggal mulai harus 16.';
    } elseif ($loan_installment_max_percent < 1 || $loan_installment_max_percent > 100) {
        $error = 'Batas cicilan loan harus antara 1% sampai 100%.';
    } else {
        $update = $pdo->prepare("
            UPDATE payroll_settings SET
                setting_name = :setting_name,
                start_day = :start_day,
                end_day = :end_day,
                default_overtime_method = :default_overtime_method,
                default_overtime_rate = :default_overtime_rate,
                default_overtime_fixed_amount = :default_overtime_fixed_amount,
                loan_installment_max_percent = :loan_installment_max_percent,
                notes = :notes,
                is_active = 1,
                updated_at = NOW()
            WHERE id = :id
        ");

        $update->execute([
            ':setting_name' => $setting_name,
            ':start_day' => $start_day,
            ':end_day' => $end_day,
            ':default_overtime_method' => $default_overtime_method,
            ':default_overtime_rate' => $default_overtime_rate,
            ':default_overtime_fixed_amount' => $default_overtime_fixed_amount,
            ':loan_installment_max_percent' => $loan_installment_max_percent,
            ':notes' => $notes ?: null,
            ':id' => $setting['id']
        ]);

        $success = 'Setting payroll berhasil diperbarui.';

        $stmt = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1");
        $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function payroll_selected($current, $value): string
{
    return ((string)$current === (string)$value) ? 'selected' : '';
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Setting Payroll</h1>
            <p class="text-muted mb-0">Atur periode tutup buku dan default lembur untuk impor absensi.</p>
        </div>

        <a href="<?= base_url('pages/payroll/index.php'); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <div class="panel">

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success rounded-3">
                <?= htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="row g-3">
            <?= csrf_field(); ?>

            <div class="col-md-12">
                <label class="form-label fw-semibold">Nama Setting</label>
                <input
                    type="text"
                    name="setting_name"
                    class="form-control"
                    value="<?= htmlspecialchars($setting['setting_name'] ?? 'Default Payroll'); ?>"
                    required>
            </div>

            <div class="col-12 mt-2">
                <h5 class="fw-bold mb-0">Periode Payroll</h5>
                <small class="text-muted">Bulan payroll mengikuti bulan tanggal tutup buku.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Mulai Periode</label>
                <input
                    type="number"
                    name="start_day"
                    class="form-control"
                    min="1"
                    max="31"
                    value="<?= htmlspecialchars($setting['start_day'] ?? 16); ?>"
                    required>
                <small class="text-muted">Jika bukan tanggal 1, tanggal mulai diambil dari bulan sebelumnya.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Tutup Buku</label>
                <input
                    type="number"
                    name="end_day"
                    class="form-control"
                    min="1"
                    max="31"
                    value="<?= htmlspecialchars($setting['end_day'] ?? 15); ?>"
                    required>
                <small class="text-muted">Pola aktif: mulai tanggal 16 bulan sebelumnya dan tutup tanggal 15 bulan payroll.</small>
            </div>

            <div class="col-12 mt-4">
                <h5 class="fw-bold mb-0">Default Lembur Impor Absensi</h5>
                <small class="text-muted">Nilai ini dipakai sebagai default saat file absensi diimpor dan tidak mengubah payroll yang sudah tersimpan.</small>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Metode Default Import</label>
                <select name="default_overtime_method" class="form-select">
                    <option value="hourly" <?= payroll_selected($setting['default_overtime_method'] ?? 'hourly', 'hourly'); ?>>Tarif per jam</option>
                    <option value="fixed" <?= payroll_selected($setting['default_overtime_method'] ?? 'hourly', 'fixed'); ?>>Borongan</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Tarif Lembur Default/Jam</label>
                <input type="text" name="default_overtime_rate" class="form-control currency-input" value="<?= htmlspecialchars(number_format((float)($setting['default_overtime_rate'] ?? 0), 0, ',', '.')); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Borongan Default</label>
                <input type="text" name="default_overtime_fixed_amount" class="form-control currency-input" value="<?= htmlspecialchars(number_format((float)($setting['default_overtime_fixed_amount'] ?? 0), 0, ',', '.')); ?>">
            </div>

            <div class="col-12 mt-4">
                <h5 class="fw-bold mb-0">Kebijakan Cicilan Loan</h5>
                <small class="text-muted">Membatasi total cicilan loan seorang karyawan dalam satu periode payroll.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Maksimal Cicilan dari Gaji (%)</label>
                <input type="number" name="loan_installment_max_percent" class="form-control" min="1" max="100" step="0.01" value="<?= htmlspecialchars((string)($setting['loan_installment_max_percent'] ?? 50)); ?>" required>
                <small class="text-muted">Default 50%. Sistem tetap membatasi cicilan agar tidak melebihi sisa pinjaman.</small>
            </div>


            <div class="col-md-12 mt-4">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea
                    name="notes"
                    rows="4"
                    class="form-control"
                    placeholder="Catatan internal untuk periode payroll atau default lembur impor."><?= htmlspecialchars($setting['notes'] ?? ''); ?></textarea>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-save me-1"></i> Simpan Setting
                </button>
            </div>

        </form>
    </div>

    <div class="panel">
        <h5 class="fw-bold mb-3">Ringkasan Setting Aktif</h5>

        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <tbody>
                    <tr>
                        <th style="width: 280px;">Periode Payroll</th>
                        <td>
                            Tanggal <?= htmlspecialchars($setting['start_day']); ?> bulan sebelumnya sampai tanggal <?= htmlspecialchars($setting['end_day']); ?> bulan payroll.
                        </td>
                    </tr>
                    <tr>
                        <th>Jadwal Kerja Aktif</th>
                        <td>Senin–Minggu memakai aturan yang sama: hari penuh bernilai 1 hari (8 jam standar). Setengah hari pagi/siang bernilai 0,5 hari berdasarkan jam absensi.</td>
                    </tr>
                    <tr>
                        <th>Default Lembur Impor</th>
                        <td><?= ($setting['default_overtime_method'] ?? 'hourly') === 'fixed' ? 'Borongan' : 'Tarif per jam'; ?> — tarif/jam Rp <?= number_format((float)($setting['default_overtime_rate'] ?? 0), 0, ',', '.'); ?>, borongan Rp <?= number_format((float)($setting['default_overtime_fixed_amount'] ?? 0), 0, ',', '.'); ?></td>
                    </tr>
                    <tr>
                        <th>Komponen Ringkasan Gaji</th>
                        <td>BPJS, pajak, multiplier lembur, tambahan, dan potongan tetap diatur pada Ringkasan Gaji. Halaman ini tidak mengubah perhitungannya.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted small mt-3 mb-0">
            Record payroll disimpan per bulan tutup buku. Mengubah setting atau gaji saat ini tidak mengubah record bulan sebelumnya kecuali bulan tersebut dipilih lalu dihitung ulang saat masih Draft.
        </p>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
