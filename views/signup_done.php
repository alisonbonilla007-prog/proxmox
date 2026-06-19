<?php /* signup done — vars: $tenant,$config,$flash */ $accent = $tenant['brand_color'] ?: '#25f4a7';
$slug = $tenant['slug'];
$local = (stripos($_SERVER['HTTP_HOST'] ?? '', $config['app_domain']) === false); // running on localhost
$adminUrl  = $local ? "/admin?tenant={$slug}"  : "https://{$slug}.{$config['app_domain']}/admin";
$portalUrl = $local ? "/?tenant={$slug}"        : "https://{$slug}.{$config['app_domain']}/";
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Welcome — <?= e($tenant['name']) ?></title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
body{display:grid;place-items:center;padding:1.5rem}
.box{position:relative;z-index:2;width:100%;max-width:520px;padding:2.1rem;text-align:center}
.check{width:60px;height:60px;margin:0 auto 1rem;border-radius:50%;background:color-mix(in srgb,var(--accent) 14%,transparent);border:1px solid var(--line);display:grid;place-items:center;color:var(--accent)}
h1{font-family:var(--fd);font-weight:600;font-size:1.6rem}.sub{color:var(--muted);margin:.4rem 0 1.4rem}
.kv{text-align:left;background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:12px;padding:1rem;margin-bottom:1.2rem}
.kv .r{display:flex;justify-content:space-between;padding:.35rem 0;font-size:.88rem}.kv .r+.r{border-top:1px solid var(--line)}
.kv .k{color:var(--muted)}.kv .v{font-family:var(--fm);color:var(--ink)}
.row{display:flex;gap:.7rem;justify-content:center;flex-wrap:wrap}
.steps{text-align:left;color:var(--muted);font-size:.9rem;margin:1.2rem 0;line-height:1.9}
.steps b{color:var(--ink)}
</style></head><body>
<div class="bg-glow"></div>
<div class="box card">
  <div class="check"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg></div>
  <h1><?= e($tenant['name']) ?> is live</h1>
  <div class="sub">Your 14-day trial has started.</div>
  <div class="kv">
    <div class="r"><span class="k">Admin login</span><span class="v">owner / (your password)</span></div>
    <div class="r"><span class="k">Admin URL</span><span class="v"><?= e($adminUrl) ?></span></div>
    <div class="r"><span class="k">Customer portal</span><span class="v"><?= e($portalUrl) ?></span></div>
  </div>
  <div class="steps">
    <div><b>1.</b> Sign in to your admin and add your M-Pesa (Daraja) keys under <b>Settings</b>.</div>
    <div><b>2.</b> Open <b>Connect router</b>, paste the MikroTik script (or follow the pfSense steps).</div>
    <div><b>3.</b> Paste the router's public key back to activate the tunnel — then create packages.</div>
  </div>
  <div class="row">
    <a class="btn btn-primary" href="<?= e($adminUrl) ?>">Go to my admin →</a>
    <a class="btn btn-ghost" href="<?= e($portalUrl) ?>">View portal</a>
  </div>
</div>
</body></html>
