<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$count = asset_sync_due_reminders($pdo);
$_SESSION['success'] = $count . ' reminder aset disinkronkan ke notifikasi.';
redirect('pages/assets/index.php');
