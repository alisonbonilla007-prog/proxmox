<?php
// src/Monitor.php — poll a tenant router's health over the WireGuard tunnel.
//  - MikroTik: RouterOS REST API (/rest/system/resource, /interface)
//  - pfSense/other: SNMP (HOST-RESOURCES / IF-MIB) when the php-snmp ext is present
// Returns a normalized metrics array; raises/resolves alerts via thresholds.

class Monitor
{
    const CPU_WARN = 85;            // % sustained
    const RX_TX_WINDOW = 1;        // seconds between counter reads for bps

    public function __construct(private PDO $pdo) {}

    /** Poll one NAS row. Returns metrics or null if unreachable. */
    public function poll(array $nas): ?array
    {
        $host = $nas['nasname'];
        try {
            if ($nas['type'] === 'mikrotik' && $nas['mon_user'] !== '') {
                return $this->pollRouterOS($host, $nas['mon_user'], $nas['mon_pass']);
            }
            return $this->pollSnmp($host, $nas['snmp_community'] ?: 'public');
        } catch (Throwable $e) {
            error_log("monitor poll failed ({$host}): " . $e->getMessage());
            return null;
        }
    }

    private function pollRouterOS(string $host, string $user, string $pass): ?array
    {
        $res = $this->rosGet("https://{$host}/rest/system/resource", $user, $pass);
        if (!$res) return null;
        $cpu = (int)($res['cpu-load'] ?? 0);
        $uptime = $this->parseRosUptime($res['uptime'] ?? '0s');
        // throughput: read interface byte counters twice and diff
        [$rx, $tx] = $this->rosThroughput($host, $user, $pass);
        return ['cpu' => $cpu, 'uptime' => $uptime, 'rx' => $rx, 'tx' => $tx];
    }

    private function rosGet(string $url, string $user, string $pass): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "$user:$pass",
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, // routers use self-signed certs on the tunnel
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || $body === false) return null;
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }

    private function rosThroughput(string $host, string $user, string $pass): array
    {
        $sample = function () use ($host, $user, $pass) {
            $ifs = $this->rosGet("https://{$host}/rest/interface", $user, $pass) ?? [];
            $rx = 0; $tx = 0;
            foreach ($ifs as $i) {
                if (($i['running'] ?? 'false') !== 'true') continue;
                $rx += (int)($i['rx-byte'] ?? 0);
                $tx += (int)($i['tx-byte'] ?? 0);
            }
            return [$rx, $tx];
        };
        [$r1, $t1] = $sample();
        sleep(self::RX_TX_WINDOW);
        [$r2, $t2] = $sample();
        $w = max(1, self::RX_TX_WINDOW);
        return [max(0, ($r2 - $r1)) * 8 / $w, max(0, ($t2 - $t1)) * 8 / $w];
    }

    private function parseRosUptime(string $u): int
    {
        // formats like "5w2d3h4m5s"
        $map = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        preg_match_all('/(\d+)([wdhms])/', $u, $m, PREG_SET_ORDER);
        $s = 0; foreach ($m as $p) $s += (int)$p[1] * $map[$p[2]];
        return $s;
    }

    private function pollSnmp(string $host, string $community): ?array
    {
        if (!function_exists('snmp2_get')) {
            error_log('snmp extension not available; skipping ' . $host);
            return null;
        }
        $prev = error_reporting(0);
        // sysUpTime (timeticks, 1/100s)
        $up = @snmp2_get($host, $community, '1.3.6.1.2.1.1.3.0', 2_000_000, 1);
        // hrProcessorLoad average (.1.3.6.1.2.1.25.3.3.1.2)
        $cpuTab = @snmp2_walk($host, $community, '1.3.6.1.2.1.25.3.3.1.2', 2_000_000, 1);
        error_reporting($prev);
        if ($up === false) return null;
        $uptime = (int)round(((int)filter_var($up, FILTER_SANITIZE_NUMBER_INT)) / 100);
        $cpu = 0;
        if (is_array($cpuTab) && $cpuTab) {
            $vals = array_map(fn($v) => (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT), $cpuTab);
            $cpu = (int)round(array_sum($vals) / count($vals));
        }
        return ['cpu' => $cpu, 'uptime' => $uptime, 'rx' => 0, 'tx' => 0];
    }

    /** Persist a sample + evaluate thresholds for alerts. */
    public function record(int $tenantId, array $m): void
    {
        $this->pdo->prepare(
            "INSERT INTO device_metrics (tenant_id, cpu_load, uptime_s, rx_bps, tx_bps, sampled_at)
             VALUES (?, ?, ?, ?, ?, " . now_sql() . ")"
        )->execute([$tenantId, (int)$m['cpu'], (int)$m['uptime'], (int)$m['rx'], (int)$m['tx']]);

        $this->evaluate($tenantId, 'cpu', (int)$m['cpu'] >= self::CPU_WARN, 'warn',
            'Router CPU at ' . (int)$m['cpu'] . '%');
        // a successful poll resolves any open offline alert
        $this->resolve($tenantId, 'offline');
    }

    /** A failed poll: open/keep an offline alert. */
    public function recordOffline(int $tenantId): void
    {
        $this->evaluate($tenantId, 'offline', true, 'crit', 'Router unreachable over tunnel');
    }

    /** Open the alert if condition holds and none open; resolve it otherwise. */
    private function evaluate(int $tenantId, string $kind, bool $firing, string $sev, string $msg): void
    {
        if ($firing) {
            $open = $this->pdo->prepare("SELECT 1 FROM alerts WHERE tenant_id=? AND kind=? AND resolved_at IS NULL");
            $open->execute([$tenantId, $kind]);
            if (!$open->fetchColumn()) {
                $this->pdo->prepare("INSERT INTO alerts (tenant_id, severity, kind, message, created_at) VALUES (?,?,?,?," . now_sql() . ")")
                    ->execute([$tenantId, $sev, $kind, $msg]);
            }
        } else {
            $this->resolve($tenantId, $kind);
        }
    }

    private function resolve(int $tenantId, string $kind): void
    {
        $this->pdo->prepare("UPDATE alerts SET resolved_at=" . now_sql() . " WHERE tenant_id=? AND kind=? AND resolved_at IS NULL")
            ->execute([$tenantId, $kind]);
    }
}
