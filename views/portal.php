<?php /* tenant portal — vars: $tenant,$vouchers,$config,$gwctx,$routerKind */
$accent = $tenant['brand_color'] ?: '#25f4a7';
$slug = $tenant['slug'];
$qs = '?tenant=' . rawurlencode($slug); // keeps tenant scope for API calls in local/testing
$gwctx = $gwctx ?? ['gw'=>'','dst'=>'','mac'=>'','err'=>'','zone'=>'','redirurl'=>''];
$routerKind = $routerKind ?? 'other';
// We can hand the user straight onto the WiFi when we arrived via the router's
// captive portal: MikroTik gives a gateway login URL (gw); pfSense gives a zone.
$gwAction = $routerKind === 'pfsense' ? ($gwctx['gw'] ?: $gwctx['redirurl']) : $gwctx['gw'];
$hasGateway = $gwAction !== '';
$userField = $routerKind === 'pfsense' ? 'auth_user' : 'username';
$passField = $routerKind === 'pfsense' ? 'auth_pass' : 'password';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tenant['name']) ?> — Buy Internet</title>
<?php require __DIR__ . '/_mesh_css.php'; ?>
<style>
.bg-grid{position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(var(--line) 1px,transparent 1px),linear-gradient(90deg,var(--line) 1px,transparent 1px);background-size:64px 64px;mask-image:radial-gradient(ellipse 80% 70% at 50% 20%,#000 30%,transparent 80%);opacity:.3}
.wrap{position:relative;z-index:2;max-width:1080px;margin:0 auto;padding:0 1.4rem}
header{display:flex;align-items:center;justify-content:space-between;padding:1.5rem 0}
.brand{display:flex;align-items:center;gap:.6rem}
.brand .mark{width:38px;height:38px;border-radius:11px;background:color-mix(in srgb,var(--accent) 16%,transparent);border:1px solid var(--line);display:grid;place-items:center}
.brand .name{font-family:var(--fd);font-weight:700;letter-spacing:.04em;font-size:1.2rem}
.hero{padding:2.5rem 0 2rem}
.hero .eyebrow{font-family:var(--fm);font-size:.74rem;letter-spacing:.25em;text-transform:uppercase;color:var(--accent);margin-bottom:.8rem}
.hero h1{font-family:var(--fd);font-weight:600;font-size:clamp(2rem,5vw,3.2rem);line-height:1;margin-bottom:.8rem}
.hero p{color:var(--muted);max-width:46ch}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1.1rem;padding-bottom:3rem}
.v{position:relative;padding:1.4rem;border-radius:var(--radius);background:var(--glass);border:1px solid var(--line);transition:transform .25s,border-color .25s,box-shadow .25s;overflow:hidden}
.v:hover{transform:translateY(-5px);border-color:color-mix(in srgb,var(--accent) 45%,transparent);box-shadow:0 22px 44px -22px #000}
.v-name{font-family:var(--fd);font-weight:600;font-size:1.15rem;margin-bottom:.2rem}
.v-price{font-family:var(--fd);font-weight:700;font-size:2.1rem;line-height:1;margin:.4rem 0}
.v-price .c{font-size:.8rem;color:var(--muted);font-family:var(--fm);margin-right:.25rem;vertical-align:.7rem}
.v-stats{display:grid;gap:.5rem;margin:1rem 0 1.2rem;font-size:.86rem;color:var(--ink)}
.v-stats .k{color:var(--muted)}
.modal-ov{display:none;position:fixed;inset:0;z-index:100;background:rgba(3,6,5,.7);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:1.2rem}
.modal-ov.open{display:flex}
.modal{width:100%;max-width:430px;padding:1.9rem;position:relative;background:linear-gradient(180deg,rgba(16,24,21,.97),rgba(8,12,11,.97));border:1px solid var(--line);border-radius:20px}
.modal::before{content:'';position:absolute;top:0;left:1.5rem;right:1.5rem;height:1px;background:linear-gradient(90deg,transparent,var(--accent),var(--accent-2),transparent)}
.x{position:absolute;top:1rem;right:1.1rem;width:32px;height:32px;border-radius:9px;background:var(--glass-2);border:1px solid var(--line);color:var(--muted);font-size:1.2rem;cursor:pointer}
.step{display:none}.step.active{display:block}.center{text-align:center}
.m-price{font-family:var(--fd);font-weight:700;font-size:2rem;text-align:center}.m-price .c{font-size:.8rem;color:var(--muted);font-family:var(--fm)}
.m-name{text-align:center;color:var(--muted);margin:.2rem 0 1.3rem}
.spinner{width:46px;height:46px;margin:.4rem auto 1.2rem;border-radius:50%;border:3px solid color-mix(in srgb,var(--accent) 18%,transparent);border-top-color:var(--accent);animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.creds{background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:13px;padding:1rem;margin:1.1rem 0;text-align:left}
.creds .r{display:flex;justify-content:space-between;padding:.35rem 0}.creds .r+.r{border-top:1px solid var(--line)}
.creds .k{color:var(--muted);font-size:.82rem}.creds .v{font-family:var(--fm);color:var(--accent);font-weight:600}
.tiny{color:var(--muted-2);font-size:.76rem}.muted{color:var(--muted);font-size:.9rem}
.login-card{margin:0 0 3rem;padding:1.5rem;border-radius:var(--radius);background:var(--glass);border:1px solid var(--line)}
.login-inner{max-width:420px;margin:0 auto}
.login-head{margin-bottom:1rem}
.login-form{display:grid;gap:.7rem}
.btn-connect{width:100%;margin-top:.4rem}
.foot{text-align:center;padding:2rem 0 3rem;color:var(--muted-2);font-size:.78rem}
</style></head><body>
<div class="bg-glow"></div><div class="bg-grid"></div>
<div class="wrap">
  <header>
    <div class="brand"><span class="mark"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?= e($accent) ?>" stroke-width="2" stroke-linecap="round"><path d="M2 8.5a16 16 0 0 1 20 0"/><path d="M5 12a11 11 0 0 1 14 0"/><path d="M8.5 15.5a6 6 0 0 1 7 0"/><circle cx="12" cy="19" r="1.2" fill="<?= e($accent) ?>" stroke="none"/></svg></span>
    <span class="name"><?= e($tenant['name']) ?></span></div>
    <span class="badge b-online"><span class="dot"></span>&nbsp;Online</span>
  </header>
  <section class="hero">
    <div class="eyebrow">// pay with m-pesa · instant access</div>
    <h1>Get online in seconds.</h1>
    <p>Choose a package, pay with M-Pesa, and connect instantly. No contracts.</p>
  </section>
  <div class="grid">
    <?php if ($vouchers): foreach ($vouchers as $v): ?>
      <article class="v">
        <div class="v-name"><?= e($v['name']) ?></div>
        <div class="v-price"><span class="c">KES</span><?= number_format((float)$v['price'],0) ?></div>
        <div class="v-stats">
          <div><span class="k">Duration</span> · <?= e(fmtLimit($v['time_limit'])) ?></div>
          <div><span class="k">Speed</span> · <?= e(fmtSpeed($v['bandwidth_limit'])) ?></div>
          <div><span class="k">Data</span> · <?= e(fmtDataLimit($v['data_limit'])) ?></div>
        </div>
        <button class="btn btn-primary buy" style="width:100%"
          data-id="<?= (int)$v['id'] ?>" data-name="<?= e($v['name']) ?>" data-price="<?= number_format((float)$v['price'],2) ?>">Buy with M-Pesa</button>
      </article>
    <?php endforeach; else: ?>
      <div class="muted">No packages available yet.</div>
    <?php endif; ?>
  </div>

  <?php if ($hasGateway): ?>
  <section class="login-card">
    <div class="login-inner">
      <div class="login-head">
        <div class="v-name">Already have a voucher?</div>
        <div class="muted" style="font-size:.86rem">Enter your voucher details to get back online with your remaining time.</div>
      </div>
      <?php if ($gwctx['err'] !== ''): ?><div class="flash flash-error" style="margin:.6rem 0"><?= e($gwctx['err']) ?></div><?php endif; ?>
      <form class="login-form" method="post" action="<?= e($gwAction) ?>">
        <input type="hidden" name="dst" value="<?= e($gwctx['dst']) ?>">
        <input type="hidden" name="popup" value="true">
        <?php if ($routerKind === 'pfsense'): ?>
          <input type="hidden" name="accept" value="Continue">
          <input type="hidden" name="zone" value="<?= e($gwctx['zone']) ?>">
          <?php if ($gwctx['redirurl'] !== ''): ?><input type="hidden" name="redirurl" value="<?= e($gwctx['redirurl']) ?>"><?php endif; ?>
        <?php endif; ?>
        <input class="input" name="<?= e($userField) ?>" placeholder="Voucher username" autocomplete="username" required>
        <input class="input" name="<?= e($passField) ?>" type="text" placeholder="Voucher password" autocomplete="current-password" required>
        <button class="btn btn-primary" type="submit">Log in &amp; connect</button>
      </form>
    </div>
  </section>
  <?php endif; ?>
</div>
<div class="foot">Powered by MESH Cloud · <?= e($tenant['name']) ?></div>

<div class="modal-ov" id="ov">
  <div class="modal">
    <button class="x" id="x">&times;</button>
    <div class="step active" id="s-phone">
      <div class="m-price"><span class="c">KES</span><span id="m-price">0</span></div>
      <div class="m-name" id="m-name"></div>
      <div class="flash flash-error" id="m-err" style="display:none"></div>
      <form id="payf">
        <input type="hidden" name="voucher_id" id="m-vid">
        <label class="lbl">M-Pesa phone number</label>
        <input class="input" type="tel" name="phone" id="m-phone" required pattern="254[0-9]{9}" placeholder="2547XXXXXXXX" inputmode="numeric">
        <div class="tiny" style="margin-top:.4rem">Format: 2547XXXXXXXX</div>
        <button class="btn btn-primary" id="payb" style="width:100%;margin-top:1.2rem">Send payment request</button>
      </form>
    </div>
    <div class="step center" id="s-proc">
      <div class="spinner"></div>
      <h2 class="fd" style="font-weight:600">Check your phone</h2>
      <p class="muted" id="m-proc" style="margin:.8rem 0"></p>
      <div class="flash flash-success" id="m-stat" style="background:rgba(25,200,255,.1);border-color:rgba(25,200,255,.3);color:#aee9ff">Waiting for confirmation…</div>
      <p class="tiny">Enter your M-Pesa PIN. Keep this open.</p>
    </div>
    <div class="step center" id="s-ok">
      <h2 class="fd" style="font-weight:600">✅ Payment confirmed</h2>
      <p class="muted" style="margin-top:.3rem">Your voucher is ready — save these.</p>
      <div class="creds"><div class="r"><span class="k">Username</span><span class="v" id="m-user"></span></div><div class="r"><span class="k">Password</span><span class="v" id="m-pass"></span></div></div>
      <?php if ($hasGateway): ?>
        <button class="btn btn-primary btn-connect" id="connectBtn">Connect now →</button>
        <p class="tiny" style="margin-top:.6rem">Saves your voucher and puts you online.</p>
      <?php else: ?>
        <p class="tiny">Use these on the WiFi login page to connect.</p>
      <?php endif; ?>
    </div>
    <div class="step center" id="s-fail">
      <h2 class="fd" style="font-weight:600">❌ Payment failed</h2>
      <p class="muted" id="m-fail" style="margin:.6rem 0 1.2rem">Could not complete.</p>
      <button class="btn btn-primary" id="retry">Try again</button>
    </div>
  </div>
</div>
<script>
(function(){
  const QS=<?= json_encode($qs) ?>;
  // Captive-portal gateway handoff (empty unless we arrived via the router).
  const GW=<?= json_encode($gwAction) ?>, ROUTER=<?= json_encode($routerKind) ?>;
  const DST=<?= json_encode($gwctx['dst']) ?>, ZONE=<?= json_encode($gwctx['zone']) ?>, REDIR=<?= json_encode($gwctx['redirurl']) ?>;
  const UF=<?= json_encode($userField) ?>, PF=<?= json_encode($passField) ?>;
  let creds=null;
  // Log the freshly-issued voucher into the gateway by POSTing a real form to it
  // (full navigation, so no cross-origin fetch). Field names vary by router.
  function connectNow(){
    if(!GW||!creds) return;
    const fm=document.createElement('form'); fm.method='post'; fm.action=GW;
    const add=(n,v)=>{const i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;fm.appendChild(i);};
    add(UF,creds.u); add(PF,creds.p);
    if(ROUTER==='pfsense'){add('accept','Continue'); add('zone',ZONE); if(REDIR)add('redirurl',REDIR);}
    else {add('dst',DST); add('popup','true');}
    document.body.appendChild(fm); fm.submit();
  }
  const cbtn=document.getElementById('connectBtn'); if(cbtn) cbtn.addEventListener('click',connectNow);
  const ov=document.getElementById('ov'),f=document.getElementById('payf'),err=document.getElementById('m-err'),pb=document.getElementById('payb');
  const steps={phone:'s-phone',proc:'s-proc',ok:'s-ok',fail:'s-fail'};let timer=null;
  const show=k=>{Object.values(steps).forEach(id=>document.getElementById(id).classList.remove('active'));document.getElementById(steps[k]).classList.add('active');};
  function open(b){document.getElementById('m-name').textContent=b.dataset.name;document.getElementById('m-price').textContent=b.dataset.price;err.style.display='none';f.reset();document.getElementById('m-vid').value=b.dataset.id;show('phone');ov.classList.add('open');setTimeout(()=>document.getElementById('m-phone').focus(),80);}
  function close(){ov.classList.remove('open');if(timer){clearTimeout(timer);timer=null;}}
  document.querySelectorAll('.buy').forEach(b=>b.addEventListener('click',()=>open(b)));
  document.getElementById('x').addEventListener('click',close);
  ov.addEventListener('click',e=>{if(e.target===ov)close();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
  document.getElementById('retry').addEventListener('click',()=>{err.style.display='none';show('phone');});
  f.addEventListener('submit',function(e){e.preventDefault();err.style.display='none';pb.disabled=true;pb.textContent='Sending…';
    fetch('initiate_payment.php'+QS,{method:'POST',body:new FormData(f)}).then(r=>r.json()).then(d=>{
      pb.disabled=false;pb.textContent='Send payment request';
      if(!d.success){err.textContent=d.error||'Could not start payment.';err.style.display='block';return;}
      document.getElementById('m-proc').innerHTML='Request sent to <b style="color:var(--ink)">'+d.phone+'</b> for <b style="color:var(--ink)">KES '+Number(d.amount).toFixed(2)+'</b>';
      show('proc');poll(d.payment_id);
    }).catch(()=>{pb.disabled=false;pb.textContent='Send payment request';err.textContent='Network error.';err.style.display='block';});
  });
  function poll(id){let n=0;const max=30;(function chk(){
    fetch('check_payment.php'+QS+'&payment_id='+encodeURIComponent(id)).then(r=>r.json()).then(d=>{
      if(d.status==='success'){creds={u:d.username||'',p:d.password||''};document.getElementById('m-user').textContent=creds.u;document.getElementById('m-pass').textContent=creds.p;show('ok');}
      else if(d.status==='failed'){document.getElementById('m-fail').textContent='The payment was cancelled or declined.';show('fail');}
      else if(n>=max){document.getElementById('m-fail').textContent='Payment timed out. If charged, contact support.';show('fail');}
      else{n++;timer=setTimeout(chk,3000);}
    }).catch(()=>{if(n>=max){document.getElementById('m-fail').textContent='Connection error.';show('fail');}else{n++;timer=setTimeout(chk,3000);}});
  })();}
})();
</script>
</body></html>
