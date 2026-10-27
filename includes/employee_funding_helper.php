<?php
require_once __DIR__.'/finance_helper.php';
require_once __DIR__.'/operational_fund_helper.php';
require_once __DIR__.'/company_party_helper.php';

function employee_funding_options(PDO $pdo): array
{
    return [
        'accounts'=>$pdo->query("SELECT id,account_name,current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC,account_name")->fetchAll(PDO::FETCH_ASSOC),
        'funds'=>$pdo->query("SELECT f.id,f.fund_code,f.person_name,f.current_balance,p.display_name FROM operational_funds f LEFT JOIN company_parties p ON p.id=f.holder_party_id WHERE f.status='open' ORDER BY COALESCE(p.display_name,f.person_name)")->fetchAll(PDO::FETCH_ASSOC),
        'parties'=>party_active_list($pdo),
    ];
}

function employee_funding_disburse(PDO $pdo,string $sourceTable,int $sourceId,float $amount,string $date,string $fundingSource,?int $accountId,?int $fundId,?int $partyId,string $paymentMethod,string $description,?int $userId,string $postingMode='posted',?string $historicalReason=null): array
{
    if($amount<=0)throw new Exception('Nominal pencairan harus lebih dari nol.');
    if(!in_array($fundingSource,['company','operational_fund','party_advance'],true))throw new Exception('Sumber dana tidak valid.');
    $postingMode=finance_validate_posting_mode($postingMode,$historicalReason);
    $historicalReason=$postingMode==='historical'?trim((string)$historicalReason):null;
    $transactionId=null;$debtId=null;
    if($fundingSource==='company'){
        if($postingMode==='posted'&&!$accountId)throw new Exception('Pilih akun kas/bank perusahaan.');
        $transactionId=finance_upsert_transaction($pdo,'payroll',$sourceTable,$sourceId,'expense',null,$amount,$description,'Pencairan Piutang Karyawan',$date,$paymentMethod,strtoupper($sourceTable).'-'.$sourceId,$userId,$accountId,null,$postingMode,$historicalReason);
    }elseif($fundingSource==='operational_fund'){
        if($postingMode==='posted'&&!$fundId)throw new Exception('Pilih Dana Operasional PIC.');
        if($fundId){$fund=opfund_get($pdo,$fundId);if(!$fund||($fund['status']??'')!=='open')throw new Exception('Dana Operasional PIC tidak ditemukan atau tidak aktif.');
            opfund_add_movement($pdo,$fundId,'expense',$amount,$date,null,$description,'payroll',$sourceTable,$sourceId,null,$paymentMethod,$userId,$postingMode,$historicalReason);
        }
    }else{
        if($postingMode==='posted'){
            if(!$partyId)throw new Exception('Pilih Pihak/PIC yang memberikan talangan.');
            $s=$pdo->prepare("SELECT display_name FROM company_parties WHERE id=:id AND status='active'");$s->execute([':id'=>$partyId]);$name=$s->fetchColumn();if(!$name)throw new Exception('Pihak/PIC tidak ditemukan.');
            $s=$pdo->prepare("INSERT INTO debts(debt_code,type,related_type,related_id,related_name,category,issue_date,amount,principal_amount,paid_amount,payment_method,source_module,source_table,source_id,reference_code,status,posting_mode,historical_reason,description,created_by,created_at,updated_at) VALUES(:code,'debt','personal',:party,:name,'Talangan Pencairan Karyawan',:date,:amount,:principal_amount,0,:method,'payroll',:source_table,:source_id,:reference,'unpaid','posted',NULL,:description,:user,NOW(),NOW())");
            $s->execute([':code'=>finance_generate_debt_code($pdo,'debt'),':party'=>$partyId,':name'=>$name,':date'=>$date,':amount'=>$amount,':principal_amount'=>$amount,':method'=>$paymentMethod,':source_table'=>$sourceTable,':source_id'=>$sourceId,':reference'=>strtoupper($sourceTable).'-'.$sourceId,':description'=>$description,':user'=>$userId]);$debtId=(int)$pdo->lastInsertId();
        }
    }
    return ['transaction_id'=>$transactionId,'debt_id'=>$debtId];
}

function employee_funding_clear(PDO $pdo,string $sourceTable,int $sourceId): void
{
    finance_delete_transaction_by_source($pdo,'payroll',$sourceTable,$sourceId);
    opfund_delete_movements_by_source($pdo,'payroll',$sourceTable,$sourceId);
    $s=$pdo->prepare("SELECT id,paid_amount FROM debts WHERE source_module='payroll' AND source_table=:table AND source_id=:id AND type='debt'");$s->execute([':table'=>$sourceTable,':id'=>$sourceId]);
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $d){if((float)$d['paid_amount']>0)throw new Exception('Pencairan tidak dapat dibatalkan karena hutang kepada PIC sudah dibayar.');$pdo->prepare("DELETE FROM debts WHERE id=:id")->execute([':id'=>$d['id']]);}
}

function employee_funding_assert_reversible(PDO $pdo,string $sourceTable,int $sourceId): array
{
    if(!in_array($sourceTable,['employee_cash_advances','employee_loans'],true))throw new Exception('Jenis pencairan tidak dapat dibatalkan.');
    $amountColumn=$sourceTable==='employee_loans'?'loan_amount':'amount';
    $stmt=$pdo->prepare("SELECT *, {$amountColumn} AS original_amount FROM {$sourceTable} WHERE id=:id LIMIT 1 FOR UPDATE");
    $stmt->execute([':id'=>$sourceId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new Exception('Data pencairan tidak ditemukan.');
    if($sourceTable==='employee_loans'){
        $paid=(float)($row['total_paid']??0);
        $countStmt=$pdo->prepare('SELECT COUNT(*) FROM employee_loan_payments WHERE loan_id=:id');
        $countStmt->execute([':id'=>$sourceId]);
        if($paid>0||(int)$countStmt->fetchColumn()>0)throw new Exception('Pinjaman tidak dapat dibatalkan atau dihapus karena sudah memiliki cicilan. Koreksi atau hapus seluruh cicilan terlebih dahulu.');
    }else{
        if(($row['status']??'')==='paid'||(float)($row['remaining_amount']??0)<(float)$row['original_amount'])throw new Exception('Kasbon tidak dapat dibatalkan atau dihapus karena sudah ditandai lunas atau sudah dipotong.');
    }
    if(($row['status']??'')==='cancelled')return $row;
    return $row;
}

function employee_funding_cancel(PDO $pdo,string $sourceTable,int $sourceId): void
{
    $row=employee_funding_assert_reversible($pdo,$sourceTable,$sourceId);
    if(($row['status']??'')==='cancelled')return;
    employee_funding_clear($pdo,$sourceTable,$sourceId);
    $pdo->prepare("UPDATE {$sourceTable} SET status='cancelled',disbursement_status='cancelled',transaction_id=NULL,debt_id=NULL,updated_at=NOW() WHERE id=:id")
        ->execute([':id'=>$sourceId]);
}

function employee_funding_source_label(array $row): string
{
    if (($row['loan_origin'] ?? '') === 'opening_balance') return 'Saldo Awal · tanpa transaksi dana';
    if (($row['posting_mode'] ?? 'posted') === 'historical') return 'Catatan Lama · tanpa perubahan saldo';
    $source=$row['funding_source']??'company';
    if($source==='operational_fund')return 'Dana PIC · '.($row['fund_holder_name']??$row['fund_person_name']??$row['fund_code']??'-');
    if($source==='party_advance')return 'Talangan PIC · '.($row['funding_party_name']??'-');
    return 'Kas/Bank · '.($row['account_name']??'-');
}

function employee_cash_advance_settle_for_payroll(PDO $pdo, array $payroll): void
{
    $deduction = max(0, (float)($payroll['cash_advance_deduction'] ?? 0));
    if ($deduction <= 0) return;

    $stmt = $pdo->prepare("SELECT id,remaining_amount FROM employee_cash_advances WHERE employee_id=:employee_id AND deduction_month=:payroll_month AND status IN ('active','allocated') ORDER BY advance_date,id FOR UPDATE");
    $stmt->execute([
        ':employee_id' => (int)$payroll['employee_id'],
        ':payroll_month' => $payroll['payroll_month'],
    ]);

    $remainingDeduction = $deduction;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cashAdvance) {
        if ($remainingDeduction <= 0) break;
        $balance = max(0, (float)$cashAdvance['remaining_amount']);
        $applied = min($balance, $remainingDeduction);
        $newBalance = max(0, $balance - $applied);
        $newStatus = $newBalance <= 0 ? 'paid' : 'active';
        $update = $pdo->prepare("UPDATE employee_cash_advances SET remaining_amount=:remaining,status=:status,allocated_payroll_id=:payroll_id,deducted_at=NOW(),updated_at=NOW() WHERE id=:id");
        $update->execute([':remaining'=>$newBalance, ':status'=>$newStatus, ':payroll_id'=>(int)$payroll['id'], ':id'=>(int)$cashAdvance['id']]);
        $remainingDeduction -= $applied;
    }

    if ($remainingDeduction > 0.009) {
        throw new Exception('Saldo kasbon aktif berubah setelah payroll dibuat. Silakan recalculate payroll sebelum pembayaran.');
    }
}

function employee_cash_advance_restore_for_payroll(PDO $pdo, int $payrollId): void
{
    if ($payrollId <= 0) return;

    $stmt = $pdo->prepare("SELECT id,amount FROM employee_cash_advances WHERE allocated_payroll_id=:payroll_id FOR UPDATE");
    $stmt->execute([':payroll_id' => $payrollId]);

    $update = $pdo->prepare("UPDATE employee_cash_advances SET remaining_amount=:remaining,status='active',allocated_payroll_id=NULL,deducted_at=NULL,updated_at=NOW() WHERE id=:id");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cashAdvance) {
        $update->execute([
            ':remaining' => max(0, (float)$cashAdvance['amount']),
            ':id' => (int)$cashAdvance['id'],
        ]);
    }
}
