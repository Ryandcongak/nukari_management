<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';
require_once __DIR__ . '/../../includes/operational_fund_helper.php';

if (!function_exists('f_e')) {
    function f_e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('f_money_to_number')) {
    function f_money_to_number($value)
    {
        $value = trim((string)$value);
        if ($value === '') return 0;
        $value = str_replace(['Rp', 'rp', ' ', '.'], '', $value);
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}

if (!function_exists('f_upload_file')) {
    function f_upload_file($field, $oldFile = null)
    {
        $uploaded = upload_store_file(
            $_FILES[$field] ?? [],
            'finance',
            'finance',
            upload_document_mimes()
        );

        return $uploaded ?? $oldFile;
    }
}

if (!function_exists('f_transaction_code')) {
    function f_transaction_code(PDO $pdo, string $type): string
    {
        $prefix = $type === 'income' ? 'INC' : 'EXP';
        $date = date('Ymd');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE transaction_code LIKE :code");
        $stmt->execute([':code' => $prefix . '-' . $date . '-%']);
        $number = ((int)$stmt->fetchColumn()) + 1;

        return $prefix . '-' . $date . '-' . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('f_account_code')) {
    function f_account_code(PDO $pdo, string $type): string
    {
        $prefixMap = [
            'cash' => 'CASH',
            'bank' => 'BANK',
            'qris' => 'QRIS',
            'ewallet' => 'EWL',
            'other' => 'ACC',
        ];
        $prefix = $prefixMap[$type] ?? 'ACC';

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM finance_accounts WHERE account_code LIKE :code");
        $stmt->execute([':code' => $prefix . '-%']);
        $number = ((int)$stmt->fetchColumn()) + 1;

        return $prefix . '-' . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('f_recalculate_account')) {
    function f_recalculate_account(PDO $pdo, int $accountId): void
    {
        $stmt = $pdo->prepare("SELECT opening_balance FROM finance_accounts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $accountId]);
        $opening = $stmt->fetchColumn();

        if ($opening === false) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income_total,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense_total
            FROM transactions
            WHERE account_id = :account_id
              AND posting_mode = 'posted'
        ");
        $stmt->execute([':account_id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $balance = (float)$opening + (float)$row['income_total'] - (float)$row['expense_total'];

        if (function_exists('opfund_account_effect_totals')) {
            $opEffect = opfund_account_effect_totals($pdo, $accountId);
            $balance = $balance - (float)$opEffect['out_total'] + (float)$opEffect['in_total'];
        }

        $stmt = $pdo->prepare("UPDATE finance_accounts SET current_balance = :balance, updated_at = NOW() WHERE id = :id");
        $stmt->execute([
            ':balance' => $balance,
            ':id' => $accountId
        ]);
    }
}

if (!function_exists('f_recalculate_all_accounts')) {
    function f_recalculate_all_accounts(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT id FROM finance_accounts");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            f_recalculate_account($pdo, (int)$id);
        }
    }
}

if (!function_exists('f_type_badge')) {
    function f_type_badge($type)
    {
        if ($type === 'income') {
            return '<span class="badge rounded-pill bg-success-subtle text-success">Income</span>';
        }
        return '<span class="badge rounded-pill bg-danger-subtle text-danger">Expense</span>';
    }
}

if (!function_exists('f_account_type_badge')) {
    function f_account_type_badge($type)
    {
        $map = [
            'cash' => 'bg-success-subtle text-success',
            'bank' => 'bg-primary-subtle text-primary',
            'qris' => 'bg-info-subtle text-info',
            'ewallet' => 'bg-warning-subtle text-warning',
            'other' => 'bg-secondary-subtle text-secondary',
        ];
        $label = [
            'cash' => 'Kas',
            'bank' => 'Bank',
            'qris' => 'QRIS',
            'ewallet' => 'E-Wallet',
            'other' => 'Lainnya',
        ][$type] ?? ucfirst((string)$type);

        return '<span class="badge rounded-pill ' . ($map[$type] ?? $map['other']) . '">' . f_e($label) . '</span>';
    }
}

if (!function_exists('f_payment_label')) {
    function f_payment_label($method)
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

if (!function_exists('f_source_label')) {
    function f_source_label($source)
    {
        $map = [
            'manual' => 'Manual',
            'debt_payment' => 'Hutang/Piutang',
            'reimbursement' => 'Dana Talangan',
            'project' => 'Project',
            'payroll' => 'Payroll',
            'pos' => 'Kasir/POS',
            'product_sale' => 'Penjualan Produk',
            'asset' => 'Aset',
            'other' => 'Lainnya',
        ];
        return $map[$source] ?? ucfirst((string)$source);
    }
}

if (!function_exists('f_month_range')) {
    function f_month_range($month)
    {
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        return [
            $month,
            $month . '-01',
            date('Y-m-t', strtotime($month . '-01'))
        ];
    }
}
?>
