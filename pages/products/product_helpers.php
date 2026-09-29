<?php
if (!function_exists('product_money')) {
    function product_money($value): string {
        return 'Rp ' . number_format((float)$value, 0, ',', '.');
    }
}
if (!function_exists('product_num')) {
    function product_num($value, $decimals = 3): string {
        $value = (float)$value;
        $txt = number_format($value, $decimals, ',', '.');
        return rtrim(rtrim($txt, '0'), ',');
    }
}
if (!function_exists('product_parse_number')) {
    function product_parse_number($value): float {
        if ($value === null || $value === '') return 0;
        $value = trim((string)$value);
        $value = str_replace(['Rp', 'rp', ' ', ','], ['', '', '', '.'], $value);
        // Indonesian thousands like 10.000.000 -> remove dots when no decimal comma exists
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            $value = str_replace('.', '', $value);
        }
        return (float)$value;
    }
}
if (!function_exists('product_code')) {
    function product_code(string $prefix): string {
        return strtoupper($prefix) . '-' . date('YmdHis') . '-' . random_int(10, 99);
    }
}
if (!function_exists('product_safe')) {
    function product_safe($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('product_badge')) {
    function product_badge($status): string {
        $map = [
            'active'=>'success','inactive'=>'secondary','draft'=>'secondary','completed'=>'success','cancelled'=>'danger',
            'inquiry'=>'info','approved'=>'primary','production'=>'warning','offline'=>'secondary','tokopedia'=>'success','shopee'=>'warning','bazar'=>'primary','custom'=>'dark'
        ];
        $class = $map[$status] ?? 'secondary';
        return '<span class="badge bg-'.$class.'">'.product_safe(ucwords(str_replace('_',' ',(string)$status))).'</span>';
    }
}
if (!function_exists('product_get_user_id')) {
    function product_get_user_id(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}
if (!function_exists('product_phone_key')) {
    function product_phone_key(string $phone): string {
        $key = preg_replace('/\D+/', '', $phone);
        return strpos($key, '0') === 0 ? '62' . substr($key, 1) : $key;
    }
}
if (!function_exists('product_resolve_client')) {
    function product_resolve_client(PDO $pdo, int $selectedId, string $name, string $phone, string $email, bool $required = true): array {
        if ($selectedId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id=? AND status='active' LIMIT 1");
            $stmt->execute([$selectedId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) throw new Exception('Client yang dipilih tidak ditemukan atau sudah tidak aktif.');
            return [(int)$client['id'], $client['client_name'], $client['phone'] ?: $phone, $client['email'] ?: $email];
        }
        if ($name === '') throw new Exception('Nama client wajib diisi.');
        if ($required && $phone === '') throw new Exception('Nomor WhatsApp client wajib diisi.');

        $phoneKey = product_phone_key($phone);
        $emailKey = strtolower(trim($email));
        foreach ($pdo->query("SELECT id,client_name,phone,email FROM clients")->fetchAll(PDO::FETCH_ASSOC) as $client) {
            $samePhone = $phoneKey !== '' && product_phone_key((string)$client['phone']) === $phoneKey;
            $sameEmail = $emailKey !== '' && strtolower(trim((string)$client['email'])) === $emailKey;
            if ($samePhone || $sameEmail) {
                return [(int)$client['id'], $client['client_name'], $client['phone'] ?: $phone, $client['email'] ?: $email];
            }
        }
        $stmt = $pdo->prepare("INSERT INTO clients(client_name,contact_person,phone,email,status,notes,created_at) VALUES(?,?,?,?, 'active','Dibuat dari Custom Order',NOW())");
        $stmt->execute([$name, $name, $phone ?: null, $email ?: null]);
        return [(int)$pdo->lastInsertId(), $name, $phone, $email];
    }
}
if (!function_exists('product_recalc_material_stock')) {
    function product_recalc_material_stock(PDO $pdo, int $materialId): void {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type IN ('in','return') THEN quantity WHEN movement_type IN ('out','damaged','lost') THEN -quantity WHEN movement_type='adjustment' THEN quantity ELSE 0 END),0) FROM raw_material_movements WHERE material_id=?");
        $stmt->execute([$materialId]);
        $stock = (float)$stmt->fetchColumn();
        $upd = $pdo->prepare("UPDATE raw_materials SET stock=?, updated_at=NOW() WHERE id=?");
        $upd->execute([$stock, $materialId]);
    }
}
if (!function_exists('product_update_product_profit')) {
    function product_update_product_profit(PDO $pdo, int $productId): void {
        $stmt = $pdo->prepare("UPDATE products SET profit_estimate = selling_price - COALESCE(hpp_price, production_cost, 0), updated_at=NOW() WHERE id=?");
        $stmt->execute([$productId]);
    }
}
if (!function_exists('product_upload_acquisition_proof')) {
    function product_upload_acquisition_proof(string $field): ?string {
        if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new Exception('Bukti perolehan bahan gagal diunggah.');
        validate_upload($_FILES[$field], ['image/jpeg','image/png','image/webp','application/pdf']);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
        $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime]??null;
        if(!$ext)throw new Exception('Bukti hanya boleh JPG, PNG, WEBP, atau PDF.');
        $dir=__DIR__.'/../../uploads/material_acquisitions/';
        if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new Exception('Folder bukti bahan tidak dapat dibuat.');
        $name='material_'.date('YmdHis').'_'.bin2hex(random_bytes(4)).'.'.$ext;
        if(!move_uploaded_file($_FILES[$field]['tmp_name'],$dir.$name))throw new Exception('Bukti perolehan bahan gagal disimpan.');
        return 'uploads/material_acquisitions/'.$name;
    }
}
