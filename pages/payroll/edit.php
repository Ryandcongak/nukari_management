<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$pageTitle = 'Edit Adjustment Payroll - Nukari';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: ' . base_url('pages/payroll/index.php'));
    exit;
}

function edit_money($value)
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
if ($currentUserId <= 0) {
    $currentUserId = null;
}

$error = '';
$success = '';

$stmt = $pdo->prepare("
    SELECT
        p.*,
        e.full_name,
        e.employee_code,
        e.position,
        e.department,
        e.salary_type,
        e.salary_amount
    FROM payrolls p
    JOIN employees e ON p.employee_id = e.id
    WHERE p.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$payroll = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payroll) {
    header('Location: ' . base_url('pages/payroll/index.php'));
    exit;
}

$isLocked = (int)($payroll['is_locked'] ?? 0) === 1;

if ($isLocked) {
    require_once __DIR__ . '/../../includes/header.php';
    require_once __DIR__ . '/../../includes/sidebar.php';
?>
    <main class="main-content">
        <div class="container-fluid py-4">
            <div class="alert alert-dark rounded-4 shadow-sm">
                <h5 class="fw-bold mb-2"><i class="bi bi-lock-fill me-1"></i> Payroll Locked</h5>
                <p class="mb-3">Payroll ini sudah dikunci. Buka lock dari halaman detail payroll jika memang perlu dilakukan koreksi.</p>
                <a href="<?= base_url('pages/payroll/detail.php?id=' . $id); ?>" class="btn btn-light">
                    <i class="bi bi-arrow-left me-1"></i> Kembali ke Detail
                </a>
            </div>
        </div>
    </main>
<?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$manualBonus = (float)($payroll['manual_bonus'] ?? 0);
$manualDeduction = (float)($payroll['manual_deduction'] ?? 0);
$adjustmentNote = (string)($payroll['adjustment_note'] ?? '');

$baseSalary = (float)($payroll['base_salary'] ?? 0);
$totalAllowance = (float)($payroll['total_allowance'] ?? 0);
$totalBonus = (float)($payroll['total_bonus'] ?? 0);
$overtimeAmount = (float)($payroll['overtime_amount'] ?? 0);
$totalDeduction = (float)($payroll['total_deduction'] ?? 0);
$cashAdvanceDeduction = (float)($payroll['cash_advance_deduction'] ?? 0);
$loanDeduction = (float)($payroll['loan_deduction'] ?? 0);
$totalTax = (float)($payroll['total_tax'] ?? 0);
$bpjsHealth = (float)($payroll['bpjs_health'] ?? 0);
$bpjsEmployment = (float)($payroll['bpjs_employment'] ?? 0);

function calculate_adjusted_net_salary(array $payroll, float $manualBonus, float $manualDeduction): float
{
    $gross = (float)($payroll['base_salary'] ?? 0)
        + (float)($payroll['total_allowance'] ?? 0)
        + (float)($payroll['total_bonus'] ?? 0)
        + (float)($payroll['overtime_amount'] ?? 0)
        + $manualBonus;

    $deductions = (float)($payroll['total_deduction'] ?? 0)
        + (float)($payroll['cash_advance_deduction'] ?? 0)
        + (float)($payroll['loan_deduction'] ?? 0)
        + (float)($payroll['total_tax'] ?? 0)
        + (float)($payroll['bpjs_health'] ?? 0)
        + (float)($payroll['bpjs_employment'] ?? 0)
        + $manualDeduction;

    return max(0, $gross - $deductions);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manualBonus = (float)str_replace(['.', ','], ['', '.'], $_POST['manual_bonus'] ?? 0);
    $manualDeduction = (float)str_replace(['.', ','], ['', '.'], $_POST['manual_deduction'] ?? 0);
    $adjustmentNote = trim($_POST['adjustment_note'] ?? '');

    if ($manualBonus < 0 || $manualDeduction < 0) {
        $error = 'Nominal adjustment tidak boleh minus.';
    } else {
        try {
            $newNetSalary = calculate_adjusted_net_salary($payroll, $manualBonus, $manualDeduction);

            $update = $pdo->prepare("
                UPDATE payrolls
                SET
                    manual_bonus = :manual_bonus,
                    manual_deduction = :manual_deduction,
                    adjustment_note = :adjustment_note,
                    adjusted_at = NOW(),
                    adjusted_by = :adjusted_by,
                    net_salary = :net_salary,
                    updated_at = NOW()
                WHERE id = :id
                AND COALESCE(is_locked, 0) = 0
                LIMIT 1
            ");

            $update->execute([
                ':manual_bonus' => $manualBonus,
                ':manual_deduction' => $manualDeduction,
                ':adjustment_note' => $adjustmentNote !== '' ? $adjustmentNote : null,
                ':adjusted_by' => $currentUserId,
                ':net_salary' => $newNetSalary,
                ':id' => $id
            ]);

            log_activity(
                $pdo,
                'Payroll',
                'UPDATE_ADJUSTMENT',
                'Mengubah adjustment payroll ' . ($payroll['full_name'] ?? '-') . ' periode ' . ($payroll['payroll_month'] ?? '-') .
                    '. Bonus manual: ' . edit_money($manualBonus) .
                    ', potongan manual: ' . edit_money($manualDeduction) .
                    ', net salary baru: ' . edit_money($newNetSalary),
                $id,
                'payrolls',
                $currentUserId
            );

            header('Location: ' . base_url('pages/payroll/detail.php?id=' . $id . '&adjusted=1'));
            exit;
        } catch (Exception $e) {
            $error = 'Gagal menyimpan adjustment payroll: ' . $e->getMessage();
        }
    }
}

$previewNetSalary = calculate_adjusted_net_salary($payroll, $manualBonus, $manualDeduction);
$grossBeforeManual = $baseSalary + $totalAllowance + $totalBonus + $overtimeAmount;
$deductionBeforeManual = $totalDeduction + $cashAdvanceDeduction + $loanDeduction + $totalTax + $bpjsHealth + $bpjsEmployment;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<main class="main-content">
    <div class="container-fluid py-4">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
            <div>
                <h1 class="fw-bold mb-1">Edit Adjustment Payroll</h1>
                <p class="text-muted mb-0">
                    Koreksi manual payroll untuk human error tanpa mengubah data absensi asli.
                </p>
            </div>

            <a href="<?= base_url('pages/payroll/detail.php?id=' . $id); ?>" class="btn btn-outline-success">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Detail
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-4 shadow-sm">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Karyawan</div>
                        <h5 class="fw-bold mb-1"><?= htmlspecialchars($payroll['full_name']); ?></h5>
                        <div class="text-muted small">
                            <?= htmlspecialchars($payroll['employee_code'] ?? '-'); ?> ·
                            <?= htmlspecialchars($payroll['position'] ?? '-'); ?>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Periode</span>
                            <strong><?= htmlspecialchars($payroll['payroll_month']); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <span class="text-muted">Status</span>
                            <span class="badge bg-warning text-dark">Editable</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body">
                                <div class="text-muted small">Pendapatan Awal</div>
                                <div class="fw-bold fs-5 text-success"><?= edit_money($grossBeforeManual); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body">
                                <div class="text-muted small">Potongan Awal</div>
                                <div class="fw-bold fs-5 text-danger"><?= edit_money($deductionBeforeManual); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body">
                                <div class="text-muted small">Preview Gaji Bersih</div>
                                <div class="fw-bold fs-5 text-primary" id="previewNet"><?= edit_money($previewNetSalary); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 px-4">
                <h5 class="fw-bold mb-1">Form Adjustment</h5>
                <p class="text-muted small mb-0">
                    Gunakan untuk koreksi khusus seperti tambahan bonus manual atau potongan manual. Payroll locked tidak bisa diedit.
                </p>
            </div>

            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Bonus Manual / Tambahan</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="text" name="manual_bonus" id="manualBonus" class="form-control money-input"
                                value="<?= number_format($manualBonus, 0, ',', '.'); ?>" placeholder="0">
                        </div>
                        <div class="form-text">Menambah gaji bersih. Contoh: bonus khusus, koreksi lembur, insentif.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Potongan Manual</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="text" name="manual_deduction" id="manualDeduction" class="form-control money-input"
                                value="<?= number_format($manualDeduction, 0, ',', '.'); ?>" placeholder="0">
                        </div>
                        <div class="form-text">Mengurangi gaji bersih. Jangan gunakan untuk potongan absensi.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Catatan Adjustment</label>
                        <textarea name="adjustment_note" class="form-control" rows="4"
                            placeholder="Contoh: Koreksi bonus karena human error / koreksi cicilan pinjaman / catatan persetujuan."><?= htmlspecialchars($adjustmentNote); ?></textarea>
                    </div>
                </div>

                <div class="alert alert-light border rounded-4 mt-4 mb-0">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="text-muted small">Gaji Pokok</div>
                            <strong><?= edit_money($baseSalary); ?></strong>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Tunjangan + Bonus</div>
                            <strong><?= edit_money($totalAllowance + $totalBonus); ?></strong>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Lembur</div>
                            <strong><?= edit_money($overtimeAmount); ?></strong>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted small">Potongan Sistem</div>
                            <strong class="text-danger"><?= edit_money($deductionBeforeManual); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-footer bg-white border-0 p-4 d-flex justify-content-end gap-2">
                <a href="<?= base_url('pages/payroll/detail.php?id=' . $id); ?>" class="btn btn-light px-4">Batal</a>
                <button type="submit" class="btn btn-success px-4">
                    <i class="bi bi-save me-1"></i> Simpan Adjustment
                </button>
            </div>
        </form>

    </div>
</main>

<script>
    document.querySelectorAll('.money-input').forEach(function(input) {
        input.addEventListener('input', function() {
            let value = this.value.replace(/[^\d]/g, '');
            this.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            updatePreview();
        });
    });

    function parseMoney(value) {
        return Number(String(value || '0').replace(/[^\d]/g, '')) || 0;
    }

    function formatRupiah(value) {
        return 'Rp ' + Math.max(0, value).toLocaleString('id-ID');
    }

    function updatePreview() {
        const grossBeforeManual = <?= json_encode($grossBeforeManual); ?>;
        const deductionBeforeManual = <?= json_encode($deductionBeforeManual); ?>;
        const manualBonus = parseMoney(document.getElementById('manualBonus').value);
        const manualDeduction = parseMoney(document.getElementById('manualDeduction').value);

        const net = Math.max(0, grossBeforeManual + manualBonus - deductionBeforeManual - manualDeduction);
        document.getElementById('previewNet').textContent = formatRupiah(net);
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>