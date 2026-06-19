<?php /* tenant admin — vars: $tenant,$user,$csrf,$flash,$section,$vouchers,$payments,
   $active,$recent,$issued,$editVoucher,$kpi,$byDay,$byPkg */
$accent = $tenant['brand_color'] ?: '#25f4a7';
$slug = $tenant['slug'];
$base = '/admin?tenant=' . $slug;
$maxDay = max(1, max($byDay ?: [0]));
$maxPkg = $byPkg ? max($byPkg) : 1;
$payTot = max(1, array_sum($kpi['status']));
$NAV = ['overview'=>'Overview','network'=>'Network','packages'=>'Packages','payments'=>'Payments','sessions'=>'Live Sessions','issued'=>'Issued Vouchers','settings'=>'Settings'];
// network chart geometry
$cpuMax = 100;
$rxMax = 1; foreach ($metrics as $mm) $rxMax = max($rxMax, (int)$mm['rx_bps'], (int)$mm['tx_bps']);
$openCount = count($openAlerts);
$st = $tenant['settings'];
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tenant['name']) ?> — Admin</title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
.layout{position:relative;z-index:2;display:grid;grid-template-columns:240px 1fr;min-height:100vh}
.side{border-right:1px solid var(--line);padding:1.3rem 1rem;display:flex;flex-direction:column;gap:.3rem;position:sticky;top:0;height:100vh;background:rgba(8,12,11,.6);backdrop-filter:blur(10px)}
.sb{display:flex;align-items:center;gap:.6rem;padding:.3rem .6rem 1.1rem}
.sb .mark{width:32px;height:32px;border-radius:9px;background:color-mix(in srgb,var(--accent) 14%,transparent);border:1px solid var(--line);display:grid;place-items:center}
.sb .nm{font-family:var(--fd);font-weight:700;font-size:1rem}
.nav{display:flex;align-items:center;gap:.6rem;padding:.65rem .8rem;border-radius:10px;color:var(--muted);font-weight:500;font-size:.9rem;cursor:pointer;border:1px solid transparent;width:100%;text-align:left;background:none;font-family:inherit}
.nav:hover{background:var(--glass);color:var(--ink)}.nav.active{background:var(--glass-2);color:var(--ink);border-color:var(--line)}
.foot{margin-top:auto;border-top:1px solid var(--line);padding-top:1rem;font-size:.8rem;color:var(--muted)}
.logout{display:block;text-align:center;padding:.5rem;border-radius:9px;background:var(--glass);border:1px solid var(--line);color:var(--muted);font-size:.82rem;margin-top:.5rem}
.main{padding:1.6rem 2rem 4rem;max-width:1200px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.6rem;flex-wrap:wrap;gap:.8rem}
.top h1{font-family:var(--fd);font-weight:600;font-size:1.5rem}
.pane{display:none}.pane.active{display:block}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:1.4rem}
.kpi{padding:1.1rem}.kpi .l{font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem}.kpi .v{font-family:var(--fd);font-weight:700;font-size:1.7rem}.kpi .s{font-size:.76rem;color:var(--muted);margin-top:.3rem}
.cards{display:grid;grid-template-columns:1.4fr 1fr;gap:1rem;margin-bottom:1.4rem}@media(max-width:900px){.cards{grid-template-columns:1fr}.layout{grid-template-columns:1fr}.side{position:fixed;z-index:50;transform:translateX(-100%);transition:.25s;width:240px}.side.open{transform:none}.menu{display:inline-flex!important}}
.panel{padding:1.2rem}.panel h3{font-family:var(--fd);font-weight:600;font-size:1rem;margin-bottom:1rem;display:flex;justify-content:space-between}.panel h3 .t{font-family:var(--fm);font-size:.7rem;color:var(--muted);font-weight:400}
.bars{display:flex;align-items:flex-end;gap:.5rem;height:140px}.bcol{flex:1;display:flex;flex-direction:column;align-items:center;gap:.35rem;height:100%;justify-content:flex-end}
.bar{width:100%;max-width:32px;border-radius:6px 6px 0 0;background:linear-gradient(180deg,var(--accent),color-mix(in srgb,var(--accent) 25%,transparent));min-height:2px}
.bl{font-size:.64rem;color:var(--muted);font-family:var(--fm)}.bv{font-size:.62rem;color:var(--ink);font-family:var(--fm)}
.sbar{display:flex;height:13px;border-radius:7px;overflow:hidden;margin-bottom:.9rem;background:rgba(255,255,255,.05)}.sbar span{height:100%}
.legend{display:flex;gap:1rem;flex-wrap:wrap;font-size:.8rem;color:var(--muted)}.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:.35rem}
.hrow{display:grid;grid-template-columns:110px 1fr 30px;align-items:center;gap:.6rem;margin-bottom:.55rem;font-size:.82rem}
.htrack{height:9px;background:rgba(255,255,255,.06);border-radius:6px;overflow:hidden}.hfill{height:100%;background:linear-gradient(90deg,var(--accent-2),var(--accent));border-radius:6px}
.toolbar{display:flex;gap:.7rem;flex-wrap:wrap;margin-bottom:1rem}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.1rem}
.menu{display:none}
</style></head><body>
<div class="bg-glow"></div>
<div class="layout">
  <aside class="side" id="side">
    <div class="sb"><span class="mark"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="<?= e($accent) ?>" stroke-width="2" stroke-linecap="round"><path d="M2 8.5a16 16 0 0 1 20 0"/><path d="M5 12a11 11 0 0 1 14 0"/><path d="M8.5 15.5a6 6 0 0 1 7 0"/></svg></span><span class="nm"><?= e($tenant['name']) ?></span></div>
    <?php foreach ($NAV as $k=>$lbl): ?><button class="nav <?= $section===$k?'active':'' ?>" data-p="<?= $k ?>"><?= $lbl ?></button><?php endforeach; ?>
    <a class="nav" href="/admin/onboarding?tenant=<?= e($slug) ?>" style="text-decoration:none">🛰 Connect router</a>
    <div class="foot"><div class="mono"><span class="dot"></span> <?= e($user['name']) ?></div><a class="logout" href="/admin/logout?tenant=<?= e($slug) ?>">Sign out</a></div>
  </aside>
  <main class="main">
    <div class="top"><div><button class="btn btn-ghost btn-sm menu" id="menu" style="margin-bottom:.5rem">☰</button><h1 id="ttl">Overview</h1></div>
      <div class="mono" style="color:var(--muted)"><span class="dot"></span> <?= date('D d M · H:i') ?></div></div>
    <?php if ($flash): ?><div class="flash flash-<?= $flash['type']==='success'?'success':'error' ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <?php if (($sub['status'] ?? '') === 'past_due'): ?>
      <div class="flash" style="background:rgba(255,207,93,.1);border:1px solid rgba(255,207,93,.3);color:var(--warn)">
        ⚠ Your subscription is past due<?= $sub['current_period_end'] ? ' (expired ' . e(substr($sub['current_period_end'],0,10)) . ')' : '' ?>. Renew to avoid your portal being suspended.</div>
    <?php endif; ?>
    <?php if ($openCount > 0 && $section !== 'network'): ?>
      <div class="flash" style="background:rgba(255,93,108,.1);border:1px solid rgba(255,93,108,.3);color:#ffb3ba">
        🔔 <?= $openCount ?> open network alert<?= $openCount>1?'s':'' ?> — <a href="/admin/onboarding?tenant=<?= e($slug) ?>" style="color:inherit;text-decoration:underline" onclick="event.preventDefault();document.querySelector('.nav[data-p=network]').click()">view Network</a>.</div>
    <?php endif; ?>

    <!-- Overview -->
    <section class="pane <?= $section==='overview'?'active':'' ?>" id="p-overview">
      <div class="kpis">
        <div class="kpi card"><div class="l">Active users</div><div class="v"><?= $kpi['activeCount'] ?></div><div class="s">connected now</div></div>
        <div class="kpi card"><div class="l">Revenue today</div><div class="v"><?= kes($kpi['revToday']) ?></div><div class="s"><?= kes($kpi['revTotal']) ?> all-time</div></div>
        <div class="kpi card"><div class="l">Vouchers sold</div><div class="v"><?= $kpi['sold'] ?></div><div class="s"><?= $kpi['pkgCount'] ?> packages</div></div>
        <div class="kpi card"><div class="l">Success rate</div><div class="v"><?= $kpi['successRate'] ?>%</div><div class="s"><?= $kpi['status']['success'] ?> ok · <?= $kpi['status']['failed'] ?> failed</div></div>
        <div class="kpi card"><div class="l">Data down</div><div class="v" style="font-size:1.3rem"><?= fmtBytes($kpi['inOct']) ?></div></div>
        <div class="kpi card"><div class="l">Data up</div><div class="v" style="font-size:1.3rem"><?= fmtBytes($kpi['outOct']) ?></div></div>
      </div>
      <div class="cards">
        <div class="panel card"><h3>Revenue <span class="t">last 7 days</span></h3>
          <div class="bars"><?php foreach ($byDay as $day=>$amt): $h=round($amt/$maxDay*118); ?>
            <div class="bcol"><span class="bv"><?= $amt>0?number_format($amt,0):'' ?></span><div class="bar" style="height:<?= max(2,$h) ?>px"></div><span class="bl"><?= date('D',strtotime($day)) ?></span></div>
          <?php endforeach; ?></div>
        </div>
        <div class="panel card"><h3>Payments <span class="t"><?= array_sum($kpi['status']) ?> total</span></h3>
          <div class="sbar"><span style="width:<?= $kpi['status']['success']/$payTot*100 ?>%;background:var(--accent)"></span><span style="width:<?= $kpi['status']['pending']/$payTot*100 ?>%;background:var(--warn)"></span><span style="width:<?= $kpi['status']['failed']/$payTot*100 ?>%;background:var(--danger)"></span></div>
          <div class="legend"><span><i style="background:var(--accent)"></i>Success <?= $kpi['status']['success'] ?></span><span><i style="background:var(--warn)"></i>Pending <?= $kpi['status']['pending'] ?></span><span><i style="background:var(--danger)"></i>Failed <?= $kpi['status']['failed'] ?></span></div>
          <h3 style="margin-top:1.4rem">Top packages</h3>
          <?php if ($byPkg): foreach (array_slice($byPkg,0,5,true) as $nm=>$ct): ?>
            <div class="hrow"><span style="color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($nm) ?></span><div class="htrack"><div class="hfill" style="width:<?= round($ct/$maxPkg*100) ?>%"></div></div><span class="mono" style="text-align:right"><?= $ct ?></span></div>
          <?php endforeach; else: ?><div style="color:var(--muted)">No sales yet.</div><?php endif; ?>
        </div>
      </div>
    </section>

    <!-- Network -->
    <section class="pane <?= $section==='network'?'active':'' ?>" id="p-network">
      <?php if (!$metrics): ?>
        <div class="panel card" style="text-align:center;padding:2.5rem">
          <div class="fd" style="font-size:1.1rem;margin-bottom:.5rem">No router telemetry yet</div>
          <p style="color:var(--muted)">Connect your router and enable the monitoring user, then health data appears here within a couple of minutes.</p>
          <a class="btn btn-primary" href="/admin/onboarding?tenant=<?= e($slug) ?>" style="margin-top:1rem">🛰 Connect router</a>
        </div>
      <?php else:
        $cpuNow=(int)($latest['cpu_load']??0); ?>
        <div class="kpis">
          <div class="kpi card"><div class="l">Router</div><div class="v" style="font-size:1.1rem"><span class="badge <?= $deviceOnline?'b-online':'b-failed' ?>"><?= $deviceOnline?'Online':'Offline' ?></span></div><div class="s"><?= e(ucfirst($nasType)) ?> · sample <?= $lastSampleAge!==null?fmtUptime($lastSampleAge).' ago':'—' ?></div></div>
          <div class="kpi card"><div class="l">CPU load</div><div class="v"><?= $cpuNow ?>%</div><div class="s"><?= $cpuNow>=85?'<span style=\"color:var(--warn)\">high</span>':'nominal' ?></div></div>
          <div class="kpi card"><div class="l">Uptime</div><div class="v" style="font-size:1.3rem"><?= e(fmtUptime($latest['uptime_s']??0)) ?></div></div>
          <div class="kpi card"><div class="l">Throughput ↓/↑</div><div class="v" style="font-size:1.1rem"><?= e(fmtBps($latest['rx_bps']??0)) ?></div><div class="s"><?= e(fmtBps($latest['tx_bps']??0)) ?> up</div></div>
          <div class="kpi card"><div class="l">Open alerts</div><div class="v"><?= $openCount ?></div><div class="s"><?= $openCount?'<span style=\"color:var(--warn)\">needs attention</span>':'all clear' ?></div></div>
        </div>
        <div class="cards">
          <div class="panel card"><h3>CPU load <span class="t">last 24h</span></h3>
            <div class="bars"><?php foreach (array_slice($metrics,-24) as $mm): $h=round((int)$mm['cpu_load']/$cpuMax*118); $hi=(int)$mm['cpu_load']>=85; ?>
              <div class="bcol"><div class="bar" style="height:<?= max(2,$h) ?>px;<?= $hi?'background:linear-gradient(180deg,var(--warn),rgba(255,207,93,.25))':'' ?>"></div></div>
            <?php endforeach; ?></div>
            <div class="mono" style="color:var(--muted-2);font-size:.7rem;text-align:right;margin-top:.4rem">0–100%</div>
          </div>
          <div class="panel card"><h3>Throughput <span class="t">↓ download, last 24h</span></h3>
            <div class="bars"><?php foreach (array_slice($metrics,-24) as $mm): $h=round((int)$mm['rx_bps']/$rxMax*118); ?>
              <div class="bcol"><div class="bar" style="height:<?= max(2,$h) ?>px;background:linear-gradient(180deg,var(--accent-2),rgba(25,200,255,.2))"></div></div>
            <?php endforeach; ?></div>
            <div class="mono" style="color:var(--muted-2);font-size:.7rem;text-align:right;margin-top:.4rem">peak <?= e(fmtBps($rxMax)) ?></div>
          </div>
        </div>
        <div class="panel card">
          <h3>Alerts</h3>
          <div class="table-wrap"><table><thead><tr><th>Severity</th><th>Type</th><th>Message</th><th>Raised</th><th>State</th></tr></thead><tbody>
            <?php if ($alerts): foreach ($alerts as $al): $open=empty($al['resolved_at']); ?>
              <tr><td><span class="badge <?= $al['severity']==='crit'?'b-failed':($al['severity']==='warn'?'b-pending':'b-offline') ?>"><?= e($al['severity']) ?></span></td>
              <td class="mono"><?= e($al['kind']) ?></td><td><?= e($al['message']) ?></td>
              <td class="mono" style="color:var(--muted)"><?= e($al['created_at']) ?></td>
              <td><span class="badge <?= $open?'b-pending':'b-online' ?>"><?= $open?'Active':'Resolved' ?></span></td></tr>
            <?php endforeach; else: ?><tr><td colspan="5" style="text-align:center;padding:1.5rem;color:var(--muted)">No alerts — router healthy.</td></tr><?php endif; ?>
          </tbody></table></div>
        </div>
      <?php endif; ?>
    </section>

    <!-- Packages -->
    <section class="pane <?= $section==='packages'?'active':'' ?>" id="p-packages">
      <div class="panel card" style="margin-bottom:1.3rem">
        <h3><?= $editVoucher?'Edit package':'Add a package' ?></h3>
        <form method="post" action="<?= e($base) ?>">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="<?= $editVoucher?'edit_voucher':'add_voucher' ?>">
          <?php if ($editVoucher): ?><input type="hidden" name="id" value="<?= (int)$editVoucher['id'] ?>"><?php endif; ?>
          <div class="form-grid">
            <div><label class="lbl">Name *</label><input class="input" name="name" required value="<?= $editVoucher?e($editVoucher['name']):'' ?>"></div>
            <div><label class="lbl">Price KES *</label><input class="input" type="number" step="0.01" min="0" name="price" required value="<?= $editVoucher?e($editVoucher['price']):'' ?>"></div>
            <div><label class="lbl">Time limit (s)</label><input class="input" type="number" min="0" name="time_limit" value="<?= $editVoucher?e($editVoucher['time_limit']):'' ?>" placeholder="300 = 5min"></div>
            <div><label class="lbl">Bandwidth up/down</label><input class="input" name="bandwidth_limit" value="<?= $editVoucher?e($editVoucher['bandwidth_limit']):'' ?>" placeholder="10M/10M"></div>
            <div><label class="lbl">Data limit (bytes)</label><input class="input" type="number" min="0" name="data_limit" value="<?= $editVoucher?e($editVoucher['data_limit']):'' ?>" placeholder="0 = unlimited"></div>
          </div>
          <button class="btn btn-primary" type="submit"><?= $editVoucher?'Save changes':'Add package' ?></button>
          <?php if ($editVoucher): ?><a class="btn btn-ghost" href="<?= e($base) ?>&s=packages">Cancel</a><?php endif; ?>
        </form>
      </div>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Price</th><th>Duration</th><th>Bandwidth</th><th>Data</th><th>Sold</th><th></th></tr></thead><tbody>
        <?php if ($vouchers): foreach ($vouchers as $v): $soldN=$byPkg[$v['name']]??0; ?>
          <tr><td class="mono"><?= (int)$v['id'] ?></td><td><strong><?= e($v['name']) ?></strong></td><td><?= kes($v['price']) ?></td>
          <td><?= e(fmtLimit($v['time_limit'])) ?></td><td class="mono"><?= e($v['bandwidth_limit']?:'—') ?></td><td><?= e(fmtDataLimit($v['data_limit'])) ?></td><td class="mono"><?= $soldN ?></td>
          <td style="white-space:nowrap"><a class="btn btn-ghost btn-sm" href="<?= e($base) ?>&s=packages&edit=<?= (int)$v['id'] ?>">Edit</a>
            <form method="post" action="<?= e($base) ?>" style="display:inline" onsubmit="return confirm('Delete <?= e($v['name']) ?>?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="delete_voucher"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn btn-danger btn-sm">Delete</button></form></td></tr>
        <?php endforeach; else: ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted)">No packages yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <!-- Payments -->
    <section class="pane <?= $section==='payments'?'active':'' ?>" id="p-payments">
      <div class="toolbar"><input class="input" id="paySearch" style="flex:1;min-width:200px" placeholder="🔍 phone, voucher, username…" onkeyup="fpay()">
        <select class="input" id="payStatus" style="width:auto" onchange="fpay()"><option value="">All</option><option>success</option><option>pending</option><option>failed</option></select></div>
      <div class="table-wrap"><table id="payT"><thead><tr><th>#</th><th>Voucher</th><th>Phone</th><th>Amount</th><th>Status</th><th>Username</th><th>Date</th></tr></thead><tbody>
        <?php if ($payments): foreach ($payments as $p): $s=$p['status']??'pending'; ?>
          <tr data-s="<?= e($s) ?>"><td class="mono"><?= (int)$p['id'] ?></td><td><?= e($p['voucher_name']??('#'.$p['voucher_id'])) ?></td><td class="mono"><?= e($p['phone']) ?></td><td><?= kes($p['amount']) ?></td><td><span class="badge b-<?= e($s) ?>"><?= e(ucfirst($s)) ?></span></td><td class="mono"><?= e($p['username']?:'—') ?></td><td class="mono" style="color:var(--muted)"><?= e($p['created_at']) ?></td></tr>
        <?php endforeach; else: ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No payments yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <!-- Sessions -->
    <section class="pane <?= $section==='sessions'?'active':'' ?>" id="p-sessions">
      <div class="table-wrap"><table><thead><tr><th>Username</th><th>IP</th><th>MAC</th><th>Started</th><th>Duration</th><th>Down</th><th>Up</th><th></th></tr></thead><tbody>
        <?php if ($active): foreach ($active as $u): ?>
          <tr><td class="mono"><?= e($u['username']) ?></td><td class="mono"><?= e($u['framedipaddress']) ?></td><td class="mono"><?= e($u['callingstationid']) ?></td><td class="mono" style="color:var(--muted)"><?= e($u['acctstarttime']) ?></td><td class="mono"><?= fmtDuration($u['acctsessiontime']) ?></td><td><?= fmtBytes($u['acctinputoctets']) ?></td><td><?= fmtBytes($u['acctoutputoctets']) ?></td>
          <td><form method="post" action="<?= e($base) ?>" style="display:inline" onsubmit="return confirm('Disconnect <?= e($u['username']) ?>?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="kick"><input type="hidden" name="username" value="<?= e($u['username']) ?>"><input type="hidden" name="sessionid" value="<?= e($u['acctsessionid']) ?>"><button class="btn btn-danger btn-sm">Kick</button></form></td></tr>
        <?php endforeach; else: ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--muted)">No active users.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <!-- Issued -->
    <section class="pane <?= $section==='issued'?'active':'' ?>" id="p-issued">
      <div class="table-wrap"><table><thead><tr><th>Username</th><th>Password</th><th>Group</th><th>Session limit</th><th>Bandwidth ↑/↓</th><th>State</th><th></th></tr></thead><tbody>
        <?php if ($issued): foreach ($issued as $a): ?>
          <tr><td class="mono"><?= e($a['username']) ?></td><td class="mono"><?= e($a['password']?:'—') ?></td><td><?= e($a['group']?:'—') ?></td><td><?= e(fmtLimit($a['timeout'])) ?></td><td class="mono"><?= $a['up']?fmtBytes($a['up']):'—' ?> / <?= $a['down']?fmtBytes($a['down']):'—' ?></td><td><span class="badge <?= $a['online']?'b-online':'b-offline' ?>"><?= $a['online']?'Online':'Offline' ?></span></td>
          <td><form method="post" action="<?= e($base) ?>" style="display:inline" onsubmit="return confirm('Revoke <?= e($a['username']) ?>?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="revoke_account"><input type="hidden" name="username" value="<?= e($a['username']) ?>"><button class="btn btn-danger btn-sm">Revoke</button></form></td></tr>
        <?php endforeach; else: ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--muted)">No vouchers issued yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <!-- Settings -->
    <section class="pane <?= $section==='settings'?'active':'' ?>" id="p-settings">
      <div class="panel card" style="max-width:680px">
        <h3>M-Pesa (your Daraja account)</h3>
        <form method="post" action="<?= e($base) ?>">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_mpesa">
          <div class="form-grid">
            <div><label class="lbl">Environment</label><select class="input" name="mpesa_env"><option value="sandbox" <?= ($st['mpesa_env']??'')!=='production'?'selected':'' ?>>Sandbox</option><option value="production" <?= ($st['mpesa_env']??'')==='production'?'selected':'' ?>>Production</option></select></div>
            <div><label class="lbl">Shortcode</label><input class="input" name="shortcode" value="<?= e($st['shortcode']??'') ?>"></div>
            <div><label class="lbl">Timeout (s)</label><input class="input" type="number" name="timeout" value="<?= e($st['timeout']??30) ?>"></div>
            <div style="grid-column:1/-1"><label class="lbl">Callback base URL</label><input class="input" name="callback_base" value="<?= e($st['callback_base']??'') ?>" placeholder="https://<?= e($slug) ?>.<?= e(($tenant['settings']['__domain']??'') ?: 'meshcloud.example') ?>"></div>
            <div><label class="lbl">Consumer key</label><input class="input" name="consumer_key" value="<?= e($st['consumer_key']??'') ?>"></div>
            <div><label class="lbl">Consumer secret</label><input class="input" name="consumer_secret" value="<?= e($st['consumer_secret']??'') ?>"></div>
            <div style="grid-column:1/-1"><label class="lbl">Passkey</label><input class="input" name="passkey" value="<?= e($st['passkey']??'') ?>"></div>
          </div>
          <button class="btn btn-primary" type="submit">Save M-Pesa settings</button>
        </form>
      </div>
    </section>
  </main>
</div>
<script>
  const titles=<?= json_encode($NAV) ?>;
  function showPane(k){document.querySelectorAll('.pane').forEach(p=>p.classList.remove('active'));document.querySelectorAll('.nav').forEach(b=>b.classList.remove('active'));
    const p=document.getElementById('p-'+k);if(p)p.classList.add('active');document.querySelectorAll('.nav[data-p="'+k+'"]').forEach(b=>b.classList.add('active'));
    document.getElementById('ttl').textContent=titles[k]||'Overview';history.replaceState(null,'','<?= e($base) ?>&s='+k);document.getElementById('side').classList.remove('open');}
  document.querySelectorAll('.nav').forEach(b=>b.addEventListener('click',()=>showPane(b.dataset.p)));
  document.getElementById('menu').addEventListener('click',()=>document.getElementById('side').classList.toggle('open'));
  function fpay(){const q=document.getElementById('paySearch').value.toLowerCase(),s=document.getElementById('payStatus').value;
    document.querySelectorAll('#payT tbody tr').forEach(tr=>{const okT=tr.textContent.toLowerCase().includes(q),okS=!s||tr.dataset.s===s;tr.style.display=(okT&&okS)?'':'none';});}
</script>
</body></html>
