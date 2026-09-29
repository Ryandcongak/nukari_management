<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Smoke test hanya boleh dijalankan dari CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$phpBinary = PHP_BINARY;
$marker = '__NUKARI_SMOKE_OK__';

$pages = [
    'pages/dashboard.php' => [],
    'pages/reimbursements/index.php' => ['q' => '__nukari_smoke__'],
    'pages/debts/index.php' => ['q' => '__nukari_smoke__'],
    'pages/assets/index.php' => ['q' => '__nukari_smoke__'],
    'pages/clients/index.php' => ['q' => '__nukari_smoke__'],
    'pages/suppliers/index.php' => ['q' => '__nukari_smoke__'],
    'pages/products/simple.php' => ['q' => '__nukari_smoke__'],
    'pages/employees/index.php' => ['search' => '__nukari_smoke__'],
    'pages/attendances/index.php' => ['from' => '2000-01-01', 'to' => date('Y-m-d'), 'review' => 'pending'],
    'pages/hr/leaves.php' => ['keyword' => '__nukari_smoke__', 'month' => date('Y-m')],
    'pages/payroll/cash_advance.php' => ['keyword' => '__nukari_smoke__', 'month' => date('Y-m')],
    'pages/payroll/components.php' => ['keyword' => '__nukari_smoke__', 'month' => date('Y-m')],
    'pages/payroll/loans.php' => ['keyword' => '__nukari_smoke__'],
    'pages/finance/transactions.php' => ['q' => '__nukari_smoke__'],
    'pages/reports/index.php' => ['type' => 'payroll', 'from' => '2000-01-01', 'to' => date('Y-m-d')],
    'pages/projects/index.php' => ['q' => '__nukari_smoke__'],
];

$childCode = <<<'PHP'
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
$_SESSION = ['user_id' => 0, 'role' => 'super_admin', 'name' => 'Smoke Test'];
$_GET = json_decode(base64_decode($argv[2]), true) ?: [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8888';
$_SERVER['SERVER_PORT'] = '8888';
$_SERVER['SCRIPT_NAME'] = '/nukari_management/' . $argv[1];
$_SERVER['SCRIPT_FILENAME'] = getcwd() . '/' . $argv[1];
ob_start();
include $argv[1];
while (ob_get_level()) ob_end_clean();
echo '__NUKARI_SMOKE_OK__';
PHP;

function run_child(array $command, string $cwd): array
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return [1, '', 'Gagal membuat proses PHP.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
}

$failures = [];
foreach ($pages as $page => $query) {
    [$exitCode, $stdout, $stderr] = run_child([
        $phpBinary,
        '-r',
        $childCode,
        $page,
        base64_encode((string)json_encode($query)),
    ], $root);

    if ($exitCode !== 0 || !str_contains($stdout, $marker) || trim($stderr) !== '') {
        $failures[] = $page . ': ' . trim($stderr ?: $stdout ?: "exit {$exitCode}");
        continue;
    }
    echo "OK {$page}\n";
}

$exportCode = <<<'PHP'
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
$_SESSION = ['user_id' => 0, 'role' => 'super_admin', 'name' => 'Smoke Test'];
$_GET = ['month' => date('Y-m'), 'keyword' => '__nukari_smoke__'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost:8888';
$_SERVER['SERVER_PORT'] = '8888';
$_SERVER['SCRIPT_NAME'] = '/nukari_management/pages/payroll/export_payroll_excel.php';
$_SERVER['SCRIPT_FILENAME'] = getcwd() . '/pages/payroll/export_payroll_excel.php';
include 'pages/payroll/export_payroll_excel.php';
PHP;

[$exportExit, $exportBytes, $exportError] = run_child([$phpBinary, '-r', $exportCode], $root);
$tempFile = tempnam(sys_get_temp_dir(), 'nukari_smoke_xlsx_');
if ($exportExit !== 0 || !str_starts_with($exportBytes, "PK") || trim($exportError) !== '') {
    $failures[] = 'payroll export: ' . trim($exportError ?: "output XLSX tidak valid (exit {$exportExit})");
} elseif ($tempFile === false) {
    $failures[] = 'payroll export: gagal membuat file sementara';
} else {
    file_put_contents($tempFile, $exportBytes);
    $zip = new ZipArchive();
    $opened = $zip->open($tempFile);
    if ($opened !== true || $zip->locateName('xl/worksheets/sheet1.xml') === false) {
        $failures[] = 'payroll export: struktur ZIP/XLSX tidak valid';
    } else {
        echo 'OK payroll export (' . strlen($exportBytes) . " bytes)\n";
    }
    if ($opened === true) $zip->close();
    unlink($tempFile);
}

if ($failures) {
    fwrite(STDERR, "Smoke test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Critical read-page smoke tests passed.\n";
