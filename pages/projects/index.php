<?php
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Project Management - Nukari';
$error = '';
$success = '';

function nkr_to_number($value)
{
    if ($value === null || $value === '') return 0;
    return (float) preg_replace('/[^0-9]/', '', (string) $value);
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function nkr_status_badge($status)
{
    $map = [
        'planning' => ['Planning', 'bg-primary-subtle text-primary'],
        'ongoing' => ['Ongoing', 'bg-success-subtle text-success'],
        'completed' => ['Completed', 'bg-purple-subtle text-purple'],
        'cancelled' => ['Cancelled', 'bg-danger-subtle text-danger'],
    ];
    $item = $map[$status] ?? [$status, 'bg-secondary-subtle text-secondary'];
    return '<span class="badge rounded-pill ' . $item[1] . '">' . htmlspecialchars($item[0]) . '</span>';
}

function nkr_type_badge($type)
{
    return $type === 'ongoing'
        ? '<span class="badge rounded-pill bg-warning-subtle text-warning">Contract Project</span>'
        : '<span class="badge rounded-pill bg-info-subtle text-info">One Time Project</span>';
}

function nkr_billing_label($cycle)
{
    $map = [
        'none' => 'Tidak Berulang',
        'monthly' => 'Bulanan',
        'quarterly' => 'Triwulan',
        'semi_annual' => 'Semester',
        'annual' => 'Tahunan',
        'custom' => 'Custom',
    ];

    return $map[$cycle] ?? '-';
}

$clients = $pdo->query("SELECT id, client_name FROM clients ORDER BY client_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$employees = $pdo->query("SELECT id, full_name FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $project_name = clean_input($_POST['project_name'] ?? '');
    $project_code = clean_input($_POST['project_code'] ?? '');
    $client_id = !empty($_POST['client_id']) ? (int) $_POST['client_id'] : null;
    $leader_id = !empty($_POST['leader_id']) ? (int) $_POST['leader_id'] : null;
    $project_type = in_array(($_POST['project_type'] ?? 'one_time'), ['one_time', 'ongoing'], true) ? $_POST['project_type'] : 'one_time';
    $project_location = clean_input($_POST['project_location'] ?? '');
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $contract_value = nkr_to_number($_POST['contract_value'] ?? 0);
    $contract_number = clean_input($_POST['contract_number'] ?? '');
    $contract_start_date = !empty($_POST['contract_start_date']) ? $_POST['contract_start_date'] : null;
    $contract_end_date = !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null;
    $billing_cycle = in_array(($_POST['billing_cycle'] ?? 'none'), ['none', 'monthly', 'quarterly', 'semi_annual', 'annual', 'custom'], true) ? $_POST['billing_cycle'] : 'none';
    $billing_amount = nkr_to_number($_POST['billing_amount'] ?? 0);
    $billing_notes = clean_input($_POST['billing_notes'] ?? '');
    $status = in_array(($_POST['status'] ?? 'planning'), ['planning', 'ongoing', 'completed', 'cancelled'], true) ? $_POST['status'] : 'planning';
    $notes = clean_input($_POST['notes'] ?? '');

    if ($project_name === '') {
        $error = 'Nama project wajib diisi.';
    } elseif ($start_date && $end_date && strtotime($end_date) < strtotime($start_date)) {
        $error = 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.';
    } elseif ($contract_start_date && $contract_end_date && strtotime($contract_end_date) < strtotime($contract_start_date)) {
        $error = 'Tanggal akhir kontrak tidak boleh lebih awal dari tanggal mulai kontrak.';
    } else {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO projects (project_code, project_name, client_id, leader_id, project_location, contract_value, start_date, end_date, status, project_type, contract_number, contract_start_date, contract_end_date, billing_cycle, billing_amount, billing_notes, notes, created_by) VALUES (:project_code, :project_name, :client_id, :leader_id, :project_location, :contract_value, :start_date, :end_date, :status, :project_type, :contract_number, :contract_start_date, :contract_end_date, :billing_cycle, :billing_amount, :billing_notes, :notes, :created_by)");
            $stmt->execute([
                ':project_code' => $project_code ?: null,
                ':project_name' => $project_name,
                ':client_id' => $client_id,
                ':leader_id' => $leader_id,
                ':project_location' => $project_location ?: null,
                ':contract_value' => $contract_value,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':status' => $status,
                ':project_type' => $project_type,
                ':contract_number' => $contract_number ?: null,
                ':contract_start_date' => $contract_start_date,
                ':contract_end_date' => $contract_end_date,
                ':billing_cycle' => $billing_cycle,
                ':billing_amount' => $billing_amount,
                ':billing_notes' => $billing_notes ?: null,
                ':notes' => $notes ?: null,
                ':created_by' => $_SESSION['user_id'] ?? null,
            ]);
            redirect('pages/projects/index.php');
        }

        if ($action === 'update' && $id > 0) {
            $stmt = $pdo->prepare("UPDATE projects SET project_code = :project_code, project_name = :project_name, client_id = :client_id, leader_id = :leader_id, project_location = :project_location, contract_value = :contract_value, start_date = :start_date, end_date = :end_date, status = :status, project_type = :project_type, contract_number = :contract_number, contract_start_date = :contract_start_date, contract_end_date = :contract_end_date, billing_cycle = :billing_cycle, billing_amount = :billing_amount, billing_notes = :billing_notes, notes = :notes, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([
                ':project_code' => $project_code ?: null,
                ':project_name' => $project_name,
                ':client_id' => $client_id,
                ':leader_id' => $leader_id,
                ':project_location' => $project_location ?: null,
                ':contract_value' => $contract_value,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':status' => $status,
                ':project_type' => $project_type,
                ':contract_number' => $contract_number ?: null,
                ':contract_start_date' => $contract_start_date,
                ':contract_end_date' => $contract_end_date,
                ':billing_cycle' => $billing_cycle,
                ':billing_amount' => $billing_amount,
                ':billing_notes' => $billing_notes ?: null,
                ':notes' => $notes ?: null,
                ':id' => $id,
            ]);
            redirect('pages/projects/index.php');
        }
    }
}

$stmt = $pdo->query("
    SELECT 
        p.*,
        c.client_name,
        e.full_name AS leader_name,
        COALESCE(term.total_terms, 0) AS total_terms,
        COALESCE(term.paid_terms, 0) AS paid_terms,
        COALESCE(term.total_term_amount, 0) AS total_term_amount,
        COALESCE(term.paid_term_amount, 0) AS paid_term_amount,
        COALESCE(fund.total_fund_amount, 0) AS total_fund_amount,
        COALESCE(tx.total_expense, 0) AS total_expense,
        COALESCE(tx.manual_income, 0) AS manual_income,
        COALESCE(w.total_workers, 0) AS total_workers
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN employees e ON p.leader_id = e.id
    LEFT JOIN (
        SELECT project_id,
               COUNT(*) AS total_terms,
               SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_terms,
               SUM(amount) AS total_term_amount,
               SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS paid_term_amount
        FROM project_payment_terms
        GROUP BY project_id
    ) term ON term.project_id = p.id
    LEFT JOIN (
        SELECT project_id,
               SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) AS total_fund_amount
        FROM project_transactions
        GROUP BY project_id
    ) fund ON fund.project_id = p.id
    LEFT JOIN (
        SELECT project_id,
               SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) AS total_expense,
               SUM(CASE WHEN transaction_type = 'income' AND source_module = 'manual' THEN amount ELSE 0 END) AS manual_income
        FROM project_transactions
        GROUP BY project_id
    ) tx ON tx.project_id = p.id
    LEFT JOIN (
        SELECT project_id, COUNT(*) AS total_workers
        FROM project_workers
        GROUP BY project_id
    ) w ON w.project_id = p.id
    WHERE p.deleted_at IS NULL
    ORDER BY p.id DESC
");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalProject = count($projects);
$totalOneTimeProject = 0;
$totalContractProject = 0;
$totalPlanning = $totalOngoing = $totalCompleted = $totalCancelled = 0;
$totalContractValue = $totalExpense = $totalIncome = $totalMargin = 0;
$totalReceivable = 0;
$totalFundAmount = 0;
$totalCashPosition = 0;
$totalRealProfit = 0;
$totalNegativeCashProjects = 0;
$totalNegativeCashAmount = 0;
$negativeCashProjects = [];
$chartProjectNames = [];
$chartContract = [];
$chartRealIncome = [];
$chartExpense = [];
$chartCashPosition = [];

foreach ($projects as $p) {
    if (($p['project_type'] ?? 'one_time') === 'ongoing') {
        $totalContractProject++;
    } else {
        $totalOneTimeProject++;
    }

    if ($p['status'] === 'planning') $totalPlanning++;
    if ($p['status'] === 'ongoing') $totalOngoing++;
    if ($p['status'] === 'completed') $totalCompleted++;
    if ($p['status'] === 'cancelled') $totalCancelled++;

    $contract = (float) $p['contract_value'];
    $realIncome = (float) $p['paid_term_amount'] + (float) ($p['manual_income'] ?? 0);
    $fundAmount = (float) ($p['total_fund_amount'] ?? 0);
    $expense = (float) $p['total_expense'];
    $receivable = max($contract - $realIncome, 0);
    $cashPosition = $realIncome - $expense;
    $realProfit = $realIncome - $expense;
    $margin = $contract - $expense;

    if ($cashPosition < 0) {
        $totalNegativeCashProjects++;
        $totalNegativeCashAmount += abs($cashPosition);
        $negativeCashProjects[] = [
            'id' => (int) $p['id'],
            'project_name' => $p['project_name'],
            'cash_position' => $cashPosition,
        ];
    }

    $totalContractValue += $contract;
    $totalExpense += $expense;
    $totalIncome += $realIncome;
    $totalReceivable += $receivable;
    $totalFundAmount += $fundAmount;
    $totalCashPosition += $cashPosition;
    $totalRealProfit += $realProfit;
    $totalMargin += $margin;

    $chartProjectNames[] = $p['project_name'];
    $chartContract[] = $contract;
    $chartRealIncome[] = $realIncome;
    $chartExpense[] = $expense;
    $chartCashPosition[] = $cashPosition;
}


// Executive risk & operational widgets
$overdueStmt = $pdo->query(""
    . "SELECT t.id, t.project_id, t.term_name, t.amount, t.due_date, p.project_name "
    . "FROM project_payment_terms t "
    . "JOIN projects p ON p.id = t.project_id AND p.deleted_at IS NULL "
    . "WHERE t.status <> 'paid' AND t.due_date < CURDATE() "
    . "ORDER BY t.due_date ASC"
);
$overdueTerms = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);
$totalOverdueTerms = count($overdueTerms);
$totalOverdueAmount = array_sum(array_map(fn($item) => (float) $item['amount'], $overdueTerms));

$dueSoonStmt = $pdo->query(""
    . "SELECT t.id, t.project_id, t.term_name, t.amount, t.due_date, p.project_name "
    . "FROM project_payment_terms t "
    . "JOIN projects p ON p.id = t.project_id AND p.deleted_at IS NULL "
    . "WHERE t.status <> 'paid' "
    . "AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) "
    . "ORDER BY t.due_date ASC"
);
$dueSoonTerms = $dueSoonStmt->fetchAll(PDO::FETCH_ASSOC);
$totalDueSoonTerms = count($dueSoonTerms);
$totalDueSoonAmount = array_sum(array_map(fn($item) => (float) $item['amount'], $dueSoonTerms));

$activeWorkerStmt = $pdo->query(""
    . "SELECT COUNT(*) AS total_active_workers "
    . "FROM project_workers w "
    . "JOIN projects p ON p.id = w.project_id AND p.deleted_at IS NULL "
    . "WHERE COALESCE(w.payment_status, 'unpaid') <> 'cancelled' "
    . "AND (w.start_date IS NULL OR w.start_date <= CURDATE()) "
    . "AND (w.end_date IS NULL OR w.end_date >= CURDATE())"
);
$totalActiveWorkers = (int) ($activeWorkerStmt->fetch(PDO::FETCH_ASSOC)['total_active_workers'] ?? 0);

$activeWorkerListStmt = $pdo->query(""
    . "SELECT w.worker_name, w.worker_type, p.project_name, w.start_date, w.end_date "
    . "FROM project_workers w "
    . "JOIN projects p ON p.id = w.project_id AND p.deleted_at IS NULL "
    . "WHERE COALESCE(w.payment_status, 'unpaid') <> 'cancelled' "
    . "AND (w.start_date IS NULL OR w.start_date <= CURDATE()) "
    . "AND (w.end_date IS NULL OR w.end_date >= CURDATE()) "
    . "ORDER BY p.project_name ASC, w.worker_name ASC LIMIT 8"
);
$activeWorkerList = $activeWorkerListStmt->fetchAll(PDO::FETCH_ASSOC);

$calendarMonthParam = $_GET['term_month'] ?? date('Y-m');
if (!preg_match('/^\\d{4}-\\d{2}$/', $calendarMonthParam)) {
    $calendarMonthParam = date('Y-m');
}
$calendarStart = date('Y-m-01', strtotime($calendarMonthParam . '-01'));
$calendarEnd = date('Y-m-t', strtotime($calendarStart));
$prevCalendarMonth = date('Y-m', strtotime($calendarStart . ' -1 month'));
$nextCalendarMonth = date('Y-m', strtotime($calendarStart . ' +1 month'));
$calendarTitle = date('M / Y', strtotime($calendarStart));
$calendarDaysInMonth = (int) date('t', strtotime($calendarStart));
$calendarFirstWeekday = (int) date('N', strtotime($calendarStart)); // 1 Monday - 7 Sunday

$calendarStmt = $pdo->prepare(""
    . "SELECT t.id, t.project_id, t.term_name, t.amount, t.due_date, t.status, p.project_name "
    . "FROM project_payment_terms t "
    . "JOIN projects p ON p.id = t.project_id AND p.deleted_at IS NULL "
    . "WHERE t.due_date BETWEEN :start_date AND :end_date "
    . "ORDER BY t.due_date ASC, p.project_name ASC"
);
$calendarStmt->execute([
    ':start_date' => $calendarStart,
    ':end_date' => $calendarEnd,
]);
$calendarTerms = $calendarStmt->fetchAll(PDO::FETCH_ASSOC);
$calendarTermsByDay = [];
foreach ($calendarTerms as $term) {
    $day = (int) date('j', strtotime($term['due_date']));
    $calendarTermsByDay[$day][] = $term;
}

$priorityTerms = array_slice(array_merge($overdueTerms, $dueSoonTerms), 0, 8);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
    :root {
        --nkr-green: #0f6b4b;
        --nkr-green-dark: #0b4f3a;
        --nkr-soft: #f5f7fb;
        --nkr-line: rgba(15, 23, 42, .08);
        --nkr-text: #111827;
        --nkr-muted: #667085;
    }

    .ops-hero {
        background: radial-gradient(circle at 92% 0%, rgba(255,255,255,.18) 0 120px, transparent 121px),
                    linear-gradient(135deg, #0f4c3a 0%, #12815c 58%, #e5b84b 100%);
        border-radius: 28px;
        padding: 28px;
        color: #fff;
        box-shadow: 0 18px 45px rgba(15,76,58,.22);
        position: relative;
        overflow: hidden;
    }
    .ops-hero .hero-chip {
        display:inline-flex; align-items:center; gap:8px;
        background: rgba(255,255,255,.95); color: var(--nkr-green);
        border-radius:999px; padding:8px 14px; font-weight:800; font-size:12px;
    }
    .ops-hero h1 { font-size: clamp(28px, 3vw, 40px); letter-spacing: -.03em; }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }
    @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .kpi-grid { grid-template-columns: 1fr; } }

    .kpi-card {
        background:#fff;
        border:1px solid var(--nkr-line);
        border-radius:18px;
        padding:16px 18px;
        box-shadow: 0 10px 28px rgba(17, 24, 39, .06);
        transition: transform .18s ease, box-shadow .18s ease;
        min-height: 108px;
    }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 14px 36px rgba(17, 24, 39, .10); }
    .kpi-top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .kpi-icon {
        width:42px; height:42px; border-radius:14px; display:flex; align-items:center; justify-content:center;
        font-size:19px; flex:0 0 auto;
    }
    .kpi-icon.green { background:#e7f6ef; color:#0f6b4b; }
    .kpi-icon.blue { background:#eaf3ff; color:#2563eb; }
    .kpi-icon.orange { background:#fff2df; color:#d97706; }
    .kpi-icon.red { background:#ffecec; color:#dc2626; }
    .kpi-icon.purple { background:#f2e8ff; color:#7c3aed; }
    .kpi-label { color:var(--nkr-muted); font-size:12px; font-weight:800; letter-spacing:.02em; }
    .kpi-number { color:var(--nkr-text); font-size:20px; line-height:1.2; font-weight:900; margin-top:8px; }
    .kpi-note { color:#8a94a6; font-size:12px; margin-top:7px; }
    .kpi-pill { font-size:11px; font-weight:800; border-radius:999px; padding:5px 8px; white-space:nowrap; }
    .pill-live { background:#dcfce7; color:#15803d; }
    .pill-watch { background:#fff7ed; color:#c2410c; }
    .pill-risk { background:#fee2e2; color:#b91c1c; }
    .pill-calm { background:#e0f2fe; color:#0369a1; }

    .ops-card {
        background:#fff;
        border:1px solid var(--nkr-line);
        border-radius:24px;
        box-shadow:0 16px 38px rgba(18,38,63,.07);
        padding:22px;
        height:100%;
    }
    .panel-title { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; }
    .panel-title h5 { font-weight:900; letter-spacing:-.02em; margin:0; }
    .panel-title small { color:var(--nkr-muted); }
    .guide-step { display:flex; gap:12px; padding:13px; border-radius:18px; background:#f8fafc; margin-bottom:10px; border:1px solid rgba(15,23,42,.04); }
    .guide-step i { width:34px; height:34px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; background:#e8f5ee; color:#0f6b4b; flex:0 0 auto; }

    .project-title { font-weight:900; color:#111827; letter-spacing:-.01em; }
    .muted-small { color:#6b7280; font-size:12px; }
    .table { --bs-table-hover-bg: #f8fafc; }
    .table thead th { background:#f3f4f6; color:#374151; font-size:12px; text-transform:uppercase; letter-spacing:.03em; border-bottom:0; }
    .table td { border-color: rgba(15,23,42,.07); }
    .btn-nukari { background:#0f6b4b; color:#fff; border:0; } .btn-nukari:hover { background:#0b563d; color:#fff; }
    .bg-purple-subtle { background:#f3e8ff; } .text-purple { color:#7e22ce; }
    .search-box { border-radius:16px; padding:12px 14px; border-color:rgba(15,23,42,.12); }
    .modal-content { border:0; border-radius:24px; }
    .form-control, .form-select { border-radius:14px; padding:11px 13px; }
    .money-line { display:grid; gap:2px; }
    .money-line small { color:#64748b; }
    .cash-positive { color:#15803d; }
    .cash-negative { color:#dc2626; }

    .risk-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:22px; }
    @media (max-width: 1200px) { .risk-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
    @media (max-width: 640px) { .risk-grid { grid-template-columns:1fr; } }
    .risk-card { background:#fff; border:1px solid var(--nkr-line); border-radius:18px; padding:16px 18px; box-shadow:0 10px 28px rgba(17,24,39,.06); }
    .risk-card .risk-title { color:var(--nkr-muted); font-size:12px; font-weight:900; letter-spacing:.02em; text-transform:uppercase; }
    .risk-card .risk-number { font-size:24px; font-weight:950; color:#111827; margin-top:5px; }
    .risk-card .risk-sub { color:#8a94a6; font-size:12px; margin-top:4px; }
    .risk-card.danger { border-left:5px solid #ef4444; } .risk-card.warning { border-left:5px solid #f59e0b; } .risk-card.green { border-left:5px solid #16a34a; } .risk-card.blue { border-left:5px solid #2563eb; }
    .term-calendar-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .term-calendar-head a { width:34px; height:34px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; color:#334155; background:#f8fafc; text-decoration:none; border:1px solid rgba(15,23,42,.08); }
    .term-calendar-title { font-size:19px; font-weight:950; color:#111827; letter-spacing:.01em; }
    .term-calendar-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; }
    .term-weekday { text-align:center; color:#64748b; font-size:12px; font-weight:800; padding-bottom:5px; }
    .term-day { position:relative; min-height:46px; border-radius:14px; background:#fbfcfe; border:1px solid rgba(15,23,42,.05); display:flex; align-items:center; justify-content:center; font-weight:800; color:#475569; }
    .term-day.empty { background:transparent; border-color:transparent; }
    .term-day.today { outline:2px solid #2563eb; color:#111827; }
    .term-day.has-term { background:#fff; box-shadow:0 8px 20px rgba(15,23,42,.06); }
    .term-day.overdue { background:#fff1f2; color:#be123c; }
    .term-day.upcoming { background:#fff7ed; color:#c2410c; }
    .term-day.paid { background:#ecfdf5; color:#047857; }
    .term-dot { position:absolute; bottom:6px; width:6px; height:6px; border-radius:999px; background:#94a3b8; }
    .term-day.overdue .term-dot { background:#ef4444; } .term-day.upcoming .term-dot { background:#f59e0b; } .term-day.paid .term-dot { background:#16a34a; }
    .term-priority-list { display:grid; gap:10px; }
    .term-priority-item { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; padding:12px 14px; border:1px solid rgba(15,23,42,.07); border-radius:16px; background:#fbfcfe; }
    .term-priority-item .name { font-weight:900; color:#111827; } .term-priority-item .meta { color:#64748b; font-size:12px; margin-top:3px; }
    .legend-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; }

</style>

<main class="main-content">

    <div class="ops-hero mb-4">
        <div class="row align-items-center g-3 position-relative" style="z-index:1;">
            <div class="col-lg-8">
                <div class="hero-chip mb-3"><i class="bi bi-kanban"></i> Operations / Project</div>
                <h1 class="fw-bold mb-2">Project Management</h1>
                <p class="mb-0 opacity-75">Pantau nilai kontrak, piutang client, pendapatan riil, pengeluaran, pendanaan, saldo project, dan progress pekerjaan dalam satu dashboard.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <button class="btn btn-light rounded-pill px-4 py-2 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#createProjectModal">
                    <i class="bi bi-plus-circle me-1"></i> Tambah Project
                </button>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Nilai Kontrak</div><div class="kpi-number"><?= rupiah($totalContractValue); ?></div></div>
                <div class="kpi-icon green"><i class="bi bi-file-earmark-text"></i></div>
            </div>
            <div class="kpi-note">Akumulasi semua nilai kontrak project.</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Piutang Client</div><div class="kpi-number"><?= rupiah($totalReceivable); ?></div></div>
                <div class="kpi-icon orange"><i class="bi bi-receipt"></i></div>
            </div>
            <div class="kpi-note">Nilai kontrak yang belum menjadi pendapatan riil.</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Pendapatan Riil</div><div class="kpi-number"><?= rupiah($totalIncome); ?></div></div>
                <div class="kpi-icon blue"><i class="bi bi-bank"></i></div>
            </div>
            <div class="kpi-note">Termin paid + income manual.</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Saldo Project</div><div class="kpi-number <?= $totalCashPosition >= 0 ? 'cash-positive' : 'cash-negative'; ?>"><?= rupiah($totalCashPosition); ?></div></div>
                <div class="kpi-icon <?= $totalCashPosition >= 0 ? 'green' : 'red'; ?>"><i class="bi bi-wallet2"></i></div>
            </div>
            <div class="kpi-note">Pendapatan riil dikurangi pengeluaran riil.</div>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Pengeluaran Riil</div><div class="kpi-number"><?= rupiah($totalExpense); ?></div></div>
                <div class="kpi-icon red"><i class="bi bi-cash-coin"></i></div>
            </div>
            <div class="kpi-note">Transaksi expense + biaya pekerja.</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Pendanaan</div><div class="kpi-number"><?= rupiah($totalFundAmount); ?></div></div>
                <div class="kpi-icon purple"><i class="bi bi-safe2"></i></div>
            </div>
            <div class="kpi-note">Dana terpakai otomatis dari transaksi project.</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Total Project</div><div class="kpi-number"><?= (int)$totalProject; ?></div></div>
                <div class="kpi-icon blue"><i class="bi bi-folder2-open"></i></div>
            </div>
            <div class="kpi-note"><?= (int)$totalOneTimeProject; ?> one time · <?= (int)$totalContractProject; ?> kontrak</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-top">
                <div><div class="kpi-label">Estimasi Margin</div><div class="kpi-number <?= $totalMargin >= 0 ? 'cash-positive' : 'cash-negative'; ?>"><?= rupiah($totalMargin); ?></div></div>
                <div class="kpi-icon <?= $totalMargin >= 0 ? 'green' : 'red'; ?>"><i class="bi bi-graph-up-arrow"></i></div>
            </div>
            <div class="kpi-note">Nilai kontrak - pengeluaran riil.</div>
        </div>
    </div>

    <div class="risk-grid">
        <div class="risk-card danger">
            <div class="risk-title">Termin Overdue</div>
            <div class="risk-number"><?= (int) $totalOverdueTerms; ?></div>
            <div class="risk-sub"><?= rupiah($totalOverdueAmount); ?> perlu ditagih</div>
        </div>
        <div class="risk-card warning">
            <div class="risk-title">Jatuh Tempo 7 Hari</div>
            <div class="risk-number"><?= (int) $totalDueSoonTerms; ?></div>
            <div class="risk-sub"><?= rupiah($totalDueSoonAmount); ?> mendekati jatuh tempo</div>
        </div>
        <div class="risk-card danger">
            <div class="risk-title">Saldo Project Negatif</div>
            <div class="risk-number"><?= (int) $totalNegativeCashProjects; ?></div>
            <div class="risk-sub"><?= rupiah($totalNegativeCashAmount); ?> sedang ditalangi</div>
        </div>
        <div class="risk-card blue">
            <div class="risk-title">Pekerja Aktif</div>
            <div class="risk-number"><?= (int) $totalActiveWorkers; ?></div>
            <div class="risk-sub">Berdasarkan rentang tanggal project</div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="ops-card">
                <div class="panel-title">
                    <div>
                        <h5>Cash Flow Project</h5>
                        <small>Perbandingan nilai kontrak, pendapatan riil, pengeluaran riil, dan saldo project.</small>
                    </div>
                    <span class="kpi-pill pill-live"><i class="bi bi-database-check me-1"></i>Live database</span>
                </div>
                <canvas id="projectFinanceChart" height="120"></canvas>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="ops-card">
                <div class="panel-title">
                    <div>
                        <h5>Status Project</h5>
                        <small>Komposisi status pekerjaan.</small>
                    </div>
                    <span class="kpi-pill pill-calm"><?= (int)$totalOngoing; ?> ongoing</span>
                </div>
                <canvas id="projectStatusChart" height="210" class="mt-2"></canvas>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-5">
            <div class="ops-card">
                <div class="term-calendar-head">
                    <a href="<?= base_url('pages/projects/index.php?term_month=' . $prevCalendarMonth); ?>" title="Bulan sebelumnya"><i class="bi bi-chevron-left"></i></a>
                    <div class="term-calendar-title"><?= e($calendarTitle); ?></div>
                    <a href="<?= base_url('pages/projects/index.php?term_month=' . $nextCalendarMonth); ?>" title="Bulan berikutnya"><i class="bi bi-chevron-right"></i></a>
                </div>
                <div class="term-calendar-grid mb-2">
                    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dayName): ?>
                        <div class="term-weekday"><?= $dayName; ?></div>
                    <?php endforeach; ?>
                    <?php for ($blank = 1; $blank < $calendarFirstWeekday; $blank++): ?>
                        <div class="term-day empty"></div>
                    <?php endfor; ?>
                    <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++): ?>
                        <?php
                            $dateString = date('Y-m-', strtotime($calendarStart)) . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                            $items = $calendarTermsByDay[$day] ?? [];
                            $dayClass = '';
                            if ($items) {
                                $dayClass = 'has-term';
                                foreach ($items as $item) {
                                    if ($item['status'] === 'paid') { $dayClass = 'has-term paid'; break; }
                                    if (strtotime($item['due_date']) < strtotime(date('Y-m-d'))) { $dayClass = 'has-term overdue'; break; }
                                    $dayClass = 'has-term upcoming';
                                }
                            }
                            if ($dateString === date('Y-m-d')) { $dayClass .= ' today'; }
                            $tooltip = $items ? implode(' | ', array_map(fn($x) => $x['project_name'] . ' - ' . $x['term_name'] . ' (' . rupiah($x['amount']) . ')', $items)) : '';
                        ?>
                        <div class="term-day <?= e(trim($dayClass)); ?>" title="<?= e($tooltip); ?>">
                            <?= $day; ?>
                            <?php if ($items): ?><span class="term-dot"></span><?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="d-flex flex-wrap gap-3 mt-3 muted-small">
                    <span><i class="legend-dot" style="background:#ef4444"></i>Overdue</span>
                    <span><i class="legend-dot" style="background:#f59e0b"></i>Akan jatuh tempo</span>
                    <span><i class="legend-dot" style="background:#16a34a"></i>Sudah dibayar</span>
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="ops-card">
                <div class="panel-title">
                    <div>
                        <h5>Prioritas Termin & Operasional</h5>
                        <small>Termin overdue, jatuh tempo 7 hari, saldo negatif, dan pekerja aktif.</small>
                    </div>
                    <span class="kpi-pill <?= $totalOverdueTerms > 0 ? 'pill-risk' : 'pill-live'; ?>"><?= $totalOverdueTerms > 0 ? (int)$totalOverdueTerms . ' overdue' : 'Terkendali'; ?></span>
                </div>
                <div class="term-priority-list">
                    <?php if ($priorityTerms): ?>
                        <?php foreach ($priorityTerms as $term): ?>
                            <?php $isOverdue = strtotime($term['due_date']) < strtotime(date('Y-m-d')); ?>
                            <div class="term-priority-item">
                                <div>
                                    <div class="name"><?= e($term['term_name']); ?></div>
                                    <div class="meta"><?= e($term['project_name']); ?> · Jatuh tempo <?= date('d M Y', strtotime($term['due_date'])); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold <?= $isOverdue ? 'text-danger' : 'text-warning'; ?>"><?= rupiah($term['amount']); ?></div>
                                    <small class="badge rounded-pill <?= $isOverdue ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning'; ?>"><?= $isOverdue ? 'Overdue' : '7 hari'; ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">Tidak ada termin overdue atau jatuh tempo 7 hari.</div>
                    <?php endif; ?>
                </div>
                <?php if ($negativeCashProjects || $activeWorkerList): ?>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <div class="small fw-bold mb-2">Saldo Project Negatif</div>
                            <?php if ($negativeCashProjects): ?>
                                <?php foreach (array_slice($negativeCashProjects, 0, 4) as $project): ?>
                                    <div class="muted-small mb-1"><i class="bi bi-exclamation-triangle text-danger me-1"></i><?= e($project['project_name']); ?> · <b class="text-danger"><?= rupiah($project['cash_position']); ?></b></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="muted-small">Tidak ada project dengan saldo negatif.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="small fw-bold mb-2">Pekerja Aktif Hari Ini</div>
                            <?php if ($activeWorkerList): ?>
                                <?php foreach (array_slice($activeWorkerList, 0, 4) as $worker): ?>
                                    <div class="muted-small mb-1"><i class="bi bi-person-check text-success me-1"></i><?= e($worker['worker_name']); ?> · <?= e($worker['project_name']); ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="muted-small">Tidak ada pekerja aktif berdasarkan tanggal hari ini.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-4">
            <div class="ops-card">
                <h5 class="fw-bold mb-3"><i class="bi bi-info-circle me-1"></i> Panduan Halaman</h5>
                <div class="guide-step"><i class="bi bi-1-circle"></i><div><b>Buat Project</b><br><small class="text-muted">Isi client, leader, tipe project, nilai kontrak, dan periode.</small></div></div>
                <div class="guide-step"><i class="bi bi-2-circle"></i><div><b>Lengkapi Detail</b><br><small class="text-muted">Masuk ke Detail untuk termin, pendanaan, transaksi, pekerja, aset, dan dokumen.</small></div></div>
                <div class="guide-step"><i class="bi bi-3-circle"></i><div><b>Pantau Uang Riil</b><br><small class="text-muted">Fokus pada pendapatan riil, pengeluaran riil, saldo project, dan piutang client.</small></div></div>
                <div class="guide-step mb-0"><i class="bi bi-people"></i><div><b>Pekerja</b><br><small class="text-muted">Internal, freelance, dan borongan dicatat di halaman Detail Project.</small></div></div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="ops-card">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Daftar Project</h5>
                        <small class="text-muted">Gunakan pencarian untuk menemukan project, client, atau leader.</small>
                    </div>
                    <input type="text" id="projectSearch" class="form-control search-box" style="max-width:280px;" placeholder="Cari project, client, leader...">
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="projectTable">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Client / Leader</th>
                                <th>Timeline</th>
                                <th>Cash & Margin</th>
                                <th>Termin</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($projects): ?>
                                <?php foreach ($projects as $p): ?>
                                    <?php
                                    $termProgress = ((int) $p['total_terms'] > 0) ? (((int) $p['paid_terms'] / (int) $p['total_terms']) * 100) : 0;
                                    $rowRealIncome = (float) $p['paid_term_amount'] + (float) ($p['manual_income'] ?? 0);
                                    $rowFundAmount = (float) ($p['total_fund_amount'] ?? 0);
                                    $rowExpense = (float) $p['total_expense'];
                                    $rowCashPosition = ($rowRealIncome + $rowFundAmount) - $rowExpense;
                                    $rowMargin = (float) $p['contract_value'] - $rowExpense;
                                    $rowReceivable = max((float)$p['contract_value'] - $rowRealIncome, 0);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="project-title"><?= htmlspecialchars($p['project_name']); ?></div>
                                            <div class="muted-small"><?= htmlspecialchars($p['project_code'] ?: 'Tanpa kode'); ?></div>
                                            <?= nkr_type_badge($p['project_type'] ?? 'one_time'); ?>
                                            <?php if (($p['project_type'] ?? 'one_time') === 'ongoing'): ?>
                                                <div class="muted-small mt-1">
                                                    <i class="bi bi-repeat me-1"></i><?= e(nkr_billing_label($p['billing_cycle'] ?? 'none')); ?>
                                                    <?php if (!empty($p['contract_number'])): ?> · <?= e($p['contract_number']); ?><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($p['client_name'] ?? '-'); ?></div>
                                            <small class="text-muted"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($p['leader_name'] ?? '-'); ?></small>
                                            <?php if (!empty($p['project_location'])): ?><br><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($p['project_location']); ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">Mulai</small><br><?= !empty($p['start_date']) ? date('d M Y', strtotime($p['start_date'])) : '-'; ?><br>
                                            <small class="text-muted">Selesai</small><br><?= !empty($p['end_date']) ? date('d M Y', strtotime($p['end_date'])) : '-'; ?>
                                        </td>
                                        <td>
                                            <div class="money-line">
                                                <small>Kontrak <b class="text-dark"><?= rupiah($p['contract_value']); ?></b></small>
                                                <small>Pendapatan <b class="text-success"><?= rupiah($rowRealIncome); ?></b></small>
                                                <small>Piutang <b class="text-warning"><?= rupiah($rowReceivable); ?></b></small>
                                                <small>Pengeluaran <b><?= rupiah($rowExpense); ?></b></small>
                                                <small>Saldo <b class="<?= $rowCashPosition >= 0 ? 'cash-positive' : 'cash-negative'; ?>"><?= rupiah($rowCashPosition); ?></b></small>
                                                <small>Margin <b class="<?= $rowMargin >= 0 ? 'cash-positive' : 'cash-negative'; ?>"><?= rupiah($rowMargin); ?></b></small>
                                            </div>
                                        </td>
                                        <td style="min-width:160px;">
                                            <b><?= (int) $p['paid_terms']; ?> / <?= (int) $p['total_terms']; ?></b> <small class="text-muted">terbayar</small>
                                            <div class="progress my-1" style="height:8px;"><div class="progress-bar bg-success" style="width: <?= $termProgress; ?>%"></div></div>
                                            <small class="text-muted"><?= rupiah($p['paid_term_amount']); ?> / <?= rupiah($p['total_term_amount']); ?></small>
                                        </td>
                                        <td><?= nkr_status_badge($p['status']); ?></td>
                                        <td class="text-end" style="min-width:190px;">
                                            <a href="<?= base_url('pages/projects/detail.php?id=' . $p['id']); ?>" class="btn btn-sm btn-outline-success rounded-pill mb-1"><i class="bi bi-eye"></i></a>
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill mb-1" data-bs-toggle="modal" data-bs-target="#editProjectModal<?= (int) $p['id']; ?>"><i class="bi bi-pencil-square"></i></button>
                                            <?php if ($p['status'] !== 'cancelled' && $p['status'] !== 'completed'): ?>
                                                <a href="<?= base_url('pages/projects/cancel.php?id=' . $p['id']); ?>" class="btn btn-sm btn-outline-warning rounded-pill mb-1" onclick="return confirm('Batalkan project ini?')"><i class="bi bi-x-circle"></i></a>
                                            <?php endif; ?>
                                            <a href="<?= base_url('pages/projects/delete.php?id=' . $p['id']); ?>" class="btn btn-sm btn-outline-danger rounded-pill mb-1"><i class="bi bi-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-5">Belum ada project. Klik Tambah Project untuk membuat data pertama.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="createProjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="create">
            <div class="modal-header border-0 pb-0"><div><h5 class="modal-title fw-bold">Tambah Project Baru</h5><small class="text-muted">Isi data utama project dengan lengkap dan valid.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-3">
                
                <div class="col-md-8"><label class="form-label fw-semibold">Nama Project <span class="text-danger">*</span></label><input type="text" name="project_name" class="form-control" placeholder="Contoh: Project ICC 2026" required></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Kode Project</label><input type="text" name="project_code" class="form-control" placeholder="NKR-PRJ-001"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Client</label><select name="client_id" class="form-select"><option value="">-- Pilih Client --</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id']; ?>"><?= htmlspecialchars($c['client_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Project Leader</label><select name="leader_id" class="form-select"><option value="">-- Pilih Leader dari Karyawan --</option><?php foreach ($employees as $e): ?><option value="<?= (int) $e['id']; ?>"><?= htmlspecialchars($e['full_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Tipe Project</label><select name="project_type" class="form-select js-project-type"><option value="one_time">One Time Project / Sekali Jalan</option><option value="ongoing">Contract Project / Kontrak Berkelanjutan</option></select><small class="text-muted">One Time untuk pekerjaan selesai sekali; Contract untuk kerja berulang sesuai periode kontrak.</small></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="planning">Planning</option><option value="ongoing">Ongoing</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></div>
                <div class="col-md-12 contract-fields">
                    <div class="p-3 rounded-4 border bg-light-subtle">
                        <div class="fw-bold mb-2"><i class="bi bi-file-earmark-text me-1"></i> Data Kontrak Berkelanjutan</div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label fw-semibold">Nomor Kontrak</label><input type="text" name="contract_number" class="form-control" placeholder="Contoh: BTID/ICC/2026"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Mulai Kontrak</label><input type="date" name="contract_start_date" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Akhir Kontrak</label><input type="date" name="contract_end_date" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Siklus Tagihan</label><select name="billing_cycle" class="form-select"><option value="none">Tidak Berulang</option><option value="monthly">Bulanan</option><option value="quarterly">Triwulan</option><option value="semi_annual">Semester</option><option value="annual">Tahunan</option><option value="custom">Custom</option></select></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Nominal Per Tagihan</label><input type="text" name="billing_amount" class="form-control js-money" placeholder="10.000.000"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Catatan Billing</label><input type="text" name="billing_notes" class="form-control" placeholder="Contoh: Dibayar setiap akhir bulan"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-12"><label class="form-label fw-semibold">Lokasi Project</label><input type="text" name="project_location" class="form-control" placeholder="Contoh: LM - Kura Kura Bali"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Mulai</label><input type="date" name="start_date" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Selesai</label><input type="date" name="end_date" class="form-control"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Nilai Kontrak</label><input type="text" name="contract_value" class="form-control js-money" placeholder="334.750.000"></div>
                <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" class="form-control" rows="4" placeholder="Catatan kesepakatan, kondisi pekerjaan, atau informasi penting lain."></textarea></div>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-nukari rounded-pill px-4">Simpan Project</button></div>
        </form>
    </div>
</div>

<?php foreach ($projects as $p): ?>
<div class="modal fade" id="editProjectModal<?= (int) $p['id']; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
            <div class="modal-header border-0 pb-0"><div><h5 class="modal-title fw-bold">Edit Project</h5><small class="text-muted"><?= htmlspecialchars($p['project_name']); ?></small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-8"><label class="form-label fw-semibold">Nama Project <span class="text-danger">*</span></label><input type="text" name="project_name" class="form-control" value="<?= htmlspecialchars($p['project_name']); ?>" required></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Kode Project</label><input type="text" name="project_code" class="form-control" value="<?= htmlspecialchars($p['project_code'] ?? ''); ?>"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Client</label><select name="client_id" class="form-select"><option value="">-- Pilih Client --</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id']; ?>" <?= ((int)($p['client_id'] ?? 0) === (int)$c['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($c['client_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Project Leader</label><select name="leader_id" class="form-select"><option value="">-- Pilih Leader --</option><?php foreach ($employees as $e): ?><option value="<?= (int) $e['id']; ?>" <?= ((int)($p['leader_id'] ?? 0) === (int)$e['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($e['full_name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Tipe Project</label><select name="project_type" class="form-select js-project-type"><option value="one_time" <?= ($p['project_type'] === 'one_time') ? 'selected' : ''; ?>>One Time Project / Sekali Jalan</option><option value="ongoing" <?= ($p['project_type'] === 'ongoing') ? 'selected' : ''; ?>>Contract Project / Kontrak Berkelanjutan</option></select><small class="text-muted">One Time untuk pekerjaan selesai sekali; Contract untuk kerja berulang sesuai periode kontrak.</small></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="planning" <?= ($p['status'] === 'planning') ? 'selected' : ''; ?>>Planning</option><option value="ongoing" <?= ($p['status'] === 'ongoing') ? 'selected' : ''; ?>>Ongoing</option><option value="completed" <?= ($p['status'] === 'completed') ? 'selected' : ''; ?>>Completed</option><option value="cancelled" <?= ($p['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option></select></div>
                <div class="col-md-12 contract-fields">
                    <div class="p-3 rounded-4 border bg-light-subtle">
                        <div class="fw-bold mb-2"><i class="bi bi-file-earmark-text me-1"></i> Data Kontrak Berkelanjutan</div>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label fw-semibold">Nomor Kontrak</label><input type="text" name="contract_number" class="form-control" value="<?= e($p['contract_number'] ?? ''); ?>"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Mulai Kontrak</label><input type="date" name="contract_start_date" class="form-control" value="<?= e($p['contract_start_date'] ?? ''); ?>"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Akhir Kontrak</label><input type="date" name="contract_end_date" class="form-control" value="<?= e($p['contract_end_date'] ?? ''); ?>"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Siklus Tagihan</label><select name="billing_cycle" class="form-select"><option value="none" <?= (($p['billing_cycle'] ?? 'none') === 'none') ? 'selected' : ''; ?>>Tidak Berulang</option><option value="monthly" <?= (($p['billing_cycle'] ?? '') === 'monthly') ? 'selected' : ''; ?>>Bulanan</option><option value="quarterly" <?= (($p['billing_cycle'] ?? '') === 'quarterly') ? 'selected' : ''; ?>>Triwulan</option><option value="semi_annual" <?= (($p['billing_cycle'] ?? '') === 'semi_annual') ? 'selected' : ''; ?>>Semester</option><option value="annual" <?= (($p['billing_cycle'] ?? '') === 'annual') ? 'selected' : ''; ?>>Tahunan</option><option value="custom" <?= (($p['billing_cycle'] ?? '') === 'custom') ? 'selected' : ''; ?>>Custom</option></select></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Nominal Per Tagihan</label><input type="text" name="billing_amount" class="form-control js-money" value="<?= number_format((float)($p['billing_amount'] ?? 0), 0, ',', '.'); ?>"></div>
                            <div class="col-md-4"><label class="form-label fw-semibold">Catatan Billing</label><input type="text" name="billing_notes" class="form-control" value="<?= e($p['billing_notes'] ?? ''); ?>"></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-12"><label class="form-label fw-semibold">Lokasi Project</label><input type="text" name="project_location" class="form-control" value="<?= htmlspecialchars($p['project_location'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Mulai</label><input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($p['start_date'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Selesai</label><input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($p['end_date'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Nilai Kontrak</label><input type="text" name="contract_value" class="form-control js-money" value="<?= number_format((float)$p['contract_value'], 0, ',', '.'); ?>"></div>
                <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" class="form-control" rows="4"><?= htmlspecialchars($p['notes'] ?? ''); ?></textarea></div>
            </div>
            <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-nukari rounded-pill px-4">Update Project</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<script src="<?= base_url('assets/vendor/chartjs/chart.umd.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal').forEach(function (modal) {
        const typeSelect = modal.querySelector('.js-project-type');
        const contractFields = modal.querySelector('.contract-fields');
        if (!typeSelect || !contractFields) return;

        const syncContractFields = function () {
            contractFields.style.display = typeSelect.value === 'ongoing' ? '' : 'none';
        };

        typeSelect.addEventListener('change', syncContractFields);
        syncContractFields();
    });

    const moneyFormat = (value) => new Intl.NumberFormat('id-ID').format(String(value).replace(/\D/g, ''));
    document.querySelectorAll('.js-money').forEach(function(input) {
        input.addEventListener('input', function() { this.value = moneyFormat(this.value); });
    });

    const search = document.getElementById('projectSearch');
    if (search) {
        search.addEventListener('keyup', function() {
            const keyword = this.value.toLowerCase();
            document.querySelectorAll('#projectTable tbody tr').forEach(function(row) {
                row.style.display = row.innerText.toLowerCase().includes(keyword) ? '' : 'none';
            });
        });
    }

    const financeEl = document.getElementById('projectFinanceChart');
    if (financeEl) {
        new Chart(financeEl, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartProjectNames); ?>,
                datasets: [
                    { label: 'Nilai Kontrak', data: <?= json_encode($chartContract); ?>, tension: .35, borderWidth: 3, fill: false },
                    { label: 'Pendapatan Riil', data: <?= json_encode($chartRealIncome); ?>, tension: .35, borderWidth: 3, fill: false },
                    { label: 'Pengeluaran Riil', data: <?= json_encode($chartExpense); ?>, tension: .35, borderWidth: 3, fill: false },
                    { label: 'Saldo Project', data: <?= json_encode($chartCashPosition); ?>, tension: .35, borderWidth: 3, fill: false }
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
        });
    }

    const statusEl = document.getElementById('projectStatusChart');
    if (statusEl) {
        new Chart(statusEl, {
            type: 'doughnut',
            data: {
                labels: ['Planning', 'Ongoing', 'Completed', 'Cancelled'],
                datasets: [{ data: [<?= (int)$totalPlanning; ?>, <?= (int)$totalOngoing; ?>, <?= (int)$totalCompleted; ?>, <?= (int)$totalCancelled; ?>], borderWidth: 2 }]
            },
            options: { cutout: '62%', plugins: { legend: { position: 'bottom' } } }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
