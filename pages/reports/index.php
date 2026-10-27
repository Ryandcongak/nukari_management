<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('reports.view');
require_once __DIR__ . '/report_data.php';
$type=$_GET['type'] ?? 'finance';
$from=nr_date($_GET['from'] ?? '', date('Y-m-01'));
$to=nr_date($_GET['to'] ?? '', date('Y-m-d'));
if ($from>$to) [$from,$to]=[$to,$from];
$report=nr_report($pdo,$type,$from,$to); $type=$report['type'];
$pageTitle=$report['definition']['title'].' - Nukari Management';
$types=['finance'=>'Keuangan','projects'=>'Proyek','payroll'=>'Payroll','assets'=>'Aset','products'=>'Penjualan Produk','employees'=>'Karyawan'];
$finance=$type==='finance' ? ($report['finance'] ?? null) : null;
$executiveView=is_report_only_role();
$today=date('Y-m-d');
$presetPeriods=[
    'Bulan Ini'=>[date('Y-m-01'),$today],
    'Bulan Lalu'=>[date('Y-m-01',strtotime('first day of last month')),date('Y-m-t',strtotime('last day of last month'))],
    'Tahun Ini'=>[date('Y-01-01'),$today],
];
$managementInsight='';
$dailyCashFlow=[];
$cashFlowIcons=[
    'Total Pemasukan'=>'bi-arrow-down-left-circle-fill',
    'Total Pengeluaran'=>'bi-arrow-up-right-circle-fill',
    'Arus Kas Bersih'=>'bi-activity',
    'Arus Kas Bersih Periode'=>'bi-activity',
];
if($finance){
    if(count($report['rows'])===0){
        $managementInsight='Belum ada pemasukan atau pengeluaran pada periode ini. Uang perusahaan tetap '.nr_money($finance['ending']['total_cash']).'.';
    }elseif($finance['cash_change']<0){
        $managementInsight='Uang perusahaan berkurang '.nr_money(abs($finance['cash_change'])).' selama periode terpilih dan tersisa '.nr_money($finance['ending']['total_cash']).'.';
    }elseif($finance['cash_change']>0){
        $managementInsight='Uang perusahaan bertambah '.nr_money($finance['cash_change']).' selama periode terpilih menjadi '.nr_money($finance['ending']['total_cash']).'.';
    }else{
        $managementInsight='Uang perusahaan tidak berubah selama periode terpilih dan berada pada '.nr_money($finance['ending']['total_cash']).'.';
    }
    if($finance['receivable']>0)$managementInsight.=' Piutang yang belum diterima '.nr_money($finance['receivable']).'.';
    foreach($report['raw'] as $movement){
        $movementDate=(string)($movement['transaction_date']??'');
        if($movementDate==='')continue;
        if(!isset($dailyCashFlow[$movementDate]))$dailyCashFlow[$movementDate]=['income'=>0.0,'expense'=>0.0];
        $movementType=(string)($movement['type']??'');
        if(isset($dailyCashFlow[$movementDate][$movementType]))$dailyCashFlow[$movementDate][$movementType]+=(float)($movement['amount']??0);
    }
    ksort($dailyCashFlow);
}
require_once __DIR__ . '/../../includes/header.php'; require_once __DIR__ . '/../../includes/sidebar.php';
?>
<style>
.management-hero{background:linear-gradient(135deg,#123f2c 0%,#1f7a4d 65%,#2f9d67 100%);color:#fff;border-radius:26px;padding:clamp(22px,4vw,38px);box-shadow:0 20px 45px rgba(18,63,44,.18);position:relative;overflow:hidden}.management-hero:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;right:-90px;top:-120px;background:rgba(255,255,255,.08)}.management-hero>*{position:relative;z-index:1}.management-kicker{font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#bbf7d0}.management-total{font-size:clamp(2rem,6vw,3.4rem);font-weight:850;letter-spacing:-.04em;line-height:1.05;margin:.55rem 0}.management-insight{font-size:clamp(1rem,2vw,1.15rem);line-height:1.6;max-width:850px;color:rgba(255,255,255,.88)}.management-meta{display:flex;flex-wrap:wrap;gap:8px 18px;font-size:.84rem;color:rgba(255,255,255,.72);margin-top:18px}.management-composition{margin-top:22px;max-width:720px}.management-bar{height:10px;border-radius:999px;background:rgba(255,255,255,.18);overflow:hidden;display:flex}.management-bar-central{background:#fff}.management-bar-pic{background:#fbbf24;min-width:3px}.management-legend{display:flex;flex-wrap:wrap;gap:8px 20px;margin-top:9px;font-size:.82rem;color:rgba(255,255,255,.8)}.management-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;background:#fff}.management-dot.pic{background:#fbbf24}.management-presets{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.management-jump-nav{display:flex;gap:8px;overflow-x:auto;padding:2px 2px 10px;margin-bottom:12px;scrollbar-width:thin}.management-jump-nav a{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;border:1px solid #d8e7df;background:#fff;color:#285a42;text-decoration:none;border-radius:999px;padding:8px 13px;font-size:.84rem;font-weight:700;transition:.2s ease}.management-jump-nav a:hover,.management-jump-nav a:focus{background:#eaf6ef;border-color:#75b58f;transform:translateY(-1px)}.management-filter{background:#fff;border-radius:18px;box-shadow:0 12px 30px rgba(0,0,0,.05);margin-bottom:24px}.management-filter>summary,.management-details>summary{cursor:pointer;list-style:none;font-weight:750;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}.management-filter>summary::-webkit-details-marker,.management-details>summary::-webkit-details-marker{display:none}.management-filter>summary:after,.management-details>summary:after{content:"＋";font-size:1.15rem}.management-filter[open]>summary:after,.management-details[open]>summary:after{content:"−"}.management-filter .panel{box-shadow:none;margin:0;border-top:1px solid #eef0f2;border-radius:0 0 18px 18px}.management-details{background:#fff;border:1px solid #e5e7eb;border-radius:18px;margin-bottom:24px;overflow:hidden}.management-details-content{padding:0 18px 18px}.management-details-content>.panel,.management-details-content>.row{margin-bottom:0!important}.executive-support .stat-card,.finance-position-summary .stat-card{min-height:146px;position:relative;overflow:hidden;padding-right:70px}.executive-support .stat-number{font-size:clamp(1.35rem,3vw,2rem)}.management-card-icon{position:absolute;right:18px;top:18px;width:42px;height:42px;border-radius:14px;display:grid;place-items:center;font-size:1.25rem;background:#eaf6ef;color:#1f7a4d}.border-orange .management-card-icon{background:#fff5df;color:#b77900}.border-red .management-card-icon{background:#fff0ef;color:#c14438}.border-blue .management-card-icon{background:#edf4ff;color:#2563a6}.management-chart-card{background:#fff;border:1px solid #e6ece8;border-radius:22px;padding:20px;box-shadow:0 12px 32px rgba(24,74,49,.07);height:100%}.management-chart-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}.management-chart-icon{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;background:#eaf6ef;color:#1f7a4d;font-size:1.15rem;flex:0 0 auto}.management-chart-wrap{position:relative;height:290px}.management-chart-toggle{display:inline-flex;padding:4px;background:#f1f5f3;border-radius:12px;gap:3px}.management-chart-toggle button{border:0;background:transparent;color:#647067;border-radius:9px;padding:7px 10px;font-size:.78rem;font-weight:700}.management-chart-toggle button.active{background:#fff;color:#176b42;box-shadow:0 2px 8px rgba(0,0,0,.08)}.management-flow-card{position:relative;padding-left:66px!important}.management-flow-icon{position:absolute;left:17px;top:18px;width:36px;height:36px;border-radius:12px;display:grid;place-items:center;background:#eef8f2;color:#1f7a4d;font-size:1.05rem}.border-red .management-flow-icon{background:#fff0ef;color:#c14438}.border-blue .management-flow-icon{background:#edf4ff;color:#2563a6}.management-section-anchor{scroll-margin-top:95px}
.report-page .stat-card{--card-accent:#1f7a4d;--card-rgb:31,122,77;--card-x:85%;--card-y:12%;position:relative;overflow:hidden;border:1px solid rgba(var(--card-rgb),.12);border-left:5px solid var(--card-accent);background:radial-gradient(260px circle at var(--card-x) var(--card-y),rgba(var(--card-rgb),.13),transparent 68%),linear-gradient(145deg,#fff 0%,#fbfdfc 100%);box-shadow:0 10px 28px rgba(25,65,45,.07);transition:transform .28s cubic-bezier(.2,.8,.2,1),box-shadow .28s ease,border-color .28s ease,background .18s ease}.report-page .stat-card.border-blue{--card-accent:#2563eb;--card-rgb:37,99,235}.report-page .stat-card.border-green{--card-accent:#16a34a;--card-rgb:22,163,74}.report-page .stat-card.border-orange{--card-accent:#f59e0b;--card-rgb:245,158,11}.report-page .stat-card.border-red{--card-accent:#dc2626;--card-rgb:220,38,38}.report-page .stat-card .stat-number{transition:color .25s ease,transform .25s ease}.management-card-icon,.management-flow-icon{transition:transform .3s cubic-bezier(.2,.8,.2,1),box-shadow .3s ease}.management-chart-card{--card-rgb:31,122,77;--card-x:85%;--card-y:12%;background:radial-gradient(320px circle at var(--card-x) var(--card-y),rgba(var(--card-rgb),.09),transparent 68%),#fff;transition:transform .3s cubic-bezier(.2,.8,.2,1),box-shadow .3s ease,border-color .3s ease}.management-chart-icon{transition:transform .3s cubic-bezier(.2,.8,.2,1),box-shadow .3s ease}.management-details,.management-filter{transition:border-color .25s ease,box-shadow .25s ease,transform .25s ease}.management-details[open],.management-filter[open]{border-color:#b9d7c5;box-shadow:0 14px 34px rgba(24,74,49,.08)}
.card-help{display:inline-grid;place-items:center;width:24px;height:24px;padding:0;margin-left:6px;vertical-align:-5px;border:1px solid rgba(var(--card-rgb,31,122,77),.22);border-radius:50%;background:rgba(var(--card-rgb,31,122,77),.08);color:var(--card-accent,#1f7a4d);font-size:.76rem;line-height:1;cursor:help;transition:transform .18s ease,background-color .18s ease,box-shadow .18s ease}.card-help:hover,.card-help:focus-visible{background:var(--card-accent,#1f7a4d);color:#fff;transform:scale(1.08);box-shadow:0 5px 12px rgba(var(--card-rgb,31,122,77),.2);outline:0}.management-chart-card .card-help{--card-accent:#1f7a4d;--card-rgb:31,122,77;vertical-align:-4px}.management-hero .card-help{--card-accent:#fff;--card-rgb:255,255,255;color:#fff;background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.32);vertical-align:-6px}.management-hero .card-help:hover,.management-hero .card-help:focus-visible{background:#fff;color:#176b42}.management-tooltip .tooltip-inner{max-width:310px;padding:10px 12px;text-align:left;line-height:1.45;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.16)}
@media(hover:hover) and (pointer:fine){.report-page .stat-card:hover{transform:translateY(-6px);box-shadow:0 22px 42px rgba(var(--card-rgb),.16),inset 0 -3px 0 rgba(var(--card-rgb),.55);border-color:rgba(var(--card-rgb),.28);border-left-color:var(--card-accent)}.report-page .stat-card:hover .stat-number{color:var(--card-accent);transform:translateY(-1px)}.report-page .stat-card:hover .management-card-icon{transform:translateY(-3px) rotate(-5deg) scale(1.08);box-shadow:0 10px 20px rgba(var(--card-rgb),.18)}.report-page .stat-card:hover .management-flow-icon{transform:translateY(-2px) scale(1.08);box-shadow:0 8px 16px rgba(var(--card-rgb),.15)}.management-chart-card:hover{transform:translateY(-5px);border-color:#bdd9c8;box-shadow:0 22px 46px rgba(24,74,49,.13)}.management-chart-card:hover .management-chart-icon{transform:rotate(-5deg) scale(1.08);box-shadow:0 9px 18px rgba(31,122,77,.15)}.management-details:hover,.management-filter:hover{border-color:#c5ddd0}}
@keyframes managementCardReveal{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@media(prefers-reduced-motion:no-preference){.report-summary>div{animation:managementCardReveal .48s cubic-bezier(.2,.8,.2,1) both}.report-summary>div:nth-child(2){animation-delay:.055s}.report-summary>div:nth-child(3){animation-delay:.11s}.report-summary>div:nth-child(4){animation-delay:.165s}.report-summary>div:nth-child(5){animation-delay:.22s}.report-summary>div:nth-child(6){animation-delay:.275s}#managementCharts .row>div{animation:managementCardReveal .52s cubic-bezier(.2,.8,.2,1) .14s both}#managementCharts .row>div:nth-child(2){animation-delay:.21s}}
@media(max-width:767.98px){.management-hero{border-radius:20px}.management-presets .btn{flex:1 1 auto}.executive-support>div{width:100%}.management-insight{line-height:1.5}.management-chart-card{padding:16px;border-radius:18px}.management-chart-wrap{height:250px}.management-chart-toggle{width:100%}.management-chart-toggle button{flex:1}.management-flow-card{padding-left:60px!important}}
@media(prefers-reduced-motion:reduce){.report-page .stat-card,.management-card-icon,.management-flow-icon,.management-chart-card,.management-chart-icon,.management-details,.management-filter{animation:none!important;transition:none!important;transform:none!important}}
@media print{.management-presets,.management-filter,.management-jump-nav,.management-chart-toggle,.card-help{display:none!important}.management-hero{background:#fff!important;color:#111!important;border:2px solid #1f7a4d;box-shadow:none}.management-kicker,.management-insight,.management-meta,.management-legend{color:#333!important}.management-bar{border:1px solid #999}.management-bar-central{background:#1f7a4d!important}.management-details>summary{display:none!important}.management-details:not([open])>.management-details-content{display:block!important}.management-details{border:0;overflow:visible}.management-details-content{padding:0}.report-page .stat-card,.management-chart-card{background:#fff!important;box-shadow:none!important;transform:none!important;animation:none!important}.management-chart-card{break-inside:avoid}.management-chart-wrap{height:240px}}
</style>
<main class="main-content report-page">
<div class="page-header report-header d-flex justify-content-between align-items-start flex-wrap gap-3"><div><div class="text-success fw-bold small text-uppercase"><?= is_report_only_role() ? 'Ringkasan Manajemen' : 'PT Nukari Kriya Raya'; ?></div><h1 class="fw-bold mb-1"><?= htmlspecialchars($report['definition']['title']); ?></h1><p class="text-muted mb-0"><?= htmlspecialchars($report['definition']['description']); ?></p></div><div class="report-actions d-flex flex-wrap gap-2"><?php if (has_permission('reports.export')): ?><a class="btn btn-outline-success" href="<?= base_url('pages/reports/export.php?'.http_build_query(['type'=>$type,'from'=>$from,'to'=>$to])); ?>"><i class="bi bi-download me-1"></i> Download CSV</a><?php endif; ?><button class="btn btn-nukari" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print / PDF</button></div></div>
<?php if($executiveView): ?><div class="management-presets" aria-label="Pilihan periode cepat"><?php foreach($presetPeriods as $label=>$period): ?><a class="btn btn-sm <?= $from===$period[0]&&$to===$period[1]?'btn-nukari':'btn-outline-success'; ?>" href="<?= base_url('pages/reports/index.php?'.http_build_query(['type'=>$type,'from'=>$period[0],'to'=>$period[1]])); ?>"><?= htmlspecialchars($label); ?></a><?php endforeach; ?></div><details class="management-filter"><summary><span><i class="bi bi-calendar3 me-2 text-success"></i>Atur jenis laporan atau tanggal khusus</span><small class="text-muted"><?= date('d M Y',strtotime($from)); ?> – <?= date('d M Y',strtotime($to)); ?></small></summary><div class="panel report-filter"><?php else: ?><div class="panel report-filter"><?php endif; ?><form method="get" class="row g-3 align-items-end"><div class="col-xl-4 col-md-6"><label class="form-label fw-semibold">Jenis Laporan</label><select class="form-select" name="type"><?php foreach($types as $key=>$label): ?><option value="<?= $key; ?>" <?= $type===$key?'selected':''; ?>><?= htmlspecialchars($label); ?></option><?php endforeach; ?></select></div><div class="col-xl-3 col-md-3 col-6"><label class="form-label fw-semibold">Dari</label><input type="date" class="form-control" name="from" value="<?= $from; ?>"></div><div class="col-xl-3 col-md-3 col-6"><label class="form-label fw-semibold">Sampai</label><input type="date" class="form-control" name="to" value="<?= $to; ?>"></div><div class="col-xl-2 col-md-12 d-grid"><button class="btn btn-nukari"><i class="bi bi-funnel me-1"></i>Tampilkan</button></div><div class="col-12"><small class="text-muted">Filter tanggal berlaku untuk laporan transaksi. Laporan aset dan karyawan menampilkan posisi data terkini.</small></div></form></div><?php if($executiveView): ?></details><?php endif; ?>
<?php if($report['error']): ?><div class="alert alert-warning"><?= htmlspecialchars($report['error']); ?></div><?php endif; ?>
<?php if($finance): ?>
<nav class="management-jump-nav" aria-label="Navigasi ringkasan laporan">
    <a href="#managementSummary"><i class="bi bi-wallet2"></i>Ringkasan</a>
    <a href="#managementCharts"><i class="bi bi-bar-chart-line"></i>Grafik</a>
    <a href="#managementCashFlow"><i class="bi bi-arrow-left-right"></i>Arus Kas</a>
    <a href="#managementDetails"><i class="bi bi-list-ul"></i>Rincian</a>
</nav>
<?php endif; ?>

<?php if($finance): ?>
<section class="mb-4 management-section-anchor" id="managementSummary">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
        <div><h2 class="h4 fw-bold mb-1">Posisi Uang Perusahaan</h2><p class="text-muted mb-0">Jumlah uang yang benar-benar berada di kas, bank, dan Dana PIC per <?= date('d M Y',strtotime($to)); ?>.</p></div>
        <span class="badge rounded-pill bg-primary-subtle text-primary px-3 py-2">Posisi per tanggal akhir</span>
    </div>
    <?php if($executiveView): ?>
    <?php $centralPercent=$finance['ending']['total_cash']>0?max(0,min(100,$finance['ending']['central_cash']/$finance['ending']['total_cash']*100)):0;$picPercent=max(0,100-$centralPercent); ?>
    <div class="management-hero mb-3">
        <div class="management-kicker">Kesimpulan Utama</div><div class="management-total"><?= nr_money($finance['ending']['total_cash']); ?></div><div class="management-insight"><?= htmlspecialchars($managementInsight); ?></div>
        <div class="management-meta"><span><i class="bi bi-calendar-check me-1"></i>Posisi <?= date('d M Y',strtotime($to)); ?></span><?php if(!empty($finance['last_updated_at'])): ?><span><i class="bi bi-clock-history me-1"></i>Data diperbarui <?= date('d M Y H:i',strtotime($finance['last_updated_at'])); ?></span><?php endif; ?><span><i class="bi bi-eye me-1"></i>Hanya lihat</span></div>
        <div class="management-composition"><div class="management-bar" aria-label="Komposisi uang perusahaan"><span class="management-bar-central" style="width:<?= number_format($centralPercent,2,'.',''); ?>%"></span><span class="management-bar-pic" style="width:<?= number_format($picPercent,2,'.',''); ?>%"></span></div><div class="management-legend"><span><i class="management-dot"></i>Bank &amp; kas <?= nr_money($finance['ending']['central_cash']); ?></span><span><i class="management-dot pic"></i>Dana PIC <?= nr_money($finance['ending']['pic_cash']); ?></span></div></div>
    </div>
    <div class="row g-3 report-summary executive-support">
        <div class="col-md-4"><div class="stat-card border-green h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-bank"></i></span><div class="stat-title">Bank &amp; Kas Pusat</div><div class="stat-number report-stat"><?= nr_money($finance['ending']['central_cash']); ?></div><div class="small text-muted mt-2">Uang di seluruh akun aktif</div></div></div>
        <div class="col-md-4"><div class="stat-card border-orange h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span><div class="stat-title">Piutang Belum Diterima</div><div class="stat-number report-stat"><?= nr_money($finance['receivable']); ?></div><div class="small text-muted mt-2">Belum termasuk uang tersedia</div></div></div>
        <div class="col-md-4"><div class="stat-card border-red h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-exclamation-circle"></i></span><div class="stat-title">Hutang Belum Dibayar</div><div class="stat-number report-stat"><?= nr_money($finance['debt']); ?></div><div class="small text-muted mt-2">Posisi bersih <?= nr_money($finance['net_position']); ?></div></div></div>
    </div>
    <?php else: ?>
    <div class="row g-3 report-summary finance-position-summary">
        <div class="col-12 col-md-6 col-xl-4"><div class="stat-card border-blue h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-wallet2"></i></span><div class="stat-title">Total Uang Perusahaan</div><div class="stat-number report-stat"><?= nr_money($finance['ending']['total_cash']); ?></div><div class="small text-muted mt-2">Bank/kas pusat + sisa uang di PIC</div></div></div>
        <div class="col-6 col-md-6 col-xl-4"><div class="stat-card border-green h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-bank"></i></span><div class="stat-title">Saldo Bank &amp; Kas Pusat</div><div class="stat-number report-stat"><?= nr_money($finance['ending']['central_cash']); ?></div><div class="small text-muted mt-2">Seluruh akun aktif perusahaan</div></div></div>
        <div class="col-6 col-md-6 col-xl-4"><div class="stat-card border-blue h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span><div class="stat-title">Sisa Uang di PIC</div><div class="stat-number report-stat"><?= nr_money($finance['ending']['pic_cash']); ?></div><div class="small text-muted mt-2">Masih milik perusahaan</div></div></div>
        <div class="col-6 col-md-6 col-xl-4"><div class="stat-card border-orange h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span><div class="stat-title">Piutang Belum Diterima</div><div class="stat-number report-stat"><?= nr_money($finance['receivable']); ?></div><div class="small text-muted mt-2">Belum termasuk uang tersedia</div></div></div>
        <div class="col-6 col-md-6 col-xl-4"><div class="stat-card border-red h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-exclamation-circle"></i></span><div class="stat-title">Hutang Belum Dibayar</div><div class="stat-number report-stat"><?= nr_money($finance['debt']); ?></div><div class="small text-muted mt-2">Kewajiban perusahaan</div></div></div>
        <div class="col-12 col-md-6 col-xl-4"><div class="stat-card border-green h-100"><span class="management-card-icon" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span><div class="stat-title">Posisi Bersih</div><div class="stat-number report-stat"><?= nr_money($finance['net_position']); ?></div><div class="small text-muted mt-2">Uang tersedia + piutang − hutang</div></div></div>
    </div>
    <?php endif; ?>
</section>

<div class="alert alert-light border rounded-4 mb-4 finance-reading-guide"><i class="bi bi-info-circle text-primary me-2"></i><strong>Cara membaca:</strong> Total Uang Perusahaan adalah uang yang tersedia. Piutang belum menjadi uang sampai diterima. Posisi Bersih adalah gambaran lebih luas, bukan saldo kas.</div>

<section class="mb-4 management-section-anchor" id="managementCharts" aria-labelledby="managementChartsTitle">
    <div class="mb-3"><h2 class="h4 fw-bold mb-1" id="managementChartsTitle">Gambaran Visual Keuangan</h2><p class="text-muted mb-0">Grafik membantu melihat komposisi uang dan perubahan arus kas tanpa membaca tabel panjang.</p></div>
    <div class="row g-3">
        <div class="col-xl-5">
            <article class="management-chart-card">
                <div class="management-chart-head"><div class="d-flex gap-3"><span class="management-chart-icon" aria-hidden="true"><i class="bi bi-pie-chart-fill"></i></span><div><h3 class="h6 fw-bold mb-1">Komposisi Uang</h3><p class="small text-muted mb-0">Lokasi uang perusahaan per <?= date('d M Y',strtotime($to)); ?></p></div></div></div>
                <div class="management-chart-wrap"><canvas id="cashCompositionChart" role="img" aria-label="Grafik komposisi uang di bank dan kas pusat serta Dana PIC"></canvas></div>
            </article>
        </div>
        <div class="col-xl-7">
            <article class="management-chart-card">
                <div class="management-chart-head">
                    <div class="d-flex gap-3"><span class="management-chart-icon" aria-hidden="true"><i class="bi bi-bar-chart-line-fill"></i></span><div><h3 class="h6 fw-bold mb-1">Pemasukan &amp; Pengeluaran</h3><p class="small text-muted mb-0" id="cashFlowChartDescription">Perbandingan periode dipilih dengan periode sebelumnya.</p></div></div>
                    <div class="management-chart-toggle" role="group" aria-label="Jenis grafik arus kas"><button type="button" class="active" data-chart-view="comparison" aria-pressed="true">Perbandingan</button><button type="button" data-chart-view="daily" aria-pressed="false">Tren Harian</button></div>
                </div>
                <div class="management-chart-wrap"><canvas id="cashFlowChart" role="img" aria-label="Grafik pemasukan dan pengeluaran"></canvas></div>
            </article>
        </div>
    </div>
</section>

<section class="mb-4 management-section-anchor" id="managementCashFlow">
    <div class="mb-3"><h2 class="h4 fw-bold mb-1">Arus Kas Periode</h2><p class="text-muted mb-0"><?= date('d M Y',strtotime($from)); ?> sampai <?= date('d M Y',strtotime($to)); ?>. Transfer antara akun perusahaan dan PIC tidak dihitung sebagai pemasukan atau pengeluaran.</p></div>
    <div class="row g-3 report-summary"><?php foreach($report['summary'] as $s): if($executiveView&&!in_array($s['label'],['Total Pemasukan','Total Pengeluaran','Arus Kas Bersih','Arus Kas Bersih Periode'],true))continue; $toneClass=$s['tone']==='primary'?'blue':($s['tone']==='danger'?'red':($s['tone']==='warning'?'orange':'green')); ?><div class="col-6 col-xl"><div class="stat-card h-100 border-<?= $toneClass; ?> <?= $executiveView?'management-flow-card':''; ?>"><?php if($executiveView): ?><span class="management-flow-icon" aria-hidden="true"><i class="bi <?= htmlspecialchars($cashFlowIcons[$s['label']]??'bi-cash-stack'); ?>"></i></span><?php endif; ?><div class="stat-title"><?= htmlspecialchars($s['label']); ?></div><div class="stat-number report-stat"><?= htmlspecialchars($s['value']); ?></div></div></div><?php endforeach; ?></div>
    <div class="panel mt-3 mb-0 py-3"><div class="row g-3 align-items-center"><div class="col-md-7"><div class="fw-semibold">Rekonsiliasi sederhana</div><div class="text-muted small">Saldo awal + pemasukan − pengeluaran = saldo akhir. Dana diberikan ke PIC <?= nr_money($finance['internal_transferred']); ?> dan dikembalikan <?= nr_money($finance['internal_returned']); ?> hanya perpindahan internal.</div><?php if($executiveView): ?><div class="small mt-2"><strong>Periode sebelumnya:</strong> <?php if(($finance['previous_period']['row_count']??0)>0): ?>arus kas bersih <?= nr_money($finance['previous_period']['net_cash_flow']); ?> (<?= date('d M',strtotime($finance['previous_period']['from'])); ?>–<?= date('d M Y',strtotime($finance['previous_period']['to'])); ?>)<?php else: ?>belum ada pergerakan kas untuk dibandingkan<?php endif; ?>.</div><?php endif; ?></div><div class="col-md-5 text-md-end"><span class="small text-muted">Perubahan uang selama periode</span><div class="fw-bold <?= $finance['cash_change']>=0?'text-success':'text-danger'; ?>"><?= nr_money($finance['cash_change']); ?></div><div class="small text-muted">Saldo akhir <?= nr_money($finance['ending']['total_cash']); ?></div></div></div></div>
    <?php if(abs((float)$finance['reconciliation_difference'])>0.01): ?><div class="alert alert-warning mt-3 mb-0"><strong>Perlu pemeriksaan:</strong> terdapat selisih rekonsiliasi <?= nr_money($finance['reconciliation_difference']); ?>. Biasanya terjadi karena saldo Dana PIC minus atau penyesuaian khusus.</div><?php endif; ?>
</section>

<?php if($executiveView): ?><details class="management-details"><summary><span><i class="bi bi-bank me-2 text-primary"></i>Lihat rincian lokasi uang</span><small class="text-muted">Bank, kas, dan Dana PIC</small></summary><div class="management-details-content"><?php endif; ?><section class="row g-4 mb-4 finance-balance-detail">
    <div class="col-xl-6"><div class="panel h-100 mb-0"><h2 class="h5 fw-bold mb-1">Rincian Saldo Bank &amp; Kas</h2><p class="small text-muted">Posisi per <?= date('d M Y',strtotime($to)); ?></p><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Akun</th><th>Jenis</th><th class="text-end">Saldo</th></tr></thead><tbody><?php foreach($finance['ending']['accounts'] as $account): ?><tr><td><?= htmlspecialchars($account['account_name']); ?></td><td><?= htmlspecialchars(ucwords(str_replace('_',' ',$account['account_type']))); ?></td><td class="text-end fw-semibold"><?= nr_money($account['balance']); ?></td></tr><?php endforeach; ?><?php if(!$finance['ending']['accounts']): ?><tr><td colspan="3" class="text-center text-muted">Belum ada akun aktif.</td></tr><?php endif; ?></tbody><tfoot><tr><th colspan="2">Total Bank &amp; Kas</th><th class="text-end"><?= nr_money($finance['ending']['central_cash']); ?></th></tr></tfoot></table></div></div></div>
    <div class="col-xl-6"><div class="panel h-100 mb-0"><h2 class="h5 fw-bold mb-1">Rincian Dana PIC</h2><p class="small text-muted">Saldo positif adalah uang perusahaan yang masih dipegang PIC.</p><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>PIC</th><th>Kode</th><th class="text-end">Saldo</th></tr></thead><tbody><?php $shownFunds=0; foreach($finance['ending']['funds'] as $fund): if(abs((float)$fund['balance'])<0.01) continue; $shownFunds++; ?><tr><td><?= htmlspecialchars($fund['holder_name']); ?></td><td><?= htmlspecialchars($fund['fund_code']); ?></td><td class="text-end fw-semibold <?= $fund['balance']<0?'text-danger':''; ?>"><?= nr_money($fund['balance']); ?></td></tr><?php endforeach; ?><?php if(!$shownFunds): ?><tr><td colspan="3" class="text-center text-muted">Tidak ada saldo Dana PIC.</td></tr><?php endif; ?></tbody><tfoot><tr><th colspan="2">Sisa Uang di PIC</th><th class="text-end"><?= nr_money($finance['ending']['pic_cash']); ?></th></tr></tfoot></table></div></div></div>
</section>
<?php if($executiveView): ?></div></details><?php endif; ?>
<?php else: ?>
<div class="row g-3 mb-4 report-summary"><?php foreach($report['summary'] as $s): ?><div class="col-6 col-xl-3"><div class="stat-card border-<?= $s['tone']==='primary'?'blue':($s['tone']==='danger'?'red':($s['tone']==='warning'?'orange':'green')); ?>"><div class="stat-title"><?= htmlspecialchars($s['label']); ?></div><div class="stat-number report-stat"><?= htmlspecialchars($s['value']); ?></div></div></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if($executiveView): ?><details class="management-details management-section-anchor" id="managementDetails"><summary><span><i class="bi bi-list-ul me-2 text-success"></i>Lihat rincian <?= $finance?'arus kas':'laporan'; ?></span><small class="text-muted"><?= count($report['rows']); ?> baris</small></summary><div class="management-details-content"><?php endif; ?><div class="panel report-table-panel <?= !$executiveView?'management-section-anchor':''; ?>" <?= !$executiveView?'id="managementDetails"':''; ?>><div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3"><div><h2 class="h5 fw-bold mb-1"><?= $finance?'Rincian Arus Kas':'Rincian Laporan'; ?></h2><?php if($finance): ?><div class="small text-muted">Hanya mutasi yang mengubah uang perusahaan; transfer internal ke/dari PIC dikecualikan.</div><?php endif; ?></div><span class="badge bg-light text-dark border"><?= count($report['rows']); ?> baris</span></div><div class="report-print-meta d-none"><strong>Periode:</strong> <?= date('d/m/Y',strtotime($from)); ?> – <?= date('d/m/Y',strtotime($to)); ?> · <strong>Dicetak:</strong> <?= date('d/m/Y H:i'); ?></div><div class="table-responsive"><table class="table table-hover align-middle report-table"><thead><tr><?php foreach($report['definition']['headers'] as $h): ?><th><?= htmlspecialchars($h); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($report['rows'] as $row): ?><tr><?php foreach($row as $cell): ?><td><?= htmlspecialchars((string)$cell); ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if(!$report['rows']): ?><tr><td colspan="<?= count($report['definition']['headers']); ?>" class="text-center text-muted py-5">Tidak ada data pada laporan ini.</td></tr><?php endif; ?></tbody></table></div><div class="small text-muted mt-3">Menampilkan <?= count($report['rows']); ?> baris · Periode <?= date('d M Y',strtotime($from)); ?> sampai <?= date('d M Y',strtotime($to)); ?></div></div><?php if($executiveView): ?></div></details><?php endif; ?>
</main>
<?php if($finance):
$chartPayload=[
    'composition'=>[
        'central'=>(float)$finance['ending']['central_cash'],
        'pic'=>(float)$finance['ending']['pic_cash'],
        'total'=>(float)$finance['ending']['total_cash'],
    ],
    'comparison'=>[
        'income'=>[(float)($finance['previous_period']['income_total']??0),(float)$finance['income']],
        'expense'=>[(float)($finance['previous_period']['expense_total']??0),(float)$finance['expense']],
    ],
    'daily'=>[
        'labels'=>array_keys($dailyCashFlow),
        'income'=>array_values(array_map(static fn($day)=>(float)$day['income'],$dailyCashFlow)),
        'expense'=>array_values(array_map(static fn($day)=>(float)$day['expense'],$dailyCashFlow)),
    ],
];
?>
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.js'); ?>"></script>
<script>
(() => {
    if (typeof Chart === 'undefined') return;
    const reportData = <?= json_encode($chartPayload,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    const rupiah = value => new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(value);
    const compactRupiah = value => 'Rp ' + new Intl.NumberFormat('id-ID',{notation:'compact',maximumFractionDigits:1}).format(value);
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const commonTooltip = {callbacks:{label: context => `${context.dataset.label}: ${rupiah(context.parsed.y ?? context.parsed)}`}};
    const emptyStatePlugin = {
        id:'managementEmptyState',
        afterDraw(chart) {
            const values=chart.data.datasets.flatMap(dataset => dataset.data).map(Number);
            if(values.some(value => Math.abs(value)>0))return;
            const {ctx,chartArea}=chart;
            if(!chartArea)return;
            ctx.save();ctx.fillStyle='#7b857f';ctx.font='600 13px system-ui';ctx.textAlign='center';ctx.textBaseline='middle';
            ctx.fillText('Belum ada arus kas pada periode ini',chartArea.left+chartArea.width/2,chartArea.top+chartArea.height/2);ctx.restore();
        }
    };
    const centerTextPlugin = {
        id:'managementCenterText',
        afterDraw(chart) {
            const {ctx,chartArea}=chart;
            if(!chartArea)return;
            const centerX=(chartArea.left+chartArea.right)/2,centerY=(chartArea.top+chartArea.bottom)/2;
            ctx.save();ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillStyle='#718078';ctx.font='600 12px system-ui';ctx.fillText('Total uang',centerX,centerY-11);
            ctx.fillStyle='#173f2d';ctx.font='800 16px system-ui';ctx.fillText(compactRupiah(reportData.composition.total),centerX,centerY+12);ctx.restore();
        }
    };
    const compositionCanvas=document.getElementById('cashCompositionChart');
    if(compositionCanvas){
        new Chart(compositionCanvas,{type:'doughnut',data:{labels:['Bank & Kas Pusat','Dana PIC'],datasets:[{data:[reportData.composition.central,reportData.composition.pic],backgroundColor:['#1f7a4d','#f5b82e'],borderColor:'#fff',borderWidth:4,hoverOffset:6}]},options:{responsive:true,maintainAspectRatio:false,cutout:'70%',animation:{duration:reduceMotion?0:550},plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:18,font:{weight:'600'}}},tooltip:{callbacks:{label:context=>`${context.label}: ${rupiah(context.parsed)}`}}}},plugins:[centerTextPlugin]});
    }
    const flowCanvas=document.getElementById('cashFlowChart');
    const chartDescription=document.getElementById('cashFlowChartDescription');
    let flowChart=null;
    const moneyScale={beginAtZero:true,grid:{color:'rgba(31,122,77,.08)'},ticks:{callback:value=>compactRupiah(value)}};
    function drawFlowChart(view){
        if(!flowCanvas)return;
        if(flowChart)flowChart.destroy();
        const daily=view==='daily';
        const labels=daily?reportData.daily.labels.map(value=>new Intl.DateTimeFormat('id-ID',{day:'2-digit',month:'short'}).format(new Date(`${value}T00:00:00`))):['Periode Sebelumnya','Periode Dipilih'];
        const income=daily?reportData.daily.income:reportData.comparison.income;
        const expense=daily?reportData.daily.expense:reportData.comparison.expense;
        flowChart=new Chart(flowCanvas,{type:daily?'line':'bar',data:{labels,datasets:[{label:'Pemasukan',data:income,backgroundColor:daily?'rgba(31,122,77,.12)':'#2d9d67',borderColor:'#1f7a4d',borderWidth:daily?2:0,borderRadius:daily?0:8,tension:.32,fill:daily,pointRadius:daily?3:0},{label:'Pengeluaran',data:expense,backgroundColor:daily?'rgba(218,83,65,.10)':'#da5341',borderColor:'#c14438',borderWidth:daily?2:0,borderRadius:daily?0:8,tension:.32,fill:daily,pointRadius:daily?3:0}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},animation:{duration:reduceMotion?0:450},scales:{y:moneyScale,x:{grid:{display:false}}},plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:18,font:{weight:'600'}}},tooltip:commonTooltip}},plugins:[emptyStatePlugin]});
        if(chartDescription)chartDescription.textContent=daily?'Pergerakan pemasukan dan pengeluaran setiap hari pada periode dipilih.':'Perbandingan periode dipilih dengan periode sebelumnya.';
    }
    drawFlowChart('comparison');
    document.querySelectorAll('[data-chart-view]').forEach(button=>button.addEventListener('click',()=>{
        document.querySelectorAll('[data-chart-view]').forEach(item=>{const active=item===button;item.classList.toggle('active',active);item.setAttribute('aria-pressed',active?'true':'false');});
        drawFlowChart(button.dataset.chartView);
    }));
    document.querySelectorAll('.management-jump-nav a').forEach(link=>link.addEventListener('click',event=>{
        const target=document.querySelector(link.getAttribute('href'));
        if(!target)return;
        event.preventDefault();
        if(target.tagName==='DETAILS')target.open=true;
        target.scrollIntoView({behavior:reduceMotion?'auto':'smooth',block:'start'});
    }));
})();
</script>
<?php endif; ?>
<script>
(() => {
    const explanations={
        'Kesimpulan Utama':'Total uang perusahaan yang benar-benar tersedia pada tanggal akhir laporan, yaitu bank dan kas pusat ditambah sisa uang perusahaan yang masih dipegang PIC.',
        'Total Uang Perusahaan':'Jumlah uang yang tersedia di seluruh bank, kas pusat, dan Dana PIC. Piutang belum termasuk sebelum benar-benar diterima.',
        'Bank & Kas Pusat':'Uang perusahaan yang tersimpan pada seluruh akun bank dan kas pusat yang masih aktif.',
        'Saldo Bank & Kas Pusat':'Uang perusahaan yang tersimpan pada seluruh akun bank dan kas pusat yang masih aktif.',
        'Sisa Uang di PIC':'Uang perusahaan yang sudah diberikan kepada PIC tetapi belum dipakai atau dikembalikan.',
        'Piutang Belum Diterima':'Hak tagih perusahaan yang belum menjadi uang kas karena pembayarannya belum diterima.',
        'Hutang Belum Dibayar':'Kewajiban perusahaan yang masih harus dibayarkan kepada pihak lain.',
        'Posisi Bersih':'Total uang tersedia ditambah piutang, kemudian dikurangi hutang. Angka ini bukan saldo kas.',
        'Saldo Awal Periode':'Total uang perusahaan tepat sebelum tanggal awal periode laporan.',
        'Total Pemasukan':'Seluruh uang masuk yang benar-benar menambah kas perusahaan selama periode dipilih.',
        'Total Pengeluaran':'Seluruh uang keluar yang benar-benar mengurangi kas perusahaan selama periode dipilih.',
        'Arus Kas Bersih':'Selisih total pemasukan dikurangi total pengeluaran pada periode dipilih.',
        'Arus Kas Bersih Periode':'Selisih total pemasukan dikurangi total pengeluaran pada periode dipilih.',
        'Saldo Akhir Periode':'Total uang perusahaan sampai dengan tanggal akhir periode laporan.',
        'Komposisi Uang':'Menunjukkan pembagian lokasi uang perusahaan antara bank dan kas pusat dengan Dana PIC.',
        'Pemasukan & Pengeluaran':'Bandingkan uang masuk dan keluar dengan periode sebelumnya atau lihat pergerakannya per hari.',
        'Jumlah Proyek':'Jumlah proyek yang termasuk dalam cakupan laporan.',
        'Nilai Kontrak':'Total nilai kontrak seluruh proyek yang termasuk dalam laporan.',
        'Termin Dibayar':'Total termin proyek yang sudah tercatat berstatus dibayar.',
        'Pengeluaran':'Total biaya atau pengeluaran yang tercatat dalam cakupan laporan.',
        'Data Payroll':'Jumlah baris payroll yang termasuk dalam periode laporan.',
        'Periode Payroll':'Jumlah periode penggajian yang ditampilkan dalam laporan.',
        'Gaji Pokok':'Total gaji pokok sebelum tunjangan, tambahan, dan potongan.',
        'Total Potongan':'Jumlah seluruh potongan payroll pada periode laporan.',
        'Gaji Bersih':'Jumlah gaji akhir setelah penambahan komponen pendapatan dan pengurangan potongan.',
        'Jumlah Aset':'Jumlah aset perusahaan yang tercatat dalam laporan.',
        'Harga Perolehan':'Total harga aset pada saat pertama kali diperoleh perusahaan.',
        'Nilai Saat Ini':'Total nilai aset berdasarkan nilai terkini yang tercatat.',
        'Perlu Perhatian':'Jumlah aset dengan kondisi perawatan, rusak, atau hilang.',
        'Jumlah Penjualan':'Jumlah transaksi penjualan produk dalam periode laporan.',
        'Produk Terjual':'Total kuantitas produk yang terjual dalam periode laporan.',
        'Omzet Bersih':'Total nilai penjualan bersih setelah penyesuaian transaksi.',
        'Laba':'Selisih omzet bersih dengan harga pokok penjualan.',
        'Jumlah Karyawan':'Jumlah karyawan yang tercakup dalam laporan.',
        'Karyawan Aktif':'Jumlah karyawan dengan status kerja aktif.',
        'Tidak Aktif':'Jumlah karyawan yang status kerjanya tidak aktif.',
        'Departemen':'Jumlah departemen berbeda yang memiliki data karyawan.',
        'Total Gaji Dasar':'Total nominal gaji dasar seluruh karyawan dalam laporan.'
    };
    document.addEventListener('DOMContentLoaded',()=>{
        document.querySelectorAll('.report-page .stat-card, .report-page .management-chart-card, .report-page .management-hero').forEach(card=>{
            const labelNode=card.matches('.stat-card')?card.querySelector('.stat-title'):(card.matches('.management-chart-card')?card.querySelector('h3'):card.querySelector('.management-kicker'));
            if(!labelNode||labelNode.querySelector('.card-help'))return;
            const label=labelNode.textContent.trim();
            const explanation=explanations[label]||`Angka ini merangkum ${label.toLowerCase()} berdasarkan data dan periode laporan yang sedang dipilih.`;
            const help=document.createElement('button');
            help.type='button';help.className='card-help';help.title=explanation;
            help.setAttribute('aria-label',`Penjelasan ${label}`);help.setAttribute('data-bs-toggle','tooltip');
            help.innerHTML='<i class="bi bi-info" aria-hidden="true"></i>';
            help.addEventListener('click',event=>event.stopPropagation());
            labelNode.appendChild(help);
            if(window.bootstrap?.Tooltip)new bootstrap.Tooltip(help,{customClass:'management-tooltip',trigger:'hover focus',placement:'top',container:'body'});
        });
    },{once:true});
    const finePointer=window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const reduceMotion=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if(!finePointer||reduceMotion)return;
    document.querySelectorAll('.report-page .stat-card, .report-page .management-chart-card').forEach(card=>{
        card.addEventListener('pointermove',event=>{
            const bounds=card.getBoundingClientRect();
            card.style.setProperty('--card-x',`${event.clientX-bounds.left}px`);
            card.style.setProperty('--card-y',`${event.clientY-bounds.top}px`);
        });
        card.addEventListener('pointerleave',()=>{
            card.style.removeProperty('--card-x');
            card.style.removeProperty('--card-y');
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
