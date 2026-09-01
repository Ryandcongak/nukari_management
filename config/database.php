<?php
$host = getenv('NUKARI_DB_HOST') ?: 'localhost';
$port = getenv('NUKARI_DB_PORT') ?: '3306';
$dbname = getenv('NUKARI_DB_NAME') ?: 'nukari_management';
$username = getenv('NUKARI_DB_USER') ?: 'root';
$password = getenv('NUKARI_DB_PASS');
$password = $password === false ? 'root' : $password;

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Koneksi database gagal. Pastikan MySQL MAMP aktif dan konfigurasi database sudah benar.');
}
