<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Tambah Aset - Nukari';
$error = '';


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


$employees = $pdo->query("SELECT id, full_name FROM employees WHERE employment_status='active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $asset_code = clean_input($_POST['asset_code'] ?? '');
    $asset_name = clean_input($_POST['asset_name'] ?? '');
    $category = clean_input($_POST['category'] ?? '');
    $purchase_date = $_POST['purchase_date'] ?: null;
    $purchase_price = asset_money_value($_POST['purchase_price'] ?? 0);
    $condition_status = clean_input($_POST['condition_status'] ?? 'good');
    $location = clean_input($_POST['location'] ?? '');
    $responsible_employee_id = (int)($_POST['responsible_employee_id'] ?? 0);
    $notes = clean_input($_POST['notes'] ?? '');
    $asset_status = clean_input($_POST['asset_status'] ?? 'active');
    $ownership_status = clean_input($_POST['ownership_status'] ?? 'owned');
    $use_type = clean_input($_POST['use_type'] ?? 'internal');
    $current_value = asset_money_value($_POST['current_value'] ?? $purchase_price);
    $warranty_until = ($_POST['warranty_until'] ?? '') ?: null;

    if ($asset_name === '') {
        $error = 'Nama aset wajib diisi.';
    } else {
        try {
            $mainPhoto = null;
            if (!empty($_FILES['photo']['name'])) {
                $mainPhoto = asset_upload_one($_FILES['photo'], 'asset_main');
            }

            if ($asset_code === '') $asset_code = 'AST-' . date('YmdHis');

            $stmt = $pdo->prepare("
                INSERT INTO assets 
                (asset_code, asset_name, category, purchase_date, purchase_price, current_value, warranty_until, condition_status, asset_status, ownership_status, use_type, location, responsible_employee_id, photo, notes, source_type) 
                VALUES 
                (:asset_code, :asset_name, :category, :purchase_date, :purchase_price, :current_value, :warranty_until, :condition_status, :asset_status, :ownership_status, :use_type, :location, :responsible_employee_id, :photo, :notes, 'manual')
            ");
            $stmt->execute([
                ':asset_code' => $asset_code,
                ':asset_name' => $asset_name,
                ':category' => $category ?: null,
                ':purchase_date' => $purchase_date,
                ':purchase_price' => $purchase_price,
                ':current_value' => $current_value,
                ':warranty_until' => $warranty_until,
                ':condition_status' => $condition_status,
                ':asset_status' => $asset_status,
                ':ownership_status' => $ownership_status,
                ':use_type' => $use_type,
                ':location' => $location ?: null,
                ':responsible_employee_id' => $responsible_employee_id ?: null,
                ':photo' => $mainPhoto,
                ':notes' => $notes ?: null
            ]);

            $assetId = (int)$pdo->lastInsertId();

            $photoPaths = [];
            if ($mainPhoto) $photoPaths[] = $mainPhoto;
            $photoPaths = array_merge($photoPaths, asset_upload_multiple($_FILES['asset_photos'] ?? [], 'asset_gallery'));

            if ($photoPaths) {
                $insPhoto = $pdo->prepare("INSERT INTO project_asset_photos (asset_id, image_path, caption) VALUES (:asset_id, :image_path, :caption)");
                foreach ($photoPaths as $path) {
                    $insPhoto->execute([
                        ':asset_id' => $assetId,
                        ':image_path' => $path,
                        ':caption' => $asset_name
                    ]);
                }
            }

            $_SESSION['success'] = 'Aset berhasil ditambahkan.';
            redirect('pages/assets/index.php');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

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
</style>

<main class="main-content">
    <div class="asset-form-hero d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <span class="badge bg-white text-success rounded-pill mb-2">Aset / Inventaris</span>
            <h1 class="fw-bold mb-1">Tambah Aset Baru</h1>
            <p class="mb-0 opacity-90">Input aset manual perusahaan lengkap dengan foto dan penanggung jawab.</p>
        </div>
        <a class="btn btn-light rounded-pill fw-semibold" href="<?= base_url('pages/assets/index.php'); ?>">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="asset-panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kode Aset</label>
                <input name="asset_code" class="form-control" placeholder="Kosongkan untuk otomatis">
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">Nama Aset *</label>
                <input name="asset_name" class="form-control" required placeholder="Contoh: Mesin Potong Rumput Honda">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Kategori</label>
                <input name="category" class="form-control" placeholder="Mesin / Kendaraan / Furniture">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Beli</label>
                <input type="date" name="purchase_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Harga Beli</label>
                <input name="purchase_price" class="form-control money-input" value="0">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nilai Saat Ini</label>
                <input name="current_value" class="form-control money-input" value="0">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Garansi Sampai</label>
                <input type="date" name="warranty_until" class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Kondisi</label>
                <select name="condition_status" class="form-select">
                    <option value="good">Baik</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="damaged">Rusak</option>
                    <option value="lost">Hilang</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Status Bisnis</label>
                <select name="asset_status" class="form-select">
                    <option value="active">Aktif</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="damaged">Rusak</option>
                    <option value="lost">Hilang</option>
                    <option value="sold">Dijual</option>
                    <option value="rented">Disewakan</option>
                    <option value="inactive">Tidak Aktif</option>
                    <option value="disposed">Dihapuskan</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Kepemilikan</label>
                <select name="ownership_status" class="form-select">
                    <option value="owned">Milik Nukari</option>
                    <option value="leased">Disewa Nukari</option>
                    <option value="borrowed">Dipinjam</option>
                    <option value="rented_out">Disewakan Keluar</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jenis Penggunaan</label>
                <select name="use_type" class="form-select">
                    <option value="internal">Internal</option>
                    <option value="rental">Untuk Disewakan</option>
                    <option value="resale">Untuk Dijual Kembali</option>
                    <option value="project">Project</option>
                    <option value="mixed">Campuran</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Lokasi</label>
                <input name="location" class="form-control" placeholder="Workshop / Office / Project">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Penanggung Jawab</label>
                <select name="responsible_employee_id" class="form-select">
                    <option value="">- Tidak ada -</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id']; ?>"><?= htmlspecialchars($e['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Foto Utama</label>
                <input type="file" name="photo" class="form-control" accept="image/*">
                <div class="small text-muted mt-1">Foto utama akan tampil di daftar aset.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Foto Tambahan</label>
                <input type="file" name="asset_photos[]" class="form-control" accept="image/*" multiple>
                <div class="small text-muted mt-1">Bisa upload beberapa foto dokumentasi aset.</div>
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Catatan</label>
                <textarea name="notes" class="form-control" rows="4" placeholder="Nomor seri, kondisi detail, garansi, atau catatan lain."></textarea>
            </div>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-nukari rounded-pill px-4"><i class="bi bi-save me-1"></i> Simpan Aset</button>
                <a class="btn btn-outline-secondary rounded-pill px-4" href="<?= base_url('pages/assets/index.php'); ?>">Batal</a>
            </div>
        </form>
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
