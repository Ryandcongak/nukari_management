<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';

$pageTitle='Tambah Produk - Nukari';

function product_upload_photo($field = 'photo') {
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $tmp = $_FILES[$field]['tmp_name'];
    $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : ($_FILES[$field]['type'] ?? '');
    if (!isset($allowed[$mime])) return null;

    $dir = __DIR__ . '/../../uploads/products';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $name = 'product-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $dest)) return null;

    return 'uploads/products/' . $name;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $code = trim($_POST['product_code']??'') ?: product_code('PRD');
    $barcode = trim($_POST['barcode_code'] ?? '') ?: $code;
    $hpp = product_parse_number($_POST['hpp_price']??0);
    $sell = product_parse_number($_POST['selling_price']??0);
    $stock = product_parse_number($_POST['stock']??0);
    $minStock = product_parse_number($_POST['minimum_stock']??0);
    $photo = product_upload_photo('photo');

    $stmt=$pdo->prepare("INSERT INTO products
        (product_code,barcode_code,barcode_type,product_name,category,product_type,description,production_cost,hpp_price,selling_price,profit_estimate,stock,unit,minimum_stock,photo,marketplace_tokopedia,marketplace_shopee,contribution_percent,contribution_program_name,contribution_event_name,contribution_event_date,status,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        $code,
        $barcode,
        'code128',
        trim($_POST['product_name']),
        trim($_POST['category']??''),
        $_POST['product_type']??'recycle',
        trim($_POST['description']??''),
        $hpp,
        $hpp,
        $sell,
        $sell-$hpp,
        $stock,
        trim($_POST['unit']??'pcs'),
        $minStock,
        $photo,
        trim($_POST['marketplace_tokopedia']??''),
        trim($_POST['marketplace_shopee']??''),
        product_parse_number($_POST['contribution_percent'] ?? 0),
        trim($_POST['contribution_program_name'] ?? ''),
        trim($_POST['contribution_event_name'] ?? ''),
        ($_POST['contribution_event_date'] ?? '') ?: null,
        $_POST['status']??'active'
    ]);
    header('Location: index.php');exit;
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
.photo-upload-box{border:1px dashed #b9c7d8;background:#f8fafc;border-radius:18px;padding:18px;text-align:center;height:100%;min-height:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.photo-upload-box .icon{width:74px;height:74px;border-radius:22px;background:#e8fff1;color:#11874f;display:flex;align-items:center;justify-content:center;font-size:30px;margin-bottom:12px;}
.input-hint{font-size:12px;color:#64748b;margin-top:5px;}
</style>
<main class="main-content">
<div class="container-fluid product-page py-3 product-form-wrap">
    <?php require_once __DIR__.'/_nav.php'; ?>
    <div class="form-card-pro">
        <div class="form-card-head">
            <div>
                <h5>Tambah Produk Jadi</h5>
                <div class="text-muted small">Lengkapi produk recycle, eco enzyme, custom, atau service.</div>
            </div>
            <a href="index.php" class="btn btn-outline-secondary btn-rounded">Kembali</a>
        </div>
        <div class="form-card-body">
            <form method="post" enctype="multipart/form-data" class="row g-4">
                <div class="col-lg-4">
                    <div class="photo-upload-box">
                        <div class="icon"><i class="bi bi-image"></i></div>
                        <label class="form-label fw-bold">Foto Produk</label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                        <div class="input-hint">Format: JPG, PNG, WEBP. Foto membantu tim mengenali produk di dashboard.</div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Kode Produk</label><input name="product_code" class="form-control" placeholder="Otomatis jika kosong"></div>
                        <div class="col-md-4"><label class="form-label">Kode Barcode</label><input name="barcode_code" class="form-control" placeholder="Otomatis mengikuti kode produk"><div class="input-hint">Kode ini yang akan discan kasir/bazar.</div></div>
                        <div class="col-md-4"><label class="form-label">Nama Produk *</label><input name="product_name" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Kategori</label><input name="category" class="form-control" placeholder="Kerajinan, Eco Enzyme, dll"></div>
                        <div class="col-md-4"><label class="form-label">Tipe Produk</label><select name="product_type" class="form-select"><option value="recycle">Recycle</option><option value="eco_enzyme">Eco Enzyme</option><option value="custom">Custom</option><option value="service">Service</option><option value="other">Other</option></select></div>
                        <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                        <div class="col-md-3"><label class="form-label">Stok Awal</label><input name="stock" class="form-control qty-input" value="0"></div>
                        <div class="col-md-3"><label class="form-label">Satuan</label><input name="unit" class="form-control" value="pcs"></div>
                        <div class="col-md-3"><label class="form-label">Minimum Stok</label><input name="minimum_stock" class="form-control qty-input" value="0"></div>
                        <div class="col-md-3"><label class="form-label">HPP / Unit</label><input name="hpp_price" class="form-control money-input" value="0"></div>
                        <div class="col-md-4"><label class="form-label">Harga Jual</label><input name="selling_price" class="form-control money-input" value="0"></div>
                        <div class="col-md-4"><label class="form-label">Link Tokopedia</label><input name="marketplace_tokopedia" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Link Shopee</label><input name="marketplace_shopee" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Potongan Program Sosial (%)</label><input name="contribution_percent" class="form-control qty-input" value="0"><div class="input-hint">Contoh: 5 untuk donasi 5% dari penjualan.</div></div>
                        <div class="col-md-3"><label class="form-label">Nama Program</label><input name="contribution_program_name" class="form-control" placeholder="Penanaman Mangrove"></div>
                        <div class="col-md-3"><label class="form-label">Nama Event</label><input name="contribution_event_name" class="form-control" placeholder="Mangrove Serangan 2027"></div>
                        <div class="col-md-3"><label class="form-label">Tanggal Event</label><input type="date" name="contribution_event_date" class="form-control"></div>
                        <div class="col-12"><label class="form-label">Deskripsi / Catatan</label><textarea name="description" class="form-control" rows="4"></textarea></div>
                        <div class="col-12 d-flex gap-2"><button class="btn btn-success btn-rounded"><i class="bi bi-save me-1"></i> Simpan</button><a href="index.php" class="btn btn-outline-secondary btn-rounded">Kembali</a></div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
