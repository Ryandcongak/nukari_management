<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT p.*, c.client_name, e.full_name leader_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN employees e ON e.id=p.leader_id WHERE p.id=:id AND p.deleted_at IS NULL LIMIT 1");
$stmt->execute([':id' => $id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) redirect('pages/projects/index.php');

$termStmt = $pdo->prepare("SELECT * FROM project_payment_terms WHERE project_id=:id ORDER BY due_date,id");
$termStmt->execute([':id' => $id]);
$terms = $termStmt->fetchAll(PDO::FETCH_ASSOC);
$trxStmt = $pdo->prepare("SELECT category,transaction_type,SUM(amount) amount FROM project_transactions WHERE project_id=:id GROUP BY category,transaction_type ORDER BY transaction_type,amount DESC");
$trxStmt->execute([':id' => $id]);
$transactions = $trxStmt->fetchAll(PDO::FETCH_ASSOC);

$contract = (float)$project['contract_value'];
$termGross = $termTax = $termNet = $paidGross = $paidNet = 0;
foreach ($terms as $term) {
    $gross = (float)$term['amount'];
    $tax = (float)($term['tax_amount'] ?? 0);
    $net = (float)($term['received_amount'] ?? 0) ?: max(0, $gross - $tax);
    $termGross += $gross; $termTax += $tax; $termNet += $net;
    if ($term['status'] === 'paid') { $paidGross += $gross; $paidNet += $net; }
}
$income = $expense = 0;
foreach ($transactions as $trx) {
    if ($trx['transaction_type'] === 'income') $income += (float)$trx['amount'];
    if ($trx['transaction_type'] === 'expense') $expense += (float)$trx['amount'];
}
$profit = $income - $expense;
$pct = static fn($value, $base) => $base > 0 ? min(100, round($value / $base * 100, 1)) : 0;
$money = static fn($value) => 'Rp ' . number_format((float)$value, 0, ',', '.');
$pageTitle = 'Laporan Project - ' . $project['project_name'];
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle); ?></title><link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
<style>
body{background:#eef2f7;color:#172033;font-size:13px}.report{max-width:1100px;margin:24px auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 12px 35px rgba(15,23,42,.08)}.hero{padding:22px;border-radius:15px;background:linear-gradient(135deg,#14532d,#15803d);color:#fff}.metric{border:1px solid #e2e8f0;border-radius:13px;padding:14px;height:100%}.metric span{display:block;color:#64748b;font-size:12px}.metric strong{font-size:18px}.bar{height:8px;background:#e2e8f0;border-radius:999px;overflow:hidden}.bar i{display:block;height:100%;background:#16a34a}.section-title{font-weight:800;margin:22px 0 10px}.table th{background:#f1f5f9;font-size:12px}.actions{max-width:1100px;margin:18px auto;text-align:right}@media print{@page{size:A4 landscape;margin:8mm}body{background:#fff}.actions{display:none}.report{max-width:none;margin:0;padding:0;box-shadow:none}.hero,.metric,table{break-inside:avoid}}
</style></head><body>
<div class="actions"><button class="btn btn-success btn-sm" onclick="window.print()">Cetak / Simpan PDF</button> <a class="btn btn-outline-secondary btn-sm" href="<?= base_url('pages/projects/detail.php?id='.$id.'&tab=overview'); ?>">Kembali</a></div>
<main class="report">
<section class="hero d-flex justify-content-between gap-3"><div><h2 class="fw-bold mb-1">Laporan Project</h2><div><?= htmlspecialchars($project['project_name']); ?> · <?= htmlspecialchars($project['project_code'] ?: '-'); ?></div></div><div class="text-end"><strong><?= htmlspecialchars($project['client_name'] ?: '-'); ?></strong><br><?= htmlspecialchars($project['leader_name'] ?: '-'); ?><br><?= date('d/m/Y'); ?></div></section>
<div class="row g-3 mt-1">
<?php foreach ([['Nilai Kontrak',$contract],['Tagihan Sebelum Pajak',$termGross],['Potongan Pajak',$termTax],['Dana Bersih Diterima',$paidNet],['Total Biaya Project',$expense],['Keuntungan Aktual',$profit]] as $metric): ?>
<div class="col-md-4 col-xl-2"><div class="metric"><span><?= $metric[0]; ?></span><strong><?= $money($metric[1]); ?></strong></div></div><?php endforeach; ?>
</div>
<h5 class="section-title">Persentase Project</h5>
<?php foreach ([['Tagihan Terjadwal',$pct($termGross,$contract)],['Tagihan Sudah Dibayar',$pct($paidGross,$contract)],['Dana Bersih Diterima',$pct($paidNet,$contract)],['Biaya terhadap Kontrak',$pct($expense,$contract)]] as $row): ?><div class="mb-3"><div class="d-flex justify-content-between"><span><?= $row[0]; ?></span><strong><?= number_format($row[1],1,',','.'); ?>%</strong></div><div class="bar mt-1"><i style="width:<?= $row[1]; ?>%"></i></div></div><?php endforeach; ?>
<h5 class="section-title">Rincian Tagihan</h5><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Termin</th><th>Persentase</th><th>Sebelum Pajak</th><th>Potongan Pajak</th><th>Dana Bersih</th><th>Jatuh Tempo</th><th>Status</th></tr></thead><tbody>
<?php foreach($terms as $term): $net=(float)($term['received_amount']??0) ?: max(0,(float)$term['amount']-(float)($term['tax_amount']??0)); ?><tr><td><?= htmlspecialchars($term['term_name']); ?></td><td><?= number_format((float)$term['percentage'],1,',','.'); ?>%</td><td><?= $money($term['amount']); ?></td><td><?= $money($term['tax_amount']??0); ?></td><td><?= $money($net); ?></td><td><?= date('d/m/Y',strtotime($term['due_date'])); ?></td><td><?= htmlspecialchars($term['status']); ?></td></tr><?php endforeach; ?>
<?php if(!$terms): ?><tr><td colspan="7" class="text-center text-muted">Belum ada termin.</td></tr><?php endif; ?></tbody></table></div>
<h5 class="section-title">Rekap Transaksi</h5><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Jenis</th><th>Kategori</th><th class="text-end">Nominal</th><th class="text-end">% Kontrak</th></tr></thead><tbody><?php foreach($transactions as $trx): ?><tr><td><?= $trx['transaction_type']==='income'?'Pemasukan':'Pengeluaran'; ?></td><td><?= htmlspecialchars($trx['category']); ?></td><td class="text-end"><?= $money($trx['amount']); ?></td><td class="text-end"><?= number_format($pct($trx['amount'],$contract),1,',','.'); ?>%</td></tr><?php endforeach; ?></tbody></table></div>
</main></body></html>
