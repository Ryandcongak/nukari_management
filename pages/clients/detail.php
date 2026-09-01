<?php
require_once __DIR__ . '/../../includes/auth.php';
$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT * FROM clients WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$id]);$client=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$client) redirect('pages/clients/index.php');
$p=$pdo->prepare("SELECT id, project_code, project_name, status, contract_value FROM projects WHERE client_id=:id ORDER BY id DESC");$p->execute([':id'=>$id]);$projects=$p->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Detail Client - Nukari';
require_once __DIR__ . '/../../includes/header.php';require_once __DIR__ . '/../../includes/sidebar.php';
function nkr_wa_link($phone){$raw=preg_replace('/\D+/','',(string)$phone);if($raw==='')return '';if(strpos($raw,'0')===0)$raw='62'.substr($raw,1);if(strpos($raw,'62')!==0)$raw='62'.$raw;return 'https://wa.me/'.$raw;}
?>
<main class="main-content">
<div class="page-header d-flex justify-content-between align-items-center"><div><h1 class="fw-bold mb-1"><?= htmlspecialchars($client['client_name']); ?></h1><p class="text-muted mb-0">Detail client dan riwayat project.</p></div><a class="btn btn-outline-secondary" href="<?= base_url('pages/clients/index.php'); ?>">Kembali</a></div>
<div class="row g-3"><div class="col-lg-4"><div class="panel"><h5 class="fw-bold">Informasi Client</h5><p><strong>PIC:</strong><br><?= htmlspecialchars($client['contact_person'] ?: '-'); ?><br><small><?= htmlspecialchars($client['contact_position'] ?? ''); ?></small></p><p><strong>Kontak:</strong><br><?php if($client['phone']): ?><a target="_blank" href="<?= nkr_wa_link($client['phone']); ?>"><?= htmlspecialchars($client['phone']); ?></a><br><?php endif; ?><?php if($client['email']): ?><a href="mailto:<?= htmlspecialchars($client['email']); ?>"><?= htmlspecialchars($client['email']); ?></a><?php endif; ?></p><p><strong>Alamat:</strong><br><?= nl2br(htmlspecialchars($client['address'] ?: '-')); ?></p><p><strong>Catatan:</strong><br><?= nl2br(htmlspecialchars($client['notes'] ?: '-')); ?></p></div></div>
<div class="col-lg-8"><div class="panel"><h5 class="fw-bold">Riwayat Project</h5><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Kode</th><th>Project</th><th>Status</th><th>Nilai</th><th></th></tr></thead><tbody><?php foreach($projects as $pr): ?><tr><td><?= htmlspecialchars($pr['project_code'] ?: '-'); ?></td><td><?= htmlspecialchars($pr['project_name']); ?></td><td><?= htmlspecialchars($pr['status']); ?></td><td><?= rupiah($pr['contract_value']); ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= base_url('pages/projects/detail.php?id='.(int)$pr['id']); ?>">Buka</a></td></tr><?php endforeach; if(!$projects): ?><tr><td colspan="5" class="text-center text-muted">Belum ada project.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
</main><?php require_once __DIR__ . '/../../includes/footer.php'; ?>
