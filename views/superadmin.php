<?php /* superadmin fleet console — vars: $tenants,$csrf,$flash,$admin,$config */
$accent = '#19c8ff';
$totalRev = array_sum(array_map(fn($t)=>(float)$t['revenue'], $tenants));
$totalOnline = array_sum(array_map(fn($t)=>(int)$t['online'], $tenants));
$totalAlerts = array_sum(array_map(fn($t)=>(int)($t['open_alerts']??0), $tenants));
$routersUp = 0;
foreach ($tenants as $t) { if (!empty($t['last_sample']) && (time() - strtotime($t['last_sample'])) < 600) $routersUp++; }
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MESH Cloud — Operator</title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
.wrap{position:relative;z-index:2;max-width:1180px;margin:0 auto;padding:1.6rem 1.6rem 4rem}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.8rem;flex-wrap:wrap;gap:1rem}
.top h1{font-family:var(--fd);font-weight:600;font-size:1.6rem}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.6rem}
.kpi{padding:1.2rem}.kpi .l{font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}
.kpi .v{font-family:var(--fd);font-weight:700;font-size:1.8rem}
.panel{padding:1.4rem;margin-bottom:1.4rem}
.panel h2{font-family:var(--fd);font-weight:600;font-size:1.15rem;margin-bottom:1rem}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
@media(max-width:760px){.grid2,.form-grid{grid-template-columns:1fr}}
</style></head><body>
<div class="bg-glow"></div>
<div class="wrap">
  <div class="top">
    <h1 class="fd">🛰 Fleet Console</h1>
    <div style="display:flex;gap:.6rem;align-items:center"><span class="mono" style="color:var(--muted)"><span class="dot"></span> <?= e($admin) ?></span><a class="btn btn-ghost btn-sm" href="/superadmin/logout">Sign out</a></div>
  </div>
  <?php if ($flash): ?><div class="flash flash-<?= $flash['type']==='success'?'success':'error' ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="kpis">
    <div class="kpi card"><div class="l">Tenants</div><div class="v"><?= count($tenants) ?></div></div>
    <div class="kpi card"><div class="l">Routers reporting</div><div class="v"><?= $routersUp ?></div><div class="mono" style="color:var(--muted-2);font-size:.7rem">of <?= count($tenants) ?></div></div>
    <div class="kpi card"><div class="l">Users online</div><div class="v"><?= $totalOnline ?></div></div>
    <div class="kpi card"><div class="l">Open alerts</div><div class="v" style="<?= $totalAlerts?'color:var(--warn)':'' ?>"><?= $totalAlerts ?></div></div>
    <div class="kpi card"><div class="l">Voucher revenue (all)</div><div class="v" style="font-size:1.4rem"><?= kes($totalRev) ?></div></div>
  </div>

  <?php if ($alerts): ?>
  <div class="panel card">
    <h2>🔔 Active alerts</h2>
    <div class="table-wrap"><table><thead><tr><th>Tenant</th><th>Severity</th><th>Type</th><th>Message</th><th>Raised</th></tr></thead><tbody>
      <?php foreach ($alerts as $al): ?>
        <tr><td><a style="color:var(--accent-2)" href="/superadmin/onboarding?tenant_id=<?= (int)$al['tenant_id'] ?>"><?= e($al['tenant_name']) ?></a></td>
        <td><span class="badge <?= $al['severity']==='crit'?'b-failed':'b-pending' ?>"><?= e($al['severity']) ?></span></td>
        <td class="mono"><?= e($al['kind']) ?></td><td><?= e($al['message']) ?></td>
        <td class="mono" style="color:var(--muted)"><?= e($al['created_at']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>

  <div class="grid2">
    <div class="panel card">
      <h2>Tenants</h2>
      <div class="table-wrap">
        <table><thead><tr><th>Tenant</th><th>Pkgs</th><th>Revenue</th><th>Online</th><th>Tunnel</th><th>Router</th><th>Subscription</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($tenants as $t): $sub = $t['sub_status'] ?: 'none';
          $subClass = $sub==='active'||$sub==='trial' ? 'b-success' : ($sub==='past_due' ? 'b-pending' : 'b-failed');
          $rUp = !empty($t['last_sample']) && (time() - strtotime($t['last_sample'])) < 600;
          $cpu = $t['last_cpu'];
          $wgStatus = $t['wg_status'] ?: 'none';
          $hsAge = !empty($t['wg_handshake']) ? (time() - strtotime($t['wg_handshake'])) : null; ?>
          <tr>
            <td><strong><?= e($t['name']) ?></strong><div class="mono" style="color:var(--muted-2);font-size:.72rem"><a style="color:var(--accent-2)" href="/?tenant=<?= e($t['slug']) ?>"><?= e($t['slug']) ?> ↗</a></div></td>
            <td class="mono"><?= (int)$t['pkgs'] ?></td>
            <td><?= kes($t['revenue']) ?></td>
            <td class="mono"><?= (int)$t['online'] ?></td>
            <td><span class="badge <?= $wgStatus==='connected'?'b-online':($wgStatus==='activated'?'b-pending':'b-failed') ?>"><?= e($wgStatus) ?></span><?php if ($hsAge!==null): ?><div class="mono" style="color:var(--muted-2);font-size:.68rem">⇄ <?= e(fmtUptime($hsAge)) ?> ago</div><?php endif; ?></td>
            <td><?php if ($rUp): ?><span class="badge b-online">up</span> <span class="mono" style="color:<?= (int)$cpu>=85?'var(--warn)':'var(--muted)' ?>"><?= (int)$cpu ?>%</span><?php else: ?><span class="badge b-offline">—</span><?php endif; ?><?php if (!empty($t['nas_type']) && $t['nas_type']!=='other'): ?> <span class="mono" style="color:var(--muted-2);font-size:.68rem"><?= e($t['nas_type']) ?></span><?php endif; ?><?php if ((int)($t['open_alerts']??0)): ?> <span class="badge b-pending"><?= (int)$t['open_alerts'] ?>⚠</span><?php endif; ?></td>
            <td><span class="badge <?= $subClass ?>"><?= e($sub) ?></span><div class="mono" style="color:var(--muted-2);font-size:.68rem"><?= e($t['period_end'] ? substr($t['period_end'],0,10) : '—') ?></div></td>
            <td><span class="badge <?= $t['status']==='active'?'b-success':'b-failed' ?>"><?= e($t['status']) ?></span></td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="/superadmin/onboarding?tenant_id=<?= (int)$t['id'] ?>">Onboard</a>
              <form method="post" action="/superadmin" style="display:inline" title="Extend subscription 30 days">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="extend_sub">
                <input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="days" value="30">
                <button class="btn btn-ghost btn-sm" type="submit">+30d</button>
              </form>
              <form method="post" action="/superadmin" style="display:inline">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="set_status">
                <input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="status" value="<?= $t['status']==='active'?'suspended':'active' ?>">
                <button class="btn btn-sm <?= $t['status']==='active'?'btn-danger':'btn-ghost' ?>" type="submit"><?= $t['status']==='active'?'Suspend':'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>

    <div class="panel card">
      <h2>New tenant</h2>
      <form method="post" action="/superadmin">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_tenant">
        <div class="form-grid">
          <div><label class="lbl">Business name</label><input class="input" name="name" required placeholder="Acme WiFi"></div>
          <div><label class="lbl">Slug (subdomain)</label><input class="input" name="slug" placeholder="acme"></div>
          <div><label class="lbl">Owner password</label><input class="input" type="text" name="owner_password" required minlength="6" placeholder="6+ chars"></div>
          <div><label class="lbl">Brand color</label><input class="input" type="text" name="brand_color" value="#25f4a7"></div>
        </div>
        <button class="btn btn-primary" type="submit">Create tenant + provision tunnel</button>
        <p class="mono" style="color:var(--muted-2);font-size:.72rem;margin-top:.8rem">Creates the tenant, owner login (owner / your password), M-Pesa settings, and a WireGuard peer slot.</p>
      </form>
    </div>
  </div>
</div>
</body></html>
