<?php
require_once __DIR__ . '/../../includes/auth.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    redirect('pages/projects/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

$clients = $pdo->query("SELECT id, client_name FROM clients ORDER BY client_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$employees = $pdo->query("SELECT id, full_name FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Edit Project - Nukari';
$error = '';

function nkr_to_number($value)
{
    if ($value === null || $value === '') return 0;
    return (float) preg_replace('/[^0-9]/', '', (string) $value);
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $project_name = clean_input($_POST['project_name'] ?? '');
    $project_code = clean_input($_POST['project_code'] ?? '');
    $client_id = $_POST['client_id'] ?? null;
    $leader_id = $_POST['leader_id'] ?? null;
    $project_type = in_array(($_POST['project_type'] ?? 'one_time'), ['one_time', 'ongoing'], true) ? $_POST['project_type'] : 'one_time';
    $project_location = clean_input($_POST['project_location'] ?? '');
    $start_date = $_POST['start_date'] ?: null;
    $end_date = $_POST['end_date'] ?: null;
    $contract_value = nkr_to_number($_POST['contract_value'] ?? 0);
    $contract_number = clean_input($_POST['contract_number'] ?? '');
    $contract_start_date = $_POST['contract_start_date'] ?: null;
    $contract_end_date = $_POST['contract_end_date'] ?: null;
    $billing_cycle = in_array(($_POST['billing_cycle'] ?? 'none'), ['none', 'monthly', 'quarterly', 'semi_annual', 'annual', 'custom'], true) ? $_POST['billing_cycle'] : 'none';
    $billing_amount = nkr_to_number($_POST['billing_amount'] ?? 0);
    $billing_notes = clean_input($_POST['billing_notes'] ?? '');
    $work_schedule_type = in_array(($_POST['work_schedule_type'] ?? 'project_saturday_half'), ['workshop', 'project_saturday_half', 'custom'], true) ? $_POST['work_schedule_type'] : 'project_saturday_half';
    $saturday_work_value = (float)str_replace(',', '.', (string)($_POST['saturday_work_value'] ?? '0.5'));
    $saturday_work_value = max(0, min(1, $saturday_work_value));
    $sunday_work_value = (float)str_replace(',', '.', (string)($_POST['sunday_work_value'] ?? '0'));
    $sunday_work_value = max(0, min(1, $sunday_work_value));
    $status = $_POST['status'] ?? 'planning';
    $notes = clean_input($_POST['notes'] ?? '');

    if ($project_name === '') {
        $error = 'Nama project wajib diisi.';
    } elseif ($start_date && $end_date && strtotime($end_date) < strtotime($start_date)) {
        $error = 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.';
    } elseif ($contract_start_date && $contract_end_date && strtotime($contract_end_date) < strtotime($contract_start_date)) {
        $error = 'Tanggal akhir kontrak tidak boleh lebih awal dari tanggal mulai kontrak.';
    } else {
        $stmt = $pdo->prepare("
            UPDATE projects SET
                project_name = :project_name,
                project_code = :project_code,
                client_id = :client_id,
                leader_id = :leader_id,
                project_type = :project_type,
                project_location = :project_location,
                start_date = :start_date,
                end_date = :end_date,
                contract_value = :contract_value,
                contract_number = :contract_number,
                contract_start_date = :contract_start_date,
                contract_end_date = :contract_end_date,
                billing_cycle = :billing_cycle,
                billing_amount = :billing_amount,
                billing_notes = :billing_notes,
                work_schedule_type = :work_schedule_type,
                saturday_work_value = :saturday_work_value,
                sunday_work_value = :sunday_work_value,
                status = :status,
                notes = :notes
            WHERE id = :id
        ");

        $stmt->execute([
            ':project_name' => $project_name,
            ':project_code' => $project_code ?: null,
            ':client_id' => $client_id ?: null,
            ':leader_id' => $leader_id ?: null,
            ':project_type' => $project_type,
            ':project_location' => $project_location ?: null,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':contract_value' => $contract_value,
            ':contract_number' => $contract_number ?: null,
            ':contract_start_date' => $contract_start_date,
            ':contract_end_date' => $contract_end_date,
            ':billing_cycle' => $billing_cycle,
            ':billing_amount' => $billing_amount,
            ':billing_notes' => $billing_notes ?: null,
            ':work_schedule_type' => $work_schedule_type,
            ':saturday_work_value' => $saturday_work_value,
            ':sunday_work_value' => $sunday_work_value,
            ':status' => $status,
            ':notes' => $notes ?: null,
            ':id' => $id
        ]);

        redirect('pages/projects/index.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Edit Project</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($project['project_name']); ?></p>
        </div>

        <a href="<?= base_url('pages/projects/index.php'); ?>" class="btn btn-outline-success">
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
                <form method="POST" class="row g-3">

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Nama Project</label>
                        <input
                            type="text"
                            name="project_name"
                            class="form-control"
                            value="<?= htmlspecialchars($project['project_name']); ?>"
                            required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Kode Project</label>
                        <input
                            type="text"
                            name="project_code"
                            class="form-control"
                            value="<?= htmlspecialchars($project['project_code'] ?? ''); ?>"
                            placeholder="Contoh: NKR-PRJ-001">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Client</label>
                        <select name="client_id" class="form-select">
                            <option value="">-- Pilih Client --</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id']; ?>" <?= ($project['client_id'] == $c['id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($c['client_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Project Leader</label>
                        <select name="leader_id" class="form-select">
                            <option value="">-- Pilih Leader --</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= $e['id']; ?>" <?= ($project['leader_id'] == $e['id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($e['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Tipe Project</label>
                        <select name="project_type" class="form-select js-project-type">
                            <option value="one_time" <?= ($project['project_type'] === 'one_time') ? 'selected' : ''; ?>>
                                One Time Project / Sekali Jalan
                            </option>
                            <option value="ongoing" <?= ($project['project_type'] === 'ongoing') ? 'selected' : ''; ?>>
                                Contract Project / Kontrak Berkelanjutan
                            </option>
                        </select>
                        <small class="text-muted">One Time untuk pekerjaan selesai sekali; Contract untuk kerja berulang sesuai periode kontrak.</small>
                    </div>

                    <div class="col-md-12 contract-fields">
                        <div class="p-3 rounded-4 border bg-light-subtle">
                            <div class="fw-bold mb-2"><i class="bi bi-file-earmark-text me-1"></i> Data Kontrak Berkelanjutan</div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Nomor Kontrak</label>
                                    <input type="text" name="contract_number" class="form-control" value="<?= e($project['contract_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Mulai Kontrak</label>
                                    <input type="date" name="contract_start_date" class="form-control" value="<?= e($project['contract_start_date'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Akhir Kontrak</label>
                                    <input type="date" name="contract_end_date" class="form-control" value="<?= e($project['contract_end_date'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Siklus Tagihan</label>
                                    <select name="billing_cycle" class="form-select">
                                        <option value="none" <?= (($project['billing_cycle'] ?? 'none') === 'none') ? 'selected' : ''; ?>>Tidak Berulang</option>
                                        <option value="monthly" <?= (($project['billing_cycle'] ?? '') === 'monthly') ? 'selected' : ''; ?>>Bulanan</option>
                                        <option value="quarterly" <?= (($project['billing_cycle'] ?? '') === 'quarterly') ? 'selected' : ''; ?>>Triwulan</option>
                                        <option value="semi_annual" <?= (($project['billing_cycle'] ?? '') === 'semi_annual') ? 'selected' : ''; ?>>Semester</option>
                                        <option value="annual" <?= (($project['billing_cycle'] ?? '') === 'annual') ? 'selected' : ''; ?>>Tahunan</option>
                                        <option value="custom" <?= (($project['billing_cycle'] ?? '') === 'custom') ? 'selected' : ''; ?>>Custom</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Nominal Per Tagihan</label>
                                    <input type="text" name="billing_amount" class="form-control" value="<?= number_format((float)($project['billing_amount'] ?? 0), 0, ',', '.'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Catatan Billing</label>
                                    <input type="text" name="billing_notes" class="form-control" value="<?= e($project['billing_notes'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Lokasi Project</label>
                        <input
                            type="text"
                            name="project_location"
                            class="form-control"
                            value="<?= htmlspecialchars($project['project_location'] ?? ''); ?>"
                            placeholder="Contoh: Serangan, Denpasar, Bali">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Tanggal Mulai</label>
                        <input
                            type="date"
                            name="start_date"
                            class="form-control"
                            value="<?= htmlspecialchars($project['start_date'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Tanggal Selesai</label>
                        <input
                            type="date"
                            name="end_date"
                            class="form-control"
                            value="<?= htmlspecialchars($project['end_date'] ?? ''); ?>">
                    </div>

                    <div class="col-md-12">
                        <div class="p-3 rounded-4 border bg-light-subtle">
                            <div class="fw-bold mb-2"><i class="bi bi-calendar-week me-1"></i> Kalender Kerja Project</div>
                            <p class="text-muted small mb-3">Digunakan untuk menghitung alokasi biaya employee internal ke payroll. Workshop default libur Sabtu & Minggu, sedangkan project dapat memakai Sabtu setengah hari.</p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Pola Kerja</label>
                                    <?php $scheduleType = $project['work_schedule_type'] ?? 'project_saturday_half'; ?>
                                    <select name="work_schedule_type" class="form-select js-work-schedule-type">
                                        <option value="project_saturday_half" <?= $scheduleType === 'project_saturday_half' ? 'selected' : ''; ?>>Project: Minggu Libur, Sabtu 1/2 Hari</option>
                                        <option value="workshop" <?= $scheduleType === 'workshop' ? 'selected' : ''; ?>>Workshop: Sabtu & Minggu Libur</option>
                                        <option value="custom" <?= $scheduleType === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Nilai Sabtu</label>
                                    <input type="number" step="0.25" min="0" max="1" name="saturday_work_value" class="form-control" value="<?= htmlspecialchars((string)($project['saturday_work_value'] ?? '0.5')); ?>">
                                    <small class="text-muted">0 = libur, 0.5 = setengah hari, 1 = penuh.</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Nilai Minggu</label>
                                    <input type="number" step="0.25" min="0" max="1" name="sunday_work_value" class="form-control" value="<?= htmlspecialchars((string)($project['sunday_work_value'] ?? '0')); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Nilai Kontrak</label>
                        <input
                            type="text"
                            class="form-control currency-input"
                            data-target="contract_value"
                            value="Rp <?= number_format((float) $project['contract_value'], 0, ',', '.'); ?>">

                        <input
                            type="hidden"
                            name="contract_value"
                            value="<?= (float) $project['contract_value']; ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="planning" <?= ($project['status'] === 'planning') ? 'selected' : ''; ?>>Planning</option>
                            <option value="ongoing" <?= ($project['status'] === 'ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="completed" <?= ($project['status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?= ($project['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea
                            name="notes"
                            class="form-control"
                            rows="4"
                            placeholder="Catatan tambahan atau kesepakatan khusus."><?= htmlspecialchars($project['notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-nukari">
                            <i class="bi bi-save me-1"></i> Update Project
                        </button>

                        <a href="<?= base_url('pages/projects/index.php'); ?>" class="btn btn-light">
                            Batal
                        </a>
                    </div>

                </form>
            </div>
        </div>

        <div class="col-lg-4">

            <div class="panel">
                <h5 class="fw-bold mb-3">
                    <i class="bi bi-info-circle me-1"></i> Panduan Edit Project
                </h5>

                <div class="mb-3">
                    <div class="fw-bold text-primary">One Time</div>
                    <small class="text-muted">
                        Project sekali selesai, seperti pembuatan patung, produk khusus, atau pekerjaan berdasarkan satu kontrak.
                    </small>
                </div>

                <div class="mb-3">
                    <div class="fw-bold text-success">Ongoing</div>
                    <small class="text-muted">
                        Project berkelanjutan, seperti maintenance taman, pengelolaan area, atau pekerjaan rutin.
                    </small>
                </div>

                <hr>

                <div class="mb-3">
                    <div class="fw-bold text-warning">Nilai Kontrak</div>
                    <small class="text-muted">
                        Perubahan nilai kontrak akan langsung memengaruhi perhitungan profit dan laporan project.
                    </small>
                </div>

                <div class="mb-3">
                    <div class="fw-bold text-success">Tanggal Project</div>
                    <small class="text-muted">
                        Tanggal mulai dan selesai membantu memantau durasi, progress, dan deadline project.
                    </small>
                </div>

                <div class="mb-3">
                    <div class="fw-bold text-success">Lokasi Project</div>
                    <small class="text-muted">
                        Lokasi memudahkan admin dan tim lapangan mengetahui tempat pekerjaan.
                    </small>
                </div>

                <div>
                    <div class="fw-bold text-danger">Status</div>
                    <small class="text-muted">
                        Gunakan completed jika project sudah selesai. Gunakan cancelled jika project dibatalkan tetapi tetap ingin disimpan sebagai arsip.
                    </small>
                </div>

            </div>

        </div>

    </div>

</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.querySelector('.js-project-type');
    const contractFields = document.querySelector('.contract-fields');

    if (!typeSelect || !contractFields) return;

    const syncContractFields = function () {
        contractFields.style.display = typeSelect.value === 'ongoing' ? '' : 'none';
    };

    typeSelect.addEventListener('change', syncContractFields);
    syncContractFields();
});
</script>
