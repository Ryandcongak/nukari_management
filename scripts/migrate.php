<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(120) PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($files, SORT_NATURAL);
$baseline = in_array('--baseline', $argv, true);
$baselineThrough = 15;
$requiredBaselineTables = ['users','employees','projects','transactions','finance_accounts','project_tasks','project_task_members','master_data_items','project_fund_receipts'];
if ($baseline) {
    foreach ($requiredBaselineTables as $table) {
        $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
        $check->execute([':table'=>$table]);
        if (!(int)$check->fetchColumn()) {
            fwrite(STDERR,"Baseline dibatalkan: tabel {$table} belum tersedia. Impor schema/dump dan migrasi yang sesuai terlebih dahulu.\n");
            exit(1);
        }
    }
}
$applied = $pdo->query('SELECT version,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($files as $file) {
    $version = basename($file);
    $sql = (string)file_get_contents($file);
    $checksum = hash('sha256', $sql);
    if (isset($applied[$version])) {
        if (!hash_equals((string)$applied[$version], $checksum)) fwrite(STDERR, "PERINGATAN checksum berubah: {$version}\n");
        continue;
    }
    $migrationNumber = preg_match('/^(\d+)_/', $version, $matches) ? (int)$matches[1] : null;
    if ($baseline && $migrationNumber !== null && $migrationNumber <= $baselineThrough) {
        $stmt=$pdo->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(:version,:checksum)');
        $stmt->execute([':version'=>$version,':checksum'=>$checksum]);
        echo "BASELINE {$version}\n";
        continue;
    }
    echo "APPLY {$version}\n";
    try {
        $pdo->exec($sql);
        $stmt=$pdo->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(:version,:checksum)');
        $stmt->execute([':version'=>$version,':checksum'=>$checksum]);
    } catch (Throwable $e) {
        fwrite(STDERR, "GAGAL {$version}: {$e->getMessage()}\n");
        exit(1);
    }
}
echo $baseline ? "Baseline 001-015 dan migrasi berikutnya selesai.\n" : "Database sudah terbaru.\n";
