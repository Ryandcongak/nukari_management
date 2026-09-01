<?php
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Cuti, Izin & Sakit - Nukari';

$error = '';
$success = '';

if (isset($_GET['success']) && $_GET['success'] !== '') {
    $success = trim($_GET['success']);
}

function redirect_leaves_after_action($month, $message)
{
    $month = preg_match('/^\d{4}-\d{2}$/', (string)$month) ? $month : date('Y-m');
    $url = 'leaves.php?month=' . urlencode($month) . '&success=' . urlencode($message);
    header('Location: ' . $url);
    exit;
}

$month = $_GET['month'] ?? $_POST['month'] ?? date('Y-m');
$keyword = trim($_GET['keyword'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';

function leave_type_label($type)
{
    switch ($type) {
        case 'annual_leave':
            return 'Cuti Tahunan';
        case 'sick':
            return 'Sakit';
        case 'permission':
            return 'Izin';
        case 'unpaid_leave':
            return 'Izin Tidak Dibayar';
        case 'official_duty':
            return 'Dinas Luar';
        default:
            return ucfirst($type);
    }
}

function leave_status_badge($status)
{
    switch ($status) {
        case 'approved':
            return '<span class="badge rounded-pill badge-soft-green">Approved</span>';
        case 'rejected':
            return '<span class="badge rounded-pill badge-soft-red">Rejected</span>';
        case 'cancelled':
            return '<span class="badge rounded-pill badge-soft-red">Cancelled</span>';
        default:
            return '<span class="badge rounded-pill badge-soft-orange">Pending</span>';
    }
}

function calculate_leave_days($startDate, $endDate)
{
    $start = strtotime($startDate);
    $end = strtotime($endDate);

    if (!$start || !$end || $end < $start) {
        return 1;
    }

    $days = 0;
    while ($start <= $end) {
        $day = (int)date('N', $start);

        // Sabtu & Minggu tidak dihitung
        if (!in_array($day, [6, 7])) {
            $days++;
        }

        $start = strtotime('+1 day', $start);
    }

    return max(1, $days);
}

$employeeStmt = $pdo->query("
    SELECT id, full_name, employee_code, position, department
    FROM employees
    WHERE employment_status = 'active'
    ORDER BY full_name ASC
");
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_leave') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $leaveType = $_POST['leave_type'] ?? 'permission';
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $isPaid = $_POST['is_paid'] ?? 'yes';
        $reason = trim($_POST['reason'] ?? '');
        $proofPath = null;

        $allowedTypes = ['annual_leave', 'sick', 'permission', 'unpaid_leave', 'official_duty'];

        if ($employeeId <= 0) {
            $error = 'Karyawan wajib dipilih.';
        } elseif (!in_array($leaveType, $allowedTypes)) {
            $error = 'Jenis pengajuan tidak valid.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $error = 'Tanggal mulai tidak valid.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $error = 'Tanggal selesai tidak valid.';
        } elseif (strtotime($endDate) < strtotime($startDate)) {
            $error = 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.';
        } else {
            try {
                if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                    $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
                    $fileName = $_FILES['proof_file']['name'];
                    $fileTmp = $_FILES['proof_file']['tmp_name'];
                    $fileSize = $_FILES['proof_file']['size'];
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowedExt)) {
                        throw new Exception('Format bukti harus JPG, JPEG, PNG, atau PDF.');
                    }

                    if ($fileSize > 5 * 1024 * 1024) {
                        throw new Exception('Ukuran file maksimal 5MB.');
                    }

                    $uploadDir = __DIR__ . '/../../uploads/leaves/';

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    $newFileName = 'leave_' . date('Ymd_His') . '_' . rand(1000, 9999) . '.' . $ext;
                    $targetPath = $uploadDir . $newFileName;

                    if (!move_uploaded_file($fileTmp, $targetPath)) {
                        throw new Exception('Gagal upload bukti.');
                    }

                    $proofPath = 'uploads/leaves/' . $newFileName;
                }

                if ($leaveType === 'unpaid_leave') {
                    $isPaid = 'no';
                }

                $totalDays = calculate_leave_days($startDate, $endDate);

                $stmt = $pdo->prepare("
                    INSERT INTO employee_leaves (
                        employee_id,
                        leave_type,
                        start_date,
                        end_date,
                        total_days,
                        is_paid,
                        status,
                        proof_file,
                        reason
                    ) VALUES (
                        :employee_id,
                        :leave_type,
                        :start_date,
                        :end_date,
                        :total_days,
                        :is_paid,
                        'pending',
                        :proof_file,
                        :reason
                    )
                ");

                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':leave_type' => $leaveType,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                    ':total_days' => $totalDays,
                    ':is_paid' => $isPaid,
                    ':proof_file' => $proofPath,
                    ':reason' => $reason !== '' ? $reason : null
                ]);

                $month = date('Y-m', strtotime($startDate));
                redirect_leaves_after_action($month, 'Pengajuan berhasil ditambahkan.');
            } catch (Exception $e) {
                $error = 'Gagal menambahkan pengajuan: ' . $e->getMessage();
            }
        }
    }

    if (in_array($action, ['approve', 'reject', 'cancel', 'delete'])) {
        $leaveId = (int)($_POST['leave_id'] ?? 0);
        $approvalNotes = trim($_POST['approval_notes'] ?? '');

        if ($leaveId <= 0) {
            $error = 'Data pengajuan tidak valid.';
        } else {
            try {
                if ($action === 'approve') {
                    $stmt = $pdo->prepare("
                        UPDATE employee_leaves
                        SET status = 'approved',
                            approval_notes = :approval_notes,
                            approved_at = NOW(),
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':approval_notes' => $approvalNotes !== '' ? $approvalNotes : null,
                        ':id' => $leaveId
                    ]);

                    redirect_leaves_after_action($month, 'Pengajuan berhasil disetujui.');
                }

                if ($action === 'reject') {
                    $stmt = $pdo->prepare("
                        UPDATE employee_leaves
                        SET status = 'rejected',
                            approval_notes = :approval_notes,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':approval_notes' => $approvalNotes !== '' ? $approvalNotes : null,
                        ':id' => $leaveId
                    ]);

                    redirect_leaves_after_action($month, 'Pengajuan berhasil ditolak.');
                }

                if ($action === 'cancel') {
                    $stmt = $pdo->prepare("
                        UPDATE employee_leaves
                        SET status = 'cancelled',
                            approval_notes = :approval_notes,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':approval_notes' => $approvalNotes !== '' ? $approvalNotes : null,
                        ':id' => $leaveId
                    ]);

                    redirect_leaves_after_action($month, 'Pengajuan berhasil dibatalkan.');
                }

                if ($action === 'delete') {
                    $fileStmt = $pdo->prepare("
                        SELECT proof_file
                        FROM employee_leaves
                        WHERE id = :id
                        LIMIT 1
                    ");
                    $fileStmt->execute([':id' => $leaveId]);
                    $leave = $fileStmt->fetch(PDO::FETCH_ASSOC);

                    if ($leave && !empty($leave['proof_file'])) {
                        $filePath = __DIR__ . '/../../' . $leave['proof_file'];

                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }

                    $deleteStmt = $pdo->prepare("
                        DELETE FROM employee_leaves
                        WHERE id = :id
                    ");
                    $deleteStmt->execute([':id' => $leaveId]);

                    redirect_leaves_after_action($month, 'Data pengajuan berhasil dihapus permanen.');
                }
            } catch (Exception $e) {
                $error = 'Gagal memproses pengajuan: ' . $e->getMessage();
            }
        }
    }
}

$where = [];
$params = [];

$periodStart = $month . '-01';
$periodEnd = date('Y-m-t', strtotime($periodStart));

$where[] = "el.start_date <= :period_end AND el.end_date >= :period_start";
$params[':period_start'] = $periodStart;
$params[':period_end'] = $periodEnd;

if ($keyword !== '') {
    $where[] = "(
        e.full_name LIKE :keyword
        OR e.employee_code LIKE :keyword
        OR e.position LIKE :keyword
        OR e.department LIKE :keyword
        OR el.reason LIKE :keyword
        OR el.approval_notes LIKE :keyword
    )";
    $params[':keyword'] = '%' . $keyword . '%';
}

if ($statusFilter !== '') {
    $where[] = "el.status = :status";
    $params[':status'] = $statusFilter;
}

if ($typeFilter !== '') {
    $where[] = "el.leave_type = :type";
    $params[':type'] = $typeFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        el.*,
        e.full_name,
        e.employee_code,
        e.position,
        e.department
    FROM employee_leaves el
    JOIN employees e ON el.employee_id = e.id
    WHERE {$whereSql}
    ORDER BY el.start_date DESC, e.full_name ASC
");

$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'count' => count($leaves),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'cancelled' => 0,
    'paid_days' => 0,
    'unpaid_days' => 0
];

foreach ($leaves as $leave) {
    if (isset($summary[$leave['status']])) {
        $summary[$leave['status']]++;
    }

    if ($leave['status'] === 'approved') {
        if ($leave['is_paid'] === 'yes') {
            $summary['paid_days'] += (int)$leave['total_days'];
        } else {
            $summary['unpaid_days'] += (int)$leave['total_days'];
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Cuti, Izin & Sakit</h1>
            <p class="text-muted mb-0">
                Kelola pengajuan cuti, sakit, izin, dinas luar, dan izin tidak dibayar.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/payroll/index.php?month=' . urlencode($month)); ?>" class="btn btn-outline-success">
                <i class="bi bi-cash-stack me-1"></i> Payroll
            </a>

            <a href="<?= base_url('pages/attendances/index.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-calendar-check me-1"></i> Absensi
            </a>
        </div>
    </div>

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

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-blue">
                <div class="stat-title">Total Pengajuan</div>
                <div class="stat-number"><?= (int)$summary['count']; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Pending</div>
                <div class="stat-number"><?= (int)$summary['pending']; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Approved</div>
                <div class="stat-number"><?= (int)$summary['approved']; ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Unpaid Leave</div>
                <div class="stat-number"><?= (int)$summary['unpaid_days']; ?> hari</div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">Tambah Pengajuan</h5>

        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_leave">

            <div class="col-md-3">
                <label class="form-label fw-semibold">Karyawan</label>
                <select name="employee_id" class="form-select" required>
                    <option value="">Pilih Karyawan</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int)$employee['id']; ?>">
                            <?= htmlspecialchars($employee['full_name']); ?>
                            <?= !empty($employee['employee_code']) ? ' - ' . htmlspecialchars($employee['employee_code']) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="leave_type" id="leaveType" class="form-select" required>
                    <option value="annual_leave">Cuti Tahunan</option>
                    <option value="sick">Sakit</option>
                    <option value="permission">Izin</option>
                    <option value="unpaid_leave">Izin Tidak Dibayar</option>
                    <option value="official_duty">Dinas Luar</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Dibayar?</label>
                <select name="is_paid" id="isPaid" class="form-select">
                    <option value="yes">Ya</option>
                    <option value="no">Tidak</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Mulai</label>
                <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Selesai</label>
                <input type="date" name="end_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Upload Bukti</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                <small class="text-muted">Surat dokter/dokumen. JPG, PNG, PDF. Maks 5MB.</small>
            </div>

            <div class="col-md-12">
                <label class="form-label fw-semibold">Alasan / Keterangan</label>
                <textarea name="reason" class="form-control" rows="2" placeholder="Contoh: Sakit demam, izin keluarga, dinas luar, dll."></textarea>
            </div>

            <div class="col-md-12">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-save me-1"></i> Simpan Pengajuan
                </button>
            </div>
        </form>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-semibold">Bulan</label>
                <input type="month" name="month" value="<?= htmlspecialchars($month); ?>" class="form-control">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Cari</label>
                <input
                    type="text"
                    name="keyword"
                    value="<?= htmlspecialchars($keyword); ?>"
                    class="form-control"
                    placeholder="Nama, kode, alasan">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="type" class="form-select">
                    <option value="">Semua</option>
                    <option value="annual_leave" <?= $typeFilter === 'annual_leave' ? 'selected' : ''; ?>>Cuti Tahunan</option>
                    <option value="sick" <?= $typeFilter === 'sick' ? 'selected' : ''; ?>>Sakit</option>
                    <option value="permission" <?= $typeFilter === 'permission' ? 'selected' : ''; ?>>Izin</option>
                    <option value="unpaid_leave" <?= $typeFilter === 'unpaid_leave' ? 'selected' : ''; ?>>Izin Tidak Dibayar</option>
                    <option value="official_duty" <?= $typeFilter === 'official_duty' ? 'selected' : ''; ?>>Dinas Luar</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="">Semua</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>

            <div class="col-md-1 d-grid">
                <a href="<?= base_url('pages/hr/leaves.php'); ?>" class="btn btn-outline-secondary">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Daftar Pengajuan</h5>
                <p class="text-muted mb-0">
                    Approved paid leave tidak akan menjadi potongan. Approved unpaid leave akan dipotong pada payroll.
                </p>
            </div>

            <input
                type="text"
                id="tableSearch"
                class="form-control"
                style="max-width: 320px;"
                placeholder="Search cepat di tabel...">
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="leaveTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Jenis</th>
                        <th>Periode</th>
                        <th>Total Hari</th>
                        <th>Dibayar</th>
                        <th>Bukti</th>
                        <th>Status</th>
                        <th>Alasan</th>
                        <th>Catatan Approval</th>
                        <th width="260">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($leaves): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($leave['full_name']); ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($leave['employee_code'] ?? '-'); ?> ·
                                        <?= htmlspecialchars($leave['position'] ?? '-'); ?>
                                        <?= !empty($leave['department']) ? ' · ' . htmlspecialchars($leave['department']) : ''; ?>
                                    </small>
                                </td>

                                <td><?= leave_type_label($leave['leave_type']); ?></td>

                                <td>
                                    <?= date('d M Y', strtotime($leave['start_date'])); ?>
                                    -
                                    <?= date('d M Y', strtotime($leave['end_date'])); ?>
                                </td>

                                <td><?= (int)$leave['total_days']; ?> hari</td>

                                <td>
                                    <?= $leave['is_paid'] === 'yes'
                                        ? '<span class="badge rounded-pill badge-soft-green">Paid Leave</span>'
                                        : '<span class="badge rounded-pill badge-soft-red">Unpaid</span>'; ?>
                                </td>

                                <td>
                                    <?php if (!empty($leave['proof_file'])): ?>
                                        <a href="<?= base_url($leave['proof_file']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-paperclip"></i> Lihat
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= leave_status_badge($leave['status']); ?></td>

                                <td>
                                    <?= !empty($leave['reason']) ? nl2br(htmlspecialchars($leave['reason'])) : '<span class="text-muted">-</span>'; ?>
                                </td>

                                <td>
                                    <?= !empty($leave['approval_notes']) ? nl2br(htmlspecialchars($leave['approval_notes'])) : '<span class="text-muted">-</span>'; ?>
                                </td>

                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php if ($leave['status'] === 'pending'): ?>
                                            <form method="POST" onsubmit="return confirm('Setujui pengajuan ini?');">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>">
                                                <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    Approve
                                                </button>
                                            </form>

                                            <form method="POST" onsubmit="return confirm('Tolak pengajuan ini?');">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>">
                                                <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    Reject
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!in_array($leave['status'], ['cancelled'])): ?>
                                            <form method="POST" onsubmit="return confirm('Batalkan pengajuan ini?');">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>">
                                                <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning">
                                                    Cancel
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" onsubmit="return confirm('Hapus permanen pengajuan ini? File bukti juga akan dihapus.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="leave_id" value="<?= (int)$leave['id']; ?>">
                                            <input type="hidden" name="month" value="<?= htmlspecialchars($month); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">
                                Belum ada data cuti/izin/sakit pada bulan ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($leaves): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="4">TOTAL APPROVED</td>
                            <td colspan="7">
                                Paid Leave: <span class="text-success"><?= (int)$summary['paid_days']; ?> hari</span>
                                · Unpaid Leave: <span class="text-danger"><?= (int)$summary['unpaid_days']; ?> hari</span>
                            </td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const leaveType = document.getElementById('leaveType');
        const isPaid = document.getElementById('isPaid');

        if (leaveType && isPaid) {
            leaveType.addEventListener('change', function() {
                if (this.value === 'unpaid_leave') {
                    isPaid.value = 'no';
                } else {
                    isPaid.value = 'yes';
                }
            });
        }

        const searchInput = document.getElementById('tableSearch');
        const table = document.getElementById('leaveTable');

        if (searchInput && table) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr');

                rows.forEach(function(row) {
                    const text = row.innerText.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>