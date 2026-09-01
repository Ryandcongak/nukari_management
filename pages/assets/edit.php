<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Edit Aset - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);


function asset_money_value($value)
{
    $value = trim((string)$value);

    // Format Indonesia:
    // 1.000.000 => 1000000
    $value = str_replace('.', '', $value);

    // Jika suatu saat ada format desimal:
    // 1.000.000,50 => 1000000.50
    $value = str_replace(',', '.', $value);

    return (float)$value;
}

function asset_allowed_image_ext($filename)
{
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true) ? $ext : false;
}

function asset_upload_one($file, $prefix = 'asset')
{
    if (empty($file['name']) || empty($file['tmp_name'])) return null;

    $ext = asset_allowed_image_ext($file['name']);
    if (!$ext) throw new Exception('Foto aset hanya boleh JPG, JPEG, PNG, atau WEBP.');
    validate_upload($file, ['image/jpeg','image/png','image/webp']);

    $uploadDir = __DIR__ . '/../../uploads/assets/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

    $fileName = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        throw new Exception('Gagal mengupload foto aset.');
    }

    return 'uploads/assets/' . $fileName;
}

function asset_upload_multiple($files, $prefix = 'asset')
{
    $paths = [];
    if (empty($files['name']) || !is_array($files['name'])) return $paths;

    foreach ($files['name'] as $i => $name) {
        if (empty($name)) continue;
        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
        if ($file['error'] !== UPLOAD_ERR_OK) continue;
        $paths[] = asset_upload_one($file, $prefix);
    }
    return $paths;
}

function asset_safe_delete_file($path)
{
    if (!$path) return;
    $file = realpath(__DIR__ . '/../../' . $path);
    $root = realpath(__DIR__ . '/../../uploads/assets');
    if ($file && $root && strpos($file, $root) === 0 && file_exists($file)) {
        @unlink($file);
    }
}


$stmt = $pdo->prepare("
    SELECT a.*, p.project_name, p.project_code 
    FROM assets a 
    LEFT JOIN projects p ON a.source_project_id = p.id
    WHERE a.id=:id LIMIT 1
");
$stmt->execute([':id' => $id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) redirect('pages/assets/index.php');

if (isset($_GET['delete_photo'])) {
    $photoId = (int)$_GET['delete_photo'];
    $ph = $pdo->prepare("SELECT * FROM project_asset_photos WHERE id=:id AND asset_id=:asset_id LIMIT 1");
    $ph->execute([':id' => $photoId, ':asset_id' => $id]);
    $photo = $ph->fetch(PDO::FETCH_ASSOC);
    if ($photo) {
        asset_safe_delete_file($photo['image_path']);
        $pdo->prepare("DELETE FROM project_asset_photos WHERE id=:id")->execute([':id' => $photoId]);

        if (!empty($asset['photo']) && $asset['photo'] === $photo['image_path']) {
            $next = $pdo->prepare("SELECT image_path FROM project_asset_photos WHERE asset_id=:asset_id ORDER BY id ASC LIMIT 1");
            $next->execute([':asset_id' => $id]);
            $nextPhoto = $next->fetchColumn();
            $pdo->prepare("UPDATE assets SET photo=:photo, updated_at=NOW() WHERE id=:id")->execute([':photo' => $nextPhoto ?: null, ':id' => $id]);
        }

        $_SESSION['success'] = 'Foto aset berhasil dihapus.';
    }
    redirect('pages/assets/edit.php?id=' . $id);
}

$employees = $pdo->query("SELECT id, full_name FROM employees WHERE employment_status='active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        ':id' => $id,
        ':asset_code' => clean_input($_POST['asset_code'] ?? ''),
        ':asset_name' => clean_input($_POST['asset_name'] ?? ''),
        ':category' => clean_input($_POST['category'] ?? ''),
        ':purchase_date' => ($_POST['purchase_date'] ?? '') ?: null,
        ':purchase_price' => asset_money_value($_POST['purchase_price'] ?? 0),
        ':condition_status' => clean_input($_POST['condition_status'] ?? 'good'),
        ':asset_status' => clean_input($_POST['asset_status'] ?? 'active'),
        ':ownership_status' => clean_input($_POST['ownership_status'] ?? 'owned'),
        ':use_type' => clean_input($_POST['use_type'] ?? 'internal'),
        ':current_value' => asset_money_value($_POST['current_value'] ?? 0),
        ':warranty_until' => ($_POST['warranty_until'] ?? '') ?: null,
        ':location' => clean_input($_POST['location'] ?? ''),
        ':responsible_employee_id' => ((int)($_POST['responsible_employee_id'] ?? 0)) ?: null,
        ':notes' => clean_input($_POST['notes'] ?? '')
    ];

    if ($data[':asset_name'] === '') {
        $error = 'Nama aset wajib diisi.';
    } else {
        try {
            $photoSql = '';

            if (!empty($_FILES['photo']['name'])) {
                $newMain = asset_upload_one($_FILES['photo'], 'asset_main');
                $data[':photo'] = $newMain;
                $photoSql = ', photo = :photo';

                $pdo->prepare("INSERT INTO project_asset_photos (asset_id, image_path, caption) VALUES (:asset_id, :image_path, :caption)")
                    ->execute([':asset_id' => $id, ':image_path' => $newMain, ':caption' => $data[':asset_name']]);
            }

            $extraPhotos = asset_upload_multiple($_FILES['asset_photos'] ?? [], 'asset_gallery');
            if ($extraPhotos) {
                $insPhoto = $pdo->prepare("INSERT INTO project_asset_photos (asset_id, image_path, caption) VALUES (:asset_id, :image_path, :caption)");
                foreach ($extraPhotos as $path) {
                    $insPhoto->execute([':asset_id' => $id, ':image_path' => $path, ':caption' => $data[':asset_name']]);
                }

                if (empty($asset['photo']) && $photoSql === '') {
                    $data[':photo'] = $extraPhotos[0];
                    $photoSql = ', photo = :photo';
                }
            }

            $stmt = $pdo->prepare("
                UPDATE assets SET 
                    asset_code=:asset_code,
                    asset_name=:asset_name,
                    category=:category,
                    purchase_date=:purchase_date,
                    purchase_price=:purchase_price,
                    current_value=:current_value,
                    warranty_until=:warranty_until,
                    condition_status=:condition_status,
                    asset_status=:asset_status,
                    ownership_status=:ownership_status,
                    use_type=:use_type,
                    location=:location,
                    responsible_employee_id=:responsible_employee_id,
                    notes=:notes,
                    updated_at=NOW()
                    $photoSql
                WHERE id=:id
            ");
            $stmt->execute($data);

            $_SESSION['success'] = 'Aset berhasil diperbarui.';
            redirect('pages/assets/detail.php?id=' . $id);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$photosStmt = $pdo->prepare("SELECT * FROM project_asset_photos WHERE asset_id=:asset_id ORDER BY id ASC");
$photosStmt->execute([':asset_id' => $id]);
$photos = $photosStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
    .asset-form-hero {
        background: linear-gradient(135deg, #07553f, #198754);
        border-radius: 26px;
        padding: 26px;
        color: #fff;
        margin-bottom: 20px
    }

    .asset-panel {
        background: #fff;
        border-radius: 22px;
        padding: 22px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 10px 30px rgba(15, 23, 42, .06)
    }

    .asset-photo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 12px
    }

    .asset-photo-item {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        background: #f8fafc
    }

    .asset-photo-item img {
        width: 100%;
        height: 110px;
        object-fit: cover
    }

    .asset-photo-item .del {
        position: absolute;
        right: 8px;
        top: 8px
    }
</style>

<main class="main-content">
    <div class="asset-form-hero d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <span class="badge bg-white text-success rounded-pill mb-2">Aset / Inventaris</span>
            <h1 class="fw-bold mb-1">Edit Aset</h1>
            <p class="mb-0 opacity-90">Perbarui data, kondisi, lokasi, penanggung jawab, dan foto aset.</p>
        </div>
        <a class="btn btn-light rounded-pill fw-semibold" href="<?= base_url('pages/assets/detail.php?id=' . $id); ?>">
            <i class="bi bi-arrow-left me-1"></i> Detail
        </a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']);
                                                                                unset($_SESSION['success']); ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="asset-panel">
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Kode Aset</label>
                        <input name="asset_code" class="form-control" value="<?= htmlspecialchars($asset['asset_code'] ?? ''); ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Nama Aset *</label>
                        <input name="asset_name" class="form-control" value="<?= htmlspecialchars($asset['asset_name']); ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Kategori</label>
                        <input name="category" class="form-control" value="<?= htmlspecialchars($asset['category'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Tanggal Beli</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?= htmlspecialchars($asset['purchase_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Harga Beli</label>
                        <input name="purchase_price" class="form-control money-input" value="<?= htmlspecialchars(number_format((float)$asset['purchase_price'], 0, ',', '.')); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Nilai Saat Ini</label>
                        <input name="current_value" class="form-control money-input" value="<?= htmlspecialchars(number_format((float)($asset['current_value'] ?? $asset['purchase_price']), 0, ',', '.')); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Garansi Sampai</label>
                        <input type="date" name="warranty_until" class="form-control" value="<?= htmlspecialchars($asset['warranty_until'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Kondisi</label>
                        <select name="condition_status" class="form-select">
                            <?php foreach (['good' => 'Baik', 'maintenance' => 'Maintenance', 'damaged' => 'Rusak', 'lost' => 'Hilang'] as $k => $v): ?>
                                <option value="<?= $k; ?>" <?= $asset['condition_status'] === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status Bisnis</label>
                        <select name="asset_status" class="form-select">
                            <?php foreach (['active'=>'Aktif','maintenance'=>'Maintenance','damaged'=>'Rusak','lost'=>'Hilang','sold'=>'Dijual','rented'=>'Disewakan','inactive'=>'Tidak Aktif','disposed'=>'Dihapuskan'] as $k=>$v): ?>
                                <option value="<?= $k; ?>" <?= ($asset['asset_status'] ?? 'active') === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Kepemilikan</label>
                        <select name="ownership_status" class="form-select">
                            <?php foreach (['owned'=>'Milik Nukari','leased'=>'Disewa Nukari','borrowed'=>'Dipinjam','rented_out'=>'Disewakan Keluar'] as $k=>$v): ?>
                                <option value="<?= $k; ?>" <?= ($asset['ownership_status'] ?? 'owned') === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Jenis Penggunaan</label>
                        <select name="use_type" class="form-select">
                            <?php foreach (['internal'=>'Internal','rental'=>'Untuk Disewakan','resale'=>'Untuk Dijual Kembali','project'=>'Project','mixed'=>'Campuran'] as $k=>$v): ?>
                                <option value="<?= $k; ?>" <?= ($asset['use_type'] ?? 'internal') === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Lokasi</label>
                        <input name="location" class="form-control" value="<?= htmlspecialchars($asset['location'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Penanggung Jawab</label>
                        <select name="responsible_employee_id" class="form-select">
                            <option value="">- Tidak ada -</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= (int)$e['id']; ?>" <?= (int)($asset['responsible_employee_id'] ?? 0) === (int)$e['id'] ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($e['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Ganti Foto Utama</label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Tambah Foto Tambahan</label>
                        <input type="file" name="asset_photos[]" class="form-control" accept="image/*" multiple>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea name="notes" class="form-control" rows="4"><?= htmlspecialchars($asset['notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-nukari rounded-pill px-4"><i class="bi bi-save me-1"></i> Update Aset</button>
                        <a class="btn btn-outline-secondary rounded-pill px-4" href="<?= base_url('pages/assets/index.php'); ?>">Kembali</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="asset-panel mb-3">
                <h5 class="fw-bold mb-3">Sumber Aset</h5>
                <?php if (($asset['source_type'] ?? 'manual') === 'project_transaction'): ?>
                    <span class="badge rounded-pill text-bg-info mb-2">Dari Project</span>
                    <div class="fw-semibold"><?= htmlspecialchars($asset['project_name'] ?: '-'); ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($asset['project_code'] ?: '-'); ?></div>
                    <?php if (!empty($asset['source_project_id'])): ?>
                        <a class="btn btn-sm btn-outline-success mt-3" href="<?= base_url('pages/projects/detail.php?id=' . (int)$asset['source_project_id']); ?>">
                            <i class="bi bi-kanban me-1"></i> Buka Project
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge rounded-pill text-bg-light">Manual</span>
                    <div class="small text-muted mt-2">Aset dibuat langsung dari halaman inventaris.</div>
                <?php endif; ?>
            </div>

            <div class="asset-panel">
                <h5 class="fw-bold mb-3">Foto Aset</h5>
                <?php if ($photos): ?>
                    <div class="asset-photo-grid">
                        <?php foreach ($photos as $ph): ?>
                            <div class="asset-photo-item">
                                <img src="<?= base_url($ph['image_path']); ?>" alt="Foto Aset">
                                <a class="btn btn-sm btn-danger del" onclick="return confirm('Hapus foto ini?')" href="<?= base_url('pages/assets/edit.php?id=' . $id . '&delete_photo=' . (int)$ph['id']); ?>">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted small">Belum ada foto tambahan.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.money-input').forEach(function(input) {
            function formatRupiah(value) {
                value = String(value || '').replace(/[^0-9]/g, '');
                if (value === '') return '';
                return new Intl.NumberFormat('id-ID').format(value);
            }

            input.addEventListener('input', function() {
                this.value = formatRupiah(this.value);
                this.setSelectionRange(this.value.length, this.value.length);
            });

            input.addEventListener('blur', function() {
                this.value = formatRupiah(this.value);
            });

            if (input.value) {
                input.value = formatRupiah(input.value);
            }
        });
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
