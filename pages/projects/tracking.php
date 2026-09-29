<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/project_status_helper.php';

function tr_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tr_code(PDO $pdo): string {
    $prefix = 'PRJ-' . date('Ym') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE project_code LIKE :prefix");
    $stmt->execute([':prefix'=>$prefix.'%']);
    return $prefix . str_pad((string)((int)$stmt->fetchColumn()+1), 3, '0', STR_PAD_LEFT);
}
function tr_daily_label(string $status): string {
    return ['not_started'=>'Belum Dimulai','in_progress'=>'Dikerjakan','completed'=>'Selesai'][$status] ?? $status;
}
function tr_daily_class(string $status): string {
    return ['not_started'=>'discussion','in_progress'=>'process','completed'=>'done'][$status] ?? 'discussion';
}
function tr_date(?string $value): string {
    return $value ? date('d M Y', strtotime($value)) : 'Belum ditentukan';
}
function tr_money(float $value): string {
    return 'Rp ' . number_format($value, 0, ',', '.');
}

$employees = $pdo->query("SELECT id,full_name FROM employees WHERE employment_status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$error = '';
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_project') {
            $editId = (int)($_POST['edit_project_id'] ?? 0);
            $name = trim($_POST['project_name'] ?? '');
            $leader = !empty($_POST['leader_id']) ? (int)$_POST['leader_id'] : null;
            $start = $_POST['start_date'] ?: null;
            $end = $_POST['end_date'] ?: null;
            if ($name === '') throw new RuntimeException('Nama project wajib diisi.');
            if (!$start || !$end) throw new RuntimeException('Tanggal mulai dan deadline wajib diisi.');
            if (strtotime($end) < strtotime($start)) throw new RuntimeException('Deadline tidak boleh sebelum tanggal mulai.');
            if ($editId) {
                $pdo->prepare("UPDATE projects SET project_name=:name,leader_id=:leader,start_date=:start,end_date=:end,updated_at=NOW() WHERE id=:id AND deleted_at IS NULL")
                    ->execute([':name'=>$name,':leader'=>$leader,':start'=>$start,':end'=>$end,':id'=>$editId]);
                $projectId = $editId;
            } else {
                $pdo->prepare("INSERT INTO projects(project_code,project_name,leader_id,start_date,end_date,status,project_type,created_by,created_at) VALUES(:code,:name,:leader,:start,:end,'planning','one_time',:user,NOW())")
                    ->execute([':code'=>tr_code($pdo),':name'=>$name,':leader'=>$leader,':start'=>$start,':end'=>$end,':user'=>$_SESSION['user_id']??null]);
                $projectId = (int)$pdo->lastInsertId();
            }
        } elseif (in_array($action, ['add_daily','update_daily'], true)) {
            if (!$projectId) throw new RuntimeException('Pilih project terlebih dahulu.');
            $title = trim($_POST['title'] ?? '');
            $start = $_POST['daily_start'] ?: null;
            $end = $_POST['daily_end'] ?: null;
            $status = in_array($_POST['status']??'', ['not_started','in_progress','completed'], true) ? $_POST['status'] : 'not_started';
            $members = array_values(array_unique(array_filter(array_map('intval', $_POST['team_ids'] ?? []))));
            if ($title === '') throw new RuntimeException('Pekerjaan daily wajib diisi.');
            if (!$start || !$end) throw new RuntimeException('Rentang tanggal daily wajib diisi.');
            if (strtotime($end) < strtotime($start)) throw new RuntimeException('Tanggal akhir daily tidak boleh sebelum tanggal mulai.');
            if (!$members) throw new RuntimeException('Pilih minimal satu anggota tim.');
            $pdo->beginTransaction();
            if ($action === 'add_daily') {
                $stmt=$pdo->prepare("INSERT INTO project_tasks(project_id,title,description,start_at,due_at,completed_at,status,progress_percent,created_by,created_at) VALUES(:project,:title,:description,:start,:end,:completed,:status,:progress,:user,NOW())");
                $stmt->execute([':project'=>$projectId,':title'=>$title,':description'=>trim($_POST['description']??''),':start'=>$start.' 00:00:00',':end'=>$end.' 23:59:59',':completed'=>$status==='completed'?date('Y-m-d H:i:s'):null,':status'=>$status,':progress'=>$status==='completed'?100:($status==='in_progress'?50:0),':user'=>$_SESSION['user_id']??null]);
                $taskId=(int)$pdo->lastInsertId();
            } else {
                $taskId=(int)($_POST['task_id']??0);
                $stmt=$pdo->prepare("UPDATE project_tasks SET title=:title,description=:description,start_at=:start,due_at=:end,status=:status,progress_percent=:progress,completed_at=CASE WHEN :done='completed' THEN COALESCE(completed_at,NOW()) ELSE NULL END WHERE id=:id AND project_id=:project");
                $stmt->execute([':title'=>$title,':description'=>trim($_POST['description']??''),':start'=>$start.' 00:00:00',':end'=>$end.' 23:59:59',':status'=>$status,':progress'=>$status==='completed'?100:($status==='in_progress'?50:0),':done'=>$status,':id'=>$taskId,':project'=>$projectId]);
                $pdo->prepare("DELETE FROM project_task_members WHERE task_id=:id")->execute([':id'=>$taskId]);
            }
            $memberStmt=$pdo->prepare("INSERT INTO project_task_members(task_id,employee_id) VALUES(:task,:employee)");
            foreach($members as $employeeId)$memberStmt->execute([':task'=>$taskId,':employee'=>$employeeId]);
            $pdo->commit();
        } elseif ($action === 'delete_daily') {
            $stmt=$pdo->prepare("DELETE FROM project_tasks WHERE id=:id AND project_id=:project");
            $stmt->execute([':id'=>(int)($_POST['task_id']??0),':project'=>$projectId]);
        }
        if ($projectId > 0) project_sync_completion_status($pdo, $projectId);
        redirect('pages/projects/tracking.php?project_id='.$projectId.'&month='.$month.'&saved=1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}

$projects=$pdo->query("SELECT p.id,p.project_name,p.leader_id,p.start_date,p.end_date,p.status,e.full_name leader_name FROM projects p LEFT JOIN employees e ON e.id=p.leader_id WHERE p.deleted_at IS NULL AND p.status<>'cancelled' ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
if (!$projectId && $projects) $projectId=(int)$projects[0]['id'];
$project=null; foreach($projects as $row) if((int)$row['id']===$projectId)$project=$row;

$taskStmt=$pdo->prepare("SELECT t.*,GROUP_CONCAT(e.full_name ORDER BY e.full_name SEPARATOR ', ') team_names,GROUP_CONCAT(tm.employee_id ORDER BY tm.employee_id) team_ids FROM project_tasks t LEFT JOIN project_task_members tm ON tm.task_id=t.id LEFT JOIN employees e ON e.id=tm.employee_id WHERE t.project_id=:project GROUP BY t.id ORDER BY t.start_at,t.id");
$taskStmt->execute([':project'=>$projectId]);
$tasks=$taskStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentStmt=$pdo->prepare("SELECT COUNT(*) total_terms,COALESCE(SUM(status='paid'),0) paid_terms,COALESCE(SUM(amount),0) total_amount,COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid_amount FROM project_payment_terms WHERE project_id=:project");
$paymentStmt->execute([':project'=>$projectId]);
$payments=$paymentStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_terms'=>0,'paid_terms'=>0,'total_amount'=>0,'paid_amount'=>0];
$workComplete=count($tasks)>0 && count(array_filter($tasks,fn($t)=>$t['status']!=='completed'))===0;
$paymentComplete=(int)$payments['total_terms']>0 && (int)$payments['total_terms']===(int)$payments['paid_terms'];
$projectComplete=$workComplete && $paymentComplete;
$discussionCount=count(array_filter($tasks,fn($t)=>$t['status']==='not_started'));
$processCount=count(array_filter($tasks,fn($t)=>$t['status']==='in_progress'));
$doneCount=count(array_filter($tasks,fn($t)=>$t['status']==='completed'));
$trackingProgress=count($tasks)>0?round(($doneCount/count($tasks))*100):0;
$paymentTotal=(float)$payments['total_amount'];
$paymentPaid=(float)$payments['paid_amount'];
$paymentRemaining=max(0,$paymentTotal-$paymentPaid);
$paymentProgress=$paymentTotal>0?round(($paymentPaid/$paymentTotal)*100):0;

$today=date('Y-m-d');
$lastCompletedAt=null;
foreach($tasks as $task){$completionMark=$task['completed_at']?:($task['updated_at']?:$task['created_at']);if($task['status']==='completed'&&$completionMark&&($lastCompletedAt===null||$completionMark>$lastCompletedAt))$lastCompletedAt=$completionMark;}
$finishedOnTime=$workComplete&&$project&&!empty($project['end_date'])&&$lastCompletedAt&&substr($lastCompletedAt,0,10)<=$project['end_date'];
$scheduleProgress=0;$deadlineLabel='Deadline belum ditentukan';$deadlineHealth='Belum dijadwalkan';$deadlineBadge='text-bg-secondary';
if($project&&!empty($project['start_date'])&&!empty($project['end_date'])){
    $startTs=strtotime($project['start_date']);$endTs=strtotime($project['end_date']);$todayTs=strtotime($today);
    $duration=max(1,(int)floor(($endTs-$startTs)/86400)+1);
    $elapsed=$todayTs<$startTs?0:min($duration,max(0,(int)floor((min($todayTs,$endTs)-$startTs)/86400)+1));
    $scheduleProgress=(int)round(($elapsed/$duration)*100);
    $daysRemaining=(int)floor(($endTs-$todayTs)/86400);
    if(count($tasks)===0){$deadlineHealth='Belum Ada Daily';$deadlineBadge='text-bg-secondary';$deadlineLabel='Tambahkan daily untuk menghitung progres';}
    elseif($workComplete){$deadlineHealth=$finishedOnTime?'Selesai Tepat Waktu':'Selesai Terlambat';$deadlineBadge=$finishedOnTime?'text-bg-success':'text-bg-danger';$deadlineLabel=$lastCompletedAt?'Selesai '.tr_date($lastCompletedAt):'Pekerjaan selesai';}
    elseif($todayTs<$startTs){$deadlineHealth='Belum Dimulai';$deadlineBadge='text-bg-secondary';$deadlineLabel=abs((int)floor(($startTs-$todayTs)/86400)).' hari menuju mulai';}
    elseif($daysRemaining<0){$deadlineHealth='Terlambat';$deadlineBadge='text-bg-danger';$deadlineLabel=abs($daysRemaining).' hari melewati deadline';}
    elseif($trackingProgress>=$scheduleProgress){$deadlineHealth='Aman';$deadlineBadge='text-bg-success';$deadlineLabel=$daysRemaining.' hari tersisa';}
    else{$deadlineHealth='Berisiko';$deadlineBadge='text-bg-warning';$deadlineLabel=$daysRemaining.' hari tersisa';}
}

$firstDay=$month.'-01';$daysInMonth=(int)date('t',strtotime($firstDay));$offset=(int)date('N',strtotime($firstDay))-1;
$previousMonth=date('Y-m',strtotime($firstDay.' -1 month'));$nextMonth=date('Y-m',strtotime($firstDay.' +1 month'));
$monthNames=[1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
$monthTitle=$monthNames[(int)date('n',strtotime($firstDay))].' '.date('Y',strtotime($firstDay));
$pageTitle='Project Tracking - Nukari';
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<style>
.simple-track{--line:#e6eaf0;--ink:#17212b}.track-card,.calendar-wrap{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 8px 25px rgba(15,23,42,.045)}.track-card{padding:18px}.calendar-wrap{overflow:hidden}.calendar-toolbar{padding:16px 18px;border-bottom:1px solid var(--line)}.calendar-scroll{overflow-x:auto}.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(130px,1fr));min-width:910px}.weekday{padding:10px;text-align:center;background:#f8fafc;color:#64748b;font-size:12px;font-weight:800}.calendar-day{min-height:122px;padding:8px;border-top:1px solid var(--line);border-right:1px solid var(--line);cursor:pointer}.calendar-day:hover{background:#f5fbf7}.calendar-day:nth-child(7n){border-right:0}.calendar-day.empty{background:#fafafa;cursor:default}.calendar-day.today{background:#f0fdf4}.day-number{font-weight:800;font-size:13px;margin-bottom:4px}.today .day-number{display:grid;place-items:center;background:#198754;color:#fff;border-radius:50%;width:27px;height:27px}.daily-event{display:block;border:0;width:100%;padding:5px 7px;margin-top:4px;border-radius:7px;text-align:left;font-size:11px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.daily-discussion{background:#f1f5f9;color:#475569;border-left:4px solid #94a3b8}.daily-process{background:#eff6ff;color:#1d4ed8;border-left:4px solid #3b82f6}.daily-done{background:#ecfdf5;color:#167348;border-left:4px solid #22c55e}.status-box{border-radius:15px;padding:13px;background:#f8fafc}.team-checks{max-height:170px;overflow:auto;border:1px solid var(--line);border-radius:12px;padding:8px 12px}.legend-dot{display:inline-block;width:10px;height:10px;border-radius:3px}.project-complete{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}.project-incomplete{background:#fff7ed;color:#9a5410;border:1px solid #fed7aa}.metric-progress{height:9px;border-radius:99px}.metric-note{font-size:12px;color:#64748b}@media(max-width:767px){.calendar-grid{grid-template-columns:repeat(7,115px);min-width:805px}.calendar-day{min-height:108px}}
</style>
<main class="main-content simple-track">
 <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3"><div><h1 class="fw-bold mb-1">Project Tracking</h1><p class="text-muted mb-0">Kalender pekerjaan harian project.</p></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-success" href="<?=base_url('pages/projects/tracking_report.php?scope=all');?>"><i class="bi bi-printer me-1"></i> Laporan Semua</a><button class="btn btn-nukari" data-bs-toggle="modal" data-bs-target="#projectModal" data-mode="new"><i class="bi bi-plus-circle me-1"></i> Project Baru</button></div></div>
 <?php if($error):?><div class="alert alert-danger rounded-4"><?=tr_e($error);?></div><?php endif;?><?php if(!empty($_GET['saved'])):?><div class="alert alert-success rounded-4 py-2">Data berhasil disimpan.</div><?php endif;?>
 <div class="track-card mb-4"><div class="row g-3 align-items-end"><div class="col-lg-7"><form method="GET"><label class="form-label small fw-bold text-muted">PILIH PROJECT</label><div class="input-group"><select name="project_id" class="form-select" onchange="this.form.submit()"><?php foreach($projects as $item):?><option value="<?=$item['id'];?>" <?=$projectId===(int)$item['id']?'selected':'';?>><?=tr_e($item['project_name']);?></option><?php endforeach;?></select><input type="hidden" name="month" value="<?=tr_e($month);?>"></div></form></div><div class="col-lg-5 text-lg-end"><?php if($project):?><div class="btn-group"><a class="btn btn-outline-success" href="<?=base_url('pages/projects/tracking_report.php?scope=project&project_id='.$projectId);?>"><i class="bi bi-printer me-1"></i> Print</a><a class="btn btn-outline-success" href="<?=base_url('pages/projects/tracking_report.php?scope=project&project_id='.$projectId.'&format=csv');?>"><i class="bi bi-download me-1"></i> Export</a><button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#projectModal" data-mode="edit" data-project='<?=tr_e(json_encode($project,JSON_UNESCAPED_UNICODE));?>'><i class="bi bi-pencil me-1"></i> Edit</button></div><?php endif;?></div></div></div>
 <?php if(!$project):?><div class="track-card text-center py-5 text-muted">Belum ada project. Klik <b>Project Baru</b> untuk mulai.</div><?php else:?>
 <div class="row g-3 mb-4"><div class="col-md-4"><div class="status-box h-100"><small class="text-muted">PIC PROJECT</small><div class="fw-bold mt-1"><i class="bi bi-person-badge me-1"></i><?=tr_e($project['leader_name']?:'Belum dipilih');?></div></div></div><div class="col-md-4"><div class="status-box h-100"><small class="text-muted">PERIODE PROJECT</small><div class="fw-bold mt-1"><?=tr_date($project['start_date']);?> — <?=tr_date($project['end_date']);?></div></div></div><div class="col-md-4"><div class="status-box h-100 <?=$projectComplete?'project-complete':'project-incomplete';?>"><small>STATUS PROJECT</small><div class="fw-bold mt-1"><?=$projectComplete?'COMPLETE':'BELUM COMPLETE';?></div><small><?=$workComplete?'Pekerjaan selesai':'Pekerjaan belum selesai';?> · <?=$paymentComplete?'Pembayaran lunas':'Pembayaran belum lunas';?></small></div></div></div>
 <div class="row g-3 mb-4"><div class="col-lg-3 col-6"><div class="track-card h-100"><small class="text-muted">TOTAL DAILY</small><div class="fs-3 fw-bold"><?=count($tasks);?></div></div></div><div class="col-lg-3 col-6"><div class="track-card h-100"><small class="text-muted">BELUM DIMULAI</small><div class="fs-3 fw-bold text-secondary"><?=$discussionCount;?></div></div></div><div class="col-lg-3 col-6"><div class="track-card h-100"><small class="text-muted">DIKERJAKAN</small><div class="fs-3 fw-bold text-primary"><?=$processCount;?></div></div></div><div class="col-lg-3 col-6"><div class="track-card h-100"><small class="text-muted">SELESAI</small><div class="fs-3 fw-bold text-success"><?=$doneCount;?></div><div class="progress mt-1" style="height:6px"><div class="progress-bar bg-success" style="width:<?=$trackingProgress;?>%"></div></div></div></div></div>
 <div class="row g-3 mb-4"><div class="col-lg-6"><div class="track-card h-100"><div class="d-flex justify-content-between align-items-start gap-3"><div><small class="text-muted">PROGRES PEKERJAAN</small><div class="fs-3 fw-bold"><?=$trackingProgress;?>%</div></div><span class="badge <?=$deadlineBadge;?> rounded-pill"><?=tr_e($deadlineHealth);?></span></div><div class="progress metric-progress mt-2"><div class="progress-bar bg-success" style="width:<?=$trackingProgress;?>%"></div></div><div class="d-flex justify-content-between gap-3 mt-2 metric-note"><span><?=$doneCount;?> dari <?=count($tasks);?> daily selesai</span><span><?=tr_e($deadlineLabel);?></span></div><div class="d-flex justify-content-between gap-3 mt-2 metric-note"><span>Waktu terpakai</span><strong><?=$scheduleProgress;?>%</strong></div></div></div><div class="col-lg-6"><div class="track-card h-100"><small class="text-muted">PROGRES PEMBAYARAN</small><div class="d-flex justify-content-between align-items-end gap-3"><div class="fs-3 fw-bold"><?=$paymentProgress;?>%</div><small class="text-muted"><?=(int)$payments['paid_terms'];?> dari <?=(int)$payments['total_terms'];?> termin lunas</small></div><div class="progress metric-progress mt-2"><div class="progress-bar bg-primary" style="width:<?=$paymentProgress;?>%"></div></div><div class="d-flex justify-content-between gap-3 mt-2 metric-note"><span>Terbayar: <strong class="text-success"><?=tr_money($paymentPaid);?></strong></span><span>Tersisa: <strong><?=tr_money($paymentRemaining);?></strong></span></div><?php if((int)$payments['total_terms']===0):?><div class="metric-note mt-2">Termin pembayaran belum dibuat.</div><?php endif;?></div></div></div>
 <div class="calendar-wrap"><div class="calendar-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2"><div class="btn-group"><a class="btn btn-light border" href="?project_id=<?=$projectId;?>&month=<?=$previousMonth;?>"><i class="bi bi-chevron-left"></i></a><a class="btn btn-light border" href="?project_id=<?=$projectId;?>&month=<?=date('Y-m');?>">Hari Ini</a><a class="btn btn-light border" href="?project_id=<?=$projectId;?>&month=<?=$nextMonth;?>"><i class="bi bi-chevron-right"></i></a></div><h4 class="fw-bold mb-0"><?=tr_e($monthTitle);?></h4><small class="text-muted"><i class="bi bi-cursor me-1"></i>Klik tanggal untuk menambah daily</small></div><div class="calendar-scroll"><div class="calendar-grid"><?php foreach(['Sen','Sel','Rab','Kam','Jum','Sab','Min'] as $dayName):?><div class="weekday"><?=$dayName;?></div><?php endforeach;?><?php for($i=0;$i<$offset;$i++):?><div class="calendar-day empty"></div><?php endfor;?><?php for($day=1;$day<=$daysInMonth;$day++):$date=$month.'-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);?><div class="calendar-day <?=$date===date('Y-m-d')?'today':'';?>" data-date="<?=$date;?>" data-bs-toggle="modal" data-bs-target="#dailyModal"><div class="day-number"><?=$day;?></div><?php foreach($tasks as $task):$start=substr($task['start_at'],0,10);$end=substr($task['due_at'],0,10);if($date<$start||$date>$end)continue;?><button type="button" class="daily-event daily-<?=tr_daily_class($task['status']);?> js-edit-daily" data-bs-toggle="modal" data-bs-target="#dailyModal" data-task='<?=tr_e(json_encode($task,JSON_UNESCAPED_UNICODE));?>'><?=tr_e($task['title']);?></button><?php endforeach;?></div><?php endfor;?><?php $used=$offset+$daysInMonth;for($i=$used;$i<ceil($used/7)*7;$i++):?><div class="calendar-day empty"></div><?php endfor;?></div></div></div>
 <div class="d-flex flex-wrap gap-3 mt-3 small text-muted"><span><i class="legend-dot me-1" style="background:#94a3b8"></i>Belum Dimulai</span><span><i class="legend-dot me-1" style="background:#3b82f6"></i>Dikerjakan</span><span><i class="legend-dot me-1" style="background:#22c55e"></i>Selesai</span></div>
 <div class="track-card mt-4"><div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="fw-bold mb-1">Daftar Daily Project</h5><small class="text-muted">Ringkasan lengkap pekerjaan, tim, rentang tanggal, dan status.</small></div><span class="badge bg-success-subtle text-success rounded-pill"><?=$trackingProgress;?>% selesai</span></div><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Pekerjaan</th><th>Rentang Tanggal</th><th>Tim</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($tasks as $task):?><tr><td><b><?=tr_e($task['title']);?></b><br><small class="text-muted"><?=tr_e($task['description']?:'-');?></small></td><td><?=tr_date($task['start_at']);?> — <?=tr_date($task['due_at']);?></td><td><?=tr_e($task['team_names']?:'-');?></td><td><span class="badge daily-<?=tr_daily_class($task['status']);?>"><?=tr_daily_label($task['status']);?></span></td><td><button class="btn btn-sm btn-outline-secondary js-edit-daily" data-bs-toggle="modal" data-bs-target="#dailyModal" data-task='<?=tr_e(json_encode($task,JSON_UNESCAPED_UNICODE));?>'><i class="bi bi-pencil"></i></button></td></tr><?php endforeach;?><?php if(!$tasks):?><tr><td colspan="5" class="text-center text-muted py-4">Belum ada daily project.</td></tr><?php endif;?></tbody></table></div></div>
 <?php endif;?>
</main>

<div class="modal fade" id="projectModal"><div class="modal-dialog modal-dialog-centered"><form method="POST" class="modal-content rounded-4 border-0"><div class="modal-header"><h5 class="modal-title fw-bold" id="projectModalTitle">Project Baru</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body row g-3"><?=csrf_field();?><input type="hidden" name="action" value="save_project"><input type="hidden" name="project_id" value="<?=$projectId;?>"><input type="hidden" name="edit_project_id" id="editProjectId"><div class="col-12"><label class="form-label">Nama Project</label><input name="project_name" id="projectName" class="form-control" required></div><div class="col-12"><label class="form-label">PIC Project</label><select name="leader_id" id="projectLeader" class="form-select"><option value="">Pilih PIC</option><?php foreach($employees as $employee):?><option value="<?=$employee['id'];?>"><?=tr_e($employee['full_name']);?></option><?php endforeach;?></select></div><div class="col-6"><label class="form-label">Tanggal Mulai</label><input type="date" name="start_date" id="projectStart" class="form-control" required></div><div class="col-6"><label class="form-label">Deadline</label><input type="date" name="end_date" id="projectEnd" class="form-control" required></div></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari">Simpan</button></div></form></div></div>

<div class="modal fade" id="dailyModal"><div class="modal-dialog modal-lg modal-dialog-centered"><form method="POST" class="modal-content rounded-4 border-0"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="dailyModalTitle">Tambah Daily</h5><small class="text-muted">Pekerjaan yang dilakukan dalam rentang tanggal.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body row g-3"><?=csrf_field();?><input type="hidden" name="action" id="dailyAction" value="add_daily"><input type="hidden" name="project_id" value="<?=$projectId;?>"><input type="hidden" name="task_id" id="dailyTaskId"><div class="col-12"><label class="form-label">Yang Dikerjakan</label><input name="title" id="dailyTitle" class="form-control" required placeholder="Contoh: Pembahasan layout dan revisi desain"></div><div class="col-md-4"><label class="form-label">Tanggal Mulai</label><input type="date" name="daily_start" id="dailyStart" class="form-control" required></div><div class="col-md-4"><label class="form-label">Tanggal Selesai</label><input type="date" name="daily_end" id="dailyEnd" class="form-control" required></div><div class="col-md-4"><label class="form-label">Status</label><select name="status" id="dailyStatus" class="form-select"><option value="not_started">Belum Dimulai</option><option value="in_progress">Dikerjakan</option><option value="completed">Selesai</option></select></div><div class="col-md-6"><label class="form-label">Tim yang Mengerjakan</label><div class="team-checks"><?php foreach($employees as $employee):?><label class="d-block py-1"><input type="checkbox" class="form-check-input me-2 team-check" name="team_ids[]" value="<?=$employee['id'];?>"><?=tr_e($employee['full_name']);?></label><?php endforeach;?></div></div><div class="col-md-6"><label class="form-label">Catatan (opsional)</label><textarea name="description" id="dailyDescription" class="form-control" rows="6"></textarea></div></div><div class="modal-footer justify-content-between"><button type="submit" name="action" value="delete_daily" id="deleteDaily" class="btn btn-outline-danger d-none" onclick="return confirm('Hapus daily ini?')"><i class="bi bi-trash"></i> Hapus</button><div class="ms-auto d-flex gap-2"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari">Simpan Daily</button></div></div></form></div></div>

<script>
document.getElementById('projectModal')?.addEventListener('show.bs.modal',function(event){const data=event.relatedTarget?.dataset.project?JSON.parse(event.relatedTarget.dataset.project):null;document.getElementById('projectModalTitle').textContent=data?'Edit Project':'Project Baru';document.getElementById('editProjectId').value=data?.id||'';document.getElementById('projectName').value=data?.project_name||'';document.getElementById('projectLeader').value=data?.leader_id||'';document.getElementById('projectStart').value=data?.start_date||'';document.getElementById('projectEnd').value=data?.end_date||'';});
document.getElementById('dailyModal')?.addEventListener('show.bs.modal',function(event){const raw=event.relatedTarget?.dataset.task;const task=raw?JSON.parse(raw):null;const date=event.relatedTarget?.dataset.date||'';document.getElementById('dailyModalTitle').textContent=task?'Edit Daily':'Tambah Daily';document.getElementById('dailyAction').value=task?'update_daily':'add_daily';document.getElementById('dailyTaskId').value=task?.id||'';document.getElementById('dailyTitle').value=task?.title||'';document.getElementById('dailyStart').value=task?task.start_at.slice(0,10):date;document.getElementById('dailyEnd').value=task?task.due_at.slice(0,10):date;document.getElementById('dailyStatus').value=task?.status||'not_started';document.getElementById('dailyDescription').value=task?.description||'';document.getElementById('deleteDaily').classList.toggle('d-none',!task);const ids=(task?.team_ids||'').split(',');document.querySelectorAll('.team-check').forEach(box=>box.checked=ids.includes(box.value));});
</script>
<?php require_once __DIR__.'/../../includes/footer.php';?>
