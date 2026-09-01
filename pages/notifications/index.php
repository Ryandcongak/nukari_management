<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Reminder & Notifikasi - Nukari Management';
$message = ''; $error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'add';
        if ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            $dueDate = $_POST['due_date'] ?? '';
            if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) throw new RuntimeException('Judul dan tanggal reminder wajib diisi.');
            $stmt = $pdo->prepare("INSERT INTO system_notifications (notification_type,title,description,due_date,priority,icon,link_url,status) VALUES (:type,:title,:description,:due_date,:priority,'bi-bell',NULL,'active')");
            $stmt->execute([':type'=>trim($_POST['notification_type'] ?? 'Operasional'), ':title'=>$title, ':description'=>trim($_POST['description'] ?? ''), ':due_date'=>$dueDate, ':priority'=>$_POST['priority'] ?? 'warning']);
            $message = 'Reminder berhasil ditambahkan.';
        } elseif ($action === 'status') {
            $stmt = $pdo->prepare("UPDATE system_notifications SET status=:status WHERE id=:id");
            $stmt->execute([':status'=>($_POST['status'] ?? '') === 'active' ? 'active' : 'done', ':id'=>(int)($_POST['id'] ?? 0)]);
            $message = 'Status reminder diperbarui.';
        }
    }
    $notifications = $pdo->query("SELECT * FROM system_notifications ORDER BY (status='active') DESC, due_date ASC, id DESC LIMIT 200")->fetchAll();
} catch (Throwable $e) {
    $notifications = [];
    $error = strpos($e->getMessage(), "doesn't exist") !== false ? 'Tabel notifikasi belum tersedia. Jalankan database/migrations/001_local_foundation.sql.' : $e->getMessage();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header"><h1 class="fw-bold mb-1">Reminder & Notifikasi</h1><p class="text-muted mb-0">Reminder operasional lokal untuk seluruh tim.</p></div>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (has_role('super_admin','admin','manager')): ?>
    <div class="panel mb-4"><h5 class="fw-bold mb-3">Tambah Reminder</h5><form method="post" class="row g-3">
        <input type="hidden" name="action" value="add"><div class="col-md-3"><label class="form-label">Jenis</label><input class="form-control" name="notification_type" value="Operasional"></div>
        <div class="col-md-5"><label class="form-label">Judul</label><input class="form-control" name="title" required maxlength="200"></div>
        <div class="col-md-2"><label class="form-label">Jatuh Tempo</label><input type="date" class="form-control" name="due_date" required></div>
        <div class="col-md-2"><label class="form-label">Prioritas</label><select class="form-select" name="priority"><option value="low">Rendah</option><option value="warning" selected>Normal</option><option value="danger">Mendesak</option></select></div>
        <div class="col-md-10"><label class="form-label">Keterangan</label><input class="form-control" name="description" maxlength="500"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-nukari w-100">Simpan</button></div>
    </form></div><?php endif; ?>
    <div class="panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Tanggal</th><th>Jenis</th><th>Reminder</th><th>Prioritas</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($notifications as $n): $overdue=$n['status']==='active' && $n['due_date'] < date('Y-m-d'); ?><tr><td><?= htmlspecialchars($n['due_date']); ?></td><td><?= htmlspecialchars($n['notification_type']); ?></td><td><strong><?= htmlspecialchars($n['title']); ?></strong><div class="small text-muted"><?= htmlspecialchars($n['description'] ?? ''); ?></div></td><td><span class="badge <?= $n['priority']==='danger'?'bg-danger':($n['priority']==='low'?'bg-secondary':'bg-warning text-dark'); ?>"><?= htmlspecialchars($n['priority']); ?></span></td><td><span class="badge <?= $n['status']==='active'?($overdue?'bg-danger':'bg-success'):'bg-secondary'; ?>"><?= $overdue?'Terlambat':htmlspecialchars($n['status']); ?></span></td><td>
    <?php if (has_role('super_admin','admin','manager')): ?><form method="post"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$n['id']; ?>"><input type="hidden" name="status" value="<?= $n['status']==='active'?'done':'active'; ?>"><button class="btn btn-sm btn-outline-secondary"><?= $n['status']==='active'?'Selesai':'Aktifkan'; ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$notifications): ?><tr><td colspan="6" class="text-center text-muted py-4">Belum ada reminder.</td></tr><?php endif; ?></tbody></table></div></div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
