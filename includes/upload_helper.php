<?php
declare(strict_types=1);

/**
 * Upload terpusat untuk seluruh modul non-HR/payroll.
 *
 * Path yang disimpan di database tetap memakai format `uploads/...` agar data
 * lama kompatibel. Akses HTTP ke path tersebut diarahkan ke endpoint download
 * terlindungi oleh .htaccess di root aplikasi.
 */

function upload_image_mimes(bool $allowGif = false): array
{
    $mimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if ($allowGif) $mimes['image/gif'] = 'gif';
    return $mimes;
}

function upload_document_mimes(): array
{
    return upload_image_mimes() + [
        'application/pdf' => 'pdf',
    ];
}

function upload_project_document_mimes(): array
{
    return upload_document_mimes() + [
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        // Beberapa versi libmagic membaca Office lama sebagai OLE dan OOXML
        // sebagai ZIP. Ekstensi asli tetap harus termasuk daftar ini.
        'application/x-ole-storage' => ['doc', 'xls', 'ppt'],
        'application/CDFV2' => ['doc', 'xls', 'ppt'],
        'application/vnd.ms-office' => ['doc', 'xls', 'ppt'],
        'application/zip' => ['docx', 'xlsx', 'pptx'],
    ];
}

function upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas server.',
        UPLOAD_ERR_PARTIAL => 'File hanya terunggah sebagian. Silakan ulangi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara upload tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file upload.',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.',
        default => 'Upload file gagal.',
    };
}

function upload_storage_root(): string
{
    $root = dirname(__DIR__) . '/uploads';
    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('Folder penyimpanan upload tidak dapat dibuat.');
    }
    return $root;
}

function upload_safe_directory(string $directory): string
{
    $directory = trim(str_replace('\\', '/', $directory), '/');
    if ($directory === '' || str_contains($directory, '..') || !preg_match('/^[a-zA-Z0-9_\/-]+$/', $directory)) {
        throw new InvalidArgumentException('Folder upload tidak valid.');
    }
    return $directory;
}

function upload_safe_prefix(string $prefix): string
{
    $prefix = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', $prefix), '_');
    return $prefix !== '' ? $prefix : 'file';
}

/**
 * Simpan satu file upload. Mengembalikan null bila field memang dikosongkan.
 * Error upload selain NO_FILE selalu dilempar agar transaksi tidak terlihat
 * berhasil ketika lampirannya sebenarnya gagal.
 */
function upload_store_file(
    array $file,
    string $directory,
    string $prefix,
    array $allowedMimeToExtension,
    int $maxBytes = 5242880
): ?string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $name = trim((string)($file['name'] ?? ''));
    if ($error === UPLOAD_ERR_NO_FILE || $name === '') return null;
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException(upload_error_text($error));

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException('File sementara upload tidak ditemukan.');
    }

    $actualSize = filesize($tmpName);
    if ($actualSize === false || $actualSize <= 0) throw new RuntimeException('File upload kosong.');
    if ($actualSize > $maxBytes) {
        throw new RuntimeException('Ukuran file maksimal ' . number_format($maxBytes / 1048576, 0, ',', '.') . ' MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $extensionRule = $allowedMimeToExtension[$mime] ?? null;
    if (!$extensionRule) throw new RuntimeException('Format isi file tidak didukung.');
    if (is_array($extensionRule)) {
        $originalExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extension = in_array($originalExtension, $extensionRule, true) ? $originalExtension : null;
    } else {
        $extension = (string)$extensionRule;
    }
    if (!$extension || !preg_match('/^[a-z0-9]+$/', $extension)) {
        throw new RuntimeException('Ekstensi file tidak sesuai dengan format isinya.');
    }

    $directory = upload_safe_directory($directory);
    $targetDir = upload_storage_root() . '/' . $directory;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Folder tujuan upload tidak dapat dibuat.');
    }

    $fileName = upload_safe_prefix($prefix) . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $target = $targetDir . '/' . $fileName;
    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('File gagal disimpan ke penyimpanan aplikasi.');
    }
    @chmod($target, 0640);

    return 'uploads/' . $directory . '/' . $fileName;
}

/** @return array<int,string> */
function upload_store_multiple(
    ?array $files,
    string $directory,
    string $prefix,
    array $allowedMimeToExtension,
    int $maxBytesPerFile = 5242880,
    int $maxFiles = 10,
    bool $required = false
): array {
    if (!$files || empty($files['name']) || !is_array($files['name'])) {
        if ($required) throw new RuntimeException('Minimal pilih satu file untuk diupload.');
        return [];
    }

    $selected = array_values(array_filter($files['name'], static fn($name): bool => trim((string)$name) !== ''));
    if (!$selected) {
        if ($required) throw new RuntimeException('Minimal pilih satu file untuk diupload.');
        return [];
    }
    if (count($selected) > $maxFiles) {
        throw new RuntimeException("Maksimal {$maxFiles} file dalam satu kali upload.");
    }

    $stored = [];
    try {
        foreach ($files['name'] as $index => $name) {
            if (trim((string)$name) === '') continue;
            $file = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
            $path = upload_store_file($file, $directory, $prefix, $allowedMimeToExtension, $maxBytesPerFile);
            if ($path !== null) $stored[] = $path;
        }
    } catch (Throwable $e) {
        foreach ($stored as $path) upload_delete_file($path);
        throw $e;
    }

    if ($required && !$stored) throw new RuntimeException('Tidak ada file yang berhasil diupload.');
    return $stored;
}

function upload_resolve_file(?string $path): ?string
{
    $path = trim((string)$path);
    if ($path === '' || preg_match('~^https?://~i', $path)) return null;
    $path = rawurldecode(parse_url($path, PHP_URL_PATH) ?: $path);
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (!str_starts_with($path, 'uploads/') || str_contains($path, '..')) return null;

    $root = realpath(upload_storage_root());
    $file = realpath(dirname(__DIR__) . '/' . $path);
    if (!$root || !$file || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) return null;
    return $file;
}

function upload_delete_file(?string $path): bool
{
    $file = upload_resolve_file($path);
    return $file ? @unlink($file) : false;
}

function upload_file_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    return base_url('pages/files/download.php?path=' . rawurlencode(ltrim($path, '/')));
}
