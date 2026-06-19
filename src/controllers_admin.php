<?php
// src/controllers_admin.php — per-tenant admin: login + CSRF action router +
// dashboard data (all queries scoped to the resolved tenant_id).

function handle_tenant_admin(string $path, PDO $pdo, Auth $auth, array $tenant, array $config, string $VIEWS): void {
    $tid = (int)$tenant['id'];
    $sub = substr($path, strlen('/admin')); // '', '/login', '/logout'
    $base = '/admin?tenant=' . $tenant['slug'];

    if ($sub === '/logout') { $auth->logoutTenant(); redirect($base); }

    if ($sub === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($auth->csrfOk() && $auth->loginTenant($tid, $_POST['username'] ?? '', $_POST['password'] ?? '')) {
            redirect($base);
        }
        flash('error', 'Invalid credentials.');
        redirect($base);
    }

    if (!$auth->tenantUser($tid)) {
        echo view($VIEWS . '/login.php', [
            'title' => e($tenant['name']) . ' — Admin', 'subtitle' => 'Hotspot console',
            'action' => '/admin/login?tenant=' . $tenant['slug'], 'csrf' => $auth->csrfToken(),
            'flash' => take_flash(), 'accent' => $tenant['brand_color'] ?: '#25f4a7',
        ]);
        return;
    }

    // ---- POST actions (CSRF) ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->csrfOk()) { flash('error', 'Invalid CSRF token.'); redirect($base); }
        $a = $_POST['action'] ?? '';
        try {
            if ($a === 'add_voucher' || $a === 'edit_voucher') {
                $name = trim($_POST['name'] ?? ''); $price = (float)($_POST['price'] ?? 0);
                if ($name === '' || $price < 0) { flash('error', 'Name and price required.'); redirect($base . '&s=packages'); }
                $args = [$name, $price, (int)($_POST['time_limit'] ?? 0), trim($_POST['bandwidth_limit'] ?? ''), (int)($_POST['data_limit'] ?? 0)];
                if ($a === 'add_voucher') {
                    $pdo->prepare("INSERT INTO vouchers (name, price, time_limit, bandwidth_limit, data_limit, tenant_id) VALUES (?,?,?,?,?,?)")
                        ->execute([...$args, $tid]);
                    flash('success', "Package “{$name}” added.");
                } else {
                    $pdo->prepare("UPDATE vouchers SET name=?, price=?, time_limit=?, bandwidth_limit=?, data_limit=? WHERE id=? AND tenant_id=?")
                        ->execute([...$args, (int)$_POST['id'], $tid]);
                    flash('success', "Package “{$name}” updated.");
                }
                redirect($base . '&s=packages');
            }
            if ($a === 'delete_voucher') {
                $pdo->prepare("DELETE FROM vouchers WHERE id=? AND tenant_id=?")->execute([(int)$_POST['id'], $tid]);
                flash('success', 'Package deleted.'); redirect($base . '&s=packages');
            }
            if ($a === 'revoke_account') {
                $u = trim($_POST['username'] ?? '');
                $pdo->beginTransaction();
                foreach (['radcheck','radreply','radusergroup'] as $tbl) {
                    $pdo->prepare("DELETE FROM $tbl WHERE username=? AND tenant_id=?")->execute([$u, $tid]);
                }
                $pdo->commit();
                flash('success', "Account {$u} revoked."); redirect($base . '&s=issued');
            }
            if ($a === 'kick') {
                $pdo->prepare("UPDATE radacct SET acctstoptime=?, acctterminatecause='Admin-Reset' WHERE username=? AND acctsessionid=? AND acctstoptime IS NULL AND tenant_id=?")
                    ->execute([date('Y-m-d H:i:s'), $_POST['username'] ?? '', $_POST['sessionid'] ?? '', $tid]);
                flash('success', 'Session disconnected.'); redirect($base . '&s=sessions');
            }
            if ($a === 'activate_peer') {
                $ok = (new Provision($pdo, $config))->savePublicKey($tid, $_POST['public_key'] ?? '');
                flash($ok ? 'success' : 'error', $ok ? 'Router public key saved — tunnel activated.' : 'Invalid public key format.');
                redirect('/admin/onboarding?tenant=' . $tenant['slug']);
            }
            if ($a === 'save_mpesa') {
                $env = ($_POST['mpesa_env'] ?? '') === 'production' ? 'production' : 'sandbox';
                $cb = rtrim(trim($_POST['callback_base'] ?? ''), '/');
                if ($cb !== '' && !preg_match('#^https?://#i', $cb)) { flash('error', 'Callback must start with http(s)://'); redirect($base . '&s=settings'); }
                $pdo->prepare("UPDATE tenant_settings SET mpesa_env=?, shortcode=?, passkey=?, consumer_key=?, consumer_secret=?, callback_base=?, timeout=? WHERE tenant_id=?")
                    ->execute([$env, trim($_POST['shortcode'] ?? ''), trim($_POST['passkey'] ?? ''), trim($_POST['consumer_key'] ?? ''), trim($_POST['consumer_secret'] ?? ''), $cb, max(5, min(120, (int)($_POST['timeout'] ?? 30))), $tid]);
                flash('success', 'M-Pesa settings saved.'); redirect($base . '&s=settings');
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('tenant admin action error: ' . $ex->getMessage());
            flash('error', 'Action failed.'); redirect($base);
        }
        redirect($base);
    }

    // ---- Tenant-owner onboarding page ("Connect router") ----
    if ($sub === '/onboarding') {
        $prov = new Provision($pdo, $config);
        $peer = $prov->ensurePeer($tenant);
        echo view($VIEWS . '/onboarding.php', [
            'tenant' => $tenant, 'peer' => $peer,
            'mikrotik' => $prov->mikrotikScript($tenant, $peer),
            'pfsense'  => $prov->pfsenseSteps($tenant, $peer),
            'isSuper' => false,
            'activateAction' => '/admin?tenant=' . $tenant['slug'],
            'backUrl' => $base, 'backLabel' => '← Dashboard',
            'csrf' => $auth->csrfToken(), 'flash' => take_flash(), 'config' => $config,
        ]);
        return;
    }

    // ---- Load scoped data ----
    $q = fn($sql) => (function($s) use ($pdo, $tid) { $st = $pdo->prepare($s); $st->execute([$tid]); return $st->fetchAll(); })($sql);

    $vouchers = $q("SELECT * FROM vouchers WHERE tenant_id=? ORDER BY price ASC");
    $payments = $q("SELECT p.*, v.name AS voucher_name FROM payments p LEFT JOIN vouchers v ON v.id=p.voucher_id WHERE p.tenant_id=? ORDER BY p.id DESC");
    $active   = $q("SELECT * FROM radacct WHERE tenant_id=? AND acctstoptime IS NULL ORDER BY acctstarttime DESC");
    $since = date('Y-m-d H:i:s', time() - 86400);
    $recentStmt = $pdo->prepare("SELECT * FROM radacct WHERE tenant_id=? AND acctstarttime > ? ORDER BY acctstarttime DESC LIMIT 100");
    $recentStmt->execute([$tid, $since]); $recent = $recentStmt->fetchAll();

    // issued accounts assembled in PHP
    $rc = $q("SELECT username, attribute, value FROM radcheck WHERE tenant_id=?");
    $rr = $q("SELECT username, attribute, value FROM radreply WHERE tenant_id=?");
    $rg = $q("SELECT username, groupname FROM radusergroup WHERE tenant_id=?");
    $online = []; foreach ($active as $u) $online[$u['username']] = true;
    $issued = [];
    foreach ($rc as $r) {
        $u = $r['username'];
        $issued[$u] ??= ['username'=>$u,'password'=>'','timeout'=>0,'group'=>'','up'=>0,'down'=>0,'online'=>isset($online[$u])];
        if ($r['attribute']==='Cleartext-Password') $issued[$u]['password']=$r['value'];
    }
    foreach ($rr as $r) {
        if (!isset($issued[$r['username']])) continue;
        if ($r['attribute']==='Session-Timeout') $issued[$r['username']]['timeout']=(int)$r['value'];
        if ($r['attribute']==='pfSense-Bandwidth-Max-Up') $issued[$r['username']]['up']=(int)$r['value'];
        if ($r['attribute']==='pfSense-Bandwidth-Max-Down') $issued[$r['username']]['down']=(int)$r['value'];
    }
    foreach ($rg as $r) if (isset($issued[$r['username']])) $issued[$r['username']]['group']=$r['groupname'];
    $issued = array_values($issued);

    // KPIs + series
    $now = time(); $today = date('Y-m-d');
    $revTotal=0; $revToday=0; $sold=0; $status=['success'=>0,'pending'=>0,'failed'=>0]; $byPkg=[]; $byDay=[];
    for ($i=6;$i>=0;$i--) $byDay[date('Y-m-d',$now-$i*86400)]=0.0;
    foreach ($payments as $p) {
        $s=$p['status']??'pending'; if(isset($status[$s])) $status[$s]++;
        if ($s==='success') { $amt=(float)$p['amount']; $revTotal+=$amt; $sold++;
            $day=substr((string)$p['created_at'],0,10); if($day===$today)$revToday+=$amt; if(isset($byDay[$day]))$byDay[$day]+=$amt;
            $nm=$p['voucher_name']??('#'.$p['voucher_id']); $byPkg[$nm]=($byPkg[$nm]??0)+1; }
    }
    arsort($byPkg);
    $decided=$status['success']+$status['failed'];
    $successRate=$decided?round($status['success']/$decided*100):0;
    $inOct=0;$outOct=0; foreach ($active as $u){ $inOct+=(int)$u['acctinputoctets']; $outOct+=(int)$u['acctoutputoctets']; }

    $editVoucher=null;
    if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
        $e=$pdo->prepare("SELECT * FROM vouchers WHERE id=? AND tenant_id=?"); $e->execute([(int)$_GET['edit'],$tid]);
        $editVoucher=$e->fetch()?:null;
    }

    $subRow = $pdo->prepare("SELECT status, current_period_end FROM subscriptions WHERE tenant_id=?");
    $subRow->execute([$tid]); $sub = $subRow->fetch() ?: null;

    // ---- Network health (last 24h) ----
    $mStmt = $pdo->prepare("SELECT cpu_load, uptime_s, rx_bps, tx_bps, sampled_at FROM device_metrics WHERE tenant_id=? AND sampled_at > ? ORDER BY sampled_at ASC");
    $mStmt->execute([$tid, date('Y-m-d H:i:s', time() - 86400)]);
    $metrics = $mStmt->fetchAll();
    $latest = $metrics ? end($metrics) : null;
    $lastSampleAge = $latest ? max(0, time() - strtotime($latest['sampled_at'])) : null;
    $deviceOnline = $lastSampleAge !== null && $lastSampleAge < 600; // sample within 10 min
    $alStmt = $pdo->prepare("SELECT * FROM alerts WHERE tenant_id=? ORDER BY (resolved_at IS NULL) DESC, COALESCE(resolved_at, created_at) DESC LIMIT 30");
    $alStmt->execute([$tid]);
    $alerts = $alStmt->fetchAll();
    $openAlerts = array_filter($alerts, fn($a) => empty($a['resolved_at']));
    $nasRow = $pdo->prepare("SELECT type FROM nas WHERE tenant_id=? LIMIT 1");
    $nasRow->execute([$tid]); $nasType = $nasRow->fetchColumn() ?: 'other';

    echo view($VIEWS . '/admin.php', [
        'tenant'=>$tenant,'user'=>$auth->tenantUser($tid),'csrf'=>$auth->csrfToken(),'sub'=>$sub,
        'metrics'=>$metrics,'latest'=>$latest,'deviceOnline'=>$deviceOnline,'lastSampleAge'=>$lastSampleAge,
        'alerts'=>$alerts,'openAlerts'=>$openAlerts,'nasType'=>$nasType,
        'flash'=>take_flash(),'section'=>$_GET['s'] ?? ($editVoucher?'packages':'overview'),
        'vouchers'=>$vouchers,'payments'=>$payments,'active'=>$active,'recent'=>$recent,'issued'=>$issued,
        'editVoucher'=>$editVoucher,
        'kpi'=>compact('revTotal','revToday','sold','status','successRate','inOct','outOct')+['activeCount'=>count($active),'pkgCount'=>count($vouchers)],
        'byDay'=>$byDay,'byPkg'=>$byPkg,
    ]);
}
