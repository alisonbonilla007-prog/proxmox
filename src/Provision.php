<?php
// src/Provision.php — generates plug-and-play onboarding artifacts for a
// tenant router: a WireGuard peer slot on the hub, a MikroTik .rsc script,
// and pfSense setup steps.
//
// Key handling: the ROUTER generates its own WireGuard keypair (the private
// key never leaves the device). Our scripts create the tunnel and print the
// router's public key; the tenant pastes it back on the onboarding page to
// activate the peer on the hub. This keeps onboarding near one-paste while
// staying secure.

class Provision
{
    public function __construct(private PDO $pdo, private array $config) {}

    /** Ensure a wg_peers + nas row exists for the tenant; return the peer row. */
    public function ensurePeer(array $tenant): array
    {
        $tid = (int)$tenant['id'];
        $st = $this->pdo->prepare("SELECT * FROM wg_peers WHERE tenant_id = ? ORDER BY id LIMIT 1");
        $st->execute([$tid]);
        $peer = $st->fetch();
        if ($peer) return $peer;

        $assignedIp = '10.66.' . $tid . '.1';
        $wg = $this->config['wg'];
        $this->pdo->prepare(
            "INSERT INTO wg_peers (tenant_id, public_key, server_pubkey, assigned_ip, endpoint, allowed_ips, status)
             VALUES (?, '', ?, ?, ?, ?, 'pending')"
        )->execute([$tid, $wg['server_pubkey'], $assignedIp, $wg['endpoint'], $wg['subnet']]);

        // matching NAS (RADIUS client) entry keyed on the tunnel IP
        $exists = $this->pdo->prepare("SELECT 1 FROM nas WHERE tenant_id = ? AND nasname = ?");
        $exists->execute([$tid, $assignedIp]);
        if (!$exists->fetchColumn()) {
            $this->pdo->prepare(
                "INSERT INTO nas (tenant_id, nasname, shortname, type, secret, mon_user, mon_pass, snmp_community)
                 VALUES (?, ?, ?, 'other', ?, 'mesh-monitor', ?, ?)"
            )->execute([$tid, $assignedIp, $tenant['slug'], bin2hex(random_bytes(8)), bin2hex(random_bytes(6)), 'mesh-' . bin2hex(random_bytes(3))]);
        }
        $st->execute([$tid]);
        return $st->fetch();
    }

    /** Persist the router's public key + mark connected-pending. */
    public function savePublicKey(int $tenantId, string $pubkey): bool
    {
        $pubkey = trim($pubkey);
        if (!preg_match('#^[A-Za-z0-9+/]{42,44}={0,2}$#', $pubkey)) return false;
        $st = $this->pdo->prepare("UPDATE wg_peers SET public_key = ? WHERE tenant_id = ?");
        return $st->execute([$pubkey, $tenantId]);
    }

    public function nasSecret(int $tenantId): string
    {
        return (string)($this->nasRow($tenantId)['secret'] ?? '');
    }

    private function nasRow(int $tenantId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM nas WHERE tenant_id = ? ORDER BY id LIMIT 1");
        $st->execute([$tenantId]);
        return $st->fetch() ?: [];
    }

    private function vars(array $tenant, array $peer): array
    {
        $wg = $this->config['wg'];
        $portalHost = $tenant['slug'] . '.' . $this->config['app_domain'];
        $nas = $this->nasRow((int)$tenant['id']);
        return [
            '{{SLUG}}'          => $tenant['slug'],
            '{{TENANT_NAME}}'   => $tenant['name'],
            '{{ASSIGNED_IP}}'   => $peer['assigned_ip'],
            '{{HUB_ENDPOINT}}'  => $wg['endpoint'],
            '{{SERVER_PUBKEY}}' => $wg['server_pubkey'],
            '{{ALLOWED_IPS}}'   => $wg['subnet'],
            '{{RADIUS_IP}}'     => $wg['radius_ip'],
            '{{RADIUS_SECRET}}' => $nas['secret'] ?? '',
            '{{MON_USER}}'      => $nas['mon_user'] ?: 'mesh-monitor',
            '{{MON_PASS}}'      => $nas['mon_pass'] ?: 'change-me',
            '{{SNMP_COMMUNITY}}'=> $nas['snmp_community'] ?: 'mesh-ro',
            '{{PORTAL_HOST}}'   => $portalHost,
            '{{PORTAL_URL}}'    => 'https://' . $portalHost,
        ];
    }

    public function mikrotikScript(array $tenant, array $peer): string
    {
        $tpl = file_get_contents(__DIR__ . '/../provisioning/templates/mikrotik.rsc.tpl');
        return strtr($tpl, $this->vars($tenant, $peer));
    }

    public function pfsenseSteps(array $tenant, array $peer): string
    {
        $tpl = file_get_contents(__DIR__ . '/../provisioning/templates/pfsense-setup.md.tpl');
        return strtr($tpl, $this->vars($tenant, $peer));
    }

    /**
     * The WireGuard [Peer] block to add on the hub for this tenant's router.
     * Drop it into the hub's wg0.conf (or apply with the wg command below).
     */
    public function serverPeerBlock(array $tenant, array $peer): string
    {
        if (empty($peer['public_key'])) {
            return "# Tenant {$tenant['slug']} has not submitted its router public key yet.\n"
                 . "# Activate the peer on the onboarding page first.";
        }
        return "[Peer]\n"
             . "# {$tenant['name']} ({$tenant['slug']})\n"
             . "PublicKey = {$peer['public_key']}\n"
             . "AllowedIPs = {$peer['assigned_ip']}/32\n";
    }

    /** Equivalent live `wg set` command (no service restart needed). */
    public function serverPeerCommand(array $peer, string $wgIface = 'wg0'): string
    {
        if (empty($peer['public_key'])) return '# awaiting router public key';
        return "wg set {$wgIface} peer {$peer['public_key']} allowed-ips {$peer['assigned_ip']}/32";
    }

    /** Full hub peers fragment for every activated tenant (for wg0.conf). */
    public function allServerPeers(): string
    {
        $rows = $this->pdo->query(
            "SELECT w.public_key, w.assigned_ip, t.name, t.slug
             FROM wg_peers w JOIN tenants t ON t.id = w.tenant_id
             WHERE w.public_key <> '' ORDER BY t.id"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = "[Peer]\n# {$r['name']} ({$r['slug']})\nPublicKey = {$r['public_key']}\nAllowedIPs = {$r['assigned_ip']}/32\n";
        }
        return $out ? implode("\n", $out) : "# No activated peers yet.\n";
    }
}
