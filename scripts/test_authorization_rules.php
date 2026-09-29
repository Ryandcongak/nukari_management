<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorization_helper.php';

$cases = [
    ['super_admin', '/nukari_management/pages/users/edit.php', true],
    ['admin', '/nukari_management/pages/users/edit.php', true],
    ['manager', '/nukari_management/pages/users/edit.php', false],
    ['finance', '/nukari_management/pages/payroll/pay.php', true],
    ['staff', '/nukari_management/pages/payroll/pay.php', false],
    ['manager', '/nukari_management/pages/attendances/index.php', true],
    ['finance', '/nukari_management/pages/attendances/index.php', false],
    ['staff', '/nukari_management/pages/projects/add.php', true],
    ['tukang', '/nukari_management/pages/projects/add.php', false],
    ['staff', '/nukari_management/pages/products/cashier.php', true],
    ['finance', '/nukari_management/pages/products/cashier.php', false],
    ['finance', '/nukari_management/pages/master/categories.php', true],
    ['staff', '/nukari_management/pages/master/categories.php', false],
];

foreach ($cases as [$role, $path, $expected]) {
    $actual = role_can_mutate_path($role, $path);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$role} on {$path}: expected " . ($expected ? 'allow' : 'deny') . "\n");
        exit(1);
    }
}

$pagesDir = realpath(__DIR__ . '/../pages');
$coveredPostPages = 0;
$uncoveredPostPages = [];
if ($pagesDir !== false) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $source = (string)file_get_contents($file->getPathname());
        if (!str_contains($source, '$_POST') && !preg_match('/REQUEST_METHOD[^\n]{0,80}POST/', $source)) continue;
        $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen(dirname($pagesDir))));
        if (mutation_roles_for_path($relativePath) === null) {
            $uncoveredPostPages[] = $relativePath;
        } else {
            $coveredPostPages++;
        }
    }
}
if ($uncoveredPostPages) {
    fwrite(STDERR, "FAIL halaman POST tanpa aturan role:\n- " . implode("\n- ", $uncoveredPostPages) . "\n");
    exit(1);
}

echo 'Authorization rule tests passed (' . count($cases) . " cases, {$coveredPostPages} POST pages covered).\n";
