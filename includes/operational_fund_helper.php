<?php
/**
 * Nukari Operational Fund Helper
 * Dana Operasional = uang perusahaan yang dititipkan ke personal.
 * Catatan penting:
 * - Transfer ke personal bukan expense.
 * - Pengembalian sisa bukan income.
 * - Saldo akun kas/bank dihitung dari transactions + movement dana operasional.
 */

if (!function_exists('opfund_table_exists')) {
    function opfund_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
            $stmt->execute([':table' => $table]);
            return ((int)$stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('opfund_generate_code')) {
    function opfund_generate_code(PDO $pdo): string
    {
        $ym = date('Ym');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM operational_funds WHERE fund_code LIKE :code");
        $stmt->execute([':code' => 'DO-' . $ym . '-%']);
        $num = ((int)$stmt->fetchColumn()) + 1;
        return 'DO-' . $ym . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('opfund_find_open')) {
    function opfund_find_open(PDO $pdo, ?int $employeeId, string $personName): ?array
    {
        if ($employeeId) {
            $stmt = $pdo->prepare("SELECT * FROM operational_funds WHERE employee_id = :employee_id AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stmt->execute([':employee_id' => $employeeId]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM operational_funds WHERE employee_id IS NULL AND person_name = :person_name AND status = 'open' ORDER BY id DESC LIMIT 1");
            $stmt->execute([':person_name' => $personName]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('opfund_find_open_by_party')) {
    function opfund_find_open_by_party(PDO $pdo, int $partyId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM operational_funds WHERE holder_party_id=:party_id AND status='open' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':party_id'=>$partyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('opfund_create')) {
    function opfund_create(PDO $pdo, ?int $employeeId, string $personName, ?int $userId = null, ?string $notes = null): int
    {
        $personName = trim($personName);
        if ($personName === '' && $employeeId) {
            $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $employeeId]);
            $personName = (string)$stmt->fetchColumn();
        }
        if ($personName === '') {
            throw new Exception('Nama penerima dana operasional wajib diisi.');
        }

        $stmt = $pdo->prepare("INSERT INTO operational_funds
            (fund_code, employee_id, person_name, status, total_received, total_used, total_returned, total_personal_advance, current_balance, notes, created_by, created_at, updated_at)
            VALUES (:fund_code, :employee_id, :person_name, 'open', 0, 0, 0, 0, 0, :notes, :created_by, NOW(), NOW())");
        $stmt->execute([
            ':fund_code' => opfund_generate_code($pdo),
            ':employee_id' => $employeeId ?: null,
            ':person_name' => $personName,
            ':notes' => $notes,
            ':created_by' => $userId ?? ($_SESSION['user_id'] ?? null),
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('opfund_create_for_party')) {
    function opfund_create_for_party(PDO $pdo, int $partyId, ?int $userId=null, ?string $notes=null): int
    {
        $stmt=$pdo->prepare("SELECT display_name,employee_id FROM company_parties WHERE id=:id AND status='active' LIMIT 1");
        $stmt->execute([':id'=>$partyId]);$party=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$party) throw new Exception('Pihak/PIC tidak ditemukan atau tidak aktif.');
        $stmt=$pdo->prepare("INSERT INTO operational_funds(fund_code,holder_party_id,employee_id,person_name,status,total_received,total_used,total_returned,total_personal_advance,current_balance,notes,created_by,created_at,updated_at) VALUES(:code,:party,:employee,:name,'open',0,0,0,0,0,:notes,:user,NOW(),NOW())");
        $stmt->execute([':code'=>opfund_generate_code($pdo),':party'=>$partyId,':employee'=>$party['employee_id']?:null,':name'=>$party['display_name'],':notes'=>$notes,':user'=>$userId??($_SESSION['user_id']??null)]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('opfund_recalculate')) {
    function opfund_recalculate(PDO $pdo, int $fundId): void
    {
        $stmt = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN movement_type = 'receive' THEN amount ELSE 0 END),0) AS received,
                COALESCE(SUM(CASE WHEN movement_type = 'expense' THEN amount ELSE 0 END),0) AS used,
                COALESCE(SUM(CASE WHEN movement_type = 'return' THEN amount ELSE 0 END),0) AS returned,
                COALESCE(SUM(CASE WHEN movement_type = 'personal_advance' THEN amount ELSE 0 END),0) AS personal_advance,
                COALESCE(SUM(CASE WHEN movement_type = 'adjustment' THEN amount ELSE 0 END),0) AS adjustment
            FROM operational_fund_movements
            WHERE fund_id = :fund_id
              AND posting_mode = 'posted'");
        $stmt->execute([':fund_id' => $fundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $received = (float)($row['received'] ?? 0);
        $used = (float)($row['used'] ?? 0);
        $returned = (float)($row['returned'] ?? 0);
        $advance = (float)($row['personal_advance'] ?? 0);
        $adjustment = (float)($row['adjustment'] ?? 0);
        // Saldo sengaja boleh negatif. Nilai negatif berarti perusahaan
        // berhutang kepada PIC karena pemakaian uang personal.
        $balance = $received - $used - $returned + $adjustment;
        $advance = max(0, -$balance);
        // Satu dompet tetap digunakan untuk PIC yang sama pada pemberian berikutnya.
        $status = 'open';
        $stmt = $pdo->prepare("UPDATE operational_funds
            SET total_received = :received,
                total_used = :used,
                total_returned = :returned,
                total_personal_advance = :advance,
                current_balance = :balance,
                status = :status,
                closed_at = CASE WHEN :closed_status = 'closed' THEN COALESCE(closed_at, NOW()) ELSE NULL END,
                updated_at = NOW()
            WHERE id = :id");
        $stmt->execute([
            ':received' => $received,
            ':used' => $used,
            ':returned' => $returned,
            ':advance' => $advance,
            ':balance' => $balance,
            ':status' => $status,
            ':closed_status' => $status,
            ':id' => $fundId,
        ]);

        // Cerminkan saldo minus sebagai hutang dinamis perusahaan kepada PIC.
        if (opfund_table_exists($pdo, 'debts')) {
            $fundStmt = $pdo->prepare("SELECT employee_id, holder_party_id, person_name, fund_code FROM operational_funds WHERE id=:id");
            $fundStmt->execute([':id' => $fundId]);
            $fund = $fundStmt->fetch(PDO::FETCH_ASSOC);
            if ($fund) {
                $debtStmt = $pdo->prepare("SELECT id, amount FROM debts WHERE source_module='operational_fund' AND source_id=:id ORDER BY id DESC LIMIT 1");
                $debtStmt->execute([':id' => $fundId]);
                $debt = $debtStmt->fetch(PDO::FETCH_ASSOC);
                if ($advance > 0) {
                    if ($debt) {
                        $pdo->prepare("UPDATE debts SET amount=:amount, principal_amount=:principal_amount, paid_amount=0, status='unpaid', paid_date=NULL, updated_at=NOW() WHERE id=:id")
                            ->execute([':amount' => $advance, ':principal_amount'=>$advance, ':id' => $debt['id']]);
                    } else {
                        $code = 'HT-' . date('Ym') . '-' . str_pad((string)$fundId, 4, '0', STR_PAD_LEFT);
                        $pdo->prepare("INSERT INTO debts (debt_code,type,related_type,related_id,related_name,category,issue_date,amount,principal_amount,source_module,source_table,source_id,reference_code,status,description,created_at,updated_at) VALUES (:code,'debt','personal',:party_id,:name,'Dana Operasional PIC',CURDATE(),:amount,:principal_amount,'operational_fund','operational_funds',:source_id,:reference,'unpaid','Saldo dana operasional minus; perusahaan berhutang kepada PIC.',NOW(),NOW())")
                            ->execute([':code'=>$code, ':party_id'=>$fund['holder_party_id'], ':name'=>$fund['person_name'], ':amount'=>$advance, ':principal_amount'=>$advance, ':source_id'=>$fundId, ':reference'=>$fund['fund_code']]);
                    }
                } elseif ($debt && (float)$debt['amount'] > 0) {
                    $pdo->prepare("UPDATE debts SET paid_amount=amount, status='paid', paid_date=CURDATE(), updated_at=NOW() WHERE id=:id")
                        ->execute([':id' => $debt['id']]);
                }
            }
        }
    }
}

if (!function_exists('opfund_add_movement')) {
    function opfund_add_movement(
        PDO $pdo,
        int $fundId,
        string $movementType,
        float $amount,
        ?string $movementDate = null,
        ?int $accountId = null,
        ?string $description = null,
        ?string $sourceModule = null,
        ?string $sourceTable = null,
        ?int $sourceId = null,
        ?int $projectId = null,
        ?string $paymentMethod = null,
        ?int $userId = null,
        string $postingMode = 'posted',
        ?string $historicalReason = null
    ): int {
        if (!in_array($movementType, ['receive','expense','return','personal_advance','adjustment'], true)) {
            throw new Exception('Jenis mutasi dana operasional tidak valid.');
        }
        if ($amount <= 0) {
            throw new Exception('Nominal mutasi dana operasional harus lebih dari nol.');
        }
        $movementDate = $movementDate ?: date('Y-m-d');
        $paymentMethod = function_exists('finance_normalize_payment_method') ? finance_normalize_payment_method($paymentMethod ?: 'cash') : ($paymentMethod ?: 'cash');
        $postingMode = function_exists('finance_normalize_posting_mode') ? finance_normalize_posting_mode($postingMode) : ($postingMode === 'historical' ? 'historical' : 'posted');
        if (function_exists('finance_validate_posting_date')) finance_validate_posting_date($pdo, $movementDate, $postingMode);

        $stmt = $pdo->prepare("INSERT INTO operational_fund_movements
            (fund_id, movement_date, movement_type, amount, posting_mode, historical_reason, account_id, project_id, source_module, source_table, source_id, description, payment_method, created_by, created_at)
            VALUES (:fund_id, :movement_date, :movement_type, :amount, :posting_mode, :historical_reason, :account_id, :project_id, :source_module, :source_table, :source_id, :description, :payment_method, :created_by, NOW())");
        $stmt->execute([
            ':fund_id' => $fundId,
            ':movement_date' => $movementDate,
            ':movement_type' => $movementType,
            ':amount' => $amount,
            ':posting_mode' => $postingMode,
            ':historical_reason' => $postingMode === 'historical' ? $historicalReason : null,
            ':account_id' => $accountId,
            ':project_id' => $projectId,
            ':source_module' => $sourceModule,
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId,
            ':description' => $description,
            ':payment_method' => $paymentMethod,
            ':created_by' => $userId ?? ($_SESSION['user_id'] ?? null),
        ]);
        $id = (int)$pdo->lastInsertId();
        opfund_recalculate($pdo, $fundId);
        if (function_exists('finance_recalculate_account_balance') && $accountId) {
            finance_recalculate_account_balance($pdo, $accountId);
        }
        return $id;
    }
}

if (!function_exists('opfund_account_effect_totals')) {
    function opfund_account_effect_totals(PDO $pdo, int $accountId): array
    {
        if (!opfund_table_exists($pdo, 'operational_fund_movements')) {
            return ['out_total' => 0.0, 'in_total' => 0.0];
        }
        $stmt = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN movement_type = 'receive' THEN amount ELSE 0 END),0) AS out_total,
                COALESCE(SUM(CASE WHEN movement_type = 'return' THEN amount ELSE 0 END),0) AS in_total
            FROM operational_fund_movements
            WHERE account_id = :account_id
              AND posting_mode = 'posted'");
        $stmt->execute([':account_id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'out_total' => (float)($row['out_total'] ?? 0),
            'in_total' => (float)($row['in_total'] ?? 0),
        ];
    }
}

if (!function_exists('opfund_summary')) {
    function opfund_summary(PDO $pdo): array
    {
        if (!opfund_table_exists($pdo, 'operational_funds')) {
            return ['open_count' => 0, 'active_balance' => 0, 'active_cash' => 0, 'total_advance' => 0, 'total_received' => 0];
        }
        $row = $pdo->query("SELECT
                COUNT(CASE WHEN status = 'open' THEN 1 END) AS open_count,
                COALESCE(SUM(CASE WHEN status = 'open' THEN current_balance ELSE 0 END),0) AS active_balance,
                COALESCE(SUM(CASE WHEN status = 'open' THEN GREATEST(current_balance,0) ELSE 0 END),0) AS active_cash,
                COALESCE(SUM(total_personal_advance),0) AS total_advance,
                COALESCE(SUM(total_received),0) AS total_received
            FROM operational_funds")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'open_count' => (int)($row['open_count'] ?? 0),
            'active_balance' => (float)($row['active_balance'] ?? 0),
            'active_cash' => (float)($row['active_cash'] ?? 0),
            'total_advance' => (float)($row['total_advance'] ?? 0),
            'total_received' => (float)($row['total_received'] ?? 0),
        ];
    }
}

if (!function_exists('opfund_movement_label')) {
    function opfund_movement_label(string $type): string
    {
        $map = [
            'receive' => 'Dana Diberikan',
            'expense' => 'Dipakai Belanja',
            'return' => 'Sisa Dikembalikan',
            'personal_advance' => 'Talangan Personal',
            'adjustment' => 'Penyesuaian',
        ];
        return $map[$type] ?? ucfirst($type);
    }
}

if (!function_exists('opfund_delete_movements_by_source')) {
    function opfund_delete_movements_by_source(PDO $pdo, string $sourceModule, string $sourceTable, int $sourceId): void
    {
        if (!opfund_table_exists($pdo, 'operational_fund_movements')) {
            return;
        }
        $stmt = $pdo->prepare("SELECT DISTINCT fund_id, account_id FROM operational_fund_movements WHERE source_module = :source_module AND source_table = :source_table AND source_id = :source_id");
        $stmt->execute([
            ':source_module' => $sourceModule,
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId,
        ]);
        $affected = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $del = $pdo->prepare("DELETE FROM operational_fund_movements WHERE source_module = :source_module AND source_table = :source_table AND source_id = :source_id");
        $del->execute([
            ':source_module' => $sourceModule,
            ':source_table' => $sourceTable,
            ':source_id' => $sourceId,
        ]);

        foreach ($affected as $row) {
            if (!empty($row['fund_id'])) {
                opfund_recalculate($pdo, (int)$row['fund_id']);
            }
            if (function_exists('finance_recalculate_account_balance') && !empty($row['account_id'])) {
                finance_recalculate_account_balance($pdo, (int)$row['account_id']);
            }
        }
    }
}

if (!function_exists('opfund_get')) {
    function opfund_get(PDO $pdo, int $fundId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM operational_funds WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $fundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
