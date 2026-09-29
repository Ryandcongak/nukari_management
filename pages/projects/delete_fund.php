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

function upload_project_fund_receipts_page($files, int $fundId, PDO $pdo): int
{
    if ($fundId <= 0 || !isset($files) || empty($files['name'][0])) return 0;
    ensure_project_fund_receipts_table($pdo);

    $uploadDir = __DIR__ . '/../../uploads/project_funds/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSize = 5 * 1024 * 1024;
    $saved = 0;

    foreach ($files['name'] as $key => $name) {
        if (($files['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ($files['error'][$key] !== UPLOAD_ERR_OK) throw new Exception('Ada foto bukti pendanaan yang gagal diupload.');
        if ($files['size'][$key] > $maxSize) throw new Exception('Ukuran setiap foto bukti pendanaan maksimal 5MB.');
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) throw new Exception('Format bukti pendanaan harus JPG, JPEG, PNG, atau WEBP.');

        $fileName = 'fund_' . $fundId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$key], $uploadDir . $fileName)) {
            throw new Exception('Gagal menyimpan foto bukti pendanaan.');
        }

        $stmt = $pdo->prepare("INSERT INTO project_fund_receipts (fund_id, file_path) VALUES (:fund_id, :file_path)");
        $stmt->execute([
            ':fund_id' => $fundId,
            ':file_path' => 'uploads/project_funds/' . $fileName
        ]);
        $saved++;
    }
    return $saved;
}

function fund_rp($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) redirect('pages/projects/index.php');
$stmt = $pdo->prepare("\n    SELECT pf.*, p.project_name\n    FROM project_funds pf\n    JOIN projects p ON p.id = pf.project_id\n    WHERE pf.id = :id\n    LIMIT 1\n");
$stmt->execute([':id' => $id]);
$fund = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$fund) redirect('pages/projects/index.php');

$pageTitle = 'Hapus Pendanaan Project - Nukari';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        finance_delete_project_fund_transaction_if_exists($pdo, $id);
        if (project_fund_table_exists($pdo, 'project_fund_receipts')) {
            $stmt = $pdo->prepare("DELETE FROM project_fund_receipts WHERE fund_id = :id");
            $stmt->execute([':id' => $id]);
        }
        $stmt = $pdo->prepare("DELETE FROM project_transactions WHERE source_module = 'fund' AND source_id = :id AND project_id = :project_id");
        $stmt->execute([':id' => $id, ':project_id' => $fund['project_id']]);
        $stmt = $pdo->prepare("DELETE FROM project_funds WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $pdo->commit();
        redirect('pages/projects/detail.php?id=' . $fund['project_id'] . '&tab=funds');
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Gagal menghapus pendanaan project: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h1 class="fw-bold mb-1 text-danger">Hapus Pendanaan Project</h1><p class="text-muted mb-0"><?= htmlspecialchars($fund['project_name']); ?></p></div>
        <a href="<?= base_url('pages/projects/detail.php?id=' . $fund['project_id'] . '&tab=funds'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <div class="row g-4"><div class="col-lg-8"><div class="panel">
        <h5 class="fw-bold mb-3">Konfirmasi Hapus Pendanaan</h5>
        <div class="alert alert-danger rounded-4"><ul class="mb-0"><li>Pendanaan project sebesar <strong><?= fund_rp($fund['amount']); ?></strong> akan dihapus.</li><li>Jika berasal dari personal, hutang talangan terkait akan ikut dihapus selama belum memiliki pembayaran.</li><li>Data yang dihapus tidak dapat dikembalikan.</li></ul></div>
        <div class="border rounded-4 p-3 mb-4 bg-light"><div class="row g-3"><div class="col-md-6"><small class="text-muted d-block">Sumber Pendanaan</small><div class="fw-semibold"><?= $fund['source_type']==='company'?'Perusahaan':'Personal'; ?></div></div><div class="col-md-6"><small class="text-muted d-block">Nama Personal</small><div class="fw-semibold"><?= htmlspecialchars($fund['person_name'] ?? '-'); ?></div></div><div class="col-md-6"><small class="text-muted d-block">Nominal</small><div class="fw-bold"><?= fund_rp($fund['amount']); ?></div></div><div class="col-md-6"><small class="text-muted d-block">Keterangan</small><div><?= htmlspecialchars($fund['description'] ?? '-'); ?></div></div></div></div>
        <form method="POST" class="d-flex gap-2"><button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i> Ya, Hapus</button><a href="<?= base_url('pages/projects/detail.php?id=' . $fund['project_id'] . '&tab=funds'); ?>" class="btn btn-light">Batal</a></form>
    </div></div><div class="col-lg-4"><div class="panel bg-light border-0"><h6 class="fw-bold mb-3"><i class="bi bi-exclamation-triangle me-1"></i> Catatan</h6><div class="small text-muted">Gunakan hapus hanya jika pendanaan salah input. Jika hanya ingin mengubah nominal atau sumber dana, gunakan menu edit.</div></div></div></div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
