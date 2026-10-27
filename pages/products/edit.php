<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';
require_once __DIR__ . '/../../includes/master_data_helper.php';

$id=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT * FROM products WHERE id=?");
$stmt->execute([$id]);
$p=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$p){header('Location:index.php');exit;}

$pageTitle='Edit Produk - Nukari';
$error = '';

function product_upload_photo_edit($old = null, $field = 'photo') {
    $uploaded = upload_store_file($_FILES[$field] ?? [], 'products', 'product', upload_image_mimes(true));
    return $uploaded ?? $old;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $newPhoto = null;
    try {
    $basePrice=product_parse_number($_POST['base_price']??0);
    $sell=product_parse_number($_POST['selling_price']??0);
    $barcode = trim($_POST['barcode_code'] ?? '') ?: trim($_POST['product_code'] ?? ($p['product_code'] ?? ''));
    $photo = product_upload_photo_edit($p['photo'] ?? null, 'photo');
    if ($photo !== ($p['photo'] ?? null)) $newPhoto = $photo;

    $stmt=$pdo->prepare("UPDATE products SET
        product_code=?,barcode_code=?,barcode_type=?,product_name=?,category=?,product_type=?,description=?,base_price=?,track_stock=?,production_cost=?,hpp_price=?,selling_price=?,profit_estimate=?,stock=?,unit=?,minimum_stock=?,photo=?,marketplace_tokopedia=?,marketplace_shopee=?,contribution_percent=?,contribution_program_name=?,contribution_event_name=?,contribution_event_date=?,status=?,updated_at=NOW()
        WHERE id=?");
    $stmt->execute([
        trim($_POST['product_code']),
        $barcode,
        'code128',
        trim($_POST['product_name']),
        trim($_POST['category']??''),
        $_POST['product_type']??($p['product_type']??'other'),
        trim($_POST['description']??''),
        $basePrice,
        isset($_POST['track_stock']) ? 1 : 0,
        $basePrice,
        $basePrice,
        $sell,
        $sell-$basePrice,
        product_parse_number($_POST['stock']??0),
        trim($_POST['unit']??'pcs'),
        product_parse_number($_POST['minimum_stock']??0),
        $photo,
        trim($_POST['marketplace_tokopedia']??''),
        trim($_POST['marketplace_shopee']??''),
        product_parse_number($_POST['contribution_percent'] ?? 0),
        trim($_POST['contribution_program_name'] ?? ''),
        trim($_POST['contribution_event_name'] ?? ''),
        ($_POST['contribution_event_date'] ?? '') ?: null,
        $_POST['status']??'active',
        $id
    ]);
        if ($newPhoto) upload_delete_file($p['photo'] ?? null);
        header('Location: index.php');exit;
    } catch (Throwable $e) {
        upload_delete_file($newPhoto);
        $error = $e->getMessage();
    }
}

$photoUrl = '';
if (!empty($p['photo'])) {
    $photoUrl = preg_match('~^https?://~i', $p['photo']) ? $p['photo'] : base_url($p['photo']);
}

require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/sidebar.php';
require_once __DIR__.'/_style.php';
?>
<style>
.product-form-wrap{max-width:1180px;margin:0 auto;}
.form-card-pro{background:#fff;border:1px solid #e3ebf2;border-radius:22px;box-shadow:0 16px 40px rgba(15,23,42,.05);overflow:hidden;}
.form-card-head{padding:20px 24px;border-bottom:1px solid #e3ebf2;display:flex;justify-content:space-between;align-items:center;}
.form-card-head h5{font-weight:850;margin:0;}
.form-card-body{padding:24px;}
.photo-upload-box{border:1px dashed #b9c7d8;background:#f8fafc;border-radius:18px;padding:18px;text-align:center;height:100%;min-height:260px;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.photo-preview{width:100%;max-width:260px;height:180px;border-radius:18px;object-fit:cover;border:1px solid #e2e8f0;margin-bottom:12px;background:#fff;}
.photo-empty{width:120px;height:120px;border-radius:30px;background:#e8fff1;color:#11874f;display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:900;margin-bottom:12px;}
.input-hint{font-size:12px;color:#64748b;margin-top:5px;}
</style>
<main class="main-content">
<div class="container-fluid product-page py-3 product-form-wrap">
    <?php require_once __DIR__.'/_nav.php'; ?>
    <div class="form-card-pro">
        <div class="form-card-head">
            <div>
                <h5>Edit Produk</h5>
                <div class="text-muted small"><?= product_safe($p['product_code']); ?> • <?= product_safe($p['product_name']); ?></div>
            </div>
            <a href="index.php" class="btn btn-outline-secondary btn-rounded">Kembali</a>
        </div>
        <div class="form-card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= product_safe($error); ?></div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="row g-4">
                <div class="col-lg-4">
                    <div class="photo-upload-box">
                        <?php if ($photoUrl): ?>
                            <img class="photo-preview" src="<?= product_safe($photoUrl); ?>" alt="<?= product_safe($p['product_name']); ?>">
                        <?php else: ?>
                            <div class="photo-empty"><?= strtoupper(substr($p['product_name'],0,1)); ?></div>
                        <?php endif; ?>
                        <label class="form-label fw-bold">Ganti Foto Produk</label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                        <div class="input-hint">Kosongkan jika tidak ingin mengganti foto.</div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Kode Produk</label><input name="product_code" class="form-control" value="<?= product_safe($p['product_code']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Kode Barcode</label><input name="barcode_code" class="form-control" value="<?= product_safe($p['barcode_code'] ?? $p['product_code']); ?>"><div class="input-hint">Kode ini yang akan discan kasir/bazar.</div></div>
                        <div class="col-md-4"><label class="form-label">Nama Produk *</label><input name="product_name" class="form-control" required value="<?= product_safe($p['product_name']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Kategori</label><input name="category" class="form-control" value="<?= product_safe($p['category']); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" <?= $p['status']==='active'?'selected':''; ?>>Active</option><option value="inactive" <?= $p['status']==='inactive'?'selected':''; ?>>Inactive</option></select></div>
                        <div class="col-md-3"><label class="form-label">Stok</label><input name="stock" class="form-control qty-input" value="<?= product_num($p['stock']??0); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Satuan</label><input name="unit" class="form-control" list="productUnitList" value="<?= product_safe($p['unit']); ?>"><?= master_datalist($pdo,'unit','productUnitList'); ?></div>
                        <div class="col-md-3"><label class="form-label">Minimum Stok</label><input name="minimum_stock" class="form-control qty-input" value="<?= product_num($p['minimum_stock']??0); ?>"></div>
                        <div class="col-md-3"><label class="form-label">Harga Dasar</label><input name="base_price" class="form-control money-input" value="<?= product_num($p['base_price']??$p['hpp_price']??0,2); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Harga Jual</label><input name="selling_price" class="form-control money-input" value="<?= product_num($p['selling_price'],2); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Kontrol Stok</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="track_stock" value="1" <?=($p['track_stock']??1)?'checked':'';?>><label class="form-check-label">Kurangi stok saat terjual</label></div></div>
                        <div class="col-12"><label class="form-label">Deskripsi / Catatan</label><textarea name="description" class="form-control" rows="4"><?= product_safe($p['description']); ?></textarea></div>
                        <div class="col-12 d-flex gap-2"><button class="btn btn-success btn-rounded">Update</button><a href="index.php" class="btn btn-outline-secondary btn-rounded">Kembali</a></div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
