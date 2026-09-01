<?php
require_once __DIR__ . '/../../includes/auth.php';

if (!has_role('super_admin', 'admin')) {
    redirect('pages/dashboard.php');
}

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
    $role = clean_input($_POST['role'] ?? 'staff');
    $status = clean_input($_POST['status'] ?? 'active');
    $password = $_POST['password'] ?? '';

    if ($full_name === '') {
        $error = 'Nama lengkap wajib diisi.';
    } else {
        if ($password !== '') {
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
        } else {
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

        redirect('pages/users/index.php');
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
                    <option value="super_admin" <?= $user['role'] === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="manager" <?= $user['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                    <option value="finance" <?= $user['role'] === 'finance' ? 'selected' : ''; ?>>Finance</option>
                    <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="tukang" <?= $user['role'] === 'tukang' ? 'selected' : ''; ?>>Tukang</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="pending" <?= $user['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
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