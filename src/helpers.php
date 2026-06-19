<?php
// src/helpers.php — shared formatting + small view helpers (ported from the
// single-tenant app so the SaaS keeps consistent output).

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }

function kes($n): string { return 'KES ' . number_format((float)$n, 0); }

function fmtBytes($bytes): string {
    $b = (float)$bytes;
    if ($b <= 0) return '0 B';
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return round($b, 2) . ' ' . $u[$i];
}

function fmtDataLimit($bytes): string {
    return (int)$bytes <= 0 ? 'Unlimited' : fmtBytes($bytes);
}

function fmtDuration($seconds): string {
    $s = (int)$seconds;
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $sec);
}

function fmtLimit($seconds): string {
    $s = (int)$seconds;
    if ($s <= 0) return 'No limit';
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    if ($d) return $h ? "{$d}d {$h}h" : "{$d}d";
    if ($h && $m) return "{$h}h {$m}m";
    if ($h) return "{$h}h";
    return "{$m}m";
}

function fmtUptime($seconds): string {
    $s = (int)$seconds;
    if ($s <= 0) return '—';
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    if ($d) return "{$d}d {$h}h";
    if ($h) return "{$h}h {$m}m";
    return "{$m}m";
}

function fmtBps($bps): string {
    $b = (float)$bps;
    if ($b <= 0) return '0 bps';
    $u = ['bps','Kbps','Mbps','Gbps']; $i = 0;
    while ($b >= 1000 && $i < count($u) - 1) { $b /= 1000; $i++; }
    return round($b, 1) . ' ' . $u[$i];
}

/** Display speed from a "up/down" bandwidth string (returns the download side). */
function fmtSpeed(string $bw): string {
    if ($bw === '' ) return 'Unlimited';
    if (strpos($bw, '/') !== false) { [$u, $d] = explode('/', $bw, 2); return trim($d) . 'bps'; }
    return trim($bw) . 'bps';
}

/** Render a PHP file as a string with $data extracted into scope. */
function view(string $file, array $data = []): string {
    extract($data, EXTR_SKIP);
    ob_start();
    require $file;
    return (string)ob_get_clean();
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Portable "current timestamp" SQL literal for the active driver. */
function now_sql(): string {
    return (getenv('DB_DRIVER') ?: 'sqlite') === 'mysql' ? 'NOW()' : "datetime('now')";
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/**
 * Create a tenant + owner + settings + trial subscription, and provision a
 * WireGuard peer/NAS slot. Shared by superadmin create and public signup.
 * @return array{0:?int,1:?string}  [tenantId, errorMessage]
 */
function provision_new_tenant(PDO $pdo, array $config, string $name, string $slug, string $password, string $color = '#25f4a7', ?string $contact = null): array {
    $slug = slugify($slug ?: $name);
    if ($name === '' || $slug === '' || strlen($password) < 6) {
        return [null, 'Business name, slug, and a 6+ character password are required.'];
    }
    $exists = $pdo->prepare("SELECT 1 FROM tenants WHERE slug = ?");
    $exists->execute([$slug]);
    if ($exists->fetchColumn()) return [null, 'That slug is already taken.'];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO tenants (name, slug, brand_color, contact) VALUES (?, ?, ?, ?)")
            ->execute([$name, $slug, $color ?: '#25f4a7', $contact]);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO tenant_users (tenant_id, username, password_hash, role) VALUES (?, 'owner', ?, 'owner')")
            ->execute([$tid, password_hash($password, PASSWORD_BCRYPT)]);
        $pdo->prepare("INSERT INTO tenant_settings (tenant_id) VALUES (?)")->execute([$tid]);
        // 14-day trial
        $end = date('Y-m-d H:i:s', time() + 14 * 86400);
        $pdo->prepare("INSERT INTO subscriptions (tenant_id, plan, status, current_period_end) VALUES (?, 'starter', 'trial', ?)")
            ->execute([$tid, $end]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('provision_new_tenant: ' . $e->getMessage());
        return [null, 'Could not create the account. Please try again.'];
    }
    // WG peer + NAS (outside the txn; ensurePeer is idempotent)
    (new Provision($pdo, $config))->ensurePeer(['id' => $tid, 'slug' => $slug, 'name' => $name]);
    return [$tid, null];
}
