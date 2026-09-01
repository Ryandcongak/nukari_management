<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Tambah Vendor - Nukari';
$error = '';
$categories = ['Kain','Besi','Kayu','Plastik','Alat','Transport','Jasa','Percetakan','Elektrikal','Tanaman','Lainnya'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_name = clean_input($_POST['supplier_name'] ?? '');
    $supplier_category = clean_input($_POST['supplier_category'] ?? '');
    $contact_person = clean_input($_POST['contact_person'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $address = clean_input($_POST['address'] ?? '');
    $notes = clean_input($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? 'active';
    if ($supplier_name === '') { $error = 'Nama vendor wajib diisi.'; }
    else {
        $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_name, supplier_category, contact_person, phone, email, address, notes, status) VALUES (:supplier_name, :supplier_category, :contact_person, :phone, :email, :address, :notes, :status)");
        $stmt->execute([':supplier_name'=>$supplier_name, ':supplier_category'=>$supplier_category ?: null, ':contact_person'=>$contact_person ?: null, ':phone'=>$phone ?: null, ':email'=>$email ?: null, ':address'=>$address ?: null, ':notes'=>$notes ?: null, ':status'=>in_array($status,['active','inactive'],true)?$status:'active']);
        redirect('pages/suppliers/index.php?message=Vendor berhasil ditambahkan');
    }
}
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3"><div><h1 class="fw-bold mb-1">Tambah Vendor</h1><p class="text-muted mb-0">Tambahkan data pemasok barang atau jasa.</p></div><a href="<?= base_url('pages/suppliers/index.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a></div>
<?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
<div class="panel"><form method="POST" class="row g-3">
<div class="col-md-12"><label class="form-label fw-semibold">Nama Vendor / Supplier</label><input type="text" name="supplier_name" class="form-control" required></div>
<div class="col-md-6"><label class="form-label fw-semibold">Kategori Vendor</label><input list="vendorCategories" name="supplier_category" class="form-control" placeholder="Contoh: Kain, Besi, Transport"><datalist id="vendorCategories"><?php foreach($categories as $cat): ?><option value="<?= htmlspecialchars($cat); ?>"><?php endforeach; ?></datalist></div>
<div class="col-md-6"><label class="form-label fw-semibold">Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Telepon / WhatsApp</label><input type="text" name="phone" class="form-control"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" class="form-control"></div>
<div class="col-md-12"><label class="form-label fw-semibold">Alamat</label><textarea name="address" rows="3" class="form-control"></textarea></div>
<div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
<div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" rows="4" class="form-control"></textarea></div>
<div class="col-12 text-end"><button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan</button></div>
</form></div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
