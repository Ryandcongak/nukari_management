<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';
require_once __DIR__ . '/../finance/helpers.php';
require_once __DIR__ . '/../../includes/employee_funding_helper.php';
require_permission('payroll.pay');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, e.full_name FROM payrolls p JOIN employees e ON e.id=p.employee_id WHERE p.id=:id LIMIT 1");
$stmt->execute([':id'=>$id]);
$payroll = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$payroll) redirect('pages/payroll/index.php?error=Payroll tidak ditemukan');
if ($payroll['status'] === 'paid') redirect('pages/payroll/detail.php?id='.$id);

$accounts = $pdo->query("SELECT id,account_name,account_type,current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC,account_name")->fetchAll(PDO::FETCH_ASSOC);
$operationalFunds = $pdo->query("SELECT f.id,f.fund_code,f.person_name,f.current_balance,f.holder_party_id,p.display_name FROM operational_funds f LEFT JOIN company_parties p ON p.id=f.holder_party_id WHERE f.status='open' AND f.current_balance > 0 ORDER BY COALESCE(p.display_name,f.person_name),f.fund_code")->fetchAll(PDO::FETCH_ASSOC);
$selectedFundingSource = $_POST['funding_source'] ?? 'company';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['payment_confirmed'] ?? '') !== 'yes') {
            throw new Exception('Konfirmasi pembayaran wajib dicentang sebelum payroll dibayar dan dikunci.');
        }
        $fundingSource = $_POST['funding_source'] ?? 'company';
        $accountId = (int)($_POST['account_id'] ?? 0);
        $operationalFundId = (int)($_POST['operational_fund_id'] ?? 0);
        $paidDate = $_POST['paid_date'] ?? date('Y-m-d');
        $paymentMethod = $_POST['payment_method'] ?? 'bank_transfer';
        $postingMode = finance_validate_posting_mode($_POST['posting_mode'] ?? 'posted', $_POST['historical_reason'] ?? null);
        $historicalReason = $postingMode === 'historical' ? trim((string)($_POST['historical_reason'] ?? '')) : null;
        finance_validate_posting_date($pdo, $paidDate, $postingMode);
        if (!in_array($fundingSource, ['company', 'operational_fund'], true)) throw new Exception('Sumber dana pembayaran tidak valid.');
        if ($fundingSource === 'company' && !$accountId) throw new Exception('Pilih akun kas/bank pembayaran gaji.');
        if ($fundingSource === 'operational_fund' && !$operationalFundId) throw new Exception('Pilih Dana Operasional PIC.');

        $pdo->beginTransaction();
        $payrollLock = $pdo->prepare("SELECT status,is_locked FROM payrolls WHERE id=:id LIMIT 1 FOR UPDATE");
        $payrollLock->execute([':id'=>$id]);
        $lockedPayroll = $payrollLock->fetch(PDO::FETCH_ASSOC);
        if (!$lockedPayroll || $lockedPayroll['status'] === 'paid' || (int)$lockedPayroll['is_locked'] === 1) {
            throw new Exception('Payroll ini sudah dibayar atau dikunci. Muat ulang halaman untuk melihat status terbaru.');
        }
        $description = 'Pembayaran gaji '.$payroll['full_name'].' periode '.$payroll['payroll_month'];
        $transactionId = null;
        $paymentAccountId = null;
        $paymentOperationalFundId = null;
        $paymentPartyId = null;
        $sourceLabel = '';

        if ($fundingSource === 'company') {
            $accountCheck = $pdo->prepare("SELECT account_name FROM finance_accounts WHERE id=:id AND status='active' LIMIT 1");
            $accountCheck->execute([':id'=>$accountId]);
            $accountName = $accountCheck->fetchColumn();
            if (!$accountName) throw new Exception('Akun kas/bank pembayaran tidak ditemukan atau tidak aktif.');

            $code = f_transaction_code($pdo, 'expense');
            $tx = $pdo->prepare("INSERT INTO transactions (transaction_code,transaction_date,type,account_id,amount,posting_mode,historical_reason,posted_at,posted_by,description,payment_method,source_module,source_table,source_id,reference_code,created_by,created_at,updated_at) VALUES (:code,:date,'expense',:account,:amount,:posting_mode,:historical_reason,:posted_at,:posted_by,:description,:method,'payroll','payrolls',:source_id,:reference,:user,NOW(),NOW())");
            $tx->execute([
                ':code'=>$code, ':date'=>$paidDate, ':account'=>$accountId,
                ':amount'=>$payroll['net_salary'], ':description'=>$description,
                ':posting_mode'=>$postingMode, ':historical_reason'=>$historicalReason,
                ':posted_at'=>$postingMode==='posted'?date('Y-m-d H:i:s'):null,
                ':posted_by'=>$postingMode==='posted'?($_SESSION['user_id']??null):null,
                ':method'=>$paymentMethod, ':source_id'=>$id,
                ':reference'=>'PAYROLL-'.$id, ':user'=>($_SESSION['user_id'] ?? null)
            ]);
            $transactionId = (int)$pdo->lastInsertId();
            $paymentAccountId = $accountId;
            $sourceLabel = 'kas/bank '.$accountName;
        } else {
            $fundStmt = $pdo->prepare("SELECT * FROM operational_funds WHERE id=:id AND status='open' LIMIT 1 FOR UPDATE");
            $fundStmt->execute([':id'=>$operationalFundId]);
            $fund = $fundStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fund) throw new Exception('Dana Operasional PIC tidak ditemukan atau tidak aktif.');
            if ($postingMode === 'posted' && (float)$fund['current_balance'] < (float)$payroll['net_salary']) {
                throw new Exception('Saldo Dana Operasional '.$fund['person_name'].' tidak cukup. Tersedia '.rupiah($fund['current_balance']).', diperlukan '.rupiah($payroll['net_salary']).'.');
            }
            opfund_add_movement($pdo, $operationalFundId, 'expense', (float)$payroll['net_salary'], $paidDate, null, $description, 'payroll', 'payrolls', $id, null, $paymentMethod, $_SESSION['user_id'] ?? null, $postingMode, $historicalReason);
            $paymentOperationalFundId = $operationalFundId;
            $paymentPartyId = !empty($fund['holder_party_id']) ? (int)$fund['holder_party_id'] : null;
            $sourceLabel = 'Dana Operasional '.$fund['person_name'].' ('.$fund['fund_code'].')';
        }

        $pdo->prepare("UPDATE payrolls SET status='paid',paid_date=:date,funding_source=:funding_source,operational_fund_id=:operational_fund_id,payment_party_id=:payment_party_id,payment_account_id=:account,payment_transaction_id=:tx,payment_posting_mode=:posting_mode,payment_historical_reason=:historical_reason,is_locked=1,locked_at=NOW(),locked_by=:user,updated_at=NOW() WHERE id=:id")
            ->execute([':date'=>$paidDate, ':funding_source'=>$fundingSource, ':operational_fund_id'=>$paymentOperationalFundId, ':payment_party_id'=>$paymentPartyId, ':account'=>$paymentAccountId, ':tx'=>$transactionId, ':posting_mode'=>$postingMode, ':historical_reason'=>$historicalReason, ':user'=>($_SESSION['user_id'] ?? null), ':id'=>$id]);
        employee_cash_advance_settle_for_payroll($pdo, $payroll);
        if ($paymentAccountId) f_recalculate_account($pdo, $paymentAccountId);
        log_activity($pdo,'Payroll','PAID_LOCKED','Payroll '.$payroll['full_name'].' dibayar dari '.$sourceLabel.' · '.finance_posting_mode_label($postingMode).($historicalReason?' — '.$historicalReason:''),$id,'payrolls',$_SESSION['user_id'] ?? null);
        $pdo->commit();
        redirect('pages/payroll/detail.php?id='.$id.'&locked=1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
$pageTitle = 'Bayar Payroll - Nukari';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
 <div class="page-header"><h1 class="fw-bold mb-1">Bayar Payroll</h1><p class="text-muted mb-0"><?= f_e($payroll['full_name']); ?> · <?= f_e($payroll['payroll_month']); ?></p></div>
 <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>
 <div class="panel" style="max-width:760px">
  <div class="alert alert-light border rounded-4"><div class="text-muted small">Gaji bersih</div><div class="fs-3 fw-bold text-success"><?= rupiah($payroll['net_salary']); ?></div></div>
  <form method="POST" class="row g-3">
   <?= csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>">
   <div class="col-md-6"><label class="form-label fw-semibold">Tanggal Pembayaran</label><input type="date" name="paid_date" value="<?= date('Y-m-d'); ?>" class="form-control" required></div>
   <div class="col-md-6"><label class="form-label fw-semibold">Metode</label><select name="payment_method" class="form-select"><option value="bank_transfer">Bank Transfer</option><option value="cash">Cash</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
   <?php if (finance_user_can_use_non_posting()): ?><div class="col-12"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" id="postingMode" class="form-select"><option value="posted">Pembayaran berjalan — memengaruhi saldo</option><option value="historical">Pembayaran historis — tidak memengaruhi saldo/dana</option></select></div><div class="col-12 d-none" id="historicalReasonField"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"></textarea></div><?php endif; ?>
   <div class="col-12"><label class="form-label fw-semibold">Jenis Sumber Dana</label><select name="funding_source" id="fundingSource" class="form-select"><option value="company" <?= $selectedFundingSource === 'company' ? 'selected' : ''; ?>>Kas/Bank Perusahaan</option><option value="operational_fund" <?= $selectedFundingSource === 'operational_fund' ? 'selected' : ''; ?>>Dana Operasional PIC</option></select></div>
   <div class="col-12 <?= $selectedFundingSource === 'operational_fund' ? 'd-none' : ''; ?>" id="companySource"><label class="form-label fw-semibold">Akun Kas/Bank</label><select name="account_id" class="form-select"><option value="">Pilih kas/bank</option><?php foreach($accounts as $a): ?><option value="<?= (int)$a['id']; ?>" <?= (int)($_POST['account_id'] ?? 0) === (int)$a['id'] ? 'selected' : ''; ?>><?= f_e($a['account_name']); ?> · <?= rupiah($a['current_balance']); ?></option><?php endforeach; ?></select><div class="form-text">Pembayaran otomatis tercatat sebagai pengeluaran di Buku Kas.</div></div>
   <div class="col-12 <?= $selectedFundingSource === 'operational_fund' ? '' : 'd-none'; ?>" id="operationalFundSource"><label class="form-label fw-semibold">Dana Operasional PIC</label><select name="operational_fund_id" class="form-select"><option value="">Pilih pemegang dana</option><?php foreach($operationalFunds as $fund): ?><option value="<?= (int)$fund['id']; ?>" <?= (int)($_POST['operational_fund_id'] ?? 0) === (int)$fund['id'] ? 'selected' : ''; ?>><?= f_e($fund['fund_code'].' · '.($fund['display_name']?:$fund['person_name'])); ?> · Saldo <?= rupiah($fund['current_balance']); ?></option><?php endforeach; ?></select><div class="form-text">Saldo PIC harus mencukupi gaji bersih. Pemakaian akan tercatat pada riwayat Dana Operasional.</div></div>
   <div class="col-12"><div class="form-check border rounded-4 p-3 ps-5 bg-light"><input class="form-check-input" type="checkbox" value="yes" name="payment_confirmed" id="paymentConfirmed" required><label class="form-check-label fw-semibold" for="paymentConfirmed">Saya sudah memastikan slip telah diperiksa dan pembayaran ini benar-benar siap dicatat. Setelah diproses, payroll akan menjadi Paid dan dikunci.</label></div></div>
   <div class="col-12 d-flex gap-2"><button class="btn btn-nukari" onclick="return confirm('Catat pembayaran payroll <?= f_e($payroll['full_name']); ?> sebesar <?= f_e(rupiah($payroll['net_salary'])); ?> dan kunci datanya?')"><i class="bi bi-check-circle me-1"></i> Bayar & Kunci Payroll</button><a href="<?= base_url('pages/payroll/detail.php?id='.$id); ?>" class="btn btn-light">Batal</a></div>
  </form>
 </div>
</main>
<script>
document.getElementById('fundingSource')?.addEventListener('change', function () {
    const useOperationalFund = this.value === 'operational_fund';
    document.getElementById('companySource')?.classList.toggle('d-none', useOperationalFund);
    document.getElementById('operationalFundSource')?.classList.toggle('d-none', !useOperationalFund);
});
function syncPostingMode(){const field=document.getElementById('postingMode');if(!field)return;const historical=field.value==='historical';document.getElementById('historicalReasonField').classList.toggle('d-none',!historical);document.querySelector('[name="historical_reason"]').required=historical;}
if(document.getElementById('postingMode')){document.getElementById('postingMode').addEventListener('change',syncPostingMode);syncPostingMode();}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
