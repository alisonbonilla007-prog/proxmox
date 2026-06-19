# MESH Cloud — multi-tenant hotspot SaaS

Turn the single-tenant voucher portal into a cloud service: many operators
("tenants") connect their **MikroTik or pfSense** routers over a private
**WireGuard** tunnel, sell vouchers through **their own M-Pesa**, and monitor
their network from a branded dashboard. You earn via a SaaS subscription.

```
 CLIENT SITE                         CLOUD (one VPS, Docker)
 MikroTik/pfSense ══WireGuard══►  WG hub ─ FreeRADIUS(rlm_sql) ─ MySQL(tenant_id)
  • Hotspot/Captive Portal           PHP front controller (portal + admin + superadmin)
  • RADIUS → hub over tunnel         per-tenant M-Pesa callbacks · metrics poller
       └ user's phone ── HTTPS ──► <slug>.<APP_DOMAIN>  (tenant-branded portal)
```

## Why this shape
All RADIUS accounting flows to the cloud, so **monitoring is built-in** — every
session, byte, and login per tenant is already in the central DB, scoped by
`tenant_id`. Plain RADIUS over the internet is insecure, so each router opens an
outbound WireGuard tunnel and RADIUS rides privately over it.

## Layout
```
SAAS/
  public/index.php      front controller (routing) + .htaccess
  config.php            env-driven (DB_DRIVER, APP_DOMAIN, WG_*)
  schema.sql            multi-tenant schema
  build_saas_db.py      SQLite seeder (2 demo tenants) for local testing
  src/                  Database, Tenancy, Auth, Mpesa, Provision, helpers,
                        controllers_superadmin, controllers_admin, callback
  views/                portal, admin, superadmin, onboarding, login, landing
  provisioning/templates/  mikrotik.rsc.tpl, pfsense-setup.md.tpl
```

## Run locally (SQLite)
```bash
cd SAAS
python build_saas_db.py
DB_DRIVER=sqlite php -d extension=pdo_sqlite -d extension=curl \
  -S 127.0.0.1:8000 public/index.php
```
Then:
- Operator console: http://127.0.0.1:8000/superadmin  (root / admin123)
- Tenant portal:    http://127.0.0.1:8000/?tenant=swiftnet
- Tenant admin:     http://127.0.0.1:8000/admin?tenant=swiftnet  (owner / owner123)

Tenant is resolved by **subdomain** in production (`swiftnet.<APP_DOMAIN>`) and by
**`?tenant=<slug>`** locally.

## Production (outline)
1. **VPS + Docker** (Hetzner/DigitalOcean). Reverse proxy (Caddy/Traefik) with a
   wildcard TLS cert for `*.APP_DOMAIN`.
2. **MySQL** — load `schema.sql` (swap the SQLite `datetime('now')` defaults for
   MySQL `CURRENT_TIMESTAMP`). Set `DB_DRIVER=mysql` + `DB_*` env.
3. **WireGuard hub** — `wg`/`wg-quick` on the VPS; one peer per tenant router
   (the onboarding page allocates the tunnel IP `10.66.<tid>.1`). Set
   `WG_ENDPOINT`, `WG_SERVER_PUBKEY`, `WG_SUBNET`, `WG_RADIUS_IP`.
4. **FreeRADIUS (`rlm_sql`)** pointed at the same DB. Scope per tenant by the
   `nas` table (each tunnel IP → tenant) and the namespaced usernames
   (`<slug>-VCH-XXXXXX`). Session-Timeout is stored in `radreply` so the NAS
   enforces expiry.
5. Point Apache/Nginx document root at `SAAS/public`.

## Onboarding (plug-and-play)
Superadmin → **Onboard** a tenant generates:
- a **MikroTik `.rsc`** (one paste: WireGuard tunnel + hotspot RADIUS + walled
  garden; it prints the router's public key), and
- **pfSense steps** (guided WireGuard + Captive Portal + RADIUS).
The operator pastes the router's public key back to **activate** the peer.

## Status / roadmap
- **Phase 1 ✓** tenancy, superadmin console, tenant admin + portal, per-tenant
  M-Pesa checkout, onboarding generator. Testable on SQLite.
- **Phase 2 ✓** WireGuard hub scripts (`provisioning/wireguard/`), FreeRADIUS
  multi-tenant `sql.conf` (`provisioning/freeradius/`), public self-serve signup
  (`/signup`), tenant-owner router onboarding (`/admin/onboarding`), SaaS
  subscription billing (`cron/billing_check.php` + fleet console controls).
- **Phase 3 ✓** router health monitoring: `src/Monitor.php` (RouterOS REST + SNMP),
  `monitoring/poll.php` cron (with `--simulate`), `device_metrics` + `alerts`
  tables, tenant **Network** tab (CPU/throughput/uptime charts + alerts) and
  fleet-wide health + alerts in the operator console.

See `provisioning/DEPLOY.md` for the full production runbook.

Demo logins: superadmin `root / admin123`; each tenant owner `owner / owner123`.
