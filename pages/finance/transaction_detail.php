<?php
require_once __DIR__ . '/helpers.php';
$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT t.*,c.category_name,a.account_name,p.project_name,u.username FROM transactions t LEFT JOIN transaction_categories c ON c.id=t.category_id LEFT JOIN finance_accounts a ON a.id=t.account_id LEFT JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.created_by WHERE t.id=:id");
$stmt->execute([':id'=>$id]); $t=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$t) redirect('pages/finance/transactions.php?error=Transaksi tidak ditemukan');
$pageTitle='Detail Transaksi - Nukari'; require_once __DIR__.'/../../includes/header.php'; require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="main-content"><div class="page-header d-flex justify-content-between"><div><h1 class="fw-bold mb-1"><?=f_e($t['transaction_name'] ?: 'Detail Transaksi');?></h1><p class="text-muted mb-0"><?= f_e($t['transaction_code']); ?></p></div><a class="btn btn-outline-success" href="<?= base_url('pages/finance/transactions.php'); ?>">Kembali</a></div>
<div class="row g-4"><div class="col-lg-7"><div class="panel"><div class="row g-3">
<?php foreach(['Tanggal'=>date('d M Y',strtotime($t['transaction_date'])),'Jenis'=>strtoupper($t['type']),'Nominal'=>rupiah($t['amount']),'Dampak Saldo'=>finance_posting_mode_label($t['posting_mode']??'posted'),'Akun'=>$t['account_name']?:'-','Kategori'=>$t['category_name']?:'-','Project'=>$t['project_name']?:'Tidak terkait project','Metode'=>ucwords(str_replace('_',' ',$t['payment_method'])),'Sumber'=>f_source_label($t['source_module']),'Referensi'=>$t['reference_code']?:'-','Dicatat oleh'=>$t['username']?:'-'] as $label=>$value): ?><div class="col-md-6"><div class="text-muted small"><?= f_e($label); ?></div><div class="fw-semibold"><?= f_e($value); ?></div></div><?php endforeach; ?>
<?php if(($t['posting_mode']??'posted')==='historical'): ?><div class="col-12"><div class="text-muted small">Alasan Historis</div><div class="fw-semibold"><?= f_e($t['historical_reason']?:'-'); ?></div></div><?php endif; ?>
<div class="col-12"><div class="text-muted small">Deskripsi</div><div><?= nl2br(f_e($t['description']?:'-')); ?></div></div></div></div></div>
<div class="col-lg-5"><div class="panel"><h5 class="fw-bold">Bukti Transaksi</h5><?php if($t['proof_file']): $url=base_url($t['proof_file']); $ext=strtolower(pathinfo($t['proof_file'],PATHINFO_EXTENSION)); ?><?php if(in_array($ext,['jpg','jpeg','png','webp'])): ?><a href="<?= f_e($url); ?>" target="_blank"><img src="<?= f_e($url); ?>" class="img-fluid rounded-4 border" alt="Bukti transaksi"></a><?php else: ?><a href="<?= f_e($url); ?>" target="_blank" class="btn btn-outline-success"><i class="bi bi-file-earmark-pdf"></i> Buka Dokumen</a><?php endif; ?><?php else: ?><div class="text-muted py-4 text-center">Tidak ada bukti foto/dokumen.</div><?php endif; ?></div></div></div></main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
