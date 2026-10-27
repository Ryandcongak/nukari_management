<?php
declare(strict_types=1);

function mutation_role_rules(): array
{
    return [
        '/pages/users/' => ['super_admin'],
        '/pages/payroll/' => ['super_admin', 'admin'],
        '/pages/finance/' => ['super_admin', 'admin'],
        '/pages/debts/' => ['super_admin', 'admin'],
        '/pages/reimbursements/' => ['super_admin', 'admin'],
        '/pages/employees/' => ['super_admin', 'admin'],
        '/pages/attendances/' => ['super_admin', 'admin'],
        '/pages/hr/' => ['super_admin', 'admin'],
        '/pages/projects/' => ['super_admin', 'admin'],
        '/pages/assets/' => ['super_admin', 'admin'],
        '/pages/products/' => ['super_admin', 'admin'],
        '/pages/clients/' => ['super_admin', 'admin'],
        '/pages/suppliers/' => ['super_admin', 'admin'],
        '/pages/notifications/' => ['super_admin', 'admin'],
        '/pages/master/' => ['super_admin', 'admin'],
    ];
}

function mutation_roles_for_path(string $path): ?array
{
    $path = str_replace('\\', '/', $path);
    foreach (mutation_role_rules() as $prefix => $roles) {
        if (str_contains($path, $prefix)) {
            return $roles;
        }
    }

    return null;
}

function role_can_mutate_path(string $role, string $path): bool
{
    $roles = mutation_roles_for_path($path);
    return $roles === null || in_array($role, $roles, true);
}

function read_permission_for_path(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    if (str_contains($path, '/pages/users/')) return 'users.manage';
    if (str_contains($path, '/pages/reports/')) return 'reports.view';
    // Panduan adalah halaman baca-saja dan perlu tersedia untuk seluruh role,
    // termasuk Direktur/Komisaris yang hanya memakai Ringkasan Manajemen.
    if (str_contains($path, '/pages/help/')) return 'reports.view';
    if (str_contains($path, '/pages/')) return 'operations.view';
    return null;
}

function role_can_read_path(string $role, string $path): bool
{
    $permission = read_permission_for_path($path);
    return $permission === null || role_has_permission($role, $permission);
}
