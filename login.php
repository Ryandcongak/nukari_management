<?php
require_once __DIR__ . '/includes/functions.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', request_is_https() ? '1' : '0');
session_start();
send_security_headers();

require_once __DIR__ . '/config/database.php';

$error = isset($_GET['role_invalid'])
    ? 'Role akun belum sesuai dengan konfigurasi sistem. Hubungi Superadmin.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_abort();
    $username = clean_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1");
        $stmt->execute([
            ':username' => $username,
            ':email' => $username
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                $error = 'Akun Anda belum aktif atau sedang dinonaktifkan.';
            } elseif (!is_valid_app_role((string)$user['role'])) {
                $error = 'Role akun belum sesuai dengan konfigurasi sistem. Hubungi Superadmin.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
                $update->execute([':id' => $user['id']]);

                redirect(default_page_for_role((string)$user['role']));
            }
        } else {
            $error = 'Username/email atau password salah.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Login - Nukari Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CDN -->
    <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0f3d2e 0%, #1f7a4d 45%, #f4f7f5 45%, #f4f7f5 100%);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Arial, sans-serif;
        }

        .login-wrapper {
            min-height: 100vh;
        }

        .login-card {
            border: none;
            border-radius: 24px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.12);
            overflow: hidden;
        }

        .brand-panel {
            background: #0f3d2e;
            color: #fff;
            padding: 40px;
        }

        .brand-panel h1 {
            font-weight: 800;
            font-size: 32px;
        }

        .brand-panel p {
            color: #b7d8c7;
            line-height: 1.7;
        }

        .login-panel {
            padding: 40px;
            background: #ffffff;
        }

        .form-control {
            border-radius: 12px;
            padding: 12px 14px;
        }

        .btn-login {
            background: #1f7a4d;
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 700;
        }

        .btn-login:hover {
            background: #16643e;
        }

        .small-muted {
            color: #6b7280;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            body {
                background: #f4f7f5;
            }

            .brand-panel {
                display: none;
            }

            .login-panel {
                padding: 28px;
            }
        }
    </style>
</head>

<body>

    <div class="container login-wrapper d-flex align-items-center justify-content-center">
        <div class="row w-100 justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="card login-card">
                    <div class="row g-0">

                        <div class="col-md-5 brand-panel d-flex flex-column justify-content-between">
                            <div>
                                <h1>Nukari Management</h1>
                                <p class="mt-3">
                                    Sistem internal PT Nukari Kriya Raya untuk mengelola project,
                                    keuangan, karyawan, aset, dan laporan operasional perusahaan.
                                </p>
                            </div>

                            <div>
                                <small>PT Nukari Kriya Raya</small>
                            </div>
                        </div>

                        <div class="col-md-7 login-panel">
                            <div class="mb-4">
                                <h3 class="fw-bold mb-1">Masuk ke Sistem</h3>
                                <p class="small-muted mb-0">Silakan login menggunakan akun yang sudah terdaftar.</p>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger rounded-3">
                                    <?= htmlspecialchars($error); ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST">
                                <?= csrf_field(); ?>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Username / Email</label>
                                    <input
                                        type="text"
                                        name="username"
                                        class="form-control"
                                        placeholder="Masukkan username atau email"
                                        required>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Password</label>
                                    <input
                                        type="password"
                                        name="password"
                                        class="form-control"
                                        placeholder="Masukkan password"
                                        required>
                                </div>

                                <button type="submit" class="btn btn-success btn-login w-100">
                                    Login
                                </button>
                            </form>

                            <div class="mt-4 text-center small-muted">
                                © <?= date('Y'); ?> Nukari Management System
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>

</body>

</html>
