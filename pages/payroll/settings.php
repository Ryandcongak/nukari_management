<?php
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Setting Payroll - Nukari';
$error = '';
$success = '';

function payroll_setting_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM payroll_settings LIKE :column_name");
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
payroll_setting_add_column($pdo, 'normal_work_hours', "DECIMAL(5,2) NOT NULL DEFAULT 7.00 AFTER payday_day");
payroll_setting_add_column($pdo, 'off_monday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER normal_work_hours");
payroll_setting_add_column($pdo, 'off_tuesday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_monday");
payroll_setting_add_column($pdo, 'off_wednesday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_tuesday");
payroll_setting_add_column($pdo, 'off_thursday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_wednesday");
payroll_setting_add_column($pdo, 'off_friday', "TINYINT(1) NOT NULL DEFAULT 0 AFTER off_thursday");
payroll_setting_add_column($pdo, 'off_saturday', "TINYINT(1) NOT NULL DEFAULT 1 AFTER off_friday");
payroll_setting_add_column($pdo, 'off_sunday', "TINYINT(1) NOT NULL DEFAULT 1 AFTER off_saturday");
payroll_setting_add_column($pdo, 'overtime_multiplier', "DECIMAL(5,2) NOT NULL DEFAULT 1.50 AFTER off_sunday");
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
            1,
            31,
            28,
            7.00,
            1,
            1,
            1.50,
            0.00,
            0.00,
            0.00,
            0,
            0,
            'daily_rate',
            'daily_rate',
            'Periode gaji default.',
            1
        )
    ");

    $stmt = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1");
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setting_name = clean_input($_POST['setting_name'] ?? '');
    $start_day = (int)($_POST['start_day'] ?? 1);
    $end_day = (int)($_POST['end_day'] ?? 31);
    $payday_day = (int)($_POST['payday_day'] ?? 28);
    $normal_work_hours = (float)($_POST['normal_work_hours'] ?? 7);

    $off_monday = isset($_POST['off_monday']) ? 1 : 0;
    $off_tuesday = isset($_POST['off_tuesday']) ? 1 : 0;
    $off_wednesday = isset($_POST['off_wednesday']) ? 1 : 0;
    $off_thursday = isset($_POST['off_thursday']) ? 1 : 0;
    $off_friday = isset($_POST['off_friday']) ? 1 : 0;
    $off_saturday = isset($_POST['off_saturday']) ? 1 : 0;
    $off_sunday = isset($_POST['off_sunday']) ? 1 : 0;

    $overtime_multiplier = (float)($_POST['overtime_multiplier'] ?? 1.5);
    $bpjs_health_percent = (float)($_POST['bpjs_health_percent'] ?? 0);
    $bpjs_employment_percent = (float)($_POST['bpjs_employment_percent'] ?? 0);
    $tax_percent = (float)($_POST['tax_percent'] ?? 0);
    $late_tolerance_minutes = (int)($_POST['late_tolerance_minutes'] ?? 0);
    $early_leave_tolerance_minutes = (int)($_POST['early_leave_tolerance_minutes'] ?? 0);
    $unpaid_leave_deduction_method = clean_input($_POST['unpaid_leave_deduction_method'] ?? 'daily_rate');
    $absent_deduction_method = clean_input($_POST['absent_deduction_method'] ?? 'daily_rate');
    $notes = clean_input($_POST['notes'] ?? '');

    $allowedDeductionMethods = ['daily_rate', 'none'];

    if ($setting_name === '') {
        $error = 'Nama setting wajib diisi.';
    } elseif ($start_day < 1 || $start_day > 31) {
        $error = 'Tanggal mulai harus antara 1 sampai 31.';
    } elseif ($end_day < 1 || $end_day > 31) {
        $error = 'Tanggal tutup buku harus antara 1 sampai 31.';
    } elseif ($payday_day < 1 || $payday_day > 31) {
        $error = 'Tanggal gajian harus antara 1 sampai 31.';
    } elseif ($normal_work_hours <= 0 || $normal_work_hours > 24) {
        $error = 'Jam kerja normal harus lebih dari 0 dan maksimal 24 jam.';
    } elseif (($off_monday + $off_tuesday + $off_wednesday + $off_thursday + $off_friday + $off_saturday + $off_sunday) >= 7) {
        $error = 'Minimal harus ada 1 hari kerja dalam seminggu.';
    } elseif ($overtime_multiplier <= 0) {
        $error = 'Multiplier lembur harus lebih dari 0.';
    } elseif ($bpjs_health_percent < 0 || $bpjs_employment_percent < 0 || $tax_percent < 0) {
        $error = 'Persentase BPJS dan pajak tidak boleh minus.';
    } elseif ($late_tolerance_minutes < 0 || $early_leave_tolerance_minutes < 0) {
        $error = 'Toleransi menit tidak boleh minus.';
    } elseif (!in_array($unpaid_leave_deduction_method, $allowedDeductionMethods, true)) {
        $error = 'Metode potongan cuti tidak dibayar tidak valid.';
    } elseif (!in_array($absent_deduction_method, $allowedDeductionMethods, true)) {
        $error = 'Metode potongan alpha tidak valid.';
    } else {
        $update = $pdo->prepare("
            UPDATE payroll_settings SET
                setting_name = :setting_name,
                start_day = :start_day,
                end_day = :end_day,
                payday_day = :payday_day,
                normal_work_hours = :normal_work_hours,
                off_monday = :off_monday,
                off_tuesday = :off_tuesday,
                off_wednesday = :off_wednesday,
                off_thursday = :off_thursday,
                off_friday = :off_friday,
                off_saturday = :off_saturday,
                off_sunday = :off_sunday,
                overtime_multiplier = :overtime_multiplier,
                bpjs_health_percent = :bpjs_health_percent,
                bpjs_employment_percent = :bpjs_employment_percent,
                tax_percent = :tax_percent,
                late_tolerance_minutes = :late_tolerance_minutes,
                early_leave_tolerance_minutes = :early_leave_tolerance_minutes,
                unpaid_leave_deduction_method = :unpaid_leave_deduction_method,
                absent_deduction_method = :absent_deduction_method,
                notes = :notes,
                is_active = 1,
                updated_at = NOW()
            WHERE id = :id
        ");

        $update->execute([
            ':setting_name' => $setting_name,
            ':start_day' => $start_day,
            ':end_day' => $end_day,
            ':payday_day' => $payday_day,
            ':normal_work_hours' => $normal_work_hours,
            ':off_monday' => $off_monday,
            ':off_tuesday' => $off_tuesday,
            ':off_wednesday' => $off_wednesday,
            ':off_thursday' => $off_thursday,
            ':off_friday' => $off_friday,
            ':off_saturday' => $off_saturday,
            ':off_sunday' => $off_sunday,
            ':overtime_multiplier' => $overtime_multiplier,
            ':bpjs_health_percent' => $bpjs_health_percent,
            ':bpjs_employment_percent' => $bpjs_employment_percent,
            ':tax_percent' => $tax_percent,
            ':late_tolerance_minutes' => $late_tolerance_minutes,
            ':early_leave_tolerance_minutes' => $early_leave_tolerance_minutes,
            ':unpaid_leave_deduction_method' => $unpaid_leave_deduction_method,
            ':absent_deduction_method' => $absent_deduction_method,
            ':notes' => $notes ?: null,
            ':id' => $setting['id']
        ]);

        $success = 'Setting payroll berhasil diperbarui.';

        $stmt = $pdo->query("SELECT * FROM payroll_settings ORDER BY id ASC LIMIT 1");
        $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function payroll_checked($value): string
{
    return ((int)$value === 1) ? 'checked' : '';
}

function payroll_selected($current, $value): string
{
    return ((string)$current === (string)$value) ? 'selected' : '';
}

function day_status_text(array $setting): string
{
    $days = [
        'Senin' => 'off_monday',
        'Selasa' => 'off_tuesday',
        'Rabu' => 'off_wednesday',
        'Kamis' => 'off_thursday',
        'Jumat' => 'off_friday',
        'Sabtu' => 'off_saturday',
        'Minggu' => 'off_sunday',
    ];

    $offDays = [];
    foreach ($days as $label => $column) {
        if (!empty($setting[$column])) {
            $offDays[] = $label;
        }
    }

    return $offDays ? implode(', ', $offDays) . ' libur' : 'Tidak ada hari libur mingguan';
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Setting Payroll</h1>
            <p class="text-muted mb-0">Atur periode gaji, hari kerja, potongan, lembur, BPJS, dan pajak payroll.</p>
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
                <small class="text-muted">Mengatur periode perhitungan gaji dan tanggal pembayaran.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Mulai Periode</label>
                <input
                    type="number"
                    name="start_day"
                    class="form-control"
                    min="1"
                    max="31"
                    value="<?= htmlspecialchars($setting['start_day'] ?? 1); ?>"
                    required>
                <small class="text-muted">Contoh: 1 berarti periode mulai tanggal 1.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Tutup Buku</label>
                <input
                    type="number"
                    name="end_day"
                    class="form-control"
                    min="1"
                    max="31"
                    value="<?= htmlspecialchars($setting['end_day'] ?? 31); ?>"
                    required>
                <small class="text-muted">Contoh: 26 berarti periode tutup tanggal 26.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Gajian</label>
                <input
                    type="number"
                    name="payday_day"
                    class="form-control"
                    min="1"
                    max="31"
                    value="<?= htmlspecialchars($setting['payday_day'] ?? 28); ?>"
                    required>
                <small class="text-muted">Contoh: 28 berarti gajian tanggal 28.</small>
            </div>

            <div class="col-12 mt-4">
                <h5 class="fw-bold mb-0">Hari Kerja & Hari Libur Mingguan</h5>
                <small class="text-muted">Centang hari yang dianggap libur. Hari tidak dicentang akan dihitung sebagai hari kerja.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Jam Kerja Normal per Hari</label>
                <input
                    type="number"
                    step="0.01"
                    name="normal_work_hours"
                    class="form-control"
                    min="1"
                    max="24"
                    value="<?= htmlspecialchars($setting['normal_work_hours'] ?? 7); ?>"
                    required>
                <small class="text-muted">Contoh Nukari: 08.00-12.00 dan 14.00-17.00 = 7 jam.</small>
            </div>

            <div class="col-md-8">
                <label class="form-label fw-semibold">Hari Libur Mingguan</label>
                <div class="row g-2">
                    <?php
                    $dayInputs = [
                        'off_monday' => 'Senin',
                        'off_tuesday' => 'Selasa',
                        'off_wednesday' => 'Rabu',
                        'off_thursday' => 'Kamis',
                        'off_friday' => 'Jumat',
                        'off_saturday' => 'Sabtu',
                        'off_sunday' => 'Minggu',
                    ];
                    foreach ($dayInputs as $name => $label):
                    ?>
                        <div class="col-6 col-md-3">
                            <div class="form-check border rounded-3 px-3 py-2 h-100">
                                <input
                                    class="form-check-input ms-0 me-2"
                                    type="checkbox"
                                    name="<?= htmlspecialchars($name); ?>"
                                    id="<?= htmlspecialchars($name); ?>"
                                    <?= payroll_checked($setting[$name] ?? 0); ?>>
                                <label class="form-check-label" for="<?= htmlspecialchars($name); ?>">
                                    <?= htmlspecialchars($label); ?> Libur
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12 mt-4">
                <h5 class="fw-bold mb-0">Lembur, Toleransi & Potongan</h5>
                <small class="text-muted">Mengatur perhitungan lembur, toleransi terlambat, pulang awal, alpha, dan unpaid leave.</small>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Multiplier Lembur</label>
                <input
                    type="number"
                    step="0.01"
                    name="overtime_multiplier"
                    class="form-control"
                    min="0.01"
                    value="<?= htmlspecialchars($setting['overtime_multiplier'] ?? 1.5); ?>"
                    required>
                <small class="text-muted">Contoh: 1.5.</small>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Toleransi Terlambat</label>
                <input
                    type="number"
                    name="late_tolerance_minutes"
                    class="form-control"
                    min="0"
                    value="<?= htmlspecialchars($setting['late_tolerance_minutes'] ?? 0); ?>"
                    required>
                <small class="text-muted">Dalam menit.</small>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Toleransi Pulang Awal</label>
                <input
                    type="number"
                    name="early_leave_tolerance_minutes"
                    class="form-control"
                    min="0"
                    value="<?= htmlspecialchars($setting['early_leave_tolerance_minutes'] ?? 0); ?>"
                    required>
                <small class="text-muted">Dalam menit.</small>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Potongan Alpha</label>
                <select name="absent_deduction_method" class="form-select">
                    <option value="daily_rate" <?= payroll_selected($setting['absent_deduction_method'] ?? 'daily_rate', 'daily_rate'); ?>>Potong sesuai gaji harian</option>
                    <option value="none" <?= payroll_selected($setting['absent_deduction_method'] ?? 'daily_rate', 'none'); ?>>Tidak dipotong otomatis</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Potongan Cuti Tidak Dibayar</label>
                <select name="unpaid_leave_deduction_method" class="form-select">
                    <option value="daily_rate" <?= payroll_selected($setting['unpaid_leave_deduction_method'] ?? 'daily_rate', 'daily_rate'); ?>>Potong sesuai gaji harian</option>
                    <option value="none" <?= payroll_selected($setting['unpaid_leave_deduction_method'] ?? 'daily_rate', 'none'); ?>>Tidak dipotong otomatis</option>
                </select>
                <small class="text-muted">Untuk approved unpaid leave.</small>
            </div>

            <div class="col-12 mt-4">
                <h5 class="fw-bold mb-0">BPJS & Pajak</h5>
                <small class="text-muted">Persentase default yang dapat digunakan dalam perhitungan payroll.</small>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">BPJS Kesehatan (%)</label>
                <input
                    type="number"
                    step="0.01"
                    name="bpjs_health_percent"
                    class="form-control"
                    min="0"
                    value="<?= htmlspecialchars($setting['bpjs_health_percent'] ?? 0); ?>"
                    required>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">BPJS Ketenagakerjaan (%)</label>
                <input
                    type="number"
                    step="0.01"
                    name="bpjs_employment_percent"
                    class="form-control"
                    min="0"
                    value="<?= htmlspecialchars($setting['bpjs_employment_percent'] ?? 0); ?>"
                    required>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Pajak / PPh (%)</label>
                <input
                    type="number"
                    step="0.01"
                    name="tax_percent"
                    class="form-control"
                    min="0"
                    value="<?= htmlspecialchars($setting['tax_percent'] ?? 0); ?>"
                    required>
            </div>

            <div class="col-md-12 mt-4">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea
                    name="notes"
                    rows="4"
                    class="form-control"
                    placeholder="Contoh: Periode gaji dari tanggal 1 sampai tanggal 26. Sabtu dan Minggu libur. Lembur dihitung 1.5 kali rate per jam."><?= htmlspecialchars($setting['notes'] ?? ''); ?></textarea>
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
                            Tanggal <?= htmlspecialchars($setting['start_day']); ?> sampai tanggal <?= htmlspecialchars($setting['end_day']); ?>.
                            Gajian tanggal <?= htmlspecialchars($setting['payday_day']); ?>.
                        </td>
                    </tr>
                    <tr>
                        <th>Jam Kerja Normal</th>
                        <td><?= htmlspecialchars($setting['normal_work_hours'] ?? 7); ?> jam per hari</td>
                    </tr>
                    <tr>
                        <th>Hari Libur Mingguan</th>
                        <td><?= htmlspecialchars(day_status_text($setting)); ?></td>
                    </tr>
                    <tr>
                        <th>Lembur</th>
                        <td>Multiplier <?= htmlspecialchars($setting['overtime_multiplier'] ?? 1.5); ?> kali rate per jam</td>
                    </tr>
                    <tr>
                        <th>BPJS & Pajak</th>
                        <td>
                            BPJS Kesehatan <?= htmlspecialchars($setting['bpjs_health_percent'] ?? 0); ?>%,
                            BPJS Ketenagakerjaan <?= htmlspecialchars($setting['bpjs_employment_percent'] ?? 0); ?>%,
                            Pajak <?= htmlspecialchars($setting['tax_percent'] ?? 0); ?>%.
                        </td>
                    </tr>
                    <tr>
                        <th>Potongan</th>
                        <td>
                            Alpha: <?= (($setting['absent_deduction_method'] ?? 'daily_rate') === 'daily_rate') ? 'potong gaji harian' : 'tidak dipotong otomatis'; ?>.
                            Cuti tidak dibayar: <?= (($setting['unpaid_leave_deduction_method'] ?? 'daily_rate') === 'daily_rate') ? 'potong gaji harian' : 'tidak dipotong otomatis'; ?>.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-muted small mt-3 mb-0">
            Catatan: setting ini menyimpan konfigurasi payroll. Agar semua perhitungan payroll membaca setting baru ini secara penuh, file <strong>payroll/index.php</strong> juga perlu disesuaikan pada bagian perhitungan hari kerja, BPJS, pajak, dan lembur.
        </p>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>