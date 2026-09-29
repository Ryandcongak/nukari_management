<?php

function request_is_https(): bool
{
    $forwardedProto = strtolower(first_proxy_value($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $cfVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
    $cfScheme = is_array($cfVisitor) ? strtolower((string)($cfVisitor['scheme'] ?? '')) : '';
    return $forwardedProto === 'https'
        || $cfScheme === 'https'
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function send_security_headers(): void
{
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Ambil nilai pertama dari header proxy yang dapat berisi daftar dipisahkan koma. */
function first_proxy_value($value): string
{
    return trim(explode(',', (string)$value, 2)[0], " \t\n\r\0\x0B\"");
}

/**
 * URL dasar aplikasi untuk localhost, hosting, dan HTTPS reverse proxy/tunnel.
 *
 * NUKARI_BASE_URL adalah sumber utama bila disetel. Jika tidak, URL dibentuk
 * dari request dan header proxy standar. Path instalasi dihitung dari lokasi
 * file aplikasi, sehingga nama folder project tidak perlu di-hardcode.
 */
function base_url($path = '')
{
    $configured = trim((string)(getenv('NUKARI_BASE_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . ltrim((string)$path, '/');
    }

    $forwarded = first_proxy_value($_SERVER['HTTP_FORWARDED'] ?? '');
    $forwardedProtoStandard = '';
    $forwardedHostStandard = '';
    if ($forwarded !== '') {
        foreach (explode(';', $forwarded) as $parameter) {
            [$name, $value] = array_pad(explode('=', trim($parameter), 2), 2, '');
            if (strtolower($name) === 'proto') $forwardedProtoStandard = trim($value, " \t\"");
            if (strtolower($name) === 'host') $forwardedHostStandard = trim($value, " \t\"");
        }
    }

    $forwardedProto = first_proxy_value($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') ?: $forwardedProtoStandard;
    $cfVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
    $cfScheme = is_array($cfVisitor) ? ($cfVisitor['scheme'] ?? '') : '';
    $scheme = strtolower($forwardedProto ?: (string)$cfScheme);

    if (!in_array($scheme, ['http', 'https'], true)) {
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        $scheme = $isHttps || (string)($_SERVER['SERVER_PORT'] ?? '') === '443' ? 'https' : 'http';
    }

    $forwardedHost = first_proxy_value($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '') ?: $forwardedHostStandard;
    $host = $forwardedHost ?: (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    // Mencegah karakter ilegal/header injection masuk ke URL dan Location header.
    if (!preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::\d+)?$/i', $host)) {
        $host = 'localhost';
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $scriptFile = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    $basePath = '';

    if ($scriptFile !== '' && str_starts_with($scriptFile, $appRoot . '/')) {
        $relativeScript = substr($scriptFile, strlen($appRoot));
        if ($relativeScript !== '' && str_ends_with($scriptName, $relativeScript)) {
            $basePath = substr($scriptName, 0, -strlen($relativeScript));
        }
    }

    // Prefix diperlukan bila aplikasi dipublikasikan di subpath reverse proxy.
    $proxyPrefix = first_proxy_value($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '');
    if ($proxyPrefix !== '' && str_starts_with($proxyPrefix, '/')) {
        $basePath = rtrim($proxyPrefix, '/') . '/' . ltrim($basePath, '/');
    }

    $basePath = '/' . trim($basePath, '/');
    if ($basePath === '/') $basePath = '';

    return $scheme . '://' . $host . $basePath . '/' . ltrim((string)$path, '/');
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
