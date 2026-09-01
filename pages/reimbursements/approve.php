<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = 'Approve Dana Talangan - Nukari';
$error = '';
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM reimbursements WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    redirect('pages/reimbursements/index.php?error=Data tidak ditemukan');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['approval_action'] ?? 'approve';
        $notes = clean_input($_POST['approval_notes'] ?? '');

        $pdo->beginTransaction();

        if ($action === 'reject') {
            $stmt = $pdo->prepare("
                UPDATE reimbursements
                SET status = 'rejected',
                    approved_by = :approved_by,
                    approved_at = NOW(),
                    approval_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id AND status = 'pending'
            ");
            $stmt->execute([
                ':approved_by' => $_SESSION['user_id'] ?? null,
                ':notes' => $notes ?: null,
                ':id' => $id
            ]);

            $pdo->commit();
            redirect('pages/reimbursements/detail.php?id=' . $id . '&message=Dana talangan ditolak');
        }

        $stmt = $pdo->prepare("
            UPDATE reimbursements
            SET status = 'approved',
                approved_by = :approved_by,
                approved_at = NOW(),
                approval_notes = :notes,
                updated_at = NOW()
            WHERE id = :id AND status = 'pending'
        ");
        $stmt->execute([
            ':approved_by' => $_SESSION['user_id'] ?? null,
            ':notes' => $notes ?: null,
            ':id' => $id
        ]);

        finance_create_debt_from_reimbursement($pdo, $id, $_SESSION['user_id'] ?? null);

        $pdo->commit();

        redirect('pages/reimbursements/detail.php?id=' . $id . '&message=Dana talangan disetujui dan otomatis menjadi hutang perusahaan');
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
            <h1 class="fw-bold mb-1">Approve Dana Talangan</h1>
            <p class="text-muted mb-0"><?= rmb_e($r['reimbursement_code']); ?></p>
        </div>
        <a href="<?= base_url('pages/reimbursements/detail.php?id=' . $id); ?>" class="btn btn-outline-success"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= rmb_e($error); ?></div><?php endif; ?>

    <div class="panel">
        <div class="alert alert-info rounded-4">
            Jika disetujui, sistem otomatis membuat <strong>Hutang Perusahaan</strong> pada modul Hutang & Piutang.
        </div>

        <table class="table table-borderless">
            <tr><td class="text-muted" style="width:220px">Pemohon</td><td class="fw-semibold"><?= rmb_e($r['requester_name']); ?></td></tr>
            <tr><td class="text-muted">Nominal</td><td class="fw-bold"><?= rupiah($r['amount']); ?></td></tr>
            <tr><td class="text-muted">Tanggal</td><td><?= date('d M Y', strtotime($r['request_date'])); ?></td></tr>
            <tr><td class="text-muted">Kategori</td><td><?= rmb_e($r['category'] ?: '-'); ?></td></tr>
            <tr><td class="text-muted">Deskripsi</td><td><?= nl2br(rmb_e($r['description'] ?: '-')); ?></td></tr>
        </table>

        <form method="POST" class="row g-3">
            <div class="col-md-12">
                <label class="form-label fw-semibold">Catatan Approval</label>
                <textarea name="approval_notes" rows="4" class="form-control" placeholder="Catatan persetujuan atau penolakan..."></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
                <?php if ($r['status'] === 'pending'): ?>
                    <button name="approval_action" value="reject" class="btn btn-outline-danger" onclick="return confirm('Yakin ingin menolak pengajuan ini?')">Tolak</button>
                    <button name="approval_action" value="approve" class="btn btn-nukari">Setujui</button>
                <?php else: ?>
                    <span class="text-muted">Data ini sudah tidak berstatus pending.</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
