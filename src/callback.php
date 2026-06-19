<?php
// src/callback.php — tenant-scoped M-Pesa STK callback handler.
// Idempotent, validates the paid amount, and issues a namespaced RADIUS
// voucher on success (Session-Timeout written to radreply).

function cb_log($tenant, $data): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] [' . ($tenant['slug'] ?? '?') . '] ' . json_encode($data) . "\n";
    @file_put_contents($dir . '/mpesa_callback.log', $line, FILE_APPEND);
}

function handle_callback(PDO $pdo, array $tenant): void {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        cb_log($tenant, ['error' => 'bad json']);
        json_out(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON'], 400);
    }
    $cb = $body['Body']['stkCallback'] ?? null;
    if (!$cb) { cb_log($tenant, ['error' => 'no callback']); json_out(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback'], 400); }

    $checkoutId = $cb['CheckoutRequestID'] ?? null;
    $resultCode = (int)($cb['ResultCode'] ?? 1);

    $st = $pdo->prepare("SELECT * FROM payments WHERE checkout_request_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$checkoutId, $tenant['id']]);
    $payment = $st->fetch();
    if (!$payment) { cb_log($tenant, ['warn' => 'no payment', 'co' => $checkoutId]); json_out(['ResultCode' => 0, 'ResultDesc' => 'No matching payment']); }

    // Idempotency
    if (in_array($payment['status'], ['success', 'failed'], true)) {
        cb_log($tenant, ['ignored' => 'already finalized', 'id' => $payment['id']]);
        json_out(['ResultCode' => 0, 'ResultDesc' => 'Already processed']);
    }

    $pdo->prepare("UPDATE payments SET mpesa_response = ?, updated_at = " . now_sql() . " WHERE id = ?")
        ->execute([json_encode($body), $payment['id']]);

    if ($resultCode !== 0) {
        $pdo->prepare("UPDATE payments SET status = 'failed', updated_at = " . now_sql() . " WHERE id = ?")->execute([$payment['id']]);
        cb_log($tenant, ['failed' => $payment['id'], 'code' => $resultCode]);
        json_out(['ResultCode' => 0, 'ResultDesc' => 'Success']);
    }

    // Extract paid amount
    $paid = null;
    foreach (($cb['CallbackMetadata']['Item'] ?? []) as $it) {
        if (($it['Name'] ?? '') === 'Amount') $paid = (float)$it['Value'];
    }
    if ($paid !== null && $paid + 0.001 < (float)$payment['amount']) {
        $pdo->prepare("UPDATE payments SET status = 'failed', updated_at = " . now_sql() . " WHERE id = ?")->execute([$payment['id']]);
        cb_log($tenant, ['amount_mismatch' => true, 'expected' => $payment['amount'], 'paid' => $paid]);
        json_out(['ResultCode' => 0, 'ResultDesc' => 'Amount mismatch']);
    }

    try {
        $v = $pdo->prepare("SELECT * FROM vouchers WHERE id = ? AND tenant_id = ?");
        $v->execute([$payment['voucher_id'], $tenant['id']]);
        $voucher = $v->fetch();
        if (!$voucher) throw new RuntimeException('voucher gone');

        $pdo->beginTransaction();
        $mpesa = new Mpesa($pdo, $tenant);
        [$username, $password] = $mpesa->issueVoucher($voucher);
        $pdo->prepare("UPDATE payments SET status = 'success', username = ?, password = ?, updated_at = " . now_sql() . " WHERE id = ?")
            ->execute([$username, $password, $payment['id']]);
        $pdo->commit();
        cb_log($tenant, ['success' => $payment['id'], 'user' => $username]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare("UPDATE payments SET status = 'failed', updated_at = " . now_sql() . " WHERE id = ?")->execute([$payment['id']]);
        cb_log($tenant, ['error' => 'issue failed', 'msg' => $ex->getMessage()]);
    }
    json_out(['ResultCode' => 0, 'ResultDesc' => 'Success']);
}
