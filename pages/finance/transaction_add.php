<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../includes/master_data_helper.php';
require_once __DIR__ . '/../../includes/company_party_helper.php';

$pageTitle = 'Tambah Transaksi Manual - Nukari';
$error = '';

$accounts = $pdo->query("SELECT id, account_name, account_type,current_balance FROM finance_accounts WHERE status='active' ORDER BY is_default DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$operationalFunds=$pdo->query("SELECT id,fund_code,person_name,current_balance FROM operational_funds WHERE status='open' ORDER BY person_name")->fetchAll(PDO::FETCH_ASSOC);
$parties=party_active_list($pdo);
$categories = $pdo->query("SELECT id, category_name FROM transaction_categories WHERE status='active' ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, project_name FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'add_category') {
            $categoryName = trim($_POST['category_name'] ?? '');
            if ($categoryName === '') throw new Exception('Nama kategori wajib diisi.');
            $check = $pdo->prepare("SELECT id FROM transaction_categories WHERE LOWER(TRIM(category_name))=LOWER(TRIM(:name)) LIMIT 1");
            $check->execute([':name'=>$categoryName]);
            if ($check->fetch()) throw new Exception('Kategori tersebut sudah tersedia.');
            $pdo->prepare("INSERT INTO transaction_categories(category_name,type,status) VALUES(:name,'both','active')")->execute([':name'=>$categoryName]);
            redirect('pages/finance/transaction_add.php?type='.urlencode($_POST['current_transaction_type']??'expense').'&category_id='.(int)$pdo->lastInsertId());
        }
        $type = in_array($_POST['type'] ?? '', ['income','expense'], true) ? $_POST['type'] : 'expense';
        $transactionName = clean_input($_POST['transaction_name'] ?? '');
        $transactionDate = $_POST['transaction_date'] ?: date('Y-m-d');
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $fundingSource=$type==='expense'?($_POST['funding_source']??'company'):'company';
        $operationalFundId=(int)($_POST['operational_fund_id']??0);$fundingPartyId=(int)($_POST['funding_party_id']??0);$shortagePartyId=(int)($_POST['shortage_party_id']??0);
        if(!in_array($fundingSource,['company','operational_fund','party_advance'],true))$fundingSource='company';
        if($type==='expense'&&$fundingSource==='company'&&!$accountId)throw new Exception('Pilih akun kas/bank perusahaan.');
        if($type==='expense'&&$fundingSource==='operational_fund'&&!$operationalFundId)throw new Exception('Pilih Dana Operasional PIC.');
        if($type==='expense'&&$fundingSource==='party_advance'&&!$fundingPartyId)throw new Exception('Pilih Pihak/PIC penalang.');
        if($fundingSource!=='company')$accountId=null;
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $amount = f_money_to_number($_POST['amount'] ?? 0);
        $paymentMethod = in_array($_POST['payment_method'] ?? '', ['cash','bank_transfer','qris','other'], true) ? $_POST['payment_method'] : 'cash';
        $description = clean_input($_POST['description'] ?? '');
        $referenceCode = clean_input($_POST['reference_code'] ?? '');
        $proofFile = f_upload_file('proof_file');

        if ($transactionName === '') {
            throw new Exception('Nama transaksi wajib diisi.');
        } elseif ($amount <= 0) {
            throw new Exception('Nominal transaksi harus lebih dari 0.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO transactions
            (transaction_code, transaction_date, transaction_name, type, category_id, account_id, project_id, amount, description, payment_method, proof_file, source_module, source_table, source_id, reference_code, created_by, created_at, updated_at)
            VALUES
            (:code, :date, :transaction_name, :type, :category_id, :account_id, :project_id, :amount, :description, :payment_method, :proof_file, 'manual', NULL, NULL, :reference_code, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            ':code' => f_transaction_code($pdo, $type),
            ':date' => $transactionDate,
            ':transaction_name' => $transactionName,
            ':type' => $type,
            ':category_id' => $categoryId,
            ':account_id' => $accountId,
            ':project_id' => $projectId,
            ':amount' => $amount,
            ':description' => $description ?: null,
            ':payment_method' => $paymentMethod,
            ':proof_file' => $proofFile,
            ':reference_code' => $referenceCode ?: null,
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
        $transactionId=(int)$pdo->lastInsertId();

        if($type==='expense'&&$fundingSource==='operational_fund'){
            opfund_add_movement($pdo,$operationalFundId,'expense',$amount,$transactionDate,null,$transactionName.($description?' - '.$description:''),'finance','transactions',$transactionId,$projectId,$paymentMethod,$_SESSION['user_id']??null);
        }
        if($type==='expense'&&$fundingSource==='party_advance'){
            $partyStmt=$pdo->prepare("SELECT display_name FROM company_parties WHERE id=:id AND status='active'");$partyStmt->execute([':id'=>$fundingPartyId]);$partyName=$partyStmt->fetchColumn();if(!$partyName)throw new Exception('Pihak/PIC penalang tidak ditemukan.');
            $debtStmt=$pdo->prepare("INSERT INTO debts(debt_code,type,related_type,related_id,related_name,category,issue_date,amount,principal_amount,paid_amount,payment_method,source_module,source_table,source_id,reference_code,status,description,created_by,created_at,updated_at) VALUES(:code,'debt','personal',:party,:name,'Talangan Operasional',:date,:amount,:principal,0,:method,'other','transactions',:source_id,:reference,'unpaid',:description,:user,NOW(),NOW())");$debtStmt->execute([':code'=>finance_generate_debt_code($pdo,'debt'),':party'=>$fundingPartyId,':name'=>$partyName,':date'=>$transactionDate,':amount'=>$amount,':principal'=>$amount,':method'=>$paymentMethod,':source_id'=>$transactionId,':reference'=>$referenceCode?:'TRX-'.$transactionId,':description'=>$transactionName.($description?' - '.$description:''),':user'=>$_SESSION['user_id']??null]);
        }
        if($type==='expense'&&$fundingSource==='company'&&$accountId){
            $balance=0;foreach($accounts as $account){if((int)$account['id']===$accountId){$balance=(float)$account['current_balance'];break;}}$shortfall=max(0,$amount-max(0,$balance));
            if($shortfall>0){if(!$shortagePartyId)throw new Exception('Saldo akun tidak cukup. Pilih Pihak/PIC yang menalangi kekurangan '.rupiah($shortfall).'.');$partyStmt=$pdo->prepare("SELECT display_name FROM company_parties WHERE id=:id AND status='active'");$partyStmt->execute([':id'=>$shortagePartyId]);$partyName=$partyStmt->fetchColumn();if(!$partyName)throw new Exception('Pihak/PIC penalang tidak ditemukan.');$debtStmt=$pdo->prepare("INSERT INTO debts(debt_code,type,related_type,related_id,related_name,category,issue_date,amount,principal_amount,paid_amount,payment_method,source_module,source_table,source_id,reference_code,status,description,created_by,created_at,updated_at) VALUES(:code,'debt','personal',:party,:name,'Kekurangan Kas Kantor',:date,:amount,:principal,0,:method,'other','transactions',:source_id,:reference,'unpaid',:description,:user,NOW(),NOW())");$debtStmt->execute([':code'=>finance_generate_debt_code($pdo,'debt'),':party'=>$shortagePartyId,':name'=>$partyName,':date'=>$transactionDate,':amount'=>$shortfall,':principal'=>$shortfall,':method'=>$paymentMethod,':source_id'=>$transactionId,':reference'=>$referenceCode?:'TRX-'.$transactionId,':description'=>'Kekurangan saldo untuk '.$transactionName,':user'=>$_SESSION['user_id']??null]);}
        }

        if ($accountId) {
            f_recalculate_account($pdo, $accountId);
        }

        $pdo->commit();

        redirect('pages/finance/transactions.php?message=Transaksi berhasil ditambahkan');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Tambah Transaksi Manual</h1>
            <p class="text-muted mb-0">Gunakan untuk transaksi yang tidak berasal dari modul otomatis.</p>
        </div>
        <a href="<?= base_url('pages/finance/transactions.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= f_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Jenis</label>
                <select name="type" id="transactionType" class="form-select" required>
                    <option value="income" <?=($_GET['type']??'')==='income'?'selected':'';?>>Income / Pemasukan</option>
                    <option value="expense" <?=($_GET['type']??'')!=='income'?'selected':'';?>>Expense / Pengeluaran</option>
                </select>
            </div>
            <div class="col-md-6"><label class="form-label fw-semibold">Nama Transaksi</label><input type="text" name="transaction_name" class="form-control" required placeholder="Contoh: Pembayaran Iuran RT/RW September"></div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Tanggal</label>
                <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Nominal</label>
                <input type="text" name="amount" id="transactionAmount" class="form-control money-input" required placeholder="Contoh: 1.500.000">
            </div>

            <div class="col-md-6 expense-funding"><label class="form-label fw-semibold">Sumber Pengeluaran</label><select name="funding_source" id="fundingSource" class="form-select"><option value="company" <?=($_POST['funding_source']??'company')==='company'?'selected':'';?>>Kas/Bank Perusahaan</option><option value="operational_fund" <?=($_POST['funding_source']??'')==='operational_fund'?'selected':'';?>>Dana Operasional PIC</option><option value="party_advance" <?=($_POST['funding_source']??'')==='party_advance'?'selected':'';?>>Talangan Pihak/PIC</option></select></div>
            <div class="col-md-6" id="companyAccountField">
                <label class="form-label fw-semibold">Akun Kas/Bank</label>
                <select name="account_id" id="companyAccountSelect" class="form-select">
                    <option value="">Pilih akun Kas/Bank</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id']; ?>" data-balance="<?= (float)$a['current_balance']; ?>" <?= (int)($_POST['account_id']??0)===(int)$a['id']?'selected':''; ?>><?= f_e($a['account_name']); ?> · <?= f_e(ucfirst($a['account_type'])); ?> · Saldo <?= rupiah($a['current_balance']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Wajib dipilih untuk pemasukan atau pengeluaran dari dana perusahaan.</div>
            </div>
            <div class="col-md-6 funding-extra d-none" data-funding="operational_fund"><label class="form-label">Dana Operasional PIC</label><select name="operational_fund_id" class="form-select"><option value="">Pilih dana</option><?php foreach($operationalFunds as $f):?><option value="<?=$f['id'];?>"><?=f_e($f['person_name']);?> · <?=rupiah($f['current_balance']);?></option><?php endforeach;?></select></div>
            <div class="col-md-6 funding-extra d-none" data-funding="party_advance"><label class="form-label">Pihak/PIC Penalang</label><select name="funding_party_id" class="form-select"><option value="">Pilih pihak</option><?php foreach($parties as $p):?><option value="<?=$p['id'];?>"><?=f_e($p['display_name']);?></option><?php endforeach;?></select></div>
            <div class="col-md-6 d-none" id="companyShortageField"><label class="form-label">PIC Penalang Kekurangan</label><select name="shortage_party_id" id="companyShortageSelect" class="form-select"><option value="">Pilih Pihak/PIC</option><?php foreach($parties as $p):?><option value="<?=$p['id'];?>"><?=f_e($p['display_name']);?></option><?php endforeach;?></select><div class="form-text text-warning">Muncul karena nominal melebihi saldo akun perusahaan.</div></div>
            <div class="col-md-4">
                <div class="d-flex justify-content-between align-items-center"><label class="form-label fw-semibold">Kategori</label><div><button type="button" class="btn btn-sm btn-link text-decoration-none" data-bs-toggle="modal" data-bs-target="#quickCategoryModal"><i class="bi bi-plus-circle"></i> Tambah</button><a class="btn btn-sm btn-link text-decoration-none" href="<?=base_url('pages/master/categories.php');?>">Kelola</a></div></div>
                <select name="category_id" id="categorySelect" class="form-select">
                    <option value="">Tanpa kategori</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id']; ?>" <?=(int)($_GET['category_id']??0)===(int)$c['id']?'selected':'';?>><?= f_e($c['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Metode Pembayaran</label>
                <select name="payment_method" class="form-select">
                    <?= master_options($pdo,'payment_method','cash'); ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Project</label>
                <select name="project_id" class="form-select">
                    <option value="">Tidak terkait project</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id']; ?>"><?= f_e($p['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Kode Referensi</label>
                <input type="text" name="reference_code" class="form-control" placeholder="No nota, invoice, referensi bank, dll">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Bukti</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="description" rows="4" class="form-control" placeholder="Keterangan transaksi..."></textarea>
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Transaksi</button>
            </div>
        </form>
    </div>
</main>

<div class="modal fade" id="quickCategoryModal"><div class="modal-dialog modal-dialog-centered"><form method="POST" class="modal-content rounded-4 border-0"><?=csrf_field();?><input type="hidden" name="action" value="add_category"><input type="hidden" name="current_transaction_type" id="quickTransactionType"><div class="modal-header"><h5 class="modal-title fw-bold">Tambah Kategori Cepat</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Nama Kategori</label><input name="category_name" class="form-control" required placeholder="Contoh: RT/RW"><div class="form-text">Kategori dapat digunakan untuk pemasukan maupun pengeluaran.</div></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Batal</button><button class="btn btn-nukari">Tambah & Pilih</button></div></form></div></div>

<script>
document.querySelectorAll('.money-input').forEach(function(input) {
    input.addEventListener('input', function() {
        let value = this.value.replace(/\D/g, '');
        this.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    });
});
document.getElementById('quickCategoryModal')?.addEventListener('show.bs.modal',()=>{document.getElementById('quickTransactionType').value=document.getElementById('transactionType').value;});
function syncFunding(){
    const expense=document.getElementById('transactionType').value==='expense';
    const value=document.getElementById('fundingSource').value;
    const useCompany=!expense||value==='company';
    document.querySelectorAll('.expense-funding').forEach(e=>e.classList.toggle('d-none',!expense));
    document.querySelectorAll('.funding-extra').forEach(e=>{
        const active=expense&&e.dataset.funding===value;
        e.classList.toggle('d-none',!active);
        e.querySelectorAll('select').forEach(input=>{input.disabled=!active;input.required=active&&e.dataset.funding!=='company';});
    });
    const accountField=document.getElementById('companyAccountField');
    const accountSelect=document.getElementById('companyAccountSelect');
    accountField?.classList.toggle('d-none',!useCompany);
    if(accountSelect){accountSelect.disabled=!useCompany;accountSelect.required=useCompany;}
    syncCompanyShortage();
}
function syncCompanyShortage(){
    const expense=document.getElementById('transactionType').value==='expense';
    const company=document.getElementById('fundingSource').value==='company';
    const account=document.getElementById('companyAccountSelect');
    const amount=parseFloat((document.getElementById('transactionAmount').value||'').replace(/[^0-9]/g,''))||0;
    const balance=parseFloat(account?.selectedOptions?.[0]?.dataset.balance||'0')||0;
    const insufficient=expense&&company&&account?.value&&amount>balance;
    const field=document.getElementById('companyShortageField');
    const select=document.getElementById('companyShortageSelect');
    field?.classList.toggle('d-none',!insufficient);
    if(select){select.disabled=!insufficient;select.required=!!insufficient;}
}
document.getElementById('transactionType')?.addEventListener('change',syncFunding);document.getElementById('fundingSource')?.addEventListener('change',syncFunding);syncFunding();
document.getElementById('companyAccountSelect')?.addEventListener('change',syncCompanyShortage);
document.getElementById('transactionAmount')?.addEventListener('input',syncCompanyShortage);
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
