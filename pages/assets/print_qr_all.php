<?php
require_once __DIR__ . '/../../includes/auth.php';

$stmt = $pdo->query("
    SELECT 
        a.*,
        e.full_name AS responsible_name,
        p.project_name,
        p.project_code
    FROM assets a
    LEFT JOIN employees e ON a.responsible_employee_id = e.id
    LEFT JOIN projects p ON a.source_project_id = p.id
    ORDER BY a.asset_code ASC, a.id ASC
");
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak Semua QR Aset</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @page { size: A4; margin: 10mm; }
        body { font-family: Arial, sans-serif; color: #111827; margin: 0; background: #f3f4f6; }
        .toolbar { padding: 14px; text-align: center; }
        .toolbar button { border: 0; background: #198754; color: #fff; padding: 10px 18px; border-radius: 999px; font-weight: 700; cursor: pointer; }
        .sheet { width: 190mm; margin: 0 auto; display: grid; grid-template-columns: repeat(2, 1fr); gap: 8mm; padding: 8mm 0; }
        .label { background: #fff; border: 1px solid #d1d5db; border-radius: 12px; padding: 10px; min-height: 78mm; box-sizing: border-box; break-inside: avoid; page-break-inside: avoid; }
        .brand { font-size: 10px; font-weight: 800; color: #198754; text-transform: uppercase; letter-spacing: .05em; }
        h2 { font-size: 14px; margin: 6px 0 3px; line-height: 1.2; }
        .code { display: inline-block; margin: 4px 0 8px; padding: 4px 8px; background: #ecfdf5; color: #166534; border-radius: 999px; font-size: 10px; font-weight: 700; }
        .qr { text-align: center; margin: 4px 0 8px; }
        .qr img, .qr canvas { width: 32mm; height: 32mm; margin: auto; }
        .meta { font-size: 10px; line-height: 1.45; border-top: 1px solid #e5e7eb; padding-top: 7px; }
        .meta strong { display: inline-block; min-width: 58px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { padding: 0; }
            .label { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Cetak Semua QR</button>
    </div>

    <div class="sheet">
        <?php if ($assets): foreach ($assets as $asset): ?>
            <?php
                $assetCode = $asset['asset_code'] ?: 'AST-' . str_pad((string)$asset['id'], 4, '0', STR_PAD_LEFT);
                $qrData = $assetCode;
            ?>
            <div class="label">
                <div class="brand">PT Nukari Kriya Raya</div>
                <h2><?= htmlspecialchars($asset['asset_name']); ?></h2>
                <div class="code"><?= htmlspecialchars($assetCode); ?></div>
                <div class="qr" data-qr="<?= htmlspecialchars($qrData, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="meta">
                    <div><strong>Kategori</strong> <?= htmlspecialchars($asset['category'] ?: '-'); ?></div>
                    <div><strong>Lokasi</strong> <?= htmlspecialchars($asset['location'] ?: '-'); ?></div>
                    <div><strong>PJ</strong> <?= htmlspecialchars($asset['responsible_name'] ?: '-'); ?></div>
                    <div><strong>Kondisi</strong> <?= htmlspecialchars($asset['condition_status'] ?: '-'); ?></div>
                </div>
            </div>
        <?php endforeach; else: ?>
            <p>Belum ada aset untuk dicetak.</p>
        <?php endif; ?>
    </div>
<script src="<?= base_url('assets/vendor/qrcodejs/qrcode.min.js'); ?>"></script>
<script>document.querySelectorAll('[data-qr]').forEach(function(el){new QRCode(el,{text:el.dataset.qr,width:220,height:220,correctLevel:QRCode.CorrectLevel.H});});</script>
</body>
</html>
