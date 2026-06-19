<?php /* signup — vars: $csrf,$flash,$config */ $accent = '#25f4a7'; ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Start free — MESH Cloud</title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
body{display:grid;place-items:center;padding:1.5rem}
.box{position:relative;z-index:2;width:100%;max-width:460px;padding:2.1rem}
.box::before{content:'';position:absolute;top:0;left:1.5rem;right:1.5rem;height:1px;background:linear-gradient(90deg,transparent,var(--accent),var(--accent-2),transparent)}
h1{font-family:var(--fd);font-weight:600;font-size:1.5rem}.sub{color:var(--muted);font-size:.9rem;margin-bottom:1.4rem}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}@media(max-width:520px){.grid{grid-template-columns:1fr}}
label.lbl{margin-top:1rem}button{width:100%;margin-top:1.5rem}
.fine{margin-top:1rem;text-align:center;font-size:.78rem;color:var(--muted-2)}
.fine a{color:var(--accent-2)}
</style></head><body>
<div class="bg-glow"></div>
<form class="box card" method="post" action="/signup">
  <h1>Start your hotspot</h1>
  <div class="sub">14-day free trial · connect a MikroTik or pfSense router in minutes.</div>
  <?php if ($flash): ?><div class="flash flash-<?= $flash['type']==='success'?'success':'error' ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <div class="grid">
    <div><label class="lbl">Business name</label><input class="input" name="name" required placeholder="Acme WiFi"></div>
    <div><label class="lbl">Subdomain</label><input class="input" name="slug" placeholder="acme"><div class="mono" style="color:var(--muted-2);font-size:.7rem;margin-top:.3rem">.<?= e($config['app_domain']) ?></div></div>
  </div>
  <label class="lbl">Contact email</label><input class="input" type="email" name="contact" placeholder="you@acme.co.ke">
  <div class="grid">
    <div><label class="lbl">Owner password</label><input class="input" type="password" name="password" required minlength="6" placeholder="6+ characters"></div>
    <div><label class="lbl">Brand color</label><input class="input" type="text" name="brand_color" value="#25f4a7"></div>
  </div>
  <button class="btn btn-primary" type="submit">Create my hotspot →</button>
  <div class="fine">Already have an account? Sign in at <span class="mono">&lt;slug&gt;.<?= e($config['app_domain']) ?>/admin</span></div>
</form>
</body></html>
