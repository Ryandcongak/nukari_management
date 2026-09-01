<?php
require_once __DIR__ . '/../../includes/auth.php';

if (!has_role('super_admin', 'admin')) {
    redirect('pages/dashboard.php');
}

$pageTitle = 'Tambah User - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = clean_input($_POST['full_name'] ?? '');
    $username = clean_input($_POST['username'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $role = clean_input($_POST['role'] ?? 'staff');
    $status = clean_input($_POST['status'] ?? 'active');
    $password = $_POST['password'] ?? '';

    if ($full_name === '' || $username === '' || $password === '') {
        $error = 'Nama lengkap, username, dan password wajib diisi.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1");
        $check->execute([
            ':username' => $username,
            ':email' => $email
        ]);

        if ($check->fetch()) {
            $error = 'Username atau email sudah digunakan.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, username, email, phone, password, role, status)
                VALUES (:full_name, :username, :email, :phone, :password, :role, :status)
            ");

            $stmt->execute([
                ':full_name' => $full_name,
                ':username' => $username,
                ':email' => $email ?: null,
                ':phone' => $phone ?: null,
                ':password' => $hash,
                ':role' => $role,
                ':status' => $status
            ]);

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
            <h1 class="fw-bold mb-1">Tambah User</h1>
            <p class="text-muted mb-0">Tambahkan akun pengguna baru.</p>
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
                <input type="text" name="full_name" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="email" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">No. Telepon</label>
                <input type="text" name="phone" class="form-control">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Role</label>
                <select name="role" class="form-select">
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="finance">Finance</option>
                    <option value="staff">Staff</option>
                    <option value="tukang">Tukang</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-nukari">
                    <i class="bi bi-save me-1"></i> Simpan User
                </button>
            </div>
        </form>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>