<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Slip Kasir Thermal - Nukari';

if (!function_exists('receipt_safe')) {
    function receipt_safe($value) { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('receipt_money')) {
    function receipt_money($value) {
        return 'Rp ' . number_format((float)$value, 0, ',', '.');
    }
}
if (!function_exists('receipt_num')) {
    function receipt_num($value) {
        $n = (float)$value;
        return abs($n - round($n)) < 0.00001 ? number_format($n, 0, ',', '.') : rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
    }
}
if (!function_exists('receipt_date')) {
    function receipt_date($value) {
        if (!$value) return '-';
        $ts = strtotime($value);
        return $ts ? date('d/m/Y H:i', $ts) : (string)$value;
    }
}
if (!function_exists('receipt_date_only')) {
    function receipt_date_only($value) {
        if (!$value) return '-';
        $ts = strtotime($value);
        return $ts ? date('d/m/Y', $ts) : (string)$value;
    }
}
if (!function_exists('short_text')) {
    function short_text($text, $max = 28) {
        $text = trim((string)$text);
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) return mb_substr($text, 0, $max - 1) . '…';
        if (strlen($text) > $max) return substr($text, 0, $max - 1) . '…';
        return $text;
    }
}

$type = $_GET['type'] ?? 'sale';
$id = (int)($_GET['id'] ?? 0);
$paper = $_GET['paper'] ?? '80'; // 80 atau 58 mm
$paper = in_array($paper, ['58','80'], true) ? $paper : '80';

if ($id <= 0) { die('Data tidak valid.'); }

$sale = null; $items = []; $custom = null;

if ($type === 'custom') {
    $stmt = $pdo->prepare("SELECT * FROM product_custom_orders WHERE id=?");
    $stmt->execute([$id]);
    $custom = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$custom) die('Custom order tidak ditemukan.');
} else {
    $stmt = $pdo->prepare("SELECT * FROM product_cashier_sales WHERE id=?");
    $stmt->execute([$id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) die('Transaksi tidak ditemukan.');
    $stmt = $pdo->prepare("SELECT * FROM product_cashier_sale_items WHERE sale_id=? ORDER BY id ASC");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= receipt_safe($pageTitle); ?></title>
<style>
:root{
    --receipt-width: <?= $paper === '58' ? '58mm' : '80mm'; ?>;
    --receipt-font: <?= $paper === '58' ? '10px' : '11px'; ?>;
    --page-bg:#eef2f7;
    --brand:#0f7f4f;
}
*{box-sizing:border-box}
html,body{
    margin:0;
    padding:0;
    min-height:100%;
    background:var(--page-bg);
    color:#111827;
}
body{font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue",Arial,sans-serif}
.receipt-page{
    width:100%;
    min-height:100vh;
    padding:22px 14px 34px;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:14px;
}
.receipt-toolbar{
    width:min(720px,96vw);
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:22px;
    padding:12px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    box-shadow:0 14px 32px rgba(15,23,42,.08);
}
.toolbar-title{display:flex;flex-direction:column;gap:2px}
.toolbar-title strong{font-size:15px;color:#0f172a}
.toolbar-title span{font-size:12px;color:#64748b}
.toolbar-actions{
    display:flex;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:8px;
}
.btn-print,.btn-paper,.btn-back{
    border-radius:999px;
    padding:9px 14px;
    border:1px solid #cbd5e1;
    font-weight:700;
    font-size:13px;
    line-height:1;
    cursor:pointer;
    text-decoration:none;
    color:#0f172a;
    background:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:34px;
}
.btn-print{background:var(--brand);color:white;border-color:var(--brand)}
.btn-paper.active{background:#facc15;border-color:#facc15;color:#111827}
.btn-back:hover,.btn-paper:hover{background:#f8fafc}
.receipt-shell{
    width:min(720px,96vw);
    display:flex;
    justify-content:center;
}
.thermal-wrap{
    width:var(--receipt-width);
    max-width:100%;
    background:#fff;
    color:#111827;
    font-family:"Courier New", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size:var(--receipt-font);
    line-height:1.28;
    padding:10px;
    border:1px solid #e2e8f0;
    border-radius:10px;
    box-shadow:0 16px 35px rgba(15,23,42,.12);
    overflow:hidden;
}
.receipt-center{text-align:center}
.receipt-title{font-size:18px;font-weight:900;letter-spacing:.5px}
.receipt-subtitle{font-size:11px}
.line{border-top:1px dashed #111;margin:8px 0}
.row{display:flex;justify-content:space-between;gap:8px;margin:2px 0}
.row .l{flex:1;min-width:0}
.row .r{text-align:right;white-space:normal;word-break:break-word;max-width:58%}
.item{margin:7px 0}
.item-name{font-weight:700;word-break:break-word}
.item-meta{display:flex;justify-content:space-between;gap:6px}
.item-meta span:first-child{min-width:0}
.item-meta strong,.item-meta span:last-child{text-align:right}
.total-box{margin-top:6px}
.grand{font-size:14px;font-weight:900}
.note{
    border:1px dashed #111;
    padding:6px;
    margin-top:8px;
    white-space:pre-line;
    word-break:break-word;
}
.small{font-size:10px}
.muted{color:#374151}
.screen-hint{
    width:min(720px,96vw);
    color:#64748b;
    font-size:12px;
    text-align:center;
}
@page{
    size: <?= $paper === '58' ? '58mm auto' : '80mm auto'; ?>;
    margin:0;
}
@media (max-width:640px){
    .receipt-toolbar{align-items:flex-start;flex-direction:column;border-radius:18px}
    .toolbar-actions{justify-content:flex-start}
    .receipt-page{padding:12px 8px 26px}
}
@media print{
    html,body{
        width:<?= $paper === '58' ? '58mm' : '80mm'; ?>!important;
        margin:0!important;
        padding:0!important;
        background:#fff!important;
    }
    body *{visibility:hidden!important}
    .thermal-wrap,.thermal-wrap *{visibility:visible!important}
    .receipt-page,.receipt-shell{
        display:block!important;
        width:<?= $paper === '58' ? '58mm' : '80mm'; ?>!important;
        min-height:auto!important;
        padding:0!important;
        margin:0!important;
    }
    .thermal-wrap{
        position:absolute!important;
        left:0!important;
        top:0!important;
        margin:0!important;
        padding:2mm 2.5mm!important;
        box-shadow:none!important;
        border:none!important;
        border-radius:0!important;
        width:<?= $paper === '58' ? '58mm' : '80mm'; ?>!important;
        max-width:none!important;
        font-size:<?= $paper === '58' ? '9.5px' : '10.8px'; ?>!important;
    }
    .receipt-toolbar,.receipt-actions,.screen-hint{
        display:none!important;
        visibility:hidden!important;
    }
}
</style>
</head>
<body>
<div class="receipt-page">
    <div class="receipt-toolbar">
        <div class="toolbar-title">
            <strong>Slip Kasir Thermal</strong>
            <span>PT Nukari Kriya Raya • Pilih ukuran lalu print</span>
        </div>
        <div class="toolbar-actions">
            <button class="btn-print" onclick="window.print()">Print Thermal</button>
            <a class="btn-paper <?= $paper === '80' ? 'active' : ''; ?>" href="?type=<?= receipt_safe($type); ?>&id=<?= (int)$id; ?>&paper=80">80mm</a>
            <a class="btn-paper <?= $paper === '58' ? 'active' : ''; ?>" href="?type=<?= receipt_safe($type); ?>&id=<?= (int)$id; ?>&paper=58">58mm</a>
            <a class="btn-back" href="cashier.php">Kembali</a>
        </div>
    </div>
    <div class="receipt-shell">
<div class="thermal-wrap id="thermalReceipt">
    <div class="receipt-center">
        <div class="receipt-title">ANGEN BALI</div>
        <div>PT Nukari Kriya Raya</div>
        <div class="receipt-subtitle">Produk Recycle • Eco Enzyme • Pupuk • Produk Nukari</div>
        <div class="receipt-subtitle">Custom Order • Program Lingkungan</div>
    </div>

    <div class="line"></div>

    <?php if ($sale): ?>
        <div class="row"><span class="l">No. Struk</span><span class="r"><?= receipt_safe($sale['receipt_code']); ?></span></div>
        <div class="row"><span class="l">Tanggal</span><span class="r"><?= receipt_safe(receipt_date($sale['sale_date'])); ?></span></div>
        <div class="row"><span class="l">Customer</span><span class="r"><?= receipt_safe(short_text($sale['customer_name'] ?: 'Umum', 22)); ?></span></div>
        <?php if (!empty($sale['customer_phone'])): ?>
            <div class="row"><span class="l">WA</span><span class="r"><?= receipt_safe($sale['customer_phone']); ?></span></div>
        <?php endif; ?>
        <?php if (!empty($sale['customer_email'])): ?>
            <div class="row"><span class="l">Email</span><span class="r"><?= receipt_safe(short_text($sale['customer_email'], 24)); ?></span></div>
        <?php endif; ?>
        <div class="row"><span class="l">Channel</span><span class="r"><?= receipt_safe(ucfirst($sale['channel'])); ?></span></div>
        <div class="row"><span class="l">Bayar</span><span class="r"><?= receipt_safe(strtoupper(str_replace('_',' ', $sale['payment_method']))); ?></span></div>

        <div class="line"></div>

        <?php foreach ($items as $it): ?>
            <div class="item">
                <div class="item-name"><?= receipt_safe($it['product_name']); ?></div>
                <?php if (!empty($it['barcode_code'])): ?>
                    <div class="small muted"><?= receipt_safe($it['barcode_code']); ?></div>
                <?php endif; ?>
                <div class="item-meta">
                    <span><?= receipt_num($it['qty']); ?> x <?= receipt_money($it['unit_price']); ?></span>
                    <strong><?= receipt_money($it['gross_amount']); ?></strong>
                </div>
                <?php if ((float)$it['discount_amount'] > 0): ?>
                    <div class="item-meta small"><span>Diskon item</span><span>-<?= receipt_money($it['discount_amount']); ?></span></div>
                <?php endif; ?>
                <?php if ((float)$it['contribution_amount'] > 0): ?>
                    <div class="item-meta small"><span>Kontribusi</span><span><?= receipt_money($it['contribution_amount']); ?></span></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="line"></div>

        <div class="total-box">
            <div class="row"><span class="l">Subtotal</span><span class="r"><?= receipt_money($sale['subtotal']); ?></span></div>
            <?php if ((float)$sale['discount_amount'] > 0): ?>
                <div class="row"><span class="l">Diskon</span><span class="r">-<?= receipt_money($sale['discount_amount']); ?></span></div>
            <?php else: ?>
                <div class="row"><span class="l">Diskon</span><span class="r"><?= receipt_money(0); ?></span></div>
            <?php endif; ?>
            <?php if ((float)$sale['contribution_amount'] > 0): ?>
                <div class="row"><span class="l">Kontribusi Program</span><span class="r"><?= receipt_money($sale['contribution_amount']); ?></span></div>
            <?php endif; ?>
            <div class="line"></div>
            <div class="row grand"><span class="l">TOTAL BAYAR</span><span class="r"><?= receipt_money($sale['total_amount']); ?></span></div>
        </div>

        <?php if ((float)$sale['contribution_amount'] > 0): ?>
            <div class="note">
Dari pembelian ini, <?= receipt_money($sale['contribution_amount']); ?> dialokasikan untuk program sosial/lingkungan Nukari.
Informasi kegiatan dapat dikirim melalui WhatsApp/email jika data customer tersedia.
            </div>
        <?php endif; ?>

        <?php if (!empty($sale['notes'])): ?>
            <div class="note"><?= nl2br(receipt_safe($sale['notes'])); ?></div>
        <?php endif; ?>

        <div class="line"></div>
        <div class="receipt-center small">
            Terima kasih telah mendukung<br>
            produk daur ulang Nukari.<br>
            Simpan slip ini sebagai bukti pembelian.
        </div>

    <?php else: ?>
        <?php
            $paid = (float)$custom['down_payment'] + (float)$custom['final_payment'];
            $remaining = max(0, (float)$custom['offered_price'] - $paid);
            $paymentLabel = $remaining <= 0 ? 'LUNAS' : ($paid > 0 ? 'DP / BELUM LUNAS' : 'BELUM BAYAR');
        ?>
        <div class="row"><span class="l">No. Order</span><span class="r"><?= receipt_safe($custom['order_code']); ?></span></div>
        <div class="row"><span class="l">Tanggal</span><span class="r"><?= receipt_safe(receipt_date_only($custom['order_date'])); ?></span></div>
        <div class="row"><span class="l">Customer</span><span class="r"><?= receipt_safe(short_text($custom['customer_name'], 22)); ?></span></div>
        <?php if (!empty($custom['customer_phone'])): ?>
            <div class="row"><span class="l">WA</span><span class="r"><?= receipt_safe($custom['customer_phone']); ?></span></div>
        <?php endif; ?>
        <div class="row"><span class="l">Status Bayar</span><span class="r"><?= receipt_safe($paymentLabel); ?></span></div>

        <div class="line"></div>

        <div class="item">
            <div class="item-name"><?= receipt_safe($custom['product_name']); ?></div>
            <div class="item-meta"><span>Qty</span><strong><?= receipt_num($custom['qty']); ?> <?= receipt_safe($custom['unit']); ?></strong></div>
            <div class="item-meta"><span>Deadline</span><strong><?= receipt_safe(receipt_date_only($custom['due_date'])); ?></strong></div>
            <div class="item-meta"><span>Status Order</span><strong><?= receipt_safe(ucfirst($custom['status'])); ?></strong></div>
        </div>

        <?php if (!empty($custom['description'])): ?>
            <div class="note"><?= nl2br(receipt_safe($custom['description'])); ?></div>
        <?php endif; ?>

        <div class="line"></div>

        <div class="total-box">
            <div class="row"><span class="l">Harga Penawaran</span><span class="r"><?= receipt_money($custom['offered_price']); ?></span></div>
            <div class="row"><span class="l">DP</span><span class="r"><?= receipt_money($custom['down_payment']); ?></span></div>
            <div class="row"><span class="l">Pelunasan</span><span class="r"><?= receipt_money($custom['final_payment']); ?></span></div>
            <div class="line"></div>
            <div class="row grand"><span class="l">SISA TAGIHAN</span><span class="r"><?= receipt_money($remaining); ?></span></div>
        </div>

        <?php if (!empty($custom['notes'])): ?>
            <div class="note"><?= nl2br(receipt_safe($custom['notes'])); ?></div>
        <?php endif; ?>

        <div class="line"></div>
        <div class="receipt-center small">
            Order custom diproses sesuai<br>
            spesifikasi dan kesepakatan.<br>
            Simpan slip ini sebagai bukti order.
        </div>
    <?php endif; ?>

    <div class="line"></div>
    <div class="receipt-center small">
        PT Nukari Kriya Raya<br>
        Terima kasih
    </div>
</div>

    </div>
    <div class="screen-hint">Saat dicetak, hanya area struk yang akan keluar. Navbar, sidebar, dan tombol tidak ikut tercetak.</div>
</div>

<script>
(function(){
    const params = new URLSearchParams(location.search);
    if (params.get('type') === 'sale') {
        const pendingId = sessionStorage.getItem('nukari_pos_paid_queue_pending');
        if (pendingId) {
            const key = 'nukari_pos_queues_v1';
            let queues = [];
            try { queues = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) {}
            queues = Array.isArray(queues) ? queues.filter(q => q.id !== pendingId) : [];
            localStorage.setItem(key, JSON.stringify(queues));
            localStorage.removeItem('nukari_pos_active_queue_v1');
            sessionStorage.removeItem('nukari_pos_paid_queue_pending');
        }
    }
    if (params.get('print') === '1') {
        setTimeout(() => window.print(), 500);
    }
})();
</script>


</body>
</html>
