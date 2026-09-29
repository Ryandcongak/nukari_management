<?php
require_once __DIR__ . '/../../includes/finance_helper.php';

if (!function_exists('asset_money_value')) {
    function asset_money_value($value) {
        $value = trim((string)$value);
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}

if (!function_exists('asset_upload_file')) {
    function asset_upload_file($file, $folder = 'assets', $prefix = 'file', $allowedExt = ['jpg','jpeg','png','webp','pdf']) {
        if (empty($file['name']) || empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new Exception('Format file tidak didukung. Gunakan: ' . strtoupper(implode(', ', $allowedExt)) . '.');
        }
        validate_upload($file, ['image/jpeg','image/png','image/webp','application/pdf']);
        $uploadDir = __DIR__ . '/../../uploads/' . trim($folder, '/') . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        $fileName = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            throw new Exception('Gagal mengupload file.');
        }
        return 'uploads/' . trim($folder, '/') . '/' . $fileName;
    }
}

if (!function_exists('asset_create_transaction')) {
    function asset_create_transaction(PDO $pdo, $type, $amount, $description, $paymentMethod = 'cash', $proofFile = null, ?int $projectId = null, ?int $assetId = null, ?int $accountId = null, ?string $transactionDate = null) {
        $amount = (float)$amount;
        if ($amount <= 0) {
            throw new Exception('Nominal transaksi aset wajib lebih dari nol.');
        }
        if ($type === 'expense') {
            if (!$accountId) {
                throw new Exception('Akun kas/bank wajib dipilih untuk pengeluaran aset.');
            }
            $accountStmt = $pdo->prepare("SELECT account_name, current_balance, status FROM finance_accounts WHERE id=:id LIMIT 1 FOR UPDATE");
            $accountStmt->execute([':id'=>$accountId]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            if (!$account || ($account['status'] ?? '') !== 'active') {
                throw new Exception('Akun pembayaran tidak ditemukan atau tidak aktif.');
            }
            if ((float)$account['current_balance'] < $amount) {
                throw new Exception('Saldo akun ' . ($account['account_name'] ?? '') . ' tidak mencukupi. Saldo tersedia Rp ' . number_format((float)($account['current_balance'] ?? 0), 0, ',', '.') . ', kebutuhan Rp ' . number_format($amount, 0, ',', '.') . '.');
            }
        }
        $code = strtoupper($type === 'income' ? 'INC' : 'EXP') . '-AST-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare("INSERT INTO transactions (transaction_code, transaction_date, type, category_id, account_id, project_id, amount, description, payment_method, proof_file, source_module, source_table, source_id, created_by) VALUES (:code, :transaction_date, :type, NULL, :account_id, :project_id, :amount, :description, :payment_method, :proof_file, 'asset', 'assets', :source_id, :created_by)");
        $stmt->execute([
            ':code' => $code,
            ':transaction_date' => $transactionDate ?: date('Y-m-d'),
            ':type' => $type,
            ':amount' => $amount,
            ':description' => $description,
            ':payment_method' => $paymentMethod,
            ':proof_file' => $proofFile,
            ':project_id' => $projectId ?: null,
            ':account_id' => $accountId ?: null,
            ':source_id' => $assetId ?: null,
            ':created_by' => $_SESSION['user_id'] ?? null,
        ]);
        $transactionId = (int)$pdo->lastInsertId();
        if ($accountId && function_exists('finance_recalculate_account_balance')) {
            finance_recalculate_account_balance($pdo, $accountId);
        }
        return $transactionId;
    }
}

if (!function_exists('asset_create_project_mirror')) {
    function asset_create_project_mirror(PDO $pdo, int $projectId, string $type, float $amount, string $category, string $description, string $sourceModule, int $sourceId, string $paymentMethod = 'cash', ?int $accountId = null, ?string $transactionDate = null): int {
        $stmt = $pdo->prepare("INSERT INTO project_transactions (project_id, transaction_date, transaction_type, funding_source, account_id, payment_method, category, description, amount, is_asset, source_module, source_id) VALUES (:project_id, :transaction_date, :transaction_type, 'company', :account_id, :payment_method, :category, :description, :amount, 0, :source_module, :source_id)");
        $stmt->execute([
            ':project_id' => $projectId,
            ':transaction_date' => $transactionDate ?: date('Y-m-d'),
            ':transaction_type' => $type,
            ':account_id' => $accountId ?: null,
            ':payment_method' => $paymentMethod,
            ':category' => $category,
            ':description' => $description,
            ':amount' => $amount,
            ':source_module' => $sourceModule,
            ':source_id' => $sourceId,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('asset_assert_available_for_rental')) {
    function asset_assert_available_for_rental(array $asset): void {
        if (in_array($asset['asset_status'] ?? '', ['sold', 'lost', 'disposed', 'damaged', 'maintenance'], true)
            || in_array($asset['condition_status'] ?? '', ['lost', 'damaged', 'maintenance'], true)) {
            throw new Exception('Aset yang rusak, hilang, maintenance, dihapuskan, atau sudah dijual tidak dapat disewakan.');
        }
    }
}

if (!function_exists('asset_create_notification')) {
    function asset_create_notification(PDO $pdo, $notificationType, $title, $description, $dueDate, $priority = 'warning', $icon = 'bi-bell', $linkUrl = null) {
        if (!$dueDate) return null;
        $stmt = $pdo->prepare("INSERT INTO system_notifications (notification_type, title, description, due_date, priority, icon, link_url, status) VALUES (:type, :title, :description, :due_date, :priority, :icon, :link_url, 'active')");
        $stmt->execute([
            ':type' => $notificationType,
            ':title' => $title,
            ':description' => $description,
            ':due_date' => $dueDate,
            ':priority' => $priority,
            ':icon' => $icon,
            ':link_url' => $linkUrl,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('asset_create_maintenance_reminder')) {
    function asset_create_maintenance_reminder(PDO $pdo, int $assetId, string $type, string $title, string $description, string $dueDate, int $reminderDays = 7): int {
        $allowed = ['service','oil_change','vehicle_tax','stnk','insurance','kir','calibration','inspection','other'];
        $type = in_array($type, $allowed, true) ? $type : 'other';
        $link = 'pages/assets/detail.php?id=' . $assetId . '#maintenance';
        $notificationId = asset_create_notification($pdo, 'asset_maintenance', $title, $description, $dueDate, 'warning', 'bi-tools', $link);
        $stmt = $pdo->prepare("INSERT INTO asset_reminders (asset_id, reminder_type, title, description, due_date, remind_before_days, priority, status, notification_id, link_url, created_by) VALUES (:asset_id,:reminder_type,:title,:description,:due_date,:remind_before_days,'warning','active',:notification_id,:link_url,:created_by)");
        $stmt->execute([
            ':asset_id'=>$assetId,
            ':reminder_type'=>$type,
            ':title'=>$title,
            ':description'=>$description,
            ':due_date'=>$dueDate,
            ':remind_before_days'=>max(0, $reminderDays),
            ':notification_id'=>$notificationId,
            ':link_url'=>$link,
            ':created_by'=>$_SESSION['user_id'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('asset_status_view')) {
    function asset_status_view($status) {
        $map = [
            'active' => ['Aktif','success','bi-check-circle'],
            'maintenance' => ['Maintenance','warning','bi-tools'],
            'damaged' => ['Rusak','danger','bi-exclamation-triangle'],
            'lost' => ['Hilang','secondary','bi-question-circle'],
            'sold' => ['Dijual','dark','bi-cash-coin'],
            'rented' => ['Disewakan','info','bi-key'],
            'inactive' => ['Tidak Aktif','secondary','bi-pause-circle'],
            'disposed' => ['Dihapuskan','dark','bi-archive'],
        ];
        return $map[$status ?: 'active'] ?? [$status, 'secondary', 'bi-circle'];
    }
}

if (!function_exists('asset_add_event')) {
    function asset_add_event(PDO $pdo, $assetId, $eventType, $title, $description = null, $referenceTable = null, $referenceId = null, $eventDate = null) {
        if (!$assetId) return null;
        $stmt = $pdo->prepare("INSERT INTO asset_events (asset_id, event_date, event_type, title, description, reference_table, reference_id, created_by) VALUES (:asset_id, :event_date, :event_type, :title, :description, :reference_table, :reference_id, :created_by)");
        $stmt->execute([
            ':asset_id' => $assetId,
            ':event_date' => $eventDate ?: date('Y-m-d H:i:s'),
            ':event_type' => $eventType ?: 'other',
            ':title' => $title ?: 'Aktivitas aset',
            ':description' => $description,
            ':reference_table' => $referenceTable,
            ':reference_id' => $referenceId,
            ':created_by' => $_SESSION['user_id'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('asset_create_asset_reminder')) {
    function asset_create_asset_reminder(PDO $pdo, $assetId, $type, $title, $description, $dueDate, $beforeDays = 7, $priority = 'warning', $linkUrl = null) {
        if (!$dueDate) return null;
        $stmt = $pdo->prepare("INSERT INTO asset_reminders (asset_id, reminder_type, title, description, due_date, remind_before_days, priority, status, link_url, created_by) VALUES (:asset_id, :type, :title, :description, :due_date, :before_days, :priority, 'active', :link_url, :created_by)");
        $stmt->execute([
            ':asset_id' => $assetId ?: null,
            ':type' => $type ?: 'other',
            ':title' => $title,
            ':description' => $description,
            ':due_date' => $dueDate,
            ':before_days' => (int)$beforeDays,
            ':priority' => $priority ?: 'warning',
            ':link_url' => $linkUrl,
            ':created_by' => $_SESSION['user_id'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($assetId) asset_add_event($pdo, $assetId, 'reminder', $title, $description, 'asset_reminders', $id, $dueDate . ' 00:00:00');
        return $id;
    }
}

if (!function_exists('asset_sync_due_reminders')) {
    function asset_sync_due_reminders(PDO $pdo) {
        $stmt = $pdo->query("SELECT r.*, a.asset_name, a.asset_code FROM asset_reminders r LEFT JOIN assets a ON r.asset_id=a.id WHERE r.status='active' AND r.due_date <= DATE_ADD(CURDATE(), INTERVAL r.remind_before_days DAY) AND r.notification_id IS NULL ORDER BY r.due_date ASC LIMIT 100");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $priority = (strtotime($r['due_date']) < strtotime(date('Y-m-d'))) ? 'danger' : ($r['priority'] ?: 'warning');
            $title = $r['title'];
            $desc = trim(($r['description'] ?: '') . '\nAset: ' . (($r['asset_code'] ?: '') . ' ' . ($r['asset_name'] ?: '')) . '\nJatuh tempo: ' . $r['due_date']);
            $notifId = asset_create_notification($pdo, 'asset_' . $r['reminder_type'], $title, $desc, $r['due_date'], $priority, 'bi-bell', $r['link_url'] ?: ($r['asset_id'] ? 'pages/assets/detail.php?id=' . $r['asset_id'] : 'pages/assets/index.php'));
            $pdo->prepare("UPDATE asset_reminders SET notification_id=:notification_id, status=IF(due_date < CURDATE(),'overdue',status), updated_at=NOW() WHERE id=:id")->execute([':notification_id'=>$notifId, ':id'=>$r['id']]);
        }
        $pdo->query("UPDATE asset_reminders SET status='overdue', updated_at=NOW() WHERE status='active' AND due_date < CURDATE()");
        $pdo->query("UPDATE asset_borrowings SET status='overdue', updated_at=NOW() WHERE status='borrowed' AND expected_return_date IS NOT NULL AND expected_return_date < CURDATE()");
        return count($rows);
    }
}

if (!function_exists('asset_depreciation_value')) {
    function asset_depreciation_value($purchasePrice, $residualValue, $usefulLifeMonths, $purchaseDate, $asOfDate = null) {
        $purchasePrice = (float)$purchasePrice;
        $residualValue = (float)$residualValue;
        $usefulLifeMonths = (int)$usefulLifeMonths;
        if ($usefulLifeMonths <= 0 || !$purchaseDate) return $purchasePrice;
        $start = new DateTime($purchaseDate);
        $end = new DateTime($asOfDate ?: date('Y-m-d'));
        if ($end < $start) return $purchasePrice;
        $months = ((int)$end->format('Y') - (int)$start->format('Y')) * 12 + ((int)$end->format('m') - (int)$start->format('m'));
        $months = max(0, min($months, $usefulLifeMonths));
        $depreciable = max(0, $purchasePrice - $residualValue);
        $depreciation = $depreciable * ($months / $usefulLifeMonths);
        return max($residualValue, $purchasePrice - $depreciation);
    }
}

if (!function_exists('asset_reminder_type_label')) {
    function asset_reminder_type_label($type) {
        $map = [
            'service'=>'Service Berkala','oil_change'=>'Ganti Oli','vehicle_tax'=>'Pajak Kendaraan','stnk'=>'STNK','insurance'=>'Asuransi','kir'=>'KIR','calibration'=>'Kalibrasi','inspection'=>'Inspeksi','warranty'=>'Garansi Berakhir','pln'=>'PLN','pdam'=>'PDAM','internet'=>'Internet','hosting'=>'Hosting Website','domain'=>'Domain Website','land_rent_contract'=>'Kontrak Sewa Lahan','warehouse_rent_contract'=>'Kontrak Sewa Gudang','building_rent_contract'=>'Kontrak Sewa Bangunan','vehicle_rent_contract'=>'Kontrak Sewa Kendaraan','rental_due'=>'Tagihan Sewa Aset','other'=>'Lainnya'
        ];
        return $map[$type] ?? $type;
    }
}
