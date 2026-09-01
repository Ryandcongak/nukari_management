<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';

$copies = max(1, min(20, (int)($_GET['copies'] ?? 1)));
$products = $pdo->query("SELECT * FROM products WHERE status='active' ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$typeMap = [
    'recycle' => 'Recycle Product',
    'eco_enzyme' => 'Eco Enzyme',
    'custom' => 'Custom Product',
    'service' => 'Service',
    'other' => 'Product'
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cetak Label QR Produk Nukari</title>
<script src="<?= base_url('assets/vendor/qrcodejs/qrcode.min.js'); ?>"></script>
<style>
*{box-sizing:border-box}
:root{--green:#12864f;--ink:#111827;--muted:#64748b;--line:#d7dee8;--soft:#f4f7f6}
body{font-family:Arial,Helvetica,sans-serif;background:var(--soft);margin:0;padding:22px;color:var(--ink)}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}.toolbar-title{font-weight:900;font-size:18px;line-height:1.2}.toolbar-sub{font-size:12px;color:var(--muted);margin-top:2px}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{border:1px solid #d1d5db;background:#fff;border-radius:999px;padding:10px 16px;font-weight:800;cursor:pointer;text-decoration:none;color:var(--ink)}.btn.green{background:var(--green);color:#fff;border-color:var(--green)}.input-copy{width:76px;border:1px solid #d1d5db;border-radius:999px;padding:10px 12px;font-weight:800;text-align:center;background:#fff}
.sheet{background:#fff;border-radius:20px;padding:22px;max-width:840px;margin:0 auto;box-shadow:0 20px 50px rgba(15,23,42,.08)}.labels{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.label{height:58mm;border:1.2px solid #0f172a;border-radius:12px;padding:9px 8px;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;break-inside:avoid;background:#fff;overflow:hidden}.brand{font-size:9px;text-transform:uppercase;font-weight:900;color:var(--green);letter-spacing:.08em;line-height:1}.name{font-weight:900;font-size:13px;line-height:1.12;margin-top:5px;max-width:100%;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.type{font-size:9.5px;color:var(--muted);font-weight:700;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}.qr{width:82px;height:82px;margin-top:7px;margin-bottom:6px}.qr img,.qr canvas{width:82px!important;height:82px!important}.code{font-family:Consolas,'Courier New',monospace;font-weight:900;font-size:10px;letter-spacing:.02em;word-break:break-all;line-height:1.15}.scan-note{font-size:8px;color:var(--muted);font-weight:700;margin-top:3px}.empty{padding:30px;text-align:center;color:var(--muted);font-weight:800}
@media print{body{background:#fff;padding:0}.toolbar{display:none}.sheet{box-shadow:none;border-radius:0;max-width:none;padding:0}.labels{gap:5mm;grid-template-columns:repeat(3,1fr)}.label{height:58mm;border:1px solid #000;border-radius:8px;page-break-inside:avoid}@page{size:A4 portrait;margin:8mm}}
@media(max-width:800px){.labels{grid-template-columns:repeat(2,1fr)}.sheet{padding:14px}.label{height:60mm}}
@media(max-width:520px){.labels{grid-template-columns:1fr}.label{height:62mm}}
</style>
</head>
<body>
<div class="toolbar">
  <div>
    <div class="toolbar-title">Cetak Label QR Produk</div>
    <div class="toolbar-sub"><?= count($products); ?> produk aktif · QR khusus POS/bazar · tanpa harga.</div>
  </div>
  <div class="actions">
    <form method="get" style="display:flex;gap:8px;align-items:center;margin:0">
      <input class="input-copy" type="number" min="1" max="20" name="copies" value="<?= (int)$copies; ?>" title="Jumlah label per produk">
      <button class="btn" type="submit">Terapkan</button>
    </form>
    <button class="btn green" onclick="window.print()">Print</button>
    <a class="btn" href="index.php">Kembali</a>
  </div>
</div>
<div class="sheet">
  <div class="labels">
    <?php foreach($products as $p):
      $scanCode = trim((string)($p['barcode_code'] ?? '')) ?: trim((string)($p['product_code'] ?? ''));
      if ($scanCode === '') { $scanCode = 'PRD-' . (int)$p['id']; }
      $labelCode = trim((string)($p['product_code'] ?? '')) ?: $scanCode;
      $typeLabel = $typeMap[$p['product_type'] ?? 'other'] ?? 'Product';
      for($i=0;$i<$copies;$i++):
    ?>
      <div class="label">
        <div class="brand">Nukari Product</div>
        <div class="name"><?= product_safe($p['product_name']); ?></div>
        <div class="type"><?= product_safe($typeLabel); ?><?= !empty($p['category']) ? ' • '.product_safe($p['category']) : ''; ?></div>
        <div class="qr" data-qr="<?= product_safe($scanCode); ?>"></div>
        <div class="code"><?= product_safe($labelCode); ?></div>
        <div class="scan-note">Scan untuk kasir</div>
      </div>
    <?php endfor; endforeach; ?>
    <?php if(!$products): ?><div class="empty">Tidak ada produk aktif.</div><?php endif; ?>
  </div>
</div>
<script>
document.querySelectorAll('.qr').forEach(el => {
  if (window.QRCode) {
    new QRCode(el, { text: el.dataset.qr || '', width: 82, height: 82, correctLevel: QRCode.CorrectLevel.M });
  } else {
    el.textContent = 'QR unavailable';
  }
});
</script>
</body>
</html>
