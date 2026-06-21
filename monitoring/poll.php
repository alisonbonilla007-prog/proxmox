<?php
// monitoring/poll.php — poll every connected tenant router and store health.
// Cron (every 2 min):
//   */2 * * * *  DB_DRIVER=mysql DB_*=... php /path/SAAS/monitoring/poll.php
//
// Local/demo without real routers:
//   DB_DRIVER=sqlite php monitoring/poll.php --simulate
//
// Prunes samples older than RETAIN_DAYS so device_metrics stays bounded.

const RETAIN_DAYS = 14;

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Monitor.php';

$pdo = Database::pdo($config);
$monitor = new Monitor($pdo);
$simulate = in_array('--simulate', $argv, true);

// Reconcile peer status with reality from the WireGuard hub before deciding who
// to poll: a recent handshake => 'connected'; a long-idle peer => 'activated'.
if (!$simulate) sync_wg_handshakes($pdo, getenv('WG_IFACE') ?: 'wg0');

// Only poll tenants whose tunnel is up and who are active.
$nasList = $pdo->query(
    "SELECT n.* FROM nas n
       JOIN tenants t   ON t.id = n.tenant_id
       JOIN wg_peers w  ON w.tenant_id = n.tenant_id
     WHERE t.status = 'active' AND w.status = 'connected' AND w.public_key <> ''
     GROUP BY n.tenant_id"
)->fetchAll();

$ok = 0; $down = 0;
foreach ($nasList as $nas) {
    $tid = (int)$nas['tenant_id'];
    $m = $simulate ? simulate_sample($tid) : $monitor->poll($nas);
    if ($m === null) {
        $monitor->recordOffline($tid);
        $down++;
        fwrite(STDOUT, "  tenant {$tid} ({$nas['shortname']}): DOWN\n");
        continue;
    }
    $monitor->record($tid, $m);
    $ok++;
    fwrite(STDOUT, "  tenant {$tid} ({$nas['shortname']}): cpu={$m['cpu']}% up={$m['uptime']}s\n");
}

// prune old metrics
$cut = date('Y-m-d H:i:s', time() - RETAIN_DAYS * 86400);
$del = $pdo->prepare("DELETE FROM device_metrics WHERE sampled_at < ?");
$del->execute([$cut]);

fwrite(STDOUT, "[" . date('c') . "] poll: ok={$ok} down={$down} pruned={$del->rowCount()}"
    . ($simulate ? " (simulated)" : "") . "\n");

/**
 * Promote/demote wg_peers.status from live WireGuard handshakes on the hub.
 * Reads `wg show <iface> latest-handshakes` (root, on the VM). A handshake
 * within 5 min => 'connected'; a peer that was connected but has gone quiet
 * for 15 min => 'activated' (key still present, tunnel idle/down). Silently
 * no-ops where wg/shell_exec is unavailable (dev boxes), so polling degrades
 * gracefully rather than erroring.
 */
function sync_wg_handshakes(PDO $pdo, string $iface = 'wg0'): void {
    if (!function_exists('shell_exec')) return;
    $out = @shell_exec('wg show ' . escapeshellarg($iface) . ' latest-handshakes 2>/dev/null');
    if (!is_string($out) || trim($out) === '') return;

    $now = time();
    $hs = []; // public_key => last-handshake unix ts
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2 || $parts[0] === '') continue;
        $hs[$parts[0]] = (int)$parts[1];
    }

    $peers = $pdo->query("SELECT id, public_key, status FROM wg_peers WHERE public_key <> ''")->fetchAll();
    $up = $pdo->prepare("UPDATE wg_peers SET status='connected', last_handshake=? WHERE id=?");
    $down = $pdo->prepare("UPDATE wg_peers SET status='activated' WHERE id=?");
    foreach ($peers as $p) {
        $ts = $hs[$p['public_key']] ?? 0;
        $age = $ts > 0 ? $now - $ts : null;
        if ($age !== null && $age < 300) {
            $up->execute([date('Y-m-d H:i:s', $ts), $p['id']]);
        } elseif ($p['status'] === 'connected' && ($age === null || $age >= 900)) {
            $down->execute([$p['id']]);
        }
    }
}

/** Plausible synthetic sample for --simulate (varies by tenant + clock). */
function simulate_sample(int $tid): ?array {
    $t = (int)date('i');                 // minute of the hour for variation
    if ($tid === 2 && $t % 7 === 0) return null;  // occasionally show a tenant offline
    return [
        'cpu' => 10 + ($tid * 13 + $t * 3) % 80,
        'uptime' => 86400 * (3 + $tid) + $t * 60,
        'rx' => (30 + ($t * 7 + $tid) % 70) * 1_000_000,
        'tx' => (5 + ($t * 3 + $tid) % 25) * 1_000_000,
    ];
}
