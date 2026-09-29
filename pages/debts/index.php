<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Hutang & Piutang - Nukari';

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$status = trim($_GET['status'] ?? '');
$source = trim($_GET['source'] ?? '');

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(d.debt_code LIKE :q_code OR d.related_name LIKE :q_related OR d.category LIKE :q_category OR d.description LIKE :q_description OR d.reference_code LIKE :q_reference OR p.project_name LIKE :q_project)";
    $queryValue = '%' . $q . '%';
    $params[':q_code'] = $queryValue;
    $params[':q_related'] = $queryValue;
    $params[':q_category'] = $queryValue;
    $params[':q_description'] = $queryValue;
    $params[':q_reference'] = $queryValue;
    $params[':q_project'] = $queryValue;
}

if ($type !== '') {
    $where[] = "d.type = :type";
    $params[':type'] = $type;
}

if ($status !== '') {
    $where[] = "d.status = :status";
    $params[':status'] = $status;
}

if ($source !== '') {
    $where[] = "d.source_module = :source";
    $params[':source'] = $source;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT 
        d.*,
        p.project_name,
        COUNT(dp.id) AS payment_count,
        COUNT(DISTINCT dp.transaction_id) AS transaction_count,
        COALESCE(SUM(dp.amount), 0) AS payment_total
    FROM debts d
    LEFT JOIN projects p ON p.id = d.project_id
    LEFT JOIN debt_payments dp ON dp.debt_id = d.id
    $whereSql
    GROUP BY d.id
    ORDER BY
        CASE
            WHEN d.status != 'paid' AND d.due_date IS NOT NULL AND d.due_date < CURDATE() THEN 0
            WHEN d.status != 'paid' AND d.due_date IS NOT NULL THEN 1
            ELSE 2
        END,
        d.due_date ASC,
        d.id DESC
");
$stmt->execute($params);
$debts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='debt' AND status!='paid' THEN amount - paid_amount ELSE 0 END),0) AS total_debt,
        COALESCE(SUM(CASE WHEN type='receivable' AND status!='paid' THEN amount - paid_amount ELSE 0 END),0) AS total_receivable,
        COALESCE(SUM(CASE WHEN source_module='project_term' THEN 1 ELSE 0 END),0) AS project_term_count,
        COALESCE(SUM(CASE WHEN status!='paid' AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END),0) AS overdue_count
    FROM debts
")->fetch(PDO::FETCH_ASSOC);

$sources = [
    '' => 'Semua Sumber',
    'manual' => 'Manual',
    'project_term' => 'Termin Project',
    'custom_order' => 'Custom Order',
    'reimbursement' => 'Dana Talangan',
    'asset_rental' => 'Sewa Aset',
    'payroll' => 'Payroll',
    'product_sale' => 'Penjualan Produk',
    'other' => 'Lainnya'
];


$personalSummaryStmt = $pdo->query("
    SELECT
        CASE
            WHEN related_type = 'personal' AND related_id IS NOT NULL THEN CONCAT('employee:', related_id)
            ELSE CONCAT('manual:', LOWER(TRIM(related_name)))
        END AS person_key,
        MAX(related_id) AS related_id,
        related_name,
        COUNT(*) AS debt_count,
        COUNT(DISTINCT project_id) AS project_count,
        COALESCE(SUM(amount), 0) AS total_amount,
        COALESCE(SUM(paid_amount), 0) AS total_paid,
        COALESCE(SUM(amount - paid_amount), 0) AS outstanding
    FROM debts
    WHERE type = 'debt'
      AND related_type = 'personal'
      AND status != 'paid'
    GROUP BY person_key, related_name
    HAVING outstanding > 0
    ORDER BY outstanding DESC, related_name ASC
    LIMIT 12
");
$personalSummaries = $personalSummaryStmt ? $personalSummaryStmt->fetchAll(PDO::FETCH_ASSOC) : [];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Hutang & Piutang</h1>
            <p class="text-muted mb-0">Pantau hutang, piutang, sumber integrasi, pembayaran, dan transaksi keuangan.</p>
        </div>
        <div class="d-flex gap-2"><a href="<?= base_url('pages/finance/operational_funds.php'); ?>" class="btn btn-outline-success"><i class="bi bi-wallet2 me-1"></i> Dana Operasional</a><a href="<?= base_url('pages/debts/add.php'); ?>" class="btn btn-nukari"><i class="bi bi-plus-circle me-1"></i> Tambah Hutang/Piutang</a></div>
    </div>

    <?php if (!empty($_GET['message'])): ?>
        <div class="alert alert-success rounded-4"><?= htmlspecialchars($_GET['message']); ?></div>
    <?php endif; ?>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Hutang Aktif</div>
                <div class="fs-4 fw-bold text-danger"><?= rupiah($summary['total_debt'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Piutang Aktif</div>
                <div class="fs-4 fw-bold text-success"><?= rupiah($summary['total_receivable'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Piutang dari Termin</div>
                <div class="fs-4 fw-bold text-primary"><?= number_format($summary['project_term_count'] ?? 0); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="panel h-100">
                <div class="text-muted small">Lewat Jatuh Tempo</div>
                <div class="fs-4 fw-bold text-danger"><?= number_format($summary['overdue_count'] ?? 0); ?></div>
            </div>
        </div>
    </div>

    <div class="panel mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Ringkasan Hutang Personal</h5>
                <div class="text-muted small">Total hutang perusahaan kepada setiap orang dari seluruh project dan transaksi personal.</div>
            </div>
            <span class="badge rounded-pill bg-warning-subtle text-warning">Outstanding Personal</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th class="text-end">Transaksi</th>
                        <th class="text-end">Project</th>
                        <th class="text-end">Total Talangan</th>
                        <th class="text-end">Sudah Dibayar</th>
                        <th class="text-end">Sisa Hutang</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($personalSummaries): ?>
                        <?php foreach ($personalSummaries as $person): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($person['related_name']); ?></div>
                                    <small class="text-muted">
                                        <?= !empty($person['related_id']) ? 'Karyawan #' . (int)$person['related_id'] : 'Nama manual'; ?>
                                    </small>
                                </td>
                                <td class="text-end"><?= number_format((int)$person['debt_count']); ?></td>
                                <td class="text-end"><?= number_format((int)$person['project_count']); ?></td>
                                <td class="text-end"><?= rupiah($person['total_amount']); ?></td>
                                <td class="text-end text-success"><?= rupiah($person['total_paid']); ?></td>
                                <td class="text-end fw-bold text-danger"><?= rupiah($person['outstanding']); ?></td>
                                <td class="text-end">
                                    <a href="<?= base_url('pages/debts/index.php?type=debt&q=' . urlencode($person['related_name'])); ?>" class="btn btn-sm btn-outline-primary">Lihat Detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Belum ada hutang personal outstanding.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-lg-4 col-md-12">
                <input type="text" name="q" value="<?= htmlspecialchars($q); ?>" class="form-control" placeholder="Cari kode, pihak, project, referensi...">
            </div>
            <div class="col-lg-2 col-md-4">
                <select name="type" class="form-select">
                    <option value="">Semua Jenis</option>
                    <option value="debt" <?= $type === 'debt' ? 'selected' : ''; ?>>Hutang</option>
                    <option value="receivable" <?= $type === 'receivable' ? 'selected' : ''; ?>>Piutang</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : ''; ?>>Belum Dibayar</option>
                    <option value="partial" <?= $status === 'partial' ? 'selected' : ''; ?>>Sebagian</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <select name="source" class="form-select">
                    <?php foreach ($sources as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value); ?>" <?= $source === $value ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-12 d-grid">
                <button class="btn btn-outline-success">
                    <i class="bi bi-search me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1">Daftar Hutang & Piutang</h5>
                <div class="text-muted small">Kolom sumber membantu Anda mengecek apakah data berasal dari project, custom order, atau input manual.</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle sortable-table" id="debtsTable">
                <thead>
                    <tr>
                        <th class="sortable" data-sort-type="text">Kode <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="text">Jenis <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="text">Sumber <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="text">Pihak / Project <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="number">Nominal <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="number">Terbayar <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="number">Sisa <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="date">Jatuh Tempo <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="text">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort-type="text">Sync <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($debts): ?>
                    <?php foreach ($debts as $row): ?>
                        <?php
                            $remaining = max((float)$row['amount'] - (float)$row['paid_amount'], 0);
                            $sourceLink = debt_get_source_link($row);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['debt_code'] ?: '-'); ?></div>
                                <small class="text-muted"><?= htmlspecialchars($row['reference_code'] ?: ($row['category'] ?: '-')); ?></small>
                            </td>
                            <td><?= debt_type_badge_html($row['type']); ?></td>
                            <td>
                                <?= debt_source_badge($row['source_module']); ?>
                                <div class="small text-muted mt-1">
                                    <?= htmlspecialchars($row['source_table'] ?: '-'); ?>
                                    <?= $row['source_id'] ? '#' . (int)$row['source_id'] : ''; ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($row['related_name']); ?></div>
                                <small class="text-muted">
                                    <?= htmlspecialchars($row['project_name'] ?: ($row['related_type'] ?? 'other')); ?>
                                </small>
                            </td>
                            <td class="fw-semibold"><?= rupiah($row['amount']); ?></td>
                            <td><?= rupiah($row['paid_amount']); ?></td>
                            <td class="<?= $remaining > 0 ? 'fw-bold text-danger' : 'fw-bold text-success'; ?>">
                                <?= rupiah($remaining); ?>
                            </td>
                            <td><?= $row['due_date'] ? date('d M Y', strtotime($row['due_date'])) : '-'; ?></td>
                            <td><?= debt_status_badge_html($row['status'], $row['due_date']); ?></td>
                            <td><?= debt_sync_status_badge($row); ?></td>
                            <td class="text-end">
                                <div class="btn-group">
                                    <a href="<?= base_url('pages/debts/detail.php?id=' . $row['id']); ?>" class="btn btn-sm btn-outline-primary">Detail</a>
                                    <?php if ($sourceLink): ?>
                                        <a href="<?= htmlspecialchars($sourceLink['url']); ?>" class="btn btn-sm btn-outline-info">Sumber</a>
                                    <?php endif; ?>
                                    <?php if (debt_can_be_paid($row)): ?>
                                        <a href="<?= base_url('pages/debts/pay.php?id=' . $row['id']); ?>" class="btn btn-sm btn-outline-success">Bayar</a>
                                    <?php elseif (debt_is_project_term_receivable($row) && $row['status'] !== 'paid'): ?>
                                        <span class="btn btn-sm btn-outline-secondary disabled" title="Piutang termin dibayar dari Detail Project">Monitoring</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-5">
                            Belum ada data hutang atau piutang.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<style>
    #debtsTable th.sortable {
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
    }
    #debtsTable th.sortable:hover {
        color: var(--bs-success);
    }
    #debtsTable .sort-icon {
        font-size: 0.75rem;
        opacity: 0.45;
        margin-left: 4px;
    }
    #debtsTable th.sorted-asc .sort-icon,
    #debtsTable th.sorted-desc .sort-icon {
        opacity: 1;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('debtsTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const headers = table.querySelectorAll('th.sortable');

    function parseNumber(value) {
        return Number(String(value || '').replace(/[^0-9,-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
    }

    function parseDate(value) {
        value = String(value || '').trim();
        if (!value || value === '-') return 0;

        const months = {
            jan: 0, januari: 0,
            feb: 1, februari: 1,
            mar: 2, maret: 2,
            apr: 3, april: 3,
            mei: 4, may: 4,
            jun: 5, juni: 5,
            jul: 6, juli: 6,
            agu: 7, ags: 7, agustus: 7, aug: 7,
            sep: 8, september: 8,
            okt: 9, oktober: 9, oct: 9,
            nov: 10, november: 10,
            des: 11, desember: 11, dec: 11
        };

        const parts = value.toLowerCase().split(/\s+/);
        if (parts.length >= 3) {
            const day = parseInt(parts[0], 10);
            const month = months[parts[1]];
            const year = parseInt(parts[2], 10);
            if (!isNaN(day) && month !== undefined && !isNaN(year)) {
                return new Date(year, month, day).getTime();
            }
        }

        const fallback = Date.parse(value);
        return isNaN(fallback) ? 0 : fallback;
    }

    function getCellValue(row, index, type) {
        const cell = row.children[index];
        const text = cell ? cell.innerText.trim() : '';

        if (type === 'number') return parseNumber(text);
        if (type === 'date') return parseDate(text);

        return text.toLowerCase();
    }

    headers.forEach(function (header, index) {
        header.addEventListener('click', function () {
            const type = header.getAttribute('data-sort-type') || 'text';
            const currentDirection = header.getAttribute('data-sort-direction') || 'none';
            const nextDirection = currentDirection === 'asc' ? 'desc' : 'asc';

            headers.forEach(function (h) {
                h.classList.remove('sorted-asc', 'sorted-desc');
                h.removeAttribute('data-sort-direction');

                const icon = h.querySelector('.sort-icon');
                if (icon) icon.className = 'bi bi-arrow-down-up sort-icon';
            });

            header.setAttribute('data-sort-direction', nextDirection);
            header.classList.add(nextDirection === 'asc' ? 'sorted-asc' : 'sorted-desc');

            const activeIcon = header.querySelector('.sort-icon');
            if (activeIcon) {
                activeIcon.className = nextDirection === 'asc'
                    ? 'bi bi-sort-up sort-icon'
                    : 'bi bi-sort-down sort-icon';
            }

            const rows = Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
                return row.children.length === headers.length + 1 || row.children.length >= headers.length;
            });

            rows.sort(function (a, b) {
                const aValue = getCellValue(a, index, type);
                const bValue = getCellValue(b, index, type);

                if (type === 'number' || type === 'date') {
                    return nextDirection === 'asc' ? aValue - bValue : bValue - aValue;
                }

                return nextDirection === 'asc'
                    ? String(aValue).localeCompare(String(bValue), 'id')
                    : String(bValue).localeCompare(String(aValue), 'id');
            });

            rows.forEach(function (row) {
                tbody.appendChild(row);
            });
        });
    });
});
</script>


<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
