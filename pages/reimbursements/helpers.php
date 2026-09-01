<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';

if (!function_exists('rmb_e')) {
    function rmb_e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('rmb_money_to_number')) {
    function rmb_money_to_number($value)
    {
        $value = trim((string)$value);
        if ($value === '') return 0;
        $value = str_replace(['Rp', 'rp', ' ', '.'], '', $value);
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}

if (!function_exists('rmb_upload_file')) {
    function rmb_upload_file($field, $oldFile = null)
    {
        if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return $oldFile;
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            throw new Exception('File bukti hanya boleh JPG, PNG, WEBP, atau PDF.');
        }
        validate_upload($_FILES[$field], ['image/jpeg','image/png','image/webp','application/pdf']);

        $dir = __DIR__ . '/../../uploads/reimbursements/';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = 'rmb_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $dir . $filename;

        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
            throw new Exception('Gagal upload file bukti.');
        }

        return 'uploads/reimbursements/' . $filename;
    }
}

if (!function_exists('rmb_status_badge')) {
    function rmb_status_badge($status)
    {
        $map = [
            'pending' => '<span class="badge rounded-pill bg-warning text-dark">Pending</span>',
            'approved' => '<span class="badge rounded-pill bg-primary">Approved</span>',
            'partial' => '<span class="badge rounded-pill bg-info text-dark">Dibayar Sebagian</span>',
            'paid' => '<span class="badge rounded-pill bg-success">Lunas</span>',
            'rejected' => '<span class="badge rounded-pill bg-danger">Ditolak</span>',
            'cancelled' => '<span class="badge rounded-pill bg-secondary">Dibatalkan</span>',
        ];
        return $map[$status] ?? '<span class="badge rounded-pill bg-secondary">-</span>';
    }
}

if (!function_exists('rmb_payment_method_label')) {
    function rmb_payment_method_label($method)
    {
        $map = [
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'qris' => 'QRIS',
            'other' => 'Lainnya',
        ];
        return $map[$method] ?? '-';
    }
}

if (!function_exists('rmb_redirect')) {
    function rmb_redirect($path)
    {
        redirect($path);
    }
}
?>
