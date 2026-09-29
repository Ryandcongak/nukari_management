<?php
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Komponen Payroll - Nukari';

$error = '';
$success = '';

$month = $_GET['month'] ?? $_POST['payroll_month'] ?? date('Y-m');
$keyword = trim($_GET['keyword'] ?? '');
$typeFilter = $_GET['type'] ?? '';

function component_money($value)
{
    if (function_exists('rupiah')) {
        return rupiah($value);
    }

    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function component_number($value)
{
    return number_format((float)$value, 0, ',', '.');
}

function component_type_label($type)
{
    switch ($type) {
        case 'allowance':
            return 'Tunjangan';
        case 'bonus':
            return 'Bonus';
        case 'deduction':
            return 'Potongan';
        case 'tax':
            return 'Pajak';
        default:
            return ucfirst($type);
    }
}

function component_type_badge($type)
{
    switch ($type) {
        case 'allowance':
            return 'badge-soft-blue';
        case 'bonus':
            return 'badge-soft-green';
        case 'deduction':
            return 'badge-soft-red';
        case 'tax':
            return 'badge-soft-orange';
        default:
            return 'badge-soft-blue';
    }
}

function normalize_money_input($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);

    return (float)$value;
}

$employeeStmt = $pdo->query("
    SELECT id, full_name, employee_code, position, department, salary_type, salary_amount
    FROM employees
    WHERE employment_status = 'active'
    ORDER BY full_name ASC
");

$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'add_component') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $componentType = $_POST['component_type'] ?? '';
        $componentName = trim($_POST['component_name'] ?? '');
        $amount = normalize_money_input($_POST['amount'] ?? 0);
        $payrollMonth = $_POST['payroll_month'] ?? date('Y-m');
        $notes = trim($_POST['notes'] ?? '');

        $allowedTypes = ['allowance', 'bonus', 'deduction', 'tax'];

        if ($employeeId <= 0) {
            $error = 'Karyawan wajib dipilih.';
        } elseif (!in_array($componentType, $allowedTypes)) {
            $error = 'Jenis komponen tidak valid.';
        } elseif ($componentName === '') {
            $error = 'Nama komponen wajib diisi.';
        } elseif ($amount <= 0) {
            $error = 'Nominal harus lebih dari 0.';
        } elseif (!preg_match('/^\d{4}-\d{2}$/', $payrollMonth)) {
            $error = 'Format bulan payroll tidak valid.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO payroll_components (
                        employee_id,
                        component_type,
                        component_name,
                        amount,
                        payroll_month,
                        notes
                    ) VALUES (
                        :employee_id,
                        :component_type,
                        :component_name,
                        :amount,
                        :payroll_month,
                        :notes
                    )
                ");

                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':component_type' => $componentType,
                    ':component_name' => $componentName,
                    ':amount' => $amount,
                    ':payroll_month' => $payrollMonth,
                    ':notes' => $notes !== '' ? $notes : null
                ]);

                $success = 'Komponen payroll berhasil ditambahkan. Jangan lupa klik Generate / Recalculate Payroll agar total gaji diperbarui.';
                $month = $payrollMonth;
            } catch (Exception $e) {
                $error = 'Gagal menambahkan komponen payroll: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_component') {
        $componentId = (int)($_POST['component_id'] ?? 0);

        if ($componentId <= 0) {
            $error = 'Data komponen tidak valid.';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM payroll_components WHERE id = :id");
                $stmt->execute([
                    ':id' => $componentId
                ]);

                $success = 'Komponen payroll berhasil dihapus. Jangan lupa klik Generate / Recalculate Payroll agar total gaji diperbarui.';
            } catch (Exception $e) {
                $error = 'Gagal menghapus komponen payroll: ' . $e->getMessage();
            }
        }
    }
}

$where = [];
$params = [
    ':month' => $month
];

$where[] = "pc.payroll_month = :month";

if ($keyword !== '') {
    $where[] = "(
        e.full_name LIKE :keyword_name
        OR e.employee_code LIKE :keyword_code
        OR e.position LIKE :keyword_position
        OR e.department LIKE :keyword_department
        OR pc.component_name LIKE :keyword_component
        OR pc.notes LIKE :keyword_notes
    )";
    $keywordValue = '%' . $keyword . '%';
    $params[':keyword_name'] = $keywordValue;
    $params[':keyword_code'] = $keywordValue;
    $params[':keyword_position'] = $keywordValue;
    $params[':keyword_department'] = $keywordValue;
    $params[':keyword_component'] = $keywordValue;
    $params[':keyword_notes'] = $keywordValue;
}

if ($typeFilter !== '') {
    $where[] = "pc.component_type = :type";
    $params[':type'] = $typeFilter;
}

$whereSql = implode(' AND ', $where);

$componentStmt = $pdo->prepare("
    SELECT
        pc.*,
        e.full_name,
        e.employee_code,
        e.position,
        e.department
    FROM payroll_components pc
    JOIN employees e ON pc.employee_id = e.id
    WHERE {$whereSql}
    ORDER BY pc.created_at DESC, e.full_name ASC
");

$componentStmt->execute($params);
$components = $componentStmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'allowance' => 0,
    'bonus' => 0,
    'deduction' => 0,
    'tax' => 0,
    'total_addition' => 0,
    'total_subtraction' => 0,
    'count' => count($components)
];

foreach ($components as $component) {
    $type = $component['component_type'];
    $amount = (float)$component['amount'];

    if (isset($summary[$type])) {
        $summary[$type] += $amount;
    }
}

$summary['total_addition'] = $summary['allowance'] + $summary['bonus'];
$summary['total_subtraction'] = $summary['deduction'] + $summary['tax'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Komponen Payroll</h1>
            <p class="text-muted mb-0">
                Tambahkan tunjangan, bonus, potongan, atau pajak manual per karyawan.
            </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/payroll/index.php?month=' . urlencode($month)); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Payroll
            </a>

            <a href="<?= base_url('pages/payroll/settings.php'); ?>" class="btn btn-outline-secondary">
                <i class="bi bi-gear me-1"></i> Setting Payroll
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
                <div class="stat-title">Total Komponen</div>
                <div class="stat-number"><?= component_number($summary['count']); ?></div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-green">
                <div class="stat-title">Tunjangan + Bonus</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= component_money($summary['total_addition']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-red">
                <div class="stat-title">Potongan + Pajak</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= component_money($summary['total_subtraction']); ?>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="stat-card border-orange">
                <div class="stat-title">Bulan Payroll</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= htmlspecialchars(date('M Y', strtotime($month . '-01'))); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <h5 class="fw-bold mb-3">Tambah Komponen Payroll</h5>

        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="add_component">

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
                <label class="form-label fw-semibold">Bulan</label>
                <input type="month" name="payroll_month" class="form-control" value="<?= htmlspecialchars($month); ?>" required>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="component_type" class="form-select" required>
                    <option value="">Pilih Jenis</option>
                    <option value="allowance">Tunjangan</option>
                    <option value="bonus">Bonus</option>
                    <option value="deduction">Potongan</option>
                    <option value="tax">Pajak</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Nama Komponen</label>
                <input
                    type="text"
                    name="component_name"
                    class="form-control"
                    placeholder="Contoh: Bonus Project"
                    required>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Nominal</label>
                <input
                    type="text"
                    name="amount"
                    id="amountInput"
                    class="form-control"
                    placeholder="Contoh: 250.000"
                    required>
            </div>

            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-plus-circle"></i>
                </button>
            </div>

            <div class="col-md-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea
                    name="notes"
                    class="form-control"
                    rows="2"
                    placeholder="Catatan tambahan jika diperlukan"></textarea>
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
                    placeholder="Nama, komponen, catatan">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="type" class="form-select">
                    <option value="">Semua Jenis</option>
                    <option value="allowance" <?= $typeFilter === 'allowance' ? 'selected' : ''; ?>>Tunjangan</option>
                    <option value="bonus" <?= $typeFilter === 'bonus' ? 'selected' : ''; ?>>Bonus</option>
                    <option value="deduction" <?= $typeFilter === 'deduction' ? 'selected' : ''; ?>>Potongan</option>
                    <option value="tax" <?= $typeFilter === 'tax' ? 'selected' : ''; ?>>Pajak</option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>

            <div class="col-md-2 d-grid">
                <a href="<?= base_url('pages/payroll/components.php'); ?>" class="btn btn-outline-secondary">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Daftar Komponen Payroll</h5>
                <p class="text-muted mb-0">
                    Setelah mengubah komponen, kembali ke halaman Payroll dan lakukan Generate / Recalculate.
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
            <table class="table table-hover align-middle" id="componentTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Bulan</th>
                        <th>Jenis</th>
                        <th>Nama Komponen</th>
                        <th>Nominal</th>
                        <th>Catatan</th>
                        <th>Dibuat</th>
                        <th width="120">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($components): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($components as $component): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($component['full_name']); ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($component['employee_code'] ?? '-'); ?> ·
                                        <?= htmlspecialchars($component['position'] ?? '-'); ?>
                                        <?= !empty($component['department']) ? ' · ' . htmlspecialchars($component['department']) : ''; ?>
                                    </small>
                                </td>

                                <td><?= htmlspecialchars($component['payroll_month']); ?></td>

                                <td>
                                    <span class="badge rounded-pill <?= component_type_badge($component['component_type']); ?>">
                                        <?= component_type_label($component['component_type']); ?>
                                    </span>
                                </td>

                                <td><?= htmlspecialchars($component['component_name']); ?></td>

                                <td class="<?= in_array($component['component_type'], ['deduction', 'tax']) ? 'text-danger fw-semibold' : 'text-success fw-semibold'; ?>">
                                    <?= component_money($component['amount']); ?>
                                </td>

                                <td>
                                    <?= !empty($component['notes']) ? nl2br(htmlspecialchars($component['notes'])) : '<span class="text-muted">-</span>'; ?>
                                </td>

                                <td>
                                    <?= date('d M Y H:i', strtotime($component['created_at'])); ?>
                                </td>

                                <td>
                                    <form method="POST" onsubmit="return confirm('Hapus komponen payroll ini?');">
                                        <input type="hidden" name="action" value="delete_component">
                                        <input type="hidden" name="component_id" value="<?= (int)$component['id']; ?>">
                                        <input type="hidden" name="payroll_month" value="<?= htmlspecialchars($month); ?>">

                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i> Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                Belum ada komponen payroll untuk bulan ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($components): ?>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="5">TOTAL</td>
                            <td colspan="4">
                                Tambahan: <span class="text-success"><?= component_money($summary['total_addition']); ?></span>
                                · Potongan/Pajak: <span class="text-danger"><?= component_money($summary['total_subtraction']); ?></span>
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
        const amountInput = document.getElementById('amountInput');

        if (amountInput) {
            amountInput.addEventListener('input', function() {
                let value = this.value.replace(/[^0-9]/g, '');

                if (value === '') {
                    this.value = '';
                    return;
                }

                this.value = new Intl.NumberFormat('id-ID').format(value);
            });
        }

        const searchInput = document.getElementById('tableSearch');
        const table = document.getElementById('componentTable');

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

        if (table) {
            const headers = table.querySelectorAll('thead th');

            headers.forEach(function(header, index) {
                header.style.cursor = 'pointer';

                header.addEventListener('click', function() {
                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const asc = header.dataset.sortAsc !== 'true';

                    rows.sort(function(a, b) {
                        const aText = a.children[index]?.innerText.toLowerCase() || '';
                        const bText = b.children[index]?.innerText.toLowerCase() || '';

                        const aNumber = parseFloat(aText.replace(/[^\d,-]/g, '').replace(',', '.'));
                        const bNumber = parseFloat(bText.replace(/[^\d,-]/g, '').replace(',', '.'));

                        if (!isNaN(aNumber) && !isNaN(bNumber)) {
                            return asc ? aNumber - bNumber : bNumber - aNumber;
                        }

                        return asc ? aText.localeCompare(bText) : bText.localeCompare(aText);
                    });

                    header.dataset.sortAsc = asc ? 'true' : 'false';

                    rows.forEach(function(row) {
                        tbody.appendChild(row);
                    });
                });
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
