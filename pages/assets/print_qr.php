<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('ID aset tidak valid.');
}

$stmt = $pdo->prepare("
    SELECT 
        a.*,
        e.full_name AS responsible_name,
        p.project_name,
        p.project_code
    FROM assets a
    LEFT JOIN employees e ON a.responsible_employee_id = e.id
    LEFT JOIN projects p ON a.source_project_id = p.id
    WHERE a.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    die('Aset tidak ditemukan.');
}

$assetCode = $asset['asset_code'] ?: 'AST-' . str_pad((string)$asset['id'], 4, '0', STR_PAD_LEFT);
$qrData = $assetCode;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak QR Aset - <?= htmlspecialchars($asset['asset_code'] ?: $asset['asset_name']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Arial, sans-serif; color: #111827; margin: 0; background: #f3f4f6; }
        .toolbar { padding: 14px; text-align: center; }
        .toolbar button { border: 0; background: #198754; color: #fff; padding: 10px 18px; border-radius: 999px; font-weight: 700; cursor: pointer; }
        .sheet { width: 88mm; min-height: 118mm; margin: 14px auto; background: #fff; border: 1px solid #d1d5db; border-radius: 14px; padding: 14px; box-sizing: border-box; }
        .brand { font-size: 12px; font-weight: 800; color: #198754; text-transform: uppercase; letter-spacing: .05em; }
        h1 { font-size: 18px; margin: 8px 0 4px; line-height: 1.2; }
        .code { display: inline-block; margin: 6px 0 12px; padding: 5px 10px; background: #ecfdf5; color: #166534; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .qr { text-align: center; margin: 8px 0 12px; }
        .qr img, .qr canvas { width: 54mm; height: 54mm; margin: auto; }
        .meta { font-size: 12px; line-height: 1.55; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .meta strong { display: inline-block; min-width: 72px; }
        .footer { margin-top: 12px; font-size: 10px; color: #6b7280; text-align: center; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Cetak QR Aset</button>
    </div>

    <div class="sheet">
        <div class="brand">PT Nukari Kriya Raya</div>
        <h1><?= htmlspecialchars($asset['asset_name']); ?></h1>
        <div class="code"><?= htmlspecialchars($assetCode); ?></div>

        <div class="qr" id="assetQr"></div>

        <div class="meta">
            <div><strong>Kategori</strong> <?= htmlspecialchars($asset['category'] ?: '-'); ?></div>
            <div><strong>Lokasi</strong> <?= htmlspecialchars($asset['location'] ?: '-'); ?></div>
            <div><strong>PJ</strong> <?= htmlspecialchars($asset['responsible_name'] ?: '-'); ?></div>
            <div><strong>Kondisi</strong> <?= htmlspecialchars($asset['condition_status'] ?: '-'); ?></div>
            <?php if (($asset['source_type'] ?? 'manual') === 'project_transaction'): ?>
                <div><strong>Sumber</strong> Project <?= htmlspecialchars($asset['project_code'] ?: ''); ?></div>
            <?php else: ?>
                <div><strong>Sumber</strong> Manual</div>
            <?php endif; ?>
        </div>

        <div class="footer">Scan QR atau input kode aset ini di menu Asset Scanner.</div>
    </div>
<script src="<?= base_url('assets/vendor/qrcodejs/qrcode.min.js'); ?>"></script>
<script>new QRCode(document.getElementById('assetQr'), {text: <?= json_encode($qrData); ?>, width: 260, height: 260, correctLevel: QRCode.CorrectLevel.H});</script>
</body>
</html>
