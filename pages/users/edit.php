<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';

require_permission('users.manage');

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/users/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    redirect('pages/users/index.php');
}

$pageTitle = 'Edit User - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = clean_input($_POST['full_name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $role = clean_input($_POST['role'] ?? 'admin');
    $status = clean_input($_POST['status'] ?? 'active');
    $password = $_POST['password'] ?? '';

    if ($full_name === '') {
        $error = 'Nama lengkap wajib diisi.';
    } elseif (!is_valid_app_role($role)) {
        $error = 'Role pengguna tidak valid.';
    } elseif (!in_array($status, ['active', 'pending', 'inactive'], true)) {
        $error = 'Status pengguna tidak valid.';
    } else {
        if ($user['role'] === 'super_admin' && ($role !== 'super_admin' || $status !== 'active')) {
            $activeSuperadmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin' AND status='active'")->fetchColumn();
            if ($activeSuperadmins <= 1) {
                $error = 'Superadmin aktif terakhir tidak dapat diturunkan role-nya atau dinonaktifkan.';
            }
        }

        if ($error !== '') {
            // Pertahankan form agar Superadmin dapat memperbaiki pilihan.
        } elseif ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $update = $pdo->prepare("
                UPDATE users 
                SET full_name = :full_name,
                    email = :email,
                    phone = :phone,
                    password = :password,
                    role = :role,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $update->execute([
                ':full_name' => $full_name,
                ':email' => $email ?: null,
                ':phone' => $phone ?: null,
                ':password' => $hash,
                ':role' => $role,
                ':status' => $status,
                ':id' => $id
            ]);
        } elseif ($error === '') {
            $update = $pdo->prepare("
                UPDATE users 
                SET full_name = :full_name,
                    email = :email,
                    phone = :phone,
                    role = :role,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $update->execute([
                ':full_name' => $full_name,
                ':email' => $email ?: null,
                ':phone' => $phone ?: null,
                ':role' => $role,
                ':status' => $status,
                ':id' => $id
            ]);
        }

        if ($error === '') {
            $changeDescription = log_activity_changes($user, [
                'full_name' => $full_name,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'role' => $role,
                'status' => $status,
            ], [
                'full_name' => 'Nama',
                'email' => 'Email',
                'phone' => 'Telepon',
                'role' => 'Role',
                'status' => 'Status',
            ]);
            if ($password !== '') $changeDescription .= '; Password diperbarui.';
            log_activity($pdo, 'User Management', 'UPDATE', $changeDescription, (int)$id, 'users');
            redirect('pages/users/index.php');
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Edit User</h1>
            <p class="text-muted mb-0">Perbarui data akun pengguna.</p>
        </div>

        <a href="<?= base_url('pages/users/index.php'); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <div class="panel">
        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3"><?= htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Nama Lengkap</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']); ?>" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Username</label>
                <input type="text" value="<?= htmlspecialchars($user['username']); ?>" class="form-control" disabled>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? ''); ?>" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">No. Telepon</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? ''); ?>" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Password Baru</label>
                <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak diganti">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Role</label>
                <select name="role" class="form-select">
                    <?php $selectedRole = $_POST['role'] ?? $user['role']; ?>
                    <?php foreach (app_roles() as $roleValue => $roleLabel): ?>
                        <option value="<?= htmlspecialchars($roleValue); ?>" <?= $selectedRole === $roleValue ? 'selected' : ''; ?>><?= htmlspecialchars($roleLabel); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Direktur dan Komisaris hanya dapat melihat laporan.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <?php $selectedStatus = $_POST['status'] ?? $user['status']; ?>
                    <?php foreach (['active' => 'Active', 'pending' => 'Pending', 'inactive' => 'Inactive'] as $statusValue => $statusLabel): ?>
                        <option value="<?= $statusValue; ?>" <?= $selectedStatus === $statusValue ? 'selected' : ''; ?>><?= $statusLabel; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-save me-1"></i> Update User
                </button>
            </div>
        </form>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
