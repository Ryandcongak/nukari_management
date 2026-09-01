<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Edit Dana Talangan - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM reimbursements WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    redirect('pages/reimbursements/index.php?error=Data tidak ditemukan');
}

if (in_array($r['status'], ['partial','paid'], true)) {
    redirect('pages/reimbursements/detail.php?id=' . $id . '&error=Data yang sudah memiliki pembayaran tidak bisa diedit');
}

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
        $proofFile = rmb_upload_file('proof_file', $r['proof_file']);

        if ($employeeId) {
            $stmt = $pdo->prepare("SELECT full_name FROM employees WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $employeeId]);
            $employeeName = $stmt->fetchColumn();
            if ($employeeName && $requesterName === '') $requesterName = $employeeName;
        }

        if ($requesterName === '') throw new Exception('Nama pemohon wajib diisi.');
        if ($amount <= 0) throw new Exception('Nominal harus lebih dari 0.');
        if (!empty($r['debt_id']) && (float)$r['paid_amount'] > $amount) {
            throw new Exception('Nominal tidak boleh lebih kecil dari jumlah yang sudah dibayar.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE reimbursements
            SET employee_id = :employee_id,
                requester_name = :requester_name,
                project_id = :project_id,
                category = :category,
                request_date = :request_date,
                due_date = :due_date,
                amount = :amount,
                description = :description,
                proof_file = :proof_file,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':employee_id' => $employeeId,
            ':requester_name' => $requesterName,
            ':project_id' => $projectId,
            ':category' => $category ?: null,
            ':request_date' => $requestDate,
            ':due_date' => $dueDate,
            ':amount' => $amount,
            ':description' => $description ?: null,
            ':proof_file' => $proofFile,
            ':id' => $id
        ]);

        if (!empty($r['debt_id']) && $r['status'] === 'approved') {
            $stmt = $pdo->prepare("
                UPDATE debts
                SET related_type = :related_type,
                    related_id = :related_id,
                    category = :category,
                    issue_date = :issue_date,
                    related_name = :related_name,
                    project_id = :project_id,
                    amount = :amount,
                    due_date = :due_date,
                    proof_file = :proof_file,
                    reference_code = :reference_code,
                    description = :description,
                    updated_at = NOW()
                WHERE id = :debt_id
            ");
            $stmt->execute([
                ':related_type' => $employeeId ? 'employee' : 'personal',
                ':related_id' => $employeeId,
                ':category' => $category ?: 'Dana Talangan',
                ':issue_date' => $requestDate,
                ':related_name' => $requesterName,
                ':project_id' => $projectId,
                ':amount' => $amount,
                ':due_date' => $dueDate,
                ':proof_file' => $proofFile,
                ':reference_code' => $r['reimbursement_code'],
                ':description' => 'Hutang otomatis dari dana talangan: ' . $r['reimbursement_code'],
                ':debt_id' => (int)$r['debt_id']
            ]);
            finance_update_debt_status($pdo, (int)$r['debt_id']);
        }

        $pdo->commit();

        redirect('pages/reimbursements/detail.php?id=' . $id . '&message=Data berhasil diperbarui');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Edit Dana Talangan</h1>
            <p class="text-muted mb-0"><?= rmb_e($r['reimbursement_code']); ?></p>
        </div>
        <a href="<?= base_url('pages/reimbursements/detail.php?id=' . $id); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= rmb_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Pilih Karyawan / Internal</label>
                <select name="employee_id" class="form-select" id="employeeSelect">
                    <option value="">Tidak dari data karyawan</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id']; ?>" data-name="<?= rmb_e($e['full_name']); ?>" <?= (int)$r['employee_id'] === (int)$e['id'] ? 'selected' : ''; ?>><?= rmb_e($e['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Nama Pemohon</label>
                <input type="text" name="requester_name" id="requesterName" class="form-control" required value="<?= rmb_e($r['requester_name']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Project</label>
                <select name="project_id" class="form-select">
                    <option value="">Tidak terkait project</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id']; ?>" <?= (int)$r['project_id'] === (int)$p['id'] ? 'selected' : ''; ?>><?= rmb_e($p['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Kategori</label>
                <input type="text" name="category" class="form-control" value="<?= rmb_e($r['category']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tanggal</label>
                <input type="date" name="request_date" class="form-control" value="<?= rmb_e($r['request_date']); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jatuh Tempo</label>
                <input type="date" name="due_date" class="form-control" value="<?= rmb_e($r['due_date']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nominal</label>
                <input type="text" name="amount" class="form-control money-input" value="<?= number_format((float)$r['amount'], 0, ',', '.'); ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Ganti Bukti</label>
                <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
                <?php if ($r['proof_file']): ?><small><a target="_blank" href="<?= base_url($r['proof_file']); ?>">Lihat file saat ini</a></small><?php endif; ?>
            </div>
            <div class="col-md-12">
                <label class="form-label fw-semibold">Deskripsi</label>
                <textarea name="description" rows="4" class="form-control"><?= rmb_e($r['description']); ?></textarea>
            </div>
            <div class="col-12 text-end">
                <button class="btn btn-nukari"><i class="bi bi-save me-1"></i> Simpan Perubahan</button>
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
