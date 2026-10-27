<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Keuangan - Nukari';
[$month, $startDate, $endDate] = f_month_range($_GET['month'] ?? date('Y-m'));

f_recalculate_all_accounts($pdo);

$accounts = $pdo->query("
    SELECT *
    FROM finance_accounts
    WHERE status = 'active'
    ORDER BY is_default DESC, account_type ASC, account_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$accountTotal = $pdo->query("SELECT COALESCE(SUM(current_balance),0) FROM finance_accounts WHERE status='active'")->fetchColumn();

$cashFlowRows = finance_consolidated_cash_flow_rows($pdo, $startDate, $endDate);
$monthly = finance_cash_flow_summary($cashFlowRows);
$profit = (float)$monthly['net_cash_flow'];

$activeReceivable = $pdo->query("SELECT COALESCE(SUM(amount - paid_amount),0) FROM debts WHERE type='receivable' AND status!='paid' AND posting_mode!='historical'")->fetchColumn();
$activeDebt = $pdo->query("SELECT COALESCE(SUM(amount - paid_amount),0) FROM debts WHERE type='debt' AND status!='paid' AND posting_mode!='historical'")->fetchColumn();
$activeReimbursement = $pdo->query("SELECT COALESCE(SUM(amount - paid_amount),0) FROM reimbursements WHERE status IN ('approved','partial')")->fetchColumn();
$operationalFundSummary = function_exists('opfund_summary') ? opfund_summary($pdo) : ['active_balance' => 0, 'active_cash' => 0, 'open_count' => 0];
$companyCashTotal = (float)$accountTotal + (float)($operationalFundSummary['active_cash'] ?? max(0, (float)($operationalFundSummary['active_balance'] ?? 0)));

$dailyMap = [];
foreach ($cashFlowRows as $row) {
    $date = (string)$row['transaction_date'];
    if (!isset($dailyMap[$date])) $dailyMap[$date] = ['trx_date'=>$date,'income_total'=>0.0,'expense_total'=>0.0];
    $key = $row['type']==='income' ? 'income_total' : 'expense_total';
    $dailyMap[$date][$key] += (float)$row['amount'];
}
ksort($dailyMap);
$dailyRows = array_values($dailyMap);

$chartLabels = [];
$chartIncome = [];
$chartExpense = [];
foreach ($dailyRows as $row) {
    $chartLabels[] = date('d M', strtotime($row['trx_date']));
    $chartIncome[] = (float)$row['income_total'];
    $chartExpense[] = (float)$row['expense_total'];
}

$recentTransactions = array_slice($cashFlowRows, 0, 12);
$categoryMap = [];
foreach ($cashFlowRows as $row) {
    $category = trim((string)($row['category_name'] ?? '')) ?: 'Tanpa Kategori';
    $key = $row['type'].'|'.$category;
    if (!isset($categoryMap[$key])) $categoryMap[$key] = ['category_name'=>$category,'type'=>$row['type'],'total_amount'=>0.0];
    $categoryMap[$key]['total_amount'] += (float)$row['amount'];
}
usort($categoryMap, static fn(array $a,array $b): int => $b['total_amount'] <=> $a['total_amount']);
$categorySummary = array_slice(array_values($categoryMap), 0, 8);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Keuangan</h1>
            <p class="text-muted mb-0">Pusat kontrol kas, bank, transaksi, hutang, piutang, dan cash flow Nukari.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= base_url('pages/finance/settings.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-sliders me-1"></i> Saldo Awal</a>
            <a href="<?= base_url('pages/finance/transactions.php'); ?>" class="btn btn-outline-success">
                <i class="bi bi-journal-text me-1"></i> Buku Kas
            </a>
            <a href="<?= base_url('pages/finance/operational_funds.php'); ?>" class="btn btn-outline-success">
                <i class="bi bi-wallet2 me-1"></i> Dana Operasional
            </a>
            <a href="<?= base_url('pages/finance/transaction_add.php'); ?>" class="btn btn-nukari">
                <i class="bi bi-plus-circle me-1"></i> Transaksi Manual
            </a>
        </div>
    </div>

    <?php if (!empty($_GET['message'])): ?>
        <div class="alert alert-success rounded-4"><?= f_e($_GET['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger rounded-4"><?= f_e($_GET['error']); ?></div>
    <?php endif; ?>

    <div class="panel mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <label class="form-label fw-semibold mb-1">Periode</label>
                <input type="month" name="month" class="form-control" value="<?= f_e($month); ?>">
            </div>
            <div class="col-md-2 d-grid">
                <label class="form-label mb-1">&nbsp;</label>
                <button class="btn btn-outline-success"><i class="bi bi-search me-1"></i> Tampilkan</button>
            </div>
            <div class="col-md-6 text-md-end">
                <label class="form-label mb-1">&nbsp;</label>
                <div class="small text-muted">Data ditampilkan dari <?= date('d M Y', strtotime($startDate)); ?> sampai <?= date('d M Y', strtotime($endDate)); ?></div>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="text-muted small">Total Uang Perusahaan</div>
                <div class="fs-4 fw-bold text-success"><?= rupiah($companyCashTotal); ?></div>
                <small class="text-muted">Akun pusat + sisa Dana PIC</small>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="text-muted small">Pendapatan Bulan Ini</div>
                <div class="fs-4 fw-bold text-success"><?= rupiah($monthly['income_total']); ?></div>
                <small class="text-muted"><?= f_e($month); ?></small>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="text-muted small">Pengeluaran Bulan Ini</div>
                <div class="fs-4 fw-bold text-danger"><?= rupiah($monthly['expense_total']); ?></div>
                <small class="text-muted"><?= f_e($month); ?></small>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="text-muted small">Arus Kas Bersih Bulan Ini</div>
                <div class="fs-4 fw-bold <?= $profit >= 0 ? 'text-success' : 'text-danger'; ?>"><?= rupiah($profit); ?></div>
                <small class="text-muted">Pendapatan - Pengeluaran</small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Piutang Aktif</div>
                        <div class="fs-4 fw-bold text-success"><?= rupiah($activeReceivable); ?></div>
                    </div>
                    <a href="<?= base_url('pages/debts/index.php?type=receivable'); ?>" class="btn btn-sm btn-outline-success align-self-start">Lihat</a>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Hutang Aktif</div>
                        <div class="fs-4 fw-bold text-danger"><?= rupiah($activeDebt); ?></div>
                    </div>
                    <a href="<?= base_url('pages/debts/index.php?type=debt'); ?>" class="btn btn-sm btn-outline-danger align-self-start">Lihat</a>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Histori Talangan Belum Dibayar</div>
                        <div class="fs-4 fw-bold text-warning"><?= rupiah($activeReimbursement); ?></div>
                    </div>
                    <a href="<?= base_url('pages/reimbursements/index.php'); ?>" class="btn btn-sm btn-outline-warning align-self-start">Lihat</a>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="panel h-100">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-muted small">Dana Operasional Aktif</div>
                        <div class="fs-4 fw-bold text-primary"><?= rupiah($operationalFundSummary['active_cash'] ?? $operationalFundSummary['active_balance'] ?? 0); ?></div>
                        <small class="text-muted"><?= (int)($operationalFundSummary['open_count'] ?? 0); ?> personal open</small>
                    </div>
                    <a href="<?= base_url('pages/finance/operational_funds.php'); ?>" class="btn btn-sm btn-outline-primary align-self-start">Lihat</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="panel h-100">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Cash Flow Bulanan</h5>
                        <div class="text-muted small">Perbandingan pemasukan dan pengeluaran harian.</div>
                    </div>
                    <span class="badge rounded-pill bg-success-subtle text-success">Kas/Bank + Dana PIC</span>
                </div>
                <canvas id="cashFlowChart" height="120"></canvas>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="panel h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">Saldo Akun</h5>
                    <a href="<?= base_url('pages/finance/accounts.php'); ?>" class="btn btn-sm btn-outline-success">Kelola</a>
                </div>
                <?php if ($accounts): ?>
                    <?php foreach ($accounts as $a): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <div>
                                <div class="fw-semibold"><?= f_e($a['account_name']); ?></div>
                                <div class="small text-muted"><?= f_account_type_badge($a['account_type']); ?> <?= $a['is_default'] ? '<span class="badge rounded-pill bg-secondary">Default</span>' : ''; ?></div>
                            </div>
                            <div class="fw-bold"><?= rupiah($a['current_balance']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted text-center py-4">Belum ada akun keuangan.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="panel h-100">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Transaksi Terbaru</h5>
                        <div class="text-muted small">Ringkasan transaksi terakhir pada periode terpilih.</div>
                    </div>
                    <a href="<?= base_url('pages/reports/index.php?' . http_build_query(['type'=>'finance','from'=>$startDate,'to'=>$endDate])); ?>" class="btn btn-sm btn-outline-success">Lihat Arus Kas</a>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Kode</th>
                                <th>Nama Transaksi</th>
                                <th>Jenis</th>
                                <th>Kategori</th>
                                <th>Akun</th>
                                <th>Project</th>
                                <th class="text-end">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($recentTransactions): ?>
                            <?php foreach ($recentTransactions as $t): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($t['transaction_date'])); ?></td>
                                    <td><span class="fw-semibold"><?= f_e($t['reference_code'] ?: '-'); ?></span><br><small class="text-muted">Sistem: <?= f_e($t['transaction_code']); ?></small></td>
                                    <td><b><?=f_e($t['transaction_name'] ?: $t['description'] ?: '-');?></b><br><small class="text-muted"><?= f_e(f_source_label($t['source_module'])); ?></small></td>
                                    <td><?= f_type_badge($t['type']); ?></td>
                                    <td><?= f_e($t['category_name'] ?: '-'); ?></td>
                                    <td><?= f_e($t['account_name'] ?: '-'); ?></td>
                                    <td><?= f_e($t['project_name'] ?: '-'); ?></td>
                                    <td class="text-end fw-bold <?= $t['type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                                        <?= ($t['type'] === 'income' ? '+' : '-'); ?> <?= rupiah($t['amount']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Belum ada transaksi pada periode ini.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="panel h-100">
                <h5 class="fw-bold mb-3">Kategori Terbesar</h5>
                <?php if ($categorySummary): ?>
                    <?php foreach ($categorySummary as $c): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <div>
                                <div class="fw-semibold"><?= f_e($c['category_name'] ?: 'Tanpa Kategori'); ?></div>
                                <div><?= f_type_badge($c['type']); ?></div>
                            </div>
                            <div class="fw-bold"><?= rupiah($c['total_amount']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted text-center py-4">Belum ada data kategori.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="<?= base_url('assets/vendor/chartjs/chart.umd.js'); ?>"></script>
<script>
const cashFlowCtx = document.getElementById('cashFlowChart');
if (cashFlowCtx) {
    new Chart(cashFlowCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Income',
                    data: <?= json_encode($chartIncome); ?>,
                    tension: 0.35,
                    fill: true
                },
                {
                    label: 'Expense',
                    data: <?= json_encode($chartExpense); ?>,
                    tension: 0.35,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                y: {
                    ticks: {
                        callback: function(value) {
                            return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
                        }
                    }
                }
            }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
