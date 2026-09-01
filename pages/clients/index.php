<?php
require_once __DIR__ . '/../../includes/auth.php';

$pageTitle = 'Client - Nukari';
$keyword = trim($_GET['q'] ?? '');
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';

$where = '';
$params = [];
if ($keyword !== '') {
    $where = "WHERE client_name LIKE :keyword OR contact_person LIKE :keyword OR phone LIKE :keyword OR email LIKE :keyword OR address LIKE :keyword";
    $params[':keyword'] = '%' . $keyword . '%';
}

$stmt = $pdo->prepare("SELECT * FROM clients $where ORDER BY client_name ASC");
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

function format_contact_phone($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }
    if (substr($digits, 0, 1) === '0') {
        $digits = '62' . substr($digits, 1);
    }
    return $digits;
}

function render_clickable_contact($phone, $email)
{
    $phone = trim((string)$phone);
    $email = trim((string)$email);
    $waNumber = format_contact_phone($phone);

    echo '<div class="d-flex flex-column gap-1">';

    if ($waNumber !== '') {
        echo '<a class="text-decoration-none fw-semibold" target="_blank" href="https://wa.me/' . htmlspecialchars($waNumber) . '">';
        echo '<i class="bi bi-whatsapp me-1 text-success"></i>' . htmlspecialchars($phone);
        echo '</a>';
    } else {
        echo '<span class="text-muted"><i class="bi bi-whatsapp me-1"></i>-</span>';
    }

    if ($email !== '') {
        echo '<a class="text-decoration-none small" href="mailto:' . htmlspecialchars($email) . '">';
        echo '<i class="bi bi-envelope me-1"></i>' . htmlspecialchars($email);
        echo '</a>';
    } else {
        echo '<span class="text-muted small"><i class="bi bi-envelope me-1"></i>-</span>';
    }

    echo '</div>';
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">Client</h1>
            <p class="text-muted mb-0">Kelola data pemberi project, contact person, alamat, dan riwayat komunikasi.</p>
        </div>
        <a href="<?= base_url('pages/clients/add.php'); ?>" class="btn btn-nukari">
            <i class="bi bi-plus-circle me-1"></i> Tambah Client
        </a>
    </div>

    <?php if ($message): ?><div class="alert alert-success rounded-4"><?= htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="panel mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-10">
                <input type="text" name="q" value="<?= htmlspecialchars($keyword); ?>" class="form-control" placeholder="Cari nama client, PIC, telepon, email, atau alamat...">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-success"><i class="bi bi-search me-1"></i> Cari</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Client</th>
                        <th>PIC / Penanggung Jawab</th>
                        <th>Kontak</th>
                        <th>Alamat</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($clients): $no=1; foreach ($clients as $client): ?>
                        <tr>
                            <td><?= $no++; ?></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($client['client_name']); ?></div>
                                <?php if (!empty($client['notes'])): ?><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($client['notes'], 0, 80, '...')); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($client['contact_person'] ?: '-'); ?><br>
                                <small class="text-muted"><?= htmlspecialchars($client['contact_role'] ?? '-'); ?></small>
                            </td>
                            <td>
                                <?php render_clickable_contact($client['phone'] ?? '', $client['email'] ?? ''); ?>
                            </td>
                            <td><?= htmlspecialchars(mb_strimwidth($client['address'] ?? '-', 0, 70, '...')); ?></td>
                            <td>
                                <?php if (($client['status'] ?? 'active') === 'active'): ?>
                                    <span class="badge rounded-pill badge-soft-green">Active</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill badge-soft-red">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= base_url('pages/clients/edit.php?id=' . $client['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square me-1"></i> Edit</a>
                                <a href="<?= base_url('pages/clients/delete.php?id=' . $client['id']); ?>" onclick="return confirm('Yakin ingin menghapus client ini?')" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i> Hapus</a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Belum ada data client.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
