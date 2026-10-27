<?php
require_once __DIR__ . '/helpers.php';
require_post();

$movementId = (int)($_GET['id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

try {
    if ($reason === '') throw new Exception('Alasan koreksi wajib diisi.');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM operational_fund_movements WHERE id=:id FOR UPDATE");
    $stmt->execute([':id'=>$movementId]);
    $movement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$movement) throw new Exception('Mutasi tidak ditemukan.');
    if (($movement['source_module'] ?? '') !== 'finance' || ($movement['source_table'] ?? '') !== 'operational_funds' || !in_array($movement['movement_type'], ['receive','return'], true)) {
        throw new Exception('Mutasi hasil integrasi harus dikoreksi dari modul sumbernya.');
    }
    $check = $pdo->prepare("SELECT id FROM operational_fund_movements WHERE source_module='finance' AND source_table='operational_fund_movements' AND source_id=:id LIMIT 1");
    $check->execute([':id'=>$movementId]);
    if ($check->fetchColumn()) throw new Exception('Mutasi ini sudah pernah dibalik.');

    $inverseType = $movement['movement_type'] === 'receive' ? 'return' : 'receive';
    $reversalId = opfund_add_movement(
        $pdo,
        (int)$movement['fund_id'],
        $inverseType,
        (float)$movement['amount'],
        date('Y-m-d'),
        !empty($movement['account_id']) ? (int)$movement['account_id'] : null,
        'Pembalik mutasi #'.$movementId.' — '.$reason,
        'finance',
        'operational_fund_movements',
        $movementId,
        !empty($movement['project_id']) ? (int)$movement['project_id'] : null,
        $movement['payment_method'] ?? 'other',
        $_SESSION['user_id'] ?? null,
        $movement['posting_mode'] ?? 'posted',
        ($movement['posting_mode'] ?? 'posted') === 'historical' ? $reason : null
    );
    log_activity($pdo, 'Operational Fund', 'REVERSE', $reason, $reversalId, 'operational_fund_movements');
    $pdo->commit();
    redirect('pages/finance/operational_fund_detail.php?id='.(int)$movement['fund_id'].'&message='.urlencode('Mutasi pembalik berhasil dibuat dan saldo sudah dihitung ulang.'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $fundId = isset($movement['fund_id']) ? (int)$movement['fund_id'] : 0;
    redirect(($fundId ? 'pages/finance/operational_fund_detail.php?id='.$fundId : 'pages/finance/operational_funds.php').($fundId ? '&' : '?').'error='.urlencode($e->getMessage()));
}
