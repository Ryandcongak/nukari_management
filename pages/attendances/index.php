<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../includes/activity_log.php';

attendance_ensure_extended_schema($pdo);

$pageTitle = 'Data Absensi - Nukari';

$legacyDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : null;
$filter_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : ($legacyDate ?? date('Y-m-d'));
$filter_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : ($legacyDate ?? date('Y-m-d'));
if ($filter_from > $filter_to) {
    [$filter_from, $filter_to] = [$filter_to, $filter_from];
}
$search = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$reviewOnly = ($_GET['review'] ?? '') === 'incomplete';
$success = $_SESSION['attendance_success'] ?? ''; unset($_SESSION['attendance_success']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action=$_POST['action'] ?? 'save';
        if ($action==='bulk_overtime_hourly') {
            require_role('super_admin', 'admin');
            $attendanceIds = array_values(array_unique(array_filter(array_map('intval', $_POST['attendance_ids'] ?? []))));
            $overtimeRate = max(0, (float)preg_replace('/[^0-9]/', '', (string)($_POST['bulk_overtime_rate'] ?? '0')));
            if (!$attendanceIds || count($attendanceIds) > 200) {
                throw new RuntimeException('Pilih minimal satu dan maksimal 200 tanggal lembur.');
            }
            if ($overtimeRate <= 0) {
                throw new RuntimeException('Tarif lembur per jam harus lebih dari nol.');
            }

            $placeholders = implode(',', array_fill(0, count($attendanceIds), '?'));
            $selectedStmt = $pdo->prepare("SELECT a.id,a.employee_id,a.attendance_date,a.overtime_minutes,e.full_name FROM attendances a JOIN employees e ON e.id=a.employee_id WHERE a.id IN ($placeholders) ORDER BY a.attendance_date");
            $selectedStmt->execute($attendanceIds);
            $selectedRows = $selectedStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($selectedRows) !== count($attendanceIds)) {
                throw new RuntimeException('Sebagian data absensi tidak ditemukan. Muat ulang halaman lalu coba kembali.');
            }

            $employeeIds = array_unique(array_column($selectedRows, 'employee_id'));
            if (count($employeeIds) !== 1) {
                throw new RuntimeException('Pembaruan tarif massal hanya dapat dilakukan untuk satu karyawan.');
            }
            foreach ($selectedRows as $selectedRow) {
                if ((int)$selectedRow['overtime_minutes'] <= 0) {
                    throw new RuntimeException('Pilihan memuat tanggal yang tidak memiliki jam lembur.');
                }
            }

            $lockedStmt = $pdo->prepare("SELECT COUNT(*) FROM payrolls p WHERE p.employee_id=? AND (p.status='paid' OR COALESCE(p.is_locked,0)=1) AND EXISTS (SELECT 1 FROM attendances a WHERE a.id IN ($placeholders) AND a.attendance_date BETWEEN p.period_start AND p.period_end)");
            $lockedStmt->execute(array_merge([(int)$employeeIds[0]], $attendanceIds));
            if ((int)$lockedStmt->fetchColumn() > 0) {
                throw new RuntimeException('Tarif tidak dapat diubah karena salah satu tanggal sudah masuk payroll yang dibayar atau dikunci.');
            }

            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare("UPDATE attendances SET overtime_method='hourly',overtime_rate=:rate,overtime_amount=:amount,updated_at=NOW() WHERE id=:id");
                $totalAmount = 0.0;
                foreach ($selectedRows as $selectedRow) {
                    $amount = attendance_payable_overtime_hours((int)$selectedRow['overtime_minutes']) * $overtimeRate;
                    $update->execute([':rate'=>$overtimeRate, ':amount'=>$amount, ':id'=>(int)$selectedRow['id']]);
                    $totalAmount += $amount;
                }
                log_activity($pdo,'Absensi','UPDATE','Mengatur tarif lembur per jam '.$selectedRows[0]['full_name'].' untuk '.count($selectedRows).' tanggal menjadi '.rupiah($overtimeRate).' per jam; total '.rupiah($totalAmount),null,'attendances');
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $_SESSION['attendance_success']='Tarif lembur '.$selectedRows[0]['full_name'].' berhasil diterapkan ke '.count($selectedRows).' tanggal. Total nilai lembur: '.rupiah($totalAmount).'.';
            $bulkReturn = ['from'=>$filter_from, 'to'=>$filter_to, 'q'=>$search];
            if ($reviewOnly) $bulkReturn['review'] = 'incomplete';
            redirect('pages/attendances/index.php?'.http_build_query($bulkReturn));
        }
        if ($action==='delete_filtered') {
            require_role('super_admin', 'admin');
            $deleteFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['from'] ?? '') ? $_POST['from'] : '';
            $deleteTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['to'] ?? '') ? $_POST['to'] : '';
            $deleteSearch = mb_substr(trim($_POST['q'] ?? ''), 0, 100);
            if ($deleteFrom === '' || $deleteTo === '' || ($_POST['confirmation'] ?? '') !== 'HAPUS') {
                throw new RuntimeException('Konfirmasi penghapusan tidak valid.');
            }
            if ($deleteFrom > $deleteTo) [$deleteFrom, $deleteTo] = [$deleteTo, $deleteFrom];

            $searchSql = '';
            $searchParams = [];
            if ($deleteSearch !== '') {
                $searchSql = " AND (e.full_name LIKE :name OR e.employee_code LIKE :code OR e.fingerprint_id LIKE :fingerprint OR e.department LIKE :department)";
                $term = '%'.$deleteSearch.'%';
                $searchParams = [':name'=>$term, ':code'=>$term, ':fingerprint'=>$term, ':department'=>$term];
            }

            $payrollCheck = $pdo->prepare("
                SELECT COUNT(*)
                FROM attendances a
                JOIN employees e ON e.id = a.employee_id
                JOIN payrolls p ON p.employee_id = a.employee_id
                    AND a.attendance_date BETWEEN p.period_start AND p.period_end
                WHERE a.attendance_date BETWEEN :filter_from AND :filter_to{$searchSql}
            ");
            $payrollCheck->execute(array_merge([':filter_from'=>$deleteFrom, ':filter_to'=>$deleteTo], $searchParams));
            if ((int)$payrollCheck->fetchColumn() > 0) {
                throw new RuntimeException('Penghapusan dibatalkan karena sebagian absensi sudah digunakan pada payroll. Hapus atau sesuaikan payroll terkait terlebih dahulu.');
            }

            $pdo->beginTransaction();
            try {
                $delete = $pdo->prepare("
                    DELETE a FROM attendances a
                    JOIN employees e ON e.id = a.employee_id
                    WHERE a.attendance_date BETWEEN :filter_from AND :filter_to{$searchSql}
                ");
                $delete->execute(array_merge([':filter_from'=>$deleteFrom, ':filter_to'=>$deleteTo], $searchParams));
                $deletedCount = $delete->rowCount();
                log_activity($pdo,'Absensi','DELETE','Menghapus '.$deletedCount.' data absensi periode '.$deleteFrom.' sampai '.$deleteTo.($deleteSearch !== '' ? ' dengan pencarian "'.$deleteSearch.'"' : ''),null,'attendances');
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $_SESSION['attendance_success']=$deletedCount.' data absensi berhasil dihapus tanpa mengubah data payroll.';
            redirect('pages/attendances/index.php?from='.$deleteFrom.'&to='.$deleteTo.'&q='.urlencode($deleteSearch));
        }
        if ($action==='delete') {
            $id=(int)($_POST['id'] ?? 0);
            $stmt=$pdo->prepare("SELECT a.id,a.attendance_date,e.full_name FROM attendances a JOIN employees e ON e.id=a.employee_id WHERE a.id=:id");$stmt->execute([':id'=>$id]);$old=$stmt->fetch();
            if(!$old) throw new RuntimeException('Data absensi tidak ditemukan.');
            $payrollCheck=$pdo->prepare("SELECT COUNT(*) FROM payrolls p JOIN attendances a ON a.employee_id=p.employee_id AND a.attendance_date BETWEEN p.period_start AND p.period_end WHERE a.id=:id");$payrollCheck->execute([':id'=>$id]);
            if((int)$payrollCheck->fetchColumn()>0) throw new RuntimeException('Absensi tidak dapat dihapus karena sudah digunakan pada payroll.');
            $pdo->prepare("DELETE FROM attendances WHERE id=:id")->execute([':id'=>$id]);
            log_activity($pdo,'Absensi','DELETE','Menghapus absensi '.$old['full_name'].' tanggal '.$old['attendance_date'],$id,'attendances');
            $_SESSION['attendance_success']='Data absensi berhasil dihapus.'; redirect('pages/attendances/index.php?from='.$old['attendance_date'].'&to='.$old['attendance_date']);
        }
        $id=(int)($_POST['id'] ?? 0); $employeeId=(int)($_POST['employee_id'] ?? 0); $date=$_POST['attendance_date'] ?? '';
        if($employeeId<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new RuntimeException('Karyawan dan tanggal wajib diisi.');
        $employee=$pdo->prepare("SELECT full_name FROM employees WHERE id=:id AND employment_status='active'");$employee->execute([':id'=>$employeeId]);$employeeName=$employee->fetchColumn();
        if(!$employeeName) throw new RuntimeException('Karyawan aktif tidak ditemukan.');
        $calculated=attendance_simple_calculate($_POST); $notes=trim($_POST['notes'] ?? '');
        $duplicate=$pdo->prepare("SELECT id FROM attendances WHERE employee_id=:employee AND attendance_date=:date AND id<>:id");$duplicate->execute([':employee'=>$employeeId,':date'=>$date,':id'=>$id]);
        if($duplicate->fetchColumn()) throw new RuntimeException('Karyawan sudah memiliki absensi pada tanggal tersebut. Silakan edit data yang ada.');
        $params=[':employee_id'=>$employeeId,':attendance_date'=>$date,':morning_in'=>$calculated['morning_in'],':morning_out'=>$calculated['morning_out'],':afternoon_in'=>$calculated['afternoon_in'],':afternoon_out'=>$calculated['afternoon_out'],':overtime_in'=>$calculated['overtime_in'],':overtime_out'=>$calculated['overtime_out'],':check_in'=>$calculated['check_in'],':check_out'=>$calculated['check_out'],':late_minutes'=>$calculated['late_minutes'],':early_leave_minutes'=>$calculated['early_leave_minutes'],':work_minutes'=>$calculated['work_minutes'],':overtime_minutes'=>$calculated['overtime_minutes'],':overtime_method'=>$calculated['overtime_method'],':overtime_rate'=>$calculated['overtime_rate'],':overtime_amount'=>$calculated['overtime_amount'],':status'=>$calculated['status'],':attendance_type'=>$calculated['attendance_type'],':attendance_day_value'=>$calculated['attendance_day_value'],':notes'=>$notes?:null];
        if($id>0){
            $params[':id']=$id;$stmt=$pdo->prepare("UPDATE attendances SET employee_id=:employee_id,attendance_date=:attendance_date,morning_in=:morning_in,morning_out=:morning_out,afternoon_in=:afternoon_in,afternoon_out=:afternoon_out,overtime_in=:overtime_in,overtime_out=:overtime_out,check_in=:check_in,check_out=:check_out,late_minutes=:late_minutes,early_leave_minutes=:early_leave_minutes,work_minutes=:work_minutes,overtime_minutes=:overtime_minutes,overtime_method=:overtime_method,overtime_rate=:overtime_rate,overtime_amount=:overtime_amount,status=:status,attendance_type=:attendance_type,attendance_day_value=:attendance_day_value,notes=:notes,source='manual_edit',updated_at=NOW() WHERE id=:id");$stmt->execute($params);$verb='diperbarui';$logAction='UPDATE';
        } else {
            $stmt=$pdo->prepare("INSERT INTO attendances (employee_id,attendance_date,morning_in,morning_out,afternoon_in,afternoon_out,overtime_in,overtime_out,check_in,check_out,late_minutes,early_leave_minutes,work_minutes,overtime_minutes,overtime_method,overtime_rate,overtime_amount,status,attendance_type,attendance_day_value,notes,source) VALUES (:employee_id,:attendance_date,:morning_in,:morning_out,:afternoon_in,:afternoon_out,:overtime_in,:overtime_out,:check_in,:check_out,:late_minutes,:early_leave_minutes,:work_minutes,:overtime_minutes,:overtime_method,:overtime_rate,:overtime_amount,:status,:attendance_type,:attendance_day_value,:notes,'manual')");$stmt->execute($params);$id=(int)$pdo->lastInsertId();$verb='ditambahkan';$logAction='CREATE';
        }
        log_activity($pdo,'Absensi',$logAction,'Absensi '.$employeeName.' tanggal '.$date.' '.$verb,$id,'attendances');
        $_SESSION['attendance_success']='Data absensi berhasil '.$verb.'.';
        $returnFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['return_from'] ?? '') ? $_POST['return_from'] : $date;
        $returnTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['return_to'] ?? '') ? $_POST['return_to'] : $date;
        if ($returnFrom > $returnTo) [$returnFrom, $returnTo] = [$returnTo, $returnFrom];
        $returnParams = [
            'from' => $returnFrom,
            'to' => $returnTo,
            'q' => mb_substr(trim((string)($_POST['return_q'] ?? '')), 0, 100),
        ];
        if (($_POST['return_review'] ?? '') === 'incomplete') $returnParams['review'] = 'incomplete';
        redirect('pages/attendances/index.php?'.http_build_query($returnParams).'#attendance-'.$id);
    } catch(Throwable $e){$error=$e->getMessage();}
}

$employees=$pdo->query("SELECT id,employee_code,full_name,department FROM employees WHERE employment_status='active' ORDER BY full_name")->fetchAll();
$reviewSummary = $pdo->query("
    SELECT COUNT(*) AS total, MIN(attendance_date) AS first_date, MAX(attendance_date) AS last_date
    FROM attendances
    WHERE ((check_in IS NULL) <> (check_out IS NULL))
       OR (
            check_in IS NOT NULL
        AND check_out IS NOT NULL
        AND attendance_type = 'full_day_irregular'
        AND TIMESTAMPDIFF(MINUTE, TIMESTAMP(attendance_date, check_in), TIMESTAMP(attendance_date, check_out)) < 360
       )
")->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0, 'first_date'=>null, 'last_date'=>null];

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

$attendanceSearchSql = '';
$attendanceParams = [':filter_from' => $filter_from, ':filter_to' => $filter_to];
if ($search !== '') {
    $attendanceSearchSql = " AND (e.full_name LIKE :search_name OR e.employee_code LIKE :search_code OR e.fingerprint_id LIKE :search_fingerprint OR e.department LIKE :search_department)";
    $searchTerm = '%'.$search.'%';
    $attendanceParams += [':search_name'=>$searchTerm, ':search_code'=>$searchTerm, ':search_fingerprint'=>$searchTerm, ':search_department'=>$searchTerm];
}
if ($reviewOnly) {
    $attendanceSearchSql .= " AND (
        ((a.check_in IS NULL) <> (a.check_out IS NULL))
        OR (
            a.check_in IS NOT NULL
            AND a.check_out IS NOT NULL
            AND a.attendance_type = 'full_day_irregular'
            AND TIMESTAMPDIFF(MINUTE, TIMESTAMP(a.attendance_date, a.check_in), TIMESTAMP(a.attendance_date, a.check_out)) < 360
        )
    )";
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
    WHERE a.attendance_date BETWEEN :filter_from AND :filter_to
    {$attendanceSearchSql}
    ORDER BY a.attendance_date DESC, e.full_name ASC
");

$stmt->execute($attendanceParams);

$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPresent = 0.0;
$totalLate = 0;
$totalAbsent = 0;
$totalEarlyLeave = 0;
$totalLeave = 0;
$totalHalfDays = 0;
$totalIncompleteScans = 0;

foreach ($attendances as $att) {
    $dayClass = attendance_day_classification($att['check_in'] ?? null, $att['check_out'] ?? null, $att['status'] ?? null);
    $totalPresent += (float)$dayClass['value'];

    if (in_array($dayClass['type'], ['half_day_morning', 'half_day_afternoon'], true)) {
        $totalHalfDays++;
    }
    if (!empty($dayClass['needs_review'])) {
        $totalIncompleteScans++;
    }

    if ($att['status'] === 'absent') {
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
    <?php if ((int)$reviewSummary['total'] > 0): ?>
        <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div><i class="bi bi-exclamation-octagon me-1"></i><strong><?= (int)$reviewSummary['total']; ?> absensi perlu diperiksa</strong> karena scan tidak lengkap atau durasi jam tidak baku terlalu pendek.</div>
            <a class="btn btn-sm btn-outline-danger" href="<?= base_url('pages/attendances/index.php?from='.urlencode((string)$reviewSummary['first_date']).'&to='.urlencode((string)$reviewSummary['last_date']).'&review=incomplete'); ?>">Periksa sekarang</a>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-lg-2 col-md-4 col-6">
            <div class="stat-card border-green">
                <div class="stat-title">Hadir</div>
                <div class="stat-number"><?= attendance_day_number($totalPresent); ?></div>
                <div class="small text-muted"><?= $totalHalfDays; ?> data setengah hari</div>
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
                <div class="stat-title">Periode</div>
                <div class="stat-number" style="font-size:22px;">
                    <?= date('d M Y', strtotime($filter_from)); ?><?= $filter_from !== $filter_to ? ' – '.date('d M Y', strtotime($filter_to)) : ''; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($totalIncompleteScans > 0): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-octagon mt-1"></i>
            <div><strong><?= $totalIncompleteScans; ?> absensi perlu diperiksa.</strong> Scan tunggal bernilai <strong>0 hari</strong>; pasangan scan jam tidak baku yang terlalu pendek tetap ditandai agar admin dapat memvalidasinya.</div>
        </div>
    <?php endif; ?>

    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> Koreksi absensi memengaruhi perhitungan payroll berikutnya. Payroll yang sudah dibuat atau dibayar perlu diperiksa kembali secara manual.</div>

    <div class="panel mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-6">
                <label class="form-label fw-semibold">Dari Tanggal</label>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($filter_from); ?>" required>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label fw-semibold">Sampai Tanggal</label>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($filter_to); ?>" required>
            </div>

            <div class="col-lg-4 col-md-8">
                <label class="form-label fw-semibold">Cari Karyawan</label>
                <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($search); ?>" placeholder="Nama, kode, fingerprint, departemen">
                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="review" value="incomplete" id="reviewIncomplete" <?= $reviewOnly ? 'checked' : ''; ?>><label class="form-check-label small" for="reviewIncomplete">Hanya scan yang perlu diperiksa</label></div>
            </div>

            <div class="col-lg-2 col-md-4 d-grid">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-search me-1"></i> Tampilkan
                </button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="text-muted small"><?= count($attendances); ?> data ditemukan<?= $search !== '' ? ' untuk pencarian “'.htmlspecialchars($search).'”' : ''; ?>.</div>
            <?php if ($attendances && has_role('super_admin', 'admin')): ?>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="bulkOvertimeButton" data-bs-toggle="modal" data-bs-target="#bulkOvertimeModal" disabled>
                        <i class="bi bi-clock-history me-1"></i> Atur Tarif Lembur (<span id="bulkOvertimeCount">0</span>)
                    </button>
                    <?php if (!$reviewOnly): ?><button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteFilteredModal">
                        <i class="bi bi-trash me-1"></i> Hapus Semua yang Ditampilkan
                    </button><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <?php if (has_role('super_admin', 'admin')): ?><th class="text-center">Pilih</th><?php endif; ?>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Karyawan</th>
                        <th>Kode</th>
                        <th>Fingerprint</th>
                        <th>Datang</th>
                        <th>Pulang</th>
                        <th>Terlambat</th>
                        <th>Pulang Awal</th>
                        <th>Jam Kerja</th>
                        <th>Jam Lembur</th>
                        <th>Nilai Lembur</th>
                        <th>Status</th>
                        <th>Sumber</th><th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($attendances): ?>
                        <?php $no = 1; ?>
                        <?php foreach ($attendances as $att): ?>
                            <?php $dayClass = attendance_day_classification($att['check_in'] ?? null, $att['check_out'] ?? null, $att['status'] ?? null); ?>
                            <tr id="attendance-<?= (int)$att['id']; ?>">
                                <?php if (has_role('super_admin', 'admin')): ?>
                                    <td class="text-center">
                                        <?php if ((int)($att['overtime_minutes'] ?? 0) > 0): ?>
                                            <input class="form-check-input overtime-checkbox" type="checkbox" value="<?= (int)$att['id']; ?>" data-employee-id="<?= (int)$att['employee_id']; ?>" data-employee-name="<?= htmlspecialchars($att['full_name'], ENT_QUOTES, 'UTF-8'); ?>" data-date="<?= htmlspecialchars($att['attendance_date']); ?>" data-minutes="<?= (int)$att['overtime_minutes']; ?>" aria-label="Pilih lembur <?= htmlspecialchars($att['full_name'].' '.$att['attendance_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><?= $no++; ?></td>
                                <td class="text-nowrap"><?= date('d M Y', strtotime($att['attendance_date'])); ?></td>

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

                                <td><?= format_time_attendance($att['check_in']); ?></td>
                                <td><?= format_time_attendance($att['check_out']); ?></td>

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
                                <td><?= format_minutes_attendance($att['overtime_minutes'] ?? 0); ?></td>
                                <td><div class="fw-semibold"><?= rupiah($att['overtime_amount'] ?? 0); ?></div><small class="text-muted"><?= ($att['overtime_method'] ?? 'hourly') === 'fixed' ? 'Borongan' : 'Per jam · '.rupiah($att['overtime_rate'] ?? 0); ?></small></td>

                                <td>
                                    <?php if (!empty($dayClass['needs_review'])): ?>
                                        <span class="badge rounded-pill badge-soft-red">Perlu Diperiksa</span>
                                    <?php elseif ($att['status'] === 'late'): ?>
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
                                    <div><small class="<?= !empty($dayClass['needs_review']) ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?= htmlspecialchars($dayClass['label']); ?> · <?= attendance_day_number($dayClass['value']); ?> hari</small></div>
                                </td>
                                <td><span class="badge bg-light text-dark"><?= htmlspecialchars($att['source'] ?: 'manual'); ?></span></td>
                                <td><div class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary" data-record="<?= htmlspecialchars(json_encode($att),ENT_QUOTES,'UTF-8'); ?>" onclick="editAttendance(this)" data-bs-toggle="modal" data-bs-target="#attendanceModal"><i class="bi bi-pencil"></i></button><form method="post" onsubmit="return confirm('Hapus absensi <?= htmlspecialchars($att['full_name'],ENT_QUOTES,'UTF-8'); ?>?')"><?= csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$att['id']; ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></div></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= has_role('super_admin', 'admin') ? 16 : 15; ?>" class="text-center text-muted py-4">
                                Belum ada data absensi pada rentang tanggal ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php if ($attendances && has_role('super_admin', 'admin')): ?>
<div class="modal fade" id="deleteFilteredModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title fw-bold">Hapus Absensi yang Ditampilkan</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?= csrf_field(); ?><input type="hidden" name="action" value="delete_filtered"><input type="hidden" name="from" value="<?= htmlspecialchars($filter_from); ?>"><input type="hidden" name="to" value="<?= htmlspecialchars($filter_to); ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($search); ?>"><div class="alert alert-warning">Tindakan ini akan menghapus <strong><?= count($attendances); ?> data</strong> pada hasil yang sedang ditampilkan. Sistem akan membatalkan proses jika ada data yang sudah digunakan payroll.</div><label class="form-label fw-semibold">Ketik HAPUS untuk konfirmasi</label><input class="form-control" name="confirmation" required pattern="HAPUS" autocomplete="off"></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-danger"><i class="bi bi-trash me-1"></i>Hapus <?= count($attendances); ?> Data</button></div></form></div></div></div>

<div class="modal fade" id="bulkOvertimeModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post" id="bulkOvertimeForm"><div class="modal-header"><div><h5 class="modal-title fw-bold">Atur Tarif Lembur Per Jam</h5><small class="text-muted">Tarif diterapkan pada beberapa tanggal lembur milik satu karyawan.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?= csrf_field(); ?><input type="hidden" name="action" value="bulk_overtime_hourly"><div id="bulkOvertimeIds"></div><div class="row g-3"><div class="col-md-7"><label class="form-label fw-semibold">Karyawan dan Tanggal</label><div class="border rounded p-3 bg-light"><div class="fw-semibold" id="bulkOvertimeEmployee">-</div><div class="small text-muted mt-1" id="bulkOvertimeDates">-</div></div></div><div class="col-md-5"><label class="form-label fw-semibold">Tarif Lembur per Jam</label><input class="form-control currency-input" name="bulk_overtime_rate" id="bulkOvertimeRate" value="0" required inputmode="numeric"><small class="text-muted">Pembulatan dilakukan pada setiap tanggal: 40 menit ke atas naik satu jam.</small></div></div><div class="row g-3 mt-1"><div class="col-md-4"><div class="stat-card border-blue h-100"><div class="stat-title">Tanggal Dipilih</div><div class="stat-number" id="bulkSummaryDays">0</div></div></div><div class="col-md-4"><div class="stat-card border-orange h-100"><div class="stat-title">Jam Dibayar</div><div class="stat-number" id="bulkSummaryHours">0</div></div></div><div class="col-md-4"><div class="stat-card border-green h-100"><div class="stat-title">Estimasi Upah</div><div class="stat-number" style="font-size:22px" id="bulkSummaryAmount">Rp 0</div></div></div></div><div class="alert alert-warning mt-3 mb-0"><small>Tanggal yang sudah masuk payroll berstatus dibayar atau dikunci akan ditolak. Payroll draft harus di-generate ulang setelah tarif berubah. Untuk lembur borongan, gunakan tombol edit pada satu tanggal.</small></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari"><i class="bi bi-check-lg me-1"></i>Terapkan Tarif</button></div></form></div></div></div>
<?php endif; ?>

<div class="modal fade" id="attendanceModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><form method="post" id="attendanceForm"><?= csrf_field(); ?><div class="modal-header"><div><h5 class="modal-title fw-bold" id="attendanceTitle">Tambah Absensi</h5><small class="text-muted">Hari penuh bernilai 1; setengah hari pagi/siang bernilai 0,5. Lembur tidak mengubah nilai hari.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="attendanceId"><input type="hidden" name="return_from" value="<?= htmlspecialchars($filter_from); ?>"><input type="hidden" name="return_to" value="<?= htmlspecialchars($filter_to); ?>"><input type="hidden" name="return_q" value="<?= htmlspecialchars($search); ?>"><input type="hidden" name="return_review" value="<?= $reviewOnly ? 'incomplete' : ''; ?>"><div class="row g-3">
<div class="col-md-7"><label class="form-label fw-semibold">Karyawan</label><select class="form-select" name="employee_id" id="attendanceEmployee" required><option value="">Pilih karyawan</option><?php foreach($employees as $employee): ?><option value="<?= (int)$employee['id']; ?>"><?= htmlspecialchars($employee['full_name'].' · '.($employee['employee_code']?:'-').' · '.($employee['department']?:'-')); ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label fw-semibold">Tanggal</label><input class="form-control" type="date" name="attendance_date" id="attendanceDate" required></div>
<div class="col-6"><label class="form-label fw-semibold">Jam Datang</label><input class="form-control" type="time" name="check_in" id="check_in"></div><div class="col-6"><label class="form-label fw-semibold">Jam Pulang</label><input class="form-control" type="time" name="check_out" id="check_out"></div>
<div class="col-md-4"><label class="form-label fw-semibold">Metode Lembur</label><select class="form-select" name="overtime_method" id="overtime_method"><option value="hourly">Tarif per jam</option><option value="fixed">Borongan</option></select></div><div class="col-md-4"><label class="form-label fw-semibold">Tarif per Jam</label><input class="form-control currency-input" name="overtime_rate" id="overtime_rate" value="0"></div><div class="col-md-4"><label class="form-label fw-semibold">Nominal Borongan</label><input class="form-control currency-input" name="overtime_amount" id="overtime_amount" value="0"></div>
<div class="col-md-5"><label class="form-label fw-semibold">Status</label><select class="form-select" name="status" id="attendanceStatus"><option value="present">Hadir (keterlambatan dihitung otomatis)</option><option value="permission">Izin</option><option value="sick">Sakit</option><option value="absent">Alpha</option></select></div><div class="col-md-7"><label class="form-label fw-semibold">Catatan</label><input class="form-control" name="notes" id="attendanceNotes" maxlength="500"></div></div><div class="alert alert-info mt-3 mb-0"><small>Tarif/metode tersimpan pada absensi ini sehingga perubahan tarif berikutnya tidak mengubah histori. Mengedit hasil fingerprint akan mengubah sumber menjadi <strong>manual_edit</strong>.</small></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari"><i class="bi bi-save me-1"></i>Simpan Absensi</button></div></form></div></div></div>
<script>
const attendanceFields=['check_in','check_out'];
function timeShort(v){return v&&v!=='00:00:00'?String(v).slice(0,5):''}
function newAttendance(){document.getElementById('attendanceForm').reset();document.getElementById('attendanceId').value='';document.getElementById('attendanceDate').value=<?= json_encode($filter_to); ?>;document.getElementById('attendanceTitle').textContent='Tambah Absensi';document.getElementById('attendanceEmployee').disabled=false}
function editAttendance(button){const r=JSON.parse(button.dataset.record);document.getElementById('attendanceForm').reset();document.getElementById('attendanceTitle').textContent='Edit Absensi · '+r.full_name;document.getElementById('attendanceId').value=r.id;document.getElementById('attendanceEmployee').value=r.employee_id;document.getElementById('attendanceEmployee').disabled=false;document.getElementById('attendanceDate').value=r.attendance_date;attendanceFields.forEach(f=>document.getElementById(f).value=timeShort(r[f]));document.getElementById('overtime_method').value=r.overtime_method||'hourly';document.getElementById('overtime_rate').value=Math.round(Number(r.overtime_rate||0));document.getElementById('overtime_amount').value=Math.round(Number(r.overtime_amount||0));document.getElementById('attendanceStatus').value=['permission','sick','absent'].includes(r.status)?r.status:'present';document.getElementById('attendanceNotes').value=r.notes||''}
const overtimeCheckboxes=Array.from(document.querySelectorAll('.overtime-checkbox'));
const bulkOvertimeButton=document.getElementById('bulkOvertimeButton');
function selectedOvertimeRows(){return overtimeCheckboxes.filter(checkbox=>checkbox.checked)}
function payableOvertimeHours(minutes){const whole=Math.floor(minutes/60);return whole+(minutes%60>=40?1:0)}
function currencyNumber(value){return Number(String(value||'').replace(/[^0-9]/g,''))||0}
function formatRupiah(value){return 'Rp '+Math.round(value).toLocaleString('id-ID')}
function updateBulkOvertimeSummary(){
    const selected=selectedOvertimeRows();
    const countElement=document.getElementById('bulkOvertimeCount');
    if(countElement) countElement.textContent=selected.length;
    if(bulkOvertimeButton) bulkOvertimeButton.disabled=selected.length===0;
    const hours=selected.reduce((total,checkbox)=>total+payableOvertimeHours(Number(checkbox.dataset.minutes||0)),0);
    const rate=currencyNumber(document.getElementById('bulkOvertimeRate')?.value);
    const days=document.getElementById('bulkSummaryDays');
    const hoursElement=document.getElementById('bulkSummaryHours');
    const amount=document.getElementById('bulkSummaryAmount');
    if(days) days.textContent=selected.length;
    if(hoursElement) hoursElement.textContent=hours+' jam';
    if(amount) amount.textContent=formatRupiah(hours*rate);
}
overtimeCheckboxes.forEach(checkbox=>checkbox.addEventListener('change',function(){
    if(this.checked){
        const selectedEmployee=selectedOvertimeRows().find(item=>item!==this)?.dataset.employeeId;
        if(selectedEmployee&&selectedEmployee!==this.dataset.employeeId){
            this.checked=false;
            window.alert('Pilih tanggal lembur dari satu karyawan yang sama.');
        }
    }
    updateBulkOvertimeSummary();
}));
document.getElementById('bulkOvertimeModal')?.addEventListener('show.bs.modal',function(event){
    const selected=selectedOvertimeRows();
    if(!selected.length){event.preventDefault();return}
    document.getElementById('bulkOvertimeEmployee').textContent=selected[0].dataset.employeeName;
    document.getElementById('bulkOvertimeDates').textContent=selected.map(checkbox=>checkbox.dataset.date).join(', ');
    document.getElementById('bulkOvertimeIds').innerHTML=selected.map(checkbox=>'<input type="hidden" name="attendance_ids[]" value="'+checkbox.value+'">').join('');
    updateBulkOvertimeSummary();
});
document.getElementById('bulkOvertimeRate')?.addEventListener('input',updateBulkOvertimeSummary);
document.getElementById('bulkOvertimeForm')?.addEventListener('submit',function(event){
    const selected=selectedOvertimeRows();
    const rate=currencyNumber(document.getElementById('bulkOvertimeRate').value);
    if(!selected.length||rate<=0){event.preventDefault();window.alert('Pilih tanggal lembur dan isi tarif per jam lebih dari nol.');return}
    if(!window.confirm('Terapkan tarif '+formatRupiah(rate)+' per jam ke '+selected.length+' tanggal lembur?')) event.preventDefault();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
