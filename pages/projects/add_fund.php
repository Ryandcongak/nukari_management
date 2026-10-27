<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';

function parse_amount_id($value)
{
    $raw = trim((string) $value);
    $raw = str_replace(["\xc2\xa0", ' ', 'Rp', 'rp', 'IDR', 'idr'], '', $raw);
    $digitsOnly = preg_replace('/[^0-9]/', '', $raw);
    return $digitsOnly === '' ? 0 : (float) $digitsOnly;
}

function project_fund_table_exists(PDO $pdo, string $tableName): bool
{
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    if ($tableName === '') return false;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
        $stmt->execute([':table_name' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_project_fund_receipts_table(PDO $pdo): void
{
    if (!project_fund_table_exists($pdo, 'project_fund_receipts')) throw new RuntimeException('Migrasi database project_fund_receipts belum dijalankan.');
}

function upload_project_fund_receipts_page($files, int $fundId, PDO $pdo, ?array &$storedPaths = null): int
{
    if ($fundId <= 0 || !isset($files) || empty($files['name'][0])) return 0;
    ensure_project_fund_receipts_table($pdo);
    $paths = upload_store_multiple($files, 'project_funds', 'fund_' . $fundId, upload_image_mimes(), 5242880, 10);
    $storedPaths = $paths;
    try {
        $stmt = $pdo->prepare("INSERT INTO project_fund_receipts (fund_id, file_path) VALUES (:fund_id, :file_path)");
        foreach ($paths as $path) $stmt->execute([':fund_id' => $fundId, ':file_path' => $path]);
    } catch (Throwable $e) {
        foreach ($paths as $path) upload_delete_file($path);
        throw $e;
    }
    return count($paths);
}

function fund_rp($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

$project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
if ($project_id <= 0) redirect('pages/projects/index.php');

$stmt = $pdo->prepare("SELECT id, project_name, contract_value FROM projects WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) redirect('pages/projects/index.php');

$pageTitle = 'Tambah Pendanaan Project - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $source_type = clean_input($_POST['source_type'] ?? 'company');
    $person_name = clean_input($_POST['person_name'] ?? '');
    $amount = parse_amount_id($_POST['amount'] ?? 0);
    $description = clean_input($_POST['description'] ?? '');

    if (!in_array($source_type, ['company', 'person'], true)) $source_type = 'company';

    if ($amount <= 0) {
        $error = 'Jumlah pendanaan harus lebih dari 0.';
    } elseif ($source_type === 'person' && $person_name === '') {
        $error = 'Nama lengkap personal wajib diisi.';
    } else {
        $uploadedReceiptPaths = [];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("\n                INSERT INTO project_funds (project_id, source_type, person_name, amount, description)\n                VALUES (:project_id, :source_type, :person_name, :amount, :description)\n            ");
            $stmt->execute([
                ':project_id' => $project_id,
                ':source_type' => $source_type,
                ':person_name' => $source_type === 'person' ? $person_name : null,
                ':amount' => $amount,
                ':description' => $description ?: null
            ]);
            $fundId = (int) $pdo->lastInsertId();
            upload_project_fund_receipts_page($_FILES['receipt_images'] ?? null, $fundId, $pdo, $uploadedReceiptPaths);
            finance_sync_project_fund_transaction($pdo, $fundId, $_SESSION['user_id'] ?? null);
            $pdo->commit();
            redirect('pages/projects/detail.php?id=' . $project_id . '&tab=funds');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($uploadedReceiptPaths as $path) upload_delete_file($path);
            $error = 'Gagal menyimpan pendanaan project: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Tambah Pendanaan Project</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p>
        </div>
        <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=funds'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="panel">
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Sumber Pendanaan</label>
                        <select name="source_type" id="source_type" class="form-select" onchange="togglePerson()">
                            <option value="company">Perusahaan / Kas Perusahaan</option>
                            <option value="person">Personal / Dana Talangan</option>
                        </select>
                    </div>
                    <div class="col-md-12" id="person_box" style="display:none;">
                        <label class="form-label fw-semibold">Nama Lengkap Personal</label>
                        <input type="text" name="person_name" class="form-control" placeholder="Contoh: Ryan Saputra / I Made Maja">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Jumlah Pendanaan</label>
                        <input type="text" id="amount_display" class="form-control" placeholder="Rp 1.000.000" autocomplete="off" required>
                        <input type="hidden" name="amount" id="amount">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Bukti Pendanaan / Transfer <span class="text-muted">(bisa lebih dari satu)</span></label>
                        <input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple>
                        <small class="text-muted">Format JPG, JPEG, PNG, WEBP. Maksimal 5MB per foto.</small>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Keterangan</label>
                        <textarea name="description" rows="4" class="form-control" placeholder="Contoh: Dana perusahaan untuk kebutuhan project / talangan pembelian material."></textarea>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Pendanaan</button>
                        <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=funds'); ?>" class="btn btn-light">Batal</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="panel bg-light border-0">
                <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-1"></i> Panduan Pendanaan</h6>
                <div class="small text-muted mb-3">Pendanaan mencatat sumber dana project. Dana perusahaan bukan pendapatan project. Dana personal otomatis menjadi hutang talangan kepada nama lengkap yang diinput.</div>
                <div class="small"><b>Dana Perusahaan</b><br><span class="text-muted">Kas/bank perusahaan yang dikeluarkan untuk project.</span></div><hr>
                <div class="small"><b>Dana Talangan Personal</b><br><span class="text-muted">Uang pribadi seseorang yang harus diganti perusahaan.</span></div>
            </div>
        </div>
    </div>
</main>
<script>
function togglePerson(){document.getElementById('person_box').style.display=document.getElementById('source_type').value==='person'?'block':'none';}
const display=document.getElementById('amount_display'), hidden=document.getElementById('amount');
if(display&&hidden){display.addEventListener('input',function(){let n=this.value.replace(/[^0-9]/g,''); hidden.value=n; this.value=n?new Intl.NumberFormat('id-ID').format(parseInt(n)):'';});}
togglePerson();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
