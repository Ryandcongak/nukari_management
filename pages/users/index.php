<?php
require_once __DIR__ . '/../../includes/auth.php';

if (!has_role('super_admin', 'admin')) {
    redirect('pages/dashboard.php');
}

$pageTitle = 'User Management - Nukari';

$stmt = $pdo->query("
    SELECT id, full_name, username, email, phone, role, status, last_login, last_activity, created_at 
    FROM users 
    ORDER BY id DESC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">User Management</h1>
            <p class="text-muted mb-0">Kelola akun pengguna dan pantau status online.</p>
        </div>

        <a href="<?= base_url('pages/users/add.php'); ?>" class="btn btn-nukari">
            <i class="bi bi-plus-circle me-1"></i> Tambah User
        </a>
    </div>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Online</th>
                        <th>Tanggal Dibuat</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($users): ?>
                        <?php $no = 1;
                        foreach ($users as $user): ?>
                            <tr>
                                <td><?= $no++; ?></td>

                                <td class="fw-semibold">
                                    <?= htmlspecialchars($user['full_name']); ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($user['username']); ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($user['email'] ?? '-'); ?>
                                </td>

                                <td>
                                    <span class="badge rounded-pill badge-soft-blue">
                                        <?= htmlspecialchars($user['role']); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($user['status'] === 'active'): ?>
                                        <span class="badge rounded-pill badge-soft-green">Active</span>
                                    <?php elseif ($user['status'] === 'pending'): ?>
                                        <span class="badge rounded-pill badge-soft-orange">Pending</span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill badge-soft-red">Inactive</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span
                                        id="online-badge-<?= $user['id']; ?>"
                                        class="badge rounded-pill badge-soft-red">
                                        Offline
                                    </span>

                                    <br>

                                    <small
                                        id="last-activity-<?= $user['id']; ?>"
                                        class="text-muted">
                                        -
                                    </small>
                                </td>

                                <td>
                                    <?= date('d M Y', strtotime($user['created_at'])); ?>
                                </td>

                                <td class="text-end">
                                    <a href="<?= base_url('pages/users/edit.php?id=' . $user['id']); ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i> Edit
                                    </a>

                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>

                                        <?php if ($user['status'] !== 'inactive'): ?>
                                            <form method="POST" action="<?= base_url('pages/users/deactivate.php?id=' . $user['id']); ?>" class="d-inline" onsubmit="return confirm('Yakin ingin menonaktifkan user ini?')">
                                                <?= csrf_field(); ?>
                                                <button class="btn btn-sm btn-outline-warning"><i class="bi bi-person-x me-1"></i> Nonaktifkan</button>
                                            </form>
                                        <?php endif; ?>

                                        <a href="<?= base_url('pages/users/delete.php?id=' . $user['id']); ?>"
                                            onclick="return confirm('Yakin ingin menghapus akun ini secara permanen? Data tidak bisa dikembalikan.')"
                                            class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i> Hapus
                                        </a>

                                    <?php else: ?>
                                        <span class="badge bg-secondary">Akun Anda</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                Belum ada data user.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<script>
    function updateOnlineStatus() {
        fetch('<?= base_url('pages/users/online_status.php'); ?>')
            .then(response => response.json())
            .then(users => {
                users.forEach(user => {
                    const badge = document.getElementById('online-badge-' + user.id);
                    const lastActivity = document.getElementById('last-activity-' + user.id);

                    if (!badge || !lastActivity) return;

                    if (user.online) {
                        badge.className = 'badge rounded-pill badge-soft-green';
                        badge.textContent = 'Online';
                    } else {
                        badge.className = 'badge rounded-pill badge-soft-red';
                        badge.textContent = 'Offline';
                    }

                    lastActivity.textContent = user.last_activity;
                });
            })
            .catch(error => {
                console.error('Gagal mengambil status online:', error);
            });
    }

    updateOnlineStatus();
    setInterval(updateOnlineStatus, 10000);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
