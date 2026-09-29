<?php
require_once __DIR__ . '/../../includes/auth.php';

$scope = ($_GET['scope'] ?? 'project') === 'all' ? 'all' : 'project';
$projectId = (int)($_GET['project_id'] ?? 0);
$format = $_GET['format'] ?? 'print';
$where = "p.deleted_at IS NULL AND p.status <> 'cancelled'";
$params = [];
if ($scope === 'project') {
    if (!$projectId) redirect('pages/projects/tracking.php');
    $where .= " AND p.id=:project_id";
    $params[':project_id'] = $projectId;
}

$projectStmt = $pdo->prepare("SELECT p.*,e.full_name leader_name,
    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id=p.id) total_daily,
    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id=p.id AND t.status='not_started') discussion_daily,
    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id=p.id AND t.status='in_progress') process_daily,
    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id=p.id AND t.status='completed') done_daily,
    (SELECT MAX(COALESCE(t.completed_at,t.updated_at,t.created_at)) FROM project_tasks t WHERE t.project_id=p.id AND t.status='completed') actual_completed_at,
    (SELECT COUNT(*) FROM project_payment_terms pt WHERE pt.project_id=p.id) total_terms,
    (SELECT COUNT(*) FROM project_payment_terms pt WHERE pt.project_id=p.id AND pt.status='paid') paid_terms,
    (SELECT COALESCE(SUM(pt.amount),0) FROM project_payment_terms pt WHERE pt.project_id=p.id) total_payment_amount,
    (SELECT COALESCE(SUM(CASE WHEN pt.status='paid' THEN pt.amount ELSE 0 END),0) FROM project_payment_terms pt WHERE pt.project_id=p.id) paid_payment_amount
    FROM projects p LEFT JOIN employees e ON e.id=p.leader_id WHERE $where ORDER BY p.project_name");
$projectStmt->execute($params);
$projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);

$ids = array_map('intval', array_column($projects,'id'));
$dailyByProject = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0,count($ids),'?'));
    $stmt = $pdo->prepare("SELECT t.*,p.project_name,GROUP_CONCAT(e.full_name ORDER BY e.full_name SEPARATOR ', ') team_names FROM project_tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN project_task_members tm ON tm.task_id=t.id LEFT JOIN employees e ON e.id=tm.employee_id WHERE t.project_id IN ($placeholders) GROUP BY t.id ORDER BY p.project_name,t.start_at,t.id");
    $stmt->execute($ids);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $daily) $dailyByProject[(int)$daily['project_id']][]=$daily;
}
function report_status(string $status): string { return ['not_started'=>'Belum Dimulai','in_progress'=>'Dikerjakan','completed'=>'Selesai'][$status]??$status; }
function report_date($value): string { return $value?date('d M Y',strtotime($value)):'Belum ditentukan'; }
function report_complete(array $p): bool { return (int)$p['total_daily']>0 && (int)$p['total_daily']===(int)$p['done_daily'] && (int)$p['total_terms']>0 && (int)$p['total_terms']===(int)$p['paid_terms']; }
function report_money($value): string { return 'Rp '.number_format((float)$value,0,',','.'); }
function report_schedule_health(array $p): string {
    $total=(int)$p['total_daily'];$done=(int)$p['done_daily'];$work=$total>0?round($done/$total*100):0;
    if($total===0)return 'Belum Ada Daily';
    if($total>0&&$done===$total){return !empty($p['actual_completed_at'])&&!empty($p['end_date'])&&substr($p['actual_completed_at'],0,10)<=$p['end_date']?'Selesai Tepat Waktu':'Selesai Terlambat';}
    if(empty($p['start_date'])||empty($p['end_date']))return 'Belum Dijadwalkan';
    $today=strtotime(date('Y-m-d'));$start=strtotime($p['start_date']);$end=strtotime($p['end_date']);
    if($today<$start)return 'Belum Dimulai';
    if($today>$end)return 'Terlambat';
    $duration=max(1,(int)floor(($end-$start)/86400)+1);$elapsed=min($duration,max(0,(int)floor(($today-$start)/86400)+1));$time=round($elapsed/$duration*100);
    return $work>=$time?'Aman':'Berisiko';
}

if ($format === 'csv') {
    $name=$scope==='all'?'semua-project':'project-'.($projects[0]['project_code']??$projectId);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tracking-'.$name.'-'.date('Ymd').'.csv"');
    header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Project','PIC','Mulai','Deadline','Kondisi Jadwal','Status Project','Progres Pekerjaan','Termin Terbayar','Sisa Termin','Pekerjaan Daily','Mulai Daily','Selesai Daily','Status Daily','Tim','Catatan'],';');
    foreach($projects as $project){$dailyRows=$dailyByProject[(int)$project['id']]??[null];$work=(int)$project['total_daily']>0?round((int)$project['done_daily']/(int)$project['total_daily']*100):0;$remaining=max(0,(float)$project['total_payment_amount']-(float)$project['paid_payment_amount']);foreach($dailyRows as $daily){fputcsv($out,[$project['project_name'],$project['leader_name']?:'-',$project['start_date'],$project['end_date'],report_schedule_health($project),report_complete($project)?'Complete':'Belum Complete',$work.'%',(float)$project['paid_payment_amount'],$remaining,$daily['title']??'-',isset($daily['start_at'])?substr($daily['start_at'],0,10):'-',isset($daily['due_at'])?substr($daily['due_at'],0,10):'-',isset($daily['status'])?report_status($daily['status']):'-',$daily['team_names']??'-',$daily['description']??'-'],';');}}
    fclose($out);exit;
}
$title=$scope==='all'?'Dashboard Progres Seluruh Project':'Laporan Progres Project';
$aggregate=['projects'=>count($projects),'complete'=>0,'discussion'=>0,'process'=>0,'done'=>0,'daily'=>0,'paid_terms'=>0,'terms'=>0,'paid_amount'=>0.0,'payment_amount'=>0.0];
foreach($projects as $projectRow){
    $aggregate['complete']+=report_complete($projectRow)?1:0;
    $aggregate['discussion']+=(int)$projectRow['discussion_daily'];
    $aggregate['process']+=(int)$projectRow['process_daily'];
    $aggregate['done']+=(int)$projectRow['done_daily'];
    $aggregate['daily']+=(int)$projectRow['total_daily'];
    $aggregate['paid_terms']+=(int)$projectRow['paid_terms'];
    $aggregate['terms']+=(int)$projectRow['total_terms'];
    $aggregate['paid_amount']+=(float)$projectRow['paid_payment_amount'];
    $aggregate['payment_amount']+=(float)$projectRow['total_payment_amount'];
}
$overallWork=$aggregate['daily']>0?round($aggregate['done']/$aggregate['daily']*100):0;
$overallPayment=$aggregate['payment_amount']>0?round($aggregate['paid_amount']/$aggregate['payment_amount']*100):0;
$backUrl = base_url('pages/projects/tracking.php' . ($scope === 'project' && $projectId ? '?project_id=' . $projectId : ''));
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($title);?></title><style>
*{box-sizing:border-box}body{margin:0;background:#f3f5f7;color:#17212b;font:14px Arial,sans-serif}.sheet{max-width:1200px;margin:24px auto;background:white;padding:34px;border-radius:18px}.header{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #198754;padding-bottom:18px;margin-bottom:24px}.brand h1{margin:0 0 6px;font-size:26px}.muted{color:#667085}.actions{display:flex;gap:8px}.btn{border:1px solid #198754;background:white;color:#157347;border-radius:8px;padding:10px 14px;text-decoration:none;cursor:pointer}.btn.primary{background:#198754;color:white}.project{page-break-inside:avoid;margin-bottom:30px;border:1px solid #dfe5ea;border-radius:14px;overflow:hidden}.project-head{background:#f7faf8;padding:18px}.project-title{font-size:20px;font-weight:bold;margin-bottom:10px}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.meta-box,.summary-card{background:white;border:1px solid #e6eaee;border-radius:12px;padding:13px}.meta-box small,.summary-card small{display:block;color:#6b7280;margin-bottom:4px}.complete{color:#15803d}.incomplete{color:#b45309}.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}.summary-card strong{font-size:25px}.chart-layout{display:grid;grid-template-columns:220px 1fr;gap:28px;align-items:center;padding:22px}.donut{--value:0;width:170px;height:170px;border-radius:50%;background:conic-gradient(#22a06b calc(var(--value)*1%),#e8edf1 0);display:grid;place-items:center;position:relative;margin:auto}.donut:after{content:'';position:absolute;inset:20px;background:#fff;border-radius:50%}.donut-content{position:relative;z-index:1;text-align:center}.donut-content strong{font-size:30px;display:block}.bar-row{margin:14px 0}.bar-label{display:flex;justify-content:space-between;margin-bottom:6px;font-size:13px}.bar{height:12px;background:#edf1f4;border-radius:20px;overflow:hidden}.bar span{display:block;height:100%;border-radius:20px}.green{background:#22a06b}.blue{background:#3b82f6}.orange{background:#f59e0b}.gray{background:#94a3b8}.project-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}.project-progress-card{border:1px solid #dfe5ea;border-radius:14px;padding:17px;page-break-inside:avoid}.project-progress-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:14px}.progress-line{height:9px;background:#edf1f4;border-radius:20px;overflow:hidden;margin:6px 0 12px}.progress-line span{height:100%;display:block;border-radius:20px}.section-title{font-size:17px;margin:24px 0 12px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-top:1px solid #e5e7eb;text-align:left;vertical-align:top}th{background:#fafafa;font-size:12px;color:#667085}.badge{display:inline-block;padding:4px 8px;border-radius:99px;font-size:11px;font-weight:bold}.Belum-Dimulai{background:#f1f5f9;color:#475569}.Dikerjakan{background:#eff6ff;color:#1d4ed8}.Selesai{background:#ecfdf5;color:#167348}.footer{text-align:center;color:#98a2b3;font-size:11px;margin-top:22px}@media(max-width:700px){.sheet{margin:0;padding:18px;border-radius:0}.header{display:block}.actions{margin-top:15px}.meta,.summary-grid,.project-grid{grid-template-columns:1fr 1fr}.chart-layout{grid-template-columns:1fr}}@media print{body{background:white}.sheet{max-width:none;margin:0;padding:0;border-radius:0}.actions{display:none}.project{break-inside:avoid}.header{margin-top:0}@page{size:A4 landscape;margin:12mm}}
body{font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",Arial,sans-serif;font-size:14px;line-height:1.47;-webkit-font-smoothing:antialiased;letter-spacing:-.01em}h1,h2,h3,.project-title{letter-spacing:-.025em}
</style></head><body><div class="sheet"><div class="header"><div class="brand"><h1><?=htmlspecialchars($title);?></h1><div class="muted">PT Nukari Kriya Raya · Dicetak <?=date('d M Y H:i');?></div></div><div class="actions"><a class="btn" href="<?=htmlspecialchars($backUrl);?>">← Kembali</a><a class="btn" href="tracking_report.php?scope=<?=$scope;?><?=$scope==='project'?'&project_id='.$projectId:'';?>&format=csv">Export CSV</a><button class="btn primary" onclick="window.print()">Print / Simpan PDF</button></div></div>
<?php if($scope==='all'): ?>
<div class="summary-grid"><div class="summary-card"><small>Total Project</small><strong><?=$aggregate['projects'];?></strong></div><div class="summary-card"><small>Project Complete</small><strong class="complete"><?=$aggregate['complete'];?></strong></div><div class="summary-card"><small>Progres Pekerjaan</small><strong><?=$overallWork;?>%</strong></div><div class="summary-card"><small>Progres Pembayaran</small><strong><?=$overallPayment;?>%</strong></div></div>
<section class="project"><div class="chart-layout"><div class="donut" style="--value:<?=$overallWork;?>"><div class="donut-content"><strong><?=$overallWork;?>%</strong><span class="muted">Progres Total</span></div></div><div><div class="bar-row"><div class="bar-label"><span>Selesai</span><b><?=$aggregate['done'];?> daily</b></div><div class="bar"><span class="green" style="width:<?=$aggregate['daily']?round($aggregate['done']/$aggregate['daily']*100):0;?>%"></span></div></div><div class="bar-row"><div class="bar-label"><span>Dikerjakan</span><b><?=$aggregate['process'];?> daily</b></div><div class="bar"><span class="blue" style="width:<?=$aggregate['daily']?round($aggregate['process']/$aggregate['daily']*100):0;?>%"></span></div></div><div class="bar-row"><div class="bar-label"><span>Belum Dimulai</span><b><?=$aggregate['discussion'];?> daily</b></div><div class="bar"><span class="gray" style="width:<?=$aggregate['daily']?round($aggregate['discussion']/$aggregate['daily']*100):0;?>%"></span></div></div><div class="bar-row"><div class="bar-label"><span>Pembayaran</span><b><?=report_money($aggregate['paid_amount']);?> / <?=report_money($aggregate['payment_amount']);?></b></div><div class="bar"><span class="green" style="width:<?=$overallPayment;?>%"></span></div></div></div></div></section>
<h2 class="section-title">Progres Setiap Project</h2><div class="project-grid"><?php foreach($projects as $project):$complete=report_complete($project);$work=(int)$project['total_daily']>0?round((int)$project['done_daily']/(int)$project['total_daily']*100):0;$pay=(float)$project['total_payment_amount']>0?round((float)$project['paid_payment_amount']/(float)$project['total_payment_amount']*100):0;?><div class="project-progress-card"><div class="project-progress-head"><div><b><?=htmlspecialchars($project['project_name']);?></b><div class="muted" style="font-size:12px"><?=htmlspecialchars($project['leader_name']?:'PIC belum dipilih');?> · <?=report_date($project['end_date']);?> · <?=htmlspecialchars(report_schedule_health($project));?></div></div><b class="<?=$complete?'complete':'incomplete';?>"><?=$complete?'COMPLETE':'BERJALAN';?></b></div><div class="bar-label"><span>Pekerjaan</span><b><?=$work;?>%</b></div><div class="progress-line"><span class="green" style="width:<?=$work;?>%"></span></div><div class="bar-label"><span>Pembayaran</span><b><?=$pay;?>%</b></div><div class="progress-line"><span class="blue" style="width:<?=$pay;?>%"></span></div><div class="muted" style="font-size:12px"><?=report_money($project['paid_payment_amount']);?> terbayar · <?=report_money(max(0,(float)$project['total_payment_amount']-(float)$project['paid_payment_amount']));?> tersisa</div></div><?php endforeach;?></div>
<?php else: ?>
<?php foreach($projects as $project):$complete=report_complete($project);$dailyRows=array_values(array_filter($dailyByProject[(int)$project['id']]??[],fn($daily)=>$daily['status']==='completed'));$progress=(int)$project['total_daily']>0?round(((int)$project['done_daily']/(int)$project['total_daily'])*100):0;$paymentProgress=(float)$project['total_payment_amount']>0?round((float)$project['paid_payment_amount']/(float)$project['total_payment_amount']*100):0;$paymentRemaining=max(0,(float)$project['total_payment_amount']-(float)$project['paid_payment_amount']);?><section class="project"><div class="project-head"><div class="project-title"><?=htmlspecialchars($project['project_name']);?></div><div class="meta"><div class="meta-box"><small>PIC Project</small><b><?=htmlspecialchars($project['leader_name']?:'Belum dipilih');?></b></div><div class="meta-box"><small>Periode</small><b><?=report_date($project['start_date']);?> — <?=report_date($project['end_date']);?></b></div><div class="meta-box"><small>Pekerjaan Selesai</small><b><?=$project['done_daily'];?> / <?=$project['total_daily'];?> daily</b></div><div class="meta-box"><small>Kondisi Jadwal</small><b><?=htmlspecialchars(report_schedule_health($project));?></b></div></div></div><div class="chart-layout"><div class="donut" style="--value:<?=$progress;?>"><div class="donut-content"><strong><?=$progress;?>%</strong><span class="muted">Pekerjaan</span></div></div><div><div class="bar-row"><div class="bar-label"><span>Progres Pekerjaan</span><b><?=$progress;?>%</b></div><div class="bar"><span class="green" style="width:<?=$progress;?>%"></span></div></div><div class="bar-row"><div class="bar-label"><span>Progres Pembayaran</span><b><?=$paymentProgress;?>% · <?=report_money($project['paid_payment_amount']);?> terbayar · <?=report_money($paymentRemaining);?> tersisa</b></div><div class="bar"><span class="blue" style="width:<?=$paymentProgress;?>%"></span></div></div><div class="bar-row"><div class="bar-label"><span>Belum Dimulai</span><b><?=$project['discussion_daily'];?></b></div><div class="bar"><span class="gray" style="width:<?=(int)$project['total_daily']?round((int)$project['discussion_daily']/(int)$project['total_daily']*100):0;?>%"></span></div></div><div class="bar-row"><div class="bar-label"><span>Dikerjakan</span><b><?=$project['process_daily'];?></b></div><div class="bar"><span class="blue" style="width:<?=(int)$project['total_daily']?round((int)$project['process_daily']/(int)$project['total_daily']*100):0;?>%"></span></div></div></div></div><h2 class="section-title" style="padding:0 18px">Daftar Pekerjaan yang Sudah Selesai</h2><table><thead><tr><th>Pekerjaan</th><th>Rentang Tanggal</th><th>Tim</th><th>Catatan / Hasil</th></tr></thead><tbody><?php foreach($dailyRows as $daily):?><tr><td><b><?=htmlspecialchars($daily['title']);?></b></td><td><?=report_date($daily['start_at']);?> — <?=report_date($daily['due_at']);?></td><td><?=htmlspecialchars($daily['team_names']?:'-');?></td><td><?=nl2br(htmlspecialchars($daily['description']?:'-'));?></td></tr><?php endforeach;?><?php if(!$dailyRows):?><tr><td colspan="4" class="muted">Belum ada pekerjaan yang selesai.</td></tr><?php endif;?></tbody></table></section><?php endforeach;?>
<?php endif; ?>
<?php if(!$projects):?><p class="muted">Tidak ada project untuk ditampilkan.</p><?php endif;?><div class="footer">Nukari Management System · Project Tracking Meeting Report</div></div></body></html>
