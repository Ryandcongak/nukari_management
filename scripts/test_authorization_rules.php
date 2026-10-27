<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/authorization_helper.php';

$cases = [
    ['super_admin', '/nukari_management/pages/users/edit.php', true],
    ['admin', '/nukari_management/pages/users/edit.php', false],
    ['manager', '/nukari_management/pages/users/edit.php', false],
    ['admin', '/nukari_management/pages/payroll/pay.php', true],
    ['director', '/nukari_management/pages/payroll/pay.php', false],
    ['commissioner', '/nukari_management/pages/payroll/pay.php', false],
    ['admin', '/nukari_management/pages/attendances/index.php', true],
    ['director', '/nukari_management/pages/attendances/index.php', false],
    ['admin', '/nukari_management/pages/projects/add.php', true],
    ['commissioner', '/nukari_management/pages/projects/add.php', false],
    ['admin', '/nukari_management/pages/products/cashier.php', true],
    ['director', '/nukari_management/pages/products/cashier.php', false],
    ['admin', '/nukari_management/pages/master/categories.php', true],
    ['commissioner', '/nukari_management/pages/master/categories.php', false],
];

foreach ($cases as [$role, $path, $expected]) {
    $actual = role_can_mutate_path($role, $path);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$role} on {$path}: expected " . ($expected ? 'allow' : 'deny') . "\n");
        exit(1);
    }
}

$readCases = [
    ['super_admin', '/nukari_management/pages/users/index.php', true],
    ['admin', '/nukari_management/pages/users/index.php', false],
    ['super_admin', '/nukari_management/pages/dashboard.php', true],
    ['admin', '/nukari_management/pages/dashboard.php', true],
    ['director', '/nukari_management/pages/dashboard.php', false],
    ['director', '/nukari_management/pages/notifications/index.php', false],
    ['commissioner', '/nukari_management/pages/payroll/index.php', false],
    ['director', '/nukari_management/pages/help/index.php', true],
    ['commissioner', '/nukari_management/pages/help/index.php', true],
    ['super_admin', '/nukari_management/pages/reports/index.php', true],
    ['admin', '/nukari_management/pages/reports/index.php', true],
    ['director', '/nukari_management/pages/reports/index.php', true],
    ['commissioner', '/nukari_management/pages/reports/export.php', true],
];

foreach ($readCases as [$role, $path, $expected]) {
    $actual = role_can_read_path($role, $path);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL read {$role} on {$path}: expected " . ($expected ? 'allow' : 'deny') . "\n");
        exit(1);
    }
}

$permissionCases = [
    ['super_admin', 'users.manage', true],
    ['admin', 'users.manage', false],
    ['admin', 'operations.manage', true],
    ['director', 'operations.view', false],
    ['director', 'reports.view', true],
    ['director', 'notifications.view', true],
    ['commissioner', 'reports.export', true],
    ['commissioner', 'notifications.view', true],
];

foreach ($permissionCases as [$role, $permission, $expected]) {
    $actual = role_has_permission($role, $permission);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL permission {$role} for {$permission}: expected " . ($expected ? 'allow' : 'deny') . "\n");
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

echo 'Authorization rule tests passed (' . count($cases) . ' mutation, ' . count($readCases) . ' read, ' . count($permissionCases) . " permission cases; {$coveredPostPages} POST pages covered).\n";
