<?php
// src/controllers_superadmin.php — platform owner console: login, fleet
// overview, create/suspend tenants, and per-tenant onboarding artifacts.

function handle_superadmin(string $path, PDO $pdo, Auth $auth, Tenancy $tenancy, array $config, string $VIEWS): void {
    $sub = substr($path, strlen('/superadmin')); // '', '/login', '/tenants', '/onboarding', '/logout'...

    if ($sub === '/logout') { $auth->logoutSuperadmin(); redirect('/superadmin'); }

    // Login handling
    if ($sub === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($auth->csrfOk() && $auth->loginSuperadmin($_POST['username'] ?? '', $_POST['password'] ?? '')) {
            redirect('/superadmin');
        }
        flash('error', 'Invalid credentials.');
        redirect('/superadmin');
    }

    if (!$auth->superadminId()) {
        echo view($VIEWS . '/login.php', [
            'title' => 'MESH Cloud — Operator', 'subtitle' => 'Platform console',
            'action' => '/superadmin/login', 'csrf' => $auth->csrfToken(),
            'flash' => take_flash(), 'accent' => '#19c8ff',
        ]);
        return;
    }

    // Authenticated POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->csrfOk()) { flash('error', 'Bad CSRF.'); redirect('/superadmin'); }
        $action = $_POST['action'] ?? '';
        if ($action === 'create_tenant') {
            [$tid, $err] = provision_new_tenant(
                $pdo, $config,
                trim($_POST['name'] ?? ''), $_POST['slug'] ?? '', $_POST['owner_password'] ?? '',
                $_POST['brand_color'] ?? '#25f4a7'
            );
            flash($err ? 'error' : 'success', $err ?: "Tenant created. Owner login: owner / (the password you set).");
            redirect('/superadmin');
        }
        if ($action === 'extend_sub') {
            // extend the billing period by N days and mark active
            $days = max(1, min(366, (int)($_POST['days'] ?? 30)));
            $end = date('Y-m-d H:i:s', time() + $days * 86400);
            $pdo->prepare("UPDATE subscriptions SET status='active', current_period_end=? WHERE tenant_id=?")
                ->execute([$end, (int)$_POST['tenant_id']]);
            // reactivate the tenant if it had been suspended for non-payment
            $pdo->prepare("UPDATE tenants SET status='active' WHERE id=? AND status='suspended'")->execute([(int)$_POST['tenant_id']]);
            flash('success', "Subscription extended {$days} days.");
            redirect('/superadmin');
        }
        if ($action === 'set_plan') {
            $plan = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_POST['plan'] ?? 'starter'));
            $pdo->prepare("UPDATE subscriptions SET plan=? WHERE tenant_id=?")->execute([$plan ?: 'starter', (int)$_POST['tenant_id']]);
            $pdo->prepare("UPDATE tenants SET plan=? WHERE id=?")->execute([$plan ?: 'starter', (int)$_POST['tenant_id']]);
            flash('success', 'Plan updated.');
            redirect('/superadmin');
        }
        if ($action === 'set_status') {
            $pdo->prepare("UPDATE tenants SET status = ? WHERE id = ?")
                ->execute([$_POST['status'] === 'suspended' ? 'suspended' : 'active', (int)$_POST['tenant_id']]);
            flash('success', 'Tenant status updated.');
            redirect('/superadmin');
        }
        if ($action === 'activate_peer') {
            $tid = (int)$_POST['tenant_id'];
            $ok = (new Provision($pdo, $config))->savePublicKey($tid, $_POST['public_key'] ?? '');
            flash($ok ? 'success' : 'error', $ok ? 'Router public key saved — peer activated.' : 'Invalid public key format.');
            redirect('/superadmin/onboarding?tenant_id=' . $tid);
        }
    }

    // Onboarding page for a tenant
    if ($sub === '/onboarding') {
        $tid = (int)($_GET['tenant_id'] ?? 0);
        $t = $pdo->prepare("SELECT * FROM tenants WHERE id = ?"); $t->execute([$tid]);
        $tenant = $t->fetch();
        if (!$tenant) { http_response_code(404); exit('No such tenant'); }
        $prov = new Provision($pdo, $config);
        $peer = $prov->ensurePeer($tenant);
        echo view($VIEWS . '/onboarding.php', [
            'tenant' => $tenant, 'peer' => $peer,
            'mikrotik' => $prov->mikrotikScript($tenant, $peer),
            'pfsense'  => $prov->pfsenseSteps($tenant, $peer),
            'serverPeer' => $prov->serverPeerBlock($tenant, $peer),
            'serverCmd'  => $prov->serverPeerCommand($peer),
            'isSuper' => true,
            'csrf' => $auth->csrfToken(), 'flash' => take_flash(), 'config' => $config,
        ]);
        return;
    }

    // Fleet console (default)
    $tenants = $pdo->query(
        "SELECT t.*, s.status AS sub_status, s.current_period_end AS period_end, s.plan AS sub_plan,
            (SELECT COUNT(*) FROM vouchers v WHERE v.tenant_id = t.id) AS pkgs,
            (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.tenant_id = t.id AND p.status='success') AS revenue,
            (SELECT COUNT(*) FROM radacct r WHERE r.tenant_id = t.id AND r.acctstoptime IS NULL) AS online,
            (SELECT status FROM wg_peers w WHERE w.tenant_id = t.id ORDER BY w.id LIMIT 1) AS wg_status,
            (SELECT COUNT(*) FROM alerts a WHERE a.tenant_id = t.id AND a.resolved_at IS NULL) AS open_alerts,
            (SELECT cpu_load FROM device_metrics d WHERE d.tenant_id = t.id ORDER BY d.id DESC LIMIT 1) AS last_cpu,
            (SELECT sampled_at FROM device_metrics d WHERE d.tenant_id = t.id ORDER BY d.id DESC LIMIT 1) AS last_sample
         FROM tenants t LEFT JOIN subscriptions s ON s.tenant_id = t.id
         GROUP BY t.id ORDER BY t.id"
    )->fetchAll();

    // recent open alerts across the fleet
    $alerts = $pdo->query(
        "SELECT a.*, t.name AS tenant_name, t.slug FROM alerts a JOIN tenants t ON t.id = a.tenant_id
         WHERE a.resolved_at IS NULL ORDER BY a.id DESC LIMIT 12"
    )->fetchAll();

    echo view($VIEWS . '/superadmin.php', [
        'tenants' => $tenants, 'alerts' => $alerts, 'csrf' => $auth->csrfToken(),
        'flash' => take_flash(), 'admin' => $_SESSION['superadmin_name'] ?? 'root',
        'config' => $config,
    ]);
}
