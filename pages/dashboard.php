<?php
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Dashboard - Nukari Management';

if (!function_exists('rupiah')) {
    function rupiah($angka)
    {
        return 'Rp ' . number_format((float) $angka, 0, ',', '.');
    }
}

/* =====================================================
   DASHBOARD HELPER - dibuat aman agar tidak merusak modul lain
===================================================== */
function dashboard_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function dashboard_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dashboard_scalar(PDO $pdo, string $sql, $default = 0)
{
    try {
        $result = $pdo->query($sql)->fetchColumn();
        return $result !== false && $result !== null ? $result : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function dashboard_rows(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function dashboard_url(string $path): string
{
    if (function_exists('base_url')) {
        return base_url($path);
    }
    return '../' . ltrim($path, '/');
}

function dashboard_badge_class(string $status): string
{
    return match ($status) {
        'ongoing', 'active', 'paid', 'completed', 'done', 'approved' => 'badge-soft-green',
        'planning', 'pending', 'partial' => 'badge-soft-blue',
        'cancelled', 'unpaid', 'rejected', 'overdue' => 'badge-soft-red',
        default => 'badge-soft-orange',
    };
}

function dashboard_percent($value, $total): int
{
    $total = (float) $total;
    if ($total <= 0) {
        return 0;
    }
    return (int) round(((float) $value / $total) * 100);
}

$today = date('Y-m-d');
$todayLabel = date('d M Y');
$currentUserName = $_SESSION['full_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'User';
$currentRole = $_SESSION['role'] ?? '-';

/* =====================================================
   DATA RINGKASAN UTAMA
===================================================== */
$totalUsers = dashboard_table_exists($pdo, 'users') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM users") : 0;
$totalEmployees = dashboard_table_exists($pdo, 'employees') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM employees") : 0;
$totalProjects = dashboard_table_exists($pdo, 'projects') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM projects") : 0;
$pendingUsers = dashboard_table_exists($pdo, 'users') && dashboard_column_exists($pdo, 'users', 'status') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'pending'") : 0;

$totalProjectIncome = dashboard_table_exists($pdo, 'project_transactions') ? dashboard_scalar($pdo, "
    SELECT COALESCE(SUM(amount),0)
    FROM project_transactions
    WHERE transaction_type = 'income'
") : 0;

$totalProjectExpense = dashboard_table_exists($pdo, 'project_transactions') ? dashboard_scalar($pdo, "
    SELECT COALESCE(SUM(amount),0)
    FROM project_transactions
    WHERE transaction_type = 'expense'
") : 0;

$totalWorkerCost = dashboard_table_exists($pdo, 'project_workers') ? dashboard_scalar($pdo, "
    SELECT COALESCE(SUM(rate),0)
    FROM project_workers
") : 0;

$totalCost = (float) $totalProjectExpense + (float) $totalWorkerCost;
$netCash = (float) $totalProjectIncome - (float) $totalCost;

$ongoingProjects = dashboard_table_exists($pdo, 'projects') && dashboard_column_exists($pdo, 'projects', 'status') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM projects WHERE status = 'ongoing'") : 0;
$planningProjects = dashboard_table_exists($pdo, 'projects') && dashboard_column_exists($pdo, 'projects', 'status') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM projects WHERE status = 'planning'") : 0;
$completedProjects = dashboard_table_exists($pdo, 'projects') && dashboard_column_exists($pdo, 'projects', 'status') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM projects WHERE status = 'completed'") : 0;
$cancelledProjects = dashboard_table_exists($pdo, 'projects') && dashboard_column_exists($pdo, 'projects', 'status') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM projects WHERE status = 'cancelled'") : 0;

/* =====================================================
   DATA TAMBAHAN DENGAN PENGAMAN TABEL
===================================================== */
$totalAssets = dashboard_table_exists($pdo, 'assets') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM assets") : 0;
$totalProducts = dashboard_table_exists($pdo, 'products') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM products") : 0;
$totalMaterials = dashboard_table_exists($pdo, 'materials') ? dashboard_scalar($pdo, "SELECT COUNT(*) FROM materials") : 0;

$todayAttendance = 0;
$todayLate = 0;
if (dashboard_table_exists($pdo, 'attendance') && dashboard_column_exists($pdo, 'attendance', 'attendance_date')) {
    $todayAttendance = dashboard_scalar($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()");
    if (dashboard_column_exists($pdo, 'attendance', 'status')) {
        $todayLate = dashboard_scalar($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND status = 'late'");
    }
}

$projectProgress = dashboard_percent($completedProjects, max(1, $totalProjects));
$incomeExpenseRatio = dashboard_percent($totalProjectIncome, max(1, ((float) $totalProjectIncome + (float) $totalCost)));

/* =====================================================
   PROJECT TERBARU
===================================================== */
$latestProjects = [];
if (dashboard_table_exists($pdo, 'projects')) {
    $clientJoin = dashboard_table_exists($pdo, 'clients') && dashboard_column_exists($pdo, 'projects', 'client_id')
        ? "LEFT JOIN clients c ON p.client_id = c.id"
        : "";
    $clientSelect = dashboard_table_exists($pdo, 'clients') && dashboard_column_exists($pdo, 'projects', 'client_id')
        ? "c.client_name"
        : "NULL AS client_name";

    $latestProjects = dashboard_rows($pdo, "
        SELECT p.project_name, p.contract_value, p.status, $clientSelect
        FROM projects p
        $clientJoin
        ORDER BY p.id DESC
        LIMIT 6
    ");
}

/* =====================================================
   TERMIN H-7
===================================================== */
$upcomingTerms = [];
if (dashboard_table_exists($pdo, 'project_payment_terms') && dashboard_table_exists($pdo, 'projects')) {
    $upcomingTerms = dashboard_rows($pdo, "
        SELECT ppt.*, p.project_name
        FROM project_payment_terms ppt
        JOIN projects p ON ppt.project_id = p.id
        WHERE ppt.status IN ('unpaid','partial')
        AND ppt.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY ppt.due_date ASC
        LIMIT 6
    ");
}

/* =====================================================
   AKTIVITAS TERBARU
===================================================== */
$latestActivities = [];
if (dashboard_table_exists($pdo, 'activity_logs')) {
    $latestActivities = dashboard_rows($pdo, "
        SELECT * FROM activity_logs
        ORDER BY id DESC
        LIMIT 6
    ");
} elseif (dashboard_table_exists($pdo, 'audit_logs')) {
    $latestActivities = dashboard_rows($pdo, "
        SELECT * FROM audit_logs
        ORDER BY id DESC
        LIMIT 6
    ");
}

$attentionItems = [];
if (count($upcomingTerms) > 0) {
    $attentionItems[] = [
        'type' => 'Termin Project',
        'title' => count($upcomingTerms) . ' termin mendekati jatuh tempo',
        'desc' => 'Periksa jadwal pembayaran project dalam 7 hari ke depan.',
        'level' => 'warning',
    ];
}
if ($pendingUsers > 0) {
    $attentionItems[] = [
        'type' => 'User Pending',
        'title' => $pendingUsers . ' user menunggu persetujuan',
        'desc' => 'Review akses user agar penggunaan sistem tetap aman.',
        'level' => 'info',
    ];
}
if ($netCash < 0) {
    $attentionItems[] = [
        'type' => 'Keuangan Project',
        'title' => 'Cash project sedang minus',
        'desc' => 'Pengeluaran dan biaya tenaga kerja lebih besar dari pendapatan masuk.',
        'level' => 'danger',
    ];
}
if (!$attentionItems) {
    $attentionItems[] = [
        'type' => 'Status Sistem',
        'title' => 'Tidak ada perhatian khusus hari ini',
        'desc' => 'Dashboard belum menemukan termin H-7, user pending, atau cash minus.',
        'level' => 'success',
    ];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
    .dashboard-hero {
        background: linear-gradient(135deg, #102a43 0%, #243b53 45%, #334e68 100%);
        color: #fff;
        border-radius: 28px;
        padding: 28px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .15);
    }

    .dashboard-hero:after {
        content: '';
        position: absolute;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .08);
        right: -80px;
        top: -100px;
    }

    .hero-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 999px;
        background: rgba(255, 255, 255, .12);
        color: rgba(255, 255, 255, .9);
        font-size: 13px;
        margin-bottom: 14px;
    }

    .hero-title {
        font-size: clamp(28px, 4vw, 42px);
        font-weight: 800;
        line-height: 1.1;
        margin-bottom: 10px;
    }

    .hero-subtitle {
        color: rgba(255, 255, 255, .75);
        max-width: 720px;
        margin-bottom: 0;
    }

    .hero-meta-card {
        background: rgba(255, 255, 255, .12);
        border: 1px solid rgba(255, 255, 255, .16);
        backdrop-filter: blur(8px);
        border-radius: 22px;
        padding: 18px;
        height: 100%;
    }

    .dashboard-card {
        background: #fff;
        border: 1px solid rgba(148, 163, 184, .25);
        border-radius: 24px;
        padding: 22px;
        height: 100%;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .06);
    }

    .metric-card {
        background: #fff;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 24px;
        padding: 20px;
        height: 100%;
        position: relative;
        overflow: hidden;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .05);
        transition: .2s ease;
    }

    .metric-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 18px 40px rgba(15, 23, 42, .08);
    }

    .metric-icon {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        background: #f1f5f9;
        margin-bottom: 14px;
    }

    .metric-title {
        font-size: 13px;
        color: #64748b;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .metric-number {
        font-size: clamp(24px, 3vw, 34px);
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
        margin-top: 6px;
    }

    .metric-note {
        font-size: 13px;
        color: #64748b;
        margin-top: 8px;
    }

    .guide-step {
        display: flex;
        gap: 12px;
        padding: 14px 0;
        border-bottom: 1px dashed #e2e8f0;
    }

    .guide-step:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .guide-number {
        width: 30px;
        height: 30px;
        border-radius: 999px;
        background: #e0f2fe;
        color: #0369a1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        flex: 0 0 30px;
    }

    .attention-item {
        border-radius: 18px;
        padding: 16px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 12px;
    }

    .attention-item.danger {
        border-color: #fecaca;
        background: #fff1f2;
    }

    .attention-item.warning {
        border-color: #fed7aa;
        background: #fff7ed;
    }

    .attention-item.info {
        border-color: #bfdbfe;
        background: #eff6ff;
    }

    .attention-item.success {
        border-color: #bbf7d0;
        background: #f0fdf4;
    }

    .shortcut-btn {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        color: #0f172a;
        text-decoration: none;
        background: #fff;
        font-weight: 700;
        transition: .2s ease;
    }

    .shortcut-btn:hover {
        color: #0f172a;
        background: #f8fafc;
        transform: translateX(3px);
    }

    .progress-soft {
        height: 10px;
        border-radius: 999px;
        overflow: hidden;
        background: #e2e8f0;
    }

    .progress-soft>span {
        display: block;
        height: 100%;
        background: linear-gradient(90deg, #0ea5e9, #22c55e);
        border-radius: 999px;
    }

    .table-dashboard thead th {
        color: #64748b;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom: 1px solid #e2e8f0;
    }

    .table-dashboard td {
        border-color: #f1f5f9;
    }

    .badge-soft-green {
        background: #dcfce7;
        color: #166534;
    }

    .badge-soft-blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .badge-soft-orange {
        background: #ffedd5;
        color: #c2410c;
    }

    .badge-soft-red {
        background: #fee2e2;
        color: #b91c1c;
    }

    .badge-soft-purple {
        background: #f3e8ff;
        color: #7e22ce;
    }

    .section-title {
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 4px;
    }

    .section-subtitle {
        color: #64748b;
        margin-bottom: 0;
        font-size: 14px;
    }

    .small-muted {
        color: #64748b;
        font-size: 13px;
    }
</style>

<main class="main-content">

    <div class="dashboard-hero mb-4">
        <div class="row g-4 align-items-center position-relative" style="z-index:1;">
            <div class="col-lg-8">
                <div class="hero-kicker">🏢 PT Nukari Kriya Raya Management System</div>
                <h1 class="hero-title">Selamat datang, <?= htmlspecialchars($currentUserName); ?></h1>
                <p class="hero-subtitle">
                    Dashboard ini merangkum kondisi perusahaan, project, keuangan, karyawan, aset, produk, serta hal penting yang perlu diperhatikan hari ini.
                </p>
            </div>
            <div class="col-lg-4">
                <div class="hero-meta-card">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-white-50">Tanggal</span>
                        <strong><?= htmlspecialchars($todayLabel); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-white-50">Role</span>
                        <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $currentRole))); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-white-50">Status Sistem</span>
                        <strong>Aktif</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon">📁</div>
                <div class="metric-title">Total Project</div>
                <div class="metric-number"><?= (int) $totalProjects; ?></div>
                <div class="metric-note"><?= (int) $ongoingProjects; ?> berjalan, <?= (int) $completedProjects; ?> selesai</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon">💰</div>
                <div class="metric-title">Pendapatan Project</div>
                <div class="metric-number" style="font-size:26px;"><?= rupiah($totalProjectIncome); ?></div>
                <div class="metric-note">Total income dari transaksi project</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon">📉</div>
                <div class="metric-title">Total Biaya</div>
                <div class="metric-number" style="font-size:26px;"><?= rupiah($totalCost); ?></div>
                <div class="metric-note">Pengeluaran + biaya tenaga kerja</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="metric-card">
                <div class="metric-icon"><?= $netCash >= 0 ? '✅' : '⚠️'; ?></div>
                <div class="metric-title"><?= $netCash >= 0 ? 'Cash Surplus' : 'Cash Minus'; ?></div>
                <div class="metric-number" style="font-size:26px;"><?= rupiah(abs($netCash)); ?></div>
                <div class="metric-note">Posisi kas project saat ini</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h5 class="section-title">Ringkasan Operasional</h5>
                        <p class="section-subtitle">Data penting dari modul HR, Project, Asset, Produk, dan User.</p>
                    </div>
                    <span class="badge badge-soft-blue rounded-pill px-3 py-2">Hari ini: <?= htmlspecialchars($todayLabel); ?></span>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6 col-xl-3">
                        <div class="p-3 rounded-4 bg-light h-100">
                            <div class="small-muted">Karyawan</div>
                            <div class="fs-3 fw-bold"><?= (int) $totalEmployees; ?></div>
                            <div class="small-muted">Hadir hari ini: <?= (int) $todayAttendance; ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="p-3 rounded-4 bg-light h-100">
                            <div class="small-muted">User Sistem</div>
                            <div class="fs-3 fw-bold"><?= (int) $totalUsers; ?></div>
                            <div class="small-muted">Pending: <?= (int) $pendingUsers; ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="p-3 rounded-4 bg-light h-100">
                            <div class="small-muted">Asset</div>
                            <div class="fs-3 fw-bold"><?= (int) $totalAssets; ?></div>
                            <div class="small-muted">Terdata dalam sistem</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="p-3 rounded-4 bg-light h-100">
                            <div class="small-muted">Produk / Material</div>
                            <div class="fs-3 fw-bold"><?= (int) $totalProducts; ?></div>
                            <div class="small-muted">Material: <?= (int) $totalMaterials; ?></div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Progress project selesai</strong>
                        <span class="small-muted"><?= $projectProgress; ?>%</span>
                    </div>
                    <div class="progress-soft"><span style="width: <?= $projectProgress; ?>%;"></span></div>
                </div>

                <div class="mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Rasio pendapatan terhadap total perputaran project</strong>
                        <span class="small-muted"><?= $incomeExpenseRatio; ?>%</span>
                    </div>
                    <div class="progress-soft"><span style="width: <?= $incomeExpenseRatio; ?>%;"></span></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="dashboard-card">
                <h5 class="section-title">Yang Perlu Diperhatikan</h5>
                <p class="section-subtitle mb-3">Ringkasan otomatis untuk manajemen.</p>

                <?php foreach ($attentionItems as $item): ?>
                    <div class="attention-item <?= htmlspecialchars($item['level']); ?>">
                        <div class="small fw-bold text-uppercase mb-1"><?= htmlspecialchars($item['type']); ?></div>
                        <div class="fw-bold"><?= htmlspecialchars($item['title']); ?></div>
                        <div class="small-muted mt-1"><?= htmlspecialchars($item['desc']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="dashboard-card">
                <h5 class="section-title">Panduan Cepat</h5>
                <p class="section-subtitle mb-3">Alur sederhana agar orang awam memahami sistem.</p>

                <div class="guide-step">
                    <div class="guide-number">1</div>
                    <div>
                        <div class="fw-bold">Mulai dari data master</div>
                        <div class="small-muted">Isi data karyawan, user, asset, produk, material, dan client terlebih dahulu.</div>
                    </div>
                </div>
                <div class="guide-step">
                    <div class="guide-number">2</div>
                    <div>
                        <div class="fw-bold">Kelola pekerjaan melalui Project</div>
                        <div class="small-muted">Buat project, input termin, dana masuk, pengeluaran, pekerja, dan dokumen.</div>
                    </div>
                </div>
                <div class="guide-step">
                    <div class="guide-number">3</div>
                    <div>
                        <div class="fw-bold">Pantau operasional harian</div>
                        <div class="small-muted">Gunakan attendance, payroll, produksi, mutasi stok, dan laporan transaksi.</div>
                    </div>
                </div>
                <div class="guide-step">
                    <div class="guide-number">4</div>
                    <div>
                        <div class="fw-bold">Perhatikan alert</div>
                        <div class="small-muted">Cek termin, hutang/piutang, asset reminder, dan aktivitas terbaru secara rutin.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="section-title">Grafik Keuangan Project</h5>
                        <p class="section-subtitle">Pendapatan, pengeluaran, biaya tenaga kerja, dan posisi kas.</p>
                    </div>
                </div>
                <canvas id="financeChart" height="115"></canvas>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="dashboard-card">
                <h5 class="section-title">Status Project</h5>
                <p class="section-subtitle mb-3">Komposisi status seluruh project.</p>
                <canvas id="projectStatusChart" height="230"></canvas>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="section-title">Project Terbaru</h5>
                        <p class="section-subtitle">Project terakhir yang masuk ke sistem.</p>
                    </div>
                    <a href="<?= htmlspecialchars(dashboard_url('pages/projects/index.php')); ?>" class="btn btn-sm btn-outline-primary rounded-pill">Lihat Project</a>
                </div>

                <div class="table-responsive">
                    <table class="table table-dashboard align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Client</th>
                                <th>Nilai</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($latestProjects): ?>
                                <?php foreach ($latestProjects as $project): ?>
                                    <?php $status = (string) ($project['status'] ?? '-'); ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($project['project_name'] ?? '-'); ?></td>
                                        <td><?= htmlspecialchars($project['client_name'] ?? '-'); ?></td>
                                        <td><?= rupiah($project['contract_value'] ?? 0); ?></td>
                                        <td>
                                            <span class="badge rounded-pill <?= dashboard_badge_class($status); ?>">
                                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Belum ada project.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="dashboard-card">
                <h5 class="section-title">Termin Mendekati Jatuh Tempo</h5>
                <p class="section-subtitle mb-3">Termin project unpaid/partial dalam 7 hari.</p>

                <?php if ($upcomingTerms): ?>
                    <?php foreach ($upcomingTerms as $term): ?>
                        <div class="border rounded-4 p-3 mb-3">
                            <div class="fw-bold"><?= htmlspecialchars($term['project_name'] ?? '-'); ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($term['term_name'] ?? 'Termin'); ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="fw-bold"><?= rupiah($term['amount'] ?? 0); ?></span>
                                <span class="badge badge-soft-orange rounded-pill">
                                    <?= !empty($term['due_date']) ? date('d M Y', strtotime($term['due_date'])) : '-'; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        Tidak ada termin yang jatuh tempo dalam 7 hari.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="dashboard-card">
                <h5 class="section-title">Shortcut Cepat</h5>
                <p class="section-subtitle mb-3">Akses menu penting.</p>

                <div class="d-grid gap-2">
                    <a class="shortcut-btn" href="<?= htmlspecialchars(dashboard_url('pages/projects/add.php')); ?>"><span>➕ Tambah Project</span><span>›</span></a>
                    <a class="shortcut-btn" href="<?= htmlspecialchars(dashboard_url('pages/employees/index.php')); ?>"><span>👤 Tambah Karyawan</span><span>›</span></a>
                    <a class="shortcut-btn" href="<?= htmlspecialchars(dashboard_url('pages/assets/add.php')); ?>"><span>🚚 Tambah Asset</span><span>›</span></a>
                    <a class="shortcut-btn" href="<?= htmlspecialchars(dashboard_url('pages/products/add.php')); ?>"><span>📦 Tambah Produk</span><span>›</span></a>
                    <a class="shortcut-btn" href="<?= htmlspecialchars(dashboard_url('pages/products/cashier.php')); ?>">
                        <span>🛒 POS / Kasir</span>
                        <span>›</span>
                    </a>
                    <a class="shortcut-btn" href="<?= htmlspecialchars(dashboard_url('pages/finance/index.php')); ?>">
                        <span>💰 Keuangan</span>
                        <span>›</span>
                    </a>

                    <a class="shortcut-btn" href="<?= htmlspecialchars(dashboard_url('#')); ?>">
                        <span>📊 Laporan</span>
                        <span>›</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="dashboard-card">
                <h5 class="section-title">Aktivitas Terbaru</h5>
                <p class="section-subtitle mb-3">Riwayat terakhir dari sistem.</p>

                <?php if ($latestActivities): ?>
                    <?php foreach ($latestActivities as $activity): ?>
                        <?php
                        $activityText = $activity['activity'] ?? $activity['action'] ?? $activity['description'] ?? 'Aktivitas sistem';
                        $activityUser = $activity['user_name'] ?? $activity['username'] ?? $activity['created_by'] ?? 'System';
                        $activityDate = $activity['created_at'] ?? $activity['log_time'] ?? $activity['date'] ?? null;
                        ?>
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                            <div class="metric-icon" style="width:38px;height:38px;font-size:18px;margin:0;">📝</div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($activityText); ?></div>
                                <div class="small-muted">
                                    <?= htmlspecialchars($activityUser); ?>
                                    <?= $activityDate ? ' • ' . htmlspecialchars(date('d M Y H:i', strtotime($activityDate))) : ''; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">Belum ada aktivitas terbaru.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</main>

<script src="<?= base_url('assets/vendor/chartjs/chart.umd.js'); ?>"></script>
<script>
    const formatRupiah = (value) => 'Rp ' + new Intl.NumberFormat('id-ID').format(value || 0);

    const financeChart = document.getElementById('financeChart');
    if (financeChart) {
        new Chart(financeChart, {
            type: 'bar',
            data: {
                labels: ['Pendapatan', 'Pengeluaran', 'Tenaga Kerja', 'Total Biaya', 'Cash Position'],
                datasets: [{
                    label: 'Nominal',
                    data: [
                        <?= (float) $totalProjectIncome; ?>,
                        <?= (float) $totalProjectExpense; ?>,
                        <?= (float) $totalWorkerCost; ?>,
                        <?= (float) $totalCost; ?>,
                        <?= (float) $netCash; ?>
                    ],
                    borderWidth: 1,
                    borderRadius: 10
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return formatRupiah(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return formatRupiah(value);
                            }
                        }
                    }
                }
            }
        });
    }

    const projectStatusChart = document.getElementById('projectStatusChart');
    if (projectStatusChart) {
        new Chart(projectStatusChart, {
            type: 'doughnut',
            data: {
                labels: ['Planning', 'Ongoing', 'Completed', 'Cancelled'],
                datasets: [{
                    data: [
                        <?= (int) $planningProjects; ?>,
                        <?= (int) $ongoingProjects; ?>,
                        <?= (int) $completedProjects; ?>,
                        <?= (int) $cancelledProjects; ?>
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
