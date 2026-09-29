<?php
/*
|--------------------------------------------------------------------------
| HEADER - Nukari Management System
|--------------------------------------------------------------------------
| Isi file:
| 1. Setup title halaman
| 2. Query notifikasi universal
| 3. Query notifikasi otomatis termin project
| 4. Layout dasar HTML, CSS global, topbar, dan app wrapper
|
| Catatan:
| - File ini dipanggil sebelum sidebar.php dan sebelum <main class="main-content">.
| - Pastikan auth.php sudah diload di halaman sebelum header.php.
| - Notifikasi manual menggunakan tabel system_notifications.
| - Notifikasi termin otomatis menggunakan tabel project_payment_terms.
*/

if (!isset($pageTitle)) {
    $pageTitle = 'Nukari Management System';
}

/*
|--------------------------------------------------------------------------
| HELPER SEDERHANA UNTUK TANGGAL NOTIFIKASI
|--------------------------------------------------------------------------
*/
if (!function_exists('nukari_days_text')) {
    function nukari_days_text($daysLeft)
    {
        $daysLeft = (int) $daysLeft;

        if ($daysLeft < 0) {
            return abs($daysLeft) . ' hari terlambat';
        }

        if ($daysLeft === 0) {
            return 'Hari ini';
        }

        if ($daysLeft === 1) {
            return 'Besok';
        }

        return $daysLeft . ' hari lagi';
    }
}

if (!function_exists('nukari_priority_badge')) {
    function nukari_priority_badge($daysLeft, $priority = 'warning')
    {
        $daysLeft = (int) $daysLeft;

        if ($daysLeft < 0) {
            return 'bg-danger';
        }

        if ($daysLeft === 0) {
            return 'bg-dark';
        }

        if ($priority === 'danger' || $priority === 'urgent') {
            return 'bg-danger';
        }

        if ($priority === 'low') {
            return 'bg-secondary';
        }

        return 'bg-warning text-dark';
    }
}

/*
|--------------------------------------------------------------------------
| NOTIFIKASI UNIVERSAL
|--------------------------------------------------------------------------
| Sumber notifikasi:
| 1. project_payment_terms  -> otomatis untuk termin project.
| 2. system_notifications   -> reminder umum/manual.
|
| Contoh system_notifications:
| - Ulang tahun karyawan
| - Samsat kendaraan
| - Pajak kendaraan
| - Ganti oli
| - Pulsa / internet jatuh tempo
| - Listrik dan air
| - Alat rusak harus diganti
| - Reminder operasional lainnya
*/
$allNotifications = [];
$totalNotifications = 0;

if (isset($pdo)) {
    try {
        /*
        |------------------------------------------------------------------
        | A. NOTIFIKASI TERMIN PROJECT OTOMATIS
        |------------------------------------------------------------------
        | Syarat:
        | - Belum paid
        | - Due date maksimal 7 hari ke depan
        | - Termasuk yang sudah lewat jatuh tempo
        */
        $termStmt = $pdo->prepare("\n            SELECT \n                pt.id,\n                pt.project_id,\n                pt.term_name,\n                pt.amount,\n                pt.due_date,\n                pt.status,\n                p.project_name,\n                DATEDIFF(pt.due_date, CURDATE()) AS days_left\n            FROM project_payment_terms pt\n            JOIN projects p ON p.id = pt.project_id\n            WHERE pt.status != 'paid'\n            AND pt.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)\n            ORDER BY pt.due_date ASC\n            LIMIT 20\n        ");
        $termStmt->execute();
        $termNotifications = $termStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($termNotifications as $term) {
            $daysLeft = (int) $term['days_left'];

            $allNotifications[] = [
                'type' => 'Termin Project',
                'icon' => 'bi-calendar-check',
                'title' => $term['project_name'],
                'description' => $term['term_name'] . ' - ' . (function_exists('rupiah') ? rupiah($term['amount']) : number_format($term['amount'], 0, ',', '.')),
                'due_date' => $term['due_date'],
                'days_left' => $daysLeft,
                'priority' => $daysLeft <= 0 ? 'danger' : 'warning',
                'link_url' => base_url('pages/projects/detail.php?id=' . $term['project_id'] . '&tab=terms#tab-terms')
            ];
        }

        /*
        |------------------------------------------------------------------
        | B. NOTIFIKASI UMUM / MANUAL
        |------------------------------------------------------------------
        | Tabel: system_notifications
        | Reminder aktif yang due date maksimal 7 hari ke depan.
        |
        | NOTE:
        | Jika tabel belum ada, catch akan mencegah halaman error.
        */
        $manualStmt = $pdo->prepare("\n            SELECT \n                id,\n                notification_type,\n                title,\n                description,\n                due_date,\n                priority,\n                icon,\n                link_url,\n                DATEDIFF(due_date, CURDATE()) AS days_left\n            FROM system_notifications\n            WHERE status = 'active'\n            AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)\n            ORDER BY due_date ASC, id DESC\n            LIMIT 30\n        ");
        $manualStmt->execute();
        $manualNotifications = $manualStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($manualNotifications as $notif) {
            $allNotifications[] = [
                'type' => $notif['notification_type'],
                'icon' => $notif['icon'] ?: 'bi-bell',
                'title' => $notif['title'],
                'description' => $notif['description'],
                'due_date' => $notif['due_date'],
                'days_left' => (int) $notif['days_left'],
                'priority' => $notif['priority'] ?: 'warning',
                'link_url' => $notif['link_url'] ? base_url($notif['link_url']) : '#'
            ];
        }

        usort($allNotifications, function ($a, $b) {
            return strtotime($a['due_date']) <=> strtotime($b['due_date']);
        });

        $totalNotifications = count($allNotifications);
    } catch (Exception $e) {
        $allNotifications = [];
        $totalNotifications = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Bootstrap -->
    <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="<?= base_url('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css'); ?>" rel="stylesheet">

    <style>
        :root {
            --nukari-green: #0f3d2e;
            --nukari-green-2: #1f7a4d;
            --nukari-green-3: #16643e;
            --nukari-bg: #f4f7f5;
            --nukari-text: #1f2933;
            --apple-green: #34c759;
            --apple-green-dark: #248a3d;
            --apple-blue: #007aff;
            --apple-orange: #ff9500;
            --apple-red: #ff3b30;
            --apple-purple: #af52de;
            --apple-teal: #5ac8fa;
            --apple-gray-1: #8e8e93;
            --apple-gray-2: #aeaeb2;
            --apple-gray-5: #e5e5ea;
            --apple-gray-6: #f2f2f7;
            --apple-green-soft: #eaf8ef;
            --apple-blue-soft: #eaf4ff;
            --apple-orange-soft: #fff5e6;
            --apple-red-soft: #fff0ef;
            --sidebar-width: 270px;
            --apple-font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Helvetica Neue", Arial, sans-serif;
            --apple-mono: "SFMono-Regular", "SF Mono", ui-monospace, Menlo, Monaco, Consolas, monospace;
            --type-caption: 12px;
            --type-footnote: 13px;
            --type-body: 15px;
            --type-title-3: 20px;
            --type-title-2: 24px;
            --type-title-1: 28px;
            --type-large-title: 34px;
            --bs-body-font-family: var(--apple-font);
            --bs-body-font-size: var(--type-body);
            --bs-body-line-height: 1.47;
            --bs-primary: #1f7a4d;
            --bs-success: #34c759;
            --bs-info: #007aff;
            --bs-warning: #ff9500;
            --bs-danger: #ff3b30;
            --bs-link-color: #007aff;
            --bs-link-hover-color: #005ecb;
        }

        html { font-size: 16px; -webkit-text-size-adjust: 100%; text-rendering: optimizeLegibility; }
        body, button, input, select, textarea, .btn, .form-control, .form-select, .table {
            font-family: var(--apple-font);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            font-kerning: normal;
        }
        body { font-size: var(--type-body); line-height: 1.47; letter-spacing: -0.01em; }
        h1, h2, h3, h4, h5, h6, .display-1, .display-2, .display-3, .display-4, .display-5, .display-6 {
            font-family: var(--apple-font); color: inherit; letter-spacing: -0.025em; line-height: 1.16;
        }
        h1, .h1 { font-size: var(--type-large-title); font-weight: 700; }
        h2, .h2 { font-size: var(--type-title-1); font-weight: 700; }
        h3, .h3 { font-size: var(--type-title-2); font-weight: 650; }
        h4, .h4 { font-size: var(--type-title-3); font-weight: 650; }
        h5, .h5 { font-size: 17px; font-weight: 650; letter-spacing: -0.015em; }
        h6, .h6 { font-size: 15px; font-weight: 650; letter-spacing: -0.01em; }
        p, li, td, .form-control, .form-select { line-height: 1.47; }
        small, .small, .form-text { font-size: var(--type-footnote); line-height: 1.38; letter-spacing: -0.003em; }
        label, .form-label { font-size: 13px; font-weight: 600; line-height: 1.3; letter-spacing: -0.005em; }
        .form-control, .form-select { font-size: 15px; font-weight: 400; }
        .btn { font-size: 14px; font-weight: 600; line-height: 1.2; letter-spacing: -0.006em; }
        .badge { font-size: 11px; font-weight: 650; line-height: 1.2; letter-spacing: 0; }
        .table { font-size: 14px; letter-spacing: -0.006em; }
        .table th { font-size: 12px; font-weight: 650; letter-spacing: 0.015em; }
        code, pre, kbd, samp, .font-monospace { font-family: var(--apple-mono); }

        /* =========================================
   GLOBAL LAYOUT
========================================= */

        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            min-height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
            background: #f5f7fb;
            font-family: var(--apple-font);
        }

        /* =========================================
   MAIN WRAPPER
========================================= */

        .app-wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* =========================================
   SIDEBAR
========================================= */

        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: #0b4b36;
            color: #fff;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1040;
            transition: all 0.3s ease;
            overflow-y: auto;
        }

        .sidebar.collapsed {
            margin-left: -260px;
        }

        /* =========================================
   MAIN CONTENT
========================================= */

        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
            padding: 24px;
            transition: all 0.3s ease;
            overflow-x: hidden;
            overflow-y: visible;
        }

        .main-content.expanded {
            margin-left: 0;
            width: 100%;
        }

        /* =========================================
   PANEL / CARD
========================================= */

        .panel {
            background: #fff;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.04);
        }

        /* =========================================
   MODAL FIX SCROLL
========================================= */

        .modal {
            overflow-y: auto !important;
        }

        .modal-dialog {
            max-width: 900px;
            margin: 1.75rem auto;
            max-height: calc(100vh - 40px);
        }

        .modal-content {
            border: none;
            border-radius: 24px;
            overflow: hidden;
        }

        .modal-body {
            overflow-y: auto;
        }

        body.modal-open {
            overflow-y: auto !important;
            padding-right: 0 !important;
        }

        /* =========================================
   TABLE
========================================= */

        .table-responsive {
            overflow-x: auto;
        }

        .table th {
            white-space: nowrap;
        }

        /* =========================================
   MOBILE
========================================= */

        @media (max-width: 991px) {

            .sidebar {
                margin-left: -260px;
            }

            .sidebar.mobile-show {
                margin-left: 0;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 18px;
            }

        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ===============================
           SIDEBAR
        ================================ */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--nukari-green);
            color: #fff;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            padding: 24px 18px;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.25s ease;
        }

        .brand-title {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .brand-subtitle {
            font-size: 13px;
            color: #b7d8c7;
            margin-bottom: 32px;
        }

        .sidebar .nav-link {
            color: #d9f2e6;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: var(--nukari-green-2);
            color: #fff;
        }

        .sidebar .nav-section-title {
            font-size: 11px;
            color: #9fcab3;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin: 18px 12px 8px;
            font-weight: 800;
        }

        /* ===============================
           MAIN CONTENT
        ================================ */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 96px 28px 28px;
            width: calc(100% - var(--sidebar-width));
            transition: all 0.25s ease;
        }

        body.sidebar-collapsed .sidebar {
            transform: translateX(-100%);
        }

        body.sidebar-collapsed .main-content {
            margin-left: 0;
            width: 100%;
        }

        /* ===============================
           TOPBAR HEADER
        ================================ */
        .nukari-topbar {
            position: fixed;
            top: 18px;
            left: calc(var(--sidebar-width) + 28px);
            right: 28px;
            height: 58px;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            border-radius: 22px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.06);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            transition: all 0.25s ease;
        }

        body.sidebar-collapsed .nukari-topbar {
            left: 28px;
        }

        .topbar-title {
            font-size: 14px;
            font-weight: 800;
            color: #374151;
        }

        .topbar-subtitle {
            font-size: 12px;
            color: #6b7280;
        }

        .topbar-icon-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: #f3f4f6;
            color: #111827;
            transition: 0.2s;
        }

        .topbar-icon-btn:hover {
            background: #e5e7eb;
        }

        /* ===============================
           NOTIFICATION DROPDOWN
        ================================ */
        .notification-dropdown {
            width: 420px;
            max-height: 560px;
            overflow-y: auto;
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.14);
            padding: 0;
        }

        .notification-header {
            padding: 16px 18px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .notification-item {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
            text-decoration: none;
            display: block;
            color: #111827;
            transition: 0.2s;
        }

        .notification-item:hover {
            background: #f9fafb;
        }

        .notification-icon {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            color: var(--nukari-green-2);
            flex-shrink: 0;
        }

        .notification-title {
            font-weight: 800;
            font-size: 14px;
        }

        .notification-desc {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.35;
        }

        .notification-badge {
            font-size: 11px;
            padding: 5px 8px;
            border-radius: 999px;
            font-weight: 800;
            white-space: nowrap;
        }

        .notification-empty {
            padding: 36px 24px;
            text-align: center;
            color: #9ca3af;
        }

        /* ===============================
           COMPONENT GLOBAL
        ================================ */
        .page-header {
            background: #fff;
            border-radius: 22px;
            padding: 24px 28px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }

        .stat-card {
            background: #fff;
            border: none;
            border-radius: 22px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.05);
            padding: 24px;
            height: 100%;
        }

        .stat-card.border-blue {
            border-left: 5px solid #2563eb;
        }

        .stat-card.border-green {
            border-left: 5px solid #16a34a;
        }

        .stat-card.border-orange {
            border-left: 5px solid #f59e0b;
        }

        .stat-card.border-red {
            border-left: 5px solid #dc2626;
        }

        .stat-title {
            color: #6b7280;
            font-weight: 600;
            font-size: 14px;
        }

        .stat-number {
            font-size: 30px;
            font-weight: 800;
            margin-top: 8px;
        }

        .panel {
            background: #fff;
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }

        .table th {
            color: #374151;
            font-weight: 700;
            background: #f3f4f6;
        }

        .btn-nukari {
            background: var(--nukari-green-2);
            color: #fff;
            border: none;
        }

        .btn-nukari:hover {
            background: var(--nukari-green-3);
            color: #fff;
        }

        .form-control,
        .form-select {
            border-radius: 12px;
            padding: 11px 14px;
        }

        .badge-soft-green {
            background: #dcfce7;
            color: #166534;
        }

        .badge-soft-red {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-soft-blue {
            background: #e0f2fe;
            color: #075985;
        }

        .badge-soft-orange {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-soft-purple {
            background: #ede9fe;
            color: #5b21b6;
        }

        /* Warna semantik Apple dengan hijau Nukari tetap sebagai identitas utama. */
        a:not(.btn):not(.nav-link) { color: var(--apple-blue); }
        a:not(.btn):not(.nav-link):hover { color: #005ecb; }
        .btn-nukari, .btn-primary {
            background-color: var(--nukari-green-2);
            border-color: var(--nukari-green-2);
            color: #fff;
        }
        .btn-nukari:hover, .btn-nukari:focus, .btn-primary:hover, .btn-primary:focus {
            background-color: var(--nukari-green-3);
            border-color: var(--nukari-green-3);
            color: #fff;
        }
        .btn-success { background-color: var(--apple-green-dark); border-color: var(--apple-green-dark); }
        .btn-danger { background-color: var(--apple-red); border-color: var(--apple-red); }
        .btn-warning { background-color: var(--apple-orange); border-color: var(--apple-orange); color: #1c1c1e; }
        .btn-info { background-color: var(--apple-blue); border-color: var(--apple-blue); color: #fff; }
        .btn-outline-success { color: var(--nukari-green-2); border-color: rgba(31,122,77,.55); }
        .btn-outline-success:hover { color: #fff; background: var(--nukari-green-2); border-color: var(--nukari-green-2); }
        .btn-outline-primary { color: var(--apple-blue); border-color: rgba(0,122,255,.42); }
        .btn-outline-primary:hover { color: #fff; background: var(--apple-blue); border-color: var(--apple-blue); }
        .btn-outline-danger { color: var(--apple-red); border-color: rgba(255,59,48,.42); }
        .btn-outline-danger:hover { color: #fff; background: var(--apple-red); border-color: var(--apple-red); }
        .text-success { color: var(--apple-green-dark) !important; }
        .text-primary { color: var(--apple-blue) !important; }
        .text-warning { color: #c66a00 !important; }
        .text-danger { color: var(--apple-red) !important; }
        .bg-success { background-color: var(--apple-green-dark) !important; }
        .bg-primary { background-color: var(--apple-blue) !important; }
        .bg-warning { background-color: var(--apple-orange) !important; }
        .bg-danger { background-color: var(--apple-red) !important; }
        .alert-success { color: #176b2c; background: var(--apple-green-soft); border-color: rgba(52,199,89,.28); }
        .alert-info { color: #0059b8; background: var(--apple-blue-soft); border-color: rgba(0,122,255,.24); }
        .alert-warning { color: #8a4b00; background: var(--apple-orange-soft); border-color: rgba(255,149,0,.28); }
        .alert-danger { color: #b4231b; background: var(--apple-red-soft); border-color: rgba(255,59,48,.24); }
        .badge-soft-green { background: var(--apple-green-soft); color: #176b2c; }
        .badge-soft-blue { background: var(--apple-blue-soft); color: #0059b8; }
        .badge-soft-orange { background: var(--apple-orange-soft); color: #8a4b00; }
        .badge-soft-red { background: var(--apple-red-soft); color: #b4231b; }
        .form-control:focus, .form-select:focus {
            border-color: var(--apple-blue);
            box-shadow: 0 0 0 .22rem rgba(0,122,255,.14);
        }
        .table-hover > tbody > tr:hover > * { background-color: rgba(0,122,255,.035); }
        ::selection { background: rgba(52,199,89,.24); color: var(--nukari-text); }

        /* ===============================
           FOOTER CHAT READY
        ================================ */
        .nukari-floating-actions {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 1100;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .floating-action-btn {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 16px 35px rgba(0, 0, 0, 0.18);
            color: #fff;
            text-decoration: none;
            font-size: 22px;
        }

        .floating-whatsapp {
            background: #25D366;
        }

        .floating-ai {
            background: var(--nukari-green-2);
        }

        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }

            body.sidebar-mobile-open .sidebar {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 92px 18px 18px;
            }

            .nukari-topbar {
                left: 18px;
                right: 18px;
                top: 14px;
            }

            .notification-dropdown {
                width: calc(100vw - 36px);
            }
        }

        /* =====================================================
           RESPONSIVE SYSTEM: desktop, laptop, tablet, smartphone
        ====================================================== */
        img, video, canvas, svg { max-width: 100%; }
        .main-content > * { min-width: 0; }
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            border-radius: 14px;
        }
        .table-responsive .table { margin-bottom: 0; }
        .table td, .table th { vertical-align: middle; }
        .btn, .form-control, .form-select { min-height: 42px; }
        .btn-sm { min-height: 34px; }
        .modal-dialog { max-width: min(94vw, 720px); }
        .report-stat { font-size: clamp(1.15rem, 2.2vw, 1.8rem); overflow-wrap: anywhere; }

        @media (max-width: 1199.98px) {
            :root { --sidebar-width: 240px; }
            .main-content { padding-left: 22px; padding-right: 22px; }
            .panel, .stat-card { padding: 20px; }
            .page-header { padding: 22px; }
        }

        @media (max-width: 767.98px) {
            body { font-size: 15px; }
            .main-content { padding: 82px 12px 16px; }
            .nukari-topbar { left: 10px; right: 10px; top: 10px; min-height: 58px; padding: 8px 10px; }
            .page-header { padding: 18px; border-radius: 16px; margin-bottom: 16px; }
            .page-header h1 { font-size: clamp(1.75rem, 8vw, 2.125rem); }
            .panel, .stat-card { padding: 16px; border-radius: 16px; margin-bottom: 16px; }
            .stat-number { font-size: 1.35rem; }
            .row { --bs-gutter-x: 0.9rem; }
            .btn:not(.topbar-icon-btn):not(.btn-close) { padding: 10px 13px; }
            .page-header .btn, .page-header .report-actions, .page-header .report-actions .btn { width: 100%; }
            .page-header > div:last-child:not(:first-child) { width: 100%; }
            .notification-dropdown { position: fixed !important; inset: 76px 10px auto 10px !important; width: auto; max-height: calc(100vh - 92px); transform: none !important; overflow-y: auto; }
            .table-responsive { margin-left: -4px; margin-right: -4px; padding-bottom: 5px; }
            .table-responsive .table { min-width: 720px; font-size: 13px; }
            .table-responsive .table.table-sm { min-width: 620px; }
            .table td, .table th { padding: 10px 9px; white-space: nowrap; }
            .modal-dialog { margin: 10px auto; max-width: calc(100vw - 20px); }
            .modal-content { max-height: calc(100vh - 20px); }
            .modal-body { overflow-y: auto; }
            .form-control, .form-select { font-size: 16px; } /* cegah auto-zoom iOS */
            .nukari-floating-actions { right: 14px; bottom: 14px; }
            .floating-action-btn { width: 48px; height: 48px; }
            .report-summary .stat-card { min-height: 112px; }
        }

        @media (max-width: 420px) {
            .main-content { padding-left: 9px; padding-right: 9px; }
            .panel, .stat-card, .page-header { padding: 14px; border-radius: 14px; }
            .report-summary > div { width: 100%; }
            .d-flex.flex-wrap.gap-2 > .btn { flex: 1 1 auto; }
        }

        /* Navigasi off-canvas tablet dan smartphone. Diletakkan paling akhir
           agar tidak kalah oleh aturan sidebar lama di atas. */
        .sidebar-backdrop,
        .sidebar-mobile-close {
            display: none;
        }

        @media (max-width: 991.98px) {
            .sidebar {
                width: min(86vw, 300px);
                margin-left: 0;
                transform: translateX(-105%);
                z-index: 1045;
                box-shadow: 18px 0 45px rgba(0, 0, 0, 0.22);
                visibility: hidden;
                transition: transform .25s ease, visibility .25s ease;
                overscroll-behavior: contain;
            }

            body.sidebar-mobile-open {
                overflow: hidden;
            }

            body.sidebar-mobile-open .sidebar {
                margin-left: 0;
                transform: translateX(0);
                visibility: visible;
            }

            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 1040;
                border: 0;
                padding: 0;
                background: rgba(15, 23, 42, .48);
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity .25s ease, visibility .25s ease;
            }

            body.sidebar-mobile-open .sidebar-backdrop {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }

            .sidebar-mobile-close {
                display: inline-flex;
                flex: 0 0 auto;
                width: 42px;
                height: 42px;
                align-items: center;
                justify-content: center;
                border: 1px solid rgba(255, 255, 255, .2);
                border-radius: 12px;
                background: rgba(255, 255, 255, .1);
                color: #fff;
            }

            .nukari-topbar {
                z-index: 1030;
            }

            body.sidebar-collapsed .main-content,
            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .sidebar,
            .sidebar-backdrop,
            .main-content,
            .nukari-topbar {
                transition: none !important;
            }
        }

        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body { background: #fff !important; color: #111 !important; font-size: 10pt; }
            .sidebar, .nukari-topbar, .nukari-floating-actions, .report-filter, .report-actions, footer { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
            .page-header, .panel, .stat-card { box-shadow: none !important; border: 1px solid #d1d5db; }
            .report-header { padding: 12px 14px; margin-bottom: 8px; }
            .report-summary { margin-bottom: 8px !important; }
            .report-summary > div { width: 25% !important; }
            .report-summary .stat-card { padding: 10px; break-inside: avoid; }
            .report-stat { font-size: 13pt; }
            .report-table-panel { padding: 0; border: 0; }
            .report-print-meta { display: block !important; margin: 8px 0; }
            .table-responsive { overflow: visible !important; }
            .report-table { width: 100%; font-size: 8pt; }
            .report-table th, .report-table td { padding: 5px; white-space: normal; }
            .report-table thead { display: table-header-group; }
            .report-table tr { break-inside: avoid; }
            a { color: inherit !important; text-decoration: none !important; }
        }
        .nukari-sortable-header {
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
            transition: color .15s ease, background-color .15s ease;
        }

        .nukari-sortable-header:hover,
        .nukari-sortable-header:focus-visible {
            color: var(--nukari-green-2);
            background-color: rgba(31, 122, 77, .06);
            outline: none;
        }

        .nukari-sort-indicator::after {
            content: '\2195';
            display: inline-block;
            margin-left: .4rem;
            font-size: .75em;
            opacity: .45;
        }

        .nukari-sorted-asc .nukari-sort-indicator::after {
            content: '\2191';
            opacity: 1;
        }

        .nukari-sorted-desc .nukari-sort-indicator::after {
            content: '\2193';
            opacity: 1;
        }
    </style>
</head>

<body>

    <button type="button" class="sidebar-backdrop" id="sidebarBackdrop" aria-label="Tutup menu navigasi" tabindex="-1"></button>

    <!-- ===============================
         TOPBAR GLOBAL
    ================================= -->
    <div class="nukari-topbar">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="topbar-icon-btn" id="sidebarToggle" title="Tampilkan/Sembunyikan Sidebar" aria-label="Buka menu navigasi" aria-controls="appSidebar" aria-expanded="false">
                <i class="bi bi-list fs-4"></i>
            </button>

            <div class="d-none d-md-block">
                <div class="topbar-title">Nukari Management</div>
                <div class="topbar-subtitle">PT Nukari Kriya Raya</div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <div class="dropdown">
                <button
                    class="topbar-icon-btn position-relative"
                    type="button"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"
                    title="Notifikasi">
                    <i class="bi bi-bell fs-5"></i>

                    <?php if ($totalNotifications > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $totalNotifications; ?>
                        </span>
                    <?php endif; ?>
                </button>

                <div class="dropdown-menu dropdown-menu-end notification-dropdown">
                    <div class="notification-header d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold">Pusat Notifikasi</div>
                            <div class="small text-muted">Termin, aset, operasional, HR, dan reminder</div>
                        </div>
                        <span class="badge bg-success rounded-pill"><?= $totalNotifications; ?></span>
                    </div>

                    <?php if (!empty($allNotifications)): ?>
                        <?php foreach ($allNotifications as $notif): ?>
                            <?php
                            $daysLeft = (int) $notif['days_left'];
                            $badgeClass = nukari_priority_badge($daysLeft, $notif['priority']);
                            $linkUrl = $notif['link_url'] ?: '#';
                            ?>

                            <a href="<?= htmlspecialchars($linkUrl); ?>" class="notification-item">
                                <div class="d-flex gap-3">
                                    <div class="notification-icon">
                                        <i class="bi <?= htmlspecialchars($notif['icon']); ?>"></i>
                                    </div>

                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between gap-2 align-items-start">
                                            <div>
                                                <div class="notification-title">
                                                    <?= htmlspecialchars($notif['title']); ?>
                                                </div>
                                                <div class="notification-desc">
                                                    <?= htmlspecialchars($notif['type']); ?>
                                                </div>
                                            </div>

                                            <span class="badge <?= $badgeClass; ?> notification-badge">
                                                <?= nukari_days_text($daysLeft); ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($notif['description'])): ?>
                                            <div class="notification-desc mt-1">
                                                <?= htmlspecialchars($notif['description']); ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="notification-desc mt-1">
                                            Jatuh tempo: <?= date('d M Y', strtotime($notif['due_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-empty">
                            <i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>
                            Tidak ada notifikasi aktif.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="app-wrapper">
