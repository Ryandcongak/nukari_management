<?php
/**
 * Nukari Finance Helper - Stable Integration
 * Fokus:
 * - Project Termin -> Piutang otomatis
 * - Dana Talangan/Reimbursement -> Hutang otomatis
 * - Debt Payment -> Transactions
 */

require_once __DIR__ . '/operational_fund_helper.php';

if (!function_exists('finance_generate_debt_code')) {
    function finance_generate_debt_code(PDO $pdo, string $type): string
    {
        $prefix = $type === 'receivable' ? 'PTG' : 'HTG';
        $ym = date('Ym');

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(debt_code,'-',-1) AS UNSIGNED)),0) FROM debts WHERE debt_code LIKE :code");
        $stmt->execute([':code' => $prefix . '-' . $ym . '-%']);
        $num = ((int)$stmt->fetchColumn()) + 1;

        return $prefix . '-' . $ym . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('finance_generate_transaction_code')) {
    function finance_generate_transaction_code(PDO $pdo, string $type): string
    {
        $prefix = $type === 'income' ? 'INC' : 'EXP';
        $ymd = date('Ymd');

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(transaction_code,'-',-1) AS UNSIGNED)),0) FROM transactions WHERE transaction_code LIKE :code");
        $stmt->execute([':code' => $prefix . '-' . $ymd . '-%']);
        $num = ((int)$stmt->fetchColumn()) + 1;

        return $prefix . '-' . $ymd . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('finance_find_existing_debt')) {
    function finance_find_existing_debt(PDO $pdo, string $sourceModule, int $sourceId, string $type): ?array
    {
        $stmt = $pdo->prepare("
            SELECT *
            FROM debts
            WHERE source_module = :source_module
              AND source_id = :source_id
              AND type = :type
            LIMIT 1
        ");
        $stmt->execute([
            ':source_module' => $sourceModule,
            ':source_id' => $sourceId,
            ':type' => $type
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('finance_update_debt_status')) {
    function finance_update_debt_status(PDO $pdo, int $debtId): void
    {
        $stmt = $pdo->prepare("SELECT amount, paid_amount FROM debts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $debtId]);
        $debt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$debt) {
            return;
        }

        $paymentSum = null;
        try {
            $pay = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM debt_payments WHERE debt_id = :id");
            $pay->execute([':id' => $debtId]);
            $paymentSum = (float)$pay->fetchColumn();
        } catch (Exception $e) {
            $paymentSum = null;
        }

        $paid = $paymentSum !== null && $paymentSum > 0 ? $paymentSum : (float)$debt['paid_amount'];
        $amount = (float)$debt['amount'];

        $status = 'unpaid';
        $paidDate = null;

        if ($paid >= $amount && $amount > 0) {
            $paid = $amount;
            $status = 'paid';
            $paidDate = date('Y-m-d');
        } elseif ($paid > 0) {
            $status = 'partial';
        }

        $stmt = $pdo->prepare("
            UPDATE debts
            SET paid_amount = :paid_amount,
                status = :status,
                paid_date = :paid_date,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':paid_amount' => $paid,
            ':status' => $status,
            ':paid_date' => $paidDate,
            ':id' => $debtId
        ]);
    }
}

if (!function_exists('finance_get_project_term_data')) {
    function finance_get_project_term_data(PDO $pdo, int $termId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                t.*,
                p.project_name,
                p.client_id,
                c.client_name
            FROM project_payment_terms t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN clients c ON c.id = p.client_id
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $termId]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);

        return $term ?: null;
    }
}

if (!function_exists('finance_sync_project_term_receivable')) {
    function finance_sync_project_term_receivable(PDO $pdo, int $termId, ?int $userId = null): ?int
    {
        $term = finance_get_project_term_data($pdo, $termId);

        if (!$term || (float)$term['amount'] <= 0) {
            return null;
        }

        $existing = finance_find_existing_debt($pdo, 'project_term', $termId, 'receivable');

        if ($existing && (float)$existing['paid_amount'] > (float)$term['amount']) {
            throw new Exception('Nominal termin tidak boleh lebih kecil dari pembayaran piutang yang sudah tercatat.');
        }

        $relatedName = $term['client_name'] ?: 'Client Project';
        $description = 'Piutang otomatis dari termin project: ' . $term['project_name'] . ' - ' . $term['term_name'];

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE debts
                SET related_type = 'client',
                    related_id = :related_id,
                    category = 'Termin Project',
                    issue_date = COALESCE(issue_date, CURDATE()),
                    related_name = :related_name,
                    project_id = :project_id,
                    amount = :amount,
                    due_date = :due_date,
                    source_table = 'project_payment_terms',
                    reference_code = :reference_code,
                    description = :description,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':related_id' => $term['client_id'] ?: null,
                ':related_name' => $relatedName,
                ':project_id' => $term['project_id'],
                ':amount' => (float)$term['amount'],
                ':due_date' => $term['due_date'],
                ':reference_code' => $term['term_name'],
                ':description' => $description,
                ':id' => (int)$existing['id']
            ]);

            finance_update_debt_status($pdo, (int)$existing['id']);
            return (int)$existing['id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO debts
            (
                debt_code, type, related_type, related_id, category, issue_date, related_name,
                project_id, amount, paid_amount, due_date, payment_method, proof_file,
                reminder_days_before, notification_id, source_module, source_table, source_id,
                reference_code, status, paid_date, description, created_by, created_at, updated_at
            )
            VALUES
            (
                :debt_code, 'receivable', 'client', :related_id, 'Termin Project', CURDATE(), :related_name,
                :project_id, :amount, 0, :due_date, NULL, NULL,
                7, NULL, 'project_term', 'project_payment_terms', :source_id,
                :reference_code, 'unpaid', NULL, :description, :created_by, NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':debt_code' => finance_generate_debt_code($pdo, 'receivable'),
            ':related_id' => $term['client_id'] ?: null,
            ':related_name' => $relatedName,
            ':project_id' => $term['project_id'],
            ':amount' => (float)$term['amount'],
            ':due_date' => $term['due_date'],
            ':source_id' => $termId,
            ':reference_code' => $term['term_name'],
            ':description' => $description,
            ':created_by' => $userId ?? ($_SESSION['user_id'] ?? null)
        ]);

        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('finance_mark_project_term_paid')) {
    /**
     * Tandai termin lunas dan sinkronkan Piutang + Finance Income ke akun kas/bank tujuan.
     * Backward compatible: pemanggilan lama finance_mark_project_term_paid($pdo, $termId, $paidDate) tetap aman.
     */
    function finance_mark_project_term_paid(PDO $pdo, int $termId, ?string $paidDate = null, ?int $accountId = null, ?string $paymentMethod = null, ?string $proofFile = null, ?int $userId = null): void
    {
        $paidDate = $paidDate ?: date('Y-m-d');
        $paymentMethod = finance_normalize_payment_method($paymentMethod ?: 'bank_transfer');
        $debtId = finance_sync_project_term_receivable($pdo, $termId, $userId ?? ($_SESSION['user_id'] ?? null));
        if (!$debtId) return;

        $stmt = $pdo->prepare("
            UPDATE debts
            SET paid_amount = amount,
                status = 'paid',
                paid_date = :paid_date,
                payment_method = :payment_method,
                proof_file = COALESCE(:proof_file, proof_file),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':paid_date' => $paidDate,
            ':payment_method' => $paymentMethod,
            ':proof_file' => $proofFile,
            ':id' => $debtId
        ]);

        finance_sync_project_term_transaction($pdo, $termId, null, $userId, $accountId, $paymentMethod, $proofFile);
    }
}

if (!function_exists('finance_unmark_project_term_paid')) {
    function finance_unmark_project_term_paid(PDO $pdo, int $termId): void
    {
        $existing = finance_find_existing_debt($pdo, 'project_term', $termId, 'receivable');

        if (!$existing) {
            finance_sync_project_term_receivable($pdo, $termId, $_SESSION['user_id'] ?? null);
            return;
        }

        $stmt = $pdo->prepare("
            UPDATE debts
            SET paid_amount = 0,
                status = 'unpaid',
                paid_date = NULL,
                updated_at = NOW()
            WHERE id = :id
              AND NOT EXISTS (
                    SELECT 1
                    FROM debt_payments dp
                    WHERE dp.debt_id = debts.id
                    LIMIT 1
              )
        ");
        $stmt->execute([':id' => (int)$existing['id']]);

        finance_update_debt_status($pdo, (int)$existing['id']);
    }
}

if (!function_exists('finance_delete_project_term_receivable_if_unpaid')) {
    function finance_delete_project_term_receivable_if_unpaid(PDO $pdo, int $termId): void
    {
        $existing = finance_find_existing_debt($pdo, 'project_term', $termId, 'receivable');

        if (!$existing) return;

        $hasPayments = false;
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM debt_payments WHERE debt_id = :id");
            $stmt->execute([':id' => (int)$existing['id']]);
            $hasPayments = ((int)$stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            $hasPayments = false;
        }

        if ((float)$existing['paid_amount'] > 0 || $hasPayments) {
            throw new Exception('Termin tidak bisa dihapus karena piutang sudah memiliki pembayaran/lunas.');
        }

        $stmt = $pdo->prepare("DELETE FROM debts WHERE id = :id");
        $stmt->execute([':id' => (int)$existing['id']]);
    }
}

if (!function_exists('finance_delete_project_term_receivable_if_safe')) {
    function finance_delete_project_term_receivable_if_safe(PDO $pdo, int $termId): void
    {
        finance_delete_project_term_receivable_if_unpaid($pdo, $termId);
    }
}

if (!function_exists('finance_generate_reimbursement_code')) {
    function finance_generate_reimbursement_code(PDO $pdo): string
    {
        $ym = date('Ym');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reimbursements WHERE reimbursement_code LIKE :code");
        $stmt->execute([':code' => 'RMB-' . $ym . '-%']);
        $num = ((int)$stmt->fetchColumn()) + 1;

        return 'RMB-' . $ym . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('finance_create_debt_from_reimbursement')) {
    function finance_create_debt_from_reimbursement(PDO $pdo, int $reimbursementId, ?int $userId = null): ?int
    {
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                e.full_name,
                p.project_name
            FROM reimbursements r
            LEFT JOIN employees e ON e.id = r.employee_id
            LEFT JOIN projects p ON p.id = r.project_id
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reimbursementId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r || (float)$r['amount'] <= 0) {
            return null;
        }

        if (!empty($r['debt_id'])) {
            $existingDebt = $pdo->prepare("SELECT id FROM debts WHERE id = :id LIMIT 1");
            $existingDebt->execute([':id' => (int)$r['debt_id']]);
            if ($existingDebt->fetchColumn()) {
                return (int)$r['debt_id'];
            }
        }

        $existing = finance_find_existing_debt($pdo, 'reimbursement', $reimbursementId, 'debt');

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE reimbursements SET debt_id = :debt_id, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':debt_id' => (int)$existing['id'], ':id' => $reimbursementId]);
            return (int)$existing['id'];
        }

        $relatedType = !empty($r['employee_id']) ? 'employee' : 'personal';
        $relatedId = !empty($r['employee_id']) ? (int)$r['employee_id'] : null;
        $relatedName = $r['full_name'] ?: $r['requester_name'];
        $description = 'Hutang otomatis dari dana talangan: ' . ($r['reimbursement_code'] ?: ('#' . $r['id']));
        if (!empty($r['project_name'])) {
            $description .= ' - Project: ' . $r['project_name'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO debts
            (
                debt_code, type, related_type, related_id, category, issue_date, related_name,
                project_id, amount, paid_amount, due_date, payment_method, proof_file,
                reminder_days_before, notification_id, source_module, source_table, source_id,
                reference_code, status, paid_date, description, created_by, created_at, updated_at
            )
            VALUES
            (
                :debt_code, 'debt', :related_type, :related_id, :category, :issue_date, :related_name,
                :project_id, :amount, 0, :due_date, NULL, :proof_file,
                7, NULL, 'reimbursement', 'reimbursements', :source_id,
                :reference_code, 'unpaid', NULL, :description, :created_by, NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':debt_code' => finance_generate_debt_code($pdo, 'debt'),
            ':related_type' => $relatedType,
            ':related_id' => $relatedId,
            ':category' => $r['category'] ?: 'Dana Talangan',
            ':issue_date' => $r['request_date'] ?: date('Y-m-d'),
            ':related_name' => $relatedName,
            ':project_id' => $r['project_id'] ?: null,
            ':amount' => (float)$r['amount'],
            ':due_date' => $r['due_date'] ?: null,
            ':proof_file' => $r['proof_file'] ?: null,
            ':source_id' => $reimbursementId,
            ':reference_code' => $r['reimbursement_code'],
            ':description' => $description,
            ':created_by' => $userId ?? ($_SESSION['user_id'] ?? null)
        ]);

        $debtId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("UPDATE reimbursements SET debt_id = :debt_id, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':debt_id' => $debtId, ':id' => $reimbursementId]);

        return $debtId;
    }
}

if (!function_exists('finance_sync_reimbursement_from_debt')) {
    function finance_sync_reimbursement_from_debt(PDO $pdo, int $reimbursementId): void
    {
        $stmt = $pdo->prepare("SELECT debt_id, amount FROM reimbursements WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $reimbursementId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$r) return;

        $paid = 0;
        $status = 'approved';

        if (!empty($r['debt_id'])) {
            finance_update_debt_status($pdo, (int)$r['debt_id']);

            $stmt = $pdo->prepare("SELECT paid_amount, status FROM debts WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => (int)$r['debt_id']]);
            $d = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($d) {
                $paid = (float)$d['paid_amount'];
                if ($d['status'] === 'paid') {
                    $status = 'paid';
                } elseif ($paid > 0) {
                    $status = 'partial';
                }
            }
        }

        $stmt = $pdo->prepare("
            UPDATE reimbursements
            SET paid_amount = :paid_amount,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
              AND status NOT IN ('pending','rejected','cancelled')
        ");
        $stmt->execute([
            ':paid_amount' => min($paid, (float)$r['amount']),
            ':status' => $status,
            ':id' => $reimbursementId
        ]);
    }
}

if (!function_exists('finance_create_transaction_from_debt_payment')) {
    function finance_create_transaction_from_debt_payment(PDO $pdo, int $paymentId, ?int $accountId = null): ?int
    {
        $stmt = $pdo->prepare("
            SELECT
                dp.*,
                d.type AS debt_type,
                d.debt_code,
                d.related_name,
                d.project_id
            FROM debt_payments dp
            INNER JOIN debts d ON d.id = dp.debt_id
            WHERE dp.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return null;
        }

        if (!empty($payment['transaction_id'])) {
            $existingTransactionId = (int)$payment['transaction_id'];
            if ($accountId && empty($payment['account_id'])) {
                $stmt = $pdo->prepare("UPDATE debt_payments SET account_id = :account_id WHERE id = :id");
                $stmt->execute([':account_id' => $accountId, ':id' => $paymentId]);
            }
            return $existingTransactionId;
        }

        $transactionType = $payment['debt_type'] === 'receivable' ? 'income' : 'expense';
        $method = finance_normalize_payment_method($payment['payment_method'] ?? 'cash');
        $accountId = $accountId ?: (!empty($payment['account_id']) ? (int)$payment['account_id'] : finance_get_default_account_id($pdo));
        $categoryName = $transactionType === 'income' ? 'Pembayaran Piutang' : 'Pembayaran Hutang';
        $categoryId = finance_get_or_create_category_id($pdo, $categoryName, $transactionType);

        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (
                transaction_code, transaction_date, type, category_id, account_id, project_id,
                amount, description, payment_method, proof_file, source_module, source_table,
                source_id, reference_code, created_by, created_at, updated_at
            )
            VALUES
            (
                :code, :date, :type, :category_id, :account_id, :project_id,
                :amount, :description, :method, :proof_file, 'debt_payment', 'debt_payments',
                :source_id, :reference_code, :created_by, NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':code' => finance_generate_transaction_code($pdo, $transactionType),
            ':date' => $payment['payment_date'],
            ':type' => $transactionType,
            ':category_id' => $categoryId,
            ':account_id' => $accountId,
            ':project_id' => $payment['project_id'] ?: null,
            ':amount' => (float)$payment['amount'],
            ':description' => ($transactionType === 'income' ? 'Pembayaran piutang: ' : 'Pembayaran hutang: ') . $payment['debt_code'] . ' - ' . $payment['related_name'],
            ':method' => $method,
            ':proof_file' => $payment['proof_file'] ?: null,
            ':source_id' => $paymentId,
            ':reference_code' => $payment['debt_code'],
            ':created_by' => $payment['created_by'] ?: null
        ]);

        $transactionId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("UPDATE debt_payments SET transaction_id = :transaction_id, account_id = :account_id WHERE id = :id");
        $stmt->execute([
            ':transaction_id' => $transactionId,
            ':account_id' => $accountId,
            ':id' => $paymentId
        ]);

        finance_recalculate_account_balance($pdo, $accountId);

        return $transactionId;
    }
}


/**
 * Ambil akun finance default untuk transaksi otomatis.
 */
if (!function_exists('finance_get_default_account_id')) {
    function finance_get_default_account_id(PDO $pdo): ?int
    {
        $stmt = $pdo->query("
            SELECT id
            FROM finance_accounts
            WHERE status = 'active'
            ORDER BY is_default DESC, id ASC
            LIMIT 1
        ");
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
}

/**
 * Ambil atau buat kategori transaksi.
 */
if (!function_exists('finance_get_or_create_category_id')) {
    function finance_get_or_create_category_id(PDO $pdo, string $categoryName, string $type = 'both'): ?int
    {
        $stmt = $pdo->prepare("SELECT id FROM transaction_categories WHERE category_name = :name LIMIT 1");
        $stmt->execute([':name' => $categoryName]);
        $id = $stmt->fetchColumn();

        if ($id) {
            return (int)$id;
        }

        $stmt = $pdo->prepare("
            INSERT INTO transaction_categories (category_name, type, created_at)
            VALUES (:name, :type, NOW())
        ");
        $stmt->execute([
            ':name' => $categoryName,
            ':type' => in_array($type, ['income','expense','both'], true) ? $type : 'both'
        ]);

        return (int)$pdo->lastInsertId();
    }
}

/**
 * Hitung ulang saldo akun berdasarkan opening balance + transaksi.
 */
if (!function_exists('finance_recalculate_account_balance')) {
    function finance_recalculate_account_balance(PDO $pdo, ?int $accountId): void
    {
        if (!$accountId) {
            return;
        }

        $stmt = $pdo->prepare("SELECT opening_balance FROM finance_accounts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $accountId]);
        $opening = $stmt->fetchColumn();

        if ($opening === false) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END), 0) AS income_total,
                COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense_total
            FROM transactions
            WHERE account_id = :account_id
        ");
        $stmt->execute([':account_id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $balance = (float)$opening + (float)$row['income_total'] - (float)$row['expense_total'];

        // Dana Operasional: pemberian dana ke personal mengurangi akun, pengembalian menambah akun.
        if (function_exists('opfund_account_effect_totals')) {
            $opEffect = opfund_account_effect_totals($pdo, $accountId);
            $balance = $balance - (float)$opEffect['out_total'] + (float)$opEffect['in_total'];
        }

        $stmt = $pdo->prepare("
            UPDATE finance_accounts
            SET current_balance = :balance,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':balance' => $balance,
            ':id' => $accountId
        ]);
    }
}

/**
 * Sinkronisasi pembayaran termin project ke transaksi utama Finance Core.
 * Fungsi ini tidak mengubah alur project lama; hanya membuat/update data di transactions.
 */
if (!function_exists('finance_sync_project_term_transaction')) {
    function finance_sync_project_term_transaction(PDO $pdo, int $termId, ?int $projectTransactionId = null, ?int $userId = null, ?int $accountId = null, ?string $paymentMethod = null, ?string $proofFile = null): ?int
    {
        $stmt = $pdo->prepare("
            SELECT
                t.*,
                p.project_name,
                c.client_name
            FROM project_payment_terms t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN clients c ON c.id = p.client_id
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $termId]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$term || (float)$term['amount'] <= 0) {
            return null;
        }

        $receivedAmount = isset($term['received_amount']) && (float)$term['received_amount'] > 0
            ? (float)$term['received_amount']
            : (float)$term['amount'];

        $accountId = $accountId ?: finance_get_default_account_id($pdo);
        $paymentMethod = finance_normalize_payment_method($paymentMethod ?: 'bank_transfer');
        $categoryId = finance_get_or_create_category_id($pdo, 'Pendapatan Project', 'income');

        $description = 'Pembayaran termin project: ' . $term['project_name'] . ' - ' . $term['term_name'];
        $referenceCode = $term['term_name'];

        $stmt = $pdo->prepare("
            SELECT id, account_id
            FROM transactions
            WHERE source_module = 'project'
              AND source_table = 'project_payment_terms'
              AND source_id = :source_id
            LIMIT 1
        ");
        $stmt->execute([':source_id' => $termId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $oldAccountId = !empty($existing['account_id']) ? (int)$existing['account_id'] : null;

            $stmt = $pdo->prepare("
                UPDATE transactions
                SET transaction_date = :transaction_date,
                    type = 'income',
                    category_id = :category_id,
                    account_id = :account_id,
                    project_id = :project_id,
                    amount = :amount,
                    description = :description,
                    payment_method = :payment_method,
                    proof_file = COALESCE(:proof_file, proof_file),
                    source_module = 'project',
                    source_table = 'project_payment_terms',
                    source_id = :source_id,
                    reference_code = :reference_code,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':transaction_date' => $term['paid_date'] ?: date('Y-m-d'),
                ':category_id' => $categoryId,
                ':account_id' => $accountId,
                ':project_id' => $term['project_id'],
                ':amount' => $receivedAmount,
                ':description' => $description,
                ':payment_method' => $paymentMethod,
                ':proof_file' => $proofFile,
                ':source_id' => $termId,
                ':reference_code' => $referenceCode,
                ':id' => (int)$existing['id']
            ]);

            finance_recalculate_account_balance($pdo, $oldAccountId);
            finance_recalculate_account_balance($pdo, $accountId);

            return (int)$existing['id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (
                transaction_code,
                transaction_date,
                type,
                category_id,
                account_id,
                project_id,
                amount,
                description,
                payment_method,
                proof_file,
                source_module,
                source_table,
                source_id,
                reference_code,
                created_by,
                created_at,
                updated_at
            )
            VALUES
            (
                :transaction_code,
                :transaction_date,
                'income',
                :category_id,
                :account_id,
                :project_id,
                :amount,
                :description,
                :payment_method,
                :proof_file,
                'project',
                'project_payment_terms',
                :source_id,
                :reference_code,
                :created_by,
                NOW(),
                NOW()
            )
        ");
        $stmt->execute([
            ':transaction_code' => finance_generate_transaction_code($pdo, 'income'),
            ':transaction_date' => $term['paid_date'] ?: date('Y-m-d'),
            ':category_id' => $categoryId,
            ':account_id' => $accountId,
            ':project_id' => $term['project_id'],
            ':amount' => $receivedAmount,
            ':description' => $description,
            ':payment_method' => $paymentMethod,
            ':proof_file' => $proofFile,
            ':source_id' => $termId,
            ':reference_code' => $referenceCode,
            ':created_by' => $userId ?? ($_SESSION['user_id'] ?? null)
        ]);

        $transactionId = (int)$pdo->lastInsertId();
        finance_recalculate_account_balance($pdo, $accountId);

        return $transactionId;
    }
}

/**
 * Hapus transaksi utama Finance Core yang berasal dari termin project.
 */
if (!function_exists('finance_delete_project_term_transaction_if_exists')) {
    function finance_delete_project_term_transaction_if_exists(PDO $pdo, int $termId): void
    {
        $stmt = $pdo->prepare("
            SELECT id, account_id
            FROM transactions
            WHERE source_module = 'project'
              AND source_table = 'project_payment_terms'
              AND source_id = :source_id
            LIMIT 1
        ");
        $stmt->execute([':source_id' => $termId]);
        $trx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trx) {
            return;
        }

        $accountId = !empty($trx['account_id']) ? (int)$trx['account_id'] : null;

        $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = :id");
        $stmt->execute([':id' => (int)$trx['id']]);

        finance_recalculate_account_balance($pdo, $accountId);
    }
}

/**
 * Normalisasi metode pembayaran agar sesuai enum transactions.
 */
if (!function_exists('finance_normalize_payment_method')) {
    function finance_normalize_payment_method(?string $method): string
    {
        $method = $method ?: 'cash';

        if ($method === 'bank_tranfer') {
            $method = 'bank_transfer';
        }

        return in_array($method, ['cash', 'bank_transfer', 'qris', 'other'], true) ? $method : 'other';
    }
}

/**
 * Ambil transaksi finance berdasarkan sumber data.
 */
if (!function_exists('finance_find_transaction_by_source')) {
    function finance_find_transaction_by_source(PDO $pdo, string $sourceModule, string $sourceTable, int $sourceId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT *
            FROM transactions
            WHERE source_module = :source_module
              AND source_table = :source_table
              AND source_id = :source_id
            LIMIT 1
        ");
        $stmt->execute([
            ':source_module' => $sourceModule,
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

/**
 * Hapus transaksi finance berdasarkan sumber data, lalu hitung ulang saldo akun.
 */
if (!function_exists('finance_delete_transaction_by_source')) {
    function finance_delete_transaction_by_source(PDO $pdo, string $sourceModule, string $sourceTable, int $sourceId): void
    {
        $existing = finance_find_transaction_by_source($pdo, $sourceModule, $sourceTable, $sourceId);

        if (!$existing) {
            return;
        }

        $accountId = !empty($existing['account_id']) ? (int)$existing['account_id'] : null;

        $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = :id");
        $stmt->execute([':id' => (int)$existing['id']]);

        finance_recalculate_account_balance($pdo, $accountId);
    }
}

/**
 * Buat/update transaksi finance umum.
 */
if (!function_exists('finance_upsert_transaction')) {
    function finance_upsert_transaction(
        PDO $pdo,
        string $sourceModule,
        string $sourceTable,
        int $sourceId,
        string $type,
        ?int $projectId,
        float $amount,
        string $description,
        string $categoryName,
        ?string $transactionDate = null,
        ?string $paymentMethod = null,
        ?string $referenceCode = null,
        ?int $userId = null,
        ?int $accountId = null,
        ?string $proofFile = null
    ): ?int {
        if ($amount <= 0) {
            finance_delete_transaction_by_source($pdo, $sourceModule, $sourceTable, $sourceId);
            return null;
        }

        $type = $type === 'income' ? 'income' : 'expense';
        $accountId = $accountId ?: finance_get_default_account_id($pdo);
        $categoryId = finance_get_or_create_category_id($pdo, $categoryName, $type);
        $paymentMethod = finance_normalize_payment_method($paymentMethod);
        $transactionDate = $transactionDate ?: date('Y-m-d');

        $existing = finance_find_transaction_by_source($pdo, $sourceModule, $sourceTable, $sourceId);

        if ($existing) {
            $oldAccountId = !empty($existing['account_id']) ? (int)$existing['account_id'] : null;

            $stmt = $pdo->prepare("
                UPDATE transactions
                SET transaction_date = :transaction_date,
                    type = :type,
                    category_id = :category_id,
                    account_id = :account_id,
                    project_id = :project_id,
                    amount = :amount,
                    description = :description,
                    payment_method = :payment_method,
                    proof_file = COALESCE(:proof_file, proof_file),
                    source_module = :source_module,
                    source_table = :source_table,
                    source_id = :source_id,
                    reference_code = :reference_code,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':transaction_date' => $transactionDate,
                ':type' => $type,
                ':category_id' => $categoryId,
                ':account_id' => $accountId,
                ':project_id' => $projectId,
                ':amount' => $amount,
                ':description' => $description,
                ':payment_method' => $paymentMethod,
                ':proof_file' => $proofFile,
                ':source_module' => $sourceModule,
                ':source_table' => $sourceTable,
                ':source_id' => $sourceId,
                ':reference_code' => $referenceCode,
                ':id' => (int)$existing['id']
            ]);

            finance_recalculate_account_balance($pdo, $oldAccountId);
            finance_recalculate_account_balance($pdo, $accountId);

            return (int)$existing['id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (
                transaction_code, transaction_date, type, category_id, account_id, project_id,
                amount, description, payment_method, proof_file, source_module, source_table,
                source_id, reference_code, created_by, created_at, updated_at
            )
            VALUES
            (
                :transaction_code, :transaction_date, :type, :category_id, :account_id, :project_id,
                :amount, :description, :payment_method, :proof_file, :source_module, :source_table,
                :source_id, :reference_code, :created_by, NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':transaction_code' => finance_generate_transaction_code($pdo, $type),
            ':transaction_date' => $transactionDate,
            ':type' => $type,
            ':category_id' => $categoryId,
            ':account_id' => $accountId,
            ':project_id' => $projectId,
            ':amount' => $amount,
            ':description' => $description,
            ':payment_method' => $paymentMethod,
            ':proof_file' => $proofFile,
            ':source_module' => $sourceModule,
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId,
            ':reference_code' => $referenceCode,
            ':created_by' => $userId ?? ($_SESSION['user_id'] ?? null)
        ]);

        $transactionId = (int)$pdo->lastInsertId();
        finance_recalculate_account_balance($pdo, $accountId);

        return $transactionId;
    }
}

/**
 * Buat/update hutang dana talangan dari sumber project.
 * Dipakai saat biaya project dibayar dulu oleh personal/kepala divisi.
 */
if (!function_exists('finance_upsert_project_person_debt')) {
    function finance_upsert_project_person_debt(
        PDO $pdo,
        string $sourceTable,
        int $sourceId,
        int $projectId,
        string $personName,
        float $amount,
        string $category,
        string $description,
        ?string $issueDate = null,
        ?string $dueDate = null,
        ?int $userId = null,
        ?int $relatedId = null
    ): ?int {
        if ($amount <= 0 || trim($personName) === '') {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT id, paid_amount
            FROM debts
            WHERE source_module = 'other'
              AND source_table = :source_table
              AND source_id = :source_id
              AND type = 'debt'
            LIMIT 1
        ");
        $stmt->execute([
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && (float)$existing['paid_amount'] > $amount) {
            throw new Exception('Nominal dana talangan tidak boleh lebih kecil dari pembayaran hutang yang sudah tercatat.');
        }

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE debts
                SET related_type = 'personal',
                    related_id = :related_id,
                    category = :category,
                    issue_date = COALESCE(:issue_date, issue_date, CURDATE()),
                    related_name = :related_name,
                    project_id = :project_id,
                    amount = :amount,
                    due_date = :due_date,
                    source_table = :source_table,
                    source_id = :source_id,
                    reference_code = :reference_code,
                    description = :description,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':category' => $category,
                ':issue_date' => $issueDate ?: date('Y-m-d'),
                ':related_name' => $personName,
                ':related_id' => $relatedId,
                ':project_id' => $projectId,
                ':amount' => $amount,
                ':due_date' => $dueDate,
                ':source_table' => $sourceTable,
                ':source_id' => $sourceId,
                ':reference_code' => $sourceTable . '#' . $sourceId,
                ':description' => $description,
                ':id' => (int)$existing['id']
            ]);
            finance_update_debt_status($pdo, (int)$existing['id']);
            return (int)$existing['id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO debts
            (
                debt_code, type, related_type, related_id, category, issue_date, related_name,
                project_id, amount, paid_amount, due_date, payment_method, proof_file,
                reminder_days_before, notification_id, source_module, source_table, source_id,
                reference_code, status, paid_date, description, created_by, created_at, updated_at
            )
            VALUES
            (
                :debt_code, 'debt', 'personal', :related_id, :category, :issue_date, :related_name,
                :project_id, :amount, 0, :due_date, NULL, NULL,
                7, NULL, 'other', :source_table, :source_id,
                :reference_code, 'unpaid', NULL, :description, :created_by, NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':debt_code' => finance_generate_debt_code($pdo, 'debt'),
            ':category' => $category,
            ':issue_date' => $issueDate ?: date('Y-m-d'),
            ':related_name' => $personName,
            ':related_id' => $relatedId,
            ':project_id' => $projectId,
            ':amount' => $amount,
            ':due_date' => $dueDate,
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId,
            ':reference_code' => $sourceTable . '#' . $sourceId,
            ':description' => $description,
            ':created_by' => $userId ?? ($_SESSION['user_id'] ?? null)
        ]);

        return (int)$pdo->lastInsertId();
    }
}

/**
 * Hapus hutang dana talangan project jika belum dibayar.
 */
if (!function_exists('finance_delete_project_person_debt_if_unpaid')) {
    function finance_delete_project_person_debt_if_unpaid(PDO $pdo, string $sourceTable, int $sourceId): void
    {
        $stmt = $pdo->prepare("
            SELECT id, paid_amount
            FROM debts
            WHERE source_module = 'other'
              AND source_table = :source_table
              AND source_id = :source_id
              AND type = 'debt'
            LIMIT 1
        ");
        $stmt->execute([
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId
        ]);
        $debt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$debt) {
            return;
        }

        $pay = $pdo->prepare("SELECT COUNT(*) FROM debt_payments WHERE debt_id = :id");
        $pay->execute([':id' => (int)$debt['id']]);

        if ((float)$debt['paid_amount'] > 0 || (int)$pay->fetchColumn() > 0) {
            throw new Exception('Data tidak bisa dihapus karena hutang dana talangan sudah memiliki pembayaran.');
        }

        $stmt = $pdo->prepare("DELETE FROM debts WHERE id = :id");
        $stmt->execute([':id' => (int)$debt['id']]);
    }
}

/**
 * Sinkronisasi upah worker project ke Finance Core.
 */
if (!function_exists('finance_sync_project_worker_transaction')) {
    function finance_sync_project_worker_transaction(PDO $pdo, int $workerId, ?int $userId = null): ?int
    {
        $stmt = $pdo->prepare("
            SELECT
                w.*,
                p.project_name
            FROM project_workers w
            INNER JOIN projects p ON p.id = w.project_id
            WHERE w.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $workerId]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$w) {
            return null;
        }

        $amount = (float)$w['total_amount'];
        $description = 'Biaya worker project: ' . $w['project_name'] . ' - ' . $w['worker_name'];

        if ($w['payment_status'] !== 'paid' || $amount <= 0) {
            finance_delete_transaction_by_source($pdo, 'project', 'project_workers', $workerId);
            finance_delete_project_person_debt_if_unpaid($pdo, 'project_workers', $workerId);

            $stmt = $pdo->prepare("UPDATE project_workers SET transaction_id = NULL WHERE id = :id");
            $stmt->execute([':id' => $workerId]);
            return null;
        }

        if (($w['funding_source'] ?? 'company') === 'person') {
            finance_delete_transaction_by_source($pdo, 'project', 'project_workers', $workerId);

            $debtId = finance_upsert_project_person_debt(
                $pdo,
                'project_workers',
                $workerId,
                (int)$w['project_id'],
                $w['person_name'] ?: $w['worker_name'],
                $amount,
                'Dana Talangan Worker Project',
                $description,
                $w['paid_date'] ?: date('Y-m-d'),
                null,
                $userId,
                !empty($w['personal_employee_id']) ? (int)$w['personal_employee_id'] : null
            );

            $stmt = $pdo->prepare("UPDATE project_workers SET transaction_id = NULL WHERE id = :id");
            $stmt->execute([':id' => $workerId]);

            return $debtId;
        }

        finance_delete_project_person_debt_if_unpaid($pdo, 'project_workers', $workerId);

        $transactionId = finance_upsert_transaction(
            $pdo,
            'project',
            'project_workers',
            $workerId,
            'expense',
            (int)$w['project_id'],
            $amount,
            $description,
            'Biaya Worker Project',
            $w['paid_date'] ?: date('Y-m-d'),
            'cash',
            $w['worker_name'],
            $userId
        );

        $stmt = $pdo->prepare("UPDATE project_workers SET transaction_id = :transaction_id WHERE id = :id");
        $stmt->execute([
            ':transaction_id' => $transactionId,
            ':id' => $workerId
        ]);

        return $transactionId;
    }
}

if (!function_exists('finance_delete_project_worker_transaction_if_exists')) {
    function finance_delete_project_worker_transaction_if_exists(PDO $pdo, int $workerId): void
    {
        finance_delete_transaction_by_source($pdo, 'project', 'project_workers', $workerId);
        finance_delete_project_person_debt_if_unpaid($pdo, 'project_workers', $workerId);

        $stmt = $pdo->prepare("UPDATE project_workers SET transaction_id = NULL WHERE id = :id");
        $stmt->execute([':id' => $workerId]);
    }
}

/**
 * Sinkronisasi dana project ke Finance Core.
 * - Dana dari company = pengeluaran kas/bank perusahaan.
 * - Dana dari person = hutang/dana talangan kepada personal.
 */
if (!function_exists('finance_sync_project_fund_transaction')) {
    function finance_sync_project_fund_transaction(PDO $pdo, int $fundId, ?int $userId = null): ?int
    {
        /*
         * Konsep final Nukari:
         * project_funds tidak lagi menjadi transaksi operasional.
         * Pendanaan Project dihitung otomatis dari project_transactions, project_workers, debts, dan transactions.
         * Fungsi ini sengaja dibuat aman/no-op agar data lama tidak lagi mencatat modal sebagai income/expense.
         */
        finance_delete_transaction_by_source($pdo, 'project', 'project_funds', $fundId);
        finance_delete_project_person_debt_if_unpaid($pdo, 'project_funds', $fundId);

        $stmt = $pdo->prepare("UPDATE project_funds SET transaction_id = NULL, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $fundId]);

        return null;
    }
}

if (!function_exists('finance_delete_project_fund_transaction_if_exists')) {
    function finance_delete_project_fund_transaction_if_exists(PDO $pdo, int $fundId): void
    {
        finance_delete_transaction_by_source($pdo, 'project', 'project_funds', $fundId);
        finance_delete_project_person_debt_if_unpaid($pdo, 'project_funds', $fundId);

        $stmt = $pdo->prepare("UPDATE project_funds SET transaction_id = NULL, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $fundId]);
    }
}

/**
 * Sinkronisasi transaksi project manual ke Finance Core.
 */
if (!function_exists('finance_sync_project_transaction')) {
    function finance_sync_project_transaction(PDO $pdo, int $projectTransactionId, ?int $userId = null): ?int
    {
        $stmt = $pdo->prepare("
            SELECT
                pt.*,
                p.project_name
            FROM project_transactions pt
            INNER JOIN projects p ON p.id = pt.project_id
            WHERE pt.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $projectTransactionId]);
        $pt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pt || (float)$pt['amount'] <= 0) {
            return null;
        }

        $amount = (float)$pt['amount'];
        $type = $pt['transaction_type'] === 'income' ? 'income' : 'expense';
        $accountId = !empty($pt['account_id']) ? (int)$pt['account_id'] : null;
        $paymentMethod = $pt['payment_method'] ?? 'cash';
        $category = $pt['category'] ?: ($type === 'income' ? 'Pendapatan Project' : 'Biaya Project');
        $description = 'Transaksi project: ' . $pt['project_name'];
        if (!empty($pt['description'])) {
            $description .= ' - ' . $pt['description'];
        }

        if ($type === 'expense') {
            finance_apply_project_expense_source(
                $pdo,
                'project_transactions',
                $projectTransactionId,
                (int)$pt['project_id'],
                $pt['funding_source'] ?? 'company',
                $amount,
                $category,
                $description,
                $pt['transaction_date'] ?: date('Y-m-d'),
                $accountId,
                !empty($pt['operational_fund_id']) ? (int)$pt['operational_fund_id'] : null,
                $pt['person_name'] ?? null,
                !empty($pt['personal_employee_id']) ? (int)$pt['personal_employee_id'] : null,
                $paymentMethod,
                $userId
            );
            return null;
        }

        finance_clear_project_expense_links($pdo, 'project_transactions', $projectTransactionId);

        return finance_upsert_transaction(
            $pdo,
            'project',
            'project_transactions',
            $projectTransactionId,
            $type,
            (int)$pt['project_id'],
            $amount,
            $description,
            $category,
            $pt['transaction_date'] ?: date('Y-m-d'),
            $paymentMethod,
            'project_transactions#' . $projectTransactionId,
            $userId,
            $accountId
        );
    }
}

if (!function_exists('finance_delete_project_transaction_if_exists')) {
    function finance_delete_project_transaction_if_exists(PDO $pdo, int $projectTransactionId): void
    {
        finance_delete_transaction_by_source($pdo, 'project', 'project_transactions', $projectTransactionId);
        finance_delete_project_person_debt_if_unpaid($pdo, 'project_transactions', $projectTransactionId);
    }
}

/**
 * Sinkronisasi payroll yang sudah dibayar ke Finance Core.
 */
if (!function_exists('finance_sync_payroll_transaction')) {
    function finance_sync_payroll_transaction(PDO $pdo, int $payrollId, ?int $userId = null): ?int
    {
        $stmt = $pdo->prepare("
            SELECT
                py.*,
                e.full_name
            FROM payrolls py
            INNER JOIN employees e ON e.id = py.employee_id
            WHERE py.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $payrollId]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payroll) {
            return null;
        }

        if (($payroll['status'] ?? 'draft') !== 'paid' || (float)$payroll['net_salary'] <= 0) {
            finance_delete_transaction_by_source($pdo, 'payroll', 'payrolls', $payrollId);
            return null;
        }

        return finance_upsert_transaction(
            $pdo,
            'payroll',
            'payrolls',
            $payrollId,
            'expense',
            null,
            (float)$payroll['net_salary'],
            'Pembayaran payroll: ' . $payroll['full_name'] . ' - ' . $payroll['payroll_month'],
            'Payroll',
            $payroll['paid_date'] ?: date('Y-m-d'),
            'cash',
            $payroll['payroll_month'] . '-' . $payroll['employee_id'],
            $userId
        );
    }
}

if (!function_exists('finance_delete_payroll_transaction_if_exists')) {
    function finance_delete_payroll_transaction_if_exists(PDO $pdo, int $payrollId): void
    {
        finance_delete_transaction_by_source($pdo, 'payroll', 'payrolls', $payrollId);
    }
}

/**
 * Hapus link finance/debt/dana operasional dari satu pengeluaran project.
 * Aman untuk re-sync saat edit. Hutang yang sudah dibayar tidak dihapus.
 */
if (!function_exists('finance_clear_project_expense_links')) {
    function finance_clear_project_expense_links(PDO $pdo, string $sourceTable, int $sourceId): void
    {
        finance_delete_transaction_by_source($pdo, 'project', $sourceTable, $sourceId);

        if (function_exists('opfund_delete_movements_by_source')) {
            opfund_delete_movements_by_source($pdo, 'project', $sourceTable, $sourceId);
        }

        $stmt = $pdo->prepare("SELECT id, paid_amount FROM debts WHERE source_module = 'other' AND source_table = :source_table AND source_id = :source_id AND type = 'debt'");
        $stmt->execute([':source_table' => $sourceTable, ':source_id' => $sourceId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $debt) {
            if ((float)$debt['paid_amount'] > 0) {
                throw new Exception('Sumber dana tidak dapat diubah karena hutang personal dari transaksi ini sudah memiliki pembayaran. Batalkan/ubah pembayaran hutang terlebih dahulu.');
            }
            $del = $pdo->prepare("DELETE FROM debts WHERE id = :id LIMIT 1");
            $del->execute([':id' => (int)$debt['id']]);
        }
    }
}

if (!function_exists('finance_apply_project_expense_source')) {
    function finance_apply_project_expense_source(
        PDO $pdo,
        string $sourceTable,
        int $sourceId,
        int $projectId,
        string $fundingSource,
        float $amount,
        string $category,
        string $description,
        ?string $transactionDate = null,
        ?int $accountId = null,
        ?int $operationalFundId = null,
        ?string $personName = null,
        ?int $personalEmployeeId = null,
        ?string $paymentMethod = null,
        ?int $userId = null
    ): void {
        $amount = (float)$amount;
        if ($amount <= 0) {
            finance_clear_project_expense_links($pdo, $sourceTable, $sourceId);
            return;
        }

        $fundingSource = in_array($fundingSource, ['company', 'operational_fund', 'person'], true) ? $fundingSource : 'company';
        $transactionDate = $transactionDate ?: date('Y-m-d');
        $paymentMethod = finance_normalize_payment_method($paymentMethod ?: 'cash');
        finance_clear_project_expense_links($pdo, $sourceTable, $sourceId);

        if ($fundingSource === 'company') {
            if (!$accountId) {
                throw new Exception('Akun kas/bank wajib dipilih untuk pengeluaran dari perusahaan.');
            }
            finance_upsert_transaction($pdo, 'project', $sourceTable, $sourceId, 'expense', $projectId, $amount, $description, $category, $transactionDate, $paymentMethod, strtoupper($sourceTable) . '-' . $sourceId, $userId, $accountId);
            return;
        }

        if ($fundingSource === 'operational_fund') {
            if (!$operationalFundId || !function_exists('opfund_get')) {
                throw new Exception('Dana Operasional wajib dipilih.');
            }
            $fund = opfund_get($pdo, $operationalFundId);
            if (!$fund) {
                throw new Exception('Dana Operasional tidak ditemukan.');
            }
            $holderName = trim((string)($fund['person_name'] ?? ''));
            $holderEmployeeId = !empty($fund['employee_id']) ? (int)$fund['employee_id'] : null;
            $balanceBefore = (float)($fund['current_balance'] ?? 0);
            $usedFromOperational = min($amount, max(0, $balanceBefore));
            $shortfall = max(0, $amount - $usedFromOperational);

            if ($usedFromOperational > 0) {
                opfund_add_movement($pdo, $operationalFundId, 'expense', $usedFromOperational, $transactionDate, null, $description, 'project', $sourceTable, $sourceId, $projectId, $paymentMethod, $userId);
            }
            if ($shortfall > 0) {
                opfund_add_movement($pdo, $operationalFundId, 'personal_advance', $shortfall, $transactionDate, null, 'Selisih belanja melebihi saldo Dana Operasional: ' . $description, 'project', $sourceTable, $sourceId, $projectId, $paymentMethod, $userId);
                finance_upsert_project_person_debt($pdo, $sourceTable, $sourceId, $projectId, $holderName, $shortfall, $category, 'Talangan personal karena Dana Operasional kurang: ' . $description, $transactionDate, null, $userId, $holderEmployeeId);
            }
            return;
        }

        $personName = trim((string)$personName);
        if ($personName === '' && $personalEmployeeId) {
            $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $personalEmployeeId]);
            $personName = (string)$stmt->fetchColumn();
        }
        if ($personName === '') {
            throw new Exception('Nama personal wajib diisi untuk talangan personal.');
        }
        finance_upsert_project_person_debt($pdo, $sourceTable, $sourceId, $projectId, $personName, $amount, $category, $description, $transactionDate, null, $userId, $personalEmployeeId ?: null);
    }
}
