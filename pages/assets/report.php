<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';

if (!function_exists('h')) {
    function h($value) { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('asset_pct')) {
    function asset_pct($value, $total) {
        $total = (float)$total;
        if ($total <= 0) return 0;
        return max(0, min(100, round(((float)$value / $total) * 100, 1)));
    }
}
if (!function_exists('asset_table_exists')) {
    function asset_table_exists(PDO $pdo, $table) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
            $stmt->execute([':table_name' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('asset_safe_query')) {
    function asset_safe_query(PDO $pdo, $sql, $default = []) {
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return $default; }
    }
}
if (!function_exists('asset_safe_fetch')) {
    function asset_safe_fetch(PDO $pdo, $sql, $default = []) {
        try { return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: $default; }
        catch (Throwable $e) { return $default; }
    }
}

try { asset_sync_due_reminders($pdo); } catch (Throwable $e) { /* report must stay accessible */ }

$summary = asset_safe_fetch($pdo, "
    SELECT
        COUNT(*) total_assets,
        COALESCE(SUM(current_value),0) total_value,
        COALESCE(SUM(purchase_price),0) purchase_value,
        SUM(asset_status='active') active_assets,
        SUM(asset_status='maintenance') maintenance_assets,
        SUM(asset_status='rented') rented_assets,
        SUM(asset_status='sold') sold_assets,
        SUM(condition_status='damaged') damaged_assets,
        SUM(condition_status='lost') lost_assets,
        COALESCE(AVG(condition_score),0) avg_score
    FROM assets
", [
    'total_assets'=>0,'total_value'=>0,'purchase_value'=>0,'active_assets'=>0,'maintenance_assets'=>0,
    'rented_assets'=>0,'sold_assets'=>0,'damaged_assets'=>0,'lost_assets'=>0,'avg_score'=>0
]);

$byCategory = asset_safe_query($pdo, "
    SELECT COALESCE(NULLIF(category,''),'Tanpa Kategori') category,
           COUNT(*) total,
           COALESCE(SUM(current_value),0) value_sum
    FROM assets
    GROUP BY COALESCE(NULLIF(category,''),'Tanpa Kategori')
    ORDER BY total DESC, value_sum DESC
");

$byStatus = asset_safe_query($pdo, "
    SELECT COALESCE(asset_status,'active') asset_status, COUNT(*) total, COALESCE(SUM(current_value),0) value_sum
    FROM assets
    GROUP BY COALESCE(asset_status,'active')
    ORDER BY total DESC
");

$maintenanceCost = asset_table_exists($pdo, 'asset_maintenance') ? asset_safe_query($pdo, "
    SELECT DATE_FORMAT(maintenance_date,'%Y-%m') month_label, COALESCE(SUM(cost),0) total_cost, COUNT(*) total_job
    FROM asset_maintenance
    GROUP BY DATE_FORMAT(maintenance_date,'%Y-%m')
    ORDER BY month_label DESC
    LIMIT 12
") : [];

$recentMaintenance = asset_table_exists($pdo, 'asset_maintenance') ? asset_safe_query($pdo, "
    SELECT m.*, a.asset_code, a.asset_name
    FROM asset_maintenance m
    LEFT JOIN assets a ON m.asset_id=a.id
    ORDER BY m.maintenance_date DESC, m.id DESC
    LIMIT 10
") : [];

$reminders = asset_table_exists($pdo, 'asset_reminders') ? asset_safe_query($pdo, "
    SELECT r.*, a.asset_name, a.asset_code
    FROM asset_reminders r
    LEFT JOIN assets a ON r.asset_id=a.id
    WHERE r.status IN ('active','overdue')
    ORDER BY r.due_date ASC, r.id ASC
    LIMIT 50
") : [];

$reminderSummary = asset_table_exists($pdo, 'asset_reminders') ? asset_safe_fetch($pdo, "
    SELECT
        SUM(status='active') active_total,
        SUM(status='overdue') overdue_total,
        SUM(due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status IN ('active','overdue')) due_7_days,
        SUM(due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status IN ('active','overdue')) due_30_days
    FROM asset_reminders
", ['active_total'=>0,'overdue_total'=>0,'due_7_days'=>0,'due_30_days'=>0]) : ['active_total'=>0,'overdue_total'=>0,'due_7_days'=>0,'due_30_days'=>0];

$rentals = asset_table_exists($pdo, 'asset_rental_payments') ? asset_safe_query($pdo, "
    SELECT a.id, a.asset_name, a.asset_code, COALESCE(SUM(p.amount),0) rental_income, COUNT(p.id) payment_count
    FROM assets a
    LEFT JOIN asset_rental_payments p ON p.asset_id=a.id
    GROUP BY a.id
    HAVING rental_income > 0 OR a.asset_status='rented'
    ORDER BY rental_income DESC, a.asset_name ASC
    LIMIT 20
") : [];

$profitability = asset_safe_query($pdo, "
    SELECT a.id, a.asset_code, a.asset_name, a.purchase_price,
           COALESCE(r.rental_income,0) rental_income,
           COALESCE(m.maintenance_cost,0) maintenance_cost,
           COALESCE(s.sale_income,0) sale_income,
           COALESCE(r.rental_income,0) + COALESCE(s.sale_income,0) - COALESCE(m.maintenance_cost,0) - COALESCE(a.purchase_price,0) net_result
    FROM assets a
    LEFT JOIN (SELECT asset_id, SUM(amount) rental_income FROM asset_rental_payments GROUP BY asset_id) r ON r.asset_id=a.id
    LEFT JOIN (SELECT asset_id, SUM(cost) maintenance_cost FROM asset_maintenance GROUP BY asset_id) m ON m.asset_id=a.id
    LEFT JOIN (SELECT asset_id, SUM(sale_price) sale_income FROM asset_sales GROUP BY asset_id) s ON s.asset_id=a.id
    WHERE COALESCE(r.rental_income,0) > 0 OR COALESCE(m.maintenance_cost,0) > 0 OR COALESCE(s.sale_income,0) > 0
    ORDER BY net_result DESC, a.asset_name
");

$depreciation = asset_safe_query($pdo, "
    SELECT id, asset_code, asset_name, purchase_price, current_value, residual_value, useful_life_months, purchase_date,
           GREATEST(COALESCE(purchase_price,0) - COALESCE(current_value,0),0) depreciation_value
    FROM assets
    WHERE COALESCE(useful_life_months,0) > 0 OR COALESCE(residual_value,0) > 0 OR COALESCE(current_value,0) < COALESCE(purchase_price,0)
    ORDER BY depreciation_value DESC
    LIMIT 15
");

$topAssets = asset_safe_query($pdo, "
    SELECT a.*, e.full_name responsible_name
    FROM assets a
    LEFT JOIN employees e ON a.responsible_employee_id=e.id
    ORDER BY COALESCE(a.current_value,0) DESC, a.id DESC
    LIMIT 15
");

$totalAssets = max(1, (int)($summary['total_assets'] ?? 0));
$totalValue = max(1, (float)($summary['total_value'] ?? 0));
$totalMaintenance = array_sum(array_map(fn($r) => (float)($r['total_cost'] ?? 0), $maintenanceCost));

$pageTitle = 'Laporan Aset - Nukari';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<style>
.asset-report-hero{background:linear-gradient(135deg,#0f766e,#16a34a);border-radius:28px;color:white;padding:28px;margin-bottom:22px;box-shadow:0 18px 40px rgba(15,118,110,.18)}
.asset-card{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.06);height:100%}
.asset-kpi .label{font-size:.78rem;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.02em}.asset-kpi .value{font-size:1.75rem;font-weight:900;color:#0f172a;line-height:1.1}.asset-kpi .sub{font-size:.82rem;color:#64748b;margin-top:6px}.mini-bar{height:9px;background:#e2e8f0;border-radius:999px;overflow:hidden}.mini-bar span{display:block;height:100%;background:linear-gradient(90deg,#0f766e,#22c55e);border-radius:999px}.status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;background:#16a34a;margin-right:6px}.table thead th{background:#f1f5f9;color:#334155;font-size:.82rem;text-transform:uppercase}.table td{vertical-align:middle}.report-section-title{font-weight:900;color:#0f172a;margin-bottom:12px}.empty-state{padding:18px;border:1px dashed #cbd5e1;border-radius:16px;color:#64748b;background:#f8fafc}.asset-report-hero .btn{font-weight:700}.badge-soft{background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}.badge-danger-soft{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.badge-warning-soft{background:#fffbeb;color:#b45309;border:1px solid #fde68a}.value-small{font-weight:800;color:#0f172a}.chart-row{display:grid;grid-template-columns:160px 1fr 110px;gap:12px;align-items:center;margin-bottom:10px}.chart-row .name{font-weight:700;color:#334155;font-size:.9rem}.chart-row .amount{text-align:right;font-weight:800;color:#0f172a;font-size:.9rem}@media(max-width:768px){.chart-row{grid-template-columns:1fr}.chart-row .amount{text-align:left}.asset-report-hero{padding:22px}.asset-report-hero h1{font-size:1.7rem}}
</style>
<main class="main-content">
    <div class="asset-report-hero d-flex justify-content-between flex-wrap gap-3 align-items-center">
        <div>
            <div class="badge bg-light text-success rounded-pill mb-3 px-3 py-2"><i class="bi bi-clipboard-data me-1"></i> Asset Intelligence</div>
            <h1 class="fw-bold mb-1">Laporan & Dashboard Aset</h1>
            <div>Ringkasan nilai aset, kondisi, maintenance, reminder, penyusutan, dan aset sewa.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/assets/export_excel.php'); ?>" class="btn btn-light rounded-pill"><i class="bi bi-file-earmark-excel me-1"></i> Export Excel</a>
            <a href="<?= base_url('pages/assets/index.php'); ?>" class="btn btn-outline-light rounded-pill"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3"><div class="asset-card asset-kpi"><div class="label">Total Aset</div><div class="value"><?= number_format((int)$summary['total_assets']); ?></div><div class="sub">Semua aset tercatat.</div></div></div>
        <div class="col-md-6 col-xl-3"><div class="asset-card asset-kpi"><div class="label">Total Nilai Saat Ini</div><div class="value"><?= rupiah($summary['total_value']); ?></div><div class="sub">Harga beli: <?= rupiah($summary['purchase_value']); ?></div></div></div>
        <div class="col-md-6 col-xl-2"><div class="asset-card asset-kpi"><div class="label">Aktif</div><div class="value"><?= (int)$summary['active_assets']; ?></div><div class="sub"><?= asset_pct($summary['active_assets'], $totalAssets); ?>% dari aset.</div></div></div>
        <div class="col-md-6 col-xl-2"><div class="asset-card asset-kpi"><div class="label">Maintenance</div><div class="value"><?= (int)$summary['maintenance_assets']; ?></div><div class="sub">Butuh perhatian teknis.</div></div></div>
        <div class="col-md-6 col-xl-2"><div class="asset-card asset-kpi"><div class="label">Overdue</div><div class="value"><?= (int)($reminderSummary['overdue_total'] ?? 0); ?></div><div class="sub">Reminder lewat tempo.</div></div></div>
    </div>

    <div class="asset-card mb-3"><h5 class="report-section-title">Profitabilitas Aset</h5><?php if(!$profitability): ?><div class="empty-state">Belum ada transaksi sewa, maintenance, atau penjualan aset.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Aset</th><th class="text-end">Harga Beli</th><th class="text-end">Pendapatan Sewa</th><th class="text-end">Biaya Maintenance</th><th class="text-end">Penjualan</th><th class="text-end">Hasil Lifecycle</th></tr></thead><tbody><?php foreach($profitability as $row): ?><tr><td><strong><?= h($row['asset_code'] ?: '-'); ?></strong><div class="small text-muted"><?= h($row['asset_name']); ?></div></td><td class="text-end"><?= rupiah($row['purchase_price']); ?></td><td class="text-end text-success"><?= rupiah($row['rental_income']); ?></td><td class="text-end text-danger"><?= rupiah($row['maintenance_cost']); ?></td><td class="text-end text-success"><?= rupiah($row['sale_income']); ?></td><td class="text-end fw-bold <?= $row['net_result']>=0?'text-success':'text-danger'; ?>"><?= rupiah($row['net_result']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="asset-card"><h5 class="report-section-title">Komposisi Nilai per Kategori</h5><?php if(!$byCategory): ?><div class="empty-state">Belum ada data kategori aset.</div><?php else: ?><?php foreach($byCategory as $r): $pct=asset_pct($r['value_sum'],$totalValue); ?><div class="chart-row"><div class="name"><?= h($r['category']); ?> <span class="text-muted">(<?= (int)$r['total']; ?>)</span></div><div class="mini-bar"><span style="width:<?= $pct; ?>%"></span></div><div class="amount"><?= rupiah($r['value_sum']); ?></div></div><?php endforeach; ?><?php endif; ?></div></div>
        <div class="col-lg-6"><div class="asset-card"><h5 class="report-section-title">Status Aset</h5><?php if(!$byStatus): ?><div class="empty-state">Belum ada data status aset.</div><?php else: ?><?php foreach($byStatus as $r): [$label,$color,$icon]=asset_status_view($r['asset_status']); $pct=asset_pct($r['total'],$totalAssets); ?><div class="chart-row"><div class="name"><i class="bi <?= h($icon); ?> me-1"></i><?= h($label); ?> <span class="text-muted">(<?= (int)$r['total']; ?>)</span></div><div class="mini-bar"><span style="width:<?= $pct; ?>%"></span></div><div class="amount"><?= rupiah($r['value_sum']); ?></div></div><?php endforeach; ?><?php endif; ?></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="asset-card"><h5 class="report-section-title">Biaya Maintenance per Bulan</h5><?php if(!$maintenanceCost): ?><div class="empty-state">Belum ada biaya maintenance tercatat.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Bulan</th><th>Jumlah Service</th><th class="text-end">Biaya</th></tr></thead><tbody><?php foreach($maintenanceCost as $r): ?><tr><td><?= h($r['month_label']); ?></td><td><?= (int)$r['total_job']; ?></td><td class="text-end value-small"><?= rupiah($r['total_cost']); ?></td></tr><?php endforeach; ?><tr class="table-light"><td colspan="2" class="fw-bold">Total</td><td class="text-end fw-bold"><?= rupiah($totalMaintenance); ?></td></tr></tbody></table></div><?php endif; ?></div></div>
        <div class="col-lg-6"><div class="asset-card"><h5 class="report-section-title">Ringkasan Reminder</h5><div class="row g-2"><div class="col-6"><div class="p-3 rounded-4 bg-light"><div class="text-muted small fw-bold">Aktif</div><div class="h3 fw-bold mb-0"><?= (int)($reminderSummary['active_total'] ?? 0); ?></div></div></div><div class="col-6"><div class="p-3 rounded-4 bg-light"><div class="text-muted small fw-bold">Overdue</div><div class="h3 fw-bold mb-0 text-danger"><?= (int)($reminderSummary['overdue_total'] ?? 0); ?></div></div></div><div class="col-6"><div class="p-3 rounded-4 bg-light"><div class="text-muted small fw-bold">7 Hari</div><div class="h3 fw-bold mb-0"><?= (int)($reminderSummary['due_7_days'] ?? 0); ?></div></div></div><div class="col-6"><div class="p-3 rounded-4 bg-light"><div class="text-muted small fw-bold">30 Hari</div><div class="h3 fw-bold mb-0"><?= (int)($reminderSummary['due_30_days'] ?? 0); ?></div></div></div></div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7"><div class="asset-card"><h5 class="report-section-title">Kalender Reminder Terdekat</h5><?php if(!$reminders): ?><div class="empty-state">Belum ada reminder aktif atau overdue.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tanggal</th><th>Reminder</th><th>Aset</th><th>Status</th></tr></thead><tbody><?php foreach($reminders as $r): $isOverdue=($r['status']==='overdue' || (!empty($r['due_date']) && $r['due_date'] < date('Y-m-d'))); ?><tr><td><?= h($r['due_date']); ?></td><td><strong><?= h(asset_reminder_type_label($r['reminder_type'])); ?></strong><div class="small text-muted"><?= h($r['title']); ?></div></td><td><?= h(trim(($r['asset_code'] ?: '') . ' - ' . ($r['asset_name'] ?: ''), ' -') ?: '-'); ?></td><td><span class="badge <?= $isOverdue ? 'badge-danger-soft' : 'badge-warning-soft'; ?> rounded-pill"><?= $isOverdue ? 'Overdue' : 'Aktif'; ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>
        <div class="col-lg-5"><div class="asset-card"><h5 class="report-section-title">Profitability Aset Disewakan</h5><?php if(!$rentals): ?><div class="empty-state">Belum ada pemasukan sewa aset.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Aset</th><th class="text-end">Pemasukan</th></tr></thead><tbody><?php foreach($rentals as $r): ?><tr><td><strong><?= h($r['asset_code'] ?: '-'); ?></strong><div class="small text-muted"><?= h($r['asset_name']); ?> · <?= (int)$r['payment_count']; ?> pembayaran</div></td><td class="text-end value-small"><?= rupiah($r['rental_income']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="asset-card"><h5 class="report-section-title">Top Nilai Aset</h5><?php if(!$topAssets): ?><div class="empty-state">Belum ada aset.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Aset</th><th>Lokasi / PIC</th><th class="text-end">Nilai</th></tr></thead><tbody><?php foreach($topAssets as $a): ?><tr><td><strong><?= h($a['asset_code']); ?></strong><div class="small text-muted"><?= h($a['asset_name']); ?></div></td><td><?= h($a['location'] ?: '-'); ?><div class="small text-muted"><?= h($a['responsible_name'] ?: 'Tanpa PIC'); ?></div></td><td class="text-end value-small"><?= rupiah($a['current_value'] ?? 0); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>
        <div class="col-lg-6"><div class="asset-card"><h5 class="report-section-title">Penyusutan / Valuasi</h5><?php if(!$depreciation): ?><div class="empty-state">Belum ada data penyusutan. Isi umur ekonomis dan nilai residu pada aset untuk menampilkan laporan ini.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Aset</th><th>Umur</th><th class="text-end">Nilai Buku</th><th class="text-end">Penyusutan</th></tr></thead><tbody><?php foreach($depreciation as $d): ?><tr><td><strong><?= h($d['asset_code']); ?></strong><div class="small text-muted"><?= h($d['asset_name']); ?></div></td><td><?= (int)$d['useful_life_months']; ?> bln</td><td class="text-end value-small"><?= rupiah($d['current_value']); ?></td><td class="text-end text-danger fw-bold"><?= rupiah($d['depreciation_value']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>
    </div>

    <div class="asset-card mb-4"><h5 class="report-section-title">Maintenance Terbaru</h5><?php if(!$recentMaintenance): ?><div class="empty-state">Belum ada riwayat maintenance.</div><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Tanggal</th><th>Aset</th><th>Jenis</th><th>Vendor</th><th class="text-end">Biaya</th><th>Next</th></tr></thead><tbody><?php foreach($recentMaintenance as $m): ?><tr><td><?= h($m['maintenance_date']); ?></td><td><strong><?= h($m['asset_code'] ?: '-'); ?></strong><div class="small text-muted"><?= h($m['asset_name']); ?></div></td><td><?= h($m['maintenance_type'] ?: '-'); ?><div class="small text-muted"><?= h($m['description'] ?? ''); ?></div></td><td><?= h($m['vendor_name'] ?: '-'); ?></td><td class="text-end value-small"><?= rupiah($m['cost']); ?></td><td><?= h($m['next_maintenance_date'] ?: '-'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
