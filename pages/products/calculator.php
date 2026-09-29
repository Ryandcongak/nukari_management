<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Location: simple.php?info=' . urlencode('Kalkulator HPP sudah digantikan oleh Harga Dasar pada Produk Sederhana.'));
exit;
require_once __DIR__ . '/product_helpers.php';

$pageTitle = 'Kalkulator HPP - Nukari';

function hpp_status_label(float $margin): array
{
    if ($margin < 0) return ['Rugi', 'danger'];
    if ($margin < 10) return ['Margin Tipis', 'warning'];
    if ($margin < 30) return ['Cukup', 'primary'];
    if ($margin < 50) return ['Untung', 'success'];
    return ['Sangat Untung', 'success'];
}

function hpp_markup(float $hpp, float $selling): float
{
    if ($hpp <= 0) return 0;
    return (($selling - $hpp) / $hpp) * 100;
}

function hpp_target_price(float $hpp, float $targetMargin): float
{
    if ($hpp <= 0) return 0;
    if ($targetMargin <= 0 || $targetMargin >= 100) return 0;
    return $hpp / (1 - ($targetMargin / 100));
}

function hpp_bep_units(float $totalCost, float $profitUnit): int
{
    if ($totalCost <= 0 || $profitUnit <= 0) return 0;
    return (int)ceil($totalCost / $profitUnit);
}

function hpp_query_history(PDO $pdo, string $search = '', string $type = '', int $limit = 30): array
{
    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(product_name LIKE ? OR calculation_code LIKE ? OR notes LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($type !== '') {
        $where[] = "product_type = ?";
        $params[] = $type;
    }

    $sql = "SELECT * FROM product_hpp_calculations";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY id DESC LIMIT " . max(1, min($limit, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hpp_load_calculation(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $stmt = $pdo->prepare("SELECT * FROM product_hpp_calculations WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hpp_try_load_spreadsheet_autoload(): void
{
    $candidates = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../vendor/autoload.php',
        __DIR__ . '/../../includes/vendor/autoload.php',
    ];

    foreach ($candidates as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
}

function hpp_export_csv(array $rows): void
{
    $filename = 'hpp-calculations-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kode', 'Produk', 'Tipe', 'Output', 'Satuan', 'Biaya Bahan', 'Tenaga Kerja', 'Overhead', 'Total Cost', 'HPP/Unit', 'Harga Jual', 'Profit/Unit', 'Profit Total', 'Margin %', 'Markup %', 'Status', 'Catatan', 'Tanggal']);
    foreach ($rows as $h) {
        $hpp = (float)$h['hpp_per_unit'];
        $selling = (float)$h['selling_price'];
        $profitUnit = (float)$h['profit_per_unit'];
        $profitTotal = $profitUnit * (float)$h['output_qty'];
        $margin = (float)$h['margin_percent'];
        $markup = hpp_markup($hpp, $selling);
        [$statusText] = hpp_status_label($margin);

        fputcsv($out, [
            $h['calculation_code'],
            $h['product_name'],
            $h['product_type'],
            $h['output_qty'],
            $h['output_unit'],
            $h['material_cost'],
            $h['labor_cost'],
            $h['overhead_cost'],
            $h['total_cost'],
            $h['hpp_per_unit'],
            $h['selling_price'],
            $h['profit_per_unit'],
            $profitTotal,
            $margin,
            $markup,
            $statusText,
            $h['notes'] ?? '',
            $h['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

function hpp_export_excel(array $rows): void
{
    hpp_try_load_spreadsheet_autoload();

    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        hpp_export_csv($rows);
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Kalkulator HPP');

    $sheet->mergeCells('A1:R1');
    $sheet->setCellValue('A1', 'Laporan Kalkulator HPP Produk - PT Nukari Kriya Raya');
    $sheet->mergeCells('A2:R2');
    $sheet->setCellValue('A2', 'Tanggal Export: ' . date('d/m/Y H:i'));

    $headers = ['Kode', 'Produk', 'Tipe', 'Output', 'Satuan', 'Biaya Bahan', 'Tenaga Kerja', 'Overhead', 'Total Cost', 'HPP/Unit', 'Harga Jual', 'Profit/Unit', 'Profit Total', 'Margin %', 'Markup %', 'Status', 'Catatan', 'Tanggal'];
    $sheet->fromArray($headers, null, 'A4');

    $rowNum = 5;
    foreach ($rows as $h) {
        $hpp = (float)$h['hpp_per_unit'];
        $selling = (float)$h['selling_price'];
        $profitUnit = (float)$h['profit_per_unit'];
        $profitTotal = $profitUnit * (float)$h['output_qty'];
        $margin = (float)$h['margin_percent'];
        $markup = hpp_markup($hpp, $selling);
        [$statusText] = hpp_status_label($margin);

        $sheet->fromArray([
            $h['calculation_code'],
            $h['product_name'],
            ucwords(str_replace('_', ' ', $h['product_type'] ?? '')),
            (float)$h['output_qty'],
            $h['output_unit'],
            (float)$h['material_cost'],
            (float)$h['labor_cost'],
            (float)$h['overhead_cost'],
            (float)$h['total_cost'],
            $hpp,
            $selling,
            $profitUnit,
            $profitTotal,
            $margin,
            $markup,
            $statusText,
            $h['notes'] ?? '',
            $h['created_at'] ?? '',
        ], null, 'A' . $rowNum);
        $rowNum++;
    }

    $lastRow = max(5, $rowNum - 1);

    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A2')->getFont()->setSize(10);
    $sheet->getStyle('A4:R4')->getFont()->setBold(true);
    $sheet->getStyle('A4:R4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAD3');
    $sheet->getStyle("A4:R{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    $sheet->getStyle("F5:M{$lastRow}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
    $sheet->getStyle("N5:O{$lastRow}")->getNumberFormat()->setFormatCode('0.00"%"');

    foreach (range('A', 'R') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4)->setRight(0.25)->setLeft(0.25)->setBottom(0.4);

    $filename = 'hpp-calculations-' . date('Ymd-His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

$filterSearch = trim($_GET['q'] ?? '');
$filterType = trim($_GET['type'] ?? '');

if (isset($_GET['export']) && $_GET['export'] === '1') {
    $exportRows = hpp_query_history($pdo, $filterSearch, $filterType, 500);
    hpp_export_excel($exportRows);
}

// Delete calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_calc'])) {
    $deleteId = (int)($_POST['calculation_id'] ?? 0);
    if ($deleteId > 0) {
        $stmt = $pdo->prepare("DELETE FROM product_hpp_calculations WHERE id = ?");
        $stmt->execute([$deleteId]);
    }
    header('Location: calculator.php');
    exit;
}

// Save / update calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_calc']) || isset($_POST['update_calc']))) {
    $materialCost = product_parse_number($_POST['material_cost'] ?? 0);
    $laborCost    = product_parse_number($_POST['labor_cost'] ?? 0);
    $overheadCost = product_parse_number($_POST['overhead_cost'] ?? 0);
    $total        = $materialCost + $laborCost + $overheadCost;
    $qty          = max(product_parse_number($_POST['output_qty'] ?? 1), 0.0001);
    $hpp          = $total / $qty;
    $sell         = product_parse_number($_POST['selling_price'] ?? 0);
    $profit       = $sell - $hpp;
    $margin       = $sell > 0 ? ($profit / $sell * 100) : 0;

    $data = [
        trim($_POST['product_name'] ?? ''),
        $_POST['product_type'] ?? 'recycle',
        $qty,
        trim($_POST['output_unit'] ?? 'pcs'),
        $materialCost,
        $laborCost,
        $overheadCost,
        $total,
        $hpp,
        $sell,
        $profit,
        $margin,
        trim($_POST['notes'] ?? ''),
    ];

    if (isset($_POST['update_calc'])) {
        $updateId = (int)($_POST['calculation_id'] ?? 0);
        if ($updateId > 0) {
            $stmt = $pdo->prepare("UPDATE product_hpp_calculations SET
                product_name = ?, product_type = ?, output_qty = ?, output_unit = ?,
                material_cost = ?, labor_cost = ?, overhead_cost = ?, total_cost = ?,
                hpp_per_unit = ?, selling_price = ?, profit_per_unit = ?, margin_percent = ?,
                notes = ?
                WHERE id = ?");
            $stmt->execute(array_merge($data, [$updateId]));
        }
    } else {
        $stmt = $pdo->prepare("INSERT INTO product_hpp_calculations
            (calculation_code, product_name, product_type, output_qty, output_unit, material_cost, labor_cost, overhead_cost, total_cost, hpp_per_unit, selling_price, profit_per_unit, margin_percent, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute(array_merge([product_code('HPP')], $data, [product_get_user_id()]));
    }

    header('Location: calculator.php');
    exit;
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editData = hpp_load_calculation($pdo, $editId);

$history = hpp_query_history($pdo, $filterSearch, $filterType, 30);

$productions = [];
try {
    $productions = $pdo->query("SELECT pp.*, p.product_name, p.selling_price
        FROM product_productions pp
        LEFT JOIN products p ON p.id = pp.product_id
        WHERE pp.status <> 'cancelled'
        ORDER BY pp.id DESC
        LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $productions = [];
}

$typeOptions = [
    'recycle' => 'Recycle',
    'eco_enzyme' => 'Eco Enzyme',
    'fertilizer' => 'Pupuk',
    'custom' => 'Custom',
    'service' => 'Jasa',
    'other' => 'Lainnya',
];

$formProductName = $editData['product_name'] ?? '';
$formProductType = $editData['product_type'] ?? 'recycle';
$formQty = isset($editData['output_qty']) ? product_num($editData['output_qty']) : '1';
$formUnit = $editData['output_unit'] ?? 'pcs';
$formMaterial = isset($editData['material_cost']) ? product_num($editData['material_cost'], 0) : '0';
$formLabor = isset($editData['labor_cost']) ? product_num($editData['labor_cost'], 0) : '0';
$formOverhead = isset($editData['overhead_cost']) ? product_num($editData['overhead_cost'], 0) : '0';
$formSell = isset($editData['selling_price']) ? product_num($editData['selling_price'], 0) : '0';
$formNotes = $editData['notes'] ?? '';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/_style.php';
?>
<style>
.hpp-layout { display:grid; grid-template-columns: minmax(390px, 0.9fr) minmax(650px, 1.6fr); gap:16px; align-items:start; }
.hpp-form-panel, .hpp-history-panel { min-width:0; }
.hpp-grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:10px; }
.hpp-box { border:1px solid #e5edf5; background:#f8fbff; border-radius:16px; padding:12px; min-height:74px; }
.hpp-box .label { font-size:10px; letter-spacing:.06em; text-transform:uppercase; color:#64748b; font-weight:800; margin-bottom:4px; }
.hpp-box .value { font-size:17px; font-weight:850; color:#0f172a; line-height:1.2; }
.hpp-box.highlight { background:#ecfdf5; border-color:#bbf7d0; }
.hpp-box.warning { background:#fff7ed; border-color:#fed7aa; }
.hpp-guide-list { margin:0; padding-left:18px; color:#475569; font-size:13px; line-height:1.65; }
.hpp-guide-card { border:1px solid #e5edf5; border-radius:18px; padding:16px; background:#ffffff; box-shadow:0 10px 28px rgba(15,23,42,.04); }
.hpp-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#f1f5f9; color:#334155; font-size:12px; font-weight:700; }
.hpp-filter { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.hpp-filter .form-control { min-width:230px; }
.hpp-table { table-layout:fixed; min-width:1180px; font-size:13px; }
.hpp-table th { white-space:nowrap; font-size:11px; letter-spacing:.05em; text-transform:uppercase; color:#64748b; background:#f8fafc; }
.hpp-table td { vertical-align:middle; padding-top:10px; padding-bottom:10px; }
.hpp-col-code { width:150px; }
.hpp-col-product { width:215px; }
.hpp-col-output { width:90px; text-align:right; }
.hpp-col-money { width:125px; text-align:right; white-space:nowrap; }
.hpp-col-percent { width:86px; text-align:right; white-space:nowrap; }
.hpp-col-status { width:115px; text-align:center; }
.hpp-col-action { width:165px; text-align:right; }
.hpp-small { font-size:12px; color:#64748b; }
.hpp-analysis-title { font-size:13px; font-weight:800; color:#0f172a; margin-bottom:8px; }
.hpp-recommend { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:8px; }
.hpp-recommend-card { border:1px solid #e2e8f0; border-radius:14px; padding:10px; background:#fff; }
.hpp-recommend-card span { display:block; font-size:11px; color:#64748b; font-weight:800; text-transform:uppercase; }
.hpp-recommend-card strong { display:block; font-size:15px; color:#0f172a; }
.hpp-detail-list { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.hpp-detail-item { border:1px solid #e5edf5; border-radius:14px; padding:10px 12px; background:#f8fafc; }
.hpp-detail-item span { display:block; font-size:11px; text-transform:uppercase; color:#64748b; font-weight:800; }
.hpp-detail-item strong { display:block; margin-top:3px; font-size:15px; color:#0f172a; }
@media (max-width: 1199px){ .hpp-layout { grid-template-columns:1fr; } .hpp-table { min-width:1120px; } }
@media (max-width: 575px){ .hpp-grid, .hpp-recommend, .hpp-detail-list { grid-template-columns:1fr; } }
</style>

<main class="main-content">
    <div class="container-fluid product-page py-3">
        <?php require_once __DIR__ . '/_nav.php'; ?>

        <div class="hpp-layout">
            <div class="hpp-form-panel">
                <div class="product-card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong><?= $editData ? 'Edit Kalkulator HPP' : 'Kalkulator HPP Produk'; ?></strong>
                        <span class="badge bg-success">Profit Analyzer</span>
                    </div>
                    <div class="card-body">
                        <?php if ($editData): ?>
                            <div class="alert alert-warning py-2">
                                Anda sedang mengedit kalkulasi <strong><?= product_safe($editData['calculation_code']); ?></strong>.
                                <a href="calculator.php" class="alert-link">Batal edit</a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($productions)): ?>
                            <div class="mb-3">
                                <label class="form-label">Ambil Data dari Produksi</label>
                                <select id="productionPicker" class="form-select">
                                    <option value="">Input manual / pilih produksi...</option>
                                    <?php foreach ($productions as $p): ?>
                                        <option value="<?= (int)$p['id']; ?>"
                                            data-product-name="<?= product_safe($p['product_name'] ?? ''); ?>"
                                            data-product-type="<?= product_safe($p['production_type'] ?? 'recycle'); ?>"
                                            data-output-qty="<?= (float)$p['output_qty']; ?>"
                                            data-output-unit="<?= product_safe($p['output_unit'] ?? 'pcs'); ?>"
                                            data-material-cost="<?= (float)$p['material_cost']; ?>"
                                            data-labor-cost="<?= (float)$p['labor_cost']; ?>"
                                            data-overhead-cost="<?= (float)$p['overhead_cost']; ?>"
                                            data-selling-price="<?= (float)($p['selling_price'] ?? 0); ?>">
                                            <?= product_safe(($p['production_code'] ?? '-') . ' - ' . ($p['product_name'] ?? '-') . ' - ' . product_num($p['output_qty']) . ' ' . ($p['output_unit'] ?? 'pcs')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Opsional. Pilih produksi agar biaya dan output terisi otomatis.</div>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="row g-2" id="calcForm" autocomplete="off">
                            <?php if ($editData): ?>
                                <input type="hidden" name="calculation_id" value="<?= (int)$editData['id']; ?>">
                            <?php endif; ?>

                            <div class="col-12">
                                <label class="form-label">Nama Produk/Estimasi</label>
                                <input name="product_name" id="productName" class="form-control" placeholder="Contoh: Eco Enzyme 1 Liter" value="<?= product_safe($formProductName); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Tipe</label>
                                <select name="product_type" id="productType" class="form-select">
                                    <?php foreach ($typeOptions as $value => $label): ?>
                                        <option value="<?= product_safe($value); ?>" <?= $formProductType === $value ? 'selected' : ''; ?>><?= product_safe($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-3 col-6">
                                <label class="form-label">Output</label>
                                <input name="output_qty" id="qty" class="form-control qty-input" value="<?= product_safe($formQty); ?>">
                            </div>

                            <div class="col-md-3 col-6">
                                <label class="form-label">Satuan</label>
                                <input name="output_unit" id="outputUnit" class="form-control" value="<?= product_safe($formUnit); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Biaya Bahan</label>
                                <input name="material_cost" id="mat" class="form-control calc money-input" value="<?= product_safe($formMaterial); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Tenaga Kerja</label>
                                <input name="labor_cost" id="lab" class="form-control calc money-input" value="<?= product_safe($formLabor); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Overhead</label>
                                <input name="overhead_cost" id="ovh" class="form-control calc money-input" value="<?= product_safe($formOverhead); ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Harga Jual / Unit</label>
                                <input name="selling_price" id="sell" class="form-control calc money-input" value="<?= product_safe($formSell); ?>">
                            </div>

                            <div class="col-12">
                                <div class="hpp-analysis-title">Ringkasan Perhitungan</div>
                                <div class="hpp-grid">
                                    <div class="hpp-box"><div class="label">Biaya Bahan</div><div class="value" id="sumMat">Rp 0</div></div>
                                    <div class="hpp-box"><div class="label">Tenaga Kerja</div><div class="value" id="sumLab">Rp 0</div></div>
                                    <div class="hpp-box"><div class="label">Overhead</div><div class="value" id="sumOvh">Rp 0</div></div>
                                    <div class="hpp-box highlight"><div class="label">Total Biaya</div><div class="value" id="total">Rp 0</div></div>
                                    <div class="hpp-box highlight"><div class="label">HPP / Unit</div><div class="value" id="hpp">Rp 0</div></div>
                                    <div class="hpp-box"><div class="label">Profit / Unit</div><div class="value" id="profit">Rp 0</div></div>
                                    <div class="hpp-box"><div class="label">Profit Total</div><div class="value" id="profitTotal">Rp 0</div></div>
                                    <div class="hpp-box"><div class="label">Margin / Markup</div><div class="value"><span id="margin">0%</span> / <span id="markup">0%</span></div></div>
                                    <div class="hpp-box warning"><div class="label">BEP Estimasi</div><div class="value" id="bep">-</div></div>
                                    <div class="hpp-box"><div class="label">Status Analisa</div><div class="value"><span id="statusBadge" class="badge bg-secondary">Belum dihitung</span></div></div>
                                </div>
                            </div>

                            <div class="col-12 mt-2">
                                <div class="hpp-analysis-title">Rekomendasi Harga Jual</div>
                                <div class="hpp-recommend">
                                    <div class="hpp-recommend-card"><span>Margin 30%</span><strong id="price30">Rp 0</strong></div>
                                    <div class="hpp-recommend-card"><span>Margin 40%</span><strong id="price40">Rp 0</strong></div>
                                    <div class="hpp-recommend-card"><span>Margin 50%</span><strong id="price50">Rp 0</strong></div>
                                </div>
                            </div>

                            <div class="col-12 mt-2">
                                <textarea name="notes" class="form-control" placeholder="Catatan"><?= product_safe($formNotes); ?></textarea>
                            </div>

                            <div class="col-12 d-flex gap-2 flex-wrap">
                                <?php if ($editData): ?>
                                    <button name="update_calc" value="1" class="btn btn-success btn-rounded">Update Perhitungan</button>
                                    <a href="calculator.php" class="btn btn-outline-secondary btn-rounded">Batal</a>
                                <?php else: ?>
                                    <button name="save_calc" value="1" class="btn btn-success btn-rounded">Simpan Perhitungan</button>
                                    <button type="reset" class="btn btn-outline-secondary btn-rounded" id="resetCalc">Reset</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="hpp-guide-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <strong>Panduan Penggunaan</strong>
                        <span class="hpp-pill">HPP</span>
                    </div>
                    <ol class="hpp-guide-list">
                        <li>Isi biaya bahan, tenaga kerja, dan overhead untuk satu batch produksi.</li>
                        <li>Output adalah jumlah produk yang dihasilkan dari batch tersebut.</li>
                        <li>HPP/unit = total biaya produksi dibagi output.</li>
                        <li>Margin = persentase laba dari harga jual.</li>
                        <li>Markup = persentase kenaikan dari HPP.</li>
                        <li>BEP menunjukkan estimasi minimal unit yang harus terjual untuk menutup modal batch.</li>
                        <li>Gunakan rekomendasi harga jual untuk menentukan harga minimal dengan target margin 30%, 40%, atau 50%.</li>
                    </ol>
                    <hr>
                    <div class="hpp-small">
                        Contoh: total biaya Rp 255.000, output 20 pcs, HPP Rp 12.750/pcs.
                        Jika harga jual Rp 50.000, profit per unit Rp 37.250 dan profit total Rp 745.000.
                    </div>
                </div>
            </div>

            <div class="hpp-history-panel">
                <div class="product-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <strong>Riwayat Kalkulasi</strong>
                            <div class="metric-note">Analisa HPP, margin, markup, dan profit produk.</div>
                        </div>
                        <a href="calculator.php?<?= http_build_query(['q' => $filterSearch, 'type' => $filterType, 'export' => 1]); ?>" class="btn btn-sm btn-outline-success btn-rounded">Export Excel</a>
                    </div>

                    <div class="p-3 border-bottom">
                        <form method="get" class="hpp-filter">
                            <input type="text" name="q" class="form-control" placeholder="Cari kode, produk, atau catatan..." value="<?= product_safe($filterSearch); ?>">
                            <select name="type" class="form-select" style="max-width:180px;">
                                <option value="">Semua Tipe</option>
                                <?php foreach ($typeOptions as $value => $label): ?>
                                    <option value="<?= product_safe($value); ?>" <?= $filterType === $value ? 'selected' : ''; ?>><?= product_safe($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-success btn-rounded">Filter</button>
                            <a href="calculator.php" class="btn btn-outline-secondary btn-rounded">Reset</a>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 hpp-table">
                            <thead>
                                <tr>
                                    <th class="hpp-col-code">Kode</th>
                                    <th class="hpp-col-product">Produk</th>
                                    <th class="hpp-col-output">Output</th>
                                    <th class="hpp-col-money">Total Cost</th>
                                    <th class="hpp-col-money">HPP/Unit</th>
                                    <th class="hpp-col-money">Harga Jual</th>
                                    <th class="hpp-col-money">Profit Total</th>
                                    <th class="hpp-col-percent">Margin</th>
                                    <th class="hpp-col-percent">Markup</th>
                                    <th class="hpp-col-status">Status</th>
                                    <th class="hpp-col-action">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($history)): ?>
                                <tr><td colspan="11" class="text-center text-muted py-4">Belum ada riwayat kalkulasi.</td></tr>
                            <?php endif; ?>

                            <?php foreach ($history as $h):
                                $outputQty = (float)$h['output_qty'];
                                $hpp = (float)$h['hpp_per_unit'];
                                $selling = (float)$h['selling_price'];
                                $profitUnit = (float)$h['profit_per_unit'];
                                $profitTotal = $profitUnit * $outputQty;
                                $margin = (float)$h['margin_percent'];
                                $markup = hpp_markup($hpp, $selling);
                                $bep = hpp_bep_units((float)$h['total_cost'], $profitUnit);
                                $price30 = hpp_target_price($hpp, 30);
                                $price40 = hpp_target_price($hpp, 40);
                                $price50 = hpp_target_price($hpp, 50);
                                [$statusText, $statusClass] = hpp_status_label($margin);
                            ?>
                                <tr>
                                    <td class="hpp-col-code"><?= product_safe($h['calculation_code']); ?></td>
                                    <td class="hpp-col-product">
                                        <strong><?= product_safe($h['product_name']); ?></strong><br>
                                        <span class="metric-note"><?= product_safe(ucwords(str_replace('_', ' ', $h['product_type'] ?? ''))); ?></span>
                                    </td>
                                    <td class="hpp-col-output"><?= product_num($h['output_qty']) . ' ' . product_safe($h['output_unit']); ?></td>
                                    <td class="hpp-col-money"><?= product_money($h['total_cost']); ?></td>
                                    <td class="hpp-col-money"><?= product_money($hpp); ?></td>
                                    <td class="hpp-col-money"><?= product_money($selling); ?></td>
                                    <td class="hpp-col-money">
                                        <?= product_money($profitTotal); ?><br>
                                        <span class="metric-note"><?= product_money($profitUnit); ?>/unit</span>
                                    </td>
                                    <td class="hpp-col-percent"><?= product_num($margin, 2); ?>%</td>
                                    <td class="hpp-col-percent"><?= product_num($markup, 2); ?>%</td>
                                    <td class="hpp-col-status"><span class="badge bg-<?= product_safe($statusClass); ?>"><?= product_safe($statusText); ?></span></td>
                                    <td class="hpp-col-action">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button"
                                                class="btn btn-outline-secondary hpp-detail-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#hppDetailModal"
                                                data-code="<?= product_safe($h['calculation_code']); ?>"
                                                data-product="<?= product_safe($h['product_name']); ?>"
                                                data-type="<?= product_safe(ucwords(str_replace('_', ' ', $h['product_type'] ?? ''))); ?>"
                                                data-output="<?= product_safe(product_num($h['output_qty']) . ' ' . ($h['output_unit'] ?? '')); ?>"
                                                data-material="<?= product_safe(product_money($h['material_cost'])); ?>"
                                                data-labor="<?= product_safe(product_money($h['labor_cost'])); ?>"
                                                data-overhead="<?= product_safe(product_money($h['overhead_cost'])); ?>"
                                                data-total="<?= product_safe(product_money($h['total_cost'])); ?>"
                                                data-hpp="<?= product_safe(product_money($hpp)); ?>"
                                                data-selling="<?= product_safe(product_money($selling)); ?>"
                                                data-profitunit="<?= product_safe(product_money($profitUnit)); ?>"
                                                data-profittotal="<?= product_safe(product_money($profitTotal)); ?>"
                                                data-margin="<?= product_safe(product_num($margin, 2) . '%'); ?>"
                                                data-markup="<?= product_safe(product_num($markup, 2) . '%'); ?>"
                                                data-bep="<?= product_safe($bep > 0 ? $bep . ' unit' : '-'); ?>"
                                                data-price30="<?= product_safe(product_money($price30)); ?>"
                                                data-price40="<?= product_safe(product_money($price40)); ?>"
                                                data-price50="<?= product_safe(product_money($price50)); ?>"
                                                data-status="<?= product_safe($statusText); ?>"
                                                data-notes="<?= product_safe($h['notes'] ?? '-'); ?>">
                                                Detail
                                            </button>
                                            <a href="calculator.php?edit_id=<?= (int)$h['id']; ?>" class="btn btn-outline-primary">Edit</a>
                                            <form method="post" onsubmit="return confirm('Hapus riwayat kalkulasi ini?');" class="d-inline">
                                                <input type="hidden" name="calculation_id" value="<?= (int)$h['id']; ?>">
                                                <button name="delete_calc" value="1" class="btn btn-outline-danger">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 border-top hpp-small">
                        Catatan: data kalkulator bersifat simulasi atau analisa biaya. Untuk biaya aktual produksi, gunakan menu Produksi lalu pilih data produksi pada dropdown di form kalkulator.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="hppDetailModal" tabindex="-1" aria-labelledby="hppDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="border-radius:18px;">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="hppDetailModalLabel">Detail Kalkulasi HPP</h5>
                        <div class="hpp-small" id="detailCode">-</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <h4 id="detailProduct" class="mb-1">-</h4>
                        <span class="badge bg-success" id="detailStatus">-</span>
                    </div>
                    <div class="hpp-detail-list">
                        <div class="hpp-detail-item"><span>Tipe</span><strong id="detailType">-</strong></div>
                        <div class="hpp-detail-item"><span>Output</span><strong id="detailOutput">-</strong></div>
                        <div class="hpp-detail-item"><span>Biaya Bahan</span><strong id="detailMaterial">-</strong></div>
                        <div class="hpp-detail-item"><span>Tenaga Kerja</span><strong id="detailLabor">-</strong></div>
                        <div class="hpp-detail-item"><span>Overhead</span><strong id="detailOverhead">-</strong></div>
                        <div class="hpp-detail-item"><span>Total Cost</span><strong id="detailTotal">-</strong></div>
                        <div class="hpp-detail-item"><span>HPP / Unit</span><strong id="detailHpp">-</strong></div>
                        <div class="hpp-detail-item"><span>Harga Jual</span><strong id="detailSelling">-</strong></div>
                        <div class="hpp-detail-item"><span>Profit / Unit</span><strong id="detailProfitUnit">-</strong></div>
                        <div class="hpp-detail-item"><span>Profit Total</span><strong id="detailProfitTotal">-</strong></div>
                        <div class="hpp-detail-item"><span>Margin</span><strong id="detailMargin">-</strong></div>
                        <div class="hpp-detail-item"><span>Markup</span><strong id="detailMarkup">-</strong></div>
                        <div class="hpp-detail-item"><span>BEP Estimasi</span><strong id="detailBep">-</strong></div>
                        <div class="hpp-detail-item"><span>Harga Margin 30%</span><strong id="detailPrice30">-</strong></div>
                        <div class="hpp-detail-item"><span>Harga Margin 40%</span><strong id="detailPrice40">-</strong></div>
                        <div class="hpp-detail-item"><span>Harga Margin 50%</span><strong id="detailPrice50">-</strong></div>
                    </div>
                    <div class="mt-3">
                        <label class="hpp-small fw-bold">Catatan</label>
                        <div class="border rounded-3 p-3 bg-light" id="detailNotes">-</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function productFallbackParse(v){
    return parseFloat((v || '').toString().replace(/\./g, '').replace(/,/g, '.').replace(/[^0-9.-]/g,'')) || 0;
}
function n(v){ return window.productParseId ? productParseId(v) : productFallbackParse(v); }
function q(v){ return window.productParseQty ? productParseQty(v) : n(v); }
function rp(v){ return 'Rp ' + Math.round(v || 0).toLocaleString('id-ID'); }
function pct(v){ return (isFinite(v) ? v : 0).toLocaleString('id-ID', {minimumFractionDigits:2, maximumFractionDigits:2}) + '%'; }

function formatMoneyInput(el){
    if (!el) return;
    let raw = (el.value || '').toString().replace(/\./g, '').replace(/[^0-9]/g, '');
    if (raw === '') raw = '0';
    el.value = parseInt(raw, 10).toLocaleString('id-ID');
}

function setMoney(id, value){
    const el = document.getElementById(id);
    if (!el) return;
    el.value = Math.round(value || 0).toLocaleString('id-ID');
    el.dispatchEvent(new CustomEvent('money-formatted', {bubbles:true}));
}

function setQty(id, value){
    const el = document.getElementById(id);
    if (!el) return;
    if (window.productFormatQty) {
        el.value = productFormatQty(value || 0);
    } else {
        el.value = (value || 0).toLocaleString('id-ID', {maximumFractionDigits:3});
    }
}

function targetPrice(hpp, margin){
    if (hpp <= 0 || margin <= 0 || margin >= 100) return 0;
    return hpp / (1 - (margin / 100));
}

function c(){
    const matEl = document.getElementById('mat');
    const labEl = document.getElementById('lab');
    const ovhEl = document.getElementById('ovh');
    const qtyEl = document.getElementById('qty');
    const sellEl = document.getElementById('sell');
    if (!matEl || !labEl || !ovhEl || !qtyEl || !sellEl) return;

    let mat = n(matEl.value),
        lab = n(labEl.value),
        ovh = n(ovhEl.value),
        qty = Math.max(q(qtyEl.value), .0001),
        sell = n(sellEl.value),
        total = mat + lab + ovh,
        hpp = total / qty,
        profit = sell - hpp,
        profitTotal = profit * qty,
        margin = sell > 0 ? profit / sell * 100 : 0,
        markup = hpp > 0 ? profit / hpp * 100 : 0,
        bep = profit > 0 ? Math.ceil(total / profit) : 0;

    document.getElementById('sumMat').innerText = rp(mat);
    document.getElementById('sumLab').innerText = rp(lab);
    document.getElementById('sumOvh').innerText = rp(ovh);
    document.getElementById('total').innerText = rp(total);
    document.getElementById('hpp').innerText = rp(hpp);
    document.getElementById('profit').innerText = rp(profit);
    document.getElementById('profitTotal').innerText = rp(profitTotal);
    document.getElementById('margin').innerText = pct(margin);
    document.getElementById('markup').innerText = pct(markup);
    document.getElementById('bep').innerText = profit > 0 ? (bep + ' unit') : '-';
    document.getElementById('price30').innerText = rp(targetPrice(hpp, 30));
    document.getElementById('price40').innerText = rp(targetPrice(hpp, 40));
    document.getElementById('price50').innerText = rp(targetPrice(hpp, 50));

    let badge = document.getElementById('statusBadge');
    let label = 'Belum dihitung', cls = 'bg-secondary';
    if (sell > 0 && total > 0) {
        if (margin < 0) { label = 'Rugi'; cls = 'bg-danger'; }
        else if (margin < 10) { label = 'Margin Tipis'; cls = 'bg-warning text-dark'; }
        else if (margin < 30) { label = 'Cukup'; cls = 'bg-primary'; }
        else if (margin < 50) { label = 'Untung'; cls = 'bg-success'; }
        else { label = 'Sangat Untung'; cls = 'bg-success'; }
    }
    badge.className = 'badge ' + cls;
    badge.innerText = label;
}

document.querySelectorAll('.money-input').forEach(e => {
    e.addEventListener('input', function(){
        formatMoneyInput(this);
        c();
    });
    e.addEventListener('focus', function(){
        if (this.value === '0') this.select();
    });
    formatMoneyInput(e);
});

document.querySelectorAll('.calc,#qty').forEach(e => {
    e.addEventListener('input', c);
    e.addEventListener('money-formatted', c);
    e.addEventListener('blur', c);
});

const picker = document.getElementById('productionPicker');
if (picker) {
    picker.addEventListener('change', function(){
        const opt = this.options[this.selectedIndex];
        if (!opt || !opt.value) return;
        document.getElementById('productName').value = opt.dataset.productName || '';
        document.getElementById('productType').value = opt.dataset.productType || 'recycle';
        setQty('qty', parseFloat(opt.dataset.outputQty || '1'));
        document.getElementById('outputUnit').value = opt.dataset.outputUnit || 'pcs';
        setMoney('mat', parseFloat(opt.dataset.materialCost || '0'));
        setMoney('lab', parseFloat(opt.dataset.laborCost || '0'));
        setMoney('ovh', parseFloat(opt.dataset.overheadCost || '0'));
        setMoney('sell', parseFloat(opt.dataset.sellingPrice || '0'));
        c();
    });
}

const resetBtn = document.getElementById('resetCalc');
if (resetBtn) {
    resetBtn.addEventListener('click', function(){ setTimeout(c, 50); });
}

document.querySelectorAll('.hpp-detail-btn').forEach(btn => {
    btn.addEventListener('click', function(){
        const set = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.innerText = val || '-';
        };
        set('detailCode', this.dataset.code);
        set('detailProduct', this.dataset.product);
        set('detailType', this.dataset.type);
        set('detailOutput', this.dataset.output);
        set('detailMaterial', this.dataset.material);
        set('detailLabor', this.dataset.labor);
        set('detailOverhead', this.dataset.overhead);
        set('detailTotal', this.dataset.total);
        set('detailHpp', this.dataset.hpp);
        set('detailSelling', this.dataset.selling);
        set('detailProfitUnit', this.dataset.profitunit);
        set('detailProfitTotal', this.dataset.profittotal);
        set('detailMargin', this.dataset.margin);
        set('detailMarkup', this.dataset.markup);
        set('detailBep', this.dataset.bep);
        set('detailPrice30', this.dataset.price30);
        set('detailPrice40', this.dataset.price40);
        set('detailPrice50', this.dataset.price50);
        set('detailStatus', this.dataset.status);
        set('detailNotes', this.dataset.notes);
    });
});

c();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
