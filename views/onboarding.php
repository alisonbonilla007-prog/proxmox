<?php /* onboarding — vars: $tenant,$peer,$mikrotik,$pfsense,$csrf,$flash,$config
   optional: $isSuper,$serverPeer,$serverCmd,$activateAction,$backUrl,$backLabel */
$accent = $tenant['brand_color'] ?? '#19c8ff';
$isSuper = $isSuper ?? false;
$serverPeer = $serverPeer ?? '';
$serverCmd = $serverCmd ?? '';
$activateAction = $activateAction ?? '/superadmin';
$backUrl = $backUrl ?? '/superadmin';
$backLabel = $backLabel ?? '← Fleet console';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Onboard <?= e($tenant['name']) ?> — MESH Cloud</title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
.wrap{position:relative;z-index:2;max-width:920px;margin:0 auto;padding:1.6rem 1.6rem 4rem}
h1{font-family:var(--fd);font-weight:600;font-size:1.5rem;margin-bottom:.3rem}
.sub{color:var(--muted);margin-bottom:1.4rem}
.panel{padding:1.3rem;margin-bottom:1.3rem}
.panel h2{font-family:var(--fd);font-weight:600;font-size:1.05rem;margin-bottom:.8rem}
.kv{display:grid;grid-template-columns:160px 1fr;gap:.4rem 1rem;font-size:.86rem;margin-bottom:1rem}
.kv .k{color:var(--muted)}.kv .v{font-family:var(--fm);color:var(--ink);word-break:break-all}
pre{background:rgba(0,0,0,.4);border:1px solid var(--line);border-radius:12px;padding:1rem;overflow:auto;font-family:var(--fm);font-size:.78rem;line-height:1.5;max-height:360px;white-space:pre-wrap}
.tabs{display:flex;gap:.5rem;margin-bottom:1rem}
.tab{padding:.5rem .9rem;border-radius:10px;background:var(--glass);border:1px solid var(--line);cursor:pointer;font-size:.85rem;color:var(--muted)}
.tab.active{background:var(--glass-2);color:var(--ink)}
.copy{float:right}
.tabpane{display:none}.tabpane.active{display:block}
</style></head><body>
<div class="bg-glow"></div>
<div class="wrap">
  <a class="mono" style="color:var(--muted)" href="<?= e($backUrl) ?>"><?= e($backLabel) ?></a>
  <h1 style="margin-top:.8rem">Onboard <?= e($tenant['name']) ?></h1>
  <div class="sub">Plug-and-play config for the tenant's router. Tunnel status:
    <span class="badge <?= $peer['public_key']?'b-online':'b-pending' ?>"><?= $peer['public_key']?'activated':'awaiting router key' ?></span></div>
  <?php if ($flash): ?><div class="flash flash-<?= $flash['type']==='success'?'success':'error' ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="panel card">
    <h2>Connection parameters</h2>
    <div class="kv">
      <span class="k">Portal URL</span><span class="v">https://<?= e($tenant['slug']) ?>.<?= e($config['app_domain']) ?></span>
      <span class="k">Hub endpoint</span><span class="v"><?= e($peer['endpoint']) ?></span>
      <span class="k">Hub public key</span><span class="v"><?= e($peer['server_pubkey']) ?></span>
      <span class="k">Tunnel IP (router)</span><span class="v"><?= e($peer['assigned_ip']) ?>/16</span>
      <span class="k">RADIUS server</span><span class="v"><?= e($config['wg']['radius_ip']) ?></span>
    </div>
  </div>

  <div class="panel card">
    <h2>1 · Configure the router</h2>
    <div class="tabs">
      <div class="tab active" data-t="mt">MikroTik (.rsc)</div>
      <div class="tab" data-t="pf">pfSense (steps)</div>
    </div>
    <div class="tabpane active" id="t-mt">
      <button class="btn btn-ghost btn-sm copy" onclick="cp('mt')">Copy</button>
      <pre id="mt"><?= e($mikrotik) ?></pre>
    </div>
    <div class="tabpane" id="t-pf">
      <button class="btn btn-ghost btn-sm copy" onclick="cp('pf')">Copy</button>
      <pre id="pf"><?= e($pfsense) ?></pre>
    </div>
  </div>

  <div class="panel card">
    <h2>2 · Activate the tunnel</h2>
    <p class="sub">After running the config, the router prints its <b>public key</b>. Paste it here to activate the peer on the hub.</p>
    <form method="post" action="<?= e($activateAction) ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="activate_peer">
      <?php if ($isSuper): ?><input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>"><?php endif; ?>
      <label class="lbl">Router public key</label>
      <input class="input" name="public_key" placeholder="base64 public key from the router" value="<?= e($peer['public_key']) ?>">
      <button class="btn btn-primary" type="submit" style="margin-top:1rem">Activate peer</button>
    </form>
  </div>

  <?php if ($isSuper): ?>
  <div class="panel card">
    <h2>3 · Hub side (operator)</h2>
    <p class="sub">Add this peer to the WireGuard hub. Either drop the block into <span class="mono">wg0.conf</span> or apply it live with the command (no restart).</p>
    <button class="btn btn-ghost btn-sm copy" onclick="cp('sp')">Copy</button>
    <pre id="sp"><?= e($serverPeer) ?></pre>
    <label class="lbl" style="margin-top:.6rem">Live apply</label>
    <pre><?= e($serverCmd) ?></pre>
  </div>
  <?php endif; ?>
</div>
<script>
  document.querySelectorAll('.tab').forEach(t=>t.addEventListener('click',()=>{
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.tabpane').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');document.getElementById('t-'+t.dataset.t).classList.add('active');
  }));
  function cp(id){navigator.clipboard.writeText(document.getElementById(id).textContent);}
</script>
</body></html>
