<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Tambah Dana Talangan - Nukari';
$error = '';

$employees = $pdo->query("SELECT id, full_name FROM employees WHERE employment_status='active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, project_name FROM projects WHERE deleted_at IS NULL ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $employeeId = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
        $requesterName = clean_input($_POST['requester_name'] ?? '');
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $category = clean_input($_POST['category'] ?? '');
        $requestDate = $_POST['request_date'] ?: date('Y-m-d');
        $dueDate = $_POST['due_date'] ?: null;
        $amount = rmb_money_to_number($_POST['amount'] ?? 0);
        $description = clean_input($_POST['description'] ?? '');
        $proofFile = rmb_upload_file('proof_file');

        if ($employeeId) {
            $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $employeeId]);
            $employeeName = $stmt->fetchColumn();
            if ($employeeName && $requesterName === '') {
                $requesterName = $employeeName;
            }
        }

        if ($requesterName === '') {
            throw new Exception('Nama pemohon wajib diisi.');
        }
        if ($amount <= 0) {
            throw new Exception('Nominal harus lebih dari 0.');
        }

        $code = finance_generate_reimbursement_code($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO reimbursements
            (reimbursement_code, employee_id, requester_name, project_id, category, request_date, due_date, amount, paid_amount, description, proof_file, status, created_by, created_at, updated_at)
            VALUES
            (:code, :employee_id, :requester_name, :project_id, :category, :request_date, :due_date, :amount, 0, :description, :proof_file, 'pending', :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            ':code' => $code,
            ':employee_id' => $employeeId,
            ':requester_name' => $requesterName,
            ':project_id' => $projectId,
            ':category' => $category ?: null,
            ':request_date' => $requestDate,
            ':due_date' => $dueDate,
            ':amount' => $amount,
            ':description' => $description ?: null,
            ':proof_file' => $proofFile,
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);

        redirect('pages/reimbursements/index.php?message=Pengajuan dana talangan berhasil dibuat');
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
            <h1 class="fw-bold mb-1">Tambah Dana Talangan</h1>
            <p class="text-muted mb-0">Catat pengeluaran yang dibayar dulu dengan uang pribadi.</p>
        </div>
        <a href="<?= base_url('pages/reimbursements/index.php'); ?>" class="btn btn-outline-success">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= rmb_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Pilih Karyawan / Internal</label>
                <select name="employee_id" class="form-select" id="employeeSelect">
                    <option value="">Tidak dari data karyawan</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id']; ?>" data-name="<?= rmb_e($e['full_name']); ?>"><?= rmb_e($e['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Boleh kosong jika pemohon bukan karyawan.</small>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Nama Pemohon</label>
                <input type="text" name="requester_name" id="requesterName" class="form-control" required placeholder="Contoh: Pak Maja, Ryan, Lirra">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Project</label>
                <select name="project_id" class="form-select">
                    <option value="">Tidak terkait project</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id']; ?>"><?= rmb_e($p['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Kategori</label>
                <input type="text" name="category" class="form-control" placeholder="Material, Transport, Operasional, Konsumsi, dll">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal Pengajuan</label>
                <input type="date" name="request_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jatuh Tempo Penggantian</label>
                <input type="date" name="due_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nominal</label>
                <input type="text" name="amount" class="form-control money-input" required placeholder="Contoh: 1.500.000">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Upload Bukti / Nota</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="description" rows="4" class="form-control" placeholder="Jelaskan dana talangan ini untuk apa..."></textarea>
            </div>

            <div class="col-12 text-end">
                <button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Pengajuan</button>
            </div>
        </form>
    </div>
</main>

<script>
document.querySelectorAll('.money-input').forEach(function(input) {
    input.addEventListener('input', function() {
        let value = this.value.replace(/\D/g, '');
        this.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    });
});

document.getElementById('employeeSelect').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const name = selected.getAttribute('data-name') || '';
    if (name) document.getElementById('requesterName').value = name;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
