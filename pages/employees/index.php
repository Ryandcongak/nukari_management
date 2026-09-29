<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/master_data_helper.php';

$pageTitle = 'Karyawan & Internship - Nukari';
$error = '';
$success = '';
$masterPositions = master_group($pdo, 'position');
$masterDepartments = master_group($pdo, 'department');

/* ============================================================
   HELPER: FORMAT TANGGAL INDONESIA
   ------------------------------------------------------------
   Fungsi kecil agar tampilan tanggal lebih rapi.
============================================================ */
function employee_date_id($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }

    return date('d M Y', strtotime($date));
}

/* ============================================================
   HELPER: HITUNG USIA
   ------------------------------------------------------------
   Dipakai untuk tanggal lahir karyawan.
============================================================ */
function employee_age($birthDate)
{
    if (empty($birthDate) || $birthDate === '0000-00-00') {
        return '-';
    }

    $birth = new DateTime($birthDate);
    $today = new DateTime('today');

    return $birth->diff($today)->y . ' tahun';
}

function employee_money($value)
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function normalize_money_input($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    return (float) $value;
}

function employee_salary_type_label($type)
{
    switch ($type) {
        case 'daily':
            return 'Harian';
        case 'per_attendance':
            return 'Per Kehadiran';
        case 'monthly':
        default:
            return 'Bulanan';
    }
}

/* ============================================================
   PROSES TAMBAH KARYAWAN
   ------------------------------------------------------------
   Data karyawan disimpan ke tabel employees.
   Field birth_date dipakai untuk notifikasi ulang tahun.
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $employee_code = clean_input($_POST['employee_code'] ?? '');
    $fingerprint_id = clean_input($_POST['fingerprint_id'] ?? '');
    $full_name = clean_input($_POST['full_name'] ?? '');
    $gender = clean_input($_POST['gender'] ?? '');
    $birth_date = $_POST['birth_date'] ?: null;
    $position = clean_input($_POST['position'] ?? '');
    $department = clean_input($_POST['department'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $address = clean_input($_POST['address'] ?? '');
    $join_date = $_POST['join_date'] ?: null;
    $employment_status = clean_input($_POST['employment_status'] ?? 'active');
    $salary_type = clean_input($_POST['salary_type'] ?? 'monthly');
    $salary_amount = normalize_money_input($_POST['salary_amount'] ?? 0);
    $notes = clean_input($_POST['notes'] ?? '');

    if ($full_name === '') {
        $error = 'Nama karyawan wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare(" 
                INSERT INTO employees 
                (employee_code, fingerprint_id, full_name, gender, birth_date, position, department, phone, address, join_date, employment_status, salary_type, salary_amount, notes)
                VALUES 
                (:employee_code, :fingerprint_id, :full_name, :gender, :birth_date, :position, :department, :phone, :address, :join_date, :employment_status, :salary_type, :salary_amount, :notes)
            ");

            $stmt->execute([
                ':employee_code' => $employee_code ?: null,
                ':fingerprint_id' => $fingerprint_id ?: null,
                ':full_name' => $full_name,
                ':gender' => $gender ?: null,
                ':birth_date' => $birth_date,
                ':position' => $position ?: null,
                ':department' => $department ?: null,
                ':phone' => $phone ?: null,
                ':address' => $address ?: null,
                ':join_date' => $join_date,
                ':employment_status' => $employment_status,
                ':salary_type' => in_array($salary_type, ['monthly', 'daily', 'per_attendance']) ? $salary_type : 'monthly',
                ':salary_amount' => $salary_amount,
                ':notes' => $notes ?: null
            ]);

            redirect('pages/employees/index.php?success=add');
        } catch (Exception $e) {
            $error = 'Gagal menambah karyawan: ' . $e->getMessage();
        }
    }
}

/* ============================================================
   PROSES UPDATE KARYAWAN
   ------------------------------------------------------------
   Data yang diedit dari modal akan memperbarui tabel employees.
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id = $_POST['id'] ?? null;

    $employee_code = clean_input($_POST['employee_code'] ?? '');
    $fingerprint_id = clean_input($_POST['fingerprint_id'] ?? '');
    $full_name = clean_input($_POST['full_name'] ?? '');
    $gender = clean_input($_POST['gender'] ?? '');
    $birth_date = $_POST['birth_date'] ?: null;
    $position = clean_input($_POST['position'] ?? '');
    $department = clean_input($_POST['department'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $address = clean_input($_POST['address'] ?? '');
    $join_date = $_POST['join_date'] ?: null;
    $employment_status = clean_input($_POST['employment_status'] ?? 'active');
    $salary_type = clean_input($_POST['salary_type'] ?? 'monthly');
    $salary_amount = normalize_money_input($_POST['salary_amount'] ?? 0);
    $notes = clean_input($_POST['notes'] ?? '');

    if (!$id) {
        redirect('pages/employees/index.php');
    }

    if ($full_name === '') {
        $error = 'Nama karyawan wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare(" 
                UPDATE employees SET
                    employee_code = :employee_code,
                    fingerprint_id = :fingerprint_id,
                    full_name = :full_name,
                    gender = :gender,
                    birth_date = :birth_date,
                    position = :position,
                    department = :department,
                    phone = :phone,
                    address = :address,
                    join_date = :join_date,
                    employment_status = :employment_status,
                    salary_type = :salary_type,
                    salary_amount = :salary_amount,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");

            $stmt->execute([
                ':employee_code' => $employee_code ?: null,
                ':fingerprint_id' => $fingerprint_id ?: null,
                ':full_name' => $full_name,
                ':gender' => $gender ?: null,
                ':birth_date' => $birth_date,
                ':position' => $position ?: null,
                ':department' => $department ?: null,
                ':phone' => $phone ?: null,
                ':address' => $address ?: null,
                ':join_date' => $join_date,
                ':employment_status' => $employment_status,
                ':salary_type' => in_array($salary_type, ['monthly', 'daily', 'per_attendance']) ? $salary_type : 'monthly',
                ':salary_amount' => $salary_amount,
                ':notes' => $notes ?: null,
                ':id' => $id
            ]);

            redirect('pages/employees/index.php?success=edit');
        } catch (Exception $e) {
            $error = 'Gagal memperbarui karyawan: ' . $e->getMessage();
        }
    }
}

/* ============================================================
   FLASH MESSAGE SEDERHANA
============================================================ */
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'add') {
        $success = 'Data karyawan berhasil ditambahkan.';
    } elseif ($_GET['success'] === 'edit') {
        $success = 'Data karyawan berhasil diperbarui.';
    } elseif ($_GET['success'] === 'inactive') {
        $success = 'Karyawan berhasil dinonaktifkan.';
    } elseif ($_GET['success'] === 'delete') {
        $success = 'Data karyawan berhasil dihapus permanen.';
    }
}

/* ============================================================
   FILTER DATA KARYAWAN
   ------------------------------------------------------------
   Admin bisa mencari berdasarkan nama, kode, fingerprint, posisi,
   departemen, dan telepon.
============================================================ */
$search = clean_input($_GET['search'] ?? '');
$statusFilter = clean_input($_GET['status'] ?? 'all');
$departmentFilter = clean_input($_GET['department'] ?? 'all');
$positionFilter = clean_input($_GET['position'] ?? 'all');

if (!in_array($positionFilter, ['all', 'nukari', 'internship'], true)) {
    $positionFilter = 'all';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        employee_code LIKE :search_code OR
        fingerprint_id LIKE :search_fingerprint OR
        full_name LIKE :search_name OR
        position LIKE :search_position OR
        department LIKE :search_department OR
        phone LIKE :search_phone
    )";
    $searchValue = '%' . $search . '%';
    $params[':search_code'] = $searchValue;
    $params[':search_fingerprint'] = $searchValue;
    $params[':search_name'] = $searchValue;
    $params[':search_position'] = $searchValue;
    $params[':search_department'] = $searchValue;
    $params[':search_phone'] = $searchValue;
}

if ($statusFilter !== 'all') {
    $where[] = "employment_status = :status";
    $params[':status'] = $statusFilter;
}

if ($departmentFilter !== 'all') {
    $where[] = "department = :department";
    $params[':department'] = $departmentFilter;
}

if ($positionFilter === 'internship') {
    $where[] = "LOWER(TRIM(COALESCE(position, ''))) IN ('internship', 'magang')";
} elseif ($positionFilter === 'nukari') {
    $where[] = "LOWER(TRIM(COALESCE(position, ''))) NOT IN ('internship', 'magang')";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ============================================================
   DATA DEPARTEMEN UNTUK FILTER
============================================================ */
$departmentStmt = $pdo->query(" 
    SELECT DISTINCT department
    FROM employees
    WHERE department IS NOT NULL AND department != ''
    ORDER BY department ASC
");
$departments = $departmentStmt->fetchAll(PDO::FETCH_COLUMN);

/* ============================================================
   DATA KARYAWAN
============================================================ */
$stmt = $pdo->prepare(" 
    SELECT *
    FROM employees
    $whereSql
    ORDER BY 
        CASE employment_status
            WHEN 'active' THEN 1
            WHEN 'inactive' THEN 2
            WHEN 'resigned' THEN 3
            ELSE 4
        END,
        full_name ASC
");
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   RINGKASAN KARYAWAN
============================================================ */
$summaryStmt = $pdo->query(" 
    SELECT
        COUNT(*) AS total_employee,
        SUM(CASE WHEN employment_status = 'active' THEN 1 ELSE 0 END) AS total_active,
        SUM(CASE WHEN employment_status = 'inactive' THEN 1 ELSE 0 END) AS total_inactive,
        SUM(CASE WHEN employment_status = 'resigned' THEN 1 ELSE 0 END) AS total_resigned,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(position, ''))) NOT IN ('internship', 'magang') THEN 1 ELSE 0 END) AS total_nukari,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(position, ''))) IN ('internship', 'magang') THEN 1 ELSE 0 END) AS total_internship
    FROM employees
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

/* ============================================================
   ULANG TAHUN TERDEKAT
   ------------------------------------------------------------
   Untuk membantu HR/admin melihat karyawan yang ulang tahun
   dalam 30 hari ke depan.
============================================================ */
$birthdayStmt = $pdo->query(" 
    SELECT *,
        DATEDIFF(
            CASE
                WHEN DATE_FORMAT(birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d')) >= CURDATE()
                THEN DATE_FORMAT(birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d'))
                ELSE DATE_FORMAT(birth_date, CONCAT(YEAR(CURDATE()) + 1, '-%m-%d'))
            END,
            CURDATE()
        ) AS days_left
    FROM employees
    WHERE birth_date IS NOT NULL
    AND employment_status = 'active'
    HAVING days_left BETWEEN 0 AND 30
    ORDER BY days_left ASC, full_name ASC
    LIMIT 8
");
$birthdays = $birthdayStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<style>
    /* ============================================================
   FIX MODAL SCROLL - EMPLOYEES PAGE
   ------------------------------------------------------------
   Agar modal tambah/edit karyawan bisa discroll saat isi form panjang.
============================================================ */

    #addEmployeeModal .modal-dialog,
    #editEmployeeModal .modal-dialog {
        max-height: calc(100vh - 24px);
        margin-top: 12px;
        margin-bottom: 12px;
    }

    #addEmployeeModal .modal-content,
    #editEmployeeModal .modal-content {
        max-height: calc(100vh - 24px);
        display: flex;
        flex-direction: column;
    }

    #addEmployeeModal .modal-body,
    #editEmployeeModal .modal-body {
        overflow-y: auto;
        overflow-x: hidden;
        max-height: calc(100vh - 170px);
        padding-right: 6px;
    }

    #addEmployeeModal .modal-content,
    #editEmployeeModal .modal-content {
        overflow: hidden;
    }

    body.modal-open {
        overflow-y: auto !important;
        padding-right: 0 !important;
    }

    .employee-summary-link {
        color: inherit;
        display: block;
        height: 100%;
        text-decoration: none;
    }

    .employee-summary-link .panel {
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .employee-summary-link:hover .panel,
    .employee-summary-link:focus-visible .panel {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, .10);
    }

    .employee-summary-link.is-active .panel {
        border: 1px solid var(--nukari-green, #198754);
    }

    @media (max-width: 576px) {

        #addEmployeeModal .modal-dialog,
        #editEmployeeModal .modal-dialog {
            max-height: calc(100vh - 10px);
            margin: 5px;
        }

        #addEmployeeModal .modal-content,
        #editEmployeeModal .modal-content {
            max-height: calc(100vh - 10px);
        }

        #addEmployeeModal .modal-body,
        #editEmployeeModal .modal-body {
            max-height: calc(100vh - 150px);
        }
    }
</style>
<main class="main-content">

    <!-- ======================================================
         PAGE HEADER
    ======================================================= -->
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Karyawan &amp; Internship</h1>
            <p class="text-muted mb-0">Kelola data karyawan Nukari dan internship dalam satu halaman.</p>
        </div>

        <button class="btn btn-nukari" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
            <i class="bi bi-plus-circle me-1"></i> Tambah Personel
        </button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success rounded-4">
            <?= htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- ======================================================
         SUMMARY CARDS
    ======================================================= -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6">
            <a class="employee-summary-link <?= $statusFilter === 'all' && $positionFilter === 'all' ? 'is-active' : ''; ?>" href="<?= base_url('pages/employees/index.php'); ?>">
                <div class="panel h-100">
                    <div class="text-muted small">Total Personel</div>
                    <div class="fs-3 fw-bold"><?= (int) ($summary['total_employee'] ?? 0); ?></div>
                </div>
            </a>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6">
            <a class="employee-summary-link <?= $statusFilter === 'active' ? 'is-active' : ''; ?>" href="<?= base_url('pages/employees/index.php?status=active'); ?>">
                <div class="panel h-100">
                    <div class="text-muted small">Aktif</div>
                    <div class="fs-3 fw-bold text-success"><?= (int) ($summary['total_active'] ?? 0); ?></div>
                </div>
            </a>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6">
            <a class="employee-summary-link <?= $statusFilter === 'inactive' ? 'is-active' : ''; ?>" href="<?= base_url('pages/employees/index.php?status=inactive'); ?>">
                <div class="panel h-100">
                    <div class="text-muted small">Nonaktif</div>
                    <div class="fs-3 fw-bold text-warning"><?= (int) ($summary['total_inactive'] ?? 0); ?></div>
                </div>
            </a>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6">
            <a class="employee-summary-link <?= $statusFilter === 'resigned' ? 'is-active' : ''; ?>" href="<?= base_url('pages/employees/index.php?status=resigned'); ?>">
                <div class="panel h-100">
                    <div class="text-muted small">Resigned</div>
                    <div class="fs-3 fw-bold text-danger"><?= (int) ($summary['total_resigned'] ?? 0); ?></div>
                </div>
            </a>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6">
            <a class="employee-summary-link <?= $positionFilter === 'nukari' ? 'is-active' : ''; ?>" href="<?= base_url('pages/employees/index.php?position=nukari'); ?>">
                <div class="panel h-100">
                    <div class="text-muted small">Total Karyawan Nukari</div>
                    <div class="fs-3 fw-bold text-primary"><?= (int) ($summary['total_nukari'] ?? 0); ?></div>
                </div>
            </a>
        </div>

        <div class="col-xl-2 col-md-4 col-sm-6">
            <a class="employee-summary-link <?= $positionFilter === 'internship' ? 'is-active' : ''; ?>" href="<?= base_url('pages/employees/index.php?position=internship'); ?>">
                <div class="panel h-100">
                    <div class="text-muted small">Total Internship</div>
                    <div class="fs-3 fw-bold text-info"><?= (int) ($summary['total_internship'] ?? 0); ?></div>
                </div>
            </a>
        </div>
    </div>

    <!-- ======================================================
         BIRTHDAY REMINDER PANEL
    ======================================================= -->
    <?php if (!empty($birthdays)): ?>
        <div class="panel mb-4">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-1"><i class="bi bi-cake2 me-1"></i> Ulang Tahun Terdekat</h5>
                    <p class="text-muted small mb-0">Personel aktif yang ulang tahun dalam 30 hari ke depan.</p>
                </div>
            </div>

            <div class="row g-3">
                <?php foreach ($birthdays as $bday): ?>
                    <div class="col-lg-3 col-md-6">
                        <div class="border rounded-4 p-3 h-100">
                            <div class="fw-bold"><?= htmlspecialchars($bday['full_name']); ?></div>
                            <div class="text-muted small"><?= employee_date_id($bday['birth_date']); ?></div>
                            <div class="mt-2">
                                <?php if ((int) $bday['days_left'] === 0): ?>
                                    <span class="badge bg-danger rounded-pill">Hari ini</span>
                                <?php elseif ((int) $bday['days_left'] === 1): ?>
                                    <span class="badge bg-warning text-dark rounded-pill">Besok</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark rounded-pill"><?= (int) $bday['days_left']; ?> hari lagi</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ======================================================
         FILTER PANEL
    ======================================================= -->
    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-xl-4 col-md-12">
                <label class="form-label fw-semibold">Cari Personel</label>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    value="<?= htmlspecialchars($search); ?>"
                    placeholder="Cari nama, kode, fingerprint, posisi, departemen, telepon">
            </div>

            <div class="col-xl-2 col-md-4">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : ''; ?>>Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="resigned" <?= $statusFilter === 'resigned' ? 'selected' : ''; ?>>Resigned</option>
                </select>
            </div>

            <div class="col-xl-2 col-md-4">
                <label class="form-label fw-semibold">Departemen</label>
                <select name="department" class="form-select">
                    <option value="all" <?= $departmentFilter === 'all' ? 'selected' : ''; ?>>Semua Departemen</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept); ?>" <?= $departmentFilter === $dept ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-xl-3 col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <select name="position" class="form-select">
                    <option value="all" <?= $positionFilter === 'all' ? 'selected' : ''; ?>>Semua Karyawan &amp; Internship</option>
                    <option value="nukari" <?= $positionFilter === 'nukari' ? 'selected' : ''; ?>>Karyawan Nukari</option>
                    <option value="internship" <?= $positionFilter === 'internship' ? 'selected' : ''; ?>>Internship</option>
                </select>
            </div>

            <div class="col-xl-1 col-md-12 d-grid">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-search"></i>
                </button>
            </div>

            <?php if ($search !== '' || $statusFilter !== 'all' || $departmentFilter !== 'all' || $positionFilter !== 'all'): ?>
                <div class="col-12">
                    <a href="<?= base_url('pages/employees/index.php'); ?>" class="btn btn-sm btn-light">
                        <i class="bi bi-x-circle me-1"></i> Reset Filter
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ======================================================
         TABLE DATA KARYAWAN
    ======================================================= -->
    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Daftar Karyawan &amp; Internship</h5>
                <p class="text-muted small mb-0">Menampilkan <?= count($employees); ?> data.</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode</th>
                        <th>Fingerprint</th>
                        <th>Nama</th>
                        <th>Jabatan</th>
                        <th>Departemen</th>
                        <th>Telepon</th>
                        <th>Tgl Lahir</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($employees): ?>
                        <?php $no = 1;
                        foreach ($employees as $emp): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td><?= htmlspecialchars($emp['employee_code'] ?? '-'); ?></td>

                                <td>
                                    <span class="badge rounded-pill badge-soft-blue">
                                        <?= htmlspecialchars($emp['fingerprint_id'] ?? '-'); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($emp['full_name']); ?></div>
                                    <div class="text-muted small">
                                        <?php
                                        if (($emp['gender'] ?? '') === 'male') {
                                            echo 'Laki-laki';
                                        } elseif (($emp['gender'] ?? '') === 'female') {
                                            echo 'Perempuan';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </div>
                                </td>

                                <td>
                                    <div><?= htmlspecialchars($emp['position'] ?? '-'); ?></div>

                                    <?php if (!empty($emp['join_date'])): ?>
                                        <?php
                                        $joinDate = new DateTime($emp['join_date']);
                                        $today = new DateTime();
                                        $diff = $joinDate->diff($today);

                                        if ($diff->y > 0) {
                                            $masaKerja = $diff->y . ' tahun';
                                            if ($diff->m > 0) {
                                                $masaKerja .= ' ' . $diff->m . ' bulan';
                                            }
                                        } elseif ($diff->m > 0) {
                                            $masaKerja = $diff->m . ' bulan';
                                            if ($diff->d > 0) {
                                                $masaKerja .= ' ' . $diff->d . ' hari';
                                            }
                                        } else {
                                            $masaKerja = $diff->d . ' hari';
                                        }
                                        ?>

                                        <div class="text-muted small">
                                            Bergabung: <?= date('d M Y', strtotime($emp['join_date'])); ?>
                                            (<?= $masaKerja; ?>)
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-muted small">
                                        Gaji:
                                        <?= employee_money($emp['salary_amount'] ?? 0); ?>
                                        /
                                        <?=
                                        ($emp['salary_type'] ?? 'monthly') === 'monthly'
                                            ? 'bulan'
                                            : (
                                                ($emp['salary_type'] ?? 'daily')
                                                === 'daily'
                                                ? 'hari'
                                                : 'kehadiran'
                                            );
                                        ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($emp['department'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($emp['phone'] ?? '-'); ?></td>

                                <td>
                                    <div><?= employee_date_id($emp['birth_date'] ?? null); ?></div>
                                    <div class="text-muted small"><?= employee_age($emp['birth_date'] ?? null); ?></div>
                                </td>

                                <td>
                                    <?php if (($emp['employment_status'] ?? '') === 'active'): ?>
                                        <span class="badge rounded-pill badge-soft-green">Active</span>
                                    <?php elseif (($emp['employment_status'] ?? '') === 'inactive'): ?>
                                        <span class="badge rounded-pill badge-soft-orange">Inactive</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-red">Resigned</span>
                                    <?php endif; ?>
                                </td>
                                <!-- Aksi -->
                                <td class="text-end">
                                    <button
                                        class="btn btn-sm btn-outline-primary editBtn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editEmployeeModal"
                                        data-id="<?= $emp['id']; ?>"
                                        data-employee_code="<?= htmlspecialchars($emp['employee_code'] ?? '', ENT_QUOTES); ?>"
                                        data-fingerprint_id="<?= htmlspecialchars($emp['fingerprint_id'] ?? '', ENT_QUOTES); ?>"
                                        data-full_name="<?= htmlspecialchars($emp['full_name'] ?? '', ENT_QUOTES); ?>"
                                        data-gender="<?= htmlspecialchars($emp['gender'] ?? '', ENT_QUOTES); ?>"
                                        data-birth_date="<?= htmlspecialchars($emp['birth_date'] ?? '', ENT_QUOTES); ?>"
                                        data-position="<?= htmlspecialchars($emp['position'] ?? '', ENT_QUOTES); ?>"
                                        data-department="<?= htmlspecialchars($emp['department'] ?? '', ENT_QUOTES); ?>"
                                        data-phone="<?= htmlspecialchars($emp['phone'] ?? '', ENT_QUOTES); ?>"
                                        data-address="<?= htmlspecialchars($emp['address'] ?? '', ENT_QUOTES); ?>"
                                        data-join_date="<?= htmlspecialchars($emp['join_date'] ?? '', ENT_QUOTES); ?>"
                                        data-employment_status="<?= htmlspecialchars($emp['employment_status'] ?? 'active', ENT_QUOTES); ?>"
                                        data-salary_type="<?= htmlspecialchars($emp['salary_type'] ?? 'monthly', ENT_QUOTES); ?>"
                                        data-salary_amount="<?= htmlspecialchars($emp['salary_amount'] ?? '0', ENT_QUOTES); ?>"
                                        data-notes="<?= htmlspecialchars($emp['notes'] ?? '', ENT_QUOTES); ?>">
                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                    </button>

                                    <?php if (($emp['employment_status'] ?? '') === 'active'): ?>
                                        <a
                                            href="<?= base_url('pages/employees/delete.php?id=' . $emp['id']); ?>"
                                            onclick="return confirm('Yakin ingin menonaktifkan karyawan ini?')"
                                            class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-person-x me-1"></i> Nonaktifkan
                                        </a>
                                    <?php endif; ?>

                                    <a
                                        href="<?= base_url('pages/employees/delete.php?id=' . $emp['id'] . '&mode=delete'); ?>"
                                        onclick="return confirm('Yakin ingin menghapus permanen? Data tidak bisa dikembalikan!')"
                                        class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i> Hapus
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                Belum ada data karyawan atau internship.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ==========================================================
     MODAL TAMBAH KARYAWAN
========================================================== -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-4">
            <form method="POST">
                <input type="hidden" name="action" value="add">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Tambah Karyawan / Internship</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Kode Karyawan</label>
                            <input type="text" name="employee_code" class="form-control" placeholder="Contoh: NKR-001">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Fingerprint ID</label>
                            <input type="text" name="fingerprint_id" class="form-control" placeholder="Contoh: 001">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Nama Lengkap</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Jenis Kelamin</label>
                            <select name="gender" class="form-select">
                                <option value="">- Pilih -</option>
                                <option value="male">Laki-laki</option>
                                <option value="female">Perempuan</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tanggal Lahir</label>
                            <input type="date" name="birth_date" class="form-control">
                            <small class="text-muted">Dipakai untuk notifikasi ulang tahun.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Posisi / Jabatan</label>
                            <input type="text" name="position" class="form-control" list="masterPositionList" placeholder="Pilih atau isi jabatan">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Departemen</label>
                            <input type="text" name="department" class="form-control" list="masterDepartmentList" placeholder="Pilih atau isi departemen">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">No. Telepon</label>
                            <input type="text" name="phone" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tanggal Bergabung</label>
                            <input type="date" name="join_date" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tipe Gaji</label>
                            <select name="salary_type" class="form-select">
                                <option value="monthly">Bulanan</option>
                                <option value="daily">Harian</option>
                                <option value="per_attendance">Per Kehadiran</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Gaji Pokok</label>
                            <input type="text" name="salary_amount" class="form-control moneyInput" placeholder="Contoh: 3.000.000">
                            <small class="text-muted">Isi tanpa Rp. Contoh: 3000000 atau 150000.</small>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Alamat</label>
                            <textarea name="address" rows="3" class="form-control"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="employment_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="resigned">Resigned</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Catatan</label>
                            <textarea name="notes" rows="3" class="form-control" placeholder="Catatan internal terkait karyawan."></textarea>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-nukari">
                        <i class="bi bi-save me-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================================
     MODAL EDIT KARYAWAN
========================================================== -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-4">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Karyawan / Internship</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Kode Karyawan</label>
                            <input type="text" name="employee_code" id="edit_employee_code" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Fingerprint ID</label>
                            <input type="text" name="fingerprint_id" id="edit_fingerprint_id" class="form-control">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Nama Lengkap</label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Jenis Kelamin</label>
                            <select name="gender" id="edit_gender" class="form-select">
                                <option value="">- Pilih -</option>
                                <option value="male">Laki-laki</option>
                                <option value="female">Perempuan</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tanggal Lahir</label>
                            <input type="date" name="birth_date" id="edit_birth_date" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Posisi / Jabatan</label>
                            <input type="text" name="position" id="edit_position" class="form-control" list="masterPositionList">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Departemen</label>
                            <input type="text" name="department" id="edit_department" class="form-control" list="masterDepartmentList">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">No. Telepon</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tanggal Bergabung</label>
                            <input type="date" name="join_date" id="edit_join_date" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tipe Gaji</label>
                            <select name="salary_type" id="edit_salary_type" class="form-select">
                                <option value="monthly">Bulanan</option>
                                <option value="daily">Harian</option>
                                <option value="per_attendance">Per Kehadiran</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Gaji Pokok</label>
                            <input type="text" name="salary_amount" id="edit_salary_amount" class="form-control moneyInput" placeholder="Contoh: 3.000.000">
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Alamat</label>
                            <textarea name="address" id="edit_address" rows="3" class="form-control"></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="employment_status" id="edit_employment_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="resigned">Resigned</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Catatan</label>
                            <textarea name="notes" id="edit_notes" rows="3" class="form-control"></textarea>
                        </div>

                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-nukari">
                        <i class="bi bi-save me-1"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================================
     SCRIPT MODAL EDIT
========================================================== -->
<script>
    document.querySelectorAll('.editBtn').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('edit_id').value = this.dataset.id || '';
            document.getElementById('edit_employee_code').value = this.dataset.employee_code || '';
            document.getElementById('edit_fingerprint_id').value = this.dataset.fingerprint_id || '';
            document.getElementById('edit_full_name').value = this.dataset.full_name || '';
            document.getElementById('edit_gender').value = this.dataset.gender || '';
            document.getElementById('edit_birth_date').value = this.dataset.birth_date || '';
            document.getElementById('edit_position').value = this.dataset.position || '';
            document.getElementById('edit_department').value = this.dataset.department || '';
            document.getElementById('edit_phone').value = this.dataset.phone || '';
            document.getElementById('edit_address').value = this.dataset.address || '';
            document.getElementById('edit_join_date').value = this.dataset.join_date || '';
            document.getElementById('edit_employment_status').value = this.dataset.employment_status || 'active';
            document.getElementById('edit_salary_type').value = this.dataset.salary_type || 'monthly';

            let salaryAmount = this.dataset.salary_amount || '0';
            salaryAmount = salaryAmount.split('.')[0];
            salaryAmount = salaryAmount.replace(/[^0-9]/g, '');
            document.getElementById('edit_salary_amount').value = salaryAmount ? new Intl.NumberFormat('id-ID').format(salaryAmount) : '';

            document.getElementById('edit_notes').value = this.dataset.notes || '';
        });
    });

    document.querySelectorAll('.moneyInput').forEach(input => {
        input.addEventListener('input', function() {
            let value = this.value.replace(/[^0-9]/g, '');

            if (value === '') {
                this.value = '';
                return;
            }

            this.value = new Intl.NumberFormat('id-ID').format(value);
        });
    });
</script>

<datalist id="masterPositionList">
    <option value="Internship">
    <?php foreach ($masterPositions as $item): ?>
        <?php if (strcasecmp(trim((string) $item['item_name']), 'Internship') !== 0): ?>
            <option value="<?= htmlspecialchars($item['item_name']); ?>">
        <?php endif; ?>
    <?php endforeach; ?>
</datalist>
<datalist id="masterDepartmentList"><?php foreach($masterDepartments as $item): ?><option value="<?=htmlspecialchars($item['item_name']);?>"><?php endforeach; ?></datalist>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
