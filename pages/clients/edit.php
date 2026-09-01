<?php
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Edit Client - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
$stmt->execute([':id' => $id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) { redirect('pages/clients/index.php?error=Client tidak ditemukan'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_name = clean_input($_POST['client_name'] ?? '');
    $contact_person = clean_input($_POST['contact_person'] ?? '');
    $contact_role = clean_input($_POST['contact_role'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $address = clean_input($_POST['address'] ?? '');
    $notes = clean_input($_POST['notes'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if ($client_name === '') {
        $error = 'Nama client wajib diisi.';
    } else {
        $stmt = $pdo->prepare("UPDATE clients SET client_name=:client_name, contact_person=:contact_person, contact_role=:contact_role, phone=:phone, email=:email, address=:address, notes=:notes, status=:status, updated_at=NOW() WHERE id=:id");
        $stmt->execute([
            ':client_name' => $client_name,
            ':contact_person' => $contact_person ?: null,
            ':contact_role' => $contact_role ?: null,
            ':phone' => $phone ?: null,
            ':email' => $email ?: null,
            ':address' => $address ?: null,
            ':notes' => $notes ?: null,
            ':status' => in_array($status, ['active','inactive'], true) ? $status : 'active',
            ':id' => $id
        ]);
        redirect('pages/clients/index.php?message=Client berhasil diperbarui');
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h1 class="fw-bold mb-1">Edit Client</h1><p class="text-muted mb-0">Perbarui data pemberi project.</p></div>
        <a href="<?= base_url('pages/clients/index.php'); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <div class="panel"><form method="POST" class="row g-3">
        <div class="col-md-12"><label class="form-label fw-semibold">Nama Client / Perusahaan</label><input type="text" name="client_name" class="form-control" value="<?= htmlspecialchars($client['client_name']); ?>" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($client['contact_person'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Jabatan / Tanggung Jawab</label><input type="text" name="contact_role" class="form-control" value="<?= htmlspecialchars($client['contact_role'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Telepon / WhatsApp</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($client['phone'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($client['email'] ?? ''); ?>"></div>
        <div class="col-md-12"><label class="form-label fw-semibold">Alamat</label><textarea name="address" rows="3" class="form-control"><?= htmlspecialchars($client['address'] ?? ''); ?></textarea></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="active" <?= (($client['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option><option value="inactive" <?= (($client['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option></select></div>
        <div class="col-md-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" rows="4" class="form-control"><?= htmlspecialchars($client['notes'] ?? ''); ?></textarea></div>
        <div class="col-12 text-end"><button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Update</button></div>
    </form></div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
