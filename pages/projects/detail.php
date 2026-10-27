<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/finance_helper.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    redirect('pages/projects/index.php');
}

function rp($value)
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function safe_date($date)
{
    return $date ? date('d M Y', strtotime($date)) : '-';
}

function pct($value)
{
    return number_format((float) $value, 1, ',', '.') . '%';
}

function project_day_number($value)
{
    $value = (float) $value;
    if (floor($value) == $value) {
        return number_format($value, 0, ',', '.');
    }

    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
}

function billing_label($cycle)
{
    $map = [
        'none' => 'Tidak Berulang',
        'monthly' => 'Bulanan',
        'quarterly' => 'Triwulan',
        'semi_annual' => 'Semester',
        'annual' => 'Tahunan',
        'custom' => 'Custom',
    ];

    return $map[$cycle] ?? '-';
}

function project_type_label($type)
{
    return $type === 'ongoing' ? 'Contract Project / Kontrak Berkelanjutan' : 'One Time Project / Sekali Jalan';
}

function post_amount($key)
{
    // Format Indonesia: 10.000 => 10000, 1.000.000 => 1000000
    // Jangan gunakan (float) langsung pada string bertitik karena PHP akan membaca 10.000 sebagai 10.
    $raw = trim((string) ($_POST[$key] ?? ''));
    $raw = str_replace(["\xc2\xa0", ' ', 'Rp', 'rp', 'IDR', 'idr'], '', $raw);
    $digitsOnly = preg_replace('/[^0-9]/', '', $raw);
    return $digitsOnly === '' ? 0 : (float) $digitsOnly;
}

function post_percentage($key)
{
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') {
        return null;
    }
    $raw = str_replace(',', '.', $raw);
    $clean = preg_replace('/[^0-9.]/', '', $raw);
    return $clean === '' ? null : (float) $clean;
}

function post_date_value($key, $default = null)
{
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') {
        return $default;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $default;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $raw));
    return checkdate($month, $day, $year) ? $raw : $default;
}

function resolve_project_person_funder(PDO $pdo): array
{
    $source = clean_input($_POST['person_source'] ?? 'manual');
    $employeeId = (int) ($_POST['personal_employee_id'] ?? 0);
    $manualName = clean_input($_POST['person_name'] ?? '');

    if ($source === 'employee' && $employeeId > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name FROM employees WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $employeeId]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($emp) {
            return [
                'source' => 'employee',
                'employee_id' => (int) $emp['id'],
                'name' => trim((string) $emp['full_name']),
            ];
        }
    }

    return [
        'source' => 'manual',
        'employee_id' => null,
        'name' => $manualName,
    ];
}

function redirect_detail($id, $tab = 'overview')
{
    redirect('pages/projects/detail.php?id=' . (int) $id . '&tab=' . urlencode($tab));
}

function project_days_between($startDate, $endDate)
{
    if (!$startDate || !$endDate) {
        return [];
    }
    try {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        if ($end < $start) {
            return 0;
        }
        return ((int) $start->diff($end)->days) + 1;
    } catch (Exception $e) {
        return 0;
    }
}


function project_worker_attendance_summary(PDO $pdo, $employeeId, $startDate, $endDate)
{
    if ((int) $employeeId <= 0 || !$startDate || !$endDate) {
        return [
            'attendance_days' => 0,
            'total_work_minutes' => 0,
            'overtime_minutes' => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(attendance_day_value), 0) AS attendance_days,
            COALESCE(SUM(work_minutes), 0) AS total_work_minutes,
            COALESCE(SUM(
                CASE
                    WHEN COALESCE(overtime_minutes, 0) > 0 THEN overtime_minutes
                    WHEN overtime_in IS NOT NULL AND overtime_out IS NOT NULL AND overtime_out > overtime_in
                    THEN TIMESTAMPDIFF(MINUTE, CONCAT(attendance_date, ' ', overtime_in), CONCAT(attendance_date, ' ', overtime_out))
                    ELSE 0
                END
            ), 0) AS overtime_minutes
        FROM attendances
        WHERE employee_id = :employee_id
          AND attendance_date BETWEEN :start_date AND :end_date
          AND attendance_day_value > 0
    ");
    $stmt->execute([
        ':employee_id' => (int) $employeeId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'attendance_days' => (float) ($row['attendance_days'] ?? 0),
        'total_work_minutes' => (int) ($row['total_work_minutes'] ?? 0),
        'overtime_minutes' => (int) ($row['overtime_minutes'] ?? 0),
    ];
}

function project_worker_total($baseDays, $rate, $overtimeHours, $overtimeRate, $bonusAmount, $deductionAmount, $isBorongan = false)
{
    $baseAmount = $isBorongan ? (float) $rate : ((float) $baseDays * (float) $rate);
    $overtimeAmount = (float) $overtimeHours * (float) $overtimeRate;
    return max(0, $baseAmount + $overtimeAmount + (float) $bonusAmount - (float) $deductionAmount);
}

function is_auto_project_transaction(array $trx)
{
    $source = $trx['source_module'] ?? 'manual';
    if (in_array($source, ['term', 'fund', 'worker', 'asset_sale', 'asset_rental', 'asset_maintenance'], true)) {
        return true;
    }

    // Fallback untuk data lama sebelum kolom source_module/source_id ditambahkan.
    $category = $trx['category'] ?? '';
    if (in_array($category, ['Modal Project', 'Biaya Pekerja', 'Pembayaran Termin'], true)) {
        return true;
    }

    return false;
}

function project_transaction_source_label(array $trx)
{
    $source = $trx['source_module'] ?? 'manual';
    if ($source === 'term' || ($trx['category'] ?? '') === 'Pembayaran Termin') {
        return 'Otomatis dari Termin';
    }
    if ($source === 'fund' || ($trx['category'] ?? '') === 'Modal Project') {
        return 'Otomatis dari Pendanaan';
    }
    if ($source === 'worker' || ($trx['category'] ?? '') === 'Biaya Pekerja') {
        return 'Otomatis dari Pekerja';
    }
    if ($source === 'asset_sale') return 'Otomatis dari Penjualan Aset';
    if ($source === 'asset_rental') return 'Otomatis dari Sewa Aset';
    if ($source === 'asset_maintenance') return 'Otomatis dari Maintenance Aset';
    return 'Manual';
}


function project_asset_table_columns(PDO $pdo)
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }

    $columns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM assets");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['Field']] = true;
        }
    } catch (Throwable $e) {
        $columns = [];
    }

    return $columns;
}

function project_asset_has_column(PDO $pdo, $column)
{
    $columns = project_asset_table_columns($pdo);
    return isset($columns[$column]);
}

function project_asset_marker($transactionId)
{
    return '[AUTO_PROJECT_ASSET][PROJECT_TRANSACTION_ID:' . (int) $transactionId . ']';
}

function find_asset_id_by_project_transaction(PDO $pdo, $transactionId)
{
    $transactionId = (int) $transactionId;
    if ($transactionId <= 0) {
        return 0;
    }

    if (project_asset_has_column($pdo, 'source_transaction_id')) {
        $stmt = $pdo->prepare("SELECT id FROM assets WHERE source_transaction_id = :transaction_id LIMIT 1");
        $stmt->execute([':transaction_id' => $transactionId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    $stmt = $pdo->prepare("SELECT id FROM assets WHERE notes LIKE :marker LIMIT 1");
    $stmt->execute([':marker' => '%' . project_asset_marker($transactionId) . '%']);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function delete_project_transaction_asset(PDO $pdo, $transactionId)
{
    $assetId = find_asset_id_by_project_transaction($pdo, $transactionId);
    if ($assetId <= 0) {
        return;
    }

    $photoStmt = $pdo->prepare("SELECT image_path FROM project_asset_photos WHERE asset_id=:asset_id");
    $photoStmt->execute([':asset_id' => $assetId]);
    foreach ($photoStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $GLOBALS['project_files_pending_delete'][] = $path;
    }
    $stmt = $pdo->prepare("DELETE FROM assets WHERE id = :asset_id");
    $stmt->execute([':asset_id' => $assetId]);
}

function flush_project_pending_file_deletes(): void
{
    $paths = array_unique($GLOBALS['project_files_pending_delete'] ?? []);
    $GLOBALS['project_files_pending_delete'] = [];
    foreach ($paths as $path) upload_delete_file($path);
}

function track_project_new_uploads(array $paths): void
{
    foreach ($paths as $path) $GLOBALS['project_new_uploads'][] = $path;
}

function cleanup_project_new_uploads(): void
{
    $paths = array_unique($GLOBALS['project_new_uploads'] ?? []);
    $GLOBALS['project_new_uploads'] = [];
    foreach ($paths as $path) upload_delete_file($path);
}

function keep_project_new_uploads(): void
{
    $GLOBALS['project_new_uploads'] = [];
}


function upload_project_transaction_receipts($files, $transactionId, PDO $pdo)
{
    $transactionId = (int) $transactionId;
    if ($transactionId <= 0) return [];
    $paths = upload_store_multiple($files, 'project_transactions', 'trx_' . $transactionId, upload_image_mimes(), 5242880, 10);
    track_project_new_uploads($paths);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_transaction_receipts (transaction_id, image_path)
            VALUES (:transaction_id, :image_path)
        ");
        foreach ($paths as $path) $stmt->execute([':transaction_id' => $transactionId, ':image_path' => $path]);
    } catch (Throwable $e) {
        foreach ($paths as $path) upload_delete_file($path);
        throw $e;
    }
    return array_map(static fn(string $path): array => ['image_path' => $path], $paths);
}


function ensure_project_fund_receipts_table(PDO $pdo)
{
    return project_table_exists($pdo, 'project_fund_receipts');
}

function upload_project_fund_receipts($files, $fundId, PDO $pdo)
{
    $fundId = (int) $fundId;
    if ($fundId <= 0 || !isset($files) || empty($files['name'][0])) {
        return 0;
    }

    ensure_project_fund_receipts_table($pdo);
    if (!project_table_exists($pdo, 'project_fund_receipts')) {
        return 0;
    }

    $paths = upload_store_multiple($files, 'project_funds', 'fund_' . $fundId, upload_image_mimes(), 5242880, 10);
    track_project_new_uploads($paths);
    try {
        $stmt = $pdo->prepare("\n            INSERT INTO project_fund_receipts (fund_id, file_path)\n            VALUES (:fund_id, :file_path)\n        ");
        foreach ($paths as $path) $stmt->execute([':fund_id' => $fundId, ':file_path' => $path]);
    } catch (Throwable $e) {
        foreach ($paths as $path) upload_delete_file($path);
        throw $e;
    }
    return count($paths);
}

function upload_project_asset_photos($files, $assetId, PDO $pdo)
{
    $assetId = (int) $assetId;
    if ($assetId <= 0 || !isset($files) || empty($files['name'][0])) {
        return 0;
    }

    $paths = upload_store_multiple($files, 'assets', 'asset_' . $assetId, upload_image_mimes(), 5242880, 10);
    track_project_new_uploads($paths);
    $firstPhotoPath = $paths[0] ?? null;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_asset_photos (asset_id, image_path)
            VALUES (:asset_id, :image_path)
        ");
        foreach ($paths as $path) $stmt->execute([':asset_id' => $assetId, ':image_path' => $path]);

        if ($firstPhotoPath !== null && project_asset_has_column($pdo, 'photo')) {
            $stmt = $pdo->prepare("
                UPDATE assets
                SET photo = COALESCE(NULLIF(photo, ''), :photo)
                WHERE id = :asset_id
                LIMIT 1
            ");
            $stmt->execute([':photo' => $firstPhotoPath, ':asset_id' => $assetId]);
        }
    } catch (Throwable $e) {
        foreach ($paths as $path) upload_delete_file($path);
        throw $e;
    }
    return count($paths);
}


function project_table_exists(PDO $pdo, $tableName)
{
    static $cache = [];
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $tableName);
    if ($tableName === '') {
        return false;
    }
    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name");
        $stmt->execute([':table_name' => $tableName]);
        $cache[$tableName] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$tableName] = false;
    }
    return $cache[$tableName];
}

function project_table_columns(PDO $pdo, $tableName)
{
    static $cache = [];
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $tableName);
    if ($tableName === '') {
        return [];
    }
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }
    $columns = [];
    if (!project_table_exists($pdo, $tableName)) {
        $cache[$tableName] = [];
        return [];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['Field']] = true;
        }
    } catch (Throwable $e) {
        $columns = [];
    }
    $cache[$tableName] = $columns;
    return $columns;
}


function ensure_project_transaction_date_column(PDO $pdo)
{
    $columns = project_table_columns($pdo, 'project_transactions');
    if (!isset($columns['transaction_date'])) {
        try {
            $pdo->exec("ALTER TABLE project_transactions ADD COLUMN transaction_date DATE NULL AFTER project_id");
            $pdo->exec("UPDATE project_transactions SET transaction_date = DATE(COALESCE(created_at, NOW())) WHERE transaction_date IS NULL");
        } catch (Throwable $e) {
            // Jika kolom sudah ada atau tidak bisa ditambahkan, jangan hentikan halaman detail project.
        }
    }

    // Kolom ini menjadikan transaksi project terhubung langsung ke akun kas/bank perusahaan.
    $columns = project_table_columns($pdo, 'project_transactions');
    if (!isset($columns['account_id'])) {
        try {
            $pdo->exec("ALTER TABLE project_transactions ADD COLUMN account_id INT(11) NULL AFTER person_name");
        } catch (Throwable $e) {}
    }
    if (!isset($columns['payment_method'])) {
        try {
            $pdo->exec("ALTER TABLE project_transactions ADD COLUMN payment_method ENUM('cash','bank_transfer','qris','other') NOT NULL DEFAULT 'cash' AFTER account_id");
        } catch (Throwable $e) {}
    }
    $columns = project_table_columns($pdo, 'project_transactions');
    if (!isset($columns['personal_employee_id'])) {
        try {
            $pdo->exec("ALTER TABLE project_transactions ADD COLUMN personal_employee_id INT(11) NULL AFTER person_name");
        } catch (Throwable $e) {}
    }
}

function project_first_existing_column(array $columns, array $candidates)
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }
    return null;
}

function fetch_project_evidence_grouped(PDO $pdo, $tableName, $foreignKey, array $ids)
{
    $result = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids) || !project_table_exists($pdo, $tableName)) {
        return $result;
    }

    $columns = project_table_columns($pdo, $tableName);
    if (!isset($columns[$foreignKey])) {
        return $result;
    }

    $fileColumn = project_first_existing_column($columns, ['file_path', 'image_path', 'photo_path', 'receipt_path', 'document_path']);
    if ($fileColumn === null) {
        return $result;
    }

    $captionColumn = project_first_existing_column($columns, ['caption', 'notes', 'description']);
    $createdColumn = project_first_existing_column($columns, ['created_at', 'uploaded_at']);

    $select = "id AS evidence_id, `{$foreignKey}` AS parent_id, `{$fileColumn}` AS file_path";
    $select .= $captionColumn ? ", `{$captionColumn}` AS caption" : ", NULL AS caption";
    $select .= $createdColumn ? ", `{$createdColumn}` AS created_at" : ", NULL AS created_at";

    $evidenceTypeMap = [
        'project_transaction_receipts' => 'transaction_receipt',
        'project_term_receipts' => 'term_receipt',
        'project_fund_receipts' => 'fund_receipt',
        'project_worker_receipts' => 'worker_receipt',
        'project_asset_photos' => 'asset_photo',
    ];
    $evidenceType = $evidenceTypeMap[$tableName] ?? '';

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT {$select} FROM `{$tableName}` WHERE `{$foreignKey}` IN ({$placeholders}) ORDER BY id ASC");
    $stmt->execute($ids);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parentId = (int) $row['parent_id'];
        $row['evidence_type'] = $evidenceType;
        $result[$parentId][] = $row;
    }

    return $result;
}

function evidence_file_is_image($path)
{
    $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function evidence_file_label($path)
{
    $name = basename((string) $path);
    return $name !== '' ? $name : 'Buka File';
}


function project_merge_evidence_items(...$groups)
{
    $merged = [];
    $seen = [];
    foreach ($groups as $items) {
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            $path = (string) ($item['file_path'] ?? $item['image_path'] ?? $item['photo_path'] ?? $item['receipt_path'] ?? $item['document_path'] ?? '');
            if ($path === '') {
                continue;
            }
            $key = $path . '|' . (string) ($item['created_at'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $item['file_path'] = $path;
            $merged[] = $item;
        }
    }
    return $merged;
}

function project_evidence_payload(array $items)
{
    $payload = [];
    foreach ($items as $item) {
        $path = (string) ($item['file_path'] ?? '');
        if ($path === '') {
            continue;
        }
        $isExternal = preg_match('/^https?:\/\//i', $path);
        $payload[] = [
            'url' => $isExternal ? $path : base_url($path),
            'path' => $path,
            'evidence_id' => (int) ($item['evidence_id'] ?? 0),
            'evidence_type' => (string) ($item['evidence_type'] ?? ''),
            'label' => evidence_file_label($path),
            'caption' => (string) ($item['caption'] ?? ''),
            'created_at' => (string) ($item['created_at'] ?? ''),
            'is_image' => evidence_file_is_image($path),
        ];
    }
    return $payload;
}

function project_evidence_button($title, array $items)
{
    $items = project_merge_evidence_items($items);
    $count = count($items);
    $json = htmlspecialchars(json_encode(project_evidence_payload($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');
    $badge = $count > 0 ? '<span class="ms-1 badge rounded-pill text-bg-info">' . $count . '</span>' : '';

    return '<button type="button" class="btn btn-sm btn-outline-info project-evidence-btn" data-evidence-title="' . $title . '" data-evidence-items="' . $json . '" onclick="openProjectEvidenceModal(this)" title="Lihat / Kelola Bukti"><i class="bi bi-eye"></i>' . $badge . '</button>';
}


function delete_project_evidence_file(PDO $pdo, $projectId, $evidenceType, $evidenceId)
{
    $projectId = (int) $projectId;
    $evidenceId = (int) $evidenceId;
    $evidenceType = (string) $evidenceType;

    if ($projectId <= 0 || $evidenceId <= 0) {
        throw new Exception('Data bukti tidak valid.');
    }

    $filePath = null;

    if ($evidenceType === 'transaction_receipt') {
        $stmt = $pdo->prepare("\n            SELECT r.image_path\n            FROM project_transaction_receipts r\n            INNER JOIN project_transactions t ON t.id = r.transaction_id\n            WHERE r.id = :id AND t.project_id = :project_id\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $evidenceId, ':project_id' => $projectId]);
        $filePath = $stmt->fetchColumn();
        if (!$filePath) {
            throw new Exception('Bukti transaksi tidak ditemukan.');
        }
        $delete = $pdo->prepare("DELETE FROM project_transaction_receipts WHERE id = :id");
        $delete->execute([':id' => $evidenceId]);
    } elseif ($evidenceType === 'term_receipt' && project_table_exists($pdo, 'project_term_receipts')) {
        $stmt = $pdo->prepare("\n            SELECT r.file_path\n            FROM project_term_receipts r\n            INNER JOIN project_payment_terms t ON t.id = r.term_id\n            WHERE r.id = :id AND t.project_id = :project_id\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $evidenceId, ':project_id' => $projectId]);
        $filePath = $stmt->fetchColumn();
        if (!$filePath) {
            throw new Exception('Bukti termin tidak ditemukan.');
        }
        $delete = $pdo->prepare("DELETE FROM project_term_receipts WHERE id = :id");
        $delete->execute([':id' => $evidenceId]);
    } elseif ($evidenceType === 'fund_receipt' && project_table_exists($pdo, 'project_fund_receipts')) {
        $stmt = $pdo->prepare("\n            SELECT r.file_path\n            FROM project_fund_receipts r\n            INNER JOIN project_funds f ON f.id = r.fund_id\n            WHERE r.id = :id AND f.project_id = :project_id\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $evidenceId, ':project_id' => $projectId]);
        $filePath = $stmt->fetchColumn();
        if (!$filePath) {
            throw new Exception('Bukti pendanaan tidak ditemukan.');
        }
        $delete = $pdo->prepare("DELETE FROM project_fund_receipts WHERE id = :id");
        $delete->execute([':id' => $evidenceId]);
    } elseif ($evidenceType === 'worker_receipt' && project_table_exists($pdo, 'project_worker_receipts')) {
        $stmt = $pdo->prepare("\n            SELECT r.file_path\n            FROM project_worker_receipts r\n            INNER JOIN project_workers w ON w.id = r.worker_id\n            WHERE r.id = :id AND w.project_id = :project_id\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $evidenceId, ':project_id' => $projectId]);
        $filePath = $stmt->fetchColumn();
        if (!$filePath) {
            throw new Exception('Bukti pekerja tidak ditemukan.');
        }
        $delete = $pdo->prepare("DELETE FROM project_worker_receipts WHERE id = :id");
        $delete->execute([':id' => $evidenceId]);
    } elseif ($evidenceType === 'asset_photo' && project_table_exists($pdo, 'project_asset_photos')) {
        $stmt = $pdo->prepare("\n            SELECT p.image_path, p.asset_id\n            FROM project_asset_photos p\n            INNER JOIN assets a ON a.id = p.asset_id\n            LEFT JOIN project_transactions t ON t.id = a.source_transaction_id\n            WHERE p.id = :id AND (a.source_project_id = :asset_project_id OR t.project_id = :transaction_project_id)\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $evidenceId, ':asset_project_id' => $projectId, ':transaction_project_id' => $projectId]);
        $assetPhoto = $stmt->fetch(PDO::FETCH_ASSOC);
        $filePath = $assetPhoto['image_path'] ?? null;
        if (!$filePath) {
            throw new Exception('Foto aset tidak ditemukan.');
        }
        $delete = $pdo->prepare("DELETE FROM project_asset_photos WHERE id = :id");
        $delete->execute([':id' => $evidenceId]);
        if (project_asset_has_column($pdo, 'photo')) {
            $nextPhoto = $pdo->prepare("SELECT image_path FROM project_asset_photos WHERE asset_id=:asset_id ORDER BY id ASC LIMIT 1");
            $nextPhoto->execute([':asset_id' => (int)$assetPhoto['asset_id']]);
            $pdo->prepare("UPDATE assets SET photo=CASE WHEN photo=:old_photo THEN :new_photo ELSE photo END WHERE id=:asset_id")
                ->execute([':old_photo' => $filePath, ':new_photo' => $nextPhoto->fetchColumn() ?: null, ':asset_id' => (int)$assetPhoto['asset_id']]);
        }
    } else {
        throw new Exception('Jenis bukti tidak dikenali.');
    }

    if ($filePath && !preg_match('/^https?:\/\//i', (string) $filePath)) upload_delete_file($filePath);
}

function ensure_term_transaction(PDO $pdo, $projectId, $termId, array $termData, ?int $accountId = null, string $paymentMethod = 'bank_transfer')
{
    $projectId = (int) $projectId;
    $termId = (int) $termId;

    $stmt = $pdo->prepare("SELECT transaction_id, posting_mode, historical_reason, paid_date FROM project_payment_terms WHERE id = :term_id AND project_id = :project_id LIMIT 1");
    $stmt->execute([':term_id' => $termId, ':project_id' => $projectId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    $description = 'Pembayaran ' . (trim((string) ($termData['term_name'] ?? 'Termin')) ?: 'Termin');
    $amount = (float) ($termData['received_amount'] ?? 0);
    if ($amount <= 0) $amount = (float) ($termData['amount'] ?? 0);
    $projectPostingMode = (($current['posting_mode'] ?? 'posted') === 'historical') ? 'historical' : 'posted';
    $projectHistoricalReason = $projectPostingMode === 'historical' ? ($current['historical_reason'] ?? null) : null;
    $transactionDate = $current['paid_date'] ?: date('Y-m-d');
    if ($projectPostingMode === 'historical') $accountId = null;

    if (!empty($current['transaction_id'])) {
        $trxId = (int) $current['transaction_id'];
        $update = $pdo->prepare("
            UPDATE project_transactions
            SET transaction_type = 'income',
                funding_source = 'company',
                person_name = NULL,
                personal_employee_id = NULL,
                account_id = :account_id,
                payment_method = :payment_method,
                category = 'Pembayaran Termin',
                description = :description,
                amount = :amount,
                posting_mode = :posting_mode,
                historical_reason = :historical_reason,
                transaction_date = :transaction_date,
                is_asset = 0,
                source_module = 'term',
                source_id = :source_id
            WHERE id = :transaction_id AND project_id = :project_id
            LIMIT 1
        ");
        $update->execute([
            ':description' => $description,
            ':amount' => $amount,
            ':posting_mode' => $projectPostingMode,
            ':historical_reason' => $projectHistoricalReason,
            ':transaction_date' => $transactionDate,
            ':account_id' => $accountId,
            ':payment_method' => $paymentMethod,
            ':source_id' => $termId,
            ':transaction_id' => $trxId,
            ':project_id' => $projectId
        ]);

        // Integrasi minimal ke Finance Core: buat/update transaksi utama.
        if (function_exists('finance_sync_project_term_transaction')) {
            finance_sync_project_term_transaction($pdo, $termId, $trxId, $_SESSION['user_id'] ?? null, $accountId, $paymentMethod);
        }

        return $trxId;
    }

    $insert = $pdo->prepare("
        INSERT INTO project_transactions
        (project_id, transaction_date, transaction_type, funding_source, person_name, personal_employee_id, account_id, payment_method, category, description, amount, posting_mode, historical_reason, is_asset, source_module, source_id)
        VALUES
        (:project_id, :transaction_date, 'income', 'company', NULL, NULL, :account_id, :payment_method, 'Pembayaran Termin', :description, :amount, :posting_mode, :historical_reason, 0, 'term', :source_id)
    ");
    $insert->execute([
        ':project_id' => $projectId,
        ':description' => $description,
        ':amount' => $amount,
        ':posting_mode' => $projectPostingMode,
        ':historical_reason' => $projectHistoricalReason,
        ':transaction_date' => $transactionDate,
        ':account_id' => $accountId,
        ':payment_method' => $paymentMethod,
        ':source_id' => $termId
    ]);
    $trxId = (int) $pdo->lastInsertId();

    $updateTerm = $pdo->prepare("
        UPDATE project_payment_terms
        SET transaction_id = :transaction_id
        WHERE id = :term_id AND project_id = :project_id
        LIMIT 1
    ");
    $updateTerm->execute([
        ':transaction_id' => $trxId,
        ':term_id' => $termId,
        ':project_id' => $projectId
    ]);

    // Integrasi minimal ke Finance Core: buat/update transaksi utama.
    if (function_exists('finance_sync_project_term_transaction')) {
        finance_sync_project_term_transaction($pdo, $termId, $trxId, $_SESSION['user_id'] ?? null, $accountId, $paymentMethod);
    }

    return $trxId;
}

function delete_term_transaction_if_unpaid(PDO $pdo, $projectId, $termId)
{
    $stmt = $pdo->prepare("SELECT transaction_id FROM project_payment_terms WHERE id = :term_id AND project_id = :project_id LIMIT 1");
    $stmt->execute([':term_id' => (int) $termId, ':project_id' => (int) $projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($row['transaction_id'])) {
        $trxId = (int) $row['transaction_id'];
        $del = $pdo->prepare("DELETE FROM project_transactions WHERE id = :transaction_id AND project_id = :project_id AND source_module = 'term'");
        $del->execute([':transaction_id' => $trxId, ':project_id' => (int) $projectId]);
    }

    // Integrasi minimal ke Finance Core: hapus transaksi utama jika termin dibatalkan/belum lunas.
    if (function_exists('finance_delete_project_term_transaction_if_exists')) {
        finance_delete_project_term_transaction_if_exists($pdo, (int) $termId);
    }

    $clear = $pdo->prepare("UPDATE project_payment_terms SET transaction_id = NULL WHERE id = :term_id AND project_id = :project_id");
    $clear->execute([':term_id' => (int) $termId, ':project_id' => (int) $projectId]);
}

function sync_project_transaction_asset(PDO $pdo, $projectId, $transactionId, $isAsset, $transactionType, $category, $description, $amount, array $assetInput = [])
{
    $projectId = (int) $projectId;
    $transactionId = (int) $transactionId;
    $isAsset = (int) $isAsset === 1;
    $transactionType = (string) $transactionType;

    if ($transactionId <= 0) {
        return;
    }

    if (!$isAsset || $transactionType !== 'expense') {
        delete_project_transaction_asset($pdo, $transactionId);
        return 0;
    }

    $projectStmt = $pdo->prepare("SELECT project_code, project_name, project_location, leader_id FROM projects WHERE id = :project_id LIMIT 1");
    $projectStmt->execute([':project_id' => $projectId]);
    $project = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $assetNameInput = trim((string) ($assetInput['asset_name'] ?? ''));
    $assetCategoryInput = trim((string) ($assetInput['asset_category'] ?? ''));
    $assetLocationInput = trim((string) ($assetInput['asset_location'] ?? ''));
    $assetConditionInput = trim((string) ($assetInput['asset_condition_status'] ?? 'good'));
    $assetResponsibleInput = (int) ($assetInput['asset_responsible_employee_id'] ?? 0);
    $assetNotesInput = trim((string) ($assetInput['asset_notes'] ?? ''));

    if (!in_array($assetConditionInput, ['good', 'maintenance', 'damaged', 'lost'], true)) {
        $assetConditionInput = 'good';
    }

    $assetName = $assetNameInput !== '' ? $assetNameInput : (trim((string) $category) ?: 'Aset Project');
    $assetName = substr($assetName, 0, 150);
    $assetCategory = $assetCategoryInput !== '' ? $assetCategoryInput : ($category ?: 'Project');

    $projectCode = trim((string) ($project['project_code'] ?? 'PRJ'));
    if ($projectCode === '') {
        $projectCode = 'PRJ';
    }

    $assetCode = substr($projectCode . '-TRX-' . $transactionId, 0, 50);
    $location = $assetLocationInput !== '' ? $assetLocationInput : (trim((string) ($project['project_location'] ?? '')) ?: null);
    $responsibleEmployeeId = $assetResponsibleInput > 0 ? $assetResponsibleInput : (!empty($project['leader_id']) ? (int) $project['leader_id'] : null);

    $notes = "Aset otomatis dari transaksi project.\n"
        . "Project: " . (($project['project_name'] ?? '') ?: ('ID ' . $projectId)) . "\n"
        . "Transaksi ID: " . $transactionId . "\n"
        . "Deskripsi Transaksi: " . (trim((string) $description) ?: '-') . "\n";
    if ($assetNotesInput !== '') {
        $notes .= "Catatan Aset: " . $assetNotesInput . "\n";
    }
    $notes .= project_asset_marker($transactionId);

    $assetId = find_asset_id_by_project_transaction($pdo, $transactionId);

    if ($assetId > 0) {
        $set = [
            'asset_code = :asset_code',
            'asset_name = :asset_name',
            'category = :category',
            'purchase_price = :purchase_price',
            'condition_status = :condition_status',
            'location = :location',
            'responsible_employee_id = :responsible_employee_id',
            'notes = :notes'
        ];
        $params = [
            ':asset_id' => $assetId,
            ':asset_code' => $assetCode,
            ':asset_name' => $assetName,
            ':category' => $assetCategory,
            ':purchase_price' => $amount,
            ':condition_status' => $assetConditionInput,
            ':location' => $location,
            ':responsible_employee_id' => $responsibleEmployeeId,
            ':notes' => $notes,
        ];

        if (project_asset_has_column($pdo, 'updated_at')) {
            $set[] = 'updated_at = NOW()';
        }
        if (project_asset_has_column($pdo, 'source_type')) {
            $set[] = 'source_type = :source_type';
            $params[':source_type'] = 'project_transaction';
        }
        if (project_asset_has_column($pdo, 'source_project_id')) {
            $set[] = 'source_project_id = :source_project_id';
            $params[':source_project_id'] = $projectId;
        }
        if (project_asset_has_column($pdo, 'source_transaction_id')) {
            $set[] = 'source_transaction_id = :source_transaction_id';
            $params[':source_transaction_id'] = $transactionId;
        }

        $stmt = $pdo->prepare("UPDATE assets SET " . implode(', ', $set) . " WHERE id = :asset_id");
        $stmt->execute($params);
        return $assetId;
    }

    $fields = [
        'asset_code' => $assetCode,
        'asset_name' => $assetName,
        'category' => $assetCategory,
        'purchase_date' => date('Y-m-d'),
        'purchase_price' => $amount,
        'condition_status' => $assetConditionInput,
        'location' => $location,
        'responsible_employee_id' => $responsibleEmployeeId,
        'notes' => $notes,
    ];

    if (project_asset_has_column($pdo, 'source_type')) {
        $fields['source_type'] = 'project_transaction';
    }
    if (project_asset_has_column($pdo, 'source_project_id')) {
        $fields['source_project_id'] = $projectId;
    }
    if (project_asset_has_column($pdo, 'source_transaction_id')) {
        $fields['source_transaction_id'] = $transactionId;
    }

    $columns = array_keys($fields);
    $placeholders = array_map(fn($col) => ':' . $col, $columns);
    $params = [];
    foreach ($fields as $col => $value) {
        $params[':' . $col] = $value;
    }

    $stmt = $pdo->prepare("INSERT INTO assets (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}


function project_gallery_is_external($path)
{
    return (bool) preg_match('/^https?:\/\//i', (string) $path);
}

function is_image_file($path)
{
    $path = (string) $path;
    if ($path === '') {
        return false;
    }
    $cleanPath = parse_url($path, PHP_URL_PATH) ?: $path;
    $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}

function project_gallery_url($path)
{
    return project_gallery_is_external($path) ? $path : base_url($path);
}

function project_gallery_icon($path)
{
    if (project_gallery_is_external($path)) {
        return 'bi-link-45deg';
    }

    $cleanPath = parse_url((string) $path, PHP_URL_PATH) ?: (string) $path;
    $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));

    if ($ext === 'pdf') {
        return 'bi-file-earmark-pdf';
    }
    if (in_array($ext, ['doc', 'docx'], true)) {
        return 'bi-file-earmark-word';
    }
    if (in_array($ext, ['xls', 'xlsx'], true)) {
        return 'bi-file-earmark-excel';
    }
    if (in_array($ext, ['ppt', 'pptx'], true)) {
        return 'bi-file-earmark-slides';
    }

    return 'bi-file-earmark-text';
}

function project_gallery_type_label($type)
{
    $labels = [
        'contract' => 'Kontrak Kerja Sama',
        'offer' => 'Penawaran Disetujui',
        'ba' => 'Berita Acara / BA',
        'invoice' => 'Invoice',
        'design' => 'Design / Konsep',
        'progress' => 'Progress',
        'final' => 'Hasil Final',
        'before' => 'Before',
        'after' => 'After',
        'other' => 'Lainnya',
    ];

    return $labels[$type] ?? ucfirst((string) $type);
}

function save_project_gallery_uploads($files, $projectId, $docType, $caption, PDO $pdo)
{
    $allowedMimes = upload_project_document_mimes() + ['image/gif' => 'gif'];
    $paths = upload_store_multiple($files, 'project_galleries', 'project_' . (int)$projectId, $allowedMimes, 10485760, 5);
    track_project_new_uploads($paths);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO project_galleries (project_id, image_type, image_path, caption)
            VALUES (:project_id, :image_type, :image_path, :caption)
        ");
        foreach ($paths as $path) {
            $stmt->execute([
                ':project_id' => $projectId,
                ':image_type' => $docType,
                ':image_path' => $path,
                ':caption' => $caption ?: null,
            ]);
        }
    } catch (Throwable $e) {
        foreach ($paths as $path) upload_delete_file($path);
        throw $e;
    }
    return count($paths);
}

function save_project_gallery_link($url, $projectId, $docType, $caption, PDO $pdo)
{
    $url = trim((string) $url);
    if ($url === '') {
        return 0;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
        throw new Exception('Link dokumen tidak valid. Gunakan link lengkap yang diawali http:// atau https://.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO project_galleries (project_id, image_type, image_path, caption)
        VALUES (:project_id, :image_type, :image_path, :caption)
    ");
    $stmt->execute([
        ':project_id' => $projectId,
        ':image_type' => $docType,
        ':image_path' => $url,
        ':caption' => $caption ?: null,
    ]);

    return 1;
}



$allowedTabs = ['overview', 'terms', 'funds', 'transactions', 'workers', 'gallery'];
$activeTab = $_GET['tab'] ?? ($_SESSION['project_active_tab'] ?? 'overview');
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}
$_SESSION['project_active_tab'] = $activeTab;

ensure_project_transaction_date_column($pdo);

/*
|--------------------------------------------------------------------------
| POST HANDLER - CRUD RINGAN DI DETAIL PROJECT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_gallery_document') {
            $docType = clean_input($_POST['doc_type'] ?? 'other');
            $allowedDocTypes = ['contract', 'offer', 'ba', 'invoice', 'design', 'progress', 'final', 'before', 'after', 'other'];
            if (!in_array($docType, $allowedDocTypes, true)) {
                $docType = 'other';
            }

            $caption = clean_input($_POST['caption'] ?? '');
            $externalUrl = trim((string) ($_POST['external_url'] ?? ''));

            $pdo->beginTransaction();
            $savedFiles = save_project_gallery_uploads($_FILES['project_files'] ?? null, $id, $docType, $caption, $pdo);
            $savedLink = save_project_gallery_link($externalUrl, $id, $docType, $caption, $pdo);

            if (($savedFiles + $savedLink) <= 0) {
                throw new Exception('Silakan upload minimal 1 file atau isi 1 link dokumen.');
            }

            $pdo->commit();
            keep_project_new_uploads();
            $_SESSION['success'] = 'Dokumen & Gallery berhasil disimpan.';
            redirect_detail($id, 'gallery');
        }

        if ($action === 'delete_project_evidence') {
            $evidenceType = clean_input($_POST['evidence_type'] ?? '');
            $evidenceId = (int) ($_POST['evidence_id'] ?? 0);
            $returnTab = clean_input($_POST['return_tab'] ?? $activeTab);
            $allowedReturnTabs = ['terms', 'funds', 'transactions', 'workers'];
            if (!in_array($returnTab, $allowedReturnTabs, true)) {
                $returnTab = $activeTab;
            }

            delete_project_evidence_file($pdo, $id, $evidenceType, $evidenceId);
            $_SESSION['success'] = 'Bukti/foto berhasil dihapus.';
            redirect_detail($id, $returnTab);
        }

        if ($action === 'add_term' || $action === 'update_term') {
            $termName = clean_input($_POST['term_name'] ?? '');
            $percentage = post_percentage('percentage');
            $amount = post_amount('amount');
            $historicalReceivedAmount = post_amount('received_amount');
            $dueDate = $_POST['due_date'] ?? '';
            $status = clean_input($_POST['status'] ?? 'unpaid');
            $paidDate = $_POST['paid_date'] ?? null;
            $notes = clean_input($_POST['notes'] ?? '');
            $postingMode = finance_validate_posting_mode($_POST['posting_mode'] ?? 'posted', $_POST['historical_reason'] ?? null, true);
            $historicalReason = $postingMode === 'posted' ? null : trim((string)($_POST['historical_reason'] ?? ''));
            $termPaymentAccountId = (int)($_POST['term_payment_account_id'] ?? 0) ?: null;
            $termPaymentMethod = finance_normalize_payment_method($_POST['term_payment_method'] ?? 'bank_transfer');
            $historicalTaxAmount = 0;
            $historicalTaxPercentage = 0;

            if ($postingMode === 'historical') {
                if (!$paidDate) throw new Exception('Tanggal pembayaran historis wajib diisi.');
                if ($historicalReceivedAmount <= 0) throw new Exception('Nominal bersih historis wajib lebih dari nol.');
                if ($historicalReceivedAmount > $amount) throw new Exception('Nominal bersih historis tidak boleh melebihi nilai termin.');
                $historicalTaxAmount = max(0, $amount - $historicalReceivedAmount);
                $historicalTaxPercentage = $amount > 0 ? round($historicalTaxAmount / $amount * 100, 2) : 0;
                $status = 'paid';
                $termPaymentAccountId = null;
                $termPaymentMethod = 'other';
            } elseif ($postingMode === 'opening_outstanding') {
                $status = 'unpaid';
                $paidDate = null;
            } elseif ($status === 'paid' && !$termPaymentAccountId) {
                throw new Exception('Pilih akun kas/bank tujuan untuk termin berjalan yang langsung dibayar.');
            }

            if ($termName === '' || $amount <= 0 || $dueDate === '') {
                $_SESSION['error'] = 'Data termin belum lengkap. Nama, nominal, dan tanggal jatuh tempo wajib diisi.';
                redirect_detail($id, 'terms');
            }

            if ($action === 'add_term') {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO project_payment_terms
                    (project_id, term_name, percentage, amount, tax_percentage, tax_amount, received_amount, due_date, paid_date, status, posting_mode, historical_reason, notes)
                    VALUES
                    (:project_id, :term_name, :percentage, :amount, :tax_percentage, :tax_amount, :received_amount, :due_date, :paid_date, :status, :posting_mode, :historical_reason, :notes)
                ");
                $stmt->execute([
                    ':project_id' => $id,
                    ':term_name' => $termName,
                    ':percentage' => $percentage,
                    ':amount' => $amount,
                    ':tax_percentage' => $postingMode === 'historical' ? $historicalTaxPercentage : 0,
                    ':tax_amount' => $postingMode === 'historical' ? $historicalTaxAmount : 0,
                    ':received_amount' => $postingMode === 'historical' ? $historicalReceivedAmount : 0,
                    ':due_date' => $dueDate,
                    ':paid_date' => $paidDate ?: ($status === 'paid' ? date('Y-m-d') : null),
                    ':status' => $status,
                    ':posting_mode' => $postingMode,
                    ':historical_reason' => $historicalReason,
                    ':notes' => $notes ?: null
                ]);
                $termId = (int) $pdo->lastInsertId();

                // Sinkronisasi minimal: Termin Project -> Piutang otomatis.
                finance_sync_project_term_receivable($pdo, $termId, $_SESSION['user_id'] ?? null);

                if ($status === 'paid') {
                    $transactionId = ensure_term_transaction($pdo, $id, $termId, [
                        'term_name' => $termName,
                        'amount' => $amount,
                        'received_amount' => $postingMode === 'historical' ? $historicalReceivedAmount : 0,
                    ], $termPaymentAccountId, $termPaymentMethod);
                    $uploadedReceipts = upload_project_transaction_receipts($_FILES['receipt_images'] ?? null, $transactionId, $pdo);
                    $firstReceiptPath = is_array($uploadedReceipts) && !empty($uploadedReceipts) ? ($uploadedReceipts[0]['image_path'] ?? null) : null;

                    // Jika termin langsung dibuat lunas, piutang ikut ditandai lunas dan uang masuk ke akun kas/bank yang dipilih.
                    finance_mark_project_term_paid($pdo, $termId, $paidDate ?: date('Y-m-d'), $termPaymentAccountId, $termPaymentMethod, $firstReceiptPath, $_SESSION['user_id'] ?? null);
                }

                $pdo->commit();
                keep_project_new_uploads();
                log_activity($pdo, 'Project Termin', 'CREATE', finance_posting_mode_label($postingMode) . ($historicalReason ? ' — ' . $historicalReason : ''), $termId, 'project_payment_terms');
                $_SESSION['success'] = 'Termin berhasil ditambahkan.';
            } else {
                $termId = (int) ($_POST['term_id'] ?? 0);

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE project_payment_terms
                    SET term_name = :term_name,
                        percentage = :percentage,
                        amount = :amount,
                        due_date = :due_date,
                        paid_date = :paid_date,
                        status = :status,
                        posting_mode = :posting_mode,
                        historical_reason = :historical_reason,
                        received_amount = CASE
                            WHEN :posting_mode_for_received = 'historical' THEN :historical_received_amount
                            WHEN :posting_mode_for_opening = 'opening_outstanding' THEN 0
                            ELSE received_amount
                        END,
                        tax_amount = CASE
                            WHEN :posting_mode_for_tax_amount = 'historical' THEN :historical_tax_amount
                            WHEN :posting_mode_for_opening_tax_amount = 'opening_outstanding' THEN 0
                            ELSE tax_amount
                        END,
                        tax_percentage = CASE
                            WHEN :posting_mode_for_tax_percentage = 'historical' THEN :historical_tax_percentage
                            WHEN :posting_mode_for_opening_tax_percentage = 'opening_outstanding' THEN 0
                            ELSE tax_percentage
                        END,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :term_id AND project_id = :project_id
                ");
                $stmt->execute([
                    ':term_id' => $termId,
                    ':project_id' => $id,
                    ':term_name' => $termName,
                    ':percentage' => $percentage,
                    ':amount' => $amount,
                    ':due_date' => $dueDate,
                    ':paid_date' => $paidDate ?: ($status === 'paid' ? date('Y-m-d') : null),
                    ':status' => $status,
                    ':posting_mode' => $postingMode,
                    ':historical_reason' => $historicalReason,
                    ':posting_mode_for_received' => $postingMode,
                    ':posting_mode_for_opening' => $postingMode,
                    ':historical_received_amount' => $historicalReceivedAmount,
                    ':posting_mode_for_tax_amount' => $postingMode,
                    ':posting_mode_for_opening_tax_amount' => $postingMode,
                    ':historical_tax_amount' => $historicalTaxAmount,
                    ':posting_mode_for_tax_percentage' => $postingMode,
                    ':posting_mode_for_opening_tax_percentage' => $postingMode,
                    ':historical_tax_percentage' => $historicalTaxPercentage,
                    ':notes' => $notes ?: null
                ]);

                // Sinkronisasi minimal: perubahan termin ikut memperbarui piutang.
                finance_sync_project_term_receivable($pdo, $termId, $_SESSION['user_id'] ?? null);

                if ($status === 'paid') {
                    $transactionId = ensure_term_transaction($pdo, $id, $termId, [
                        'term_name' => $termName,
                        'amount' => $amount,
                        'received_amount' => $postingMode === 'historical' ? $historicalReceivedAmount : 0,
                    ], $termPaymentAccountId, $termPaymentMethod);
                    $uploadedReceipts = upload_project_transaction_receipts($_FILES['receipt_images'] ?? null, $transactionId, $pdo);
                    $firstReceiptPath = is_array($uploadedReceipts) && !empty($uploadedReceipts) ? ($uploadedReceipts[0]['image_path'] ?? null) : null;

                    // Jika status termin dibuat lunas, piutang ikut ditandai lunas dan uang masuk ke akun kas/bank yang dipilih.
                    finance_mark_project_term_paid($pdo, $termId, $paidDate ?: date('Y-m-d'), $termPaymentAccountId, $termPaymentMethod, $firstReceiptPath, $_SESSION['user_id'] ?? null);
                } else {
                    delete_term_transaction_if_unpaid($pdo, $id, $termId);

                    // Jika status termin tidak lunas, status piutang dikembalikan mengikuti pembayaran yang ada.
                    finance_unmark_project_term_paid($pdo, $termId);
                }

                $pdo->commit();
                keep_project_new_uploads();
                log_activity($pdo, 'Project Termin', 'UPDATE', finance_posting_mode_label($postingMode) . ($historicalReason ? ' — ' . $historicalReason : ''), $termId, 'project_payment_terms');
                $_SESSION['success'] = 'Termin berhasil diperbarui.';
            }

            redirect_detail($id, 'terms');
        }

        if ($action === 'delete_term') {
            $termId = (int) ($_POST['term_id'] ?? 0);
            $termFilesToDelete = [];
            $termTransactionStmt = $pdo->prepare("SELECT id FROM project_transactions WHERE project_id=:project_id AND source_module='term' AND source_id=:term_id");
            $termTransactionStmt->execute([':project_id' => $id, ':term_id' => $termId]);
            $termTransactionIds = array_map('intval', $termTransactionStmt->fetchAll(PDO::FETCH_COLUMN));

            $pdo->beginTransaction();

            $termStmt = $pdo->prepare("SELECT transaction_id FROM project_payment_terms WHERE id = :term_id AND project_id = :project_id LIMIT 1 FOR UPDATE");
            $termStmt->execute([':term_id' => $termId, ':project_id' => $id]);
            $termToDelete = $termStmt->fetch(PDO::FETCH_ASSOC);
            if (!$termToDelete) {
                throw new Exception('Termin tidak ditemukan pada project ini.');
            }
            if (!empty($termToDelete['transaction_id'])) $termTransactionIds[] = (int)$termToDelete['transaction_id'];
            $termTransactionIds = array_values(array_unique($termTransactionIds));
            if ($termTransactionIds) {
                $in = implode(',', array_fill(0, count($termTransactionIds), '?'));
                $receiptStmt = $pdo->prepare("SELECT image_path FROM project_transaction_receipts WHERE transaction_id IN ($in)");
                $receiptStmt->execute($termTransactionIds);
                $termFilesToDelete = $receiptStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // Hapus piutang otomatis hanya jika belum ada pembayaran.
            finance_delete_project_term_receivable_if_unpaid($pdo, $termId);

            // Bersihkan kedua lapisan transaksi (Project dan Finance Core).
            finance_delete_project_term_transaction_if_exists($pdo, $termId);
            if (!empty($termToDelete['transaction_id'])) {
                $stmt = $pdo->prepare("DELETE FROM project_transactions WHERE id = :transaction_id AND project_id = :project_id AND source_module = 'term'");
                $stmt->execute([':transaction_id' => (int)$termToDelete['transaction_id'], ':project_id' => $id]);
            }
            $stmt = $pdo->prepare("DELETE FROM project_transactions WHERE project_id = :project_id AND source_module = 'term' AND source_id = :term_id");
            $stmt->execute([':project_id' => $id, ':term_id' => $termId]);

            $stmt = $pdo->prepare("DELETE FROM project_payment_terms WHERE id = :term_id AND project_id = :project_id");
            $stmt->execute([':term_id' => $termId, ':project_id' => $id]);

            $pdo->commit();
            keep_project_new_uploads();
            foreach (array_unique($termFilesToDelete) as $file) upload_delete_file($file);

            $_SESSION['success'] = 'Termin berhasil dihapus.';
            redirect_detail($id, 'terms');
        }

        if ($action === 'pay_term') {
            $termId = (int) ($_POST['term_id'] ?? 0);
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $paymentMethod = function_exists('finance_normalize_payment_method')
                ? finance_normalize_payment_method($_POST['payment_method'] ?? 'bank_transfer')
                : (in_array(($_POST['payment_method'] ?? 'bank_transfer'), ['cash', 'bank_transfer', 'qris', 'other'], true) ? ($_POST['payment_method'] ?? 'bank_transfer') : 'other');
            $receivedAmountInput = post_amount('received_amount');

            if ($accountId <= 0) {
                $_SESSION['error'] = 'Pilih akun kas/bank tujuan pembayaran termin terlebih dahulu.';
                redirect_detail($id, 'terms');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT term_name, amount
                FROM project_payment_terms
                WHERE id = :term_id AND project_id = :project_id
                LIMIT 1
            ");
            $stmt->execute([':term_id' => $termId, ':project_id' => $id]);
            $termForPayment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($termForPayment) {
                $grossAmount = (float)$termForPayment['amount'];
                if ($receivedAmountInput <= 0) throw new Exception('Nominal bersih yang diterima wajib lebih dari nol.');
                if ($receivedAmountInput > $grossAmount) throw new Exception('Nominal bersih diterima tidak boleh melebihi nilai tagihan termin.');
                $receivedAmount = $receivedAmountInput;
                $taxAmount = max(0, $grossAmount - $receivedAmount);
                $taxPercentage = $grossAmount > 0 ? round($taxAmount / $grossAmount * 100, 2) : 0;
                $termForPayment['received_amount'] = $receivedAmount;
                $stmt = $pdo->prepare("
                    UPDATE project_payment_terms
                    SET status = 'paid', paid_date = CURDATE(), tax_percentage = :tax_percentage,
                        tax_amount = :tax_amount, received_amount = :received_amount, updated_at = NOW()
                    WHERE id = :term_id AND project_id = :project_id
                ");
                $stmt->execute([':term_id' => $termId, ':project_id' => $id, ':tax_percentage' => $taxPercentage, ':tax_amount' => $taxAmount, ':received_amount' => $receivedAmount]);

                $transactionId = ensure_term_transaction($pdo, $id, $termId, $termForPayment, $accountId, $paymentMethod);
                $uploadedReceipts = upload_project_transaction_receipts($_FILES['receipt_images'] ?? null, $transactionId, $pdo);
                $firstReceiptPath = is_array($uploadedReceipts) && !empty($uploadedReceipts) ? ($uploadedReceipts[0]['image_path'] ?? null) : null;

                finance_mark_project_term_paid($pdo, $termId, date('Y-m-d'), $accountId, $paymentMethod, $firstReceiptPath, $_SESSION['user_id'] ?? null);
            }

            $pdo->commit();
            keep_project_new_uploads();

            $_SESSION['success'] = 'Termin ditandai lunas dan dana masuk ke akun kas/bank yang dipilih.';
            redirect_detail($id, 'terms');
        }
        if ($action === 'add_fund' || $action === 'update_fund' || $action === 'delete_fund') {
            $_SESSION['error'] = 'Pendanaan Project sekarang otomatis dari transaksi project. Silakan tambah/edit data melalui tab Transaksi.';
            redirect_detail($id, 'funds');
        }

        if ($action === 'add_transaction' || $action === 'update_transaction') {
            $transactionType = clean_input($_POST['transaction_type'] ?? 'expense');
            $fundingSource = clean_input($_POST['funding_source'] ?? 'company');
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $operationalFundId = (int) ($_POST['operational_fund_id'] ?? 0);
            $paymentMethod = clean_input($_POST['payment_method'] ?? 'cash');
            if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'qris', 'other'], true)) { $paymentMethod = 'other'; }
            $personFunder = resolve_project_person_funder($pdo);
            $personName = $personFunder['name'];
            $personalEmployeeId = $personFunder['employee_id'];
            $category = clean_input($_POST['category'] ?? '');
            $description = clean_input($_POST['description'] ?? '');
            $amount = post_amount('amount');
            $transactionDate = post_date_value('transaction_date', date('Y-m-d'));
            $postingMode = finance_validate_posting_mode($_POST['posting_mode'] ?? 'posted', $_POST['historical_reason'] ?? null);
            $historicalReason = $postingMode === 'historical' ? trim((string)($_POST['historical_reason'] ?? '')) : null;
            $isAsset = isset($_POST['is_asset']) ? 1 : 0;
            $assetInput = [
                'asset_name' => clean_input($_POST['asset_name'] ?? ''),
                'asset_category' => clean_input($_POST['asset_category'] ?? ''),
                'asset_location' => clean_input($_POST['asset_location'] ?? ''),
                'asset_condition_status' => clean_input($_POST['asset_condition_status'] ?? 'good'),
                'asset_responsible_employee_id' => (int) ($_POST['asset_responsible_employee_id'] ?? 0),
                'asset_notes' => clean_input($_POST['asset_notes'] ?? ''),
            ];

            if ($amount <= 0 || $category === '') {
                $_SESSION['error'] = 'Kategori dan nominal transaksi wajib diisi.';
                redirect_detail($id, 'transactions');
            }

            if ($transactionType === 'income') {
                $fundingSource = 'company';
                $personName = '';
            }

            if ($fundingSource === 'person' && $personName === '') {
                $_SESSION['error'] = 'Nama personal wajib diisi jika sumber dana talangan personal.';
                redirect_detail($id, 'transactions');
            }

            if ($postingMode === 'posted' && $fundingSource === 'operational_fund' && $operationalFundId <= 0) {
                $_SESSION['error'] = 'Dana Operasional personal wajib dipilih.';
                redirect_detail($id, 'transactions');
            }

            if ($postingMode === 'posted' && $fundingSource === 'company' && $accountId <= 0) {
                $_SESSION['error'] = 'Akun kas/bank perusahaan wajib dipilih jika transaksi menggunakan uang perusahaan.';
                redirect_detail($id, 'transactions');
            }

            if ($isAsset === 1 && $transactionType !== 'expense') {
                $_SESSION['error'] = 'Aset/Inventaris hanya bisa dibuat dari transaksi pengeluaran/pembelian.';
                redirect_detail($id, 'transactions');
            }

            $pdo->beginTransaction();

            if ($action === 'add_transaction') {
                $stmt = $pdo->prepare("
                    INSERT INTO project_transactions
                    (project_id, transaction_date, transaction_type, funding_source, person_name, personal_employee_id, operational_fund_id, account_id, payment_method, category, description, amount, posting_mode, historical_reason, is_asset, source_module, source_id)
                    VALUES
                    (:project_id, :transaction_date, :transaction_type, :funding_source, :person_name, :personal_employee_id, :operational_fund_id, :account_id, :payment_method, :category, :description, :amount, :posting_mode, :historical_reason, :is_asset, 'manual', NULL)
                ");
                $stmt->execute([
                    ':project_id' => $id,
                    ':transaction_date' => $transactionDate,
                    ':transaction_type' => $transactionType,
                    ':funding_source' => $fundingSource,
                    ':person_name' => $fundingSource === 'person' ? $personName : null,
                    ':personal_employee_id' => ($fundingSource === 'person' && $personalEmployeeId) ? $personalEmployeeId : null,
                    ':operational_fund_id' => $fundingSource === 'operational_fund' ? $operationalFundId : null,
                    ':account_id' => $fundingSource === 'company' ? $accountId : null,
                    ':payment_method' => $fundingSource === 'company' ? $paymentMethod : 'other',
                    ':category' => $category,
                    ':description' => $description ?: null,
                    ':amount' => $amount,
                    ':posting_mode' => $postingMode,
                    ':historical_reason' => $historicalReason,
                    ':is_asset' => $isAsset
                ]);
                $transactionId = (int) $pdo->lastInsertId();
                $assetId = sync_project_transaction_asset($pdo, $id, $transactionId, $isAsset, $transactionType, $category, $description, $amount, $assetInput);
                upload_project_transaction_receipts($_FILES['receipt_images'] ?? null, $transactionId, $pdo);
                if ($isAsset === 1 && $assetId > 0) {
                    upload_project_asset_photos($_FILES['asset_photos'] ?? null, $assetId, $pdo);
                }
                $_SESSION['success'] = $isAsset === 1 ? 'Transaksi berhasil ditambahkan dan aset/inventaris otomatis dibuat.' : 'Transaksi berhasil ditambahkan.';
            } else {
                $transactionId = (int) ($_POST['transaction_id'] ?? 0);
                $check = $pdo->prepare("SELECT * FROM project_transactions WHERE id = :transaction_id AND project_id = :project_id LIMIT 1");
                $check->execute([':transaction_id' => $transactionId, ':project_id' => $id]);
                $existingTransaction = $check->fetch(PDO::FETCH_ASSOC);

                if (!$existingTransaction || is_auto_project_transaction($existingTransaction)) {
                    $pdo->rollBack();
                    $_SESSION['error'] = 'Transaksi otomatis dari termin/pendanaan/pekerja tidak boleh diedit dari tabel transaksi. Silakan edit dari sesi asalnya.';
                    redirect_detail($id, 'transactions');
                }

                $stmt = $pdo->prepare("
                    UPDATE project_transactions
                    SET transaction_date = :transaction_date,
                        transaction_type = :transaction_type,
                        funding_source = :funding_source,
                        person_name = :person_name,
                        personal_employee_id = :personal_employee_id,
                        operational_fund_id = :operational_fund_id,
                        account_id = :account_id,
                        payment_method = :payment_method,
                        category = :category,
                        description = :description,
                        amount = :amount,
                        posting_mode = :posting_mode,
                        historical_reason = :historical_reason,
                        is_asset = :is_asset,
                        source_module = 'manual',
                        source_id = NULL
                    WHERE id = :transaction_id AND project_id = :project_id
                ");
                $stmt->execute([
                    ':transaction_id' => $transactionId,
                    ':project_id' => $id,
                    ':transaction_date' => $transactionDate,
                    ':transaction_type' => $transactionType,
                    ':funding_source' => $fundingSource,
                    ':person_name' => $fundingSource === 'person' ? $personName : null,
                    ':personal_employee_id' => ($fundingSource === 'person' && $personalEmployeeId) ? $personalEmployeeId : null,
                    ':operational_fund_id' => $fundingSource === 'operational_fund' ? $operationalFundId : null,
                    ':account_id' => $fundingSource === 'company' ? $accountId : null,
                    ':payment_method' => $fundingSource === 'company' ? $paymentMethod : 'other',
                    ':category' => $category,
                    ':description' => $description ?: null,
                    ':amount' => $amount,
                    ':posting_mode' => $postingMode,
                    ':historical_reason' => $historicalReason,
                    ':is_asset' => $isAsset
                ]);
                $assetId = sync_project_transaction_asset($pdo, $id, $transactionId, $isAsset, $transactionType, $category, $description, $amount, $assetInput);
                upload_project_transaction_receipts($_FILES['receipt_images'] ?? null, $transactionId, $pdo);
                if ($isAsset === 1 && $assetId > 0) {
                    upload_project_asset_photos($_FILES['asset_photos'] ?? null, $assetId, $pdo);
                }
                $_SESSION['success'] = $isAsset === 1 ? 'Transaksi berhasil diperbarui dan aset/inventaris ikut disinkronkan.' : 'Transaksi berhasil diperbarui.';
            }

            if (function_exists('finance_sync_project_transaction')) {
                finance_sync_project_transaction($pdo, $transactionId, $_SESSION['user_id'] ?? null);
            }
            log_activity($pdo, 'Project Transaction', $action === 'add_transaction' ? 'CREATE' : 'UPDATE', finance_posting_mode_label($postingMode) . ($historicalReason ? ' — ' . $historicalReason : ''), $transactionId, 'project_transactions');

            $pdo->commit();
            keep_project_new_uploads();
            flush_project_pending_file_deletes();

            redirect_detail($id, 'transactions');
        }

        if ($action === 'delete_transaction') {
            $transactionId = (int) ($_POST['transaction_id'] ?? 0);
            $check = $pdo->prepare("SELECT * FROM project_transactions WHERE id = :transaction_id AND project_id = :project_id LIMIT 1");
            $check->execute([':transaction_id' => $transactionId, ':project_id' => $id]);
            $existingTransaction = $check->fetch(PDO::FETCH_ASSOC);

            if (!$existingTransaction || is_auto_project_transaction($existingTransaction)) {
                $_SESSION['error'] = 'Transaksi otomatis dari termin/pendanaan/pekerja tidak boleh dihapus dari tabel transaksi. Hapus/edit dari sesi asalnya.';
                redirect_detail($id, 'transactions');
            }

            $filesToDelete = [];
            $receiptStmt = $pdo->prepare("SELECT image_path FROM project_transaction_receipts WHERE transaction_id=:transaction_id");
            $receiptStmt->execute([':transaction_id' => $transactionId]);
            $filesToDelete = $receiptStmt->fetchAll(PDO::FETCH_COLUMN);
            $assetIdToDelete = find_asset_id_by_project_transaction($pdo, $transactionId);
            if ($assetIdToDelete > 0) {
                $assetPhotoStmt = $pdo->prepare("SELECT image_path FROM project_asset_photos WHERE asset_id=:asset_id");
                $assetPhotoStmt->execute([':asset_id' => $assetIdToDelete]);
                $filesToDelete = array_merge($filesToDelete, $assetPhotoStmt->fetchAll(PDO::FETCH_COLUMN));
            }
            $pdo->beginTransaction();
            if (function_exists('finance_delete_project_transaction_if_exists')) {
                finance_delete_project_transaction_if_exists($pdo, $transactionId);
            }
            delete_project_transaction_asset($pdo, $transactionId);
            $stmt = $pdo->prepare("DELETE FROM project_transactions WHERE id = :transaction_id AND project_id = :project_id AND source_module = 'manual'");
            $stmt->execute([':transaction_id' => $transactionId, ':project_id' => $id]);
            $pdo->commit();
            keep_project_new_uploads();
            flush_project_pending_file_deletes();
            foreach (array_unique($filesToDelete) as $file) upload_delete_file($file);
            $_SESSION['success'] = 'Transaksi berhasil dihapus.';
            redirect_detail($id, 'transactions');
        }

        if ($action === 'add_worker' || $action === 'update_worker') {
            $workerSource = clean_input($_POST['worker_source'] ?? 'freelance');
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $manualName = clean_input($_POST['worker_name'] ?? '');
            $workerType = clean_input($_POST['worker_type'] ?? 'freelance');
            $paymentType = clean_input($_POST['payment_type'] ?? 'daily');
            $startDate = $_POST['start_date'] ?? null;
            $endDate = $_POST['end_date'] ?? null;
            $postedWorkDays = (float) str_replace(',', '.', (string) ($_POST['work_days'] ?? 1));
            $workDays = max(0.5, round($postedWorkDays * 2) / 2);
            $rate = post_amount('rate');
            $overtimeHours = (float) str_replace(',', '.', (string) ($_POST['overtime_hours'] ?? 0));
            $overtimeRate = post_amount('overtime_rate');
            $bonusAmount = post_amount('bonus_amount');
            $deductionAmount = post_amount('deduction_amount');
            $fundingSource = clean_input($_POST['funding_source'] ?? 'company');
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $operationalFundId = (int) ($_POST['operational_fund_id'] ?? 0);
            $paymentMethod = clean_input($_POST['payment_method'] ?? 'cash');
            if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'qris', 'other'], true)) { $paymentMethod = 'other'; }
            $personFunder = resolve_project_person_funder($pdo);
            $personName = $personFunder['name'];
            $personalEmployeeId = $personFunder['employee_id'];
            $paymentStatus = clean_input($_POST['payment_status'] ?? 'unpaid');
            $paidDate = post_date_value('paid_date');
            $notes = clean_input($_POST['notes'] ?? '');

            if (!in_array($paymentStatus, ['unpaid', 'paid', 'cancelled'], true)) {
                $paymentStatus = 'unpaid';
            }

            $workerName = $manualName;

            if ($workerSource === 'employee') {
                $empStmt = $pdo->prepare("SELECT id, full_name, salary_type, salary_amount FROM employees WHERE id = :id LIMIT 1");
                $empStmt->execute([':id' => $employeeId]);
                $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

                if (!$emp) {
                    $_SESSION['error'] = 'Karyawan internal tidak ditemukan.';
                    redirect_detail($id, 'workers');
                }

                $workerName = $emp['full_name'];
                $workerType = 'employee';
                $paymentType = $paymentType ?: ($emp['salary_type'] ?: 'daily');
                if ($rate <= 0) {
                    $rate = (float) $emp['salary_amount'];
                }
            } else {
                $employeeId = 0;
            }

            if ($workerType === 'borongan' || $paymentType === 'borongan') {
                $workerType = 'borongan';
                $paymentType = 'borongan';
                if (!$startDate || !$endDate || project_days_between($startDate, $endDate) <= 0) {
                    $_SESSION['error'] = 'Borongan wajib memiliki periode tanggal mulai dan selesai yang valid.';
                    redirect_detail($id, 'workers');
                }
                $workDays = project_days_between($startDate, $endDate);
                $totalAmount = project_worker_total($workDays, $rate, $overtimeHours, $overtimeRate, $bonusAmount, $deductionAmount, true);
            } elseif ($workerType === 'employee') {
                $paymentStatus = 'unpaid';
                $paidDate = null;
                if (!$startDate || !$endDate || project_days_between($startDate, $endDate) <= 0) {
                    $_SESSION['error'] = 'Karyawan internal wajib memiliki rentang tanggal kerja yang valid.';
                    redirect_detail($id, 'workers');
                }
                $attendanceSummary = project_worker_attendance_summary($pdo, $employeeId, $startDate, $endDate);
                $workDays = (float) $attendanceSummary['attendance_days'];
                if ($workDays <= 0) {
                    $_SESSION['error'] = 'Belum ada data kehadiran fingerprint untuk karyawan internal pada rentang tanggal tersebut.';
                    redirect_detail($id, 'workers');
                }
                if ($overtimeHours <= 0 && (int) $attendanceSummary['overtime_minutes'] > 0) {
                    $overtimeHours = round(((int) $attendanceSummary['overtime_minutes']) / 60, 2);
                }
                $paymentType = 'per_attendance';
                $totalAmount = project_worker_total($workDays, $rate, $overtimeHours, $overtimeRate, $bonusAmount, $deductionAmount, false);
            } else {
                $workerType = 'freelance';
                $paymentType = 'daily';
                $totalAmount = project_worker_total($workDays, $rate, $overtimeHours, $overtimeRate, $bonusAmount, $deductionAmount, false);
            }

            if ($workerName === '' || $rate <= 0 || $totalAmount <= 0) {
                $_SESSION['error'] = 'Nama pekerja dan nilai bayaran wajib diisi.';
                redirect_detail($id, 'workers');
            }

            if ($fundingSource === 'person' && $personName === '') {
                $_SESSION['error'] = 'Nama personal wajib diisi jika pembayaran dari talangan personal.';
                redirect_detail($id, 'workers');
            }

            if ($fundingSource === 'operational_fund' && $operationalFundId <= 0) {
                $_SESSION['error'] = 'Dana Operasional personal wajib dipilih.';
                redirect_detail($id, 'workers');
            }

            if ($fundingSource === 'company' && $paymentStatus === 'paid' && $accountId <= 0 && $workerType !== 'employee') {
                $_SESSION['error'] = 'Akun kas/bank perusahaan wajib dipilih jika pekerja dibayar dari perusahaan.';
                redirect_detail($id, 'workers');
            }

            if ($paymentStatus === 'paid' && !$paidDate) {
                $paidDate = date('Y-m-d');
            }

            if ($action === 'add_worker') {
                $pdo->beginTransaction();

                $description = 'Biaya pekerja: ' . $workerName . ' (' . $workerType . ' - ' . $paymentType . ', ' . $workDays . ' hari x ' . rp($rate) . ', lembur ' . $overtimeHours . ' jam)';

                $stmt = $pdo->prepare("
                    INSERT INTO project_workers
                    (project_id, employee_id, worker_name, worker_type, payment_type, start_date, end_date, work_days, rate, overtime_hours, overtime_rate, overtime_amount, bonus_amount, deduction_amount, total_amount, funding_source, person_name, personal_employee_id, operational_fund_id, account_id, payment_method, transaction_id, notes, payment_status, paid_date)
                    VALUES
                    (:project_id, :employee_id, :worker_name, :worker_type, :payment_type, :start_date, :end_date, :work_days, :rate, :overtime_hours, :overtime_rate, :overtime_amount, :bonus_amount, :deduction_amount, :total_amount, :funding_source, :person_name, :personal_employee_id, :operational_fund_id, :account_id, :payment_method, NULL, :notes, :payment_status, :paid_date)
                ");
                $stmt->execute([
                    ':project_id' => $id,
                    ':employee_id' => $employeeId > 0 ? $employeeId : null,
                    ':worker_name' => $workerName,
                    ':worker_type' => $workerType,
                    ':payment_type' => $paymentType,
                    ':start_date' => $startDate ?: null,
                    ':end_date' => $endDate ?: null,
                    ':work_days' => $workDays,
                    ':rate' => $rate,
                    ':overtime_hours' => $overtimeHours,
                    ':overtime_rate' => $overtimeRate,
                    ':overtime_amount' => $overtimeHours * $overtimeRate,
                    ':bonus_amount' => $bonusAmount,
                    ':deduction_amount' => $deductionAmount,
                    ':total_amount' => $totalAmount,
                    ':funding_source' => $fundingSource,
                    ':person_name' => $fundingSource === 'person' ? $personName : null,
                    ':personal_employee_id' => ($fundingSource === 'person' && $personalEmployeeId) ? $personalEmployeeId : null,
                    ':operational_fund_id' => $fundingSource === 'operational_fund' ? $operationalFundId : null,
                    ':account_id' => $fundingSource === 'company' ? $accountId : null,
                    ':payment_method' => $fundingSource === 'company' ? $paymentMethod : 'other',
                    ':notes' => $notes ?: null,
                    ':payment_status' => $paymentStatus,
                    ':paid_date' => $paymentStatus === 'paid' ? $paidDate : null
                ]);
                $workerId = (int) $pdo->lastInsertId();

                $trx = $pdo->prepare("
                    INSERT INTO project_transactions
                    (project_id, transaction_date, transaction_type, category, description, funding_source, person_name, personal_employee_id, operational_fund_id, account_id, payment_method, amount, is_asset, source_module, source_id)
                    VALUES
                    (:project_id, CURDATE(), 'expense', 'Biaya Pekerja', :description, :funding_source, :person_name, :personal_employee_id, :operational_fund_id, :account_id, :payment_method, :amount, 0, 'worker', :source_id)
                ");
                $trx->execute([
                    ':project_id' => $id,
                    ':description' => $description,
                    ':funding_source' => $fundingSource,
                    ':person_name' => $fundingSource === 'person' ? $personName : null,
                    ':personal_employee_id' => ($fundingSource === 'person' && $personalEmployeeId) ? $personalEmployeeId : null,
                    ':operational_fund_id' => $fundingSource === 'operational_fund' ? $operationalFundId : null,
                    ':account_id' => $fundingSource === 'company' ? $accountId : null,
                    ':payment_method' => $fundingSource === 'company' ? $paymentMethod : 'other',
                    ':amount' => $totalAmount,
                    ':source_id' => $workerId
                ]);
                $transactionId = (int) $pdo->lastInsertId();

                upload_project_transaction_receipts($_FILES['receipt_images'] ?? null, $transactionId, $pdo);

                $stmt = $pdo->prepare("UPDATE project_workers SET transaction_id = :transaction_id WHERE id = :worker_id AND project_id = :project_id");
                $stmt->execute([':transaction_id' => $transactionId, ':worker_id' => $workerId, ':project_id' => $id]);

                if ($workerType === 'employee') {
                    if (function_exists('finance_delete_project_transaction_if_exists')) {
                        finance_delete_project_transaction_if_exists($pdo, $transactionId);
                    }
                } elseif ($paymentStatus === 'paid') {
                    if (function_exists('finance_sync_project_transaction')) {
                        finance_sync_project_transaction($pdo, $transactionId, $_SESSION['user_id'] ?? null);
                    }
                } else {
                    if (function_exists('finance_delete_project_transaction_if_exists')) {
                        finance_delete_project_transaction_if_exists($pdo, $transactionId);
                    }
                }

                $pdo->commit();
                keep_project_new_uploads();
                $_SESSION['success'] = 'Pekerja berhasil ditambahkan.';
            } else {
                $workerId = (int) ($_POST['worker_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT transaction_id FROM project_workers WHERE id = :worker_id AND project_id = :project_id LIMIT 1");
                $stmt->execute([':worker_id' => $workerId, ':project_id' => $id]);
                $oldWorker = $stmt->fetch(PDO::FETCH_ASSOC);

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE project_workers
                    SET employee_id = :employee_id,
                        worker_name = :worker_name,
                        worker_type = :worker_type,
                        payment_type = :payment_type,
                        start_date = :start_date,
                        end_date = :end_date,
                        work_days = :work_days,
                        rate = :rate,
                        overtime_hours = :overtime_hours,
                        overtime_rate = :overtime_rate,
                        overtime_amount = :overtime_amount,
                        bonus_amount = :bonus_amount,
                        deduction_amount = :deduction_amount,
                        total_amount = :total_amount,
                        funding_source = :funding_source,
                        person_name = :person_name,
                        personal_employee_id = :personal_employee_id,
                        operational_fund_id = :operational_fund_id,
                        account_id = :account_id,
                        payment_method = :payment_method,
                        payment_status = :payment_status,
                        paid_date = :paid_date,
                        notes = :notes
                    WHERE id = :worker_id AND project_id = :project_id
                ");
                $stmt->execute([
                    ':worker_id' => $workerId,
                    ':project_id' => $id,
                    ':employee_id' => $employeeId > 0 ? $employeeId : null,
                    ':worker_name' => $workerName,
                    ':worker_type' => $workerType,
                    ':payment_type' => $paymentType,
                    ':start_date' => $startDate ?: null,
                    ':end_date' => $endDate ?: null,
                    ':work_days' => $workDays,
                    ':rate' => $rate,
                    ':overtime_hours' => $overtimeHours,
                    ':overtime_rate' => $overtimeRate,
                    ':overtime_amount' => $overtimeHours * $overtimeRate,
                    ':bonus_amount' => $bonusAmount,
                    ':deduction_amount' => $deductionAmount,
                    ':total_amount' => $totalAmount,
                    ':funding_source' => $fundingSource,
                    ':person_name' => $fundingSource === 'person' ? $personName : null,
                    ':personal_employee_id' => ($fundingSource === 'person' && $personalEmployeeId) ? $personalEmployeeId : null,
                    ':operational_fund_id' => $fundingSource === 'operational_fund' ? $operationalFundId : null,
                    ':account_id' => $fundingSource === 'company' ? $accountId : null,
                    ':payment_method' => $fundingSource === 'company' ? $paymentMethod : 'other',
                    ':payment_status' => $paymentStatus,
                    ':paid_date' => $paymentStatus === 'paid' ? $paidDate : null,
                    ':notes' => $notes ?: null
                ]);

                if (!empty($oldWorker['transaction_id'])) {
                    $description = 'Biaya pekerja: ' . $workerName . ' (' . $workerType . ' - ' . $paymentType . ', ' . $workDays . ' hari x ' . rp($rate) . ', lembur ' . $overtimeHours . ' jam)';
                    $trx = $pdo->prepare("
                        UPDATE project_transactions
                        SET description = :description,
                            funding_source = :funding_source,
                            person_name = :person_name,
                            personal_employee_id = :personal_employee_id,
                            operational_fund_id = :operational_fund_id,
                            account_id = :account_id,
                            payment_method = :payment_method,
                            amount = :amount,
                            source_module = 'worker',
                            source_id = :source_id
                        WHERE id = :transaction_id AND project_id = :project_id
                    ");
                    $trx->execute([
                        ':transaction_id' => $oldWorker['transaction_id'],
                        ':project_id' => $id,
                        ':description' => $description,
                        ':funding_source' => $fundingSource,
                        ':person_name' => $fundingSource === 'person' ? $personName : null,
                        ':personal_employee_id' => ($fundingSource === 'person' && $personalEmployeeId) ? $personalEmployeeId : null,
                        ':operational_fund_id' => $fundingSource === 'operational_fund' ? $operationalFundId : null,
                        ':account_id' => $fundingSource === 'company' ? $accountId : null,
                        ':payment_method' => $fundingSource === 'company' ? $paymentMethod : 'other',
                        ':amount' => $totalAmount,
                        ':source_id' => $workerId
                    ]);
                }

                if (!empty($oldWorker['transaction_id'])) {
                    if ($workerType === 'employee') {
                        if (function_exists('finance_delete_project_transaction_if_exists')) {
                            finance_delete_project_transaction_if_exists($pdo, (int) $oldWorker['transaction_id']);
                        }
                    } elseif ($paymentStatus === 'paid') {
                        if (function_exists('finance_sync_project_transaction')) {
                            finance_sync_project_transaction($pdo, (int) $oldWorker['transaction_id'], $_SESSION['user_id'] ?? null);
                        }
                    } else {
                        if (function_exists('finance_delete_project_transaction_if_exists')) {
                            finance_delete_project_transaction_if_exists($pdo, (int) $oldWorker['transaction_id']);
                        }
                    }

                    upload_project_transaction_receipts($_FILES['receipt_images'] ?? null, (int) $oldWorker['transaction_id'], $pdo);
                }

                $pdo->commit();
                keep_project_new_uploads();
                $_SESSION['success'] = 'Data pekerja berhasil diperbarui.';
            }

            redirect_detail($id, 'workers');
        }

        if ($action === 'delete_worker') {
            $workerId = (int) ($_POST['worker_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT transaction_id FROM project_workers WHERE id = :worker_id AND project_id = :project_id LIMIT 1");
            $stmt->execute([':worker_id' => $workerId, ':project_id' => $id]);
            $worker = $stmt->fetch(PDO::FETCH_ASSOC);
            $workerFilesToDelete = [];
            if (!empty($worker['transaction_id'])) {
                $receiptStmt = $pdo->prepare("SELECT image_path FROM project_transaction_receipts WHERE transaction_id=:transaction_id");
                $receiptStmt->execute([':transaction_id' => (int)$worker['transaction_id']]);
                $workerFilesToDelete = $receiptStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM project_workers WHERE id = :worker_id AND project_id = :project_id");
            $stmt->execute([':worker_id' => $workerId, ':project_id' => $id]);

            if (!empty($worker['transaction_id'])) {
                if (function_exists('finance_delete_project_transaction_if_exists')) {
                    finance_delete_project_transaction_if_exists($pdo, (int) $worker['transaction_id']);
                }
                $stmt = $pdo->prepare("DELETE FROM project_transactions WHERE id = :transaction_id AND project_id = :project_id");
                $stmt->execute([':transaction_id' => $worker['transaction_id'], ':project_id' => $id]);
            }

            $pdo->commit();
            keep_project_new_uploads();
            foreach (array_unique($workerFilesToDelete) as $file) upload_delete_file($file);

            $_SESSION['success'] = 'Pekerja berhasil dihapus.';
            redirect_detail($id, 'workers');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cleanup_project_new_uploads();
        $_SESSION['error'] = 'Terjadi kesalahan: ' . $e->getMessage();
        redirect_detail($id, $activeTab);
    }
}

/*
|--------------------------------------------------------------------------
| DATA PROJECT
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT p.*, c.client_name, c.contact_person, c.phone AS client_phone, e.full_name AS leader_name
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    LEFT JOIN employees e ON p.leader_id = e.id
    WHERE p.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    redirect('pages/projects/index.php');
}

$termsStmt = $pdo->prepare("SELECT * FROM project_payment_terms WHERE project_id = :id ORDER BY due_date ASC, id ASC");
$termsStmt->execute([':id' => $id]);
$terms = $termsStmt->fetchAll(PDO::FETCH_ASSOC);

$fundsStmt = $pdo->prepare("SELECT * FROM project_funds WHERE project_id = :id ORDER BY id DESC");
$fundsStmt->execute([':id' => $id]);
$funds = $fundsStmt->fetchAll(PDO::FETCH_ASSOC);

$transactionsStmt = $pdo->prepare("SELECT * FROM project_transactions WHERE project_id = :id ORDER BY transaction_date DESC, id DESC");
$transactionsStmt->execute([':id' => $id]);
$transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

$financeAccounts = [];
$financeAccountMap = [];
try {
    $financeAccountsStmt = $pdo->query("SELECT id, account_name, account_type, bank_name, current_balance FROM finance_accounts WHERE status = 'active' ORDER BY is_default DESC, account_name ASC");
    $financeAccounts = $financeAccountsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($financeAccounts as $acc) { $financeAccountMap[(int)$acc['id']] = $acc; }
} catch (Throwable $e) {
    $financeAccounts = [];
    $financeAccountMap = [];
}

$operationalFunds = [];
$operationalFundMap = [];
try {
    $operationalFundsStmt = $pdo->query("SELECT id, fund_code, employee_id, person_name, current_balance, status FROM operational_funds WHERE status = 'open' ORDER BY person_name ASC, id DESC");
    $operationalFunds = $operationalFundsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($operationalFunds as $fund) { $operationalFundMap[(int)$fund['id']] = $fund; }
} catch (Throwable $e) {
    $operationalFunds = [];
    $operationalFundMap = [];
}

$workersStmt = $pdo->prepare("SELECT * FROM project_workers WHERE project_id = :id ORDER BY id DESC");
$workersStmt->execute([':id' => $id]);
$workers = $workersStmt->fetchAll(PDO::FETCH_ASSOC);

$galleryStmt = $pdo->prepare("SELECT * FROM project_galleries WHERE project_id = :id ORDER BY id DESC");
$galleryStmt->execute([':id' => $id]);
$gallery = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);

$termEvidence = fetch_project_evidence_grouped($pdo, 'project_term_receipts', 'term_id', array_column($terms, 'id'));
$fundEvidence = fetch_project_evidence_grouped($pdo, 'project_fund_receipts', 'fund_id', array_column($funds, 'id'));
$transactionEvidence = fetch_project_evidence_grouped($pdo, 'project_transaction_receipts', 'transaction_id', array_column($transactions, 'id'));
$workerEvidence = fetch_project_evidence_grouped($pdo, 'project_worker_receipts', 'worker_id', array_column($workers, 'id'));

$transactionAssetEvidence = [];
$transactionAssetInfo = [];
if (!empty($transactions) && project_table_exists($pdo, 'assets')) {
    $assetInfoStmt = $pdo->prepare("
        SELECT pt.id transaction_id,
               COALESCE(purchase_asset.id, sale_asset.id, rental_asset.id, maintenance_asset.id) asset_id,
               COALESCE(purchase_asset.asset_code, sale_asset.asset_code, rental_asset.asset_code, maintenance_asset.asset_code) asset_code,
               COALESCE(purchase_asset.asset_name, sale_asset.asset_name, rental_asset.asset_name, maintenance_asset.asset_name) asset_name
        FROM project_transactions pt
        LEFT JOIN assets purchase_asset ON pt.is_asset=1 AND purchase_asset.source_transaction_id=pt.id
        LEFT JOIN asset_sales sale ON pt.source_module='asset_sale' AND sale.id=pt.source_id
        LEFT JOIN assets sale_asset ON sale_asset.id=sale.asset_id
        LEFT JOIN asset_rental_payments rental_payment ON pt.source_module='asset_rental' AND rental_payment.id=pt.source_id
        LEFT JOIN assets rental_asset ON rental_asset.id=rental_payment.asset_id
        LEFT JOIN asset_maintenance maintenance ON pt.source_module='asset_maintenance' AND maintenance.id=pt.source_id
        LEFT JOIN assets maintenance_asset ON maintenance_asset.id=maintenance.asset_id
        WHERE pt.project_id=:project_id
    ");
    $assetInfoStmt->execute([':project_id'=>$id]);
    foreach ($assetInfoStmt->fetchAll(PDO::FETCH_ASSOC) as $assetInfo) {
        if (!empty($assetInfo['asset_id'])) $transactionAssetInfo[(int)$assetInfo['transaction_id']] = $assetInfo;
    }
}
if (!empty($transactions) && project_table_exists($pdo, 'project_asset_photos') && project_table_exists($pdo, 'assets')) {
    $transactionIdsForAssets = array_values(array_unique(array_filter(array_map(
        static fn($trx) => (int) ($trx['id'] ?? 0),
        array_filter($transactions, static fn($trx) => (int) ($trx['is_asset'] ?? 0) === 1)
    ))));

    if (!empty($transactionIdsForAssets)) {
        $assetColumns = project_table_columns($pdo, 'assets');
        if (isset($assetColumns['source_transaction_id'])) {
            $placeholders = implode(',', array_fill(0, count($transactionIdsForAssets), '?'));
            $assetStmt = $pdo->prepare("SELECT id, source_transaction_id FROM assets WHERE source_transaction_id IN ({$placeholders})");
            $assetStmt->execute($transactionIdsForAssets);
            $assetRows = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

            $assetIds = array_column($assetRows, 'id');
            $assetEvidenceByAsset = fetch_project_evidence_grouped($pdo, 'project_asset_photos', 'asset_id', $assetIds);

            foreach ($assetRows as $assetRow) {
                $transactionId = (int) ($assetRow['source_transaction_id'] ?? 0);
                $assetId = (int) ($assetRow['id'] ?? 0);
                if ($transactionId > 0 && !empty($assetEvidenceByAsset[$assetId])) {
                    $transactionAssetEvidence[$transactionId] = array_merge(
                        $transactionAssetEvidence[$transactionId] ?? [],
                        $assetEvidenceByAsset[$assetId]
                    );
                }
            }
        }
    }
}


/* Statistik absensi karyawan internal berdasarkan employee_id dan periode pekerja. */
$workerAttendanceStats = [];
if (!empty($workers)) {
    foreach ($workers as $w) {
        if (($w['worker_type'] ?? '') === 'employee') {
            $employeeIdForStats = (int) ($w['employee_id'] ?? 0);
            if ($employeeIdForStats > 0 && !empty($w['start_date']) && !empty($w['end_date'])) {
                $workerAttendanceStats[$w['id']] = project_worker_attendance_summary($pdo, $employeeIdForStats, $w['start_date'], $w['end_date']);
            } else {
                $workerAttendanceStats[$w['id']] = null;
            }
        }
    }
}

$employeesStmt = $pdo->query("
    SELECT id, full_name, position, salary_type, salary_amount
    FROM employees
    WHERE employment_status = 'active'
    ORDER BY full_name ASC
");
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalTerms = 0;
$totalPaidTerms = 0;
$totalTermTax = 0;
$totalPaidNet = 0;
$overdueTerms = 0;
foreach ($terms as $term) {
    $totalTerms += (float) $term['amount'];
    if ($term['status'] === 'paid') {
        $totalPaidTerms += (float) $term['amount'];
        $totalTermTax += (float)($term['tax_amount'] ?? 0);
        $totalPaidNet += (float)($term['received_amount'] ?? 0) > 0 ? (float)$term['received_amount'] : max(0, (float)$term['amount'] - (float)($term['tax_amount'] ?? 0));
    }
    if ($term['status'] !== 'paid' && strtotime($term['due_date']) < strtotime(date('Y-m-d'))) {
        $overdueTerms++;
    }
}
$effectiveTermTaxRate = $totalPaidTerms > 0 ? ($totalTermTax / $totalPaidTerms) * 100 : 0;

$paidWorkerIdsForFunding = [];
foreach ($workers as $w) {
    if (($w['payment_status'] ?? 'unpaid') === 'paid') {
        $paidWorkerIdsForFunding[(int)($w['id'] ?? 0)] = true;
    }
}
$fundingRows = array_values(array_filter($transactions, static function ($trx) use ($paidWorkerIdsForFunding) {
    if (($trx['transaction_type'] ?? '') !== 'expense') {
        return false;
    }
    if (($trx['source_module'] ?? '') === 'worker') {
        return isset($paidWorkerIdsForFunding[(int)($trx['source_id'] ?? 0)]);
    }
    return true;
}));

$totalCompanyFund = 0;
$totalPersonFund = 0;
$companyFundSummary = [];
$personFundSummary = [];
foreach ($fundingRows as $row) {
    $amount = (float) ($row['amount'] ?? 0);
    if (($row['funding_source'] ?? 'company') === 'person') {
        $totalPersonFund += $amount;
        $name = trim((string) ($row['person_name'] ?? '')) ?: 'Personal Tidak Bernama';
        if (!isset($personFundSummary[$name])) {
            $personFundSummary[$name] = ['name' => $name, 'amount' => 0, 'paid' => 0, 'remaining' => 0];
        }
        $personFundSummary[$name]['amount'] += $amount;
    } else {
        $totalCompanyFund += $amount;
        $accountIdForSummary = (int)($row['account_id'] ?? 0);
        $accountName = 'Kas Perusahaan';
        if ($accountIdForSummary > 0 && isset($financeAccountMap[$accountIdForSummary])) {
            $accountName = (string)($financeAccountMap[$accountIdForSummary]['account_name'] ?? 'Kas Perusahaan');
        }
        if (!isset($companyFundSummary[$accountName])) {
            $companyFundSummary[$accountName] = ['name' => $accountName, 'amount' => 0, 'count' => 0];
        }
        $companyFundSummary[$accountName]['amount'] += $amount;
        $companyFundSummary[$accountName]['count']++;
    }
}
$totalAllFund = $totalCompanyFund + $totalPersonFund;

if (!empty($personFundSummary) && project_table_exists($pdo, 'debts')) {
    $debtStmt = $pdo->prepare("
        SELECT related_name, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(paid_amount), 0) AS paid_amount
        FROM debts
        WHERE type = 'debt'
          AND project_id = :project_id
          AND source_table = 'project_transactions'
        GROUP BY related_name
    ");
    $debtStmt->execute([':project_id' => $id]);
    foreach ($debtStmt->fetchAll(PDO::FETCH_ASSOC) as $debtRow) {
        $name = trim((string) ($debtRow['related_name'] ?? '')) ?: 'Personal Tidak Bernama';
        if (!isset($personFundSummary[$name])) {
            $personFundSummary[$name] = ['name' => $name, 'amount' => (float) ($debtRow['amount'] ?? 0), 'paid' => 0, 'remaining' => 0];
        }
        $personFundSummary[$name]['paid'] = (float) ($debtRow['paid_amount'] ?? 0);
    }
}
foreach ($personFundSummary as $name => $row) {
    $personFundSummary[$name]['remaining'] = max(0, (float) $row['amount'] - (float) $row['paid']);
}
$totalPersonFundPaid = array_sum(array_column($personFundSummary, 'paid'));
$totalPersonFundRemaining = max(0, $totalPersonFund - $totalPersonFundPaid);

$totalClientIncome = 0;
$totalExpense = 0;
$totalWorkerCost = 0;
$totalAssetExpense = 0;
$assetPurchaseTotal = $assetSaleTotal = $assetRentalTotal = $assetMaintenanceTotal = 0;
$projectAssetIds = [];
foreach ($transactions as $trx) {
    if ($trx['transaction_type'] === 'income' && $trx['category'] !== 'Modal Project') {
        $totalClientIncome += (float) $trx['amount'];
    }
    if ($trx['transaction_type'] === 'expense') {
        $totalExpense += (float) $trx['amount'];
    }
    if ($trx['category'] === 'Biaya Pekerja') {
        $totalWorkerCost += (float) $trx['amount'];
    }
    if ((int) $trx['is_asset'] === 1) {
        $totalAssetExpense += (float) $trx['amount'];
        $assetPurchaseTotal += (float) $trx['amount'];
    }
    if (($trx['source_module'] ?? '') === 'asset_sale') $assetSaleTotal += (float)$trx['amount'];
    if (($trx['source_module'] ?? '') === 'asset_rental') $assetRentalTotal += (float)$trx['amount'];
    if (($trx['source_module'] ?? '') === 'asset_maintenance') $assetMaintenanceTotal += (float)$trx['amount'];
    if (!empty($transactionAssetInfo[(int)$trx['id']]['asset_id'])) $projectAssetIds[(int)$transactionAssetInfo[(int)$trx['id']]['asset_id']] = true;
}

$contractValue = (float) $project['contract_value'];
$grossProfit = $totalClientIncome - $totalExpense;
$projectedProfit = $contractValue - $totalExpense;
$cashPosition = $totalClientIncome - $totalExpense;
$collectionProgress = $contractValue > 0 ? min(100, ($totalPaidTerms / $contractValue) * 100) : 0;
$expenseProgress = $contractValue > 0 ? min(100, ($totalExpense / $contractValue) * 100) : 0;

$daysLeft = null;
if (!empty($project['end_date'])) {
    $daysLeft = floor((strtotime($project['end_date']) - strtotime(date('Y-m-d'))) / 86400);
}

$contractDaysLeft = null;
if (!empty($project['contract_end_date'])) {
    $contractDaysLeft = floor((strtotime($project['contract_end_date']) - strtotime(date('Y-m-d'))) / 86400);
}

$statusMap = [
    'planning' => ['label' => 'Planning', 'class' => 'primary'],
    'ongoing' => ['label' => 'Ongoing', 'class' => 'success'],
    'completed' => ['label' => 'Completed', 'class' => 'dark'],
    'cancelled' => ['label' => 'Cancelled', 'class' => 'danger'],
];

$pageTitle = 'Detail Project - ' . $project['project_name'];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<style>
    .project-hero {
        border-radius: 28px;
        padding: 28px;
        color: #fff;
        background:
            radial-gradient(circle at 15% 20%, rgba(255,255,255,.22), transparent 28%),
            linear-gradient(135deg, #0f5132 0%, #116149 42%, #0f766e 100%);
        box-shadow: 0 24px 60px rgba(15, 81, 50, .20);
        position: relative;
        overflow: hidden;
    }
    .project-hero:after {
        content: "";
        position: absolute;
        width: 320px;
        height: 320px;
        border-radius: 999px;
        right: -110px;
        top: -130px;
        background: rgba(255,255,255,.12);
    }
    .hero-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 16px;
    }
    .hero-pill {
        border: 1px solid rgba(255,255,255,.24);
        background: rgba(255,255,255,.14);
        color: #fff;
        border-radius: 999px;
        padding: 8px 13px;
        font-size: 13px;
        backdrop-filter: blur(10px);
    }
    .metric-card {
        border: 0;
        border-radius: 24px;
        background: #fff;
        padding: 22px;
        min-height: 126px;
        box-shadow: 0 16px 40px rgba(15, 23, 42, .07);
        transition: .2s ease;
        border-left: 5px solid #198754;
    }
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 24px 52px rgba(15, 23, 42, .09);
    }
    .metric-label {
        color: #64748b;
        font-weight: 700;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .metric-value {
        font-size: 24px;
        font-weight: 850;
        margin-top: 8px;
        color: #111827;
    }
    .metric-sub {
        color: #64748b;
        font-size: 13px;
        margin-top: 6px;
    }
    .modern-panel {
        border: 0;
        border-radius: 26px;
        background: #fff;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .07);
    }
    .nav-project-tabs {
        gap: 10px;
        border: 0;
        background: #f1f5f9;
        padding: 8px;
        border-radius: 22px;
    }
    .nav-project-tabs .nav-link {
        border: 0;
        border-radius: 16px;
        color: #475569;
        font-weight: 750;
        padding: 10px 14px;
    }
    .nav-project-tabs .nav-link.active {
        color: #fff;
        background: #198754;
        box-shadow: 0 10px 24px rgba(25, 135, 84, .25);
    }
    .finance-chart {
        height: 260px;
        width: 100%;
        position: relative;
    }
    .line-visual {
        height: 120px;
        width: 100%;
    }
    .table-modern {
        vertical-align: middle;
    }
    .table-modern thead th {
        background: #f8fafc;
        color: #334155;
        border-bottom: 1px solid #e5e7eb;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .table-modern td {
        border-color: #eef2f7;
    }
    .status-dot {
        width: 10px;
        height: 10px;
        display: inline-block;
        border-radius: 50%;
        margin-right: 6px;
        background: #198754;
    }
    .empty-state {
        border: 1px dashed #cbd5e1;
        border-radius: 20px;
        padding: 26px;
        background: #f8fafc;
        text-align: center;
        color: #64748b;
    }
    .modal-content {
        border: 0;
        border-radius: 24px;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .25);
    }
    .modal-header {
        border-bottom: 1px solid #eef2f7;
    }
    .form-control, .form-select {
        border-radius: 14px;
        padding: 11px 13px;
    }
    .btn {
        border-radius: 14px;
        font-weight: 700;
    }
    .mini-card {
        border: 1px solid #eef2f7;
        border-radius: 20px;
        padding: 16px;
        background: #fff;
    }
    @media (max-width: 768px) {
        .project-hero { padding: 22px; }
        .metric-value { font-size: 20px; }
        .nav-project-tabs { overflow-x: auto; flex-wrap: nowrap; }
    }

.mini-total-card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:18px;padding:14px 16px;height:100%;display:flex;justify-content:space-between;gap:12px;align-items:center}.mini-total-card span{font-size:.82rem;color:#64748b;font-weight:700}.mini-total-card strong{font-size:1rem;color:#0f172a}.table-tools{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}.table-tools .form-control{max-width:320px;border-radius:14px}.sortable-th{cursor:pointer;user-select:none}.sortable-th:after{content:' ↕';font-size:.72rem;color:#94a3b8}.doc-card-icon{height:170px;background:#f8fafc;display:flex;align-items:center;justify-content:center;font-size:52px;color:#64748b;border-bottom:1px solid #e5e7eb}


/* Nukari project modal fix: keep long worker forms usable on smaller screens */
.project-worker-modal-dialog {
    max-width: min(1180px, calc(100vw - 2rem));
}
.project-worker-modal-content {
    max-height: calc(100vh - 2rem);
}
.project-worker-modal-content .modal-body {
    overflow-y: auto;
}
.project-worker-modal-content .modal-footer {
    position: sticky;
    bottom: 0;
    background: #fff;
    z-index: 2;
}
@media (max-width: 768px) {
    .project-worker-modal-dialog {
        margin: .5rem auto;
        max-width: calc(100vw - 1rem);
    }
}

</style>

<main class="main-content">

    <div class="project-hero mb-4">
        <div class="position-relative" style="z-index:2;">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="hero-pill d-inline-flex align-items-center mb-3">
                        <i class="bi bi-folder2-open me-2"></i>
                        <?= e($project['project_code'] ?: 'PROJECT'); ?>
                    </div>
                    <h1 class="fw-bold mb-2"><?= e($project['project_name']); ?></h1>
                    <p class="mb-0 opacity-75">
                        <?= e($project['description'] ?: 'Kelola termin, pendanaan otomatis, pengeluaran, pekerja, dan progress project dalam satu halaman.'); ?>
                    </p>
                </div>
                <div class="text-end">
                    <?php $status = $statusMap[$project['status']] ?? $statusMap['planning']; ?>
                    <span class="badge bg-light text-success rounded-pill px-3 py-2 mb-2"><?= e($status['label']); ?></span>
                    <div class="fw-bold fs-4"><?= rp($contractValue); ?></div>
                    <div class="opacity-75 small">Nilai Kontrak</div>
                    <a href="<?= base_url('pages/projects/report.php?id=' . $id); ?>" target="_blank" class="btn btn-sm btn-light mt-2"><i class="bi bi-printer me-1"></i>Laporan Project</a>
                </div>
            </div>

            <div class="hero-meta">
                <span class="hero-pill"><i class="bi bi-person-badge me-1"></i> Client: <?= e($project['client_name'] ?: '-'); ?></span>
                <span class="hero-pill"><i class="bi bi-person-check me-1"></i> Leader: <?= e($project['leader_name'] ?: '-'); ?></span>
                <span class="hero-pill"><i class="bi bi-geo-alt me-1"></i> <?= e($project['project_location'] ?: '-'); ?></span>
                <span class="hero-pill"><i class="bi bi-diagram-3 me-1"></i> <?= e(project_type_label($project['project_type'] ?? 'one_time')); ?></span>
                <span class="hero-pill"><i class="bi bi-calendar-event me-1"></i> <?= safe_date($project['start_date']); ?> - <?= safe_date($project['end_date']); ?></span>
                <?php if (($project['project_type'] ?? 'one_time') === 'ongoing'): ?>
                    <span class="hero-pill"><i class="bi bi-repeat me-1"></i> Tagihan: <?= e(billing_label($project['billing_cycle'] ?? 'none')); ?></span>
                    <?php if (!empty($project['contract_number'])): ?>
                        <span class="hero-pill"><i class="bi bi-file-earmark-text me-1"></i> Kontrak: <?= e($project['contract_number']); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="hero-pill"><i class="bi bi-hourglass-split me-1"></i>
                    <?= $daysLeft === null ? 'Deadline belum diatur' : ($daysLeft >= 0 ? $daysLeft . ' hari tersisa' : abs($daysLeft) . ' hari lewat'); ?>
                </span>
            </div>
        </div>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success rounded-4 shadow-sm">
            <i class="bi bi-check-circle me-2"></i><?= e($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger rounded-4 shadow-sm">
            <i class="bi bi-exclamation-triangle me-2"></i><?= e($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="modern-panel p-3 p-lg-4 mb-4" style="border-left:5px solid #f59f00;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-1"><i class="bi bi-receipt-cutoff text-warning me-2"></i>Ringkasan Potongan Pajak Termin</h5>
                <div class="small text-muted">Selisih dihitung otomatis dari nilai tagihan dikurangi nominal bersih yang diterima.</div>
            </div>
            <a href="<?= base_url('pages/projects/detail.php?id='.$id.'&tab=terms'); ?>" class="btn btn-sm btn-outline-warning rounded-pill">Lihat Rincian Termin</a>
        </div>
        <div class="row g-3">
            <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Tagihan Sudah Dibayar</span><strong><?= rp($totalPaidTerms); ?></strong><small class="text-muted">Nilai sebelum pajak</small></div></div>
            <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Selisih Dipotong Client</span><strong class="text-danger">- <?= rp($totalTermTax); ?></strong><small class="text-muted">Potongan pajak otomatis</small></div></div>
            <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Dana Bersih Termin</span><strong class="text-success"><?= rp($totalPaidNet); ?></strong><small class="text-muted">Benar-benar diterima</small></div></div>
            <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Persentase Potongan</span><strong><?= pct($effectiveTermTaxRate); ?></strong><small class="text-muted">Dari termin yang sudah dibayar</small></div></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="metric-card" style="border-left-color:#198754;">
                <div class="metric-label">Total Pemasukan Project</div>
                <div class="metric-value"><?= rp($totalClientIncome); ?></div>
                <div class="metric-sub">Dana bersih termin: <?= rp($totalPaidNet); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="metric-card" style="border-left-color:#0d6efd;">
                <div class="metric-label">Dana yang Digunakan</div>
                <div class="metric-value"><?= rp($totalAllFund); ?></div>
                <div class="metric-sub">Perusahaan <?= rp($totalCompanyFund); ?> · Personal <?= rp($totalPersonFund); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="metric-card" style="border-left-color:#f59f00;">
                <div class="metric-label">Total Biaya Project</div>
                <div class="metric-value"><?= rp($totalExpense); ?></div>
                <div class="metric-sub">Pekerja <?= rp($totalWorkerCost); ?> · Aset <?= rp($totalAssetExpense); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="metric-card" style="border-left-color:<?= $cashPosition >= 0 ? '#20c997' : '#dc3545'; ?>;">
                <div class="metric-label">Saldo Project Saat Ini</div>
                <div class="metric-value"><?= rp($cashPosition); ?></div>
                <div class="metric-sub">Perkiraan keuntungan: <?= rp($projectedProfit); ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="modern-panel p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Grafik Keuangan Project</h5>
                        <div class="text-muted small">Ringkasan nilai kontrak, pendapatan, pendanaan otomatis, biaya, dan cash position.</div>
                    </div>
                    <span class="badge bg-success-subtle text-success rounded-pill px-3 py-2">Live Summary</span>
                </div>
                <div class="finance-chart">
                    <canvas id="financeChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="modern-panel p-4 h-100">
                <h5 class="fw-bold mb-3">Progress Keuangan</h5>

                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-semibold">Persentase Pembayaran Client</span>
                        <span class="fw-bold"><?= pct($collectionProgress); ?></span>
                    </div>
                    <div class="progress rounded-pill" style="height:12px;">
                        <div class="progress-bar bg-success" style="width:<?= $collectionProgress; ?>%"></div>
                    </div>
                    <div class="small text-muted mt-1">Berdasarkan termin yang sudah lunas dibanding nilai kontrak.</div>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-semibold">Persentase Biaya terhadap Kontrak</span>
                        <span class="fw-bold"><?= pct($expenseProgress); ?></span>
                    </div>
                    <div class="progress rounded-pill" style="height:12px;">
                        <div class="progress-bar bg-warning" style="width:<?= $expenseProgress; ?>%"></div>
                    </div>
                    <div class="small text-muted mt-1">Perbandingan total biaya terhadap nilai kontrak.</div>
                </div>

                <div class="mini-card">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Tagihan Terlambat Dibayar</span>
                        <strong class="<?= $overdueTerms > 0 ? 'text-danger' : 'text-success'; ?>"><?= $overdueTerms; ?></strong>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Sisa Tagihan</span>
                        <strong><?= rp(max(0, $totalTerms - $totalPaidTerms)); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modern-panel p-3 p-lg-4 mb-4">
        <ul class="nav nav-tabs nav-project-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link <?= $activeTab === 'overview' ? 'active' : ''; ?>" href="<?= base_url('pages/projects/detail.php?id=' . $id . '&tab=overview'); ?>"><i class="bi bi-speedometer2 me-1"></i> Overview</a></li>
            <li class="nav-item"><a class="nav-link <?= $activeTab === 'terms' ? 'active' : ''; ?>" href="<?= base_url('pages/projects/detail.php?id=' . $id . '&tab=terms'); ?>"><i class="bi bi-calendar-check me-1"></i> Termin</a></li>
            <li class="nav-item"><a class="nav-link <?= $activeTab === 'funds' ? 'active' : ''; ?>" href="<?= base_url('pages/projects/detail.php?id=' . $id . '&tab=funds'); ?>"><i class="bi bi-wallet2 me-1"></i> Pendanaan</a></li>
            <li class="nav-item"><a class="nav-link <?= $activeTab === 'transactions' ? 'active' : ''; ?>" href="<?= base_url('pages/projects/detail.php?id=' . $id . '&tab=transactions'); ?>"><i class="bi bi-receipt me-1"></i> Transaksi</a></li>
            <li class="nav-item"><a class="nav-link <?= $activeTab === 'workers' ? 'active' : ''; ?>" href="<?= base_url('pages/projects/detail.php?id=' . $id . '&tab=workers'); ?>"><i class="bi bi-people me-1"></i> Pekerja</a></li>
            <li class="nav-item"><a class="nav-link <?= $activeTab === 'gallery' ? 'active' : ''; ?>" href="<?= base_url('pages/projects/detail.php?id=' . $id . '&tab=gallery'); ?>"><i class="bi bi-folder2-open me-1"></i> Dokumen & Gallery</a></li>
        </ul>

        <div class="pt-4">
            <?php if ($activeTab === 'overview'): ?>
                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Tagihan Sudah Dibayar</span><strong><?= rp($totalPaidTerms); ?></strong></div></div>
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Sisa Tagihan Client</span><strong><?= rp(max(0, $totalTerms - $totalPaidTerms)); ?></strong></div></div>
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Total Biaya Project</span><strong><?= rp($totalExpense); ?></strong></div></div>
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Perkiraan Keuntungan</span><strong class="<?= $projectedProfit >= 0 ? 'text-success' : 'text-danger'; ?>"><?= rp($projectedProfit); ?></strong></div></div>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h5 class="fw-bold mb-1">Ringkasan Aktivitas Aset</h5><div class="small text-muted"><?= count($projectAssetIds); ?> aset terhubung dengan aktivitas Project ini.</div></div><a href="<?= base_url('pages/projects/detail.php?id='.$id.'&tab=transactions'); ?>" class="btn btn-sm btn-outline-success rounded-pill">Lihat Transaksi</a></div>
                    <div class="row g-3">
                        <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span><i class="bi bi-cart-check me-1"></i>Pembelian Aset</span><strong><?= rp($assetPurchaseTotal); ?></strong></div></div>
                        <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span><i class="bi bi-cash-coin me-1"></i>Hasil Penjualan Aset</span><strong class="text-success"><?= rp($assetSaleTotal); ?></strong></div></div>
                        <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span><i class="bi bi-key me-1"></i>Pemasukan dari Sewa Aset</span><strong class="text-success"><?= rp($assetRentalTotal); ?></strong></div></div>
                        <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span><i class="bi bi-tools me-1"></i>Biaya Perawatan Aset</span><strong class="text-danger"><?= rp($assetMaintenanceTotal); ?></strong></div></div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        <h5 class="fw-bold mb-3">Informasi Project</h5>
                        <div class="table-responsive">
                            <table class="table table-modern">
                                <tr><td class="text-muted">Kode Project</td><td class="fw-semibold"><?= e($project['project_code'] ?: '-'); ?></td></tr>
                                <tr><td class="text-muted">Nama Project</td><td class="fw-semibold"><?= e($project['project_name']); ?></td></tr>
                                <tr><td class="text-muted">Client</td><td><?= e($project['client_name'] ?: '-'); ?></td></tr>
                                <tr><td class="text-muted">Contact Person</td><td><?= e($project['contact_person'] ?: '-'); ?></td></tr>
                                <tr><td class="text-muted">Lokasi</td><td><?= e($project['project_location'] ?: '-'); ?></td></tr>
                                <tr><td class="text-muted">Tipe Project</td><td><?= e(project_type_label($project['project_type'] ?? 'one_time')); ?></td></tr>
                                <?php if (($project['project_type'] ?? 'one_time') === 'ongoing'): ?>
                                    <tr><td class="text-muted">Nomor Kontrak</td><td><?= e($project['contract_number'] ?: '-'); ?></td></tr>
                                    <tr><td class="text-muted">Periode Kontrak</td><td><?= safe_date($project['contract_start_date']); ?> - <?= safe_date($project['contract_end_date']); ?></td></tr>
                                    <tr><td class="text-muted">Siklus Tagihan</td><td><?= e(billing_label($project['billing_cycle'] ?? 'none')); ?><?= ((float)($project['billing_amount'] ?? 0) > 0) ? ' · ' . rp($project['billing_amount']) : ''; ?></td></tr>
                                    <tr><td class="text-muted">Catatan Billing</td><td><?= nl2br(e($project['billing_notes'] ?: '-')); ?></td></tr>
                                <?php endif; ?>
                                <tr><td class="text-muted">Catatan</td><td><?= nl2br(e($project['notes'] ?: '-')); ?></td></tr>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <h5 class="fw-bold mb-3">Aktivitas Terbaru</h5>
                        <?php if (empty($transactions) && empty($terms) && empty($workers)): ?>
                            <div class="empty-state">Belum ada aktivitas project.</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach (array_slice($transactions, 0, 5) as $trx): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between gap-3">
                                            <div>
                                                <strong><?= e($trx['category'] ?: '-'); ?></strong>
                                                <div class="small text-muted"><?= e($trx['description'] ?: '-'); ?></div>
                                            </div>
                                            <div class="fw-bold <?= $trx['transaction_type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                                                <?= $trx['transaction_type'] === 'income' ? '+' : '-'; ?> <?= rp($trx['amount']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'terms'): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Termin Pembayaran</h5>
                        <div class="text-muted small">Atur jadwal tagihan, status pembayaran, dan tanggal jatuh tempo.</div>
                    </div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTermModal"><i class="bi bi-plus-lg me-1"></i> Tambah Termin</button>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Total Tagihan</span><strong><?= rp($totalTerms); ?></strong></div></div>
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Tagihan Sudah Dibayar</span><strong><?= rp($totalPaidTerms); ?></strong></div></div>
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Potongan Pajak</span><strong class="text-danger"><?= rp($totalTermTax); ?></strong></div></div>
                    <div class="col-md-6 col-xl-3"><div class="mini-total-card"><span>Dana Bersih Diterima</span><strong class="text-success"><?= rp($totalPaidNet); ?></strong></div></div>
                </div>

                <?php if (empty($terms)): ?>
                    <div class="empty-state">Belum ada termin. Klik tombol Tambah Termin untuk membuat jadwal pembayaran.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-modern align-middle">
                            <thead>
                                <tr>
                                    <th>Termin</th>
                                    <th>Nominal</th>
                                    <th>Potongan Pajak</th>
                                    <th>Diterima Bersih</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Status</th>
                                    <th>Catatan</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($terms as $term): ?>
                                    <?php
                                        $isOverdue = $term['status'] !== 'paid' && strtotime($term['due_date']) < strtotime(date('Y-m-d'));
                                        $badge = $term['status'] === 'paid' ? 'success' : ($isOverdue ? 'danger' : 'warning');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= e($term['term_name']); ?></div>
                                            <div class="small text-muted"><?= $term['percentage'] !== null ? pct($term['percentage']) : '-'; ?></div>
                                            <?php if (($term['posting_mode']??'posted')!=='posted'): ?><span class="badge text-bg-secondary rounded-pill mt-1"><?= ($term['posting_mode']??'')==='historical'?'Historis tanpa saldo':'Piutang saldo awal'; ?></span><?php endif; ?>
                                        </td>
                                        <td class="fw-bold"><?= rp($term['amount']); ?></td>
                                        <td><strong class="text-danger">- <?= rp($term['tax_amount'] ?? 0); ?></strong><div class="small text-muted"><?= (float)($term['tax_percentage'] ?? 0) > 0 ? pct($term['tax_percentage']) : 'Tanpa potongan'; ?></div></td>
                                        <td><strong class="text-success"><?= rp((float)($term['received_amount'] ?? 0) > 0 ? $term['received_amount'] : max(0, (float)$term['amount'] - (float)($term['tax_amount'] ?? 0))); ?></strong><div class="small text-muted">Selisih setelah pajak: <?= rp($term['tax_amount'] ?? 0); ?></div></td>
                                        <td><?= safe_date($term['due_date']); ?></td>
                                        <td><span class="badge text-bg-<?= $badge; ?> rounded-pill"><?= $isOverdue ? 'Terlambat Dibayar' : (($term['status'] ?? '') === 'paid' ? 'Sudah Dibayar' : 'Belum Dibayar'); ?></span></td>
                                        <td><?= e($term['notes'] ?: '-'); ?></td>
                                        <td class="text-end">
                                            <?php
                                                $termEvidenceItems = project_merge_evidence_items(
                                                    $termEvidence[(int) $term['id']] ?? [],
                                                    !empty($term['transaction_id']) ? ($transactionEvidence[(int) $term['transaction_id']] ?? []) : []
                                                );
                                                echo project_evidence_button('Bukti Pembayaran Termin - ' . ($term['term_name'] ?? ''), $termEvidenceItems);
                                            ?>
                                            <?php if ($term['status'] !== 'paid'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#payTerm<?= (int) $term['id']; ?>" title="Bayar termin"><i class="bi bi-check2-circle"></i></button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTerm<?= (int) $term['id']; ?>"><i class="bi bi-pencil"></i></button>
                                            <form method="POST" enctype="multipart/form-data" class="d-inline">
                                                <input type="hidden" name="action" value="delete_term">
                                                <input type="hidden" name="term_id" value="<?= (int) $term['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus termin ini?')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'funds'): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Sumber Dana Project</h5>
                        <div class="text-muted small">Ringkasan otomatis dari transaksi project. Tidak ada input pendanaan manual.</div>
                    </div>
                    <a class="btn btn-success" href="<?= base_url('pages/projects/detail.php?id=' . $id . '&tab=transactions'); ?>"><i class="bi bi-plus-lg me-1"></i> Tambah Transaksi</a>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4"><div class="mini-total-card"><span>Dibayar dari Kas/Bank</span><strong><?= rp($totalCompanyFund); ?></strong></div></div>
                    <div class="col-md-4"><div class="mini-total-card"><span>Dibayar Dulu oleh Personal</span><strong><?= rp($totalPersonFund); ?></strong></div></div>
                    <div class="col-md-4"><div class="mini-total-card"><span>Total Biaya yang Sudah Dibayar</span><strong><?= rp($totalAllFund); ?></strong></div></div>
                </div>

                <?php if (!empty($companyFundSummary)): ?>
                    <div class="modern-panel p-3 mb-3 border-0 bg-light">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <div>
                                <div class="fw-bold">Ringkasan Dana Perusahaan</div>
                                <div class="small text-muted">Dikelompokkan berdasarkan akun kas/bank yang digunakan pada transaksi project.</div>
                            </div>
                            <span class="badge text-bg-success rounded-pill">Total <?= rp($totalCompanyFund); ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-modern align-middle mb-0">
                                <thead><tr><th>Akun Kas/Bank</th><th class="text-end">Jumlah Transaksi</th><th class="text-end">Total Digunakan</th></tr></thead>
                                <tbody>
                                    <?php foreach ($companyFundSummary as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= e($row['name']); ?></td>
                                            <td class="text-end"><?= (int)$row['count']; ?></td>
                                            <td class="text-end fw-bold"><?= rp($row['amount']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($personFundSummary)): ?>
                    <div class="modern-panel p-3 mb-3 border-0 bg-light">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <div>
                                <div class="fw-bold">Ringkasan Talangan Personal</div>
                                <div class="small text-muted">Dihitung dari transaksi pengeluaran yang memakai sumber dana personal.</div>
                            </div>
                            <span class="badge text-bg-warning rounded-pill">Sisa Talangan <?= rp($totalPersonFundRemaining); ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-modern align-middle mb-0">
                                <thead><tr><th>Nama Lengkap</th><th class="text-end">Total Talangan</th><th class="text-end">Sudah Diganti</th><th class="text-end">Sisa</th></tr></thead>
                                <tbody>
                                    <?php foreach ($personFundSummary as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= e($row['name']); ?></td>
                                            <td class="text-end"><?= rp($row['amount']); ?></td>
                                            <td class="text-end text-success"><?= rp($row['paid']); ?></td>
                                            <td class="text-end fw-bold <?= $row['remaining'] > 0 ? 'text-danger' : 'text-success'; ?>"><?= rp($row['remaining']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($fundingRows)): ?>
                    <div class="empty-state">Belum ada pendanaan otomatis. Tambahkan pengeluaran di tab Transaksi terlebih dahulu.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-modern align-middle">
                            <thead>
                                <tr><th>Tanggal</th><th>Sumber</th><th>Nama/Akun</th><th>Kategori</th><th>Keterangan</th><th class="text-end">Nominal</th><th class="text-end">Bukti</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fundingRows as $row): ?>
                                    <?php
                                        $isPerson = (($row['funding_source'] ?? 'company') === 'person');
                                        $acc = !$isPerson && !empty($row['account_id']) ? ($financeAccountMap[(int)$row['account_id']] ?? null) : null;
                                        $sourceName = $isPerson ? (trim((string)($row['person_name'] ?? '')) ?: 'Personal Tidak Bernama') : ($acc['account_name'] ?? 'Kas Perusahaan');
                                    ?>
                                    <tr>
                                        <td><?= safe_date($row['transaction_date'] ?? $row['created_at'] ?? null); ?></td>
                                        <td><span class="badge text-bg-<?= $isPerson ? 'warning' : 'success'; ?> rounded-pill"><?= $isPerson ? 'Personal' : 'Perusahaan'; ?></span></td>
                                        <td class="fw-semibold"><?= e($sourceName); ?></td>
                                        <td><?= e($row['category'] ?? '-'); ?></td>
                                        <td><?= e($row['description'] ?: '-'); ?></td>
                                        <td class="text-end fw-bold"><?= rp($row['amount']); ?></td>
                                        <td class="text-end"><?= project_evidence_button('Bukti Transaksi Pendanaan', $transactionEvidence[(int)$row['id']] ?? []); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'transactions'): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Transaksi Project</h5>
                        <div class="text-muted small">Input pengeluaran, pendapatan client, pembelian aset, dan biaya operasional.</div>
                    </div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTransactionModal"><i class="bi bi-plus-lg me-1"></i> Tambah Transaksi</button>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-3"><div class="mini-total-card"><span>Total Pemasukan Project</span><strong><?= rp($totalClientIncome); ?></strong></div></div>
                    <div class="col-md-3"><div class="mini-total-card"><span>Total Biaya Project</span><strong><?= rp($totalExpense); ?></strong></div></div>
                    <div class="col-md-3"><div class="mini-total-card"><span>Biaya Pekerja</span><strong><?= rp($totalWorkerCost); ?></strong></div></div>
                    <div class="col-md-3"><div class="mini-total-card"><span>Saldo Project Saat Ini</span><strong><?= rp($cashPosition); ?></strong></div></div>
                </div>

                <?php if (empty($transactions)): ?>
                    <div class="empty-state">Belum ada transaksi project.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-modern align-middle">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Tipe</th>
                                    <th>Kategori</th>
                                    <th>Keterangan</th>
                                    <th>Sumber Dana</th>
                                    <th>Nominal</th>
                                    <th>Aktivitas Aset</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $trx): ?>
                                    <tr>
                                        <td><?= safe_date($trx['transaction_date'] ?? ($trx['created_at'] ?? null)); ?><?php if (($trx['posting_mode']??'posted')==='historical'): ?><br><span class="badge text-bg-secondary rounded-pill">Historis tanpa saldo</span><?php endif; ?></td>
                                        <td><span class="badge text-bg-<?= $trx['transaction_type'] === 'income' ? 'success' : 'danger'; ?> rounded-pill"><?= e($trx['transaction_type']); ?></span></td>
                                        <td class="fw-semibold"><?= e($trx['category'] ?: '-'); ?></td>
                                        <td><?= e($trx['description'] ?: '-'); ?></td>
                                        <td><?= e($trx['funding_source']); ?><?= $trx['person_name'] ? ' - ' . e($trx['person_name']) : ''; ?></td>
                                        <td class="fw-bold <?= $trx['transaction_type'] === 'income' ? 'text-success' : 'text-danger'; ?>"><?= rp($trx['amount']); ?></td>
                                        <td>
                                            <?php
                                                $assetSource = $trx['source_module'] ?? 'manual';
                                                $activity = null;
                                                if ((int)$trx['is_asset'] === 1) $activity = ['Dibeli','info','bi-cart-check'];
                                                elseif ($assetSource === 'asset_sale') $activity = ['Dijual','success','bi-cash-coin'];
                                                elseif ($assetSource === 'asset_rental') $activity = ['Disewakan','primary','bi-key'];
                                                elseif ($assetSource === 'asset_maintenance') $activity = ['Maintenance','warning','bi-tools'];
                                                $assetInfo = $transactionAssetInfo[(int)$trx['id']] ?? null;
                                            ?>
                                            <?php if ($activity): ?><span class="badge text-bg-<?= $activity[1]; ?> rounded-pill"><i class="bi <?= $activity[2]; ?> me-1"></i><?= $activity[0]; ?></span><?php if ($assetInfo): ?><div class="small mt-1"><a class="text-decoration-none" href="<?= base_url('pages/assets/detail.php?id='.(int)$assetInfo['asset_id']); ?>"><?= e(trim(($assetInfo['asset_code'] ?: '').' - '.($assetInfo['asset_name'] ?: ''), ' -')); ?></a></div><?php endif; ?><?php else: ?>-<?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php
                                                $trxEvidenceItems = project_merge_evidence_items(
                                                    $transactionEvidence[(int) $trx['id']] ?? [],
                                                    $transactionAssetEvidence[(int) $trx['id']] ?? []
                                                );
                                                echo project_evidence_button('Bukti Transaksi - ' . ($trx['category'] ?? ''), $trxEvidenceItems);
                                            ?>
                                            <?php if (is_auto_project_transaction($trx)): ?>
                                                <span class="badge text-bg-secondary rounded-pill" title="<?= e(project_transaction_source_label($trx)); ?>"><?= e(project_transaction_source_label($trx)); ?></span>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTransaction<?= (int) $trx['id']; ?>"><i class="bi bi-pencil"></i></button>
                                                <form method="POST" enctype="multipart/form-data" class="d-inline">
                                                    <input type="hidden" name="action" value="delete_transaction">
                                                    <input type="hidden" name="transaction_id" value="<?= (int) $trx['id']; ?>">
                                                    <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus transaksi ini?')"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'workers'): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Pekerja Project</h5>
                        <div class="text-muted small">Pekerja bisa diambil dari karyawan internal atau ditambahkan sebagai freelance/borongan.</div>
                    </div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addWorkerModal"><i class="bi bi-plus-lg me-1"></i> Tambah Pekerja</button>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4"><div class="mini-total-card"><span>Total Pekerja</span><strong><?= count($workers); ?> Orang/Vendor</strong></div></div>
                    <div class="col-md-4"><div class="mini-total-card"><span>Total Biaya Pekerja</span><strong><?= rp(array_sum(array_map(fn($w) => (float) ($w['total_amount'] ?: $w['rate']), $workers))); ?></strong></div></div>
                    <div class="col-md-4"><div class="mini-total-card"><span>Karyawan Internal</span><strong><?= count(array_filter($workers, fn($w) => $w['worker_type'] === 'employee')); ?> Orang</strong></div></div>
                </div>

                <div class="alert alert-info rounded-4 border-0">
                    <strong>Catatan sistem pekerja:</strong> karyawan internal tetap dicatat sebagai biaya project berdasarkan periode dan rate project, tetapi pembayarannya diproses melalui Payroll. Freelance dan borongan dibayar dari Project dan memiliki slip pembayaran. Lembur, tambahan, dan potongan bisa ditambahkan.
                </div>

                <?php if (empty($workers)): ?>
                    <div class="empty-state">Belum ada pekerja project.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-modern align-middle">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Tipe</th>
                                    <th>Pembayaran</th>
                                    <th>Periode/Hari</th>
                                    <th>Rate</th>
                                    <th>Total</th>
                                    <th>Lembur/Tambahan</th>
                                    <th>Absensi/HOK</th>
                                    <th>Sumber Dana</th>
                                    <th>Catatan</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workers as $worker): ?>
                                    <tr>
                                        <td class="fw-bold"><?= e($worker['worker_name']); ?></td>
                                        <td><span class="badge text-bg-light rounded-pill"><?= e($worker['worker_type']); ?></span></td>
                                        <td>
                                            <div><?= e($worker['payment_type']); ?></div>
                                            <?php if ($worker['worker_type'] === 'employee'): ?>
                                                <span class="badge text-bg-info rounded-pill">Dibayar melalui Payroll</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-<?= ($worker['payment_status'] ?? 'unpaid') === 'paid' ? 'success' : (($worker['payment_status'] ?? 'unpaid') === 'cancelled' ? 'secondary' : 'warning'); ?> rounded-pill">
                                                    <?= e($worker['payment_status'] ?? 'unpaid'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small"><?= safe_date($worker['start_date']); ?> - <?= safe_date($worker['end_date']); ?></div>
                                            <div class="fw-semibold"><?= project_day_number($worker['work_days']); ?> hari<?= $worker['worker_type'] === 'borongan' ? ' periode' : ''; ?></div>
                                        </td>
                                        <td class="fw-bold"><?= rp($worker['rate']); ?></td>
                                        <td class="fw-bold text-danger"><?= rp($worker['total_amount'] ?: $worker['rate']); ?></td>
                                        <td class="small">
                                            <div>Lembur: <?= e((float) ($worker['overtime_hours'] ?? 0)); ?> jam × <?= rp($worker['overtime_rate'] ?? 0); ?></div>
                                            <div>Tambahan: <?= rp($worker['bonus_amount'] ?? 0); ?></div>
                                            <div>Potongan: <?= rp($worker['deduction_amount'] ?? 0); ?></div>
                                        </td>
                                        <td>
                                            <?php $att = $workerAttendanceStats[$worker['id']] ?? null; ?>
                                            <?php if ($worker['worker_type'] === 'employee' && $att): ?>
                                                <div class="fw-semibold"><?= project_day_number($att['attendance_days']); ?> hari hadir</div>
                                                <div class="small text-muted"><?= round(((int) $att['total_work_minutes']) / 60, 1); ?> jam kerja</div>
                                                <div class="small text-muted">Lembur fingerprint: <?= round(((int) ($att['overtime_minutes'] ?? 0)) / 60, 1); ?> jam</div>
                                            <?php elseif ($worker['worker_type'] === 'employee'): ?>
                                                <span class="text-muted small">Belum cocok dengan data employee/absensi</span>
                                            <?php else: ?>
                                                <span class="text-muted small">Tidak memakai absensi internal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($worker['funding_source']); ?><?= $worker['person_name'] ? ' - ' . e($worker['person_name']) : ''; ?></td>
                                        <td><?= e($worker['notes'] ?: '-'); ?></td>
                                        <td class="text-end">
                                            <?php
                                                $workerEvidenceItems = project_merge_evidence_items(
                                                    $workerEvidence[(int) $worker['id']] ?? [],
                                                    !empty($worker['transaction_id']) ? ($transactionEvidence[(int) $worker['transaction_id']] ?? []) : []
                                                );
                                                echo project_evidence_button('Bukti Pembayaran Pekerja - ' . ($worker['worker_name'] ?? ''), $workerEvidenceItems);
                                            ?>
                                            <?php if ($worker['worker_type'] !== 'employee'): ?>
                                                <a class="btn btn-sm btn-outline-success" target="_blank" href="<?= base_url('pages/projects/worker_slip.php?id=' . (int) $worker['id']); ?>" title="Cetak Slip"><i class="bi bi-printer"></i></a>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editWorker<?= (int) $worker['id']; ?>"><i class="bi bi-pencil"></i></button>
                                            <form method="POST" enctype="multipart/form-data" class="d-inline">
                                                <input type="hidden" name="action" value="delete_worker">
                                                <input type="hidden" name="worker_id" value="<?= (int) $worker['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus pekerja ini? Biaya pekerja terkait juga akan dihapus.')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'gallery'): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-1">Dokumen & Gallery Project</h5>
                        <div class="text-muted small">Upload kontrak kerja sama, penawaran yang disetujui, BA, invoice, PDF, Word, Excel, foto progress, dan hasil final.</div>
                    </div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addGalleryDocumentModal"><i class="bi bi-plus-lg me-1"></i> Tambah File</button>
                </div>

                <?php if (empty($gallery)): ?>
                    <div class="empty-state">Belum ada dokumentasi project.</div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($gallery as $img): ?>
                            <div class="col-md-4 col-xl-3">
                                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                                    <?php
                                        $galleryUrl = project_gallery_url($img['image_path']);
                                        $isExternal = project_gallery_is_external($img['image_path']);
                                    ?>
                                    <?php if (!$isExternal && is_image_file($img['image_path'])): ?>
                                        <a href="<?= e($galleryUrl); ?>" target="_blank"><img src="<?= e($galleryUrl); ?>" class="card-img-top" style="height:170px;object-fit:cover;" alt="Project file"></a>
                                    <?php else: ?>
                                        <a href="<?= e($galleryUrl); ?>" target="_blank" class="text-decoration-none"><div class="doc-card-icon"><i class="bi <?= e(project_gallery_icon($img['image_path'])); ?>"></i></div></a>
                                    <?php endif; ?>
                                    <div class="card-body">
                                        <span class="badge text-bg-light rounded-pill mb-2"><?= e(project_gallery_type_label($img['image_type'])); ?></span>
                                        <?php if ($isExternal): ?><span class="badge text-bg-success rounded-pill mb-2">Link Drive/Dokumen</span><?php endif; ?>
                                        <div class="fw-semibold"><?= e($img['caption'] ?: '-'); ?></div>
                                    </div>
                                    <div class="card-footer bg-white border-0 d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-success flex-fill" href="<?= e($galleryUrl); ?>" target="_blank"><i class="bi bi-eye"></i></a>
                                        <?php if (!$isExternal): ?>
                                            <a class="btn btn-sm btn-outline-primary flex-fill" href="<?= e($galleryUrl); ?>" download><i class="bi bi-download"></i></a>
                                        <?php endif; ?>
                                        <form method="POST" action="<?= base_url('pages/projects/delete_gallery.php'); ?>" class="d-flex flex-fill" onsubmit="return confirm('Hapus file/link ini?')"><?= csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$img['id']; ?>"><button class="btn btn-sm btn-outline-danger flex-fill"><i class="bi bi-trash"></i></button></form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between mb-5">
        <a href="<?= base_url('pages/projects/index.php'); ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Kembali ke Project</a>
        <a href="<?= base_url('pages/projects/edit.php?id=' . $id); ?>" class="btn btn-outline-success"><i class="bi bi-pencil-square me-1"></i> Edit Data Utama</a>
    </div>

</main>


<?php
function render_project_evidence_modal($modalId, $title, array $items)
{
    ?>
    <div class="modal fade" id="<?= e($modalId); ?>" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><?= e($title); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($items)): ?>
                        <div class="empty-state">Belum ada bukti yang diupload.</div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($items as $item): ?>
                                <?php
                                    $filePath = (string) ($item['file_path'] ?? '');
                                    $fileUrl = base_url($filePath);
                                ?>
                                <div class="col-md-4">
                                    <div class="border rounded-4 p-2 h-100 bg-light">
                                        <?php if (evidence_file_is_image($filePath)): ?>
                                            <a href="<?= e($fileUrl); ?>" target="_blank">
                                                <img src="<?= e($fileUrl); ?>" alt="Bukti" class="img-fluid rounded-3 w-100" style="height: 180px; object-fit: cover;">
                                            </a>
                                        <?php else: ?>
                                            <div class="d-flex align-items-center justify-content-center rounded-3 bg-white border" style="height: 180px;">
                                                <div class="text-center">
                                                    <i class="bi bi-file-earmark-text fs-1 text-secondary"></i>
                                                    <div class="small text-muted mt-2"><?= e(evidence_file_label($filePath)); ?></div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mt-2 d-flex justify-content-between align-items-center gap-2">
                                            <small class="text-muted text-truncate"><?= e(evidence_file_label($filePath)); ?></small>
                                            <a class="btn btn-sm btn-outline-primary" href="<?= e($fileUrl); ?>" target="_blank">
                                                <i class="bi bi-box-arrow-up-right"></i> Buka
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
?>

<?php foreach ($terms as $term): ?>
    <?php render_project_evidence_modal('evidenceTerm' . (int) $term['id'], 'Bukti Pembayaran Termin - ' . ($term['term_name'] ?? ''), $termEvidence[(int) $term['id']] ?? []); ?>
<?php endforeach; ?>

<?php foreach ($funds as $fund): ?>
    <?php render_project_evidence_modal('evidenceFund' . (int) $fund['id'], 'Bukti Pendanaan Project', $fundEvidence[(int) $fund['id']] ?? []); ?>
<?php endforeach; ?>

<?php foreach ($transactions as $trx): ?>
    <?php
        $trxEvidenceItemsForModal = array_merge(
            $transactionEvidence[(int) $trx['id']] ?? [],
            $transactionAssetEvidence[(int) $trx['id']] ?? []
        );
    ?>
    <?php render_project_evidence_modal('evidenceTransaction' . (int) $trx['id'], 'Bukti Transaksi - ' . ($trx['category'] ?? ''), $trxEvidenceItemsForModal); ?>
<?php endforeach; ?>

<?php foreach ($workers as $worker): ?>
    <?php render_project_evidence_modal('evidenceWorker' . (int) $worker['id'], 'Bukti Pembayaran Pekerja - ' . ($worker['worker_name'] ?? ''), $workerEvidence[(int) $worker['id']] ?? []); ?>
<?php endforeach; ?>

<!-- ADD TERM MODAL -->

<div class="modal fade" id="projectEvidenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="projectEvidenceModalTitle">Bukti</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="projectEvidenceModalBody" style="max-height:70vh; overflow-y:auto;">
                <div class="text-muted">Tidak ada bukti.</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addTermModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="add_term">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Tambah Termin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Nama Termin</label>
                    <input type="text" name="term_name" class="form-control" placeholder="Termin 1 / DP 30%" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Persentase</label>
                    <input type="number" step="0.01" name="percentage" class="form-control" placeholder="30">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Nominal</label>
                    <input type="text" name="amount" class="form-control money-input" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Jatuh Tempo</label>
                    <input type="date" name="due_date" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select project-term-status">
                        <option value="unpaid">Unpaid</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>
                <?php if (finance_user_can_use_non_posting()): ?><div class="col-md-12"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" class="form-select project-posting-mode"><option value="posted">Termin berjalan — pembayaran memengaruhi saldo</option><option value="historical">Historis sudah dibayar — tidak memengaruhi saldo</option><option value="opening_outstanding">Piutang awal masih terbuka — pembayaran berikutnya memengaruhi saldo</option></select></div><div class="col-md-6 d-none project-historical-paid-date"><label class="form-label fw-semibold">Tanggal Pembayaran Historis</label><input type="date" name="paid_date" class="form-control" disabled></div><div class="col-md-6 d-none project-historical-payment"><label class="form-label fw-semibold">Nominal Bersih Diterima (Historis)</label><input type="text" name="received_amount" class="form-control money-input historical-net-input" disabled><small class="text-muted">Isi nominal setelah potongan pajak.</small></div><div class="col-md-6 d-none project-historical-payment"><div class="alert alert-warning rounded-4 mb-0 h-100"><span class="small">Potongan pajak historis</span><div class="fw-bold fs-5 historical-tax-preview">Rp 0</div><small class="historical-tax-percent-preview">0%</small></div></div><div class="col-md-12 d-none project-historical-reason"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"></textarea></div><?php endif; ?>
                <div class="col-md-6 project-term-balance-field">
                    <label class="form-label fw-semibold">Jika Paid, Dana Masuk Ke Akun</label>
                    <select name="term_payment_account_id" class="form-select">
                        <option value="">Pilih akun kas/bank tujuan</option>
                        <?php foreach ($financeAccounts as $acc): ?>
                            <option value="<?= (int)$acc['id']; ?>"><?= e($acc['account_name']); ?> - <?= rp($acc['current_balance'] ?? 0); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 project-term-balance-field">
                    <label class="form-label fw-semibold">Jika Paid, Metode Pembayaran</label>
                    <select name="term_payment_method" class="form-select">
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="qris">QRIS</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>
                
                <div class="col-12">
                    <label class="form-label fw-semibold">Bukti Pembayaran Pekerja <span class="text-muted">(bisa lebih dari satu)</span></label>
                    <input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple>
                    <small class="text-muted">Upload bukti transfer/upah pekerja, tanda terima, atau bukti pembayaran lainnya.</small>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Catatan</label>
                    <textarea name="notes" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- ADD FUND MODAL -->
<div class="modal fade" id="addTransactionModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="add_transaction">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Tambah Transaksi Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tipe Transaksi</label>
                    <select name="transaction_type" class="form-select">
                        <option value="expense">Pengeluaran</option>
                        <option value="income">Pemasukan Project</option>
                    </select>
                </div>
                <?php if (finance_user_can_use_non_posting()): ?><div class="col-md-6"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" class="form-select project-posting-mode"><option value="posted">Transaksi berjalan — memengaruhi saldo</option><option value="historical">Catatan historis — tidak memengaruhi saldo/dana/hutang</option></select></div><div class="col-12 d-none project-historical-reason"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"></textarea></div><?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Kategori</label>
                    <input type="text" name="category" class="form-control" placeholder="Material / Transport / Vendor / dll" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tanggal Transaksi</label>
                    <input type="date" name="transaction_date" class="form-control" value="<?= e(date('Y-m-d')); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Sumber Dana</label>
                    <select name="funding_source" class="form-select funding-source">
                        <option value="company">Akun Perusahaan</option>
                        <option value="operational_fund">Dana Operasional Personal</option>
                        <option value="person">Talangan Personal</option>
                    </select>
                </div>
                <div class="col-md-6 company-account-field">
                    <label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label>
                    <select name="account_id" class="form-select">
                        <option value="">Pilih akun perusahaan</option>
                        <?php foreach ($financeAccounts as $acc): ?>
                            <option value="<?= (int)$acc['id']; ?>"><?= e($acc['account_name']); ?> - <?= rp($acc['current_balance'] ?? 0); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 company-account-field">
                    <label class="form-label fw-semibold">Metode Pembayaran</label>
                    <select name="payment_method" class="form-select">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="qris">QRIS</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>
                <div class="col-md-12 operational-fund-field d-none">
                    <label class="form-label fw-semibold">Dana Operasional Personal</label>
                    <select name="operational_fund_id" class="form-select">
                        <option value="">Pilih Dana Operasional</option>
                        <?php foreach ($operationalFunds as $fund): ?>
                            <option value="<?= (int)$fund['id']; ?>"><?= e($fund['person_name']); ?> - <?= e($fund['fund_code']); ?> - Sisa <?= rp($fund['current_balance'] ?? 0); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Jika saldo kurang, selisih otomatis menjadi hutang/talangan personal.</small>
                </div>
                <div class="col-md-6 person-field d-none">
                    <label class="form-label fw-semibold">Jenis Personal</label>
                    <select name="person_source" class="form-select person-source">
                        <option value="employee">Pilih dari Karyawan</option>
                        <option value="manual">Input Nama Manual</option>
                    </select>
                </div>
                <div class="col-md-6 person-field person-employee-field d-none">
                    <label class="form-label fw-semibold">Pilih Karyawan</label>
                    <select name="personal_employee_id" class="form-select">
                        <option value="">Pilih karyawan pemberi talangan</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int) $emp['id']; ?>"><?= e($emp['full_name']); ?> - <?= e($emp['position'] ?: 'Staff'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 person-field person-manual-field d-none">
                    <label class="form-label fw-semibold">Nama Personal Manual</label>
                    <input type="text" name="person_name" class="form-control" placeholder="Nama lengkap pemberi talangan">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Nominal</label>
                    <input type="text" name="amount" class="form-control money-input" required>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input asset-toggle" type="checkbox" name="is_asset" value="1" id="isAssetAdd">
                        <label class="form-check-label fw-semibold" for="isAssetAdd">Masuk sebagai Aset/Inventaris</label>
                    </div>
                </div>
                <div class="col-12 asset-fields d-none">
                    <div class="p-3 rounded-4 border bg-light">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-box-seam text-success"></i>
                            <strong>Detail Aset / Inventaris</strong>
                            <small class="text-muted">Akan otomatis masuk ke halaman Aset / Inventaris.</small>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Nama Aset</label>
                                <input type="text" name="asset_name" class="form-control" placeholder="Contoh: Roda Tiga">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Kategori Aset</label>
                                <input type="text" name="asset_category" class="form-control" placeholder="Contoh: Kendaraan / Alat / Mesin">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Lokasi</label>
                                <input type="text" name="asset_location" class="form-control" placeholder="Contoh: Workshop Nukari">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Kondisi</label>
                                <select name="asset_condition_status" class="form-select">
                                    <option value="good">Baik</option>
                                    <option value="maintenance">Perawatan</option>
                                    <option value="damaged">Rusak</option>
                                    <option value="lost">Hilang</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Penanggung Jawab</label>
                                <select name="asset_responsible_employee_id" class="form-select">
                                    <option value="">-</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= (int) $emp['id']; ?>"><?= e($emp['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label fw-semibold">Foto Aset / Barang <span class="text-muted">(bisa lebih dari satu)</span></label>
                                <input type="file" name="asset_photos[]" class="form-control" accept="image/*" multiple>
                                <small class="text-muted">Upload foto barang/aset yang dibeli. Foto pertama akan menjadi foto utama aset.</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Catatan Aset</label>
                                <textarea name="asset_notes" class="form-control" rows="2" placeholder="Catatan khusus aset/inventaris."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Bukti Pendanaan / Transfer / Nota <span class="text-muted">(bisa lebih dari satu)</span></label>
                    <input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple>
                    <small class="text-muted">Upload nota, invoice, bukti transfer, atau bukti barang. Format JPG, JPEG, PNG, WEBP. Maksimal 5MB per foto.</small>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Keterangan</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- ADD WORKER MODAL -->
<div class="modal fade" id="addWorkerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable project-worker-modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content project-worker-modal-content">
            <input type="hidden" name="action" value="add_worker">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Tambah Pekerja Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Sumber Pekerja</label>
                    <select name="worker_source" class="form-select worker-source">
                        <option value="employee">Karyawan Internal</option>
                        <option value="freelance">Freelance / Manual</option>
                    </select>
                </div>
                <div class="col-md-6 employee-select-wrap">
                    <label class="form-label fw-semibold">Pilih Karyawan</label>
                    <select name="employee_id" class="form-select employee-select">
                        <option value="">Pilih karyawan</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int) $emp['id']; ?>" data-rate="<?= (float) $emp['salary_amount']; ?>" data-type="<?= e($emp['salary_type']); ?>">
                                <?= e($emp['full_name']); ?> - <?= e($emp['position'] ?: 'Staff'); ?> - <?= rp($emp['salary_amount']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 manual-worker-wrap d-none">
                    <label class="form-label fw-semibold">Nama Pekerja</label>
                    <input type="text" name="worker_name" class="form-control" placeholder="Nama pekerja freelance">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tipe Pekerja</label>
                    <select name="worker_type" class="form-select">
                        <option value="employee">Employee</option>
                        <option value="freelance">Freelance</option>
                        <option value="borongan">Borongan</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tipe Pembayaran</label>
                    <select name="payment_type" class="form-select payment-type">
                        <option value="daily">Harian</option>
                        <option value="per_attendance">Per Kehadiran</option>
                        <option value="monthly">Bulanan</option>
                        <option value="borongan">Borongan</option>
                    </select>
                </div>
                <div class="col-md-6 worker-date-field">
                    <label class="form-label fw-semibold">Tanggal Mulai</label>
                    <input type="date" name="start_date" class="form-control">
                </div>
                <div class="col-md-6 worker-date-field">
                    <label class="form-label fw-semibold">Tanggal Selesai</label>
                    <input type="date" name="end_date" class="form-control">
                </div>
                <div class="col-md-6 worker-days-field">
                    <label class="form-label fw-semibold">Jumlah Hari Kerja</label>
                    <input type="number" name="work_days" class="form-control" min="0.5" step="0.5" value="1">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Rate/Nilai</label>
                    <input type="text" name="rate" class="form-control money-input worker-rate" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Lembur (Jam)</label>
                    <input type="number" step="0.01" min="0" name="overtime_hours" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Rate Lembur</label>
                    <input type="text" name="overtime_rate" class="form-control money-input" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tambahan/Bonus</label>
                    <input type="text" name="bonus_amount" class="form-control money-input" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Potongan</label>
                    <input type="text" name="deduction_amount" class="form-control money-input" value="0">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Sumber Dana</label>
                    <select name="funding_source" class="form-select funding-source">
                        <option value="company">Akun Perusahaan</option>
                        <option value="operational_fund">Dana Operasional Personal</option>
                        <option value="person">Talangan Personal</option>
                    </select>
                </div>
                <div class="col-md-6 company-account-field">
                    <label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label>
                    <select name="account_id" class="form-select">
                        <option value="">Pilih akun perusahaan</option>
                        <?php foreach ($financeAccounts as $acc): ?>
                            <option value="<?= (int)$acc['id']; ?>"><?= e($acc['account_name']); ?> - <?= rp($acc['current_balance'] ?? 0); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 company-account-field">
                    <label class="form-label fw-semibold">Metode Pembayaran</label>
                    <select name="payment_method" class="form-select">
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="qris">QRIS</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>
                <div class="col-md-12 operational-fund-field d-none">
                    <label class="form-label fw-semibold">Dana Operasional Personal</label>
                    <select name="operational_fund_id" class="form-select">
                        <option value="">Pilih Dana Operasional</option>
                        <?php foreach ($operationalFunds as $fund): ?>
                            <option value="<?= (int)$fund['id']; ?>"><?= e($fund['person_name']); ?> - <?= e($fund['fund_code']); ?> - Sisa <?= rp($fund['current_balance'] ?? 0); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Jika saldo kurang, selisih otomatis menjadi hutang/talangan personal.</small>
                </div>
                <div class="col-md-6 person-field d-none">
                    <label class="form-label fw-semibold">Jenis Personal</label>
                    <select name="person_source" class="form-select person-source">
                        <option value="employee">Pilih dari Karyawan</option>
                        <option value="manual">Input Nama Manual</option>
                    </select>
                </div>
                <div class="col-md-6 person-field person-employee-field d-none">
                    <label class="form-label fw-semibold">Pilih Karyawan</label>
                    <select name="personal_employee_id" class="form-select">
                        <option value="">Pilih karyawan pemberi talangan</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= (int) $emp['id']; ?>"><?= e($emp['full_name']); ?> - <?= e($emp['position'] ?: 'Staff'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 person-field person-manual-field d-none">
                    <label class="form-label fw-semibold">Nama Personal Manual</label>
                    <input type="text" name="person_name" class="form-control" placeholder="Nama lengkap pemberi talangan">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Status Pembayaran</label>
                    <select name="payment_status" class="form-select">
                        <option value="unpaid">Belum Dibayar</option>
                        <option value="paid">Sudah Dibayar</option>
                        <option value="cancelled">Dibatalkan</option>
                    </select>
                    <small class="text-muted">Karyawan internal tetap dibayar melalui Payroll.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tanggal Bayar</label>
                    <input type="date" name="paid_date" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Catatan</label>
                    <textarea name="notes" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($terms as $term): ?>
<?php if ($term['status'] !== 'paid'): ?>
<div class="modal fade" id="payTerm<?= (int) $term['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="pay_term">
            <input type="hidden" name="term_id" value="<?= (int) $term['id']; ?>">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Konfirmasi Pembayaran Termin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Termin</label>
                    <input type="text" class="form-control" value="<?= e($term['term_name']); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Nominal</label>
                    <input type="text" class="form-control" value="<?= rp($term['amount']); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Dana Masuk Ke Akun</label>
                    <select name="account_id" class="form-select" required>
                        <option value="">Pilih akun kas/bank tujuan</option>
                        <?php foreach ($financeAccounts as $acc): ?>
                            <option value="<?= (int)$acc['id']; ?>"><?= e($acc['account_name']); ?> - Saldo <?= rp($acc['current_balance'] ?? 0); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Saldo akun ini akan bertambah setelah termin dibayar.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Metode Pembayaran</label>
                    <select name="payment_method" class="form-select" required>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="qris">QRIS</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Nominal Bersih yang Diterima</label>
                    <input type="text" name="received_amount" class="form-control currency-input term-net-input" value="<?= number_format((float)$term['amount'], 0, ',', '.'); ?>" required>
                    <small class="text-muted">Masukkan nominal yang benar-benar masuk setelah dipotong client.</small>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-warning rounded-4 mb-0 h-100">
                        <span class="small">Potongan pajak otomatis</span>
                        <div class="fw-bold fs-5 term-tax-preview" data-gross="<?= (float) $term['amount']; ?>">Rp 0</div>
                        <small class="term-tax-percent-preview">0%</small>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Bukti Pembayaran Termin <span class="text-muted">(optional, bisa lebih dari satu)</span></label>
                    <input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple>
                    <small class="text-muted">Upload bukti transfer/pembayaran termin. Format JPG, JPEG, PNG, WEBP. Maksimal 5MB per foto.</small>
                </div>
                <div class="col-12">
                    <div class="alert alert-info rounded-4 mb-0 small">
                        Setelah disimpan, termin menjadi lunas, piutang berkurang/lunas, transaksi income masuk ke Finance, dan saldo akun kas/bank tujuan bertambah otomatis.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i> Konfirmasi Pembayaran</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php foreach ($terms as $term): ?>
<div class="modal fade" id="editTerm<?= (int) $term['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="update_term">
            <input type="hidden" name="term_id" value="<?= (int) $term['id']; ?>">
            <div class="modal-header"><h5 class="modal-title fw-bold">Edit Termin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-6"><label class="form-label fw-semibold">Nama Termin</label><input type="text" name="term_name" class="form-control" value="<?= e($term['term_name']); ?>" required></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Persentase</label><input type="number" step="0.01" name="percentage" class="form-control" value="<?= e($term['percentage']); ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Nominal</label><input type="text" name="amount" class="form-control money-input" value="<?= e(number_format($term['amount'], 0, ',', '.')); ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Jatuh Tempo</label><input type="date" name="due_date" class="form-control" value="<?= e($term['due_date']); ?>" required></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Tanggal Bayar</label><input type="date" name="paid_date" class="form-control" value="<?= e($term['paid_date']); ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select project-term-status"><?php foreach (['unpaid','partial','paid','overdue'] as $s): ?><option value="<?= $s; ?>" <?= $term['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php endforeach; ?></select></div>
                <?php if (finance_user_can_use_non_posting()): ?><div class="col-md-12"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" class="form-select project-posting-mode"><option value="posted" <?= ($term['posting_mode']??'posted')==='posted'?'selected':''; ?>>Termin berjalan</option><option value="historical" <?= ($term['posting_mode']??'')==='historical'?'selected':''; ?>>Historis sudah dibayar — tanpa saldo</option><option value="opening_outstanding" <?= ($term['posting_mode']??'')==='opening_outstanding'?'selected':''; ?>>Piutang awal masih terbuka</option></select></div><div class="col-md-6 <?= ($term['posting_mode']??'posted')==='historical'?'':'d-none'; ?> project-historical-payment"><label class="form-label fw-semibold">Nominal Bersih Diterima (Historis)</label><input type="text" name="received_amount" class="form-control money-input historical-net-input" value="<?= e(number_format((float)($term['received_amount'] ?? 0) > 0 ? (float)$term['received_amount'] : (float)$term['amount'], 0, ',', '.')); ?>" <?= ($term['posting_mode']??'posted')==='historical'?'':'disabled'; ?>><small class="text-muted">Isi nominal setelah potongan pajak.</small></div><div class="col-md-6 <?= ($term['posting_mode']??'posted')==='historical'?'':'d-none'; ?> project-historical-payment"><div class="alert alert-warning rounded-4 mb-0 h-100"><span class="small">Potongan pajak historis</span><div class="fw-bold fs-5 historical-tax-preview">Rp <?= e(number_format((float)($term['tax_amount'] ?? 0), 0, ',', '.')); ?></div><small class="historical-tax-percent-preview"><?= e(number_format((float)($term['tax_percentage'] ?? 0), 2, ',', '.')); ?>%</small></div></div><div class="col-md-12 <?= ($term['posting_mode']??'posted')==='posted'?'d-none':''; ?> project-historical-reason"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"><?= e($term['historical_reason'] ?? ''); ?></textarea></div><?php else: ?><input type="hidden" name="posting_mode" value="<?= e($term['posting_mode'] ?? 'posted'); ?>"><input type="hidden" name="historical_reason" value="<?= e($term['historical_reason'] ?? ''); ?>"><?php endif; ?>
                <div class="col-md-6 project-term-balance-field"><label class="form-label fw-semibold">Jika Paid, Dana Masuk Ke Akun</label><select name="term_payment_account_id" class="form-select"><option value="">Pilih akun kas/bank tujuan</option><?php foreach ($financeAccounts as $acc): ?><option value="<?= (int)$acc['id']; ?>"><?= e($acc['account_name']); ?> - <?= rp($acc['current_balance'] ?? 0); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 project-term-balance-field"><label class="form-label fw-semibold">Jika Paid, Metode Pembayaran</label><select name="term_payment_method" class="form-select"><option value="bank_transfer">Bank Transfer</option><option value="cash">Cash</option><option value="qris">QRIS</option><option value="other">Lainnya</option></select></div>
                
                <div class="col-12 paid-proof-field">
                    <label class="form-label fw-semibold">Bukti Pembayaran Termin <span class="text-muted">(bisa lebih dari satu)</span></label>
                    <input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple>
                    <small class="text-muted">Upload bukti transfer/pembayaran termin. Format JPG, JPEG, PNG, WEBP. Maksimal 5MB per foto.</small>
                </div>
                <div class="col-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" class="form-control" rows="3"><?= e($term['notes']); ?></textarea></div>
            </div>
            <div class="modal-footer"><button class="btn btn-success">Update</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php foreach ($transactions as $trx): ?>
<?php if (!is_auto_project_transaction($trx)): ?>
<div class="modal fade" id="editTransaction<?= (int) $trx['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="update_transaction">
            <input type="hidden" name="transaction_id" value="<?= (int) $trx['id']; ?>">
            <div class="modal-header"><h5 class="modal-title fw-bold">Edit Transaksi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-6"><label class="form-label fw-semibold">Tipe</label><select name="transaction_type" class="form-select"><option value="expense" <?= $trx['transaction_type'] === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option><option value="income" <?= $trx['transaction_type'] === 'income' ? 'selected' : ''; ?>>Pendapatan</option></select></div>
                <?php if (finance_user_can_use_non_posting()): ?><div class="col-md-6"><label class="form-label fw-semibold">Dampak Saldo</label><select name="posting_mode" class="form-select project-posting-mode"><option value="posted" <?= ($trx['posting_mode']??'posted')==='posted'?'selected':''; ?>>Transaksi berjalan</option><option value="historical" <?= ($trx['posting_mode']??'')==='historical'?'selected':''; ?>>Catatan historis — tanpa saldo/dana/hutang</option></select></div><div class="col-12 <?= ($trx['posting_mode']??'posted')==='historical'?'':'d-none'; ?> project-historical-reason"><label class="form-label fw-semibold">Alasan / Referensi Historis</label><textarea name="historical_reason" class="form-control" rows="2"><?= e($trx['historical_reason'] ?? ''); ?></textarea></div><?php else: ?><input type="hidden" name="posting_mode" value="<?= e($trx['posting_mode'] ?? 'posted'); ?>"><input type="hidden" name="historical_reason" value="<?= e($trx['historical_reason'] ?? ''); ?>"><?php endif; ?>
                <div class="col-md-6"><label class="form-label fw-semibold">Kategori</label><input type="text" name="category" class="form-control" value="<?= e($trx['category']); ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Tanggal Transaksi</label><input type="date" name="transaction_date" class="form-control" value="<?= e($trx['transaction_date'] ?? date('Y-m-d')); ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Sumber Dana</label><select name="funding_source" class="form-select funding-source"><option value="company" <?= $trx['funding_source'] === 'company' ? 'selected' : ''; ?>>Akun Perusahaan</option><option value="operational_fund" <?= ($trx['funding_source'] ?? '') === 'operational_fund' ? 'selected' : ''; ?>>Dana Operasional Personal</option><option value="person" <?= $trx['funding_source'] === 'person' ? 'selected' : ''; ?>>Talangan Personal</option></select></div>
                <div class="col-md-6 company-account-field <?= in_array(($trx['funding_source'] ?? 'company'), ['person','operational_fund'], true) ? 'd-none' : ''; ?>"><label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label><select name="account_id" class="form-select"><option value="">Pilih akun perusahaan</option><?php foreach ($financeAccounts as $acc): ?><option value="<?= (int)$acc['id']; ?>" <?= (int)($trx['account_id'] ?? 0) === (int)$acc['id'] ? 'selected' : ''; ?>><?= e($acc['account_name']); ?> - <?= rp($acc['current_balance'] ?? 0); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 company-account-field <?= in_array(($trx['funding_source'] ?? 'company'), ['person','operational_fund'], true) ? 'd-none' : ''; ?>"><label class="form-label fw-semibold">Metode Pembayaran</label><select name="payment_method" class="form-select"><option value="cash" <?= ($trx['payment_method'] ?? 'cash') === 'cash' ? 'selected' : ''; ?>>Cash</option><option value="bank_transfer" <?= ($trx['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option><option value="qris" <?= ($trx['payment_method'] ?? '') === 'qris' ? 'selected' : ''; ?>>QRIS</option><option value="other" <?= ($trx['payment_method'] ?? '') === 'other' ? 'selected' : ''; ?>>Lainnya</option></select></div>
                <div class="col-md-12 operational-fund-field <?= (($trx['funding_source'] ?? '') === 'operational_fund') ? '' : 'd-none'; ?>"><label class="form-label fw-semibold">Dana Operasional Personal</label><select name="operational_fund_id" class="form-select"><option value="">Pilih Dana Operasional</option><?php foreach ($operationalFunds as $fund): ?><option value="<?= (int)$fund['id']; ?>" <?= (int)($trx['operational_fund_id'] ?? 0) === (int)$fund['id'] ? 'selected' : ''; ?>><?= e($fund['person_name']); ?> - <?= e($fund['fund_code']); ?> - Sisa <?= rp($fund['current_balance'] ?? 0); ?></option><?php endforeach; ?></select><small class="text-muted">Jika saldo kurang, selisih otomatis menjadi hutang/talangan personal.</small></div>
                <?php $trxPersonalEmployeeId = (int)($trx['personal_employee_id'] ?? 0); ?>
                <div class="col-md-6 person-field <?= $trx['funding_source'] === 'person' ? '' : 'd-none'; ?>"><label class="form-label fw-semibold">Jenis Personal</label><select name="person_source" class="form-select person-source"><option value="employee" <?= $trxPersonalEmployeeId > 0 ? 'selected' : ''; ?>>Pilih dari Karyawan</option><option value="manual" <?= $trxPersonalEmployeeId <= 0 ? 'selected' : ''; ?>>Input Nama Manual</option></select></div>
                <div class="col-md-6 person-field person-employee-field <?= ($trx['funding_source'] === 'person' && $trxPersonalEmployeeId > 0) ? '' : 'd-none'; ?>"><label class="form-label fw-semibold">Pilih Karyawan</label><select name="personal_employee_id" class="form-select"><option value="">Pilih karyawan pemberi talangan</option><?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id']; ?>" <?= $trxPersonalEmployeeId === (int)$emp['id'] ? 'selected' : ''; ?>><?= e($emp['full_name']); ?> - <?= e($emp['position'] ?: 'Staff'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 person-field person-manual-field <?= ($trx['funding_source'] === 'person' && $trxPersonalEmployeeId <= 0) ? '' : 'd-none'; ?>"><label class="form-label fw-semibold">Nama Personal Manual</label><input type="text" name="person_name" class="form-control" value="<?= e($trx['person_name']); ?>" placeholder="Nama lengkap pemberi talangan"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Nominal</label><input type="text" name="amount" class="form-control money-input" value="<?= e(number_format($trx['amount'], 0, ',', '.')); ?>" required></div>
                <div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input asset-toggle" type="checkbox" name="is_asset" value="1" id="isAsset<?= (int) $trx['id']; ?>" <?= (int) $trx['is_asset'] === 1 ? 'checked' : ''; ?>><label class="form-check-label fw-semibold" for="isAsset<?= (int) $trx['id']; ?>">Masuk sebagai Aset/Inventaris</label></div></div>
                <div class="col-12 asset-fields <?= (int) $trx['is_asset'] === 1 ? '' : 'd-none'; ?>">
                    <div class="p-3 rounded-4 border bg-light">
                        <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-box-seam text-success"></i><strong>Detail Aset / Inventaris</strong></div>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label fw-semibold">Nama Aset</label><input type="text" name="asset_name" class="form-control" value="<?= e($trx['category']); ?>"></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">Kategori Aset</label><input type="text" name="asset_category" class="form-control" value="<?= e($trx['category']); ?>"></div>
                            <div class="col-md-6"><label class="form-label fw-semibold">Lokasi</label><input type="text" name="asset_location" class="form-control" placeholder="Lokasi aset"></div>
                            <div class="col-md-3"><label class="form-label fw-semibold">Kondisi</label><select name="asset_condition_status" class="form-select"><option value="good">Baik</option><option value="maintenance">Perawatan</option><option value="damaged">Rusak</option><option value="lost">Hilang</option></select></div>
                            <div class="col-md-3"><label class="form-label fw-semibold">Penanggung Jawab</label><select name="asset_responsible_employee_id" class="form-select"><option value="">-</option><?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id']; ?>"><?= e($emp['full_name']); ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label fw-semibold">Foto Aset / Barang Baru <span class="text-muted">(bisa lebih dari satu)</span></label><input type="file" name="asset_photos[]" class="form-control" accept="image/*" multiple><small class="text-muted">Upload tambahan foto aset/barang jika diperlukan.</small></div>
                            <div class="col-12"><label class="form-label fw-semibold">Catatan Aset</label><textarea name="asset_notes" class="form-control" rows="2"></textarea></div>
                        </div>
                    </div>
                </div>
                <div class="col-12"><label class="form-label fw-semibold">Tambah Bukti Pendanaan / Transfer / Nota <span class="text-muted">(bisa lebih dari satu)</span></label><input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple><small class="text-muted">Bukti lama tetap tersimpan. Upload di sini untuk menambahkan bukti baru.</small></div>
                <div class="col-12"><label class="form-label fw-semibold">Keterangan</label><textarea name="description" class="form-control" rows="3"><?= e($trx['description']); ?></textarea></div>
            </div>
            <div class="modal-footer"><button class="btn btn-success">Update</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php foreach ($workers as $worker): ?>
<div class="modal fade" id="editWorker<?= (int) $worker['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable project-worker-modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content project-worker-modal-content">
            <input type="hidden" name="action" value="update_worker">
            <input type="hidden" name="worker_id" value="<?= (int) $worker['id']; ?>">
            <input type="hidden" name="worker_source" value="<?= $worker['worker_type'] === 'employee' ? 'employee' : 'freelance'; ?>">
            <input type="hidden" name="employee_id" value="<?= (int) ($worker['employee_id'] ?? 0); ?>">
            <div class="modal-header"><h5 class="modal-title fw-bold">Edit Pekerja</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-6"><label class="form-label fw-semibold">Nama Pekerja</label><input type="text" name="worker_name" class="form-control" value="<?= e($worker['worker_name']); ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Tipe Pekerja</label><select name="worker_type" class="form-select"><?php foreach (['employee','freelance','borongan'] as $s): ?><option value="<?= $s; ?>" <?= $worker['worker_type'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Tipe Pembayaran</label><select name="payment_type" class="form-select payment-type"><?php foreach (['daily','per_attendance','monthly','borongan'] as $s): ?><option value="<?= $s; ?>" <?= $worker['payment_type'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 worker-date-field"><label class="form-label fw-semibold">Tanggal Mulai</label><input type="date" name="start_date" class="form-control" value="<?= e($worker['start_date']); ?>"></div>
                <div class="col-md-6 worker-date-field"><label class="form-label fw-semibold">Tanggal Selesai</label><input type="date" name="end_date" class="form-control" value="<?= e($worker['end_date']); ?>"></div>
                <div class="col-md-6 worker-days-field"><label class="form-label fw-semibold">Jumlah Hari Kerja</label><input type="number" name="work_days" class="form-control" min="0.5" step="0.5" value="<?= e((float) ($worker['work_days'] ?: 1)); ?>"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Rate/Nilai</label><input type="text" name="rate" class="form-control money-input" value="<?= e(number_format($worker['rate'], 0, ',', '.')); ?>" required></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Lembur (Jam)</label><input type="number" step="0.01" min="0" name="overtime_hours" class="form-control" value="<?= e($worker['overtime_hours'] ?? 0); ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Rate Lembur</label><input type="text" name="overtime_rate" class="form-control money-input" value="<?= e(number_format($worker['overtime_rate'] ?? 0, 0, ',', '.')); ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Tambahan/Bonus</label><input type="text" name="bonus_amount" class="form-control money-input" value="<?= e(number_format($worker['bonus_amount'] ?? 0, 0, ',', '.')); ?>"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Potongan</label><input type="text" name="deduction_amount" class="form-control money-input" value="<?= e(number_format($worker['deduction_amount'] ?? 0, 0, ',', '.')); ?>"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Sumber Dana</label><select name="funding_source" class="form-select funding-source"><option value="company" <?= $worker['funding_source'] === 'company' ? 'selected' : ''; ?>>Akun Perusahaan</option><option value="operational_fund" <?= ($worker['funding_source'] ?? '') === 'operational_fund' ? 'selected' : ''; ?>>Dana Operasional Personal</option><option value="person" <?= $worker['funding_source'] === 'person' ? 'selected' : ''; ?>>Talangan Personal</option></select></div>
                <div class="col-md-6 company-account-field <?= in_array(($worker['funding_source'] ?? 'company'), ['person','operational_fund'], true) ? 'd-none' : ''; ?>"><label class="form-label fw-semibold">Akun Kas/Bank Perusahaan</label><select name="account_id" class="form-select"><option value="">Pilih akun perusahaan</option><?php foreach ($financeAccounts as $acc): ?><option value="<?= (int)$acc['id']; ?>"><?= e($acc['account_name']); ?> - <?= rp($acc['current_balance'] ?? 0); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 company-account-field <?= in_array(($worker['funding_source'] ?? 'company'), ['person','operational_fund'], true) ? 'd-none' : ''; ?>"><label class="form-label fw-semibold">Metode Pembayaran</label><select name="payment_method" class="form-select"><option value="cash" <?= ($worker['payment_method'] ?? 'cash') === 'cash' ? 'selected' : ''; ?>>Cash</option><option value="bank_transfer" <?= ($worker['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option><option value="qris" <?= ($worker['payment_method'] ?? '') === 'qris' ? 'selected' : ''; ?>>QRIS</option><option value="other" <?= ($worker['payment_method'] ?? '') === 'other' ? 'selected' : ''; ?>>Lainnya</option></select></div>
                <div class="col-md-12 operational-fund-field <?= (($worker['funding_source'] ?? '') === 'operational_fund') ? '' : 'd-none'; ?>"><label class="form-label fw-semibold">Dana Operasional Personal</label><select name="operational_fund_id" class="form-select"><option value="">Pilih Dana Operasional</option><?php foreach ($operationalFunds as $fund): ?><option value="<?= (int)$fund['id']; ?>" <?= (int)($worker['operational_fund_id'] ?? 0) === (int)$fund['id'] ? 'selected' : ''; ?>><?= e($fund['person_name']); ?> - <?= e($fund['fund_code']); ?> - Sisa <?= rp($fund['current_balance'] ?? 0); ?></option><?php endforeach; ?></select><small class="text-muted">Jika saldo kurang, selisih otomatis menjadi hutang/talangan personal.</small></div>
                <div class="col-md-6 person-field <?= $worker['funding_source'] === 'person' ? '' : 'd-none'; ?>"><label class="form-label fw-semibold">Jenis Personal</label><select name="person_source" class="form-select person-source"><option value="manual" selected>Input Nama Manual</option><option value="employee">Pilih dari Karyawan</option></select></div>
                <div class="col-md-6 person-field person-employee-field d-none"><label class="form-label fw-semibold">Pilih Karyawan</label><select name="personal_employee_id" class="form-select"><option value="">Pilih karyawan pemberi talangan</option><?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id']; ?>"><?= e($emp['full_name']); ?> - <?= e($emp['position'] ?: 'Staff'); ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 person-field person-manual-field <?= $worker['funding_source'] === 'person' ? '' : 'd-none'; ?>"><label class="form-label fw-semibold">Nama Personal Manual</label><input type="text" name="person_name" class="form-control" value="<?= e($worker['person_name']); ?>" placeholder="Nama lengkap pemberi talangan"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Status Pembayaran</label><select name="payment_status" class="form-select"><option value="unpaid" <?= ($worker['payment_status'] ?? 'unpaid') === 'unpaid' ? 'selected' : ''; ?>>Belum Dibayar</option><option value="paid" <?= ($worker['payment_status'] ?? 'unpaid') === 'paid' ? 'selected' : ''; ?>>Sudah Dibayar</option><option value="cancelled" <?= ($worker['payment_status'] ?? 'unpaid') === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option></select><small class="text-muted">Karyawan internal tetap dibayar melalui Payroll.</small></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Tanggal Bayar</label><input type="date" name="paid_date" class="form-control" value="<?= e($worker['paid_date'] ?? ''); ?>"></div>
                
                <div class="col-12">
                    <label class="form-label fw-semibold">Bukti Pembayaran Pekerja <span class="text-muted">(bisa lebih dari satu)</span></label>
                    <input type="file" name="receipt_images[]" class="form-control" accept="image/*" multiple>
                    <small class="text-muted">Upload bukti transfer/upah pekerja, tanda terima, atau bukti pembayaran lainnya.</small>
                </div>
                <div class="col-12"><label class="form-label fw-semibold">Catatan</label><textarea name="notes" class="form-control" rows="3"><?= e($worker['notes']); ?></textarea></div>
            </div>
            <div class="modal-footer"><button class="btn btn-success">Update</button></div>
        </form>
    </div>
</div>
<?php endforeach; ?>


<!-- ADD GALLERY / DOCUMENT MODAL -->
<div class="modal fade" id="addGalleryDocumentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content rounded-4 border-0 shadow">
            <input type="hidden" name="action" value="add_gallery_document">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Tambah Dokumen / Gallery Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Jenis File</label>
                    <select name="doc_type" class="form-select">
                        <option value="contract">Kontrak Kerja Sama</option>
                        <option value="offer">Penawaran Disetujui</option>
                        <option value="ba">Berita Acara / BA</option>
                        <option value="invoice">Invoice</option>
                        <option value="design">Design / Konsep</option>
                        <option value="progress">Progress</option>
                        <option value="final">Hasil Final</option>
                        <option value="before">Before</option>
                        <option value="after">After</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Upload File</label>
                    <input type="file" name="project_files[]" class="form-control" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" multiple>
                    <div class="form-text">Bisa gambar, PDF, Word, Excel, atau PowerPoint. Maksimal 5 file, masing-masing 10MB.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Link Google Drive / Google Docs / Dokumen Online</label>
                    <input type="url" name="external_url" class="form-control" placeholder="https://drive.google.com/...">
                    <div class="form-text">Opsional. Boleh isi link tanpa upload file, atau upload file tanpa link.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Catatan / Caption</label>
                    <textarea name="caption" class="form-control" rows="3" placeholder="Contoh: Kontrak kerja sama yang sudah disetujui / foto progress minggu pertama."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success">Simpan File</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= base_url('assets/vendor/chartjs/chart.umd.js'); ?>"></script>
<script>
function openProjectEvidenceModal(button) {
    const title = button.getAttribute('data-evidence-title') || 'Bukti';
    let items = [];
    try {
        items = JSON.parse(button.getAttribute('data-evidence-items') || '[]');
    } catch (e) {
        items = [];
    }

    const titleEl = document.getElementById('projectEvidenceModalTitle');
    const bodyEl = document.getElementById('projectEvidenceModalBody');
    if (titleEl) titleEl.textContent = title;

    const currentTab = new URLSearchParams(window.location.search).get('tab') || 'overview';

    if (bodyEl) {
        if (!items.length) {
            bodyEl.innerHTML = '<div class="alert alert-light border mb-0 text-muted">Belum ada bukti/foto yang tersimpan.</div>';
        } else {
            bodyEl.innerHTML = '<div class="row g-3">' + items.map((item, index) => {
                const url = item.url || '#';
                const label = item.label || ('Bukti ' + (index + 1));
                const evidenceId = parseInt(item.evidence_id || 0, 10);
                const evidenceType = item.evidence_type || '';
                const createdAt = item.created_at ? '<div class="small text-muted mt-1">Upload: ' + escapeHtml(item.created_at) + '</div>' : '';
                const deleteForm = (evidenceId > 0 && evidenceType)
                    ? '<form method="POST" class="mt-2" onsubmit="return confirm(\'Hapus bukti/foto ini?\')">'
                        + '<input type="hidden" name="action" value="delete_project_evidence">'
                        + '<input type="hidden" name="evidence_type" value="' + escapeAttr(evidenceType) + '">'
                        + '<input type="hidden" name="evidence_id" value="' + evidenceId + '">'
                        + '<input type="hidden" name="return_tab" value="' + escapeAttr(currentTab) + '">'
                        + '<button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash"></i> Hapus Foto/Bukti</button>'
                    + '</form>'
                    : '';

                if (item.is_image) {
                    return '<div class="col-md-4"><div class="border rounded-4 p-2 h-100 bg-light">'
                        + '<a href="' + escapeAttr(url) + '" target="_blank"><img src="' + escapeAttr(url) + '" class="img-fluid rounded-3 w-100" style="height:190px;object-fit:cover;" alt="Bukti"></a>'
                        + '<div class="mt-2 small fw-semibold text-truncate">' + escapeHtml(label) + '</div>'
                        + createdAt
                        + '<a href="' + escapeAttr(url) + '" target="_blank" class="btn btn-sm btn-outline-primary w-100 mt-2">Buka</a>'
                        + deleteForm
                    + '</div></div>';
                }
                return '<div class="col-md-4"><div class="border rounded-4 p-3 h-100 bg-light">'
                    + '<div class="fw-semibold text-truncate">' + escapeHtml(label) + '</div>'
                    + createdAt
                    + '<a href="' + escapeAttr(url) + '" target="_blank" class="btn btn-sm btn-outline-primary w-100 mt-3">Buka / Download</a>'
                    + deleteForm
                + '</div></div>';
            }).join('') + '</div>';
        }
    }

    const modalEl = document.getElementById('projectEvidenceModal');
    if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } else if (modalEl) {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        modalEl.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
    }
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function (char) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char];
    });
}

function escapeAttr(value) {
    return escapeHtml(value);
}

document.addEventListener('DOMContentLoaded', function () {
    const formatNumber = (value) => {
        value = String(value || '').replace(/[^\d]/g, '');
        return value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    };

    const projectContractValue = <?= json_encode((float) $contractValue); ?>;
    const parseMoney = (value) => Number(String(value || '').replace(/[^\d]/g, '')) || 0;
    const parsePercent = (value) => Number(String(value || '').replace(',', '.').replace(/[^\d.]/g, '')) || 0;

    document.querySelectorAll('.money-input').forEach(input => {
        input.addEventListener('input', function () {
            this.value = formatNumber(this.value);
        });
    });

    document.querySelectorAll('form').forEach(form => {
        const netInput = form.querySelector('.term-net-input');
        const taxPreview = form.querySelector('.term-tax-preview');
        const percentagePreview = form.querySelector('.term-tax-percent-preview');
        if (!netInput || !taxPreview || !percentagePreview) return;

        const gross = Number(taxPreview.dataset.gross) || 0;
        const refreshTax = () => {
            const received = Math.min(gross, parseMoney(netInput.value));
            const tax = Math.max(0, gross - received);
            const percentage = gross > 0 ? tax / gross * 100 : 0;
            taxPreview.textContent = 'Rp ' + formatNumber(tax);
            percentagePreview.textContent = percentage.toFixed(2).replace('.', ',') + '%';
        };
        netInput.addEventListener('input', function () {
            this.value = formatNumber(this.value);
            refreshTax();
        });
        refreshTax();
    });

    // Termin dua arah: isi persentase -> nominal otomatis, isi nominal -> persentase otomatis.
    document.querySelectorAll('form').forEach(form => {
        const actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || !['add_term', 'update_term'].includes(actionInput.value)) return;

        const percentageInput = form.querySelector('input[name="percentage"]');
        const amountInput = form.querySelector('input[name="amount"]');
        if (!percentageInput || !amountInput) return;

        let syncing = false;

        percentageInput.addEventListener('input', function () {
            if (syncing) return;
            syncing = true;
            const percentage = parsePercent(this.value);
            if (percentage > 0 && projectContractValue > 0) {
                const amount = Math.round(projectContractValue * percentage / 100);
                amountInput.value = formatNumber(amount);
            }
            syncing = false;
        });

        amountInput.addEventListener('input', function () {
            if (syncing) return;
            syncing = true;
            const amount = parseMoney(this.value);
            if (amount > 0 && projectContractValue > 0) {
                const percentage = (amount / projectContractValue) * 100;
                percentageInput.value = percentage.toFixed(2);
            }
            syncing = false;
        });

        form.addEventListener('submit', function () {
            amountInput.value = formatNumber(amountInput.value);
            if (percentageInput.value) {
                percentageInput.value = String(percentageInput.value).replace(',', '.');
            }
        });
    });

    // Search + sort ringan untuk semua tabel data di halaman detail project.
    document.querySelectorAll('.table-responsive .table-modern').forEach((table, idx) => {
        if (table.closest('.overview')) return;
        const wrapper = table.closest('.table-responsive');
        const tools = document.createElement('div');
        tools.className = 'table-tools';
        tools.innerHTML = `<input type="text" class="form-control table-search" placeholder="Cari data tabel..."><div class="small text-muted table-count"></div>`;
        wrapper.parentNode.insertBefore(tools, wrapper);
        const search = tools.querySelector('.table-search');
        const count = tools.querySelector('.table-count');
        const rows = Array.from(table.querySelectorAll('tbody tr'));

        function refreshRows() {
            const q = search.value.toLowerCase();
            let visible = 0;
            rows.forEach(row => {
                const match = row.innerText.toLowerCase().includes(q);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            count.textContent = visible + ' dari ' + rows.length + ' data';
        }
        search.addEventListener('input', refreshRows);
        refreshRows();

        table.querySelectorAll('thead th').forEach((th, index) => {
            if (th.classList.contains('text-end')) return;
            th.classList.add('sortable-th');
            th.addEventListener('click', () => {
                const asc = th.dataset.asc !== '1';
                th.dataset.asc = asc ? '1' : '0';
                rows.sort((a, b) => {
                    const av = (a.children[index]?.innerText || '').trim();
                    const bv = (b.children[index]?.innerText || '').trim();
                    const an = parseFloat(av.replace(/[^0-9-]/g, ''));
                    const bn = parseFloat(bv.replace(/[^0-9-]/g, ''));
                    if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
                    return asc ? av.localeCompare(bv) : bv.localeCompare(av);
                });
                const tbody = table.querySelector('tbody');
                rows.forEach(r => tbody.appendChild(r));
                refreshRows();
            });
        });
    });

    function refreshPersonFields(scope) {
        scope.querySelectorAll('.source-type, .funding-source').forEach(select => {
            const root = select.closest('form') || select.closest('.row, .modal-body') || document;
            const companyFields = root.querySelectorAll('.company-account-field');
            const operationalFields = root.querySelectorAll('.operational-fund-field');
            const personFields = root.querySelectorAll('.person-field');
            const personSource = root.querySelector('.person-source');
            const employeeFields = root.querySelectorAll('.person-employee-field');
            const manualFields = root.querySelectorAll('.person-manual-field');
            const showPerson = select.value === 'person';
            const showOperational = select.value === 'operational_fund';

            companyFields.forEach(el => el.classList.toggle('d-none', showPerson || showOperational));
            operationalFields.forEach(el => el.classList.toggle('d-none', !showOperational));
            personFields.forEach(el => el.classList.toggle('d-none', !showPerson));

            if (showPerson) {
                const mode = personSource ? personSource.value : 'manual';
                employeeFields.forEach(el => el.classList.toggle('d-none', mode !== 'employee'));
                manualFields.forEach(el => el.classList.toggle('d-none', mode !== 'manual'));
            } else {
                employeeFields.forEach(el => el.classList.add('d-none'));
                manualFields.forEach(el => el.classList.add('d-none'));
            }
        });
    }

    document.querySelectorAll('.source-type, .funding-source, .person-source').forEach(select => {
        select.addEventListener('change', function () {
            refreshPersonFields(this.closest('form') || document);
        });
    });

    document.querySelectorAll('form').forEach(form => refreshPersonFields(form));

    function refreshAssetFields(form) {
        if (!form) return;
        const checkbox = form.querySelector('.asset-toggle');
        const fields = form.querySelector('.asset-fields');
        if (!checkbox || !fields) return;
        if (checkbox.checked) {
            fields.classList.remove('d-none');
        } else {
            fields.classList.add('d-none');
        }
    }

    document.querySelectorAll('form').forEach(form => refreshAssetFields(form));
    document.querySelectorAll('.asset-toggle').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            refreshAssetFields(this.closest('form'));
        });
    });


    document.querySelectorAll('.worker-source').forEach(select => {
        select.addEventListener('change', function () {
            const form = this.closest('form');
            const empWrap = form.querySelector('.employee-select-wrap');
            const manualWrap = form.querySelector('.manual-worker-wrap');
            const workerType = form.querySelector('[name="worker_type"]');
            if (this.value === 'employee') {
                empWrap?.classList.remove('d-none');
                manualWrap?.classList.add('d-none');
                if (workerType) workerType.value = 'employee';
            } else {
                empWrap?.classList.add('d-none');
                manualWrap?.classList.remove('d-none');
                if (workerType) workerType.value = 'freelance';
            }
        });
    });

    document.querySelectorAll('.employee-select').forEach(select => {
        select.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            const form = this.closest('form');
            const rate = selected.dataset.rate || '';
            const type = selected.dataset.type || 'daily';
            const rateInput = form.querySelector('.worker-rate');
            const paymentType = form.querySelector('.payment-type');
            if (rateInput && rate) rateInput.value = formatNumber(rate);
            if (paymentType && type) paymentType.value = type;
        });
    });

    function refreshWorkerFields(form) {
        const workerType = form.querySelector('[name="worker_type"]');
        const paymentType = form.querySelector('[name="payment_type"]');
        if (!workerType || !paymentType) return;
        const type = workerType.value;
        const pay = paymentType.value;
        const dateFields = form.querySelectorAll('.worker-date-field');
        const daysFields = form.querySelectorAll('.worker-days-field');

        if (type === 'borongan' || pay === 'borongan') {
            dateFields.forEach(el => el.classList.remove('d-none'));
            daysFields.forEach(el => el.classList.add('d-none'));
            paymentType.value = 'borongan';
        } else if (type === 'employee') {
            dateFields.forEach(el => el.classList.remove('d-none'));
            daysFields.forEach(el => el.classList.add('d-none'));
        } else {
            dateFields.forEach(el => el.classList.add('d-none'));
            daysFields.forEach(el => el.classList.remove('d-none'));
            paymentType.value = 'daily';
        }
    }

    document.querySelectorAll('form').forEach(form => refreshWorkerFields(form));
    document.querySelectorAll('[name="worker_type"], [name="payment_type"]').forEach(field => {
        field.addEventListener('change', function () {
            refreshWorkerFields(this.closest('form'));
        });
    });

    document.querySelectorAll('.project-posting-mode').forEach(select => {
        const form = select.closest('form');
        const netInput = form?.querySelector('.historical-net-input');
        const amountInput = form?.querySelector('[name="amount"]');
        const taxPreview = form?.querySelector('.historical-tax-preview');
        const taxPercentPreview = form?.querySelector('.historical-tax-percent-preview');
        if (netInput && typeof netInput.dataset.autoGross === 'undefined') {
            netInput.dataset.autoGross = netInput.value.trim() === '' ? '1' : '0';
        }

        const refreshHistoricalTax = function (useGrossAsDefault = false) {
            if (!netInput || !amountInput || !taxPreview || !taxPercentPreview) return;
            const gross = parseMoney(amountInput.value);
            let received = parseMoney(netInput.value);
            if (useGrossAsDefault && netInput.dataset.autoGross === '1' && gross > 0) {
                received = gross;
                netInput.value = formatNumber(gross);
            }
            const tax = Math.max(0, gross - Math.min(gross, received));
            const percentage = gross > 0 ? tax / gross * 100 : 0;
            taxPreview.textContent = 'Rp ' + formatNumber(tax);
            taxPercentPreview.textContent = percentage.toFixed(2).replace('.', ',') + '%';
        };

        const sync = function () {
            const historical = select.value === 'historical';
            const nonPosted = select.value !== 'posted';
            const status = form?.querySelector('.project-term-status');
            if (status && nonPosted) status.value = historical ? 'paid' : 'unpaid';
            if (status) status.disabled = nonPosted;

            const paidDateBox = form?.querySelector('.project-historical-paid-date');
            paidDateBox?.classList.toggle('d-none', !historical);
            const paidDate = paidDateBox?.querySelector('[name="paid_date"]');
            if (paidDate) {
                paidDate.disabled = !historical;
                paidDate.required = historical;
            }

            form?.querySelectorAll('.project-historical-payment').forEach(field => {
                field.classList.toggle('d-none', !historical);
                field.querySelectorAll('input, select, textarea').forEach(input => {
                    input.disabled = !historical;
                });
            });
            if (netInput) netInput.required = historical;

            form?.querySelectorAll('.project-term-balance-field').forEach(field => {
                field.classList.toggle('d-none', nonPosted);
                field.querySelectorAll('input, select, textarea').forEach(input => {
                    input.disabled = nonPosted;
                });
            });

            const box = form?.querySelector('.project-historical-reason');
            box?.classList.toggle('d-none', !nonPosted);
            const reason = box?.querySelector('[name="historical_reason"]');
            if (reason) reason.required = nonPosted;
            refreshHistoricalTax(historical);
        };

        netInput?.addEventListener('input', function () {
            this.dataset.autoGross = '0';
            this.value = formatNumber(this.value);
            refreshHistoricalTax();
        });
        amountInput?.addEventListener('input', function () {
            refreshHistoricalTax(select.value === 'historical');
        });
        select.addEventListener('change', sync);
        sync();
    });

    const ctx = document.getElementById('financeChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Nilai Kontrak', 'Pemasukan', 'Dana Digunakan', 'Biaya Project', 'Biaya Pekerja', 'Saldo Project'],
                datasets: [{
                    label: 'Keuangan Project',
                    data: [
                        <?= json_encode($contractValue); ?>,
                        <?= json_encode($totalClientIncome); ?>,
                        <?= json_encode($totalAllFund); ?>,
                        <?= json_encode($totalExpense); ?>,
                        <?= json_encode($totalWorkerCost); ?>,
                        <?= json_encode($cashPosition); ?>
                    ],
                    tension: 0.42,
                    fill: true,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Rp ' + new Intl.NumberFormat('id-ID').format(context.raw || 0);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
