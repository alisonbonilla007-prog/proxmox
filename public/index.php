<?php
// public/index.php — MESH Cloud front controller.
// Routes: superadmin console, tenant admin, tenant-branded portal, and the
// per-tenant M-Pesa JSON APIs. Tenant is resolved by subdomain or ?tenant=.

require_once __DIR__ . '/../secure_session.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Tenancy.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Mpesa.php';
require_once __DIR__ . '/../src/Provision.php';
require_once __DIR__ . '/../src/helpers.php';

$pdo      = Database::pdo($config);
$auth     = new Auth($pdo);
$tenancy  = new Tenancy($pdo, $config);
$VIEWS    = __DIR__ . '/../views';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($path === '') $path = '/';
// strip a leading /index.php if the dev server routes through it
$path = preg_replace('#^/index\.php#', '', $path) ?: '/';

function flash(string $t, string $m): void { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

// ----- helper: require a resolved tenant or 404 -----
$tenant = $tenancy->load($tenancy->resolveSlug());
function need_tenant(?array $tenant): array {
    if (!$tenant) { http_response_code(404); exit('Unknown tenant. Use a tenant subdomain or ?tenant=<slug>.'); }
    if (($tenant['status'] ?? '') === 'suspended') { http_response_code(403); exit('This portal is suspended.'); }
    return $tenant;
}

// =================================================================
//  SUPERADMIN  (/superadmin...)
// =================================================================
if (str_starts_with($path, '/superadmin')) {
    require __DIR__ . '/../src/controllers_superadmin.php';
    handle_superadmin($path, $pdo, $auth, $tenancy, $config, $VIEWS);
    exit;
}

// =================================================================
//  PUBLIC SELF-SERVE SIGNUP  (/signup...)
// =================================================================
if (str_starts_with($path, '/signup')) {
    require __DIR__ . '/../src/controllers_public.php';
    handle_signup($path, $pdo, $auth, $config, $VIEWS);
    exit;
}

// =================================================================
//  TENANT JSON APIs
// =================================================================
if ($path === '/initiate_payment.php') {
    $tenant = need_tenant($tenant);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['success' => false, 'error' => 'POST only'], 405);
    $voucherId = (int)($_POST['voucher_id'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    if (!preg_match('/^254[0-9]{9}$/', $phone)) json_out(['success' => false, 'error' => 'Invalid phone number. Use 2547XXXXXXXX.']);

    // per-session STK throttle
    $now = time();
    $_SESSION['stk_hits'] = array_values(array_filter($_SESSION['stk_hits'] ?? [], fn($t) => $t > $now - 60));
    if (count($_SESSION['stk_hits']) >= 5) json_out(['success' => false, 'error' => 'Too many attempts. Wait a minute.'], 429);
    $_SESSION['stk_hits'][] = $now;

    $st = $pdo->prepare("SELECT * FROM vouchers WHERE id = ? AND tenant_id = ?");
    $st->execute([$voucherId, $tenant['id']]);
    $voucher = $st->fetch();
    if (!$voucher) json_out(['success' => false, 'error' => 'Voucher not found.'], 404);

    $mpesa = new Mpesa($pdo, $tenant);
    [$ok, $res, $code] = $mpesa->stkPush($voucher, $phone);
    if (!$ok) json_out(['success' => false, 'error' => $res], $code);

    $ins = $pdo->prepare("INSERT INTO payments (tenant_id, voucher_id, merchant_request_id, checkout_request_id, phone, amount, status, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, 'pending', " . now_sql() . ", " . now_sql() . ")");
    $ins->execute([$tenant['id'], $voucherId, $res['MerchantRequestID'], $res['CheckoutRequestID'], $phone, $voucher['price']]);
    $pid = (int)$pdo->lastInsertId();
    $_SESSION['payments'][] = $pid;
    json_out(['success' => true, 'payment_id' => $pid, 'phone' => $phone, 'amount' => (float)$voucher['price'], 'voucher' => $voucher['name']]);
}

if ($path === '/check_payment.php') {
    $tenant = need_tenant($tenant);
    $pid = (int)($_GET['payment_id'] ?? 0);
    if (!$pid) json_out(['error' => 'missing payment_id']);
    if (!in_array($pid, $_SESSION['payments'] ?? [], true)) json_out(['error' => 'forbidden'], 403);
    $st = $pdo->prepare("SELECT status, username, password FROM payments WHERE id = ? AND tenant_id = ?");
    $st->execute([$pid, $tenant['id']]);
    $p = $st->fetch();
    if (!$p) json_out(['error' => 'not found']);
    $out = ['status' => $p['status']];
    if ($p['status'] === 'success' && !empty($p['username'])) { $out['username'] = $p['username']; $out['password'] = $p['password']; }
    json_out($out);
}

if ($path === '/mpesa_callback.php') {
    $tenant = need_tenant($tenant);
    require __DIR__ . '/../src/callback.php';
    handle_callback($pdo, $tenant);
    exit;
}

// =================================================================
//  TENANT ADMIN  (/admin...)
// =================================================================
if (str_starts_with($path, '/admin')) {
    $tenant = need_tenant($tenant);
    require __DIR__ . '/../src/controllers_admin.php';
    handle_tenant_admin($path, $pdo, $auth, $tenant, $config, $VIEWS);
    exit;
}

// =================================================================
//  TENANT PORTAL  (/)  — or apex landing
// =================================================================
if ($path === '/') {
    if (!$tenant) {
        // apex / no tenant → tiny landing
        echo view($VIEWS . '/landing.php', ['config' => $config]);
        exit;
    }
    $tenant = need_tenant($tenant);
    $st = $pdo->prepare("SELECT * FROM vouchers WHERE tenant_id = ? ORDER BY price ASC");
    $st->execute([$tenant['id']]);

    // Captive-portal handoff params (set by the router's login page redirect):
    //   MikroTik -> gw=link-login-only, dst=link-orig, mac, err
    //   pfSense  -> zone, redirurl
    // They let the portal log the user into the gateway after buy/voucher login.
    $gw = (string)($_GET['gw'] ?? '');
    $gwctx = [
        'gw'       => preg_match('#^https?://#i', $gw) ? $gw : '',  // only accept a real URL
        'dst'      => (string)($_GET['dst'] ?? ''),
        'mac'      => (string)($_GET['mac'] ?? ''),
        'err'      => (string)($_GET['err'] ?? ''),
        'zone'     => preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['zone'] ?? '')),
        'redirurl' => preg_match('#^https?://#i', (string)($_GET['redirurl'] ?? '')) ? $_GET['redirurl'] : '',
    ];
    $nt = $pdo->prepare("SELECT type FROM nas WHERE tenant_id = ? ORDER BY id LIMIT 1");
    $nt->execute([$tenant['id']]);
    $nasType = $nt->fetchColumn() ?: 'other';
    $routerKind = $gwctx['zone'] !== '' ? 'pfsense' : ($gwctx['gw'] !== '' ? 'mikrotik' : $nasType);

    echo view($VIEWS . '/portal.php', [
        'tenant' => $tenant, 'vouchers' => $st->fetchAll(), 'config' => $config,
        'gwctx' => $gwctx, 'routerKind' => $routerKind,
    ]);
    exit;
}

http_response_code(404);
echo 'Not found';
