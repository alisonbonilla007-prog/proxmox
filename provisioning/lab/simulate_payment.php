<?php
// simulate_payment.php — OFFLINE voucher issuance for LAB testing only.
//
// M-Pesa is NOT removed: the normal flow (initiate_payment.php -> Safaricom STK
// -> mpesa_callback.php) is the real path. This helper exists ONLY for testing
// the RADIUS / portal / dashboard pieces when you have no internet to reach
// Safaricom. It marks a pending payment "success" and issues the SAME namespaced
// RADIUS account (<slug>-VCH-XXXXXX, Session-Timeout in radreply) the real
// callback would, by reusing Mpesa::issueVoucher().
//
// Usage (run on the lab VM, mysql env like the crons):
//   List a tenant's pending payments:
//     DB_DRIVER=mysql DB_HOST=localhost DB_NAME=meshcloud DB_USER=meshcloud DB_PASS=... \
//       php simulate_payment.php --tenant=swiftnet --list
//   Approve a specific pending payment id:
//     ... php simulate_payment.php --tenant=swiftnet --payment=12
//   Create + approve a fresh payment for a voucher (no portal click needed):
//     ... php simulate_payment.php --tenant=swiftnet --voucher=3 --phone=254712345678
//
// SQLite (no DB env): defaults to the local meshcloud.db used in dev.

$root = dirname(__DIR__, 2); // .../SAAS
require_once $root . '/src/Database.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/Mpesa.php';

$config = require $root . '/config.php';
$pdo = Database::pdo($config);

// ---- args ----
$args = [];
foreach ($argv as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$slug = $args['tenant'] ?? null;
if (!$slug) { fwrite(STDERR, "Required: --tenant=<slug>. See header for usage.\n"); exit(1); }

$ts = $pdo->prepare("SELECT * FROM tenants WHERE slug = ?");
$ts->execute([$slug]);
$tenant = $ts->fetch();
if (!$tenant) { fwrite(STDERR, "No tenant '{$slug}'.\n"); exit(1); }
$tid = (int)$tenant['id'];
// load settings (Mpesa::issueVoucher reads tenant['slug'] + ['id'])
$tenant['settings'] = [];

// ---- list pending ----
if (!empty($args['list'])) {
    $st = $pdo->prepare("SELECT p.id, p.phone, p.amount, p.status, v.name FROM payments p LEFT JOIN vouchers v ON v.id=p.voucher_id WHERE p.tenant_id=? ORDER BY p.id DESC LIMIT 20");
    $st->execute([$tid]);
    printf("%-5s %-14s %-9s %-9s %s\n", 'ID', 'PHONE', 'AMOUNT', 'STATUS', 'VOUCHER');
    foreach ($st->fetchAll() as $r) printf("%-5s %-14s %-9s %-9s %s\n", $r['id'], $r['phone'], $r['amount'], $r['status'], $r['name']);
    exit;
}

// ---- resolve / create the payment to approve ----
$paymentId = isset($args['payment']) ? (int)$args['payment'] : 0;

if (!$paymentId && isset($args['voucher'])) {
    $vid = (int)$args['voucher'];
    $phone = $args['phone'] ?? '254700000000';
    $v = $pdo->prepare("SELECT * FROM vouchers WHERE id=? AND tenant_id=?");
    $v->execute([$vid, $tid]);
    $voucher = $v->fetch();
    if (!$voucher) { fwrite(STDERR, "No voucher {$vid} for {$slug}.\n"); exit(1); }
    $ins = $pdo->prepare("INSERT INTO payments (tenant_id, voucher_id, merchant_request_id, checkout_request_id, phone, amount, status, created_at, updated_at) VALUES (?,?,?,?,?,?, 'pending', " . now_sql() . ", " . now_sql() . ")");
    $ins->execute([$tid, $vid, 'SIM-' . bin2hex(random_bytes(3)), 'SIM_' . bin2hex(random_bytes(4)), $phone, $voucher['price']]);
    $paymentId = (int)$pdo->lastInsertId();
    fwrite(STDOUT, "Created pending payment #{$paymentId} for voucher '{$voucher['name']}'.\n");
}

if (!$paymentId) { fwrite(STDERR, "Give --payment=<id>, or --voucher=<id> [--phone=...], or --list.\n"); exit(1); }

$p = $pdo->prepare("SELECT * FROM payments WHERE id=? AND tenant_id=?");
$p->execute([$paymentId, $tid]);
$payment = $p->fetch();
if (!$payment) { fwrite(STDERR, "No payment {$paymentId} for {$slug}.\n"); exit(1); }
if ($payment['status'] === 'success') { fwrite(STDOUT, "Already success: {$payment['username']} / {$payment['password']}\n"); exit; }

$v = $pdo->prepare("SELECT * FROM vouchers WHERE id=? AND tenant_id=?");
$v->execute([$payment['voucher_id'], $tid]);
$voucher = $v->fetch();
if (!$voucher) { fwrite(STDERR, "Voucher gone.\n"); exit(1); }

// ---- issue exactly like the real callback would ----
try {
    $pdo->beginTransaction();
    $mpesa = new Mpesa($pdo, $tenant);
    [$username, $password] = $mpesa->issueVoucher($voucher);
    $pdo->prepare("UPDATE payments SET status='success', username=?, password=?, updated_at=" . now_sql() . " WHERE id=?")
        ->execute([$username, $password, $paymentId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "OK — payment #{$paymentId} approved (SIMULATED).\n");
fwrite(STDOUT, "  Voucher login:  {$username} / {$password}\n");
fwrite(STDOUT, "  Use it on the MikroTik/pfSense captive portal to test RADIUS auth.\n");
