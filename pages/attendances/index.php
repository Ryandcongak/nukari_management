<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$pageTitle = 'Data Absensi - Nukari';

$filter_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$success = $_SESSION['attendance_success'] ?? ''; unset($_SESSION['attendance_success']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action=$_POST['action'] ?? 'save';
        if ($action==='delete') {
            $id=(int)($_POST['id'] ?? 0);
            $stmt=$pdo->prepare("SELECT a.id,a.attendance_date,e.full_name FROM attendances a JOIN employees e ON e.id=a.employee_id WHERE a.id=:id");$stmt->execute([':id'=>$id]);$old=$stmt->fetch();
            if(!$old) throw new RuntimeException('Data absensi tidak ditemukan.');
            $pdo->prepare("DELETE FROM attendances WHERE id=:id")->execute([':id'=>$id]);
            log_activity($pdo,'Absensi','DELETE','Menghapus absensi '.$old['full_name'].' tanggal '.$old['attendance_date'],$id,'attendances');
            $_SESSION['attendance_success']='Data absensi berhasil dihapus.'; redirect('pages/attendances/index.php?date='.$old['attendance_date']);
        }
        $id=(int)($_POST['id'] ?? 0); $employeeId=(int)($_POST['employee_id'] ?? 0); $date=$_POST['attendance_date'] ?? '';
        if($employeeId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new RuntimeException('Karyawan dan tanggal wajib diisi.');
        $employee=$pdo->prepare("SELECT full_name FROM employees WHERE id=:id AND employment_status='active'");$employee->execute([':id'=>$employeeId]);$employeeName=$employee->fetchColumn();
        if(!$employeeName) throw new RuntimeException('Karyawan aktif tidak ditemukan.');
        $calculated=attendance_calculate($_POST); $notes=trim($_POST['notes'] ?? '');
        $duplicate=$pdo->prepare("SELECT id FROM attendances WHERE employee_id=:employee AND attendance_date=:date AND id<>:id");$duplicate->execute([':employee'=>$employeeId,':date'=>$date,':id'=>$id]);
        if($duplicate->fetchColumn()) throw new RuntimeException('Karyawan sudah memiliki absensi pada tanggal tersebut. Silakan edit data yang ada.');
        $params=[':employee_id'=>$employeeId,':attendance_date'=>$date,':morning_in'=>$calculated['morning_in'],':morning_out'=>$calculated['morning_out'],':afternoon_in'=>$calculated['afternoon_in'],':afternoon_out'=>$calculated['afternoon_out'],':overtime_in'=>$calculated['overtime_in'],':overtime_out'=>$calculated['overtime_out'],':check_in'=>$calculated['check_in'],':check_out'=>$calculated['check_out'],':late_minutes'=>$calculated['late_minutes'],':early_leave_minutes'=>$calculated['early_leave_minutes'],':work_minutes'=>$calculated['work_minutes'],':status'=>$calculated['status'],':notes'=>$notes?:null];
        if($id>0){
            $params[':id']=$id;$stmt=$pdo->prepare("UPDATE attendances SET employee_id=:employee_id,attendance_date=:attendance_date,morning_in=:morning_in,morning_out=:morning_out,afternoon_in=:afternoon_in,afternoon_out=:afternoon_out,overtime_in=:overtime_in,overtime_out=:overtime_out,check_in=:check_in,check_out=:check_out,late_minutes=:late_minutes,early_leave_minutes=:early_leave_minutes,work_minutes=:work_minutes,status=:status,notes=:notes,source='manual_edit',updated_at=NOW() WHERE id=:id");$stmt->execute($params);$verb='diperbarui';$logAction='UPDATE';
        } else {
            $stmt=$pdo->prepare("INSERT INTO attendances (employee_id,attendance_date,morning_in,morning_out,afternoon_in,afternoon_out,overtime_in,overtime_out,check_in,check_out,late_minutes,early_leave_minutes,work_minutes,status,notes,source) VALUES (:employee_id,:attendance_date,:morning_in,:morning_out,:afternoon_in,:afternoon_out,:overtime_in,:overtime_out,:check_in,:check_out,:late_minutes,:early_leave_minutes,:work_minutes,:status,:notes,'manual')");$stmt->execute($params);$id=(int)$pdo->lastInsertId();$verb='ditambahkan';$logAction='CREATE';
        }
        log_activity($pdo,'Absensi',$logAction,'Absensi '.$employeeName.' tanggal '.$date.' '.$verb,$id,'attendances');
        $_SESSION['attendance_success']='Data absensi berhasil '.$verb.'.';redirect('pages/attendances/index.php?date='.$date);
    } catch(Throwable $e){$error=$e->getMessage();}
}

$employees=$pdo->query("SELECT id,employee_code,full_name,department FROM employees WHERE employment_status='active' ORDER BY full_name")->fetchAll();

function format_time_attendance($time)
{
    return !empty($time) ? date('H:i', strtotime($time)) : '-';
}

function format_minutes_attendance($minutes)
{
    $minutes = (int) $minutes;

    if ($minutes <= 0) {
        return '-';
    }

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    if ($hours > 0) {
        return $hours . ' jam ' . $mins . ' menit';
    }

    return $mins . ' menit';
}

$stmt = $pdo->prepare("
    SELECT 
        a.*,
        e.full_name,
        e.employee_code,
        e.fingerprint_id,
        e.position,
        e.department
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    WHERE a.attendance_date = :filter_date
    ORDER BY e.full_name ASC
");

$stmt->execute([
    ':filter_date' => $filter_date
]);

$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPresent = 0;
$totalLate = 0;
$totalAbsent = 0;
$totalEarlyLeave = 0;
$totalLeave = 0;

foreach ($attendances as $att) {
    if (in_array($att['status'], ['present','late'], true)) {
        $totalPresent++;
    } elseif ($att['status'] === 'absent') {
        $totalAbsent++;
    } elseif (in_array($att['status'], ['permission','sick'], true)) {
        $totalLeave++;
    }

    if ((int) $att['late_minutes'] > 0 || $att['status'] === 'late') {
        $totalLate++;
    }

    if ((int) $att['early_leave_minutes'] > 0) {
        $totalEarlyLeave++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Data Absensi</h1>
            <p class="text-muted mb-0">Kelola hasil impor dan absensi manual setiap karyawan.</p>
        </div>

        <div class="d-flex gap-2 flex-wrap"><button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#attendanceModal" onclick="newAttendance()"><i class="bi bi-plus-lg me-1"></i> Tambah Absensi</button><a href="<?= base_url('pages/attendances/import.php'); ?>" class="btn btn-nukari">
            <i class="bi bi-upload me-1"></i> Import Absensi
        </a></div>
    </div>

    <?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stat-card border-green">
                <div class="stat-title">Hadir</div>
                <div class="stat-number"><?= $totalPresent; ?></div>
            </div>
        </div>

        <div class="col-lg-2 col-md-4 col-6">
            <div class="stat-card border-orange">
                <div class="stat-title">Terlambat</div>
                <div class="stat-number"><?= $totalLate; ?></div>
            </div>
        </div>

        <div class="col-lg-2 col-md-4 col-6">
            <div class="stat-card border-red">
                <div class="stat-title">Pulang Awal</div>
                <div class="stat-number"><?= $totalEarlyLeave; ?></div>
            </div>
        </div>

        <div class="col-lg-2 col-md-4 col-6"><div class="stat-card border-red"><div class="stat-title">Alpha</div><div class="stat-number"><?= $totalAbsent; ?></div></div></div>
        <div class="col-lg-2 col-md-4 col-6"><div class="stat-card border-blue"><div class="stat-title">Izin / Sakit</div><div class="stat-number"><?= $totalLeave; ?></div></div></div>
        <div class="col-lg-2 col-md-4 col-12">
            <div class="stat-card border-blue">
                <div class="stat-title">Tanggal</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= date('d M Y', strtotime($filter_date)); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Koreksi absensi memengaruhi perhitungan payroll berikutnya. Payroll yang sudah dibuat atau dibayar perlu diperiksa kembali secara manual.</div>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Filter Tanggal</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date); ?>">
            </div>

            <div class="col-md-3">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-search me-1"></i> Tampilkan
                </button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Kode</th>
                        <th>Fingerprint</th>
                        <th>Pagi Masuk</th>
                        <th>Pagi Keluar</th>
                        <th>Siang Masuk</th>
                        <th>Siang Keluar</th>
                        <th>Lembur Masuk</th>
                        <th>Lembur Keluar</th>
                        <th>Terlambat</th>
                        <th>Pulang Awal</th>
                        <th>Total Kerja</th>
                        <th>Status</th>
                        <th>Sumber</th><th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($attendances): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($attendances as $att): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($att['full_name']); ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($att['position'] ?? '-'); ?>
                                        <?= !empty($att['department']) ? ' - ' . htmlspecialchars($att['department']) : ''; ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($att['employee_code'] ?? '-'); ?></td>

                                <td>
                                    <span class="badge rounded-pill badge-soft-blue">
                                        <?= htmlspecialchars($att['fingerprint_id'] ?? '-'); ?>
                                    </span>
                                </td>

                                <td><?= format_time_attendance($att['morning_in']); ?></td>
                                <td><?= format_time_attendance($att['morning_out']); ?></td>
                                <td><?= format_time_attendance($att['afternoon_in']); ?></td>
                                <td><?= format_time_attendance($att['afternoon_out']); ?></td>
                                <td><?= format_time_attendance($att['overtime_in']); ?></td>
                                <td><?= format_time_attendance($att['overtime_out']); ?></td>

                                <td>
                                    <?php if ((int) $att['late_minutes'] > 0): ?>
                                        <span class="badge rounded-pill badge-soft-orange">
                                            <?= format_minutes_attendance($att['late_minutes']); ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ((int) $att['early_leave_minutes'] > 0): ?>
                                        <span class="badge rounded-pill badge-soft-red">
                                            <?= format_minutes_attendance($att['early_leave_minutes']); ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>

                                <td><?= format_minutes_attendance($att['work_minutes']); ?></td>

                                <td>
                                    <?php if ($att['status'] === 'late'): ?>
                                        <span class="badge rounded-pill badge-soft-orange">Terlambat</span>
                                    <?php elseif ($att['status'] === 'absent'): ?>
                                        <span class="badge rounded-pill badge-soft-red">Alpha</span>
                                    <?php elseif ($att['status'] === 'permission'): ?>
                                        <span class="badge rounded-pill badge-soft-blue">Izin</span>
                                    <?php elseif ($att['status'] === 'sick'): ?>
                                        <span class="badge rounded-pill badge-soft-blue">Sakit</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-green">Hadir</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark"><?= htmlspecialchars($att['source'] ?: 'manual'); ?></span></td>
                                <td><div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary" data-record="<?= htmlspecialchars(json_encode($att),ENT_QUOTES,'UTF-8'); ?>" onclick="editAttendance(this)" data-bs-toggle="modal" data-bs-target="#attendanceModal"><i class="bi bi-pencil"></i></button><form method="post" onsubmit="return confirm('Hapus absensi <?= htmlspecialchars($att['full_name'],ENT_QUOTES,'UTF-8'); ?>?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$att['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></div></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="16" class="text-center text-muted py-4">
                                Belum ada data absensi pada tanggal ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<div class="modal fade" id="attendanceModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><form method="post" id="attendanceForm"><div class="modal-header"><div><h5 class="modal-title fw-bold" id="attendanceTitle">Tambah Absensi</h5><small class="text-muted">Terlambat, pulang awal, dan total kerja dihitung otomatis.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="attendanceId"><div class="row g-3">
<div class="col-md-7"><label class="form-label fw-semibold">Karyawan</label><select class="form-select" name="employee_id" id="attendanceEmployee" required><option value="">Pilih karyawan</option><?php foreach($employees as $employee): ?><option value="<?= (int)$employee['id']; ?>"><?= htmlspecialchars($employee['full_name'].' · '.($employee['employee_code']?:'-').' · '.($employee['department']?:'-')); ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label fw-semibold">Tanggal</label><input class="form-control" type="date" name="attendance_date" id="attendanceDate" required></div>
<?php foreach(['morning_in'=>'Pagi Masuk','morning_out'=>'Pagi Keluar','afternoon_in'=>'Siang Masuk','afternoon_out'=>'Siang Keluar','overtime_in'=>'Lembur Masuk','overtime_out'=>'Lembur Keluar'] as $field=>$label): ?><div class="col-6 col-md-4"><label class="form-label"><?= $label; ?></label><input class="form-control" type="time" name="<?= $field; ?>" id="<?= $field; ?>"></div><?php endforeach; ?>
<div class="col-md-5"><label class="form-label fw-semibold">Status</label><select class="form-select" name="status" id="attendanceStatus"><option value="present">Hadir (otomatis terlambat bila lewat 08:00)</option><option value="permission">Izin</option><option value="sick">Sakit</option><option value="absent">Alpha</option></select></div><div class="col-md-7"><label class="form-label fw-semibold">Catatan</label><input class="form-control" name="notes" id="attendanceNotes" maxlength="500"></div></div><div class="alert alert-info mt-3 mb-0"><small>Jam kerja normal: masuk 08:00 dan pulang 17:00. Mengedit hasil fingerprint akan mengubah sumber menjadi <strong>manual_edit</strong>.</small></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari"><i class="bi bi-save me-1"></i>Simpan Absensi</button></div></form></div></div></div>
<script>
const attendanceFields=['morning_in','morning_out','afternoon_in','afternoon_out','overtime_in','overtime_out'];
function timeShort(v){return v&&v!=='00:00:00'?String(v).slice(0,5):''}
function newAttendance(){document.getElementById('attendanceForm').reset();document.getElementById('attendanceId').value='';document.getElementById('attendanceDate').value=<?= json_encode($filter_date); ?>;document.getElementById('attendanceTitle').textContent='Tambah Absensi';document.getElementById('attendanceEmployee').disabled=false}
function editAttendance(button){const r=JSON.parse(button.dataset.record);document.getElementById('attendanceForm').reset();document.getElementById('attendanceTitle').textContent='Edit Absensi · '+r.full_name;document.getElementById('attendanceId').value=r.id;document.getElementById('attendanceEmployee').value=r.employee_id;document.getElementById('attendanceEmployee').disabled=false;document.getElementById('attendanceDate').value=r.attendance_date;attendanceFields.forEach(f=>document.getElementById(f).value=timeShort(r[f]));document.getElementById('attendanceStatus').value=['permission','sick','absent'].includes(r.status)?r.status:'present';document.getElementById('attendanceNotes').value=r.notes||''}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
