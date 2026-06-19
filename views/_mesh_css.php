<?php /* shared MESH design tokens + base components. $accent optional. */
$accent = $accent ?? '#25f4a7'; ?>
<link rel="preconnect" href="https://api.fontshare.com" crossorigin>
<link rel="stylesheet" media="print" onload="this.media='all'" href="https://api.fontshare.com/v2/css?f[]=clash-display@500,600,700&f[]=satoshi@400,500,700&f[]=jetbrains-mono@500&display=swap">
<noscript><link rel="stylesheet" href="https://api.fontshare.com/v2/css?f[]=clash-display@500,600,700&f[]=satoshi@400,500,700&f[]=jetbrains-mono@500&display=swap"></noscript>
<style>
:root{
  --bg:#060a09;--bg-2:#0a100e;--ink:#e9f6ef;--muted:#7d938a;--muted-2:#4d5f58;
  --accent:<?= htmlspecialchars($accent, ENT_QUOTES) ?>;--accent-2:#19c8ff;--danger:#ff5d6c;--warn:#ffcf5d;
  --line:rgba(255,255,255,.09);--glass:rgba(255,255,255,.035);--glass-2:rgba(255,255,255,.06);--radius:16px;
  --fd:'Clash Display',ui-sans-serif,system-ui,sans-serif;
  --fb:'Satoshi',ui-sans-serif,system-ui,-apple-system,sans-serif;
  --fm:'JetBrains Mono',ui-monospace,'SF Mono',monospace;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--fb);background:var(--bg);color:var(--ink);min-height:100vh;-webkit-font-smoothing:antialiased;line-height:1.5}
a{color:inherit;text-decoration:none}
.bg-glow{position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(60vw 60vw at 18% 6%,color-mix(in srgb,var(--accent) 16%,transparent),transparent 60%),radial-gradient(50vw 50vw at 85% 25%,rgba(25,200,255,.10),transparent 60%)}
.fd{font-family:var(--fd)}.mono{font-family:var(--fm);font-size:.9em}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.7rem 1.1rem;border:none;border-radius:11px;font-family:var(--fb);font-weight:700;font-size:.9rem;cursor:pointer;transition:transform .15s,background .2s}
.btn-primary{background:linear-gradient(100deg,var(--accent),#7af);color:#04140f;background:linear-gradient(100deg,var(--accent),color-mix(in srgb,var(--accent) 55%,#fff))}
.btn-primary:hover{transform:translateY(-2px)}
.btn-ghost{background:var(--glass-2);color:var(--ink);border:1px solid var(--line)}.btn-ghost:hover{background:rgba(255,255,255,.1)}
.btn-danger{background:rgba(255,93,108,.14);color:var(--danger);border:1px solid rgba(255,93,108,.3)}.btn-danger:hover{background:rgba(255,93,108,.24)}
.btn-sm{padding:.4rem .7rem;font-size:.78rem}
.card{background:var(--glass);border:1px solid var(--line);border-radius:var(--radius);backdrop-filter:blur(12px)}
.input,select.input{width:100%;padding:.8rem .95rem;border-radius:11px;background:rgba(0,0,0,.25);border:1px solid var(--line);color:var(--ink);font-family:var(--fb);font-size:.95rem}
.input:focus{outline:none;border-color:color-mix(in srgb,var(--accent) 60%,transparent);box-shadow:0 0 0 4px color-mix(in srgb,var(--accent) 12%,transparent)}
label.lbl{display:block;font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:999px;font-size:.7rem;font-weight:600}
.b-success,.b-online{background:rgba(37,244,167,.14);color:var(--accent);border:1px solid rgba(37,244,167,.3)}
.b-pending{background:rgba(255,207,93,.12);color:var(--warn);border:1px solid rgba(255,207,93,.3)}
.b-failed,.b-offline{background:rgba(255,93,108,.12);color:var(--danger);border:1px solid rgba(255,93,108,.3)}
.b-offline{background:rgba(255,255,255,.06);color:var(--muted);border:1px solid var(--line)}
.flash{padding:.85rem 1.1rem;border-radius:12px;margin-bottom:1.3rem;font-size:.9rem}
.flash-success{background:rgba(37,244,167,.1);border:1px solid rgba(37,244,167,.3);color:var(--accent)}
.flash-error{background:rgba(255,93,108,.1);border:1px solid rgba(255,93,108,.3);color:#ffb3ba}
table{width:100%;border-collapse:collapse;font-size:.85rem}
th{background:rgba(255,255,255,.04);color:var(--muted);text-align:left;padding:.75rem 1rem;font-weight:500;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
td{padding:.7rem 1rem;border-top:1px solid var(--line)}
tbody tr:hover{background:rgba(255,255,255,.025)}
.table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--radius)}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 0 rgba(37,244,167,.6);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(37,244,167,.5)}70%{box-shadow:0 0 0 8px rgba(37,244,167,0)}100%{box-shadow:0 0 0 0 rgba(37,244,167,0)}}
@media(prefers-reduced-motion:reduce){*{animation:none!important}}
</style>
