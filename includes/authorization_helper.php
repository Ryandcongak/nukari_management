<?php
declare(strict_types=1);

function mutation_role_rules(): array
{
    return [
        '/pages/users/' => ['super_admin', 'admin'],
        '/pages/payroll/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/finance/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/debts/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/reimbursements/' => ['super_admin', 'admin', 'manager', 'finance'],
        '/pages/employees/' => ['super_admin', 'admin', 'manager'],
        '/pages/attendances/' => ['super_admin', 'admin', 'manager'],
        '/pages/hr/' => ['super_admin', 'admin', 'manager'],
        '/pages/projects/' => ['super_admin', 'admin', 'manager', 'finance', 'staff'],
        '/pages/assets/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/products/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/clients/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/suppliers/' => ['super_admin', 'admin', 'manager', 'staff'],
        '/pages/notifications/' => ['super_admin', 'admin', 'manager'],
        '/pages/master/' => ['super_admin', 'admin', 'manager', 'finance'],
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
