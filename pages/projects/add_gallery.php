<?php
require_once __DIR__ . '/../../includes/auth.php';

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

$pageTitle = 'Tambah Visual Project - Nukari';
$error = '';

function uploadProjectGallery($files, $project_id, $image_type, $caption, $pdo)
{
    if (!isset($files) || empty($files['name'][0])) {
        throw new Exception('Minimal upload 1 gambar.');
    }

    $uploadDir = __DIR__ . '/../../uploads/project_galleries/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $maxSize = 5 * 1024 * 1024;

    foreach ($files['name'] as $key => $name) {
        if ($files['error'][$key] !== UPLOAD_ERR_OK) {
            throw new Exception('Ada gambar yang gagal diupload.');
        }

        if ($files['size'][$key] > $maxSize) {
            throw new Exception('Ukuran setiap gambar maksimal 5MB.');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt)) {
            throw new Exception('Format gambar harus JPG, JPEG, PNG, atau WEBP.');
        }

        $fileName = 'project_' . $project_id . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $targetPath = $uploadDir . $fileName;

        if (!move_uploaded_file($files['tmp_name'][$key], $targetPath)) {
            throw new Exception('Gagal menyimpan gambar.');
        }

        $imagePath = 'uploads/project_galleries/' . $fileName;

        $stmt = $pdo->prepare("
            INSERT INTO project_galleries
            (project_id, image_type, image_path, caption)
            VALUES
            (:project_id, :image_type, :image_path, :caption)
        ");

        $stmt->execute([
            ':project_id' => $project_id,
            ':image_type' => $image_type,
            ':image_path' => $imagePath,
            ':caption' => $caption ?: null
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $image_type = clean_input($_POST['image_type'] ?? 'other');
    $caption = clean_input($_POST['caption'] ?? '');

    try {
        uploadProjectGallery($_FILES['gallery_images'] ?? null, $project_id, $image_type, $caption, $pdo);

        redirect('pages/projects/detail.php?id=' . $project_id . '&tab=gallery#tab-gallery');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Tambah Visual Project</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=gallery#tab-gallery'); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4">
            <?= htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="panel">
                <form method="POST" enctype="multipart/form-data" class="row g-3">

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Jenis Visual</label>
                        <select name="image_type" class="form-select">
                            <option value="design">Foto Design / Konsep</option>
                            <option value="progress">Foto Progress / Proses</option>
                            <option value="final">Foto Hasil Jadi</option>
                            <option value="before">Foto Sebelum</option>
                            <option value="after">Foto Sesudah</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Upload Gambar</label>
                        <input type="file" name="gallery_images[]" class="form-control" accept="image/*" multiple required>
                        <small class="text-muted">Bisa upload lebih dari satu gambar. Format JPG, JPEG, PNG, WEBP. Maksimal 5MB per gambar.</small>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Catatan / Caption</label>
                        <textarea name="caption" rows="3" class="form-control" placeholder="Contoh: Design awal dari client / hasil jadi setelah pemasangan."></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-nukari">
                            <i class="bi bi-upload me-1"></i> Upload Visual
                        </button>

                        <a href="<?= base_url('pages/projects/detail.php?id=' . $project_id . '&tab=gallery#tab-gallery'); ?>" class="btn btn-light">
                            Batal
                        </a>
                    </div>

                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="panel">
                <h5 class="fw-bold mb-3">Panduan</h5>
                <p class="small text-muted mb-0">
                    Gunakan fitur ini untuk menyimpan dokumentasi project seperti foto design, progress, dan hasil jadi.
                    Data ini berguna sebagai portofolio internal perusahaan.
                </p>
            </div>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>