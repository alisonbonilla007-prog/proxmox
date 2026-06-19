<?php /* apex landing — vars: $config */ $accent = '#25f4a7'; ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MESH Cloud — Hotspot management</title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
body{display:grid;place-items:center;padding:2rem;text-align:center}
.hero{position:relative;z-index:2;max-width:640px}
h1{font-family:var(--fd);font-weight:600;font-size:clamp(2.2rem,6vw,3.6rem);line-height:1;margin-bottom:1rem}
h1 .g{background:linear-gradient(100deg,var(--accent),var(--accent-2));-webkit-background-clip:text;background-clip:text;color:transparent}
p{color:var(--muted);font-size:1.05rem;max-width:46ch;margin:0 auto 1.8rem}
.row{display:flex;gap:.8rem;justify-content:center;flex-wrap:wrap}
.eyebrow{font-family:var(--fm);font-size:.75rem;letter-spacing:.25em;text-transform:uppercase;color:var(--accent);margin-bottom:1rem}
.note{margin-top:2rem;font-size:.8rem;color:var(--muted-2);font-family:var(--fm)}
</style></head><body>
<div class="bg-glow"></div>
<div class="hero">
  <div class="eyebrow">// hotspot operating system</div>
  <h1>Run your WiFi business <span class="g">from the cloud.</span></h1>
  <p>Connect any MikroTik or pfSense router, sell vouchers via M-Pesa, and monitor every site from one dashboard — secured over a private WireGuard tunnel.</p>
  <div class="row">
    <a class="btn btn-primary" href="/signup">Start free →</a>
    <a class="btn btn-ghost" href="/superadmin">Operator console</a>
  </div>
  <div class="note">Tenant portals live at &lt;slug&gt;.<?= e($config['app_domain']) ?> · locally try /?tenant=swiftnet</div>
</div>
</body></html>
