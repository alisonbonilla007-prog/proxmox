<?php
// src/Tenancy.php — resolve the active tenant and load its settings.
//
// Resolution order:
//   1. ?tenant=<slug>  (explicit; used for local/SQLite testing on localhost)
//   2. subdomain of APP_DOMAIN  (<slug>.meshcloud.example in production)
// Every business query MUST be scoped by the resolved tenant id.

class Tenancy
{
    public function __construct(private PDO $pdo, private array $config) {}

    /** Resolve slug from query param or Host header; null if none/apex. */
    public function resolveSlug(): ?string
    {
        if (!empty($_GET['tenant'])) {
            return preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['tenant']));
        }
        $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        $apex = strtolower($this->config['app_domain']);
        if ($host && $host !== $apex && str_ends_with($host, '.' . $apex)) {
            $sub = substr($host, 0, -strlen('.' . $apex));
            // ignore www / app reserved subdomains
            if (!in_array($sub, ['www', 'app', 'admin'], true)) {
                return preg_replace('/[^a-z0-9\-]/', '', $sub);
            }
        }
        return null;
    }

    /** Load a tenant row + its settings by slug, or null. */
    public function load(?string $slug): ?array
    {
        if (!$slug) return null;
        $st = $this->pdo->prepare("SELECT * FROM tenants WHERE slug = ?");
        $st->execute([$slug]);
        $tenant = $st->fetch();
        if (!$tenant) return null;

        $s = $this->pdo->prepare("SELECT * FROM tenant_settings WHERE tenant_id = ?");
        $s->execute([$tenant['id']]);
        $tenant['settings'] = $s->fetch() ?: [];
        return $tenant;
    }
}
