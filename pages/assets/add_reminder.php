<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/asset_helpers.php';
$id = (int)($_POST['asset_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($id ? 'pages/assets/detail.php?id='.$id : 'pages/assets/index.php');
try {
    $type = clean_input($_POST['reminder_type'] ?? 'other');
    $title = clean_input($_POST['title'] ?? 'Reminder Aset');
    $desc = clean_input($_POST['description'] ?? '');
    $due = $_POST['due_date'] ?? null;
    $before = (int)($_POST['remind_before_days'] ?? 7);
    $priority = clean_input($_POST['priority'] ?? 'warning');
    $link = $id ? 'pages/assets/detail.php?id=' . $id . '#reminders' : 'pages/assets/index.php';
    asset_create_asset_reminder($pdo, $id ?: null, $type, $title, $desc, $due, $before, $priority, $link);
    asset_sync_due_reminders($pdo);
    $_SESSION['success'] = 'Reminder aset berhasil ditambahkan.';
} catch (Exception $e) { $_SESSION['error'] = $e->getMessage(); }
redirect($id ? 'pages/assets/detail.php?id='.$id.'#reminders' : 'pages/assets/index.php');
