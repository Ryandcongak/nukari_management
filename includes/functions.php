<?php

function base_url($path = '')
{
    $configured = getenv('NUKARI_BASE_URL');
    if ($configured) return rtrim($configured, '/') . '/' . ltrim($path, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8888';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/nukari_management/index.php');
    $marker = '/nukari_management/';
    $position = strpos($script, $marker);
    $basePath = $position !== false ? substr($script, 0, $position + strlen($marker) - 1) : rtrim(dirname($script), '/');
    return $scheme . '://' . $host . $basePath . '/' . ltrim($path, '/');
}

function redirect($path)
{
    header("Location: " . base_url($path));
    exit;
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

function has_role(...$roles)
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles);
}

function rupiah($angka)
{
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

function clean_input($data)
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function current_user_name()
{
    return $_SESSION['name'] ?? $_SESSION['username'] ?? 'System';
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_or_abort(): void
{
    $provided = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('Sesi formulir sudah tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Metode tidak diizinkan.');
    }
}

function require_role(...$roles): void
{
    if (!has_role(...$roles)) {
        http_response_code(403);
        exit('Anda tidak memiliki izin untuk menjalankan tindakan ini.');
    }
}

function validate_upload(array $file, array $allowedMime, int $maxBytes = 5242880): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload file gagal.');
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) throw new RuntimeException('Ukuran file melebihi batas.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) throw new RuntimeException('Isi file tidak sesuai dengan format yang diizinkan.');
    return $mime;
}
