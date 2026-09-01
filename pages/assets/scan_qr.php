<?php
require_once __DIR__ . '/../../includes/auth.php';

/**
 * Asset QR Scanner - Cloudflare/HTTPS ready
 * Replace: pages/assets/scan_qr.php
 */

function normalize_asset_lookup_value(string $raw): string
{
    $value = trim($raw);
    if ($value === '') {
        return '';
    }

    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

    // If QR contains URL, extract id/code/query/path value.
    if (preg_match('/[?&]id=(\d+)/i', $value, $m)) {
        return trim($m[1]);
    }

    if (preg_match('/[?&](code|asset_code|qr|q)=([^&]+)/i', $value, $m)) {
        return trim(urldecode($m[2]));
    }

    // If QR contains a full asset URL without query but contains AST code.
    if (preg_match('/(AST[-_\s]?[A-Za-z0-9][A-Za-z0-9\-_\s]*)/i', $value, $m)) {
        return strtoupper(trim($m[1]));
    }

    return trim($value);
}

function find_asset_by_code(PDO $pdo, string $raw): ?array
{
    $value = normalize_asset_lookup_value($raw);
    if ($value === '') {
        return null;
    }

    // Numeric input can be asset ID.
    if (ctype_digit($value)) {
        $stmt = $pdo->prepare("SELECT id, asset_code, asset_name FROM assets WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$value]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($asset) {
            return $asset;
        }
    }

    $clean = strtoupper(trim($value));
    $compact = preg_replace('/[^A-Z0-9]/', '', $clean);

    // Flexible lookup: exact asset_code, compact asset_code, or QR text containing code.
    $stmt = $pdo->prepare("
        SELECT id, asset_code, asset_name
        FROM assets
        WHERE
            UPPER(asset_code) = UPPER(:code)
            OR REPLACE(REPLACE(REPLACE(UPPER(asset_code), '-', ''), '_', ''), ' ', '') = :compact
            OR UPPER(:raw) LIKE CONCAT('%', UPPER(asset_code), '%')
        LIMIT 1
    ");
    $stmt->execute([
        ':code' => $clean,
        ':compact' => $compact,
        ':raw' => strtoupper($raw),
    ]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    return $asset ?: null;
}

$manualCode = trim($_GET['code'] ?? $_POST['code'] ?? '');
$errorMessage = '';

if ($manualCode !== '') {
    $asset = find_asset_by_code($pdo, $manualCode);
    if ($asset) {
        header('Location: ' . base_url('pages/assets/detail.php?id=' . (int)$asset['id']));
        exit;
    }
    $errorMessage = 'Kode aset tidak ditemukan. Pastikan kode/QR sesuai label aset.';
}

$pageTitle = 'Scan QR Aset - Nukari';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
.assets-scan-hero{
    background:linear-gradient(135deg,#064e3b 0%,#15803d 58%,#e0bd42 100%);
    border-radius:28px;
    padding:30px;
    color:#fff;
    margin-bottom:20px;
    overflow:hidden;
    position:relative;
}
.assets-scan-hero:after{
    content:"";
    width:220px;
    height:220px;
    border-radius:50%;
    background:rgba(255,255,255,.12);
    position:absolute;
    right:-50px;
    top:-70px;
}
.assets-scan-card{
    background:#fff;
    border:1px solid rgba(226,232,240,.95);
    border-radius:24px;
    box-shadow:0 14px 35px rgba(15,23,42,.07);
    overflow:hidden;
}
.assets-scan-card-header{
    padding:20px 22px;
    border-bottom:1px solid #eef2f7;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
}
.assets-scan-card-body{padding:22px}
.assets-scanner-stage{
    background:#0f172a;
    border-radius:22px;
    min-height:360px;
    padding:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    position:relative;
    overflow:hidden;
}
#reader{
    width:100%;
    max-width:620px;
    min-height:320px;
    border-radius:18px;
    overflow:hidden;
}
.assets-scan-overlay{
    position:absolute;
    width:min(260px,70vw);
    height:min(260px,70vw);
    border:3px solid rgba(34,197,94,.9);
    border-radius:24px;
    pointer-events:none;
    box-shadow:0 0 0 999px rgba(15,23,42,.25);
}
.assets-scan-status{
    border-radius:16px;
    padding:13px 14px;
    font-weight:700;
}
.assets-input-code{
    font-size:20px;
    font-weight:800;
    letter-spacing:.05em;
    text-transform:uppercase;
    border-radius:16px;
    padding:14px 16px;
}
.assets-guide-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:18px;
    padding:16px;
}
.assets-code-example{
    background:#ecfdf5;
    color:#166534;
    border:1px dashed #86efac;
    border-radius:14px;
    padding:11px 13px;
    font-weight:800;
    letter-spacing:.04em;
}
.assets-camera-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}
.assets-camera-actions .btn{border-radius:999px;font-weight:800}
.assets-pill{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:7px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    background:#ecfdf5;
    color:#166534;
}
.assets-device-note{
    color:#64748b;
    font-size:13px;
    line-height:1.55;
}
@media(max-width:767px){
    .assets-scan-hero{padding:22px;border-radius:22px}
    .assets-scan-hero h1{font-size:28px}
    .assets-scanner-stage{min-height:300px;padding:10px}
    #reader{min-height:260px}
    .assets-scan-card-body{padding:16px}
    .assets-camera-actions .btn{width:100%}
}
</style>

<main class="main-content">
    <div class="assets-scan-hero">
        <span class="badge bg-white text-success rounded-pill mb-3 px-3 py-2 fw-bold">
            <i class="bi bi-qr-code-scan me-1"></i> Asset Scanner
        </span>
        <h1 class="fw-bold mb-2">Scan QR Aset</h1>
        <p class="mb-0 opacity-90">
            Gunakan kamera HP melalui HTTPS/Cloudflare, scan dari foto QR, atau input kode aset manual.
        </p>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="assets-scan-card">
                <div class="assets-scan-card-header">
                    <div>
                        <span class="assets-pill mb-2"><i class="bi bi-camera-video"></i> Kamera QR</span>
                        <h5 class="fw-bold mb-0">Scanner Kamera</h5>
                    </div>
                    <div class="assets-camera-actions">
                        <button type="button" class="btn btn-success px-4" id="startCameraBtn">
                            <i class="bi bi-camera-video me-1"></i> Mulai Scan
                        </button>
                        <button type="button" class="btn btn-outline-success px-4" id="switchCameraBtn" disabled>
                            <i class="bi bi-arrow-repeat me-1"></i> Ganti Kamera
                        </button>
                        <button type="button" class="btn btn-outline-danger px-4" id="stopCameraBtn" disabled>
                            <i class="bi bi-stop-circle me-1"></i> Stop
                        </button>
                    </div>
                </div>

                <div class="assets-scan-card-body">
                    <div class="assets-scanner-stage">
                        <div id="reader"></div>
                        <div class="assets-scan-overlay d-none" id="scanOverlay"></div>
                    </div>

                    <div id="scanStatus" class="assets-scan-status bg-light text-muted mt-3">
                        Tekan <strong>Mulai Scan</strong>. Pastikan browser memakai URL <strong>https://</strong> agar izin kamera HP muncul.
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-7">
                            <label class="form-label fw-bold">Scan dari Foto QR</label>
                            <input type="file" id="qrImageFile" class="form-control" accept="image/*">
                            <div class="assets-device-note mt-2">
                                Gunakan ini jika kamera browser sulit membaca QR langsung.
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold">Status Koneksi</label>
                            <div class="assets-guide-box">
                                <div id="secureContextInfo" class="fw-bold"></div>
                                <div class="assets-device-note mt-1">Cloudflare HTTPS biasanya mendukung akses kamera HP.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="assets-scan-card mb-4">
                <div class="assets-scan-card-header">
                    <div>
                        <span class="assets-pill mb-2"><i class="bi bi-keyboard"></i> Input Manual</span>
                        <h5 class="fw-bold mb-0">Masukkan Kode Aset</h5>
                    </div>
                </div>
                <div class="assets-scan-card-body">
                    <p class="text-muted mb-2">Contoh kode:</p>
                    <div class="assets-code-example mb-3">AST-20260601103655</div>

                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger rounded-4 py-2"><?= htmlspecialchars($errorMessage); ?></div>
                    <?php endif; ?>

                    <form method="get" action="scan_qr.php" id="manualForm">
                        <label class="form-label fw-semibold">Kode / URL QR Aset</label>
                        <input type="text" name="code" id="manualCode" class="form-control assets-input-code mb-3" placeholder="AST-..." autocomplete="off" autofocus value="<?= htmlspecialchars($manualCode); ?>">
                        <button class="btn btn-success rounded-pill px-4 fw-bold" type="submit">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Buka Detail Aset
                        </button>
                    </form>
                </div>
            </div>

            <div class="assets-scan-card">
                <div class="assets-scan-card-header">
                    <h5 class="fw-bold mb-0"><i class="bi bi-info-circle me-1"></i> Panduan Scan</h5>
                </div>
                <div class="assets-scan-card-body">
                    <ol class="text-muted small mb-0 lh-lg">
                        <li>Buka sistem melalui URL Cloudflare yang diawali <strong>https://</strong>.</li>
                        <li>Tekan <strong>Mulai Scan</strong>, lalu izinkan kamera.</li>
                        <li>Arahkan kamera ke QR label aset.</li>
                        <li>Jika tidak terbaca, gunakan <strong>Scan dari Foto QR</strong>.</li>
                        <li>Jika kamera tetap gagal, input kode aset manual.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="<?= base_url('assets/vendor/html5-qrcode/html5-qrcode.min.js'); ?>"></script>
<script>
(function(){
    let html5QrCode = null;
    let cameras = [];
    let currentCameraIndex = 0;
    let isScanning = false;
    let hasScanned = false;

    const readerId = 'reader';
    const statusBox = document.getElementById('scanStatus');
    const overlay = document.getElementById('scanOverlay');
    const startBtn = document.getElementById('startCameraBtn');
    const switchBtn = document.getElementById('switchCameraBtn');
    const stopBtn = document.getElementById('stopCameraBtn');
    const fileInput = document.getElementById('qrImageFile');
    const input = document.getElementById('manualCode');
    const secureInfo = document.getElementById('secureContextInfo');

    function setStatus(type, message) {
        const map = {
            info: 'bg-light text-muted',
            success: 'bg-success-subtle text-success',
            warning: 'bg-warning-subtle text-dark',
            danger: 'bg-danger-subtle text-danger'
        };
        statusBox.className = 'assets-scan-status mt-3 ' + (map[type] || map.info);
        statusBox.innerHTML = message;
    }

    function isSecureReady() {
        return window.isSecureContext || location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    }

    secureInfo.innerHTML = isSecureReady()
        ? '<span class="text-success"><i class="bi bi-shield-check me-1"></i>HTTPS / secure context aktif</span>'
        : '<span class="text-danger"><i class="bi bi-shield-exclamation me-1"></i>Belum HTTPS, kamera HP bisa ditolak</span>';

    function normalizeAssetCode(text) {
        text = (text || '').trim();
        if (!text) return '';

        try {
            const url = new URL(text, window.location.origin);
            if (url.searchParams.get('code')) return url.searchParams.get('code').trim();
            if (url.searchParams.get('asset_code')) return url.searchParams.get('asset_code').trim();
            if (url.searchParams.get('id')) return url.searchParams.get('id').trim();
        } catch (e) {}

        const match = text.match(/AST[-_\s]?[A-Za-z0-9][A-Za-z0-9\-_\s]*/i);
        if (match) return match[0].toUpperCase().replace(/\s+/g, '-').replace(/_/g, '-');

        return text.toUpperCase();
    }

    function openAssetCode(decodedText) {
        if (hasScanned) return;
        const code = normalizeAssetCode(decodedText);
        if (!code) return;

        hasScanned = true;
        setStatus('success', '<i class="bi bi-check-circle me-1"></i> QR terbaca: <strong>' + escapeHtml(code) + '</strong>. Membuka detail aset...');
        stopCamera(false).finally(function(){
            window.location.href = 'scan_qr.php?code=' + encodeURIComponent(code);
        });
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function(m){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
        });
    }

    async function ensureScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            throw new Error('Library scanner belum termuat.');
        }
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode(readerId, { verbose: false });
        }
        return html5QrCode;
    }

    async function loadCameras() {
        if (!isSecureReady()) {
            throw new Error('Akses kamera membutuhkan HTTPS. Gunakan URL Cloudflare HTTPS.');
        }

        await ensureScanner();
        cameras = await Html5Qrcode.getCameras();

        if (!cameras || !cameras.length) {
            throw new Error('Kamera tidak ditemukan atau izin kamera ditolak.');
        }

        // Prefer back/environment camera for phones.
        const backIndex = cameras.findIndex(function(cam){
            const label = (cam.label || '').toLowerCase();
            return label.includes('back') || label.includes('rear') || label.includes('environment') || label.includes('belakang');
        });
        currentCameraIndex = backIndex >= 0 ? backIndex : cameras.length - 1;

        switchBtn.disabled = cameras.length <= 1;
        return cameras;
    }

    async function startCamera() {
        try {
            hasScanned = false;
            startBtn.disabled = true;
            setStatus('info', '<i class="bi bi-hourglass-split me-1"></i> Membuka kamera...');

            await loadCameras();
            const scanner = await ensureScanner();
            const cameraId = cameras[currentCameraIndex].id;

            await scanner.start(
                cameraId,
                {
                    fps: 12,
                    qrbox: function(viewfinderWidth, viewfinderHeight) {
                        const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                        const size = Math.floor(minEdge * 0.72);
                        return { width: size, height: size };
                    },
                    aspectRatio: 1.0,
                    disableFlip: false
                },
                function(decodedText) {
                    openAssetCode(decodedText);
                },
                function() {}
            );

            isScanning = true;
            overlay.classList.remove('d-none');
            stopBtn.disabled = false;
            startBtn.disabled = true;
            setStatus('success', '<i class="bi bi-camera-video me-1"></i> Kamera aktif. Arahkan ke QR aset.');
        } catch (err) {
            startBtn.disabled = false;
            stopBtn.disabled = true;
            switchBtn.disabled = true;
            overlay.classList.add('d-none');
            setStatus('warning', '<i class="bi bi-exclamation-triangle me-1"></i> ' + escapeHtml(err.message || 'Kamera gagal dibuka.') + '<br><span class="small">Gunakan input manual atau scan dari foto QR.</span>');
        }
    }

    async function stopCamera(updateStatus = true) {
        if (!html5QrCode || !isScanning) {
            startBtn.disabled = false;
            stopBtn.disabled = true;
            overlay.classList.add('d-none');
            return;
        }

        try {
            await html5QrCode.stop();
            await html5QrCode.clear();
        } catch (e) {}

        isScanning = false;
        overlay.classList.add('d-none');
        startBtn.disabled = false;
        stopBtn.disabled = true;
        switchBtn.disabled = cameras.length <= 1;

        if (updateStatus) {
            setStatus('info', '<i class="bi bi-stop-circle me-1"></i> Kamera dihentikan.');
        }
    }

    async function switchCamera() {
        if (!cameras.length) return;
        await stopCamera(false);
        currentCameraIndex = (currentCameraIndex + 1) % cameras.length;
        await startCamera();
    }

    async function scanImageFile(file) {
        if (!file) return;

        try {
            hasScanned = false;
            const scanner = await ensureScanner();
            setStatus('info', '<i class="bi bi-image me-1"></i> Membaca QR dari foto...');
            const decodedText = await scanner.scanFile(file, true);
            openAssetCode(decodedText);
        } catch (err) {
            setStatus('warning', '<i class="bi bi-exclamation-triangle me-1"></i> QR pada foto belum terbaca. Pastikan foto tajam dan QR terlihat penuh.');
        } finally {
            fileInput.value = '';
        }
    }

    startBtn.addEventListener('click', startCamera);
    stopBtn.addEventListener('click', function(){ stopCamera(true); });
    switchBtn.addEventListener('click', switchCamera);
    fileInput.addEventListener('change', function(e){ scanImageFile(e.target.files[0]); });

    input.addEventListener('input', function(){
        this.value = this.value.toUpperCase();
    });

    document.addEventListener('visibilitychange', function(){
        if (document.hidden && isScanning) {
            stopCamera(false);
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
