<?php
require_once __DIR__ . '/../../includes/auth.php';
$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT * FROM suppliers WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$id]);$supplier=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$supplier) redirect('pages/suppliers/index.php');
$pageTitle='Detail Vendor - Nukari';
require_once __DIR__ . '/../../includes/header.php';require_once __DIR__ . '/../../includes/sidebar.php';
function nkr_wa_link($phone){$raw=preg_replace('/\D+/','',(string)$phone);if($raw==='')return '';if(strpos($raw,'0')===0)$raw='62'.substr($raw,1);if(strpos($raw,'62')!==0)$raw='62'.$raw;return 'https://wa.me/'.$raw;}
?>
<main class="main-content"><div class="page-header d-flex justify-content-between align-items-center"><div><h1 class="fw-bold mb-1"><?= htmlspecialchars($supplier['supplier_name']); ?></h1><p class="text-muted mb-0">Detail vendor/supplier.</p></div><a class="btn btn-outline-secondary" href="<?= base_url('pages/suppliers/index.php'); ?>">Kembali</a></div>
<div class="panel"><div class="row g-3"><div class="col-md-6"><strong>Kategori</strong><br><?= htmlspecialchars($supplier['supplier_category'] ?? '-'); ?></div><div class="col-md-6"><strong>PIC</strong><br><?= htmlspecialchars($supplier['contact_person'] ?: '-'); ?><br><small><?= htmlspecialchars($supplier['contact_position'] ?? ''); ?></small></div><div class="col-md-6"><strong>WhatsApp</strong><br><?php if($supplier['phone']): ?><a target="_blank" href="<?= nkr_wa_link($supplier['phone']); ?>"><?= htmlspecialchars($supplier['phone']); ?></a><?php else: ?>-<?php endif; ?></div><div class="col-md-6"><strong>Email</strong><br><?php if($supplier['email']): ?><a href="mailto:<?= htmlspecialchars($supplier['email']); ?>"><?= htmlspecialchars($supplier['email']); ?></a><?php else: ?>-<?php endif; ?></div><div class="col-12"><strong>Alamat</strong><br><?= nl2br(htmlspecialchars($supplier['address'] ?: '-')); ?></div><div class="col-12"><strong>Catatan</strong><br><?= nl2br(htmlspecialchars($supplier['notes'] ?: '-')); ?></div></div></div></main><?php require_once __DIR__ . '/../../includes/footer.php'; ?>
