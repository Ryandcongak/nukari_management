<?php
/*
|--------------------------------------------------------------------------
| SIDEBAR - Nukari Management System
|--------------------------------------------------------------------------
| Isi file:
| 1. Menu utama sistem
| 2. Menu payroll collapse
| 3. Sidebar mendukung hide/show dari tombol di header
*/

$currentPath = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('is_active_menu')) {
    function is_active_menu($keyword, $currentPath)
    {
        return strpos($currentPath, $keyword) !== false ? 'active' : '';
    }
}

// $isPayrollOpen = strpos($currentPath, 'payroll') !== false;
$isPayrollOpen = strpos($currentPath, 'payroll') !== false || strpos($currentPath, 'hr/analytics.php') !== false;
$isProjectOpen = strpos($currentPath, 'projects') !== false;
$isCashierOpen = strpos($currentPath, 'products/cashier') !== false || strpos($currentPath, 'cashier.php') !== false || strpos($currentPath, 'pos.php') !== false;
$isProductsOpen = strpos($currentPath, 'products') !== false && !$isCashierOpen;
?>

<aside class="sidebar" id="appSidebar">

    <!-- ===============================
         BRAND
    ================================= -->
    <div class="brand-title">
        Nukari Management
    </div>
    <div class="brand-subtitle">
        PT Nukari Kriya Raya
    </div>

    <!-- ===============================
         NAVIGATION
    ================================= -->
    <nav class="nav flex-column">

        <div class="nav-section-title">Main</div>

        <a class="nav-link <?= is_active_menu('dashboard', $currentPath); ?>" href="<?= base_url('pages/dashboard.php'); ?>">
            <i class="bi bi-speedometer2 me-2"></i> Dashboard
        </a>

        <a class="nav-link <?= is_active_menu('users', $currentPath); ?>" href="<?= base_url('pages/users/index.php'); ?>">
            <i class="bi bi-people me-2"></i> User Management
        </a>

        <div class="nav-section-title">HR & Payroll</div>

        <a class="nav-link <?= is_active_menu('employees', $currentPath); ?>" href="<?= base_url('pages/employees/index.php'); ?>">
            <i class="bi bi-person-badge me-2"></i> Karyawan
        </a>

        <a class="nav-link <?= is_active_menu('attendances', $currentPath); ?>" href="<?= base_url('pages/attendances/index.php'); ?>">
            <i class="bi bi-fingerprint me-2"></i> Absensi
        </a>

        <!-- PAYROLL SUBMENU -->
        <a
            class="nav-link d-flex justify-content-between align-items-center <?= $isPayrollOpen ? 'active' : ''; ?>"
            data-bs-toggle="collapse"
            href="#payrollMenu"
            role="button"
            aria-expanded="<?= $isPayrollOpen ? 'true' : 'false'; ?>">
            <span><i class="bi bi-cash-coin me-2"></i> Payroll</span>
            <i class="bi bi-chevron-down small"></i>
        </a>

        <div class="collapse <?= $isPayrollOpen ? 'show' : ''; ?>" id="payrollMenu">
            <a class="nav-link ms-3 <?= is_active_menu('payroll/index.php', $currentPath); ?>" href="<?= base_url('pages/payroll/index.php'); ?>">
                <i class="bi bi-circle me-2 small"></i> Ringkasan Gaji
            </a>

            <a class="nav-link ms-3 <?= is_active_menu('payroll/settings.php', $currentPath); ?>" href="<?= base_url('pages/payroll/settings.php'); ?>">
                <i class="bi bi-circle me-2 small"></i> Setting Payroll
            </a>

            <a class="nav-link ms-3 <?= is_active_menu('hr/analytics.php', $currentPath); ?>" href="<?= base_url('pages/hr/analytics.php'); ?>">
                <i class="bi bi-circle me-2 small"></i> HR Analytics
            </a>
        </div>

        <div class="nav-section-title">Operations</div>

        <a class="nav-link <?= is_active_menu('projects', $currentPath); ?>" href="<?= base_url('pages/projects/index.php'); ?>">
            <i class="bi bi-kanban me-2"></i> Project
        </a>


        <a class="nav-link <?= is_active_menu('clients', $currentPath); ?>" href="<?= base_url('pages/clients/index.php'); ?>">
            <i class="bi bi-building me-2"></i> Client
        </a>

        <a class="nav-link <?= is_active_menu('suppliers', $currentPath); ?>" href="<?= base_url('pages/suppliers/index.php'); ?>">
            <i class="bi bi-truck me-2"></i> Vendor / Supplier
        </a>
        <a class="nav-link <?= is_active_menu('assets', $currentPath); ?>" href="<?= base_url('pages/assets/index.php'); ?>">
            <i class="bi bi-box-seam me-2"></i> Aset / Inventaris
        </a>

        <a class="nav-link <?= $isProductsOpen ? 'active' : ''; ?>" href="<?= base_url('pages/products/index.php'); ?>">
            <i class="bi bi-recycle me-2"></i> Produk
        </a>

        <a class="nav-link <?= $isCashierOpen ? 'active' : ''; ?>" href="<?= base_url('pages/products/cashier.php'); ?>">
            <i class="bi bi-upc-scan me-2"></i> Kasir / POS
        </a>

        <div class="nav-section-title">Finance</div>

        <a class="nav-link <?= is_active_menu('finance', $currentPath); ?>" href="<?= base_url('pages/finance/index.php'); ?>">
            <i class="bi bi-cash-stack me-2"></i> Keuangan
        </a>

        <a class="nav-link <?= is_active_menu('operational_funds', $currentPath); ?>" href="<?= base_url('pages/finance/operational_funds.php'); ?>">
            <i class="bi bi-wallet2 me-2"></i> Dana Operasional
        </a>

        <a class="nav-link <?= is_active_menu('debts', $currentPath); ?>" href="<?= base_url('pages/debts/index.php'); ?>">
            <i class="bi bi-wallet2 me-2"></i> Hutang & Piutang
        </a>

        <a class="nav-link <?= is_active_menu('reimbursements', $currentPath); ?>" href="<?= base_url('pages/reimbursements/index.php'); ?>">
            <i class="bi bi-receipt-cutoff me-2"></i> Dana Talangan
        </a>

        <a class="nav-link <?= is_active_menu('reports', $currentPath); ?>" href="<?= base_url('pages/reports/index.php'); ?>">
            <i class="bi bi-file-earmark-bar-graph me-2"></i> Laporan
        </a>

        <div class="nav-section-title">System</div>

        <a class="nav-link <?= is_active_menu('notifications', $currentPath); ?>" href="<?= base_url('pages/notifications/index.php'); ?>">
            <i class="bi bi-bell me-2"></i> Reminder / Notifikasi
        </a>

        <hr class="border-light opacity-25">

        <a class="nav-link" href="<?= base_url('logout.php'); ?>">
            <i class="bi bi-box-arrow-right me-2"></i> Logout
        </a>

    </nav>
</aside>