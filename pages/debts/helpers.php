<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';

if (!function_exists('debt_money_to_number')) {
    function debt_money_to_number($value)
    {
        $value = trim((string)$value);
        if ($value === '') return 0;
        $value = str_replace(['Rp', 'rp', ' ', '.'], '', $value);
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}

if (!function_exists('debt_upload_file')) {
    function debt_upload_file($field, $oldFile = null)
    {
        $uploaded = upload_store_file(
            $_FILES[$field] ?? [],
            'debts',
            'debt',
            upload_document_mimes()
        );

        return $uploaded ?? $oldFile;
    }
}

if (!function_exists('debt_source_label')) {
    function debt_source_label($source)
    {
        $labels = [
            'manual' => 'Manual',
            'project_term' => 'Termin Project',
            'custom_order' => 'Custom Order',
            'reimbursement' => 'Dana Talangan',
            'asset_rental' => 'Sewa Aset',
            'payroll' => 'Payroll',
            'product_sale' => 'Penjualan Produk',
            'other' => 'Talangan/Integrasi Lain'
        ];

        return $labels[$source] ?? ucfirst((string)$source);
    }
}

if (!function_exists('debt_source_badge')) {
    function debt_source_badge($source)
    {
        $source = $source ?: 'manual';

        $classes = [
            'manual' => 'bg-secondary-subtle text-secondary',
            'project_term' => 'bg-primary-subtle text-primary',
            'custom_order' => 'bg-info-subtle text-info',
            'reimbursement' => 'bg-warning-subtle text-warning',
            'asset_rental' => 'bg-success-subtle text-success',
            'payroll' => 'bg-danger-subtle text-danger',
            'product_sale' => 'bg-success-subtle text-success',
            'other' => 'bg-warning-subtle text-warning'
        ];

        $class = $classes[$source] ?? 'bg-secondary-subtle text-secondary';

        return '<span class="badge rounded-pill ' . $class . '">' . htmlspecialchars(debt_source_label($source)) . '</span>';
    }
}

if (!function_exists('debt_type_badge_html')) {
    function debt_type_badge_html($type)
    {
        return $type === 'receivable'
            ? '<span class="badge rounded-pill bg-success-subtle text-success">Piutang</span>'
            : '<span class="badge rounded-pill bg-danger-subtle text-danger">Hutang</span>';
    }
}

if (!function_exists('debt_status_badge_html')) {
    function debt_status_badge_html($status, $dueDate = null)
    {
        $today = date('Y-m-d');

        if ($status !== 'paid' && $dueDate && $dueDate < $today) {
            return '<span class="badge rounded-pill bg-danger">Overdue</span>';
        }

        if ($status === 'paid') {
            return '<span class="badge rounded-pill bg-success">Lunas</span>';
        }

        if ($status === 'partial') {
            return '<span class="badge rounded-pill bg-warning text-dark">Sebagian</span>';
        }

        return '<span class="badge rounded-pill bg-secondary">Belum Dibayar</span>';
    }
}

if (!function_exists('debt_payment_method_label')) {
    function debt_payment_method_label($method)
    {
        $labels = [
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'qris' => 'QRIS',
            'other' => 'Talangan/Integrasi Lain'
        ];

        return $labels[$method] ?? '-';
    }
}

if (!function_exists('debt_get_source_link')) {
    function debt_get_source_link(array $row)
    {
        $source = $row['source_module'] ?? 'manual';

        if ($source === 'project_term' && !empty($row['project_id'])) {
            return [
                'label' => 'Buka Detail Project',
                'url' => base_url('pages/projects/detail.php?id=' . (int)$row['project_id'])
            ];
        }

        if ($source === 'custom_order' && !empty($row['source_id'])) {
            return [
                'label' => 'Buka Custom Order',
                'url' => base_url('pages/products/custom_orders.php')
            ];
        }

        if ($source === 'reimbursement' && !empty($row['source_id'])) {
            return [
                'label' => 'Buka Dana Talangan',
                'url' => base_url('pages/reimbursements/detail.php?id=' . (int)$row['source_id'])
            ];
        }

        if (($source === 'other' || $source === 'project') && !empty($row['project_id'])) {
            return [
                'label' => 'Buka Detail Project',
                'url' => base_url('pages/projects/detail.php?id=' . (int)$row['project_id'])
            ];
        }

        return null;
    }
}

if (!function_exists('debt_sync_status_badge')) {
    function debt_sync_status_badge(array $row)
    {
        $source = $row['source_module'] ?? 'manual';

        if ($source === 'manual') {
            return '<span class="badge rounded-pill bg-secondary-subtle text-secondary">Manual</span>';
        }

        if (empty($row['source_id'])) {
            return '<span class="badge rounded-pill bg-danger-subtle text-danger">Belum Sinkron</span>';
        }

        if ((int)($row['payment_count'] ?? 0) > 0 && (int)($row['transaction_count'] ?? 0) > 0) {
            return '<span class="badge rounded-pill bg-success">Sinkron + Transaksi</span>';
        }

        if ((int)($row['payment_count'] ?? 0) > 0) {
            return '<span class="badge rounded-pill bg-warning text-dark">Ada Pembayaran</span>';
        }

        return '<span class="badge rounded-pill bg-primary-subtle text-primary">Sinkron</span>';
    }
}


if (!function_exists('debt_is_project_term_receivable')) {
    function debt_is_project_term_receivable(array $row)
    {
        return (($row['type'] ?? '') === 'receivable') && (($row['source_module'] ?? '') === 'project_term');
    }
}

if (!function_exists('debt_can_be_paid')) {
    function debt_can_be_paid(array $row)
    {
        if (($row['status'] ?? '') === 'paid') {
            return false;
        }

        /*
         * Piutang yang berasal dari termin project tidak dibayar dari modul Hutang & Piutang.
         * Single point of execution: pembayaran termin wajib dilakukan dari Detail Project.
         * Halaman Hutang & Piutang hanya menjadi monitoring piutang.
         */
        if (debt_is_project_term_receivable($row)) {
            return false;
        }

        return true;
    }
}

?>
