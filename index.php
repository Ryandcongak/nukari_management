<?php
require_once __DIR__ . '/includes/functions.php';
session_start();

if (isset($_SESSION['user_id'])) {
    redirect(default_page_for_role((string)($_SESSION['role'] ?? '')));
}

redirect('login.php');
