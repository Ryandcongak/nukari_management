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
?>
