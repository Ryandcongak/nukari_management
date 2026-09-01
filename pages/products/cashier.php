<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Kasir / POS Produk - Nukari';

if (!function_exists('cashier_safe')) {
    function cashier_safe($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('cashier_money')) {
    function cashier_money($value) {
        if (function_exists('product_money')) return product_money($value);
        return 'Rp ' . number_format((float)$value, 0, ',', '.');
    }
}
if (!function_exists('cashier_num')) {
    function cashier_num($value) {
        if (function_exists('product_num')) return product_num($value);
        $n = (float)$value;
        return fmod($n, 1.0) == 0.0 ? number_format($n, 0, ',', '.') : number_format($n, 3, ',', '.');
    }
}
if (!function_exists('cashier_parse_number')) {
    function cashier_parse_number($value): float {
        if (function_exists('product_parse_number')) return (float) product_parse_number($value);
        $value = trim((string)$value);
        if ($value === '') return 0;
        $value = str_replace(['Rp', ' ', '.'], '', $value);
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}
if (!function_exists('cashier_product_photo_url')) {
    function cashier_product_photo_url($photo): string {
        $photo = trim((string)($photo ?? ''));
        if ($photo === '') return '';
        if (preg_match('~^(https?:)?//|^data:image/~i', $photo)) return $photo;

        $photo = ltrim($photo, '/');
        if (function_exists('base_url')) {
            return base_url($photo);
        }

        return '../../' . $photo;
    }
}
function cashier_user_id() {
    return $_SESSION['user_id'] ?? null;
}
function cashier_payment_method_for_transactions($method) {
    return in_array($method, ['cash','bank_transfer','qris'], true) ? $method : 'other';
}
function cashier_product_sales_channel($channel) {
    return in_array($channel, ['offline','tokopedia','shopee','bazar','custom','other'], true) ? $channel : 'offline';
}
function cashier_category_id(PDO $pdo, string $name = 'Penjualan Produk') {
    try {
        $stmt = $pdo->prepare("SELECT id FROM transaction_categories WHERE category_name = ? LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        $stmt = $pdo->prepare("INSERT INTO transaction_categories (category_name, type) VALUES (?, 'income')");
        $stmt->execute([$name]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'checkout_sale') {
        $cart = json_decode($_POST['cart_json'] ?? '[]', true);
        if (!is_array($cart) || count($cart) < 1) {
            $errors[] = 'Keranjang masih kosong.';
        }

        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $customerEmail = trim($_POST['customer_email'] ?? '');
        $channel = $_POST['channel'] ?? 'cashier';
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $discountAmount = max(0, cashier_parse_number($_POST['discount_amount'] ?? 0));
        $notes = trim($_POST['notes'] ?? '');

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                $ids = array_values(array_unique(array_map(fn($i) => (int)($i['id'] ?? 0), $cart)));
                $ids = array_filter($ids);
                if (!$ids) throw new Exception('Produk tidak valid.');

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) FOR UPDATE");
                $stmt->execute($ids);
                $productMap = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $productMap[(int)$p['id']] = $p;
                }

                $lines = [];
                $subtotal = 0;
                $totalContribution = 0;
                foreach ($cart as $item) {
                    $pid = (int)($item['id'] ?? 0);
                    if (!$pid || !isset($productMap[$pid])) throw new Exception('Produk tidak ditemukan.');
                    $p = $productMap[$pid];
                    $qty = max(0, cashier_parse_number($item['qty'] ?? 0));
                    if ($qty <= 0) throw new Exception('Qty produk ' . $p['product_name'] . ' tidak valid.');
                    if ($qty > (float)$p['stock']) throw new Exception('Stok ' . $p['product_name'] . ' tidak cukup. Stok tersedia: ' . cashier_num($p['stock']) . ' ' . ($p['unit'] ?? 'pcs'));

                    $unitPrice = cashier_parse_number($item['unit_price'] ?? $p['selling_price']);
                    if ($unitPrice <= 0) $unitPrice = (float)$p['selling_price'];
                    $hpp = (float)($p['hpp_price'] ?? 0);
                    $gross = $qty * $unitPrice;
                    $subtotal += $gross;

                    $lines[] = [
                        'product' => $p,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'hpp_price' => $hpp,
                        'gross_amount' => $gross
                    ];
                }

                if ($discountAmount > $subtotal) {
                    throw new Exception('Diskon tidak boleh lebih besar dari subtotal.');
                }

                $totalAmount = $subtotal - $discountAmount;
                $receiptCode = 'POS-' . date('YmdHis') . '-' . random_int(10, 99);
                $categoryId = cashier_category_id($pdo, 'Penjualan Produk');
                $transactionCode = 'TRX-' . date('YmdHis') . '-' . random_int(10, 99);
                $trxMethod = cashier_payment_method_for_transactions($paymentMethod);

                $stmt = $pdo->prepare("INSERT INTO transactions
                    (transaction_code, transaction_date, type, category_id, amount, description, payment_method, created_by)
                    VALUES (?, CURDATE(), 'income', ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $transactionCode,
                    $categoryId,
                    $totalAmount,
                    'Penjualan kasir produk - ' . $receiptCode,
                    $trxMethod,
                    cashier_user_id()
                ]);
                $transactionId = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO product_cashier_sales
                    (receipt_code, sale_date, customer_name, customer_phone, customer_email, channel, subtotal, discount_amount, contribution_amount, total_amount, payment_method, payment_status, transaction_id, notes, created_by)
                    VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, 0, ?, ?, 'paid', ?, ?, ?)");
                $stmt->execute([
                    $receiptCode,
                    $customerName ?: null,
                    $customerPhone ?: null,
                    $customerEmail ?: null,
                    $channel,
                    $subtotal,
                    $discountAmount,
                    $totalAmount,
                    $paymentMethod,
                    $transactionId,
                    $notes ?: null,
                    cashier_user_id()
                ]);
                $saleId = (int)$pdo->lastInsertId();

                $insertItem = $pdo->prepare("INSERT INTO product_cashier_sale_items
                    (sale_id, product_id, product_code, barcode_code, product_name, qty, unit_price, hpp_price, gross_amount, discount_amount, contribution_percent, contribution_amount, net_amount, profit_amount)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $updateStock = $pdo->prepare("UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ?");
                $insertSale = $pdo->prepare("INSERT INTO product_sales
                    (sale_code, product_id, sale_date, channel, customer_name, qty, unit_price, gross_amount, marketplace_fee, shipping_cost, contribution_percent, contribution_amount, contribution_program_name, net_amount, hpp_total, profit_amount, payment_method, transaction_id, notes, created_by)
                    VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                foreach ($lines as $line) {
                    $p = $line['product'];
                    $share = $subtotal > 0 ? ($line['gross_amount'] / $subtotal) : 0;
                    $itemDiscount = round($discountAmount * $share, 2);
                    $net = $line['gross_amount'] - $itemDiscount;
                    $percent = (float)($p['contribution_percent'] ?? 0);
                    $contribution = round($net * $percent / 100, 2);
                    $profit = $net - ($line['hpp_price'] * $line['qty']);
                    $totalContribution += $contribution;

                    $insertItem->execute([
                        $saleId,
                        $p['id'],
                        $p['product_code'] ?? null,
                        $p['barcode_code'] ?? null,
                        $p['product_name'],
                        $line['qty'],
                        $line['unit_price'],
                        $line['hpp_price'],
                        $line['gross_amount'],
                        $itemDiscount,
                        $percent,
                        $contribution,
                        $net,
                        $profit
                    ]);

                    $updateStock->execute([$line['qty'], $p['id']]);

                    $insertSale->execute([
                        'SALE-' . date('YmdHis') . '-' . random_int(10, 99),
                        $p['id'],
                        cashier_product_sales_channel($channel),
                        $customerName ?: null,
                        $line['qty'],
                        $line['unit_price'],
                        $line['gross_amount'],
                        $percent,
                        $contribution,
                        $p['contribution_program_name'] ?? null,
                        $net,
                        $line['hpp_price'] * $line['qty'],
                        $profit,
                        $trxMethod,
                        $transactionId,
                        'Dari kasir: ' . $receiptCode,
                        cashier_user_id()
                    ]);
                }

                $stmt = $pdo->prepare("UPDATE product_cashier_sales SET contribution_amount = ? WHERE id = ?");
                $stmt->execute([$totalContribution, $saleId]);

                $pdo->commit();
                header('Location: cashier_receipt.php?type=sale&id=' . $saleId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }

    if ($action === 'checkout_custom') {
        $customerName = trim($_POST['co_customer_name'] ?? '');
        $customerPhone = trim($_POST['co_customer_phone'] ?? '');
        $customerEmail = trim($_POST['co_customer_email'] ?? '');
        $productName = trim($_POST['co_product_name'] ?? '');
        $description = trim($_POST['co_description'] ?? '');
        $dueDate = $_POST['co_due_date'] ?: null;
        $qty = max(1, cashier_parse_number($_POST['co_qty'] ?? 1));
        $unit = trim($_POST['co_unit'] ?? 'unit');
        $estimatedCost = max(0, cashier_parse_number($_POST['co_estimated_cost'] ?? 0));
        $offeredPrice = max(0, cashier_parse_number($_POST['co_offered_price'] ?? 0));
        $paymentMode = $_POST['co_payment_mode'] ?? 'dp';
        $paymentMethod = $_POST['co_payment_method'] ?? 'cash';
        $dpAmount = max(0, cashier_parse_number($_POST['co_down_payment'] ?? 0));
        $notes = trim($_POST['co_notes'] ?? '');

        if ($customerName === '') $errors[] = 'Nama customer custom order wajib diisi.';
        if ($productName === '') $errors[] = 'Nama produk custom wajib diisi.';
        if ($offeredPrice <= 0) $errors[] = 'Harga penawaran wajib lebih dari 0.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($paymentMode === 'full') {
                    $downPayment = 0;
                    $finalPayment = $offeredPrice;
                    $paidAmount = $offeredPrice;
                    $status = 'approved';
                } elseif ($paymentMode === 'none') {
                    $downPayment = 0;
                    $finalPayment = 0;
                    $paidAmount = 0;
                    $status = 'inquiry';
                } else {
                    $downPayment = min($dpAmount, $offeredPrice);
                    $finalPayment = 0;
                    $paidAmount = $downPayment;
                    $status = $downPayment > 0 ? 'approved' : 'inquiry';
                }

                $profitEstimate = $offeredPrice - $estimatedCost;
                $orderCode = 'CUS-' . date('YmdHis') . '-' . random_int(10, 99);
                $transactionId = null;

                if ($paidAmount > 0) {
                    $categoryId = cashier_category_id($pdo, 'DP Custom Order');
                    $transactionCode = 'TRX-' . date('YmdHis') . '-' . random_int(10, 99);
                    $trxMethod = cashier_payment_method_for_transactions($paymentMethod);
                    $stmt = $pdo->prepare("INSERT INTO transactions
                        (transaction_code, transaction_date, type, category_id, amount, description, payment_method, created_by)
                        VALUES (?, CURDATE(), 'income', ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $transactionCode,
                        $categoryId,
                        $paidAmount,
                        'Pembayaran custom order - ' . $orderCode,
                        $trxMethod,
                        cashier_user_id()
                    ]);
                    $transactionId = (int)$pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("INSERT INTO product_custom_orders
                    (order_code, customer_name, customer_phone, product_name, description, order_date, due_date, qty, unit, estimated_cost, offered_price, down_payment, final_payment, profit_estimate, status, transaction_id, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $orderCode,
                    $customerName,
                    $customerPhone ?: null,
                    $productName,
                    $description ?: null,
                    $dueDate,
                    $qty,
                    $unit ?: 'unit',
                    $estimatedCost,
                    $offeredPrice,
                    $downPayment,
                    $finalPayment,
                    $profitEstimate,
                    $status,
                    $transactionId,
                    $notes ?: null,
                    cashier_user_id()
                ]);
                $orderId = (int)$pdo->lastInsertId();

                $pdo->commit();
                header('Location: cashier_receipt.php?type=custom&id=' . $orderId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

$products = $pdo->query("SELECT id, product_code, barcode_code, product_name, category, product_type, hpp_price, selling_price, stock, minimum_stock, unit, photo, contribution_percent, contribution_program_name, contribution_event_name, contribution_event_date, status FROM products WHERE status='active' ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$recentSales = $pdo->query("SELECT * FROM product_cashier_sales ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$recentCustom = $pdo->query("SELECT * FROM product_custom_orders ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$productJson = [];
foreach ($products as $p) {
    $productJson[] = [
        'id' => (int)$p['id'],
        'code' => $p['product_code'],
        'barcode' => $p['barcode_code'],
        'name' => $p['product_name'],
        'category' => $p['category'],
        'type' => $p['product_type'],
        'hpp' => (float)$p['hpp_price'],
        'price' => (float)$p['selling_price'],
        'stock' => (float)$p['stock'],
        'unit' => $p['unit'] ?: 'pcs',
        'photo' => cashier_product_photo_url($p['photo'] ?? ''),
        'contribution_percent' => (float)$p['contribution_percent'],
        'contribution_program_name' => $p['contribution_program_name'],
        'contribution_event_name' => $p['contribution_event_name'],
        'contribution_event_date' => $p['contribution_event_date'],
    ];
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>

<style>
.pos-shell{padding:18px 0 40px;}
.pos-hero{border-radius:24px;background:linear-gradient(135deg,#075f42,#16a34a 65%,#facc15);padding:26px 30px;color:#fff;position:relative;overflow:hidden;margin-bottom:18px;}
.pos-hero:after{content:"";position:absolute;right:-60px;top:-70px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.17)}
.pos-hero h1{font-weight:800;margin:0;font-size:34px;}
.pos-hero p{margin:8px 0 0;opacity:.95}
.pos-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:18px;}
.pos-card{background:#fff;border:1px solid #dfe7f1;border-radius:22px;box-shadow:0 16px 36px rgba(15,23,42,.06);overflow:hidden;}
.pos-card-header{padding:18px 20px;border-bottom:1px solid #e8eef5;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.pos-card-body{padding:20px;}
.pos-input{border:1px solid #d8e2ef;border-radius:14px;padding:12px 14px;width:100%;}
.pos-search-row{display:grid;grid-template-columns:1fr auto;gap:10px;}
.pos-product-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;max-height:300px;overflow:auto;padding-right:4px;}
.pos-product{border:1px solid #e4ebf4;border-radius:16px;padding:12px;display:flex;gap:12px;align-items:center;background:#fbfdff;cursor:pointer;transition:.15s;}
.pos-product:hover{border-color:#16a34a;box-shadow:0 8px 20px rgba(22,163,74,.1);}
.pos-product-img{width:50px;height:50px;border-radius:14px;background:#eafaf2;display:flex;align-items:center;justify-content:center;font-weight:800;color:#12854d;flex:none;overflow:hidden;}
.pos-product-img img{width:100%;height:100%;object-fit:cover;}
.pos-product-name{font-weight:800;line-height:1.2;}
.pos-muted{font-size:12px;color:#64748b;}
.pos-table-wrap{padding:0 16px 16px;}
.pos-table{width:100%;border-collapse:separate;border-spacing:0;}
.pos-table th{background:#f3f6fa;color:#64748b;text-transform:uppercase;font-size:11px;letter-spacing:.04em;padding:11px 10px;}
.pos-table td{border-bottom:1px solid #edf2f7;padding:12px 10px;vertical-align:middle;}
.pos-table th:first-child{border-top-left-radius:12px;}
.pos-table th:last-child{border-top-right-radius:12px;}
.pos-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
.pos-summary .box{border:1px solid #e4ebf4;background:#f8fafc;border-radius:16px;padding:12px;}
.pos-summary .box strong{font-size:18px;}
.pos-summary .box.green{background:#ecfdf5;border-color:#bbf7d0;}
.pos-summary .box.yellow{background:#fffbeb;border-color:#fde68a;}
.pos-tabs{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.pos-tab{border:1px solid #d8e2ef;background:#fff;border-radius:999px;padding:10px 14px;font-weight:700;color:#334155;}
.pos-tab.active{background:#12854d;color:#fff;border-color:#12854d;}
.cart-empty{padding:28px;text-align:center;color:#64748b;border:1px dashed #cbd5e1;border-radius:18px;}
.qty-input,.money-input{min-width:92px;}
.receipt-link{white-space:nowrap;}
@media(max-width:1100px){.pos-grid{grid-template-columns:1fr}.pos-product-list{grid-template-columns:1fr}}
@media(max-width:768px){.pos-summary{grid-template-columns:1fr}.pos-hero h1{font-size:26px}.pos-search-row{grid-template-columns:1fr}}
@media print{.sidebar,.topbar,.product-nav,.pos-hero,.no-print{display:none!important}.main-content{margin:0!important}.pos-card{box-shadow:none;border:1px solid #ddd}}

.pos-scanner-modal{position:fixed;inset:0;background:rgba(15,23,42,.72);z-index:99999;display:none;align-items:center;justify-content:center;padding:18px;}
.pos-scanner-box{width:min(560px,100%);background:#fff;border-radius:24px;box-shadow:0 24px 80px rgba(2,6,23,.35);overflow:hidden;}
.pos-scanner-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #e8eef6;}
.pos-scanner-body{padding:18px;}
#barcodeReader{width:100%;min-height:300px;background:#020617;border-radius:18px;overflow:hidden;}
.pos-scan-status{font-size:13px;color:#64748b;margin-top:10px;line-height:1.4;}
.pos-scanner-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
.pos-scanner-tip{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:12px;font-size:13px;color:#475569;margin-top:12px;}
@media(max-width:640px){.pos-scanner-modal{align-items:flex-start;padding-top:24px}.pos-scanner-box{border-radius:20px}#barcodeReader{min-height:260px}}
</style>

<main class="main-content">
<div class="container-fluid pos-shell">

    <?php require_once __DIR__ . '/_nav.php'; ?>

    <section class="pos-hero">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <div class="small text-uppercase fw-bold mb-2">Kasir / Point of Sale</div>
                <h1>Kasir Produk Nukari</h1>
                <p>Scan barcode, tambah produk ke keranjang, buat custom order DP/lunas, dan cetak slip pembelian.</p>
            </div>
            <div class="d-flex gap-2 no-print">
                <button class="btn btn-light rounded-pill fw-bold" onclick="openBarcodeScanner()">Scan Barcode</button>
                <button class="btn btn-warning rounded-pill fw-bold" onclick="switchMode('custom')">Custom Order</button>
            </div>
        </div>
    </section>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><div><?= cashier_safe($e); ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="pos-tabs no-print">
        <button type="button" class="pos-tab active" id="tab-sale" onclick="switchMode('sale')">Penjualan Produk</button>
        <button type="button" class="pos-tab" id="tab-custom" onclick="switchMode('custom')">Custom Order DP / Lunas</button>
    </div>

    <div class="pos-scanner-modal no-print" id="barcodeScannerModal" aria-hidden="true">
        <div class="pos-scanner-box">
            <div class="pos-scanner-header">
                <div>
                    <strong>Scan Barcode Produk</strong>
                    <div class="pos-muted">Arahkan kamera ke barcode/QR produk Nukari.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="closeBarcodeScanner()">Tutup</button>
            </div>
            <div class="pos-scanner-body">
                <div id="barcodeReader"></div>
                <div class="pos-scan-status" id="scannerStatus">
                    Scanner siap. Pastikan halaman dibuka melalui HTTPS Cloudflare agar kamera HP bisa diakses.
                </div>
                <div class="pos-scanner-actions">
                    <button type="button" class="btn btn-success rounded-pill" onclick="startBarcodeScanner()">Mulai Kamera</button>
                    <button type="button" class="btn btn-outline-success rounded-pill" onclick="switchBarcodeCamera()">Ganti Kamera</button>
                    <button type="button" class="btn btn-outline-danger rounded-pill" onclick="stopBarcodeScanner()">Stop</button>
                    <label class="btn btn-outline-secondary rounded-pill mb-0">
                        Scan dari Foto
                        <input type="file" id="barcodeImageInput" accept="image/*" hidden>
                    </label>
                </div>
                <div class="pos-scanner-tip">
                    Jika kamera tidak muncul, pastikan browser memberi izin kamera dan URL menggunakan <strong>https://</strong>.
                    Barcode/QR yang terbaca akan otomatis masuk ke keranjang. Jika CODE128 sulit terbaca, gunakan QR pada label baru.
                </div>
            </div>
        </div>
    </div>

    <section id="mode-sale">
        <div class="pos-grid">
            <div class="pos-card">
                <div class="pos-card-header">
                    <strong>Scan / Cari Produk</strong>
                    <span class="badge bg-success"><?= count($products); ?> produk aktif</span>
                </div>
                <div class="pos-card-body">
                    <div class="pos-search-row mb-3">
                        <input type="text" id="barcodeInput" class="pos-input" placeholder="Scan barcode / ketik kode produk lalu Enter..." autofocus>
                        <button class="btn btn-success rounded-pill px-4" onclick="addByBarcode()">Tambah</button>
                    </div>
                    <input type="text" id="productSearch" class="pos-input mb-3" placeholder="Cari produk berdasarkan nama, kode, barcode, kategori...">
                    <div class="pos-product-list" id="productList"></div>
                </div>
            </div>

            <div class="pos-card">
                <div class="pos-card-header">
                    <strong>Keranjang Belanja</strong>
                    <button class="btn btn-sm btn-outline-danger rounded-pill no-print" type="button" onclick="clearCart()">Kosongkan</button>
                </div>
                <form method="post" id="checkoutForm">
                    <input type="hidden" name="action" value="checkout_sale">
                    <input type="hidden" name="cart_json" id="cartJson">
                    <div class="pos-table-wrap pt-3">
                        <div id="cartArea"></div>
                    </div>
                    <div class="pos-card-body border-top">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Nama Customer</label>
                                <input class="pos-input" name="customer_name" placeholder="Opsional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">No. WhatsApp</label>
                                <input class="pos-input" name="customer_phone" placeholder="Opsional">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Email</label>
                                <input class="pos-input" name="customer_email" placeholder="Opsional untuk broadcast/email">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Channel</label>
                                <select class="pos-input" name="channel" id="saleChannel">
                                    <option value="cashier">Kasir</option>
                                    <option value="bazar">Bazar</option>
                                    <option value="offline">Offline</option>
                                    <option value="tokopedia">Tokopedia</option>
                                    <option value="shopee">Shopee</option>
                                    <option value="other">Lainnya</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Metode Bayar</label>
                                <select class="pos-input" name="payment_method">
                                    <option value="cash">Cash</option>
                                    <option value="qris">QRIS</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="other">Lainnya</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Diskon Transaksi</label>
                                <input class="pos-input money-format" name="discount_amount" id="discountAmount" value="0">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Catatan Slip</label>
                                <textarea class="pos-input" name="notes" rows="2" placeholder="Contoh: Bazar Pantai Serangan"></textarea>
                            </div>
                        </div>

                        <div class="pos-summary mt-3">
                            <div class="box"><div class="pos-muted">Subtotal</div><strong id="sumSubtotal">Rp 0</strong></div>
                            <div class="box"><div class="pos-muted">Diskon</div><strong id="sumDiscount">Rp 0</strong></div>
                            <div class="box green"><div class="pos-muted">Total Bayar</div><strong id="sumTotal">Rp 0</strong></div>
                            <div class="box yellow"><div class="pos-muted">Kontribusi Program</div><strong id="sumContribution">Rp 0</strong></div>
                            <div class="box"><div class="pos-muted">HPP</div><strong id="sumHpp">Rp 0</strong></div>
                            <div class="box"><div class="pos-muted">Estimasi Profit</div><strong id="sumProfit">Rp 0</strong></div>
                        </div>

                        <button class="btn btn-success btn-lg rounded-pill mt-3 w-100 fw-bold" type="submit">Bayar & Cetak Slip</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section id="mode-custom" style="display:none">
        <div class="pos-grid">
            <div class="pos-card">
                <div class="pos-card-header"><strong>Input Custom Order</strong><span class="badge bg-warning text-dark">DP / Lunas</span></div>
                <form method="post" id="customForm">
                    <input type="hidden" name="action" value="checkout_custom">
                    <div class="pos-card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label fw-bold">Customer *</label><input class="pos-input" name="co_customer_name" required></div>
                            <div class="col-md-6"><label class="form-label fw-bold">No. WhatsApp</label><input class="pos-input" name="co_customer_phone"></div>
                            <div class="col-md-12"><label class="form-label fw-bold">Email</label><input class="pos-input" name="co_customer_email"></div>
                            <div class="col-md-12"><label class="form-label fw-bold">Nama Produk Custom *</label><input class="pos-input" name="co_product_name" required placeholder="Contoh: Bangku Recycle Custom"></div>
                            <div class="col-md-6"><label class="form-label fw-bold">Deadline</label><input type="date" class="pos-input" name="co_due_date"></div>
                            <div class="col-md-3"><label class="form-label fw-bold">Qty</label><input class="pos-input" name="co_qty" value="1"></div>
                            <div class="col-md-3"><label class="form-label fw-bold">Satuan</label><input class="pos-input" name="co_unit" value="unit"></div>
                            <div class="col-md-6"><label class="form-label fw-bold">Estimasi Cost</label><input class="pos-input money-format" id="coCost" name="co_estimated_cost" value="0"></div>
                            <div class="col-md-6"><label class="form-label fw-bold">Harga Penawaran *</label><input class="pos-input money-format" id="coPrice" name="co_offered_price" value="0" required></div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Jenis Pembayaran</label>
                                <select class="pos-input" name="co_payment_mode" id="coPaymentMode" onchange="updateCustomSummary()">
                                    <option value="dp">DP Terlebih Dahulu</option>
                                    <option value="full">Lunas di Depan</option>
                                    <option value="none">Belum Ada Pembayaran</option>
                                </select>
                            </div>
                            <div class="col-md-6"><label class="form-label fw-bold">Metode Bayar</label><select class="pos-input" name="co_payment_method"><option value="cash">Cash</option><option value="qris">QRIS</option><option value="bank_transfer">Bank Transfer</option><option value="other">Lainnya</option></select></div>
                            <div class="col-md-12" id="dpBox"><label class="form-label fw-bold">Nominal DP</label><input class="pos-input money-format" id="coDp" name="co_down_payment" value="0"></div>
                            <div class="col-md-12"><label class="form-label fw-bold">Deskripsi Pesanan</label><textarea class="pos-input" name="co_description" rows="3"></textarea></div>
                            <div class="col-md-12"><label class="form-label fw-bold">Catatan Slip</label><textarea class="pos-input" name="co_notes" rows="2"></textarea></div>
                        </div>
                        <div class="pos-summary mt-3">
                            <div class="box"><div class="pos-muted">Estimasi Profit</div><strong id="coProfit">Rp 0</strong></div>
                            <div class="box green"><div class="pos-muted">Dibayar Hari Ini</div><strong id="coPaid">Rp 0</strong></div>
                            <div class="box yellow"><div class="pos-muted">Sisa Tagihan</div><strong id="coRemaining">Rp 0</strong></div>
                            <div class="box"><div class="pos-muted">Status Awal</div><strong id="coStatus">Inquiry</strong></div>
                        </div>
                        <button class="btn btn-success btn-lg rounded-pill mt-3 w-100 fw-bold" type="submit">Simpan Custom Order & Cetak Slip</button>
                    </div>
                </form>
            </div>

            <div class="pos-card">
                <div class="pos-card-header"><strong>Custom Order Terbaru</strong><a class="btn btn-sm btn-outline-success rounded-pill" href="custom_orders.php">Lihat Semua</a></div>
                <div class="pos-table-wrap pt-3">
                    <table class="pos-table">
                        <thead><tr><th>Kode</th><th>Customer</th><th>Produk</th><th>Tagihan</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentCustom as $r): ?>
                            <?php $paid = (float)$r['down_payment'] + (float)$r['final_payment']; $remaining = (float)$r['offered_price'] - $paid; ?>
                            <tr>
                                <td><?= cashier_safe($r['order_code']); ?></td>
                                <td><?= cashier_safe($r['customer_name']); ?><div class="pos-muted"><?= cashier_safe($r['customer_phone']); ?></div></td>
                                <td><?= cashier_safe($r['product_name']); ?></td>
                                <td><?= cashier_money($remaining); ?><div class="pos-muted">Sisa</div></td>
                                <td><?= function_exists('product_badge') ? product_badge($r['status']) : cashier_safe($r['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$recentCustom): ?><tr><td colspan="5" class="text-center text-muted py-4">Belum ada custom order.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <div class="pos-card mt-3">
        <div class="pos-card-header"><strong>Riwayat Kasir Terbaru</strong><span class="pos-muted">10 transaksi terakhir</span></div>
        <div class="pos-table-wrap pt-3">
            <table class="pos-table">
                <thead><tr><th>Struk</th><th>Tanggal</th><th>Customer</th><th>Channel</th><th>Total</th><th>Kontribusi</th><th>Bayar</th><th>Slip</th></tr></thead>
                <tbody>
                <?php foreach ($recentSales as $s): ?>
                    <tr>
                        <td><strong><?= cashier_safe($s['receipt_code']); ?></strong></td>
                        <td><?= cashier_safe($s['sale_date']); ?></td>
                        <td><?= cashier_safe($s['customer_name'] ?: '-'); ?></td>
                        <td><?= function_exists('product_badge') ? product_badge($s['channel']) : cashier_safe($s['channel']); ?></td>
                        <td><strong><?= cashier_money($s['total_amount']); ?></strong></td>
                        <td><?= cashier_money($s['contribution_amount']); ?></td>
                        <td><?= function_exists('product_badge') ? product_badge($s['payment_method']) : cashier_safe($s['payment_method']); ?></td>
                        <td><a class="btn btn-sm btn-outline-success rounded-pill receipt-link" target="_blank" href="cashier_receipt.php?type=sale&id=<?= (int)$s['id']; ?>">Cetak</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentSales): ?><tr><td colspan="8" class="text-center text-muted py-4">Belum ada transaksi kasir.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</main>

<script src="<?= base_url('assets/vendor/html5-qrcode/html5-qrcode.min.js'); ?>"></script>
<script>
const PRODUCTS = <?= json_encode($productJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let cart = [];

function money(n){ return 'Rp ' + Math.round(Number(n || 0)).toLocaleString('id-ID'); }
function parseMoney(v){ return Number(String(v||'').replace(/[^0-9,-]/g,'').replace(',', '.')) || 0; }
function formatNumberInput(el){
    const raw = String(el.value || '').replace(/[^0-9]/g,'');
    el.value = raw ? Number(raw).toLocaleString('id-ID') : '0';
}
document.querySelectorAll('.money-format').forEach(el => {
    el.addEventListener('input', () => { formatNumberInput(el); updateCart(); updateCustomSummary(); });
});

function switchMode(mode){
    document.getElementById('mode-sale').style.display = mode === 'sale' ? '' : 'none';
    document.getElementById('mode-custom').style.display = mode === 'custom' ? '' : 'none';
    document.getElementById('tab-sale').classList.toggle('active', mode === 'sale');
    document.getElementById('tab-custom').classList.toggle('active', mode === 'custom');
}
function focusBarcode(){ switchMode('sale'); setTimeout(()=>document.getElementById('barcodeInput').focus(), 100); }

function productInitials(name){
    return String(name||'P').split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
}
function productImageHtml(p){
    if(p.photo){
        return `<img src="${escapeHtml(p.photo)}" alt="${escapeHtml(p.name)}" onerror="this.parentNode.textContent='${productInitials(p.name)}'">`;
    }
    return productInitials(p.name);
}

function renderProductList(){
    const q = document.getElementById('productSearch').value.toLowerCase();
    const wrap = document.getElementById('productList');
    const filtered = PRODUCTS.filter(p => {
        const blob = [p.name,p.code,p.barcode,p.category,p.type].join(' ').toLowerCase();
        return blob.includes(q);
    }).slice(0, 40);
    wrap.innerHTML = filtered.map(p => `
        <div class="pos-product" onclick="addToCart(${p.id})">
            <div class="pos-product-img">${productImageHtml(p)}</div>
            <div class="flex-grow-1">
                <div class="pos-product-name">${escapeHtml(p.name)}</div>
                <div class="pos-muted">${escapeHtml(p.barcode || p.code || '-')} · Stok ${formatQty(p.stock)} ${escapeHtml(p.unit)}</div>
                <div class="fw-bold">${money(p.price)}</div>
            </div>
        </div>
    `).join('') || '<div class="cart-empty">Produk tidak ditemukan.</div>';
}
function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }
function formatQty(n){ n=Number(n||0); return Number.isInteger(n)? n.toLocaleString('id-ID') : n.toLocaleString('id-ID', {maximumFractionDigits:3}); }

function normalizeBarcode(value){
    let v = String(value || '').trim();
    if(!v) return '';

    // Jika QR berisi URL, ambil parameter umum atau potongan kode produk.
    try{
        if(/^https?:\/\//i.test(v)){
            const u = new URL(v);
            v = u.searchParams.get('barcode')
                || u.searchParams.get('code')
                || u.searchParams.get('product_code')
                || u.searchParams.get('q')
                || u.pathname.split('/').filter(Boolean).pop()
                || v;
        }
    }catch(e){}

    v = String(v || '').trim();

    // Bersihkan karakter yang sering ikut terbaca oleh scanner.
    v = v.replace(/\s+/g, '');
    v = v.replace(/[^a-zA-Z0-9\-_]/g, '');

    return v.toUpperCase();
}

function barcodeVariants(value){
    const raw = normalizeBarcode(value);
    if(!raw) return [];
    const noDash = raw.replace(/[-_]/g, '');
    const withDashAsSpace = raw.replace(/[-_]/g, ' ');
    return Array.from(new Set([raw, noDash, withDashAsSpace.toUpperCase()]));
}

function findProductByBarcode(inputCode){
    const inputVariants = barcodeVariants(inputCode);
    if(inputVariants.length < 1) return null;

    return PRODUCTS.find(p => {
        const productCodes = [
            p.barcode,
            p.code,
            p.id,
            String(p.name || '')
        ];

        const productVariants = productCodes.flatMap(barcodeVariants);
        return inputVariants.some(v => productVariants.includes(v));
    }) || null;
}

function addByBarcode(){
    const originalCode = document.getElementById('barcodeInput').value.trim();
    if(!originalCode) return false;

    const p = findProductByBarcode(originalCode);
    if(!p){
        alert('Produk tidak ditemukan untuk kode: ' + originalCode + '\\n\\nTips: coba scan QR pada label atau ketik kode produk/barcode secara manual.');
        return false;
    }

    addToCart(p.id);
    document.getElementById('barcodeInput').value = '';
    return true;
}
document.getElementById('barcodeInput').addEventListener('keydown', e => { if(e.key === 'Enter'){ e.preventDefault(); addByBarcode(); }});
document.getElementById('productSearch').addEventListener('input', renderProductList);

function addToCart(id){
    const p = PRODUCTS.find(p => p.id === id);
    if(!p) return;
    const item = cart.find(i => i.id === id);
    if(item){
        if(item.qty + 1 > p.stock){ alert('Stok tidak cukup.'); return; }
        item.qty += 1;
    } else {
        if(p.stock <= 0){ alert('Stok produk habis.'); return; }
        cart.push({id:p.id, qty:1, unit_price:p.price});
    }
    updateCart();
}
function removeItem(id){ cart = cart.filter(i => i.id !== id); updateCart(); }
function clearCart(){ if(confirm('Kosongkan keranjang?')){ cart=[]; updateCart(); } }
function changeQty(id, value){
    const item = cart.find(i=>i.id===id); const p = PRODUCTS.find(p=>p.id===id);
    if(!item||!p) return;
    let qty = Number(value)||0;
    if(qty < 0) qty = 0;
    if(qty > p.stock){ alert('Qty melebihi stok.'); qty = p.stock; }
    item.qty = qty;
    updateCart();
}
function changePrice(id, value){
    const item = cart.find(i=>i.id===id);
    if(!item) return;
    item.unit_price = parseMoney(value);
    updateCart();
}

function updateCart(){
    const area = document.getElementById('cartArea');
    if(cart.length < 1){
        area.innerHTML = '<div class="cart-empty">Keranjang kosong. Scan barcode atau pilih produk.</div>';
    } else {
        area.innerHTML = `<table class="pos-table"><thead><tr><th>Produk</th><th>Qty</th><th>Harga</th><th>Total</th><th></th></tr></thead><tbody>` + cart.map(i => {
            const p = PRODUCTS.find(p=>p.id===i.id);
            if(!p) return '';
            const total = Number(i.qty) * Number(i.unit_price);
            return `<tr>
                <td><strong>${escapeHtml(p.name)}</strong><div class="pos-muted">Stok ${formatQty(p.stock)} ${escapeHtml(p.unit)} · HPP ${money(p.hpp)}</div></td>
                <td><input class="pos-input qty-input" value="${i.qty}" type="number" min="0" step="0.001" onchange="changeQty(${i.id}, this.value)" oninput="changeQty(${i.id}, this.value)"></td>
                <td><input class="pos-input money-input" value="${Math.round(i.unit_price).toLocaleString('id-ID')}" oninput="changePrice(${i.id}, this.value)"></td>
                <td><strong>${money(total)}</strong></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger rounded-pill" onclick="removeItem(${i.id})">Hapus</button></td>
            </tr>`;
        }).join('') + '</tbody></table>';
    }

    let subtotal = 0, hpp = 0, contribution = 0;
    cart.forEach(i => {
        const p = PRODUCTS.find(p=>p.id===i.id);
        if(!p) return;
        const gross = Number(i.qty) * Number(i.unit_price);
        subtotal += gross;
        hpp += Number(i.qty) * Number(p.hpp || 0);
        contribution += gross * Number(p.contribution_percent || 0) / 100;
    });
    const discount = parseMoney(document.getElementById('discountAmount').value);
    const total = Math.max(0, subtotal - discount);
    const profit = total - hpp;
    document.getElementById('sumSubtotal').textContent = money(subtotal);
    document.getElementById('sumDiscount').textContent = money(discount);
    document.getElementById('sumTotal').textContent = money(total);
    document.getElementById('sumContribution').textContent = money(contribution);
    document.getElementById('sumHpp').textContent = money(hpp);
    document.getElementById('sumProfit').textContent = money(profit);
    document.getElementById('cartJson').value = JSON.stringify(cart);
}
document.getElementById('checkoutForm').addEventListener('submit', e => {
    if(cart.length < 1){ e.preventDefault(); alert('Keranjang masih kosong.'); }
});


let barcodeScanner = null;
let barcodeScannerRunning = false;
let barcodeCameraIndex = 0;
let barcodeCameras = [];

function setScannerStatus(message, isError=false){
    const el = document.getElementById('scannerStatus');
    if(!el) return;
    el.textContent = message;
    el.style.color = isError ? '#dc2626' : '#64748b';
}

let lastScannedCode = '';
let lastScannedAt = 0;

function handleScannedBarcode(decodedText){
    const code = String(decodedText || '').trim();
    if(!code) return;

    // Hindari pembacaan berulang dalam waktu sangat dekat.
    const now = Date.now();
    const normalized = normalizeBarcode(code);
    if(normalized === lastScannedCode && (now - lastScannedAt) < 1500) return;
    lastScannedCode = normalized;
    lastScannedAt = now;

    document.getElementById('barcodeInput').value = code;

    const p = findProductByBarcode(code);
    if(!p){
        setScannerStatus('Kode terbaca, tetapi produk tidak ditemukan: ' + code + '. Coba scan QR atau ketik kode manual.', true);
        return;
    }

    addToCart(p.id);
    setScannerStatus('Produk berhasil masuk keranjang: ' + p.name + ' (' + (p.barcode || p.code || p.id) + ')');
    if(navigator.vibrate) navigator.vibrate(120);
    stopBarcodeScanner();
    closeBarcodeScanner();
}

async function openBarcodeScanner(){
    switchMode('sale');
    const modal = document.getElementById('barcodeScannerModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    setScannerStatus('Menyiapkan scanner kamera...');
    await startBarcodeScanner();
}

async function closeBarcodeScanner(){
    await stopBarcodeScanner();
    const modal = document.getElementById('barcodeScannerModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    focusBarcode();
}

async function startBarcodeScanner(){
    if(!window.Html5Qrcode){
        setScannerStatus('Library scanner belum termuat. Cek koneksi internet atau refresh halaman.', true);
        return;
    }

    if(location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1'){
        setScannerStatus('Kamera browser biasanya hanya aktif di HTTPS. Gunakan link Cloudflare HTTPS.', true);
        return;
    }

    try{
        if(!barcodeScanner){
            barcodeScanner = new Html5Qrcode('barcodeReader');
        }

        if(barcodeScannerRunning){
            setScannerStatus('Scanner sudah aktif.');
            return;
        }

        barcodeCameras = await Html5Qrcode.getCameras();
        let cameraConfig = { facingMode: 'environment' };

        if(barcodeCameras && barcodeCameras.length){
            const backIndex = barcodeCameras.findIndex(c => /back|rear|environment|belakang/i.test(c.label || ''));
            barcodeCameraIndex = backIndex >= 0 ? backIndex : Math.min(barcodeCameraIndex, barcodeCameras.length - 1);
            cameraConfig = barcodeCameras[barcodeCameraIndex].id;
        }

        const supportedFormats = [];
        if(window.Html5QrcodeSupportedFormats){
            [
                'QR_CODE',
                'CODE_128',
                'CODE_39',
                'EAN_13',
                'EAN_8',
                'UPC_A',
                'UPC_E'
            ].forEach(name => {
                if(Html5QrcodeSupportedFormats[name] !== undefined){
                    supportedFormats.push(Html5QrcodeSupportedFormats[name]);
                }
            });
        }

        const scanConfig = {
            fps: 15,
            qrbox: function(viewfinderWidth, viewfinderHeight){
                const width = Math.min(Math.floor(viewfinderWidth * 0.92), 420);
                const height = Math.min(Math.floor(viewfinderHeight * 0.45), 220);
                return { width, height };
            },
            aspectRatio: 1.777,
            rememberLastUsedCamera: true,
            experimentalFeatures: { useBarCodeDetectorIfSupported: true }
        };
        if(supportedFormats.length){
            scanConfig.formatsToSupport = supportedFormats;
        }

        await barcodeScanner.start(
            cameraConfig,
            scanConfig,
            handleScannedBarcode,
            () => {}
        );

        barcodeScannerRunning = true;
        setScannerStatus('Scanner aktif. Arahkan kamera ke barcode produk.');
    } catch(err){
        console.error(err);
        barcodeScannerRunning = false;
        setScannerStatus('Gagal membuka kamera. Pastikan izin kamera diizinkan dan gunakan HTTPS Cloudflare.', true);
    }
}

async function stopBarcodeScanner(){
    try{
        if(barcodeScanner && barcodeScannerRunning){
            await barcodeScanner.stop();
            barcodeScannerRunning = false;
            setScannerStatus('Scanner dihentikan.');
        }
    } catch(err){
        console.warn(err);
        barcodeScannerRunning = false;
    }
}

async function switchBarcodeCamera(){
    if(!barcodeCameras || barcodeCameras.length < 2){
        setScannerStatus('Tidak ada kamera lain yang tersedia.');
        return;
    }
    barcodeCameraIndex = (barcodeCameraIndex + 1) % barcodeCameras.length;
    await stopBarcodeScanner();
    await startBarcodeScanner();
}

document.getElementById('barcodeImageInput')?.addEventListener('change', async function(){
    const file = this.files && this.files[0];
    if(!file) return;
    if(!window.Html5Qrcode){
        setScannerStatus('Library scanner belum termuat. Tidak bisa scan dari foto.', true);
        return;
    }
    try{
        if(!barcodeScanner){
            barcodeScanner = new Html5Qrcode('barcodeReader');
        }
        await stopBarcodeScanner();
        const result = await barcodeScanner.scanFile(file, true);
        handleScannedBarcode(result);
    } catch(err){
        console.error(err);
        setScannerStatus('Barcode tidak terbaca dari foto. Coba foto lebih jelas dan tidak blur.', true);
    } finally {
        this.value = '';
    }
});

document.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
        const modal = document.getElementById('barcodeScannerModal');
        if(modal && modal.style.display === 'flex') closeBarcodeScanner();
    }
});


function updateCustomSummary(){
    const cost = parseMoney(document.getElementById('coCost').value);
    const price = parseMoney(document.getElementById('coPrice').value);
    const mode = document.getElementById('coPaymentMode').value;
    const dpBox = document.getElementById('dpBox');
    const dp = parseMoney(document.getElementById('coDp').value);
    let paid = 0, status = 'Inquiry';
    dpBox.style.display = mode === 'dp' ? '' : 'none';
    if(mode === 'full'){ paid = price; status = 'Approved / Lunas'; }
    else if(mode === 'none'){ paid = 0; status = 'Inquiry'; }
    else { paid = Math.min(dp, price); status = paid > 0 ? 'Approved / DP' : 'Inquiry'; }
    const remaining = Math.max(0, price - paid);
    document.getElementById('coProfit').textContent = money(price - cost);
    document.getElementById('coPaid').textContent = money(paid);
    document.getElementById('coRemaining').textContent = money(remaining);
    document.getElementById('coStatus').textContent = status;
}
document.getElementById('customForm').addEventListener('input', updateCustomSummary);
renderProductList(); updateCart(); updateCustomSummary();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
