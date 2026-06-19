<?php /* shared login — vars: $title,$subtitle,$action,$csrf,$flash,$accent */ ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
body{display:grid;place-items:center;padding:1.5rem}
.box{position:relative;z-index:2;width:100%;max-width:380px;padding:2.1rem}
.box::before{content:'';position:absolute;top:0;left:1.5rem;right:1.5rem;height:1px;background:linear-gradient(90deg,transparent,var(--accent),var(--accent-2),transparent)}
.brand{display:flex;align-items:center;gap:.6rem;margin-bottom:1.5rem}
.mark{width:38px;height:38px;border-radius:11px;background:color-mix(in srgb,var(--accent) 14%,transparent);border:1px solid var(--line);display:grid;place-items:center}
h1{font-family:var(--fd);font-weight:600;font-size:1.3rem}.sub{color:var(--muted);font-size:.85rem;margin-bottom:1.4rem}
.box label.lbl{margin-top:1rem}
button{width:100%;margin-top:1.5rem}
</style></head><body>
<div class="bg-glow"></div>
<form class="box card" method="post" action="<?= e($action) ?>">
  <div class="brand"><span class="mark"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?= e($accent) ?>" stroke-width="2" stroke-linecap="round"><path d="M2 8.5a16 16 0 0 1 20 0"/><path d="M5 12a11 11 0 0 1 14 0"/><path d="M8.5 15.5a6 6 0 0 1 7 0"/><circle cx="12" cy="19" r="1.2" fill="<?= e($accent) ?>" stroke="none"/></svg></span>
  <span class="fd" style="font-weight:700;letter-spacing:.12em"><?= $title ?></span></div>
  <h1>Sign in</h1><div class="sub"><?= $subtitle ?></div>
  <?php if ($flash && $flash['type']==='error'): ?><div class="flash flash-error"><?= e($flash['msg']) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <label class="lbl">Username</label><input class="input" name="username" required autofocus autocomplete="username">
  <label class="lbl">Password</label><input class="input" type="password" name="password" required autocomplete="current-password">
  <button class="btn btn-primary" type="submit">Sign in →</button>
</form>
</body></html>
